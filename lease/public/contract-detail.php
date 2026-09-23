<?php

require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    die('Ongeldig contract.');
}

$stmt = $pdo->prepare("
    SELECT *
    FROM lease_orders
    WHERE id = :id
    LIMIT 1
");

$stmt->execute([
    ':id' => $id,
]);

$order = $stmt->fetch();

if (!$order) {
    die('Contract niet gevonden.');
}

$logbookStmt = $pdo->prepare("
    SELECT *
    FROM customer_logbook
    WHERE lease_order_id = :lease_order_id
    ORDER BY created_at DESC, id DESC
");

$logbookStmt->execute([
    ':lease_order_id' => $order['id'],
]);

$logbookEntries = $logbookStmt->fetchAll();

function formatDateValue($value): string
{
    if (!$value) {
        return 'Niet ingevuld';
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);

    if (!$date) {
        return (string) $value;
    }

    return $date->format('d/m/Y');
}

function formatMoneyValue($value): string
{
    if ($value === null || $value === '') {
        return 'Niet ingevuld';
    }

    return '€ ' . number_format((float) $value, 2, ',', '.');
}

function daysUntil($dateValue): ?int
{
    if (!$dateValue) {
        return null;
    }

    $today = new DateTime('today');
    $date = DateTime::createFromFormat('Y-m-d', $dateValue);

    if (!$date) {
        return null;
    }

    return (int) $today->diff($date)->format('%r%a');
}

function statusText(?int $days): string
{
    if ($days === null) {
        return 'Geen datum beschikbaar';
    }

    if ($days < 0) {
        return 'Verlopen';
    }

    if ($days <= 90) {
        return 'Binnen 3 maanden';
    }

    return 'Actief';
}

function statusClass(?int $days): string
{
    if ($days === null) {
        return 'status-neutral';
    }

    if ($days < 0) {
        return 'status-danger';
    }

    if ($days <= 90) {
        return 'status-warning';
    }

    return 'status-success';
}

function orderStatusBadgeClass($code): string
{
    if ((string) $code === '5') {
        return 'status-success';
    }

    if ((string) $code === '3') {
        return 'status-warning';
    }

    return 'status-neutral';
}

$leasePartnerNormalized = strtolower((string) ($order['lease_partner'] ?? ''));

$daysUntilLeaseEnd = daysUntil($order['lease_end_date']);
$daysUntilMaintenanceEnd = daysUntil($order['yearly_maintenance_end_date']);

$copyCustomerInfo = trim(
    "SO-number: " . ($order['so_number'] ?? '') . "\n" .
    "Naam: " . ($order['customer_name'] ?? '') . "\n" .
    "Email: " . ($order['email'] ?? '') . "\n" .
    "Telefoon: " . ($order['phone'] ?? '') . "\n" .
    "Bedrijf: " . ($order['company'] ?? '') . "\n" .
    "Leasepartner: " . ($order['lease_partner'] ?? '') . "\n" .
    "Fiets: " . ($order['bike_name'] ?? '') . "\n" .
    "Framenummer: " . ($order['frame_number'] ?? '')
);

?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Contract <?= e($order['so_number']) ?> | Lease Import Manager</title>
    <link rel="stylesheet" href="assets.css">
</head>
<body>

<header class="page-header">
    <h1>Contract <?= e($order['so_number']) ?></h1>

    <?php require __DIR__ . '/partials/navigation.php'; ?>
</header>

<main>
    <section class="card">
        <h2><?= e($order['customer_name'] ?: 'Naam niet ingevuld') ?></h2>

        <p><strong>SO-number:</strong> <?= e($order['so_number']) ?></p>
        <p><strong>Fiets:</strong> <?= e($order['bike_name'] ?: 'Niet ingevuld') ?></p>
        <p><strong>Leasepartner:</strong> <?= e($order['lease_partner'] ?: 'Niet ingevuld') ?></p>

        <p>
            <strong>Orderstatus:</strong>
            <?php if (!empty($order['order_status_raw'])): ?>
                <span class="status-badge <?= e(orderStatusBadgeClass($order['order_status_code'])) ?>">
                    <?= e($order['order_status_raw']) ?>
                </span>
            <?php else: ?>
                Niet ingevuld
            <?php endif; ?>
        </p>

        <?php if ((int) $order['archived'] === 1): ?>
            <p class="alert-warning">Dit contract is gearchiveerd en verschijnt niet in het standaardoverzicht.</p>
        <?php endif; ?>

        <?php if (isset($_GET['updated'])): ?>
            <p class="alert-success">Contract succesvol bijgewerkt.</p>
        <?php endif; ?>

        <?php if (isset($_GET['restored'])): ?>
            <p class="alert-success">Contract succesvol hersteld naar het actieve overzicht.</p>
        <?php endif; ?>

        <?php if (isset($_GET['mail_logged'])): ?>
            <p class="alert-success">Mailactie toegevoegd aan het logbook.</p>
        <?php endif; ?>

        <?php if (isset($_GET['mail_missing'])): ?>
            <p class="alert-warning">Mailactie is gelogd, maar er is geen e-mailadres beschikbaar.</p>
        <?php endif; ?>

        <?php if (isset($_GET['budget_logged'])): ?>
            <p class="alert-success">Budgetactie toegevoegd aan het logbook.</p>
        <?php endif; ?>

        <div class="status-row">
            <span class="status-badge <?= e(statusClass($daysUntilLeaseEnd)) ?>">
                Leasing: <?= e(statusText($daysUntilLeaseEnd)) ?>
            </span>

            <?php if ($leasePartnerNormalized !== 'o2o' && $leasePartnerNormalized !== 'cyclis'): ?>
                <span class="status-badge <?= e(statusClass($daysUntilMaintenanceEnd)) ?>">
                    Onderhoud: <?= e(statusText($daysUntilMaintenanceEnd)) ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="quick-actions">
            <a href="edit-contract.php?id=<?= e($order['id']) ?>" class="button">
                Bewerken
            </a>

            <?php if ((int) $order['archived'] === 0): ?>
                <form
                    action="archive-contract.php"
                    method="POST"
                    onsubmit="return confirm('Ben je zeker dat je dit contract wil archiveren? Het verdwijnt uit het standaardoverzicht, maar wordt niet definitief verwijderd.');"
                >
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="id" value="<?= e($order['id']) ?>">
                    <button type="submit" class="button-danger">Archiveren</button>
                </form>
            <?php else: ?>
                <span class="status-badge status-neutral">Gearchiveerd</span>

                <form
                    action="restore-contract.php"
                    method="POST"
                    onsubmit="return confirm('Wil je dit contract herstellen naar het actieve overzicht?');"
                >
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="id" value="<?= e($order['id']) ?>">
                    <button type="submit">Herstellen</button>
                </form>
            <?php endif; ?>

            <form action="log-mail-action.php" method="POST">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="id" value="<?= e($order['id']) ?>">
                <button type="submit" class="button">Mail klant</button>
            </form>

            <?php if (!empty($order['phone'])): ?>
                <a class="button-secondary" href="tel:<?= e($order['phone']) ?>">
                    Bel klant
                </a>
            <?php endif; ?>

            <button
                type="button"
                class="button-secondary"
                data-copy="<?= e($order['so_number']) ?>"
            >
                Kopieer SO-number
            </button>

            <button
                type="button"
                class="button-secondary"
                data-copy="<?= e($copyCustomerInfo) ?>"
            >
                Kopieer klantinfo
            </button>
        </div>

        <p id="copy-feedback" class="copy-feedback" hidden>Gekopieerd.</p>
    </section>

    <section class="detail-grid">
        <article class="card">
            <h2>Klantgegevens</h2>

            <dl class="detail-list">
                <dt>Naam</dt>
                <dd><?= e($order['customer_name'] ?: 'Niet ingevuld') ?></dd>

                <dt>Email</dt>
                <dd>
                    <?php if (!empty($order['email'])): ?>
                        <a href="mailto:<?= e($order['email']) ?>"><?= e($order['email']) ?></a>
                    <?php else: ?>
                        Niet ingevuld
                    <?php endif; ?>
                </dd>

                <dt>Telefoon</dt>
                <dd>
                    <?php if (!empty($order['phone'])): ?>
                        <a href="tel:<?= e($order['phone']) ?>"><?= e($order['phone']) ?></a>
                    <?php else: ?>
                        Niet ingevuld
                    <?php endif; ?>
                </dd>

                <dt>Bedrijf</dt>
                <dd><?= e($order['company'] ?: 'Niet ingevuld') ?></dd>
            </dl>
        </article>

        <article class="card">
            <h2>Fietsgegevens</h2>

            <dl class="detail-list">
                <dt>Fietstype</dt>
                <dd><?= e($order['bike_type'] ?: 'Niet ingevuld') ?></dd>

                <dt>Fietsnaam</dt>
                <dd><?= e($order['bike_name'] ?: 'Niet ingevuld') ?></dd>

                <dt>Framenummer</dt>
                <dd><?= e($order['frame_number'] ?: 'Niet ingevuld') ?></dd>
            </dl>
        </article>

        <article class="card">
            <h2>Leasingcontract</h2>

            <dl class="detail-list">
                <dt>Startdatum</dt>
                <dd><?= e(formatDateValue($order['lease_start_date'])) ?></dd>

                <dt>Einddatum</dt>
                <dd><?= e(formatDateValue($order['lease_end_date'])) ?></dd>

                <dt>Status</dt>
                <dd>
                    <span class="status-badge <?= e(statusClass($daysUntilLeaseEnd)) ?>">
                        <?= e(statusText($daysUntilLeaseEnd)) ?>
                    </span>
                </dd>

                <dt>Dagen tot einde</dt>
                <dd>
                    <?php if ($daysUntilLeaseEnd === null): ?>
                        Niet beschikbaar
                    <?php else: ?>
                        <?= e($daysUntilLeaseEnd) ?> dagen
                    <?php endif; ?>
                </dd>

                <?php if ($leasePartnerNormalized === 'o2o'): ?>
                    <dt>O2O budget grace-periode start</dt>
                    <dd><?= e(formatDateValue($order['o2o_budget_grace_start_date'])) ?></dd>

                    <dt>O2O budget bruikbaar tot</dt>
                    <dd><?= e(formatDateValue($order['o2o_budget_valid_until'])) ?></dd>
                <?php endif; ?>

                <?php if ($leasePartnerNormalized === 'cyclis'): ?>
                    <dt>Cyclis budget grace-periode start</dt>
                    <dd><?= e(formatDateValue($order['cyclis_budget_grace_start_date'])) ?></dd>

                    <dt>Cyclis budget bruikbaar tot</dt>
                    <dd><?= e(formatDateValue($order['cyclis_budget_valid_until'])) ?></dd>
                <?php endif; ?>
            </dl>
        </article>

        <article class="card">
            <h2>Onderhoud</h2>

            <dl class="detail-list">
                <dt>Beschikbaar budget</dt>
                <dd><?= e(formatMoneyValue($order['maintenance_budget'])) ?></dd>

                <dt>Budgetactie</dt>
                <dd>
                    <form action="log-budget-conversion.php" method="POST" class="inline-log-form">
                        <?= Auth::csrfField() ?>
                        <input type="hidden" name="id" value="<?= e($order['id']) ?>">

                        <input
                            type="number"
                            step="0.01"
                            name="amount"
                            value="<?= e($order['maintenance_budget']) ?>"
                            placeholder="Bedrag"
                            required
                        >

                        <input
                            type="text"
                            name="note"
                            placeholder="Notitie, bv. gebruikt voor accessoires/herstelling"
                        >

                        <button type="submit">
                            Budget omzetten naar winkeluitgave
                        </button>
                    </form>
                </dd>

                <?php if ($leasePartnerNormalized === 'o2o'): ?>
                    <dt>Onderhoudsregeling</dt>
                    <dd>O2O-budget via digitale portefeuille. De budgetperiode staat bij het leasingcontract.</dd>
                <?php elseif ($leasePartnerNormalized === 'cyclis'): ?>
                    <dt>Onderhoudsregeling</dt>
                    <dd>Cyclis-budget blijft na afloop van het contract nog 1 jaar bruikbaar. De budgetperiode staat bij het leasingcontract.</dd>
                <?php elseif ($leasePartnerNormalized === 'lease a bike'): ?>
                    <dt>Onderhoudsregeling</dt>
                    <dd>
                        Lease a Bike werkt met een jaarlijks onderhoudsbudget.
                        De getoonde onderhoudsdatum is de eerstvolgende jaarlijkse vervaldatum,
                        berekend op basis van de startdatum van het leasecontract en begrensd op de einddatum van het contract.
                    </dd>

                    <dt>Einde jaarlijks onderhoudscontract</dt>
                    <dd><?= e(formatDateValue($order['yearly_maintenance_end_date'])) ?></dd>

                    <dt>Status onderhoud</dt>
                    <dd>
                        <span class="status-badge <?= e(statusClass($daysUntilMaintenanceEnd)) ?>">
                            <?= e(statusText($daysUntilMaintenanceEnd)) ?>
                        </span>
                    </dd>

                    <dt>Dagen tot einde onderhoud</dt>
                    <dd>
                        <?php if ($daysUntilMaintenanceEnd === null): ?>
                            Niet beschikbaar
                        <?php else: ?>
                            <?= e($daysUntilMaintenanceEnd) ?> dagen
                        <?php endif; ?>
                    </dd>
                <?php else: ?>
                    <dt>Einde jaarlijks onderhoudscontract</dt>
                    <dd><?= e(formatDateValue($order['yearly_maintenance_end_date'])) ?></dd>

                    <dt>Status onderhoud</dt>
                    <dd>
                        <span class="status-badge <?= e(statusClass($daysUntilMaintenanceEnd)) ?>">
                            <?= e(statusText($daysUntilMaintenanceEnd)) ?>
                        </span>
                    </dd>

                    <dt>Dagen tot einde onderhoud</dt>
                    <dd>
                        <?php if ($daysUntilMaintenanceEnd === null): ?>
                            Niet beschikbaar
                        <?php else: ?>
                            <?= e($daysUntilMaintenanceEnd) ?> dagen
                        <?php endif; ?>
                    </dd>
                <?php endif; ?>
            </dl>
        </article>

        <article class="card">
            <h2>Importmetadata</h2>

            <dl class="detail-list">
                <dt>Bronbestand</dt>
                <dd><?= e($order['source_file'] ?: 'Niet ingevuld') ?></dd>

                <dt>Laatst geïmporteerd</dt>
                <dd><?= e($order['last_imported_at'] ?: 'Niet ingevuld') ?></dd>

                <dt>Aangemaakt</dt>
                <dd><?= e($order['created_at'] ?: 'Niet ingevuld') ?></dd>

                <dt>Laatst aangepast</dt>
                <dd><?= e($order['updated_at'] ?: 'Niet ingevuld') ?></dd>
            </dl>
        </article>
    </section>

    <section class="card">
        <h2>Logbook</h2>
        <p>Acties in het logbook worden niet overschreven door imports.</p>
    </section>

    <section class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Actie</th>
                    <th>Bedrag</th>
                    <th>Notitie</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logbookEntries)): ?>
                    <tr>
                        <td colspan="4">Nog geen logbookacties voor deze klant.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($logbookEntries as $entry): ?>
                    <tr>
                        <td><?= e($entry['created_at']) ?></td>
                        <td><?= e($entry['action_label']) ?></td>
                        <td>
                            <?php if ($entry['amount'] !== null && $entry['amount'] !== ''): ?>
                                <?= e(formatMoneyValue($entry['amount'])) ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?= e($entry['note']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card">
        <a href="index.php" class="button-secondary">Terug naar overzicht</a>
    </section>
</main>

<script>
    document.querySelectorAll('[data-copy]').forEach((button) => {
        button.addEventListener('click', async () => {
            const text = button.getAttribute('data-copy');
            const feedback = document.getElementById('copy-feedback');

            try {
                await navigator.clipboard.writeText(text);
                feedback.hidden = false;
                feedback.textContent = 'Gekopieerd.';

                setTimeout(() => {
                    feedback.hidden = true;
                }, 1800);
            } catch (error) {
                feedback.hidden = false;
                feedback.textContent = 'Kopiëren lukte niet. Selecteer en kopieer manueel.';
            }
        });
    });
</script>

</body>
</html>