<?php

require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

$stmt = $pdo->query("
    SELECT
        import_errors.*,
        import_batches.filename,
        import_batches.created_at AS import_created_at
    FROM import_errors
    LEFT JOIN import_batches
        ON import_errors.import_batch_id = import_batches.id
    ORDER BY import_errors.created_at DESC
");

$errors = $stmt->fetchAll();

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatRawData($rawData): string
{
    if (!$rawData) {
        return '';
    }

    $decoded = json_decode($rawData, true);

    if (!is_array($decoded)) {
        return (string) $rawData;
    }

    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Importfouten | Lease Import Manager</title>
    <link rel="stylesheet" href="assets.css">
</head>
<body>

<header class="page-header">
    <h1>Importfouten</h1>

    <?php require __DIR__ . '/partials/navigation.php'; ?>
</header>

<main>
    <section class="card">
        <h2>Rijen die niet verwerkt zijn</h2>
        <p>Hier zie je welke rijen tijdens de import zijn overgeslagen en waarom. Meestal gaat het om ontbrekende SO-numbers of onleesbare data.</p>
    </section>

    <section class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Importdatum</th>
                    <th>Bestand</th>
                    <th>Excel-rij</th>
                    <th>SO-number</th>
                    <th>Foutmelding</th>
                    <th>Ruwe data</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($errors)): ?>
                    <tr>
                        <td colspan="6">Geen importfouten gevonden.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($errors as $error): ?>
                    <tr>
                        <td><?= e($error['import_created_at']) ?></td>
                        <td><?= e($error['filename']) ?></td>
                        <td><?= e($error['row_number']) ?></td>
                        <td><?= e($error['so_number']) ?></td>
                        <td><?= e($error['error_message']) ?></td>
                        <td>
                            <details>
                                <summary>Bekijk data</summary>
                                <pre><?= e(formatRawData($error['raw_data'])) ?></pre>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>

</body>
</html>