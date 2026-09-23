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

$bikeTypes = [
    'elektrisch',
    'racefiets',
    'gravelfiets',
    'speedpedelec',
    'elektrische gezinsfiets',
    'niet elektrisch city bike',
    'mountainbike',
    'onbekend',
];

?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Contract bewerken <?= e($order['so_number']) ?> | Lease Import Manager</title>
    <link rel="stylesheet" href="assets.css">
</head>
<body>

<header class="page-header">
    <h1>Contract bewerken</h1>

    <?php require __DIR__ . '/partials/navigation.php'; ?>
</header>

<main>
    <section class="card">
        <h2><?= e($order['so_number']) ?></h2>
        <p>Pas ontbrekende of foutieve gegevens manueel aan. De SO-number blijft de unieke sleutel.</p>
    </section>

    <form action="update-contract.php" method="POST" class="card">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="id" value="<?= e($order['id']) ?>">

        <h2>Basisgegevens</h2>

        <div class="form-grid two-columns">
            <div class="form-group">
                <label for="so_number">SO-number</label>
                <input type="text" name="so_number" id="so_number" value="<?= e($order['so_number']) ?>" required>
            </div>

            <div class="form-group">
                <label for="customer_name">Naam</label>
                <input type="text" name="customer_name" id="customer_name" value="<?= e($order['customer_name']) ?>">
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input type="text" name="email" id="email" value="<?= e($order['email']) ?>">
            </div>

            <div class="form-group">
                <label for="phone">Telefoon</label>
                <input type="text" name="phone" id="phone" value="<?= e($order['phone']) ?>">
            </div>

            <div class="form-group">
                <label for="company">Bedrijf</label>
                <input type="text" name="company" id="company" value="<?= e($order['company']) ?>">
            </div>

            <div class="form-group">
                <label for="lease_partner">Leasepartner</label>
                <input type="text" name="lease_partner" id="lease_partner" value="<?= e($order['lease_partner']) ?>">
            </div>
        </div>

        <h2>Fietsgegevens</h2>

        <div class="form-grid two-columns">
            <div class="form-group">
                <label for="bike_type">Fietstype</label>
                <select name="bike_type" id="bike_type">
                    <?php foreach ($bikeTypes as $type): ?>
                        <option value="<?= e($type) ?>" <?= $order['bike_type'] === $type ? 'selected' : '' ?>>
                            <?= e($type) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="bike_name">Fietsnaam</label>
                <input type="text" name="bike_name" id="bike_name" value="<?= e($order['bike_name']) ?>">
            </div>

            <div class="form-group">
                <label for="frame_number">Framenummer</label>
                <input type="text" name="frame_number" id="frame_number" value="<?= e($order['frame_number']) ?>">
            </div>
        </div>

        <h2>Leasing & onderhoud</h2>

        <div class="form-grid two-columns">
            <div class="form-group">
                <label for="lease_start_date">Startdatum leasingcontract</label>
                <input type="date" name="lease_start_date" id="lease_start_date" value="<?= e($order['lease_start_date']) ?>">
            </div>

            <div class="form-group">
                <label for="lease_end_date">Einddatum leasingcontract</label>
                <input type="date" name="lease_end_date" id="lease_end_date" value="<?= e($order['lease_end_date']) ?>">
            </div>

            <div class="form-group">
                <label for="maintenance_budget">Beschikbaar onderhoudsbudget</label>
                <input type="number" step="0.01" name="maintenance_budget" id="maintenance_budget" value="<?= e($order['maintenance_budget']) ?>">
            </div>

            <div class="form-group">
                <label for="yearly_maintenance_end_date">Einde jaarlijks onderhoudscontract</label>
                <input type="date" name="yearly_maintenance_end_date" id="yearly_maintenance_end_date" value="<?= e($order['yearly_maintenance_end_date']) ?>">
            </div>
        </div>

        <div class="filter-actions">
            <button type="submit">Wijzigingen opslaan</button>
            <a href="contract-detail.php?id=<?= e($order['id']) ?>" class="button-secondary">Annuleren</a>
        </div>
    </form>
</main>

</body>
</html>