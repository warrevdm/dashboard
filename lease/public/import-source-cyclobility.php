<?php

require_once __DIR__ . '/../app/bootstrap.php';
Auth::requirePost();
require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$incomingFile = __DIR__ . '/../storage/incoming/cyclobility-orders.csv';
$uploadDir = __DIR__ . '/../storage/uploads/';

if (!file_exists($incomingFile)) {
    die('Geen Cyclobility-scrape gevonden. Run eerst de Cyclobility scraper zodat storage/incoming/cyclobility-orders.csv bestaat.');
}

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0700, true);
}

$filename = bin2hex(random_bytes(16)) . '_cyclobility-orders.csv';
$targetPath = $uploadDir . $filename;

if (!copy($incomingFile, $targetPath)) {
    die('Kon Cyclobility-bestand niet kopiëren naar uploads.');
}

$templateStmt = $pdo->prepare("
    SELECT *
    FROM import_mappings
    WHERE LOWER(mapping_name) = LOWER(:mapping_name)
    LIMIT 1
");

$templateStmt->execute([
    ':mapping_name' => 'cyclobility',
]);

$template = $templateStmt->fetch();

if (!$template) {
    die('Geen mappingtemplate “cyclobility” gevonden. Maak eerst een mappingtemplate met naam cyclobility.');
}

$mapping = json_decode($template['mapping_json'], true);

if (!is_array($mapping)) {
    die('Mappingtemplate “cyclobility” bevat geen geldige mapping.');
}

?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Cyclobility import voorbereiden | Lease Import Manager</title>
    <link rel="stylesheet" href="assets.css">
</head>
<body>

<header class="page-header">
    <h1>Cyclobility import voorbereiden</h1>

    <?php require __DIR__ . '/partials/navigation.php'; ?>
</header>

<main>
    <section class="card">
        <h2>Laatste Cyclobility-scrape gevonden</h2>

        <p><strong>Bronbestand:</strong> storage/incoming/cyclobility-orders.csv</p>
        <p><strong>Uploadbestand:</strong> <?= e($filename) ?></p>
        <p><strong>Mappingtemplate:</strong> <?= e($template['mapping_name']) ?></p>

        <p>Controleer de import eerst in de preview. Daarna kan je definitief importeren.</p>
    </section>

    <section class="card">
        <form action="import-review.php" method="POST">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="uploaded_file" value="<?= e($filename) ?>">
            <input type="hidden" name="mapping_name" value="<?= e($template['mapping_name']) ?>">

            <?php foreach ($mapping as $field => $columnIndex): ?>
                <input
                    type="hidden"
                    name="mapping[<?= e($field) ?>]"
                    value="<?= e($columnIndex) ?>"
                >
            <?php endforeach; ?>

            <button type="submit">Preview Cyclobility-import bekijken</button>
            <a href="index.php" class="button-secondary">Annuleren</a>
        </form>
    </section>
</main>

</body>
</html>