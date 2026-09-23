<?php

require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

$stmt = $pdo->query("
    SELECT *
    FROM lease_orders
    WHERE archived = 1
    ORDER BY archived_at DESC, updated_at DESC
");

$orders = $stmt->fetchAll();

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Archief | Lease Import Manager</title>
    <link rel="stylesheet" href="assets.css">
</head>
<body>

<header class="page-header">
    <h1>Gearchiveerde contracten</h1>

    <?php require __DIR__ . '/partials/navigation.php'; ?>
</header>

<main>
    <section class="card">
        <h2>Archief</h2>
        <p>Deze contracten zijn gearchiveerd en verschijnen niet meer in het standaardoverzicht of de opvolglijsten.</p>
    </section>

    <section class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>SO-number</th>
                    <th>Naam</th>
                    <th>Email</th>
                    <th>Telefoon</th>
                    <th>Bedrijf</th>
                    <th>Leasepartner</th>
                    <th>Fiets</th>
                    <th>Type</th>
                    <th>Einde contract</th>
                    <th>Gearchiveerd op</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="10">Geen gearchiveerde contracten.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($orders as $order): ?>
                    <tr>
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
                        <td><?= e($order['archived_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>

</body>
</html>