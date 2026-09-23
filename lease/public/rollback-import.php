<?php

require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: import-history.php');
    exit;
}

$batchId = (int) ($_POST['batch_id'] ?? 0);

if ($batchId <= 0) {
    die('Ongeldige import.');
}

$pdo->beginTransaction();

try {
    $recordsStmt = $pdo->prepare("
        SELECT *
        FROM import_created_records
        WHERE import_batch_id = :batch_id
    ");

    $recordsStmt->execute([
        ':batch_id' => $batchId,
    ]);

    $records = $recordsStmt->fetchAll();

    // Eerst de rollback-logregels verwijderen,
    // anders blokkeert de foreign key het verwijderen van lease_orders.
    $deleteCreatedRecordsStmt = $pdo->prepare("
        DELETE FROM import_created_records
        WHERE import_batch_id = :batch_id
    ");

    $deleteCreatedRecordsStmt->execute([
        ':batch_id' => $batchId,
    ]);

    foreach ($records as $record) {
        $deleteOrderStmt = $pdo->prepare("
            DELETE FROM lease_orders
            WHERE id = :id
              AND so_number = :so_number
            LIMIT 1
        ");

        $deleteOrderStmt->execute([
            ':id' => $record['lease_order_id'],
            ':so_number' => $record['so_number'],
        ]);
    }

    $pdo->commit();

    header('Location: import-batch-detail.php?id=' . $batchId . '&rolled_back=1&deleted=' . count($records));
    exit;
} catch (Throwable $e) {
    $pdo->rollBack();

    die('Rollback mislukt: ' . $e->getMessage());
}