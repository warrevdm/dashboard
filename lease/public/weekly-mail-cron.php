<?php

// Dedicated scheduler credential; no browser session or interactive login.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
ini_set('display_errors', '0');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405); header('Allow: GET'); exit('{"status":"method_not_allowed"}');
}
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if (!$isHttps) { http_response_code(403); exit('{"status":"https_required"}'); }
require_once __DIR__ . '/../app/WeeklyMailConfig.php';
require_once __DIR__ . '/../app/WeeklyMailAutomation.php';
require_once __DIR__ . '/../app/WeeklyMailRunner.php';
try {
    $config = WeeklyMailConfig::load();
    $settings = WeeklyMailAutomation::read($config['state_directory']);
    $key = $_SERVER['HTTP_X_CRON_KEY'] ?? $_GET['key'] ?? null;
    if (!WeeklyMailAutomation::authorized($key, $settings)) {
        http_response_code(403); exit('{"status":"forbidden"}');
    }
    unset($key, $settings);
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels'));
    if (($config['enabled'] ?? false) !== true) exit('{"status":"disabled","sent":0}');
    if (!WeeklyMailRunner::isDue($now, (int)$config['send_hour'])) exit('{"status":"not_due","sent":0}');
    require_once __DIR__ . '/../app/DatabaseConnection.php';
    require_once __DIR__ . '/../app/ExpiringContracts.php';
    require_once __DIR__ . '/../app/WeeklySmtpMailer.php';
    WeeklySmtpMailer::assertAvailable();
    $mailer = new WeeklySmtpMailer($config);
    $load = static function (DateTimeImmutable $date): array {
        $path = getenv('AAB_LEASE_DB_FILE') ?: __DIR__ . '/../config/config.production.php';
        return ExpiringContracts::find(DatabaseConnection::open($path), $date, true);
    };
    $runner = new WeeklyMailRunner($config, $load, Closure::fromCallable([$mailer, 'send']));
    $result = $runner->run($now);
    if (($result['status'] ?? '') === 'needs_review') http_response_code(500);
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('Lease dinsdagmail cron mislukt; controleer instellingen en verzendstatus.');
    http_response_code(500); echo '{"status":"error","message":"Controleer mailinstellingen en verzendstatus."}';
}
