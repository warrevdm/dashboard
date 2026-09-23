<?php

require_once __DIR__ . '/../app/Auth.php';

Auth::requireLogin();

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$projectRoot = realpath(__DIR__ . '/..');

$connectors = [
    'o2o' => [
        'label' => 'O2O',
        'workdir' => $projectRoot . '\\browser-connectors\\o2o',
        'command' => 'node scrape-contracts.js',
        'log_file' => $projectRoot . '\\storage\\logs\\o2o-update.log',
        'csv_file' => $projectRoot . '\\storage\\incoming\\o2o-contracts.csv',
        'import_url' => 'import-source-o2o.php',
    ],
    'cyclobility' => [
        'label' => 'Cyclobility',
        'workdir' => $projectRoot . '\\browser-connectors\\cyclobility',
        'command' => 'npm run scrape',
        'log_file' => $projectRoot . '\\storage\\logs\\cyclobility-update.log',
        'csv_file' => $projectRoot . '\\storage\\incoming\\cyclobility-orders.csv',
        'import_url' => 'import-source-cyclobility.php',
    ],
];

$storageLogs = $projectRoot . '\\storage\\logs';

if (!is_dir($storageLogs)) {
    mkdir($storageLogs, 0777, true);
}

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $connectorKey = (string) ($_POST['connector'] ?? '');

    if (!isset($connectors[$connectorKey])) {
        $error = 'Onbekende updatebron.';
    } else {
        $connector = $connectors[$connectorKey];

        if (!is_dir($connector['workdir'])) {
            $error = 'Connector-map bestaat niet: ' . $connector['workdir'];
        } else {
            $timestamp = date('Y-m-d H:i:s');

            file_put_contents(
                $connector['log_file'],
                "=== Update gestart: {$timestamp} ===\n",
                FILE_APPEND
            );

            $command = 'start /B cmd /C "cd /d "' .
                $connector['workdir'] .
                '" && ' .
                $connector['command'] .
                ' >> "' .
                $connector['log_file'] .
                '" 2>&1"';

            pclose(popen($command, 'r'));

            $message = $connector['label'] . ' update is gestart. Wacht even en vernieuw deze pagina om de log/status te bekijken.';
        }
    }
}

function fileInfo(?string $path): array
{
    if (!$path || !file_exists($path)) {
        return [
            'exists' => false,
            'updated_at' => null,
            'size' => null,
        ];
    }

    return [
        'exists' => true,
        'updated_at' => date('d/m/Y H:i:s', filemtime($path)),
        'size' => filesize($path),
    ];
}

function readLogTail(string $path, int $maxLines = 40): string
{
    if (!file_exists($path)) {
        return 'Nog geen logbestand beschikbaar.';
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);

    if (!$lines) {
        return 'Logbestand is leeg.';
    }

    return implode("\n", array_slice($lines, -$maxLines));
}

?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Updates aanvragen | Lease Import Manager</title>
    <link rel="stylesheet" href="assets.css">
</head>
<body>

<header class="page-header">
    <h1>Updates aanvragen</h1>

    <?php require __DIR__ . '/partials/navigation.php'; ?>
</header>

<main>
    <?php if ($message): ?>
        <section class="card">
            <p class="alert-success"><?= e($message) ?></p>
        </section>
    <?php endif; ?>

    <?php if ($error): ?>
        <section class="card">
            <p class="alert-warning"><?= e($error) ?></p>
        </section>
    <?php endif; ?>

    <section class="card">
        <h2>Nieuwe data ophalen</h2>
        <p>Start hier een update. De scraper draait op de achtergrond en schrijft een CSV naar <code>storage/incoming</code>.</p>
    </section>

    <section class="dashboard-grid">
        <?php foreach ($connectors as $key => $connector): ?>
            <?php
                $csvInfo = fileInfo($connector['csv_file']);
                $logInfo = fileInfo($connector['log_file']);
            ?>

            <article class="dashboard-card">
                <span class="dashboard-label"><?= e($connector['label']) ?></span>
                <strong>Update</strong>

                <p>
                    CSV:
                    <?php if ($csvInfo['exists']): ?>
                        laatst bijgewerkt op <?= e($csvInfo['updated_at']) ?>
                    <?php else: ?>
                        nog niet beschikbaar
                    <?php endif; ?>
                </p>

                <p>
                    Log:
                    <?php if ($logInfo['exists']): ?>
                        laatst bijgewerkt op <?= e($logInfo['updated_at']) ?>
                    <?php else: ?>
                        nog niet beschikbaar
                    <?php endif; ?>
                </p>

                <form method="POST" onsubmit="return confirm('Update voor <?= e($connector['label']) ?> starten?');">
                    <input type="hidden" name="connector" value="<?= e($key) ?>">
                    <button type="submit">Update <?= e($connector['label']) ?> starten</button>
                </form>

                <div class="quick-actions">
                    <a class="button-secondary" href="<?= e($connector['import_url']) ?>">
                        Naar importpreview
                    </a>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <?php foreach ($connectors as $key => $connector): ?>
        <section class="card">
            <h2>Log <?= e($connector['label']) ?></h2>
            <pre class="log-output"><?= e(readLogTail($connector['log_file'])) ?></pre>
        </section>
    <?php endforeach; ?>
</main>

</body>
</html>
