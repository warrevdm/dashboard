<?php

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();
// Role revocations also apply to a previously authenticated session.
$account = find_user((int) current_user()['id']);
if (!$account || !(int) $account['active'] || $account['role'] !== 'admin') {
    http_response_code(403);
    exit('Geen toegang.');
}
require_once __DIR__ . '/../app/daily_rental_mail.php';
header('Cache-Control: no-store, private');
$result = null;
$error = null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) { http_response_code(405); exit; }
if ($method === 'POST') {
    verify_csrf();
    $requestId = $_POST['request_id'] ?? null;
    if (!is_string($requestId) || !isset($_SESSION['daily_mail_requests'][$requestId]) || ($_POST['confirm_send'] ?? '') !== '1') {
        http_response_code(400);
        exit('Bevestig de verzending via het formulier.');
    }
    // Persisted per-recipient claims make repeated POSTs harmless. Release the session
    // before network I/O, so other tabs remain responsive while mail is being sent.
    session_write_close();
    try {
        validate_daily_rental_mail_config();
        $result = run_daily_rental_mail(db(), new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels')), 'send_daily_rental_message', $requestId);
    } catch (Throwable $e) {
        $error = 'De verzending kon niet volledig worden bevestigd. Controleer de mailinstellingen en verzendregistratie voordat je opnieuw verstuurt.';
    }
} else {
    $requestId = bin2hex(random_bytes(32));
    $requests = $_SESSION['daily_mail_requests'] ?? [];
    $requests[$requestId] = time();
    $_SESSION['daily_mail_requests'] = array_slice($requests, -20, null, true);
}
render_header('Dagmail versturen');
?>
<div class="card">
    <h1>Verhuren onderweg mailen</h1>
    <p>Verstuur het actuele overzicht naar <strong>werkplaats@aertsactionbike.be</strong>, <strong>marketing@aertsactionbike.be</strong> en <strong>verkoop@aertsactionbike.be</strong>.</p>
    <p>Dit is een echte e-mail naar beide ontvangers, ook vóór 17:00. De automatische dagmail om 17:00 blijft apart gepland.</p>
    <?php if ($result !== null): ?>
        <div class="alert alert-success"><?= e($result) ?></div>
        <p><a class="button button-secondary" href="daily-rental-mail-admin.php">Een nieuwe verzending voorbereiden</a></p>
    <?php elseif ($error !== null): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
        <p>Vernieuw deze pagina niet om een nieuwe verzending te starten. Een onbevestigde poging kan al bij de mailprovider zijn aangekomen.</p>
    <?php else: ?>
        <form method="post" class="stack">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="request_id" value="<?= e($requestId) ?>">
            <label><input class="checkbox-inline" type="checkbox" name="confirm_send" value="1" required> Ik wil het huidige overzicht nu naar beide ontvangers versturen.</label>
            <button class="button" type="submit">Nu versturen</button>
        </form>
    <?php endif; ?>
    <p><a href="planning.php">Terug naar planning</a></p>
</div>
<?php render_footer(); ?>
