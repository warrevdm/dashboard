<?php

require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

$stmt = $pdo->query("
    SELECT *
    FROM import_batches
    ORDER BY created_at DESC
");

$batches = $stmt->fetchAll();

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Importgeschiedenis | Lease Import Manager</title>
    <link rel="stylesheet" href="assets.css">
</head>
<body>

<header class="page-header">
    <h1>Importgeschiedenis</h1>

    <?php require __DIR__ . '/partials/navigation.php'; ?>
</header>

<main>
    <section class="card">
        <h2>Controle per import</h2>
        <p>Gebruik deze pagina om elke wekelijkse import te controleren: hoeveel rijen zijn verwerkt, hoeveel records zijn nieuw, hoeveel zijn geüpdatet en hoeveel zijn overgeslagen.</p>
    </section>

    <section class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Bestandsnaam</th>
                    <th>Totaal rijen</th>
                    <th>Nieuwe records</th>
                    <th>Geüpdatet actief</th>
                    <th>Geüpdatet archief</th>
                    <th>Overgeslagen</th>
                    <th>Detail</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($batches)): ?>
                    <tr>
                        <td colspan="8">Nog geen imports uitgevoerd.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($batches as $batch): ?>
                    <tr>
                        <td><?= e($batch['created_at']) ?></td>
                        <td>
    <a href="import-batch-detail.php?id=<?= e($batch['id']) ?>">
        <?= e($batch['filename']) ?>
    </a>
</td>
                        <td><?= e($batch['total_rows']) ?></td>
                        <td><?= e($batch['imported_rows']) ?></td>
                        <td><?= e($batch['updated_rows']) ?></td>
                        <td><?= e($batch['archived_updates']) ?></td>   
                        <td><?= e($batch['skipped_rows']) ?></td>
<td>
    <a href="import-batch-detail.php?id=<?= e($batch['id']) ?>" class="button-secondary">
        Bekijk detail
    </a>
</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>

</body>
</html>