<?php

require_once __DIR__ . '/../app/bootstrap.php';
Auth::requirePost();

require_once __DIR__ . '/../vendor/autoload.php';

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

function mappedValue(array $row, array $mapping, string $field)
{
    if (!isset($mapping[$field]) || $mapping[$field] === '') {
        return null;
    }

    return $row[(int) $mapping[$field]] ?? null;
}

if (!isset($_POST['uploaded_file'], $_POST['mapping']) || !is_array($_POST['mapping'])) {
    die('Importgegevens ontbreken.');
}

$filename = basename((string) $_POST['uploaded_file']);
$filePath = __DIR__ . '/../storage/uploads/' . $filename;
$mapping = $_POST['mapping'];
$mappingName = trim((string) ($_POST['mapping_name'] ?? ''));

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

$previewRows = array_slice($rows, 0, 10);
$totalRows = count($rows);

$mappedPreview = [];

foreach ($previewRows as $rowIndex => $row) {
    $mappedPreview[] = [
        'excel_row' => $rowIndex + 2,
        'so_number' => trim((string) mappedValue($row, $mapping, 'so_number')),
        'customer_name' => trim((string) mappedValue($row, $mapping, 'customer_name')),
        'email' => trim((string) mappedValue($row, $mapping, 'email')),
        'phone' => trim((string) mappedValue($row, $mapping, 'phone')),
        'company' => trim((string) mappedValue($row, $mapping, 'company')),
        'lease_partner' => trim((string) mappedValue($row, $mapping, 'lease_partner')),
        'order_status' => trim((string) mappedValue($row, $mapping, 'order_status')),
        'bike_type' => normalizeBikeTypeValue(mappedValue($row, $mapping, 'bike_type')),
        'bike_name' => trim((string) mappedValue($row, $mapping, 'bike_name')),
        'frame_number' => trim((string) mappedValue($row, $mapping, 'frame_number')),
        'lease_start_date' => normalizeDateValue(mappedValue($row, $mapping, 'lease_start_date')),
        'lease_end_date' => normalizeDateValue(mappedValue($row, $mapping, 'lease_end_date')),
        'maintenance_budget' => normalizeMoneyValue(mappedValue($row, $mapping, 'maintenance_budget')),
        'yearly_maintenance_end_date' => normalizeDateValue(mappedValue($row, $mapping, 'yearly_maintenance_end_date')),
    ];
}

$missingSoNumbers = 0;

foreach ($rows as $row) {
    $soNumber = trim((string) mappedValue($row, $mapping, 'so_number'));

    if ($soNumber === '') {
        $missingSoNumbers++;
    }
}

?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Import preview | Lease Import Manager</title>
    <link rel="stylesheet" href="assets.css">
</head>
<body>

<header class="page-header">
    <h1>Import preview</h1>

    <?php require __DIR__ . '/partials/navigation.php'; ?>
</header>

<main>
    <section class="card">
        <h2>Controle vóór definitieve import</h2>

        <p><strong>Bestand:</strong> <?= e($filename) ?></p>
        <p><strong>Mappingtemplate:</strong> <?= e($mappingName ?: 'Niet opgeslagen') ?></p>
        <p><strong>Totaal aantal rijen:</strong> <?= e($totalRows) ?></p>
        <p><strong>Rijen zonder SO-number:</strong> <?= e($missingSoNumbers) ?></p>

        <?php if ($missingSoNumbers > 0): ?>
            <p class="alert-warning">
                Let op: <?= e($missingSoNumbers) ?> rij(en) hebben geen SO-number en zullen worden overgeslagen.
            </p>
        <?php endif; ?>

        <p>Controleer hieronder de eerste 10 rijen. Als de kolommen fout gekoppeld zijn, ga terug en pas de mapping aan.</p>
    </section>

    <section class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Excel-rij</th>
                    <th>SO-number</th>
                    <th>Naam</th>
                    <th>Email</th>
                    <th>Telefoon</th>
                    <th>Bedrijf</th>
                    <th>Leasepartner</th>
                    <th>Orderstatus</th>
                    <th>Fietstype</th>
                    <th>Fietsnaam</th>
                    <th>Framenummer</th>
                    <th>Start contract</th>
                    <th>Einde contract</th>
                    <th>Budget</th>
                    <th>Einde onderhoud</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($mappedPreview as $row): ?>
                    <tr class="<?= $row['so_number'] === '' ? 'row-warning' : '' ?>">
                        <td><?= e($row['excel_row']) ?></td>
                        <td><?= e($row['so_number']) ?></td>
                        <td><?= e($row['customer_name']) ?></td>
                        <td><?= e($row['email']) ?></td>
                        <td><?= e($row['phone']) ?></td>
                        <td><?= e($row['company']) ?></td>
                        <td><?= e($row['lease_partner']) ?></td>
                        <td><?= e($row['order_status']) ?></td>
                        <td><?= e($row['bike_type']) ?></td>
                        <td><?= e($row['bike_name']) ?></td>
                        <td><?= e($row['frame_number']) ?></td>
                        <td><?= e($row['lease_start_date']) ?></td>
                        <td><?= e($row['lease_end_date']) ?></td>
                        <td>€ <?= e($row['maintenance_budget']) ?></td>
                        <td><?= e($row['yearly_maintenance_end_date']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card">
        <form action="import-process.php" method="POST">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="uploaded_file" value="<?= e($filename) ?>">
            <input type="hidden" name="mapping_name" value="<?= e($mappingName) ?>">

            <?php foreach ($mapping as $field => $columnIndex): ?>
                <input
                    type="hidden"
                    name="mapping[<?= e($field) ?>]"
                    value="<?= e($columnIndex) ?>"
                >
            <?php endforeach; ?>

            <button type="submit">Definitief importeren</button>
            <a href="upload.php" class="button-secondary">Annuleren en opnieuw uploaden</a>
        </form>
    </section>
</main>

</body>
</html>
