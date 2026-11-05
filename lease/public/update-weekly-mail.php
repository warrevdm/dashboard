<?php

require_once __DIR__ . '/../app/bootstrap.php';
Auth::requirePost();
require_once __DIR__ . '/../app/WeeklyMailConfig.php';
require_once __DIR__ . '/../app/WeeklyMailAutomation.php';
require_once __DIR__ . '/../app/WeeklySmtpMailer.php';

try {
    $enabled = $_POST['enabled'] ?? null;
    if (!in_array($enabled, ['0','1'], true)) throw new InvalidArgumentException('Ongeldige planningsinstelling.');
    $config = WeeklyMailConfig::load();
    if ($enabled === '1') {
        $config['send_hour'] = 9;
        $errors = WeeklyMailConfig::errors($config);
        if ($errors) throw new InvalidArgumentException(implode(' ', $errors));
        WeeklySmtpMailer::assertAvailable();
    }
    WeeklyMailAutomation::setEnabled($config, $enabled === '1');
    $_SESSION['weekly_mail_notice'] = $enabled === '1'
        ? 'Dinsdagmail ingeschakeld voor 09:00 Belgische tijd. Stel ook de cron-URL hieronder in bij de hosting.'
        : 'Automatische dinsdagmail uitgeschakeld.';
} catch (InvalidArgumentException $error) {
    $_SESSION['weekly_mail_notice'] = $error->getMessage();
} catch (Throwable $error) {
    error_log('Lease dinsdagplanning wijzigen mislukt.');
    $_SESSION['weekly_mail_notice'] = 'De dinsdagplanning kon niet worden gewijzigd. Controleer de mailinstellingen en de opslagrechten.';
}
header('Location: weekly-mail.php', true, 303);
exit;
