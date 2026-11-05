<?php

require_once __DIR__ . '/../app/Database.php';
require_once __DIR__ . '/../app/CsvExport.php';
require_once __DIR__ . '/../app/ExpiringContracts.php';

$pdo = Database::connect();

$search = trim(is_string($_GET['search'] ?? null) ? $_GET['search'] : '');
$leasePartner = trim(is_string($_GET['lease_partner'] ?? null) ? $_GET['lease_partner'] : '');
$bikeType = trim(is_string($_GET['bike_type'] ?? null) ? $_GET['bike_type'] : '');
$contractStatus = trim(is_string($_GET['contract_status'] ?? null) ? $_GET['contract_status'] : '');

[$windowStart, $windowEnd] = ExpiringContracts::window(new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels')));

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        so_number LIKE :search_so
        OR customer_name LIKE :search_name
        OR email LIKE :search_email
        OR phone LIKE :search_phone
        OR company LIKE :search_company
        OR bike_name LIKE :search_bike
        OR frame_number LIKE :search_frame
    )";

    foreach (['so', 'name', 'email', 'phone', 'company', 'bike', 'frame'] as $field) {
        $params[':search_' . $field] = '%' . $search . '%';
    }
}

if ($leasePartner !== '') {
    $where[] = "lease_partner = :lease_partner";
    $params[':lease_partner'] = $leasePartner;
}

if ($bikeType !== '') {
    $where[] = "bike_type = :bike_type";
    $params[':bike_type'] = $bikeType;
}

if (in_array($contractStatus, ['active', 'expiring_soon', 'expired'], true)) $params[':filter_today'] = $windowStart->format('Y-m-d');
if ($contractStatus === 'expiring_soon') $params[':filter_end'] = $windowEnd->format('Y-m-d');

if ($contractStatus === 'active') {
    $where[] = "lease_end_date IS NOT NULL AND lease_end_date >= :filter_today";
}

if ($contractStatus === 'expiring_soon') {
    $where[] = "lease_end_date IS NOT NULL AND lease_end_date BETWEEN :filter_today AND :filter_end";
}

if ($contractStatus === 'expired') {
    $where[] = "lease_end_date IS NOT NULL AND lease_end_date < :filter_today";
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
    ORDER BY lease_start_date IS NULL ASC, lease_start_date DESC, created_at DESC, id ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

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
], ';', '"', '');

while ($order = $stmt->fetch()) {
    fputcsv($output, [
        CsvExport::cell($order['so_number']),
        CsvExport::cell($order['customer_name']),
        CsvExport::cell($order['email']),
        CsvExport::cell($order['phone']),
        CsvExport::cell($order['company']),
        CsvExport::cell($order['lease_partner']),
        CsvExport::cell($order['bike_type']),
        CsvExport::cell($order['bike_name']),
        CsvExport::cell($order['frame_number']),
        CsvExport::cell($order['lease_start_date']),
        CsvExport::cell($order['lease_end_date']),
        CsvExport::cell($order['maintenance_budget'], true),
        CsvExport::cell($order['yearly_maintenance_end_date']),
        CsvExport::cell($order['source_file']),
        CsvExport::cell($order['last_imported_at']),
        CsvExport::cell($order['created_at']),
        CsvExport::cell($order['updated_at']),
    ], ';', '"', '');
}

fclose($output);
exit;