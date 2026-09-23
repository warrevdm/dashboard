<?php

require_once __DIR__ . '/../app/bootstrap.php';
Auth::requirePost();
require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

$templateId = (int) ($_POST['template_id'] ?? 0);

if ($templateId <= 0) {
    header('Location: mapping-templates.php');
    exit;
}

$stmt = $pdo->prepare("
    DELETE FROM import_mappings
    WHERE id = :id
    LIMIT 1
");

$stmt->execute([
    ':id' => $templateId,
]);

header('Location: mapping-templates.php?deleted=1');
exit;