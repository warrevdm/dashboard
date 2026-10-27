<?php

require_once __DIR__ . '/../app/bootstrap.php';
Auth::requirePost();
require_once __DIR__ . '/../app/Database.php';
require_once __DIR__ . '/../app/ContractUpdate.php';

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$id) { http_response_code(400); exit('Ongeldig contract.'); }
$pdo = Database::connect();
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT * FROM lease_orders WHERE id=:id FOR UPDATE');
    $stmt->execute([':id'=>$id]);
    $order = $stmt->fetch();
    if (!$order) { $pdo->rollBack(); http_response_code(404); exit('Contract niet gevonden.'); }
    $version = $_POST['version'] ?? '';
    if (!is_string($version) || !hash_equals(ContractUpdate::version($order), $version)) throw new InvalidArgumentException('Dit contract is intussen gewijzigd. Je invoer is behouden; controleer deze tegenover de nieuwste gegevens voordat je opnieuw opslaat.');
    $values = ContractUpdate::values($_POST, $order);
    $assignments = implode(', ', array_map(static fn(string $field): string => $field . '=:' . $field, array_keys($values)));
    $stmt = $pdo->prepare('UPDATE lease_orders SET ' . $assignments . ' WHERE id=:id');
    $stmt->execute($values + ['id'=>$id]);
    $pdo->commit();
    unset($_SESSION['lease_edit_error'][$id]);
    header('Location: contract-detail.php?id=' . $id . '&updated=1', true, 303);
    exit;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $message = $error instanceof InvalidArgumentException ? $error->getMessage() : 'Opslaan mislukt. Probeer opnieuw.';
    if ($error instanceof PDOException && $error->getCode() === '23000') $message = 'Deze SO-number bestaat al bij een ander contract.';
    if (!$error instanceof InvalidArgumentException) error_log('Lease contract update: ' . $error->getMessage());
    $submitted = array_filter($_POST, static fn($value): bool => is_scalar($value));
    $_SESSION['lease_edit_error'][$id] = ['message'=>$message, 'values'=>$submitted];
    header('Location: edit-contract.php?id=' . $id, true, 303);
    exit;
}
