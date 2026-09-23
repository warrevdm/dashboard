<?php

require_once __DIR__ . '/../app/bootstrap.php';

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Excel importeren | Lease Import Manager</title>
    <link rel="stylesheet" href="assets.css">
</head>
<body>

<header class="page-header">
    <h1>Excel importeren</h1>

    <?php require __DIR__ . '/partials/navigation.php'; ?>
</header>

<main>
    <section class="card">
        <h2>Upload leasingbestand</h2>
        <p>Upload een Excel- of CSV-bestand. In de volgende stap koppel je de Excel-kolommen aan de juiste databasevelden.</p>

        <form action="import-preview.php" method="POST" enctype="multipart/form-data">
            <?= Auth::csrfField() ?>
            <div class="form-group">
                <label for="excel_file">Bestand</label>
                <input type="file" name="excel_file" id="excel_file" accept=".xlsx,.xls,.csv" required>
            </div>

            <button type="submit" class="button">Uploaden en kolommen bekijken</button>
        </form>
    </section>
</main>

</body>
</html>
