<?php

require_once __DIR__ . '/../app/Database.php';
require_once __DIR__ . '/../app/WeeklyMailRunner.php';

$pdo = Database::connect();
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels'));
$config = WeeklyMailConfig::load();
$errors = WeeklyMailConfig::errors($config);
$orders = ExpiringContracts::find($pdo, $now, true);
$preview = WeeklyMailConfig::validBaseUrl((string) $config['base_url'])
    ? WeeklyMailMessage::build($orders, $now, $config['base_url']) : null;
$enabled = ($config['enabled'] ?? false) === true;
$lastWeek = null;
$lastEntries = [];
try {
    $state = (new WeeklyMailState($config['state_directory']))->read();
    krsort($state['weeks']);
    $lastWeek = array_key_first($state['weeks']);
    $lastEntries = $lastWeek !== null ? ($state['weeks'][$lastWeek]['recipients'] ?? []) : [];
} catch (Throwable $error) {
    $errors[] = 'De verzendstatus kan niet worden gelezen. Laat dit nakijken vóór opnieuw verzenden.';
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
</head>
<body>
<header class="page-header">
    <h1>Dinsdagmail</h1>
    <?php require __DIR__ . '/partials/navigation.php'; ?>
</header>
<main>
    <section class="card">
        <h2>Wekelijkse interne opvolging</h2>
        <p>Iedere dinsdag vanaf <?= e(sprintf('%02d:00', (int) $config['send_hour'])) ?>, Belgische tijd.</p>
        <p>Het overzicht bevat niet-gearchiveerde contracten die tussen vandaag en drie maanden aflopen en nog geen enkele logboekactie hebben.</p>
        <p><strong>Instelling:</strong> <?= $enabled ? 'Ingeschakeld' : 'Uitgeschakeld' ?>.
            <?= $enabled ? 'De geplande servertaak verzorgt de verzending.' : 'Laat de ontvangers en mailverbinding instellen en de geplande taak activeren.' ?></p>
        <p><strong>Ontvangers:</strong> <?= e(implode(', ', WeeklyMailConfig::recipients($config)) ?: 'Nog niet ingesteld') ?></p>
        <p>Ook wanneer er geen dossiers zijn, ontvangen de ingestelde ontvangers een korte bevestiging. Deze interne mail maakt geen logboekactie aan en wordt niet naar klanten gestuurd.</p>
        <?php if ($errors): ?>
            <div role="status">
                <strong>Nog te controleren</strong>
                <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>
        <a class="button-secondary" href="expiring-contracts.php?without_logbook=1">Open contracten zonder logboek</a>
    </section>
    <section class="card">
        <h2>Laatste dinsdagmail</h2>
        <?php if ($lastWeek === null): ?>
            <p>Er is nog geen verzendpoging geregistreerd. Inschakelen alleen start de geplande servertaak niet.</p>
        <?php else: ?>
            <p>Dinsdag <?= e($lastWeek) ?>. “Verzonden” betekent dat de mailserver de e-mail heeft aanvaard.</p>
            <table>
                <thead><tr><th>Ontvanger</th><th>Status</th><th>Contracten</th></tr></thead>
                <tbody>
                <?php foreach (WeeklyMailConfig::recipients($config) as $recipient): ?>
                    <?php $entry = $lastEntries[hash('sha256', strtolower($recipient))] ?? []; ?>
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
    <section class="card">
        <h2>Mailvoorbeeld — <?= count($orders) ?> contracten</h2>
        <p>Dit voorbeeld gebruikt de gegevens van vandaag. De selectie wordt dinsdag opnieuw berekend.</p>
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
