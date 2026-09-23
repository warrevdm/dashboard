<?php

require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

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

$batchId = (int) ($_GET['id'] ?? 0);

if ($batchId <= 0) {
    die('Ongeldige import.');
}

$batchStmt = $pdo->prepare("
    SELECT *
    FROM import_batches
    WHERE id = :id
    LIMIT 1
");

$batchStmt->execute([
    ':id' => $batchId,
]);

$batch = $batchStmt->fetch();

if (!$batch) {
    die('Importbatch niet gevonden.');
}

$errorsStmt = $pdo->prepare("
    SELECT *
    FROM import_errors
    WHERE import_batch_id = :id
    ORDER BY row_number ASC, created_at ASC
");

$errorsStmt->execute([
    ':id' => $batchId,
]);

$errors = $errorsStmt->fetchAll();

$errorCount = count($errors);

$createdRecordsStmt = $pdo->prepare("
    SELECT *
    FROM import_created_records
    WHERE import_batch_id = :id
    ORDER BY created_at ASC
");

$createdRecordsStmt->execute([
    ':id' => $batchId,
]);

$createdRecords = $createdRecordsStmt->fetchAll();
$createdRecordCount = count($createdRecords);

?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Importdetail | Lease Import Manager</title>
    <link rel="stylesheet" href="assets.css">
</head>
<body>

<header class="page-header">
    <h1>Importdetail</h1>

    <?php require __DIR__ . '/partials/navigation.php'; ?>
</header>

<main>
    <section class="card">
    <h2><?= e($batch['filename']) ?></h2>
    <p><strong>Importdatum:</strong> <?= e($batch['created_at']) ?></p>

    <?php if (isset($_GET['rolled_back'])): ?>
        <p class="alert-success">
            Rollback uitgevoerd. <?= e($_GET['deleted'] ?? 0) ?> nieuw aangemaakte record(s) verwijderd.
        </p>
    <?php endif; ?>
</section>

    <section class="dashboard-grid">
        <article class="dashboard-card">
            <span class="dashboard-label">Totaal rijen</span>
            <strong><?= e($batch['total_rows']) ?></strong>
            <small>Rijen uit Excel zonder header</small>
        </article>

        <article class="dashboard-card">
            <span class="dashboard-label">Nieuwe records</span>
            <strong><?= e($batch['imported_rows']) ?></strong>
            <small>Aangemaakt in database</small>
        </article>

        <article class="dashboard-card">
    <span class="dashboard-label">Rollbackbaar</span>
    <strong><?= e($createdRecordCount) ?></strong>
    <small>Nieuw aangemaakte records die nog verwijderd kunnen worden</small>
</article>

        <article class="dashboard-card">
            <span class="dashboard-label">Geüpdatet actief</span>
            <strong><?= e($batch['updated_rows']) ?></strong>
            <small>Bestaande actieve SO-numbers</small>
        </article>

        <article class="dashboard-card">
            <span class="dashboard-label">Geüpdatet archief</span>
            <strong><?= e($batch['archived_updates'] ?? 0) ?></strong>
            <small>Gearchiveerd gebleven</small>
        </article>

        <article class="dashboard-card">
            <span class="dashboard-label">Overgeslagen</span>
            <strong><?= e($batch['skipped_rows']) ?></strong>
            <small>Niet verwerkt</small>
        </article>

        <article class="dashboard-card">
            <span class="dashboard-label">Importfouten</span>
            <strong><?= e($errorCount) ?></strong>
            <small>Fouten gekoppeld aan deze import</small>
        </article>
    </section>

    <section class="card">
        <h2>Controle</h2>

        <?php if ($errorCount === 0): ?>
            <p class="alert-success">Geen fouten gevonden voor deze import.</p>
        <?php else: ?>
            <p class="alert-warning">Deze import bevat <?= e($errorCount) ?> fout(en). Controleer de tabel hieronder.</p>
        <?php endif; ?>
    </section>

    <section class="card">
    <h2>Import terugdraaien</h2>

    <?php if ($createdRecordCount > 0): ?>
        <p>
            Deze import heeft <?= e($createdRecordCount) ?> nieuwe record(s) aangemaakt.
            Je kan alleen deze nieuwe records verwijderen. Updates aan bestaande contracten worden niet teruggedraaid.
        </p>

        <form
            action="rollback-import.php"
            method="POST"
            onsubmit="return confirm('Ben je zeker dat je deze import wil terugdraaien? Alleen nieuw aangemaakte records worden verwijderd. Updates worden niet teruggedraaid.');"
        >
            <?= Auth::csrfField() ?>
            <input type="hidden" name="batch_id" value="<?= e($batch['id']) ?>">
            <button type="submit" class="button-danger">Nieuwe records uit deze import verwijderen</button>
        </form>
    <?php else: ?>
        <p class="alert-warning">
            Er zijn geen nieuw aangemaakte records meer gekoppeld aan deze import.
            Deze import kan niet verder automatisch teruggedraaid worden.
        </p>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Nieuw aangemaakte records in deze import</h2>
    <p>Deze records worden verwijderd als je de rollback uitvoert.</p>
</section>

<section class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>SO-number</th>
                <th>Lease order ID</th>
                <th>Aangemaakt op</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($createdRecords)): ?>
                <tr>
                    <td colspan="3">Geen rollbackbare records voor deze import.</td>
                </tr>
            <?php endif; ?>

            <?php foreach ($createdRecords as $record): ?>
                <tr>
                    <td><?= e($record['so_number']) ?></td>
                    <td><?= e($record['lease_order_id']) ?></td>
                    <td><?= e($record['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

    <section class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Excel-rij</th>
                    <th>SO-number</th>
                    <th>Foutmelding</th>
                    <th>Ruwe data</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($errors)): ?>
                    <tr>
                        <td colspan="4">Geen importfouten voor deze import.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($errors as $error): ?>
                    <tr>
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

    <section class="card">
        <a href="import-history.php" class="button-secondary">Terug naar importgeschiedenis</a>
    </section>
</main>

</body>
</html>