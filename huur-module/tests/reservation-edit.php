<?php

declare(strict_types=1);

// This script runs inside the isolated virtual filesystem prepared by reservation-edit.cjs.
if (__DIR__ !== '/rental-fixture') {
    http_response_code(403);
    exit;
}

define('ROOT_PATH', __DIR__);
date_default_timezone_set('Europe/Brussels');
putenv('DB_PATH=/rental-fixture/synthetic.sqlite');
foreach (['env', 'database', 'security', 'repositories', 'contracts_v2', 'reservation_edit'] as $file) {
    require __DIR__ . '/app/' . $file . '.php';
}
$schema = file_get_contents(__DIR__ . '/schema.sql');
$pdo = db();
$pdo->exec($schema);
$checks = 0;

function check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $checks++;
}

function fails(callable $operation, string $message, string $label): void
{
    try {
        $operation();
    } catch (Throwable $e) {
        check(str_contains($e->getMessage(), $message), $label . ': ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('FAIL: ' . $label . ' was accepted');
}

function rows(PDO $pdo, string $table): array
{
    return $pdo->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
}

function snapshot(PDO $pdo): array
{
    $result = [];
    foreach (['users', 'customers', 'bikes', 'reservations', 'reservation_bikes', 'rental_contracts', 'payment_logs', 'identity_documents', 'audit_logs'] as $table) {
        $result[$table] = rows($pdo, $table);
    }
    return $result;
}

function seed(PDO $pdo): void
{
    $pdo->exec("INSERT INTO users (id,name,email,password_hash,role) VALUES (1,'Fixture staff','staff@example.test','synthetic','staff'), (2,'Fixture finance','finance@example.test','synthetic','finance')");
    $pdo->exec("INSERT INTO bikes (id,code,name,category,usage_type,daily_rate,status) VALUES (1,'BIKE-1','A fixture bike','test','rental',25,'active'),(2,'BIKE-2','B fixture bike','test','test',35,'active'),(3,'BIKE-3','C fixture bike','test','replacement',45,'active'),(4,'BIKE-4','D fixture bike','test','rental',55,'maintenance')");
}

function reservation(PDO $pdo, array $bikes = [1, 2], string $kind = 'rental', string $start = '2026-09-25 09:00:00', string $end = '2026-09-26 17:00:00', ?int $customer = null): int
{
    if ($customer === null) {
        $pdo->exec("INSERT INTO customers (name,email,phone,address) VALUES ('Synthetic customer','customer@example.test','0123456789','Fixture street 1')");
        $customer = (int) $pdo->lastInsertId();
    }
    $stmt = $pdo->prepare("INSERT INTO reservations (bike_id,customer_id,start_at,end_at,status,rental_kind,total_price,notes,created_by,eid_physical_checked,eid_photo_match,eid_checked_at,eid_checked_by) VALUES (?,?,?,?,'confirmed',?,120.50,'Initial note',1,1,1,'2026-09-24 10:00:00',1)");
    $stmt->execute([$bikes[0], $customer, $start, $end, $kind]);
    $id = (int) $pdo->lastInsertId();
    foreach ($bikes as $bike) {
        $stmt = $pdo->prepare('INSERT INTO reservation_bikes (reservation_id,bike_id,daily_rate) VALUES (?,?,?)');
        $stmt->execute([$id, $bike, 10.5 + $bike]);
    }
    $stmt = $pdo->prepare("INSERT INTO payment_logs (reservation_id,amount,method,note,recorded_by) VALUES (?,40,'bancontact','Synthetic payment',1)");
    $stmt->execute([$id]);
    return $id;
}

function contract(PDO $pdo, int $id, bool $signed = false): int
{
    $stmt = $pdo->prepare('INSERT INTO rental_contracts (reservation_id,contract_number,public_token_hash,public_token_expires_at,contract_html,contract_hash,signed_at,signed_contract_html,signed_hash,signature_stored_name,pdf_stored_name,email_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$id, 'FIXTURE-' . $id, hash('sha256', str_repeat('a', 64)), '2099-01-01 00:00:00', '<p>Original contract</p>', hash('sha256', '<p>Original contract</p>'), $signed ? '2026-09-24 11:00:00' : null, $signed ? '<p>Signed immutable snapshot</p>' : null, $signed ? 'signed-hash-fixture' : null, $signed ? 'synthetic-signature.png' : null, $signed ? 'synthetic-contract.pdf' : null, $signed ? 'sent' : 'pending']);
    $contractId = (int) $pdo->lastInsertId();
    $_SESSION['contract_tokens'][$contractId] = str_repeat('a', 64);
    return $contractId;
}

function fixture(): int
{
    $pdo = db();
    foreach (['rental_contracts', 'payment_logs', 'reservation_bikes', 'reservations', 'identity_documents', 'customers', 'bikes', 'audit_logs', 'users', 'sqlite_sequence'] as $table) {
        $pdo->exec('DELETE FROM ' . $table);
    }
    seed($pdo);
    $_SESSION = ['user' => ['id' => 1, 'name' => 'Fixture staff', 'email' => 'staff@example.test', 'role' => 'staff']];
    return reservation($pdo);
}

function input(int $id, array $changes = []): array
{
    $reservation = find_reservation($id);
    return array_replace([
        'customer_name' => $reservation['customer_name'],
        'customer_email' => $reservation['customer_email'],
        'customer_phone' => $reservation['customer_phone'],
        'customer_address' => $reservation['customer_address'],
        'start_date' => substr($reservation['start_at'], 0, 10),
        'start_time' => substr($reservation['start_at'], 11, 5),
        'end_date' => substr($reservation['end_at'], 0, 10),
        'end_time' => substr($reservation['end_at'], 11, 5),
        'rental_kind' => $reservation['rental_kind'],
        'status' => $reservation['status'],
        'notes' => $reservation['notes'],
        'version' => reservation_edit_version($reservation, find_contract_by_reservation($id)),
    ], $changes);
}

// Complete dossier edit, while preserving the agreed price, payment history and every bike.
$id = fixture();
$money = rows($pdo, 'payment_logs');
$bikes = rows($pdo, 'reservation_bikes');
update_reservation_details($id, input($id, [
    'customer_name' => 'Updated synthetic customer', 'customer_email' => 'updated@example.test',
    'customer_phone' => '0987654321', 'customer_address' => 'Changed street 2',
    'start_date' => '2026-09-27', 'start_time' => '10:15',
    'end_date' => '2026-09-28', 'end_time' => '18:45', 'rental_kind' => 'test',
    'status' => 'picked_up', 'notes' => 'Updated dossier note',
]));
$updated = find_reservation($id);
check($updated['start_at'] === '2026-09-27 10:15:00' && $updated['end_at'] === '2026-09-28 18:45:00', 'Both dates and times saved');
check($updated['rental_kind'] === 'test' && $updated['status'] === 'picked_up' && $updated['notes'] === 'Updated dossier note', 'Type, status and notes saved');
check($updated['customer_name'] === 'Updated synthetic customer' && $updated['customer_email'] === 'updated@example.test' && $updated['customer_phone'] === '0987654321' && $updated['customer_address'] === 'Changed street 2', 'All customer fields saved');
check((float) $updated['total_price'] === 120.5 && rows($pdo, 'payment_logs') === $money, 'Price and existing payments preserved');
check(rows($pdo, 'reservation_bikes') === $bikes, 'Multiple reserved bikes and agreed rates preserved');
check((int) $updated['eid_physical_checked'] === 0 && (int) $updated['eid_photo_match'] === 0 && $updated['eid_checked_at'] === null && $updated['eid_checked_by'] === null, 'Changed identity clears all previous eID verification');
$audit = rows($pdo, 'audit_logs');
$details = json_decode($audit[0]['details_json'], true);
check(count($audit) === 1 && $audit[0]['action'] === 'update_reservation_details' && $details['old_rental_kind'] === 'rental' && $details['new_rental_kind'] === 'test', 'Edit recorded with old and new type');
check(!str_contains($audit[0]['details_json'], 'updated@example.test') && !str_contains($audit[0]['details_json'], 'Changed street'), 'Audit records field names without customer contact contents');
foreach (['replacement', 'rental', 'test'] as $kind) {
    update_reservation_details($id, input($id, ['rental_kind' => $kind]));
    ensure_reservation_kind_schema($pdo);
    check(find_reservation($id)['rental_kind'] === $kind, 'Selected type persists: ' . $kind);
}

// Conflicts must include the second bike; self and exactly adjacent reservations are valid.
$id = fixture();
reservation($pdo, [2], 'rental', '2026-09-27 09:00:00', '2026-09-28 17:00:00');
$before = snapshot($pdo);
fails(fn () => update_reservation_details($id, input($id, ['end_date' => '2026-09-27', 'end_time' => '09:01'])), 'BIKE-2', 'Overlap on second bike rejected');
check(snapshot($pdo) === $before, 'Conflict leaves all dossier data unchanged');
update_reservation_details($id, input($id, ['end_date' => '2026-09-27', 'end_time' => '09:00']));
check(find_reservation($id)['end_at'] === '2026-09-27 09:00:00', 'Adjacent boundary and overlap with self accepted');

// Input validation uses local Brussels time, including the nonexistent spring DST hour.
$invalidInputs = [
    [['customer_name' => '  '], 'klantnaam'], [['customer_email' => 'invalid-address'], 'e-mailadres'],
    [['rental_kind' => 'other'], 'Huur'], [['status' => 'cancelled'], 'status'],
    [['start_date' => '2026-02-30'], 'datums'], [['start_time' => '25:30'], 'datums'],
    [['start_date' => '2026-03-29', 'start_time' => '02:30'], 'datums'],
    [['start_date' => '2026-09-30'], 'datums'],
    [['end_date' => '2026-09-25', 'end_time' => '09:00'], 'datums'],
];
foreach ($invalidInputs as [$changes, $message]) {
    $id = fixture();
    $before = snapshot($pdo);
    fails(fn () => update_reservation_details($id, input($id, $changes)), $message, 'Invalid dossier input rejected: ' . json_encode($changes));
    check(snapshot($pdo) === $before, 'Validation failure leaves database unchanged');
}
$id = fixture();
update_reservation_details($id, input($id, ['customer_email' => '', 'notes' => '']));
check(find_reservation($id)['customer_email'] === null && find_reservation($id)['notes'] === null, 'Optional empty email and notes accepted');
check((int) find_reservation($id)['eid_physical_checked'] === 1, 'Contact-only correction preserves identity verification');

// Unsigned contract links are revoked; completed signatures keep their original snapshot.
$id = fixture();
$contractId = contract($pdo, $id);
check(find_contract_by_token(str_repeat('a', 64)) !== null, 'Fixture unsigned token starts valid');
update_reservation_details($id, input($id, ['start_time' => '09:30']));
check(find_contract_by_reservation($id) === null && find_contract_by_token(str_repeat('a', 64)) === null, 'Period correction revokes unsigned contract and public token');
check(!isset($_SESSION['contract_tokens'][$contractId]), 'Unsigned token removed from session after successful commit');
$id = fixture();
contract($pdo, $id);
$beforeContract = rows($pdo, 'rental_contracts');
update_reservation_details($id, input($id, ['notes' => 'A staff note']));
check(rows($pdo, 'rental_contracts') === $beforeContract, 'Notes-only correction preserves an unsigned contract');
$id = fixture();
contract($pdo, $id, true);
$beforeContract = rows($pdo, 'rental_contracts');
update_reservation_details($id, input($id, ['customer_address' => 'Changed street 3', 'rental_kind' => 'test', 'end_time' => '19:00']));
check(rows($pdo, 'rental_contracts') === $beforeContract, 'Signed contract, signature, hashes, PDF and mail metadata remain byte-for-byte unchanged');

// Stale forms cannot silently overwrite concurrent dossier or signature changes.
foreach (['notes', 'contract', 'bike', 'customer'] as $concurrentChange) {
    $id = fixture();
    $oldInput = input($id, ['notes' => 'Stale update']);
    if ($concurrentChange === 'notes') $pdo->exec("UPDATE reservations SET notes='Concurrent note' WHERE id=$id");
    if ($concurrentChange === 'contract') contract($pdo, $id, true);
    if ($concurrentChange === 'bike') $pdo->exec("DELETE FROM reservation_bikes WHERE reservation_id=$id AND bike_id=2");
    if ($concurrentChange === 'customer') $pdo->exec("UPDATE customers SET phone='Changed concurrently'");
    $before = snapshot($pdo);
    fails(fn () => update_reservation_details($id, $oldInput), 'intussen gewijzigd', 'Stale form rejected after concurrent ' . $concurrentChange);
    check(snapshot($pdo) === $before, 'Concurrent edit preserved');
}
$id = fixture();
foreach (['finance', null] as $role) {
    $values = input($id);
    $_SESSION['user'] = $role ? ['id' => 2, 'role' => $role] : null;
    $before = snapshot($pdo);
    fails(fn () => update_reservation_details($id, $values), 'rechten', 'Unauthorized dossier edit rejected');
    check(snapshot($pdo) === $before, 'Unauthorized request changes nothing');
}
$id = fixture();
$pdo->exec("UPDATE reservations SET status='cancelled' WHERE id=$id");
fails(fn () => update_reservation_details($id, input($id, ['status' => 'confirmed'])), 'geannuleerd', 'Cancelled dossier cannot be edited');
$id = fixture();
$customer = (int) find_reservation($id)['customer_id'];
reservation($pdo, [3], 'rental', '2026-10-01 09:00:00', '2026-10-02 17:00:00', $customer);
fails(fn () => update_reservation_details($id, input($id, ['customer_phone' => 'Changed'])), 'meerdere dossiers', 'Shared customer cannot be changed through one dossier');
update_reservation_details($id, input($id, ['notes' => 'Shared customer, own note']));
check(find_reservation($id)['notes'] === 'Shared customer, own note', 'Other dossier fields remain editable for shared customer');

// Preserve the established one-bike replacement swap, with conflicts and agreed rates checked.
$id = fixture();
$pdo->exec("UPDATE reservations SET rental_kind='replacement' WHERE id=$id; DELETE FROM reservation_bikes WHERE reservation_id=$id AND bike_id=2");
update_reservation_details($id, input($id, ['bike_id' => 3]));
$bikeRows = rows($pdo, 'reservation_bikes');
check((int) find_reservation($id)['bike_id'] === 3 && count($bikeRows) === 1 && (int) $bikeRows[0]['bike_id'] === 3 && (float) $bikeRows[0]['daily_rate'] === 11.5, 'Single replacement bike can change without repricing');
fails(fn () => update_reservation_details($id, input($id, ['bike_id' => 4])), 'actieve fiets', 'Maintenance bike cannot replace current bike');
reservation($pdo, [2]);
fails(fn () => update_reservation_details($id, input($id, ['bike_id' => 2])), 'overlapt', 'Replacement bike swap respects availability');
$id = fixture();
fails(fn () => update_reservation_details($id, input($id, ['bike_id' => 3])), 'fietswissel', 'Ordinary multi-bike dossier cannot lose bicycles via a swap');

// Complete atomicity even if the last audit write fails after customer, period and contract edits.
$id = fixture();
$contractId = contract($pdo, $id);
$before = snapshot($pdo);
$tokenBefore = $_SESSION['contract_tokens'][$contractId];
$pdo->exec("CREATE TRIGGER reject_fixture_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT,'synthetic audit failure'); END");
fails(fn () => update_reservation_details($id, input($id, ['customer_name' => 'Would change', 'end_time' => '18:00', 'rental_kind' => 'test'])), 'synthetic audit failure', 'Late audit failure reported');
check(snapshot($pdo) === $before, 'Audit failure rolls back customer, reservation, eID flags and contract deletion');
check($_SESSION['contract_tokens'][$contractId] === $tokenBefore && !$pdo->inTransaction(), 'Rollback preserves session token and ends transaction');
$pdo->exec('DROP TRIGGER reject_fixture_audit');

// Return stamps are added once, kept while returned, and cleared when reopened.
$id = fixture();
update_reservation_details($id, input($id, ['status' => 'returned']));
$returned = find_reservation($id);
check($returned['closed_at'] !== null && (int) $returned['closed_by'] === 1, 'Returning dossier records the employee and time');
update_reservation_details($id, input($id, ['notes' => 'Return note']));
check(find_reservation($id)['closed_at'] === $returned['closed_at'], 'Later returned-dossier edit preserves original return stamp');
update_reservation_details($id, input($id, ['status' => 'confirmed']));
check(find_reservation($id)['closed_at'] === null && find_reservation($id)['closed_by'] === null, 'Reopening clears return stamps');
$pdo->exec("UPDATE reservations SET status='returned' WHERE id=$id");
reservation($pdo, [2]);
update_reservation_details($id, input($id, ['notes' => 'Historical return correction']));
check(find_reservation($id)['notes'] === 'Historical return correction', 'Returned dossier can be corrected despite a later overlapping booking');
fails(fn () => update_reservation_details($id, input($id, ['status' => 'confirmed'])), 'overlapt', 'Reopening a returned dossier checks all bike conflicts');

// Migrate an old two-kind database without losing any existing data or attached schema objects.
$oldSchema = str_replace("CHECK(rental_kind IN ('rental', 'test', 'replacement'))", "CHECK(rental_kind IN ('rental', 'replacement'))", $schema);
$migration = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$migration->exec($oldSchema);
seed($migration);
$oldId = reservation($migration);
contract($migration, $oldId, true);
$migration->exec("ALTER TABLE reservations ADD COLUMN custom_reference TEXT DEFAULT 'legacy-default'; ALTER TABLE reservations ADD COLUMN custom_computed TEXT GENERATED ALWAYS AS ('ref-' || id) VIRTUAL");
$migration->exec("UPDATE reservations SET custom_reference='keep this value', notes='Snelle fietsregistratie via werkplaats. historical note' WHERE id=$oldId");
$migration->exec("CREATE INDEX idx_fixture_reference ON reservations(custom_reference); CREATE TABLE migration_events (reservation_id INTEGER); CREATE TRIGGER fixture_update AFTER UPDATE ON reservations BEGIN INSERT INTO migration_events VALUES (new.id); END; CREATE VIEW fixture_reservations AS SELECT id,custom_reference FROM reservations; CREATE TRIGGER fixture_payment AFTER INSERT ON payment_logs BEGIN UPDATE reservations SET custom_reference=custom_reference WHERE id=new.reservation_id; END");
$migration->exec("INSERT INTO reservations (id,bike_id,customer_id,start_at,end_at) VALUES (100,1,1,'2026-10-01','2026-10-02'); DELETE FROM reservations WHERE id=100");
$beforeMigration = snapshot($migration);
ensure_reservation_kind_schema($migration);
check(snapshot($migration) === $beforeMigration, 'Two-kind schema migration preserves every existing row including all child tables and extra columns');
check((int) $migration->query('PRAGMA foreign_keys')->fetchColumn() === 1 && $migration->query('PRAGMA foreign_key_check')->fetchAll() === [], 'Migrated foreign keys remain enabled and valid');
check((int) $migration->query("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('idx_fixture_reference','fixture_update','fixture_reservations','fixture_payment')")->fetchColumn() === 4, 'Custom index, view and both table triggers preserved');
check($migration->query('SELECT custom_reference FROM fixture_reservations')->fetchColumn() === 'keep this value', 'Existing view still resolves original table');
$migration->exec("UPDATE reservations SET rental_kind='test' WHERE id=$oldId");
check((int) $migration->query('SELECT COUNT(*) FROM migration_events')->fetchColumn() === 1, 'Original update trigger still executes');
$migration->exec("INSERT INTO payment_logs (reservation_id,amount,method) VALUES ($oldId,5,'cash')");
check((int) $migration->query('SELECT COUNT(*) FROM migration_events')->fetchColumn() === 2, 'External table trigger still targets reservations');
ensure_reservation_kind_schema($migration);
check($migration->query('SELECT rental_kind FROM reservations')->fetchColumn() === 'test' && (int) $migration->query('SELECT COUNT(*) FROM migration_events')->fetchColumn() === 2, 'Repeated startup leaves explicitly changed kinds intact without update writes');
$migration->exec("INSERT INTO reservations (bike_id,customer_id,start_at,end_at,rental_kind) VALUES (1,1,'2026-10-01','2026-10-02','test')");
check((int) $migration->lastInsertId() > 100, 'Migration preserves AUTOINCREMENT high-water mark after deleted rows');
fails(fn () => $migration->exec("UPDATE reservations SET rental_kind='invalid' WHERE id=$oldId"), 'CHECK constraint failed', 'Migrated kind restriction still rejects invalid values');
check($migration->query("SELECT COUNT(*) FROM sqlite_master WHERE name='reservations_kind_migration'")->fetchColumn() == 0, 'Migration leaves no temporary table behind');

// A still older database receives the missing kind column and is classified only once.
$withoutKind = preg_replace('/^.*rental_kind.*\R/m', '', $schema);
$legacy = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$legacy->exec($withoutKind);
seed($legacy);
$legacy->exec("INSERT INTO customers (id,name) VALUES (1,'Legacy fixture'); INSERT INTO reservations (id,bike_id,customer_id,start_at,end_at,notes) VALUES (1,1,1,'2026-09-25 09:00:00','2026-09-26 17:00:00','Snelle fietsregistratie via werkplaats. fixture'),(2,2,1,'2026-09-25 09:00:00','2026-09-26 17:00:00','Regular fixture')");
ensure_reservation_kind_schema($legacy);
check($legacy->query('SELECT rental_kind FROM reservations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) === ['replacement', 'rental'], 'Legacy notes classified only when missing kind column is introduced');
$legacy->exec("UPDATE reservations SET rental_kind='test' WHERE id=1");
ensure_reservation_kind_schema($legacy);
check($legacy->query('SELECT rental_kind FROM reservations WHERE id=1')->fetchColumn() === 'test', 'Legacy replacement can subsequently remain a test booking');

// Broken legacy references must abort atomically, restoring the original schema and FK setting.
$broken = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$broken->exec($oldSchema);
seed($broken);
reservation($broken);
$broken->exec("PRAGMA foreign_keys=OFF; INSERT INTO payment_logs (reservation_id,amount,method) VALUES (999,5,'cash'); PRAGMA foreign_keys=ON");
$brokenBefore = snapshot($broken);
$brokenSql = $broken->query("SELECT sql FROM sqlite_master WHERE name='reservations'")->fetchColumn();
fails(fn () => ensure_reservation_kind_schema($broken), 'foreign-keyfout', 'Broken legacy database fails migration safely');
check(snapshot($broken) === $brokenBefore && $broken->query("SELECT sql FROM sqlite_master WHERE name='reservations'")->fetchColumn() === $brokenSql, 'Failed migration restores all old data and schema');
check((int) $broken->query('PRAGMA foreign_keys')->fetchColumn() === 1 && !$broken->inTransaction(), 'Failed migration restores foreign key enforcement and closes transaction');

echo 'PASS: ' . $checks . ' rental edit and migration checks using PHP ' . PHP_VERSION . '. Synthetic fixtures only; no live services used.' . PHP_EOL;
