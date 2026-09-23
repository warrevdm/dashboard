<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Database.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function normalizeDateValue($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        try {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d');
        } catch (Throwable $e) {
            return null;
        }
    }

    $value = trim((string) $value);

    $formats = [
        'd/m/Y',
        'd-m-Y',
        'Y-m-d',
        'd/m/y',
        'd-m-y',
    ];

    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $value);

        if ($date instanceof DateTime) {
            return $date->format('Y-m-d');
        }
    }

    return null;
}

function addMonthsToDate(?string $dateValue, int $months): ?string
{
    if (!$dateValue) {
        return null;
    }

    $date = DateTime::createFromFormat('Y-m-d', $dateValue);

    if (!$date) {
        return null;
    }

    $date->modify('+' . $months . ' months');

    return $date->format('Y-m-d');
}

function calculateNextAnnualMaintenanceEndDate(?string $startDateValue, ?string $contractEndDateValue): ?string
{
    if (!$startDateValue) {
        return null;
    }

    $startDate = DateTime::createFromFormat('Y-m-d', $startDateValue);

    if (!$startDate) {
        return null;
    }

    $today = new DateTime('today');
    $contractEndDate = null;

    if ($contractEndDateValue) {
        $contractEndDate = DateTime::createFromFormat('Y-m-d', $contractEndDateValue) ?: null;
    }

    if ($contractEndDate && $contractEndDate < $today) {
        return $contractEndDate->format('Y-m-d');
    }

    $maintenanceEndDate = clone $startDate;
    $maintenanceEndDate->modify('+1 year');

    while ($maintenanceEndDate < $today) {
        $maintenanceEndDate->modify('+1 year');
    }

    if ($contractEndDate && $maintenanceEndDate > $contractEndDate) {
        return $contractEndDate->format('Y-m-d');
    }

    return $maintenanceEndDate->format('Y-m-d');
}

function normalizeMoneyValue($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    $value = trim((string) $value);
    $value = str_replace(['€', ' '], '', $value);

    if (str_contains($value, ',') && str_contains($value, '.')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } else {
        $value = str_replace(',', '.', $value);
    }

    return is_numeric($value) ? (float) $value : null;
}

function normalizeOrderStatusValue($value): array
{
    $raw = trim((string) $value);

    if ($raw === '') {
        return [
            'raw' => '',
            'code' => '',
            'label' => '',
        ];
    }

    // Voorbeelden:
    // "5 delivered"
    // "3 Ordered"
    // "5 - delivered"
    // "3: Ordered"
    if (preg_match('/^(\d+)\s*[-:]?\s*(.*)$/', $raw, $matches)) {
        return [
            'raw' => $raw,
            'code' => trim($matches[1]),
            'label' => trim($matches[2]),
        ];
    }

    return [
        'raw' => $raw,
        'code' => '',
        'label' => $raw,
    ];
}

function normalizeBikeTypeValue($value): string
{
    $value = strtolower(trim((string) $value));

    if ($value === '') {
        return 'onbekend';
    }

    if (str_contains($value, 'speed')) {
        return 'speedpedelec';
    }

    if (str_contains($value, 'gravel')) {
        return 'gravelfiets';
    }

    if (str_contains($value, 'race') || str_contains($value, 'road')) {
        return 'racefiets';
    }

    if (str_contains($value, 'mountain') || str_contains($value, 'mtb')) {
        return 'mountainbike';
    }

    if (str_contains($value, 'longtail') || str_contains($value, 'bakfiets') || str_contains($value, 'cargo') || str_contains($value, 'familie')) {
        return 'elektrische gezinsfiets';
    }

    if (str_contains($value, 'e-bike') || str_contains($value, 'ebike') || str_contains($value, 'elektrisch')) {
        return 'elektrisch';
    }

    if (str_contains($value, 'city') || str_contains($value, 'stadsfiets') || str_contains($value, 'niet elektrisch')) {
        return 'niet elektrisch city bike';
    }

    return 'onbekend';
}

if (!isset($_POST['uploaded_file'], $_POST['mapping']) || !is_array($_POST['mapping'])) {
    die('Importgegevens ontbreken.');
}

$filename = basename((string) $_POST['uploaded_file']);
$filePath = __DIR__ . '/../storage/uploads/' . $filename;
$mapping = $_POST['mapping'];
$mappingName = preg_replace('/\s+/', ' ', trim((string) ($_POST['mapping_name'] ?? '')));

if (!file_exists($filePath)) {
    die('Bestand niet gevonden.');
}

try {
    $spreadsheet = IOFactory::load($filePath);
} catch (Throwable $e) {
    die('Excelbestand kon niet gelezen worden: ' . e($e->getMessage()));
}

$sheet = $spreadsheet->getActiveSheet();
$rows = $sheet->toArray();

array_shift($rows);

$pdo = Database::connect();

$importedRows = 0;
$updatedRows = 0;
$archivedUpdates = 0;
$skippedRows = 0;

if ($mappingName !== '') {
    $mappingJson = json_encode($mapping, JSON_UNESCAPED_UNICODE);

    $mappingStmt = $pdo->prepare("
        INSERT INTO import_mappings (
            mapping_name,
            mapping_json
        ) VALUES (
            :mapping_name,
            :mapping_json
        )
        ON DUPLICATE KEY UPDATE
            mapping_json = VALUES(mapping_json),
            updated_at = NOW()
    ");

    $mappingStmt->execute([
        ':mapping_name' => $mappingName,
        ':mapping_json' => $mappingJson,
    ]);
}

$batchStmt = $pdo->prepare("
    INSERT INTO import_batches (filename, total_rows)
    VALUES (:filename, :total_rows)
");

$batchStmt->execute([
    ':filename' => $filename,
    ':total_rows' => count($rows),
]);

$batchId = (int) $pdo->lastInsertId();

$existsStmt = $pdo->prepare("
    SELECT id, archived
    FROM lease_orders
    WHERE so_number = :so_number
");

$upsertStmt = $pdo->prepare("
    INSERT INTO lease_orders (
        so_number,
        customer_name,
        email,
        phone,
        company,
        lease_partner,
        order_status_raw,
        order_status_code,
        order_status_label,
        bike_type,
        bike_name,
        frame_number,
        lease_start_date,
        lease_end_date,
        maintenance_budget,
        yearly_maintenance_end_date,
        o2o_budget_grace_start_date,
        o2o_budget_valid_until,
        cyclis_budget_grace_start_date,
        cyclis_budget_valid_until,
        source_file,
        last_imported_at
    ) VALUES (
        :so_number,
        :customer_name,
        :email,
        :phone,
        :company,
        :lease_partner,
        :order_status_raw,
        :order_status_code,
        :order_status_label,
        :bike_type,
        :bike_name,
        :frame_number,
        :lease_start_date,
        :lease_end_date,
        :maintenance_budget,
        :yearly_maintenance_end_date,
        :o2o_budget_grace_start_date,
        :o2o_budget_valid_until,
        :cyclis_budget_grace_start_date,
        :cyclis_budget_valid_until,
        :source_file,
        NOW()
    )
    ON DUPLICATE KEY UPDATE
        customer_name = VALUES(customer_name),
        email = VALUES(email),
        phone = VALUES(phone),
        company = VALUES(company),
        lease_partner = VALUES(lease_partner),
        order_status_raw = VALUES(order_status_raw),
        order_status_code = VALUES(order_status_code),
        order_status_label = VALUES(order_status_label),
        bike_type = VALUES(bike_type),
        bike_name = VALUES(bike_name),
        frame_number = VALUES(frame_number),
        lease_start_date = VALUES(lease_start_date),
        lease_end_date = VALUES(lease_end_date),
        maintenance_budget = VALUES(maintenance_budget),
        yearly_maintenance_end_date = VALUES(yearly_maintenance_end_date),
        o2o_budget_grace_start_date = VALUES(o2o_budget_grace_start_date),
        o2o_budget_valid_until = VALUES(o2o_budget_valid_until),
        cyclis_budget_grace_start_date = VALUES(cyclis_budget_grace_start_date),
        cyclis_budget_valid_until = VALUES(cyclis_budget_valid_until),
        source_file = VALUES(source_file),
        last_imported_at = NOW()
");

$errorStmt = $pdo->prepare("
    INSERT INTO import_errors (
        import_batch_id,
        row_number,
        so_number,
        error_message,
        raw_data
    ) VALUES (
        :import_batch_id,
        :row_number,
        :so_number,
        :error_message,
        :raw_data
    )
");

$createdRecordStmt = $pdo->prepare("
    INSERT INTO import_created_records (
        import_batch_id,
        lease_order_id,
        so_number
    ) VALUES (
        :import_batch_id,
        :lease_order_id,
        :so_number
    )
");

foreach ($rows as $rowIndex => $row) {
    $data = [];

    foreach ($mapping as $field => $columnIndex) {
        $data[$field] = $columnIndex !== '' ? ($row[(int) $columnIndex] ?? null) : null;
    }

    $soNumber = trim((string) ($data['so_number'] ?? ''));

    if ($soNumber === '') {
        $skippedRows++;

        $errorStmt->execute([
            ':import_batch_id' => $batchId,
            ':row_number' => $rowIndex + 2,
            ':so_number' => null,
            ':error_message' => 'Rij overgeslagen: SO-number ontbreekt.',
            ':raw_data' => json_encode($row, JSON_UNESCAPED_UNICODE),
        ]);

        continue;
    }

    $leasePartnerValue = trim((string) ($data['lease_partner'] ?? ''));
    $normalizedLeasePartner = strtolower($leasePartnerValue);
    $normalizedMappingName = strtolower($mappingName);

    $isLeaseABikeImport = str_contains($normalizedLeasePartner, 'lease a bike')
        || str_contains($normalizedMappingName, 'lease a bike');

    if ($isLeaseABikeImport) {
        $leasePartnerValue = 'Lease a Bike';
        $normalizedLeasePartner = 'lease a bike';
    }

    $orderStatus = normalizeOrderStatusValue($data['order_status'] ?? '');

    $leaseStartDateValue = normalizeDateValue($data['lease_start_date'] ?? null);
    $leaseEndDateValue = normalizeDateValue($data['lease_end_date'] ?? null);
    $yearlyMaintenanceEndDateValue = normalizeDateValue($data['yearly_maintenance_end_date'] ?? null);

    $o2oBudgetGraceStartDate = null;
    $o2oBudgetValidUntil = null;
    $cyclisBudgetGraceStartDate = null;
    $cyclisBudgetValidUntil = null;

    if ($normalizedLeasePartner === 'o2o' && $leaseStartDateValue) {
        $o2oBudgetGraceStartDate = addMonthsToDate($leaseStartDateValue, 36);
        $o2oBudgetValidUntil = addMonthsToDate($leaseStartDateValue, 37);

        if (!$leaseEndDateValue) {
            $leaseEndDateValue = $o2oBudgetGraceStartDate;
        }

        // O2O heeft geen klassiek jaarlijks onderhoudscontract.
        // De extra maand is een aparte budgetperiode.
        $yearlyMaintenanceEndDateValue = null;
    }

    if ($normalizedLeasePartner === 'cyclis' && $leaseEndDateValue) {
        $cyclisBudgetGraceStartDate = $leaseEndDateValue;
        $cyclisBudgetValidUntil = addMonthsToDate($leaseEndDateValue, 12);

        // Cyclis-budgetperiode is een aparte teller.
        // Niet gelijkstellen aan yearly_maintenance_end_date.
        $yearlyMaintenanceEndDateValue = null;
    }

    if ($isLeaseABikeImport && $leaseStartDateValue) {
        // Lease a Bike werkt met een jaarlijks onderhoudsbudget.
        // We bewaren telkens de eerstvolgende jaarlijkse vervaldatum,
        // begrensd op de contracteinddatum.
        $yearlyMaintenanceEndDateValue = calculateNextAnnualMaintenanceEndDate(
            $leaseStartDateValue,
            $leaseEndDateValue
        );
    }

    $existsStmt->execute([
        ':so_number' => $soNumber,
    ]);

    $exists = $existsStmt->fetch();

    $upsertStmt->execute([
        ':so_number' => $soNumber,
        ':customer_name' => trim((string) ($data['customer_name'] ?? '')),
        ':email' => trim((string) ($data['email'] ?? '')),
        ':phone' => trim((string) ($data['phone'] ?? '')),
        ':company' => trim((string) ($data['company'] ?? '')),
        ':lease_partner' => $leasePartnerValue,
        ':order_status_raw' => $orderStatus['raw'],
        ':order_status_code' => $orderStatus['code'],
        ':order_status_label' => $orderStatus['label'],
        ':bike_type' => normalizeBikeTypeValue($data['bike_type'] ?? ''),
        ':bike_name' => trim((string) ($data['bike_name'] ?? '')),
        ':frame_number' => trim((string) ($data['frame_number'] ?? '')),
        ':lease_start_date' => $leaseStartDateValue,
        ':lease_end_date' => $leaseEndDateValue,
        ':maintenance_budget' => normalizeMoneyValue($data['maintenance_budget'] ?? null),
        ':yearly_maintenance_end_date' => $yearlyMaintenanceEndDateValue,
        ':o2o_budget_grace_start_date' => $o2oBudgetGraceStartDate,
        ':o2o_budget_valid_until' => $o2oBudgetValidUntil,
        ':cyclis_budget_grace_start_date' => $cyclisBudgetGraceStartDate,
        ':cyclis_budget_valid_until' => $cyclisBudgetValidUntil,
        ':source_file' => $filename,
    ]);

    if ($exists) {
        if ((int) $exists['archived'] === 1) {
            $archivedUpdates++;
        } else {
            $updatedRows++;
        }
    } else {
        $importedRows++;

        $leaseOrderId = (int) $pdo->lastInsertId();

        if ($leaseOrderId > 0) {
            $createdRecordStmt->execute([
                ':import_batch_id' => $batchId,
                ':lease_order_id' => $leaseOrderId,
                ':so_number' => $soNumber,
            ]);
        }
    }
}

$updateBatchStmt = $pdo->prepare("
    UPDATE import_batches
    SET imported_rows = :imported_rows,
        updated_rows = :updated_rows,
        archived_updates = :archived_updates,
        skipped_rows = :skipped_rows
    WHERE id = :id
");

$updateBatchStmt->execute([
    ':imported_rows' => $importedRows,
    ':updated_rows' => $updatedRows,
    ':archived_updates' => $archivedUpdates,
    ':skipped_rows' => $skippedRows,
    ':id' => $batchId,
]);

?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Import voltooid | Lease Import Manager</title>
    <link rel="stylesheet" href="assets.css">
</head>
<body>

<header class="page-header">
    <h1>Import voltooid</h1>

    <?php require __DIR__ . '/partials/navigation.php'; ?>
</header>

<main>
    <section class="card">
        <h2>Import succesvol afgerond</h2>

        <p>De Excel-import is verwerkt. Controleer hieronder de samenvatting en kies meteen je volgende actie.</p>

        <?php if ($skippedRows > 0): ?>
            <p class="alert-warning">
                Let op: <?= e($skippedRows) ?> rij(en) zijn overgeslagen. Controleer de importfouten.
            </p>
        <?php else: ?>
            <p class="alert-success">
                Import verwerkt zonder overgeslagen rijen.
            </p>
        <?php endif; ?>
    </section>

    <section class="dashboard-grid">
        <article class="dashboard-card">
            <span class="dashboard-label">Nieuwe records</span>
            <strong><?= e($importedRows) ?></strong>
            <small>Aangemaakt in database</small>
        </article>

        <article class="dashboard-card">
            <span class="dashboard-label">Geüpdatet actief</span>
            <strong><?= e($updatedRows) ?></strong>
            <small>Bestaande actieve contracten</small>
        </article>

        <article class="dashboard-card">
            <span class="dashboard-label">Geüpdatet archief</span>
            <strong><?= e($archivedUpdates) ?></strong>
            <small>Gearchiveerd gebleven</small>
        </article>

        <article class="dashboard-card">
            <span class="dashboard-label">Overgeslagen</span>
            <strong><?= e($skippedRows) ?></strong>
            <small>Niet verwerkt</small>
        </article>
    </section>

    <section class="card">
        <h2>Volgende acties</h2>

        <div class="quick-actions">
            <a class="button" href="index.php">
                Bekijk alle contracten
            </a>

            <a class="button-secondary" href="import-batch-detail.php?id=<?= e($batchId) ?>">
                Bekijk importdetail
            </a>

            <a class="button-secondary" href="import-errors.php">
                Bekijk fouten
            </a>

            <a class="button-secondary" href="export-contracts.php">
                Export CSV
            </a>

            <a class="button-secondary" href="upload.php">
                Nieuwe import starten
            </a>
        </div>
    </section>
</main>

</body>
</html>