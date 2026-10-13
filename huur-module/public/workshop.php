<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
header('Cache-Control: no-store, private');
// Recheck the database on each refresh so revoked access does not remain active.
$userId = (int) (current_user()['id'] ?? 0);
$stmt = db()->prepare('SELECT role, active FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user || !(int)$user['active'] || !in_array($user['role'], ['admin','staff'], true)) {
    if (isset($_GET['refresh'])) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error'=>'Opnieuw aanmelden vereist.']);
        exit;
    }
    require_auth();
    http_response_code(403);
    exit('Geen toegang tot het werkplaatsscherm.');
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
require_once __DIR__ . '/../app/workshop_board.php';
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels'));
$columns = workshop_board_data(db(), $now);
ob_start();
render_workshop_columns($columns);
$html = (string) ob_get_clean();
if (isset($_GET['refresh'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['html'=>$html,'updated'=>$now->format('d/m/Y H:i:s')], JSON_THROW_ON_ERROR);
    exit;
}
?>
<!doctype html>
<html lang="nl-BE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Werkplaatsscherm · Aerts Action Bike</title>
    <link rel="icon" href="assets/aerts-action-bike-logo.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/workshop.css?v=<?= (int)filemtime(__DIR__.'/assets/workshop.css') ?>">
    <script src="assets/workshop.js?v=<?= (int)filemtime(__DIR__.'/assets/workshop.js') ?>" defer></script>
</head>
<body>
    <header class="board-header">
        <div class="board-brand"><img src="assets/aerts-action-bike-logo.svg" alt="Aerts Action Bike"><div><p>WINKEL &amp; WERKPLAATS</p><h1>Vandaag onderweg</h1></div></div>
        <div class="board-tools"><a href="planning.php">Planning</a><button type="button" id="refresh">Nu vernieuwen</button><button type="button" id="fullscreen">Volledig scherm</button></div>
    </header>
    <div class="board-meta"><span id="refresh-status" role="status">Bijgewerkt: <?= e($now->format('d/m/Y H:i:s')) ?> · vernieuwt elke 60 seconden</span><span>Europe/Brussels · aantallen per dossier</span></div>
    <noscript><p>Automatisch vernieuwen vereist JavaScript. Herlaad deze pagina om actuele gegevens te zien.</p></noscript>
    <main id="board" class="board-grid"><?= $html ?></main>
    <footer>Klik op een dossier om het te openen. Scroll binnen een kolom voor meer dossiers.</footer>
</body>
</html>
