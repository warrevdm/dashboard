<?php

declare(strict_types=1);

require __DIR__ . '/src/auth.php';
authRequireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$email = trim((string) ($_GET['email'] ?? ''));
$item = trim((string) ($_GET['item'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
    echo json_encode(['ok' => true, 'matches' => [], 'exact_match' => false], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$stmt = authDb()->prepare(
    "SELECT ml.id, ml.customer_name, ml.customer_email, ml.bike_type, ml.pickup_note, ml.subject, ml.created_at,
            COALESCE(u.name, 'Onbekende gebruiker') AS sender_name
     FROM mail_log ml
     LEFT JOIN users u ON u.id = ml.user_id
     WHERE ml.status = 'sent' AND lower(ml.customer_email) = lower(?)
     ORDER BY ml.created_at DESC
     LIMIT 10"
);
$stmt->execute([$email]);
$rows = $stmt->fetchAll();

$normalizedItem = mb_strtolower(preg_replace('/\s+/', ' ', trim($item)) ?? '');
$matches = [];
$exactMatch = false;

foreach ($rows as $row) {
    $rowItem = mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $row['bike_type'])) ?? '');
    $isExact = $normalizedItem !== '' && $rowItem === $normalizedItem;
    $exactMatch = $exactMatch || $isExact;
    $subject = (string) $row['subject'];

    $matches[] = [
        'customer_name' => (string) $row['customer_name'],
        'email' => (string) $row['customer_email'],
        'item' => (string) $row['bike_type'],
        'note' => (string) $row['pickup_note'],
        'sender' => (string) $row['sender_name'],
        'created_at' => (string) $row['created_at'],
        'type' => str_contains(mb_strtolower($subject), 'bestelling') ? 'Collect & Go' : 'Nieuwe fiets',
        'exact_item' => $isExact,
    ];
}

echo json_encode([
    'ok' => true,
    'matches' => $matches,
    'exact_match' => $exactMatch,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
