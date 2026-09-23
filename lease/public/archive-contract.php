<?php

require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    die('Ongeldig contract.');
}

$stmt = $pdo->prepare("
    UPDATE lease_orders
    SET archived = 1,
        archived_at = NOW()
    WHERE id = :id
    LIMIT 1
");

$stmt->execute([
    ':id' => $id,
]);

header('Location: index.php?archived=1');
exit;