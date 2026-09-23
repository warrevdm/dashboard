<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Database.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$uploadDir = __DIR__ . '/../storage/uploads/';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$allowedExtensions = ['xlsx', 'xls', 'csv'];

if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
    $originalName = basename($_FILES['excel_file']['name']);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions, true)) {
        die('Ongeldig bestandstype. Upload een .xlsx, .xls of .csv bestand.');
    }

    $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
    $targetPath = $uploadDir . $filename;

    if (!move_uploaded_file($_FILES['excel_file']['tmp_name'], $targetPath)) {
        die('Upload mislukt.');
    }
} elseif (isset($_POST['uploaded_file'])) {
    $filename = basename((string) $_POST['uploaded_file']);
    $targetPath = $uploadDir . $filename;
    $originalName = $filename;

    if (!file_exists($targetPath)) {
        die('Eerder geüpload bestand niet gevonden. Upload het bestand opnieuw.');
    }
} else {
    die('Geen bestand ontvangen.');
}

try {
    $spreadsheet = IOFactory::load($targetPath);
} catch (Throwable $e) {
    die('Excelbestand kon niet gelezen worden: ' . e($e->getMessage()));
}

$sheet = $spreadsheet->getActiveSheet();
$rows = $sheet->toArray();

$headers = $rows[0] ?? [];
$pdo = Database::connect();

$templatesStmt = $pdo->query("
    SELECT *
    FROM import_mappings
    ORDER BY mapping_name ASC, updated_at DESC
");

$mappingTemplates = $templatesStmt->fetchAll();

$selectedTemplateId = trim((string) ($_POST['template_id'] ?? $_GET['template_id'] ?? ''));
$selectedTemplateMapping = null;

if ($selectedTemplateId !== '') {
    $templateStmt = $pdo->prepare("
        SELECT *
        FROM import_mappings
        WHERE id = :id
        LIMIT 1
    ");

    $templateStmt->execute([
        ':id' => $selectedTemplateId,
    ]);

    $selectedTemplate = $templateStmt->fetch();

    if ($selectedTemplate) {
        $decodedMapping = json_decode($selectedTemplate['mapping_json'], true);

        if (is_array($decodedMapping)) {
            $selectedTemplateMapping = $decodedMapping;
        }
    }
}

$requiredFields = [
    'so_number' => 'SO-number',
    'customer_name' => 'Naam',
    'email' => 'Email',
    'phone' => 'Telefoonnummer',
    'company' => 'Bedrijf',
    'lease_partner' => 'Leasepartner',
    'order_status' => 'Orderstatus',
    'bike_type' => 'Soort fiets',
    'bike_name' => 'Fietsnaam',
    'frame_number' => 'Framenummer',
    'lease_start_date' => 'Startdatum leasingcontract',
    'lease_end_date' => 'Einddatum leasingcontract',
    'maintenance_budget' => 'Beschikbaar onderhoudsbudget',
    'yearly_maintenance_end_date' => 'Einde jaarlijks onderhoudscontract',
];

$fieldSynonyms = [
    'so_number' => [
        'so number',
        'so-number',
        'so_number',
        'sonumber',
        'sales order',
        'sales order #',
        'salesorder',
        'order number',
        'ordernumber',
        'bestelnummer',
        'bestel nr',
        'bestelnr',
        'bestelcode',
        'contract id',
        'contract-id',
        'contract_id',
        'contractnummer',
        'contract number',
        'order nr',
        'ordernr',
    ],
    'customer_name' => [
        'naam',
        'name',
        'customer',
        'customer name',
        'klant',
        'klantnaam',
        'gebruiker',
        'voornaam naam',
        'full name',
        'fullname',
    ],
    'email' => [
        'email',
        'e-mail',
        'mail',
        'email address',
        'e-mailadres',
        'emailadres',
    ],
    'phone' => [
        'telefoon',
        'telefoonnummer',
        'phone',
        'mobile',
        'gsm',
        'phone number',
        'tel',
    ],
    'company' => [
        'bedrijf',
        'company',
        'employer',
        'werkgever',
        'onderneming',
        'firma',
        'leasemaatschappij',
    ],
    'lease_partner' => [
        'leasepartner',
        'lease partner',
        'leasingpartner',
        'leasing partner',
        'partner',
        'leasing',
    ],
    'order_status' => [
        'status',
        'order status',
        'orderstatus',
        'bestelstatus',
        'fiets status',
        'bike status',
        'delivery status',
        'leverstatus',
        'levering status',
    ],

    'bike_type' => [
        'soort fiets',
        'type fiets',
        'bike type',
        'fietstype',
        'categorie',
        'category',
    ],
    'bike_name' => [
        'fietsnaam',
        'fiets',
        'bike',
        'bike name',
        'model',
        'product',
        'artikel',
        'description',
        'omschrijving',
    ],
    'frame_number' => [
        'framenummer',
        'frame number',
        'frame',
        'frame no',
        'frame nr',
        'serienummer',
        'serial',
        'serial number',
    ],
    'lease_start_date' => [
        'startdatum',
        'start date',
        'lease start',
        'leasing start',
        'startdatum leasing',
        'startdatum leasingcontract',
        'startdatum leasecontract',
        'contract start',
        'begindatum',
    ],
    'lease_end_date' => [
        'einddatum',
        'end date',
        'lease end',
        'leasing end',
        'einddatum leasing',
        'einddatum leasingcontract',
        'einddatum leasecontract',
        'contract end',
        'contract einde',
        'einde contract',
    ],
    'maintenance_budget' => [
        'budget',
        'onderhoudsbudget',
        'beschikbaar budget',
        'beschikbaar onderhoudsbudget',
        'maintenance budget',
        'service budget',
        'available budget',
    ],
    'yearly_maintenance_end_date' => [
        'einde jaarlijks onderhoudscontract',
        'onderhoud einddatum',
        'einddatum onderhoud',
        'maintenance end',
        'service end',
        'yearly maintenance end',
        'einde onderhoud',
    ],
];

function normalizeHeaderName($value): string
{
    $value = strtolower(trim((string) $value));
    $value = str_replace(['_', '-', '.', ':', ';', '/', '\\', '(', ')', '[', ']'], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);

    return trim($value);
}

function guessColumnIndex(string $fieldKey, array $headers, array $fieldSynonyms): ?int
{
    $synonyms = $fieldSynonyms[$fieldKey] ?? [];

    foreach ($headers as $index => $header) {
        $normalizedHeader = normalizeHeaderName($header);

        foreach ($synonyms as $synonym) {
            $normalizedSynonym = normalizeHeaderName($synonym);

            if ($normalizedHeader === $normalizedSynonym) {
                return (int) $index;
            }
        }
    }

    foreach ($headers as $index => $header) {
        $normalizedHeader = normalizeHeaderName($header);

        foreach ($synonyms as $synonym) {
            $normalizedSynonym = normalizeHeaderName($synonym);

            if (
                $normalizedSynonym !== ''
                && str_contains($normalizedHeader, $normalizedSynonym)
            ) {
                return (int) $index;
            }
        }
    }

    return null;
}

$guessedMapping = [];

foreach ($requiredFields as $fieldKey => $fieldLabel) {
    $guessedMapping[$fieldKey] = guessColumnIndex($fieldKey, $headers, $fieldSynonyms);
}

if (is_array($selectedTemplateMapping)) {
    foreach ($requiredFields as $fieldKey => $fieldLabel) {
        if (
            isset($selectedTemplateMapping[$fieldKey])
            && $selectedTemplateMapping[$fieldKey] !== ''
        ) {
            $guessedMapping[$fieldKey] = (int) $selectedTemplateMapping[$fieldKey];
        }
    }
}

?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Kolommen mappen | Lease Import Manager</title>
    <link rel="stylesheet" href="assets.css">
</head>
<body>

<header class="page-header">
    <h1>Kolommen mappen</h1>

    <?php require __DIR__ . '/partials/navigation.php'; ?>
</header>

<main>
    <section class="card">
        <h2>Koppel Excel-kolommen aan databasevelden</h2>
        <p>Bestand: <strong><?= e($originalName) ?></strong></p>
        <p>Kies per veld welke kolom uit de Excel gebruikt moet worden. Velden die je niet wil importeren laat je op “Niet importeren”.</p>
        <p><strong>Tip:</strong> het systeem vult de meest waarschijnlijke kolommen automatisch in. Controleer de selectie altijd vóór je importeert.</p>
    </section>

    <section class="card">
    <h2>Bestaande template gebruiken</h2>
    <p>Kies een opgeslagen mappingtemplate om de kolommen automatisch in te vullen.</p>

    <form action="import-preview.php" method="POST">
        <input type="hidden" name="uploaded_file" value="<?= e($filename) ?>">

        <div class="form-group">
            <label for="template_id">Mappingtemplate</label>
            <select name="template_id" id="template_id">
                <option value="">Automatische herkenning gebruiken</option>

                <?php foreach ($mappingTemplates as $template): ?>
                    <option
                        value="<?= e($template['id']) ?>"
                        <?= $selectedTemplateId === (string) $template['id'] ? 'selected' : '' ?>
                    >
                        <?= e($template['mapping_name']) ?> — laatst aangepast <?= e($template['updated_at']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit">Template toepassen</button>
    </form>
</section>
    <form action="import-review.php" method="POST">
        <input type="hidden" name="uploaded_file" value="<?= e($filename) ?>">
        <section class="card">
    <h2>Mappingtemplate</h2>
    <p>Geef deze kolomkoppeling een naam als je ze later wil herkennen. Voorbeeld: Cyclis, KBC, O2O of Lease a Bike.</p>

    <div class="form-group">
        <label for="mapping_name">Template naam</label>
        <input
    type="text"
    name="mapping_name"
    id="mapping_name"
    placeholder="Bijvoorbeeld: Cyclis"
    value="<?=
        isset($selectedTemplate['mapping_name'])
            ? e($selectedTemplate['mapping_name'])
            : ''
    ?>"
>
    </div>
</section>

        <section class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Databaseveld</th>
                        <th>Excel-kolom</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requiredFields as $fieldKey => $fieldLabel): ?>
                        <tr>
                            <td><?= e($fieldLabel) ?></td>
                            <td>
                                <select name="mapping[<?= e($fieldKey) ?>]">
                                    <option value="">Niet importeren</option>

                                    <?php foreach ($headers as $index => $header): ?>
                                        <option
    value="<?= e($index) ?>"
    <?= $guessedMapping[$fieldKey] === (int) $index ? 'selected' : '' ?>
>
    <?= e($header ?: 'Kolom ' . ($index + 1)) ?>
</option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <br>

        <button type="submit">Preview import bekijken</button>
    </form>
</main>

</body>
</html>