<?php

require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

$search = trim($_GET['search'] ?? '');
$leasePartner = trim($_GET['lease_partner'] ?? '');
$bikeType = trim($_GET['bike_type'] ?? '');
$contractStatus = trim($_GET['contract_status'] ?? '');

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        so_number LIKE :search
        OR customer_name LIKE :search
        OR email LIKE :search
        OR phone LIKE :search
        OR company LIKE :search
        OR bike_name LIKE :search
        OR frame_number LIKE :search
    )";

    $params[':search'] = '%' . $search . '%';
}

if ($leasePartner !== '') {
    $where[] = "lease_partner = :lease_partner";
    $params[':lease_partner'] = $leasePartner;
}

if ($bikeType !== '') {
    $where[] = "bike_type = :bike_type";
    $params[':bike_type'] = $bikeType;
}

if ($contractStatus === 'active') {
    $where[] = "lease_end_date IS NOT NULL AND lease_end_date >= CURDATE()";
}

if ($contractStatus === 'expiring_soon') {
    $where[] = "lease_end_date IS NOT NULL AND lease_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 MONTH)";
}

if ($contractStatus === 'expired') {
    $where[] = "lease_end_date IS NOT NULL AND lease_end_date < CURDATE()";
}

if ($contractStatus === 'no_end_date') {
    $where[] = "lease_end_date IS NULL";
}

$where[] = "archived = 0";

$whereSql = '';

if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}

$sql = "
    SELECT
        so_number,
        customer_name,
        email,
        phone,
        company,
        lease_partner,
        bike_type,
        bike_name,
        frame_number,
        lease_start_date,
        lease_end_date,
        maintenance_budget,
        yearly_maintenance_end_date,
        source_file,
        last_imported_at,
        created_at,
        updated_at
    FROM lease_orders
    $whereSql
    ORDER BY lease_start_date DESC, created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$filename = 'lease-contracten-export-' . date('Y-m-d-His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// UTF-8 BOM zodat Excel accenten correct opent
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($output, [
    'SO-number',
    'Naam',
    'Email',
    'Telefoon',
    'Bedrijf',
    'Leasepartner',
    'Fietstype',
    'Fietsnaam',
    'Framenummer',
    'Startdatum leasingcontract',
    'Einddatum leasingcontract',
    'Beschikbaar onderhoudsbudget',
    'Einde jaarlijks onderhoudscontract',
    'Bronbestand',
    'Laatst geïmporteerd',
    'Aangemaakt op',
    'Laatst aangepast op',
], ';');

foreach ($orders as $order) {
    fputcsv($output, [
        $order['so_number'],
        $order['customer_name'],
        $order['email'],
        $order['phone'],
        $order['company'],
        $order['lease_partner'],
        $order['bike_type'],
        $order['bike_name'],
        $order['frame_number'],
        $order['lease_start_date'],
        $order['lease_end_date'],
        $order['maintenance_budget'],
        $order['yearly_maintenance_end_date'],
        $order['source_file'],
        $order['last_imported_at'],
        $order['created_at'],
        $order['updated_at'],
    ], ';');
}

fclose($output);
exit;