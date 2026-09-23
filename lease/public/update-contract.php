<?php

require_once __DIR__ . '/../app/bootstrap.php';
Auth::requirePost();
require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

function cleanText($value): string
{
    return trim((string) $value);
}

function cleanDate($value): ?string
{
    $value = trim((string) $value);

    return $value === '' ? null : $value;
}

function cleanMoney($value): ?float
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    return is_numeric($value) ? (float) $value : null;
}

$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    die('Ongeldig contract.');
}

$soNumber = cleanText($_POST['so_number'] ?? '');

if ($soNumber === '') {
    die('SO-number is verplicht.');
}

$stmt = $pdo->prepare("
    UPDATE lease_orders
    SET
        so_number = :so_number,
        customer_name = :customer_name,
        email = :email,
        phone = :phone,
        company = :company,
        lease_partner = :lease_partner,
        bike_type = :bike_type,
        bike_name = :bike_name,
        frame_number = :frame_number,
        lease_start_date = :lease_start_date,
        lease_end_date = :lease_end_date,
        maintenance_budget = :maintenance_budget,
        yearly_maintenance_end_date = :yearly_maintenance_end_date
    WHERE id = :id
");

try {
    $stmt->execute([
        ':so_number' => $soNumber,
        ':customer_name' => cleanText($_POST['customer_name'] ?? ''),
        ':email' => cleanText($_POST['email'] ?? ''),
        ':phone' => cleanText($_POST['phone'] ?? ''),
        ':company' => cleanText($_POST['company'] ?? ''),
        ':lease_partner' => cleanText($_POST['lease_partner'] ?? ''),
        ':bike_type' => cleanText($_POST['bike_type'] ?? 'onbekend'),
        ':bike_name' => cleanText($_POST['bike_name'] ?? ''),
        ':frame_number' => cleanText($_POST['frame_number'] ?? ''),
        ':lease_start_date' => cleanDate($_POST['lease_start_date'] ?? ''),
        ':lease_end_date' => cleanDate($_POST['lease_end_date'] ?? ''),
        ':maintenance_budget' => cleanMoney($_POST['maintenance_budget'] ?? ''),
        ':yearly_maintenance_end_date' => cleanDate($_POST['yearly_maintenance_end_date'] ?? ''),
        ':id' => $id,
    ]);
} catch (PDOException $e) {
    die('Opslaan mislukt. Mogelijk bestaat deze SO-number al bij een ander contract.');
}

header('Location: contract-detail.php?id=' . $id . '&updated=1');
exit;