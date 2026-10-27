<?php

require_once __DIR__ . '/../app/bootstrap.php';
Auth::requirePost();
require_once __DIR__ . '/../app/ImportFile.php';
require_once __DIR__ . '/../app/Database.php';

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$uploadDir = __DIR__ . '/../storage/uploads/';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0700, true);
}

$allowedExtensions = ['xlsx', 'xls', 'csv'];

if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
    if ((int) $_FILES['excel_file']['size'] > 10 * 1024 * 1024) {
        http_response_code(413);
        exit('Dit bestand is te groot. Upload maximaal 10 MB.');
    }
    $originalName = basename($_FILES['excel_file']['name']);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions, true)) {
        die('Ongeldig bestandstype. Upload een .xlsx, .xls of .csv bestand.');
    }

    $filename = bin2hex(random_bytes(16)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
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
    $rows = ImportFile::rows($targetPath);
} catch (Throwable $e) {
    die('Excelbestand kon niet gelezen worden: ' . e($e->getMessage()));
}

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

require_once __DIR__ . '/../app/ImportColumns.php';

$guessedMapping = [];

foreach ($requiredFields as $fieldKey => $fieldLabel) {
    $guessedMapping[$fieldKey] = ImportColumns::guess($fieldKey, $headers);
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
        <p>Bij bestaande SO-nummers blijven gegevens behouden wanneer de kolom ontbreekt of leeg is. Een onderhoudsbudget van 0 wordt wel bijgewerkt.</p>
        <p><strong>Tip:</strong> het systeem vult de meest waarschijnlijke kolommen automatisch in. Controleer de selectie altijd vóór je importeert.</p>
    </section>

    <section class="card">
    <h2>Bestaande template gebruiken</h2>
    <p>Kies een opgeslagen mappingtemplate om de kolommen automatisch in te vullen.</p>

    <form action="import-preview.php" method="POST">
        <?= Auth::csrfField() ?>
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
        <?= Auth::csrfField() ?>
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
