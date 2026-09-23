<?php

require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

require_once __DIR__ . '/../app/ExpiringContracts.php';
$withoutLogbook = ($_GET['without_logbook'] ?? '') === '1';
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels'));
$orders = ExpiringContracts::find($pdo, $now, $withoutLogbook);

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contracten verlopen binnenkort | Lease Import Manager</title>
    <link rel="stylesheet" href="assets.css">
</head>
<body>

<header class="page-header">
    <h1>Contracten binnen 3 maanden</h1>

    <?php require __DIR__ . '/partials/navigation.php'; ?>
</header>

<main>
    <section class="card">
        <h2>Leasingcontracten die binnenkort aflopen</h2>
        <p>Deze lijst toont contracten met een einddatum tussen vandaag en 3 maanden vanaf vandaag.</p>
        <div class="quick-actions">
            <a class="<?= $withoutLogbook ? 'button-secondary' : 'button' ?>" href="expiring-contracts.php">Alle contracten</a>
            <a class="<?= $withoutLogbook ? 'button' : 'button-secondary' ?>" href="expiring-contracts.php?without_logbook=1">Zonder logboek</a>
            <a class="button-secondary" href="weekly-mail.php">Voorbeeld dinsdagmail</a>
        </div>
        <?php if ($withoutLogbook): ?>
            <p>Alleen dossiers zonder enige logboekactie. Dit is dezelfde selectie als in de dinsdagmail.</p>
        <?php endif; ?>
        <p><strong>🔄 In behandeling</strong> = er werd minstens één item toegevoegd aan het logboek van dit dossier.</p>
    </section>

    <section class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Status</th>
                    <th>SO-number</th>
                    <th>Naam</th>
                    <th>Email</th>
                    <th>Telefoon</th>
                    <th>Bedrijf</th>
                    <th>Leasepartner</th>
                    <th>Fiets</th>
                    <th>Type</th>
                    <th>Einde contract</th>
                    <th>Budget onderhoud</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="11"><?= $withoutLogbook ? 'Geen aflopende contracten zonder logboekactie.' : 'Geen leasingcontracten die binnen 3 maanden aflopen.' ?></td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td class="status-icons-cell">
                            <?php if ((int) ($order['in_progress'] ?? 0) === 1): ?>
                                <span
                                    class="status-icon icon-status-warning"
                                    title="Dossier in behandeling — laatste logboekactiviteit: <?= e($order['last_logbook_activity'] ?: 'onbekend') ?>"
                                    aria-label="Dossier in behandeling"
                                >
                                    🔄
                                </span>
                            <?php else: ?>
                                <span
                                    class="status-icon icon-status-neutral"
                                    title="Nog geen logboekactiviteit"
                                    aria-label="Nog niet in behandeling"
                                >
                                    —
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="contract-detail.php?id=<?= e($order['id']) ?>">
                                <?= e($order['so_number']) ?>
                            </a>
                        </td>
                        <td><?= e($order['customer_name']) ?></td>
                        <td><?= e($order['email']) ?></td>
                        <td><?= e($order['phone']) ?></td>
                        <td><?= e($order['company']) ?></td>
                        <td><?= e($order['lease_partner']) ?></td>
                        <td><?= e($order['bike_name']) ?></td>
                        <td><?= e($order['bike_type']) ?></td>
                        <td><?= e($order['lease_end_date']) ?></td>
                        <td>€ <?= e($order['maintenance_budget']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>

</body>
</html>
