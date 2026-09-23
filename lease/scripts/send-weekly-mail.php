<?php

// This must never become a public cron URL or bypass browser authentication.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

foreach (array_slice($argv, 1) as $argument) {
    if (!in_array($argument, ['--dry-run', '--retry-failed', '--help'], true)) {
        fwrite(STDERR, "Onbekende optie. Gebruik --help; er is niets verstuurd.\n");
        exit(2);
    }
}
if (in_array('--dry-run', $argv, true) && in_array('--retry-failed', $argv, true)) {
    fwrite(STDERR, "Kies --dry-run of --retry-failed; er is niets verstuurd.\n");
    exit(2);
}

require_once __DIR__ . '/../app/DatabaseConnection.php';
require_once __DIR__ . '/../app/ExpiringContracts.php';
require_once __DIR__ . '/../app/WeeklyMailRunner.php';
require_once __DIR__ . '/../app/WeeklySmtpMailer.php';

$options = getopt('', ['dry-run', 'retry-failed', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Gebruik: php lease/scripts/send-weekly-mail.php [--dry-run | --retry-failed]\n"
        . "Standaard: dinsdag vanaf het ingestelde uur, Europe/Brussels.\n"
        . "--dry-run: alleen aantallen en periode controleren, nooit mailen of verzendstatus wijzigen.\n"
        . "--retry-failed: alleen na controle van mailbox/mailserver; kan bij een eerdere timeout een dubbele mail opleveren.\n");
    exit(0);
}

try {
    $config = WeeklyMailConfig::load();
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels'));
    $loadOrders = static function (DateTimeImmutable $date): array {
        // CLI has no HTTP_HOST: never accidentally select the development database.
        $path = getenv('AAB_LEASE_DB_FILE') ?: __DIR__ . '/../config/config.production.php';
        return ExpiringContracts::find(DatabaseConnection::open($path), $date, true);
    };
    if (isset($options['dry-run'])) {
        $orders = $loadOrders($now);
        [$start, $end] = ExpiringContracts::window($now);
        $result = ['status' => 'preview', 'contracts' => count($orders), 'from' => $start->format('Y-m-d'),
            'until' => $end->format('Y-m-d'), 'recipients' => count(WeeklyMailConfig::recipients($config)),
            'configuration_errors' => WeeklyMailConfig::errors($config), 'sent' => 0];
    } else {
        if (($config['enabled'] ?? false) === true && WeeklyMailRunner::isDue($now, (int) $config['send_hour'])) {
            WeeklySmtpMailer::assertAvailable();
        }
        $mailer = new WeeklySmtpMailer($config);
        $runner = new WeeklyMailRunner($config, $loadOrders, Closure::fromCallable([$mailer, 'send']));
        $result = $runner->run($now, isset($options['retry-failed']));
    }
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(($result['status'] ?? '') === 'needs_review' || !empty($result['configuration_errors']) ? 1 : 0);
} catch (Throwable $error) {
    // Safe operational output: no DB credentials or detailed SMTP responses.
    fwrite(STDERR, "Weekmail niet uitgevoerd. Controleer configuratie, databank, mailbibliotheek en rechten op de statusmap.\n");
    exit(1);
}
