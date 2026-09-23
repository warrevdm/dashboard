<?php

require_once __DIR__ . '/../app/bootstrap.php';
Auth::requirePost();
require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    die('Ongeldig contract.');
}

function cleanMoney($value): ?float
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    $value = str_replace(['€', ' '], '', $value);

    if (str_contains($value, ',') && str_contains($value, '.')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } else {
        $value = str_replace(',', '.', $value);
    }

    return is_numeric($value) ? (float) $value : null;
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

$amount = cleanMoney($_POST['amount'] ?? '');
$note = trim((string) ($_POST['note'] ?? ''));

if ($amount === null) {
    die('Bedrag is verplicht.');
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
    ':action_type' => 'budget_converted_to_store_spend',
    ':action_label' => 'Resterend budget omgezet naar winkeluitgave',
    ':amount' => $amount,
    ':note' => $note !== '' ? $note : 'Resterend budget intern geregistreerd als winkeluitgave. Bronbudget uit import werd niet aangepast.',
]);

header('Location: contract-detail.php?id=' . $id . '&budget_logged=1');
exit;