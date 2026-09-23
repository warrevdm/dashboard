<?php

require_once __DIR__ . '/../app/Database.php';
require_once __DIR__ . '/../app/WeeklyMailRunner.php';
require_once __DIR__ . '/../app/WeeklySmtpMailer.php';

$pdo = Database::connect();
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels'));
$config = WeeklyMailConfig::load();
$errors = WeeklyMailConfig::errors($config);
try {
    WeeklySmtpMailer::assertAvailable();
} catch (Throwable $error) {
    $errors[] = 'De mailbibliotheek ontbreekt. Upload de door Composer bijgewerkte vendor-map.';
}
$orders = ExpiringContracts::find($pdo, $now, true);
$preview = WeeklyMailConfig::validBaseUrl((string) $config['base_url'])
    ? WeeklyMailMessage::build($orders, $now, $config['base_url']) : null;
$enabled = ($config['enabled'] ?? false) === true;
$lastWeek = null;
$lastEntries = [];
$lastManual = null;
$manualDate = '';
try {
    $state = (new WeeklyMailState($config['state_directory']))->read();
    krsort($state['weeks']);
    $lastWeek = array_key_first($state['weeks']);
    $lastEntries = $lastWeek !== null ? ($state['weeks'][$lastWeek]['recipients'] ?? []) : [];
    $manual = $state['manual'] ?? [];
    $lastManual = $manual ? $manual[array_key_last($manual)] : null;
    $manualDate = $lastManual !== null
        ? (new DateTimeImmutable($lastManual['updated_at']))->setTimezone(new DateTimeZone('Europe/Brussels'))->format('d/m/Y H:i') : '';
} catch (Throwable $error) {
    $errors[] = 'De verzendstatus kan niet worden gelezen. Laat dit nakijken vóór opnieuw verzenden.';
}
$notice = $_SESSION['weekly_mail_notice'] ?? null;
unset($_SESSION['weekly_mail_notice']);
if (empty($_SESSION['weekly_mail_send_id'])) {
    $_SESSION['weekly_mail_send_id'] = bin2hex(random_bytes(16));
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dinsdagmail | Lease Import Manager</title>
    <link rel="stylesheet" href="assets.css">
    <style>button:disabled { opacity: 0.55; cursor: not-allowed; }</style>
</head>
<body>
<header class="page-header">
    <h1>Dinsdagmail</h1>
    <?php require __DIR__ . '/partials/navigation.php'; ?>
</header>
<main>
    <?php if (is_string($notice)): ?>
        <section class="card" role="status"><p><?= e($notice) ?></p></section>
    <?php endif; ?>
    <section class="card">
        <h2>Wekelijkse interne opvolging</h2>
        <p>Iedere dinsdag vanaf <?= e(sprintf('%02d:00', (int) $config['send_hour'])) ?>, Belgische tijd.</p>
        <p>Het overzicht bevat niet-gearchiveerde contracten die tussen vandaag en drie maanden aflopen en nog geen enkele logboekactie hebben.</p>
        <p><strong>Automatische verzending:</strong> <?= $enabled ? 'Ingeschakeld' : 'Uitgeschakeld' ?>.
            <?= $enabled ? 'De geplande servertaak verzorgt de verzending.' : 'Je kunt hieronder wel handmatig versturen zodra de mailinstellingen volledig zijn.' ?></p>
        <p><strong>Ontvangers:</strong> <?= e(implode(', ', WeeklyMailConfig::recipients($config)) ?: 'Nog niet ingesteld') ?></p>
        <p>Ook wanneer er geen dossiers zijn, ontvangen de ingestelde ontvangers een korte bevestiging. Deze interne mail maakt geen logboekactie aan en wordt niet naar klanten gestuurd.</p>
        <?php if ($errors): ?>
            <div role="status">
                <strong>Nog te controleren</strong>
                <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>
        <form method="POST" action="send-weekly-mail.php">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="send_request_id" value="<?= e($_SESSION['weekly_mail_send_id']) ?>">
            <p>Verstuur het overzicht van vandaag meteen naar de bovenstaande ontvangers. Dit kan op elke dag, ook als de automatische verzending uitstaat.</p>
            <button type="submit" <?= $errors ? 'disabled' : '' ?>>Nu versturen</button>
        </form>
        <p>De automatische dinsdagplanning blijft afzonderlijk actief wanneer die is ingeschakeld.</p>
        <a class="button-secondary" href="expiring-contracts.php?without_logbook=1">Open contracten zonder logboek</a>
    </section>
    <?php foreach ([
        ['title' => 'Laatste handmatige verzending', 'date' => $manualDate, 'exists' => $lastManual !== null, 'entries' => $lastManual['recipients'] ?? []],
        ['title' => 'Laatste dinsdagmail', 'date' => $lastWeek, 'exists' => $lastWeek !== null, 'entries' => $lastEntries],
    ] as $delivery): ?>
    <section class="card">
        <h2><?= e($delivery['title']) ?></h2>
        <?php if (!$delivery['exists']): ?>
            <p>Er is nog geen verzendpoging geregistreerd.</p>
        <?php else: ?>
            <p><?= e($delivery['date']) ?>, Belgische tijd. “Verzonden” betekent dat de mailserver de e-mail heeft aanvaard.</p>
            <table>
                <thead><tr><th>Ontvanger</th><th>Status</th><th>Contracten</th></tr></thead>
                <tbody>
                <?php foreach (WeeklyMailConfig::recipients($config) as $recipient): ?>
                    <?php $entry = $delivery['entries'][hash('sha256', strtolower($recipient))] ?? []; ?>
                    <tr>
                        <td><?= e($recipient) ?></td>
                        <td><?= e(match ($entry['status'] ?? '') {
                            'sent' => 'Verzonden',
                            'failed' => 'Niet bevestigd — controle nodig',
                            'sending' => 'In verzending of onderbroken — controle nodig',
                            default => 'Geen verzendpoging',
                        }) ?></td>
                        <td><?= e($entry['count'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p>Bij een niet-bevestigde verzending eerst de mailbox en mailserver controleren. Er wordt dan niet automatisch opnieuw verstuurd om dubbele mails te voorkomen.</p>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>
    <section class="card">
        <h2>Mailvoorbeeld — <?= count($orders) ?> contracten</h2>
        <p>Dit voorbeeld gebruikt de gegevens van vandaag. Bij iedere verzending wordt de selectie opnieuw berekend.</p>
        <?php if ($preview !== null): ?>
            <p><strong>Onderwerp:</strong> <?= e($preview['subject']) ?></p>
            <iframe title="Voorbeeld van de wekelijkse lease-update" sandbox="" srcdoc="<?= e($preview['html']) ?>" style="display:block;width:100%;height:680px;border:1px solid #e5e7eb;border-radius:8px"></iframe>
        <?php else: ?>
            <p>Het mailvoorbeeld wordt beschikbaar zodra de link naar het leaseprogramma correct is ingesteld.</p>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
