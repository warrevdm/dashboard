<?php

require_once __DIR__ . '/../app/bootstrap.php';
Auth::requirePost();
require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    die('Ongeldig contract.');
}

$stmt = $pdo->prepare("
    UPDATE lease_orders
    SET archived = 0,
        archived_at = NULL
    WHERE id = :id
    LIMIT 1
");

$stmt->execute([
    ':id' => $id,
]);

header('Location: contract-detail.php?id=' . $id . '&restored=1');
exit;