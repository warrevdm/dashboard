<?php

require_once __DIR__ . '/../app/bootstrap.php';
Auth::requirePost();

$requestId = $_POST['send_request_id'] ?? null;
$expected = $_SESSION['weekly_mail_send_id'] ?? null;
if (!is_string($requestId) || !is_string($expected)
    || !preg_match('/^[a-f0-9]{32}$/D', $requestId) || !hash_equals($expected, $requestId)) {
    http_response_code(403);
    exit('Dit verzendformulier is al gebruikt of verlopen. Open Dinsdagmail opnieuw.');
}
// Consume before SMTP and release the session so a double click cannot send twice.
unset($_SESSION['weekly_mail_send_id']);
session_write_close();

require_once __DIR__ . '/../app/WeeklyMailRunner.php';
require_once __DIR__ . '/../app/WeeklySmtpMailer.php';

try {
    $config = WeeklyMailConfig::load();
    if (WeeklyMailConfig::errors($config)) {
        throw new RuntimeException('De mailinstellingen zijn nog niet volledig.');
    }
    WeeklySmtpMailer::assertAvailable();
    require_once __DIR__ . '/../app/Database.php';
    $pdo = Database::connect();
    $mailer = new WeeklySmtpMailer($config);
    $runner = new WeeklyMailRunner($config,
        static fn (DateTimeImmutable $date): array => ExpiringContracts::find($pdo, $date, true),
        Closure::fromCallable([$mailer, 'send']));
    $result = $runner->sendNow(new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels')), $requestId);
    $notice = match ($result['status']) {
        'sent' => 'De mailserver heeft het overzicht voor ' . $result['sent'] . ' ontvanger(s) aanvaard.',
        'already_sent' => 'Deze verzendopdracht is al uitgevoerd. Er is niets opnieuw verstuurd.',
        'busy' => 'Er loopt al een verzending. Controleer straks de verzendstatus voordat je opnieuw probeert.',
        'needs_review' => 'Niet alle verzendingen zijn bevestigd. Controleer de verzendstatus en de mailboxen vóór opnieuw versturen.',
        default => 'De verzending is niet uitgevoerd. Controleer de instellingen en verzendstatus.',
    };
} catch (Throwable $error) {
    // Detailed SMTP errors may contain credentials or server responses.
    $notice = 'Verzending niet voltooid. Controleer de mailinstellingen, mailbibliotheek en schrijfrechten op de statusmap. Controleer ook de verzendstatus en mailboxen vóór opnieuw proberen.';
}

session_start();
$_SESSION['weekly_mail_notice'] = $notice;
session_write_close();
header('Location: weekly-mail.php', true, 303);
exit;
