<?php

require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

$stmt = $pdo->query("
    SELECT *
    FROM import_mappings
    ORDER BY updated_at DESC, created_at DESC
");

$templates = $stmt->fetchAll();

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatJson($json): string
{
    if (!$json) {
        return '';
    }

    $decoded = json_decode($json, true);

    if (!is_array($decoded)) {
        return (string) $json;
    }

    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Mappingtemplates | Lease Import Manager</title>
    <link rel="stylesheet" href="assets.css">
</head>
<body>

<header class="page-header">
    <h1>Mappingtemplates</h1>

    <?php require __DIR__ . '/partials/navigation.php'; ?>
</header>

<main>
    <section class="card">
    <h2>Opgeslagen kolomkoppelingen</h2>
    <p>Gebruik deze pagina om opgeslagen mappings te controleren, hergebruiken of verwijderen.</p>

    <?php if (isset($_GET['deleted'])): ?>
        <p class="alert-success">Mappingtemplate verwijderd.</p>
    <?php endif; ?>
</section>

    <section class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Template naam</th>
                    <th>Aangemaakt</th>
                    <th>Laatst aangepast</th>
                    <th>Mapping</th>
                    <th>Actie</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($templates)): ?>
                    <tr>
                        <td colspan="5">Nog geen mappingtemplates opgeslagen.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($templates as $template): ?>
                    <tr>
                        <td><?= e($template['mapping_name']) ?></td>
                        <td><?= e($template['created_at']) ?></td>
                        <td><?= e($template['updated_at']) ?></td>
                        <td>
                            <details>
                                <summary>Bekijk mapping</summary>
                                <pre><?= e(formatJson($template['mapping_json'])) ?></pre>
                            </details>
                        </td>
                        <td>
    <form
        action="delete-mapping-template.php"
        method="POST"
        onsubmit="return confirm('Ben je zeker dat je deze mappingtemplate wil verwijderen? Dit verwijdert geen leasingcontracten.');"
    >
        <input type="hidden" name="template_id" value="<?= e($template['id']) ?>">
        <button type="submit" class="button-danger">Verwijderen</button>
    </form>
</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>

</body>
</html>