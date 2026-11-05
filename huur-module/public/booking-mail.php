<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_auth();
require_once __DIR__ . '/../app/booking_mail.php';

header('Cache-Control: no-store, private');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Methode niet toegestaan.');
}

$idInput = $_GET['id'] ?? '';
if (!is_string($idInput) || !ctype_digit($idInput) || (int) $idInput < 1) {
    http_response_code(400);
    exit('Ongeldig dossier.');
}
$id = (int) $idInput;
$prompt = booking_mail_prompt($id);
if ($prompt === null) {
    redirect('planning.php');
}

$error = null;
$uncertain = false;
if ($method === 'POST') {
    verify_csrf();
    $nonce = $_POST['nonce'] ?? null;
    $postedId = $_POST['reservation_id'] ?? null;
    $action = $_POST['action'] ?? null;
    if (!is_string($nonce) || !is_string($postedId) || $postedId !== (string) $id
        || !is_string($action) || !in_array($action, ['send', 'skip'], true)
        || !hash_equals((string) $prompt['nonce'], $nonce)) {
        http_response_code(400);
        exit('Ongeldige of verlopen keuze. Open het dossier opnieuw.');
    }
    try {
        if ($action === 'skip') {
            $continueUrl = booking_mail_finish_prompt($id, $nonce);
            flash('success', 'Boeking opgeslagen. De mailstap is overgeslagen.');
            redirect($continueUrl);
        }
        $recipientInput = $_POST['recipient'] ?? '';
        if (!is_string($recipientInput)) {
            throw new DomainException('Vul een geldig e-mailadres voor de klant in.');
        }
        $result = booking_mail_attempt($id, $nonce, trim($recipientInput));
        $status = (string) ($result['status'] ?? 'uncertain');
        if (in_array($status, ['sent', 'logged'], true)) {
            $continueUrl = booking_mail_finish_prompt($id, $nonce);
            if ($status === 'sent') {
                flash('success', 'De boekingsmail met agenda-afspraak is verstuurd naar de klant.');
            } else {
                flash('warning', 'Testmodus: de boekingsmail is lokaal opgeslagen en niet naar de klant verstuurd.');
            }
            redirect($continueUrl);
        }
        $uncertain = $status === 'uncertain';
        $error = $uncertain
            ? 'De mailprovider heeft de verzending niet bevestigd. De mail kan al onderweg zijn. Nogmaals versturen is geblokkeerd om dubbele mails te vermijden.'
            : 'De mail kon niet worden verstuurd. Controleer het e-mailadres en de mailinstellingen. De boeking is wel opgeslagen.';
    } catch (DomainException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Boekingsmail keuzescherm: ' . $exception->getMessage());
        $error = 'De verzending kon niet worden bevestigd. De boeking is wel opgeslagen. Controleer de mailregistratie voordat je opnieuw verstuurt.';
        $uncertain = true;
    }
}

try {
    $reservation = booking_mail_reservation_for_prompt($id);
} catch (DomainException $exception) {
    flash('error', $exception->getMessage());
    redirect('planning.php');
}

$recipient = trim((string) ($reservation['customer_email'] ?? ''));
$hasEmail = filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false;
$recipientInput = $method === 'POST' && is_string($_POST['recipient'] ?? null)
    ? trim($_POST['recipient']) : $recipient;
$start = new DateTimeImmutable((string) $reservation['start_at'], new DateTimeZone('Europe/Brussels'));
$end = new DateTimeImmutable((string) $reservation['end_at'], new DateTimeZone('Europe/Brussels'));
$isTest = ($reservation['rental_kind'] ?? '') === 'test';
$kindLabel = $isTest ? 'Testafspraak' : 'Huurafspraak';

render_header('Afspraak ingepland', true, 'booking-mail');
?>
<section class="card booking-mail-saved" aria-label="Boeking opgeslagen">
    <span class="booking-mail-kicker">Boeking opgeslagen</span>
    <h2><?= e($kindLabel) ?> voor <?= e((string) $reservation['customer_name']) ?></h2>
    <p>De fiets<?= count($reservation['bikes']) === 1 ? ' is' : 'en zijn' ?> ingepland. Kies hieronder of je de afspraak ook per mail wilt bevestigen.</p>
</section>

<dialog open class="booking-mail-dialog" data-booking-mail-dialog aria-labelledby="booking-mail-title" aria-describedby="booking-mail-description">
    <div class="booking-mail-dialog-inner">
        <div class="booking-mail-heading">
            <div>
                <span class="booking-mail-kicker">Nog één keuze</span>
                <h2 id="booking-mail-title">Afspraak naar de klant mailen?</h2>
            </div>
        </div>
        <p id="booking-mail-description">Stuur een bevestiging met de afspraakgegevens en een agendabijlage. De klant kan de boeking zo toevoegen aan de eigen agenda.</p>

        <?php if ($error !== null): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if (!$hasEmail): ?>
            <div class="alert alert-warning" role="status">Er staat nog geen geldig e-mailadres in dit dossier. Vul hieronder het adres van de klant in om de mail te versturen, of sla deze stap over.</div>
        <?php endif; ?>

        <dl class="booking-mail-details">
            <div><dt>Aan</dt><dd><?= e((string) $reservation['customer_name']) ?><span><?= $hasEmail ? e($recipient) : 'Geen geldig e-mailadres' ?></span></dd></div>
            <div><dt>Afspraak</dt><dd><?= e($kindLabel) ?> · dossier #<?= $id ?></dd></div>
            <div><dt>Afhalen</dt><dd><?= e($start->format('d/m/Y')) ?><strong><?= e($start->format('H:i')) ?></strong></dd></div>
            <div><dt>Terugbrengen</dt><dd><?= e($end->format('d/m/Y')) ?><strong><?= e($end->format('H:i')) ?></strong></dd></div>
            <div class="booking-mail-bike-detail"><dt><?= count($reservation['bikes']) === 1 ? 'Fiets' : 'Fietsen' ?></dt><dd><ul><?php foreach ($reservation['bikes'] as $bike): ?><li><?= e((string) $bike['code']) ?> · <?= e((string) $bike['name']) ?></li><?php endforeach; ?></ul></dd></div>
        </dl>

        <div class="field booking-mail-recipient">
            <label for="booking-mail-recipient">E-mailadres klant</label>
            <input id="booking-mail-recipient" name="recipient" type="email" value="<?= e($recipientInput) ?>" form="booking-mail-choice" autocomplete="email" required autofocus>
            <span class="help">Dit adres wordt bij het dossier opgeslagen wanneer je kiest om de mail te versturen.</span>
        </div>

        <p class="booking-mail-agenda-note">De agendabijlage (.ics) bevat een herinnering één uur vóór de start. De agenda-app van de klant bepaalt hoe deze melding wordt overgenomen. Deze keuze verstuurt nu één mail; er wordt geen latere herinneringsmail ingepland.</p>

        <form method="post" action="booking-mail.php?id=<?= $id ?>" id="booking-mail-choice" class="booking-mail-actions" data-booking-mail-form>
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="reservation_id" value="<?= $id ?>">
            <input type="hidden" name="nonce" value="<?= e((string) $prompt['nonce']) ?>">
            <input type="hidden" name="action" value="" disabled data-booking-action-value>
            <button type="submit" class="button booking-mail-send" name="action" value="send" data-booking-action="send" <?= $uncertain ? 'disabled' : '' ?>>Mail met agenda versturen</button>
            <button type="submit" class="button button-secondary" name="action" value="skip" formnovalidate data-booking-action="skip" data-booking-mail-skip>Overslaan · geen mail</button>
            <p class="booking-mail-submit-status" aria-live="polite" data-booking-mail-status></p>
        </form>
        <button class="booking-mail-close" type="submit" name="action" value="skip" form="booking-mail-choice" formnovalidate data-booking-action="skip" aria-label="Overslaan en verdergaan zonder mail">×</button>
    </div>
</dialog>
<?php render_footer('booking-mail'); ?>
