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

$logStmt = $pdo->prepare("
    INSERT INTO customer_logbook (
        lease_order_id,
        so_number,
        action_type,
        action_label,
        amount,
        note
    ) VALUES (
        :lease_order_id,
        :so_number,
        :action_type,
        :action_label,
        :amount,
        :note
    )
");

$logStmt->execute([
    ':lease_order_id' => $order['id'],
    ':so_number' => $order['so_number'],
    ':action_type' => 'mail_composed',
    ':action_label' => 'Mail opgesteld via detailpagina',
    ':amount' => null,
    ':note' => 'Mailknop aangeklikt. Dit betekent dat er een mail werd opgesteld, niet noodzakelijk verzonden.',
]);

$email = trim((string) ($order['email'] ?? ''));

if ($email === '') {
    header('Location: contract-detail.php?id=' . $id . '&mail_logged=1&mail_missing=1');
    exit;
}

$subject = rawurlencode('Uw leasingfiets bij Aerts Action Bike');
$body = rawurlencode(
    "Dag " . ($order['customer_name'] ?: '') . ",\n\n" .
    "We nemen graag even contact met u op rond uw leasingfiets.\n\n" .
    "Fiets: " . ($order['bike_name'] ?: '') . "\n" .
    "SO-number: " . ($order['so_number'] ?: '') . "\n\n" .
    "Sportieve groeten,\n" .
    "Aerts Action Bike"
);

$mailto = 'mailto:' . rawurlencode($email) . '?subject=' . $subject . '&body=' . $body;

?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Mail openen | Lease Import Manager</title>
    <link rel="stylesheet" href="assets.css">
</head>
<body>

<header class="page-header">
    <h1>Mail wordt geopend</h1>
</header>

<main>
    <section class="card">
        <h2>Mailactie gelogd</h2>
        <p>De actie werd toegevoegd aan het logbook. Je mailprogramma wordt nu geopend.</p>

        <div class="quick-actions">
            <a class="button" href="<?= e($mailto) ?>">Mail openen</a>
            <a class="button-secondary" href="contract-detail.php?id=<?= e($id) ?>&mail_logged=1">Terug naar detailpagina</a>
        </div>
    </section>
</main>

<script>
    window.location.href = <?= json_encode($mailto) ?>;
</script>

</body>
</html>