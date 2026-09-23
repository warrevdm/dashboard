<?php

require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$incomingFile = __DIR__ . '/../storage/incoming/o2o-contracts.csv';
$uploadDir = __DIR__ . '/../storage/uploads/';

if (!file_exists($incomingFile)) {
    die('Geen o2o-scrape gevonden. Run eerst de o2o scraper zodat storage/incoming/o2o-contracts.csv bestaat.');
}

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$filename = time() . '_o2o-contracts.csv';
$targetPath = $uploadDir . $filename;

if (!copy($incomingFile, $targetPath)) {
    die('Kon o2o-bestand niet kopiëren naar uploads.');
}

$templateStmt = $pdo->prepare("
    SELECT *
    FROM import_mappings
    WHERE LOWER(mapping_name) = LOWER(:mapping_name)
    LIMIT 1
");

$templateStmt->execute([
    ':mapping_name' => 'o2o',
]);

$template = $templateStmt->fetch();

if (!$template) {
    die('Geen mappingtemplate “o2o” gevonden. Maak eerst een mappingtemplate met naam o2o.');
}

$mapping = json_decode($template['mapping_json'], true);

if (!is_array($mapping)) {
    die('Mappingtemplate “o2o” bevat geen geldige mapping.');
}

?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>o2o import voorbereiden | Lease Import Manager</title>
    <link rel="stylesheet" href="assets.css">
</head>
<body>

<header class="page-header">
    <h1>o2o import voorbereiden</h1>

    <?php require __DIR__ . '/partials/navigation.php'; ?>
</header>

<main>
    <section class="card">
        <h2>Laatste o2o-scrape gevonden</h2>

        <p><strong>Bronbestand:</strong> storage/incoming/o2o-contracts.csv</p>
        <p><strong>Uploadbestand:</strong> <?= e($filename) ?></p>
        <p><strong>Mappingtemplate:</strong> <?= e($template['mapping_name']) ?></p>

        <p>Controleer de import eerst in de preview. Daarna kan je definitief importeren.</p>
    </section>

    <section class="card">
        <form action="import-review.php" method="POST">
            <input type="hidden" name="uploaded_file" value="<?= e($filename) ?>">
            <input type="hidden" name="mapping_name" value="<?= e($template['mapping_name']) ?>">

            <?php foreach ($mapping as $field => $columnIndex): ?>
                <input
                    type="hidden"
                    name="mapping[<?= e($field) ?>]"
                    value="<?= e($columnIndex) ?>"
                >
            <?php endforeach; ?>

            <button type="submit">Preview o2o-import bekijken</button>
            <a href="index.php" class="button-secondary">Annuleren</a>
        </form>
    </section>
</main>

</body>
</html>