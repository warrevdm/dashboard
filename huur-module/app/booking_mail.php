<?php

declare(strict_types=1);

require_once __DIR__ . '/booking_mail_message.php';

/** Only booking details that appear in the customer message form its version. */
function booking_mail_fingerprint(array $reservation): string
{
    $bikes = array_map(static fn (array $bike): array => [
        (int) $bike['id'], (string) $bike['code'], (string) $bike['name'],
    ], $reservation['bikes'] ?? []);
    usort($bikes, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    return hash('sha256', json_encode([
        (int) $reservation['id'], (int) $reservation['customer_id'],
        (string) $reservation['customer_name'], (string) ($reservation['customer_email'] ?? ''),
        (string) $reservation['start_at'], (string) $reservation['end_at'],
        (string) $reservation['rental_kind'], $bikes,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function booking_mail_continue_url(int $id, string $url): string
{
    $dossier = 'reservation.php?id=' . $id;
    return !is_finance() && $url === 'contract.php?reservation_id=' . $id ? $url : $dossier;
}

/** Called only after the booking has been committed; never sends mail. */
function booking_mail_start_prompt(int $id, string $continueUrl): string
{
    $reservation = find_reservation($id);
    if (!$reservation || !in_array($reservation['rental_kind'], ['rental', 'test'], true)) {
        return $continueUrl;
    }
    $prompts = $_SESSION['booking_mail_prompts'] ?? [];
    if (!is_array($prompts)) $prompts = [];
    foreach ($prompts as $key => $prompt) {
        if (!is_array($prompt) || (int) ($prompt['created_at'] ?? 0) < time() - 86400) unset($prompts[$key]);
    }
    // Bound the session without affecting independently opened booking tabs.
    while (count($prompts) >= 20) unset($prompts[array_key_first($prompts)]);
    $prompts[$id] = [
        'reservation_id' => $id, 'user_id' => (int) (current_user()['id'] ?? 0),
        'nonce' => bin2hex(random_bytes(32)), 'created_at' => time(),
        'continue_url' => booking_mail_continue_url($id, $continueUrl),
        'fingerprint' => booking_mail_fingerprint($reservation),
    ];
    $_SESSION['booking_mail_prompts'] = $prompts;
    return 'booking-mail.php?id=' . $id;
}

function booking_mail_prompt(int $reservationId): ?array
{
    $prompt = $_SESSION['booking_mail_prompts'][$reservationId] ?? null;
    if (!is_array($prompt) || (int) ($prompt['reservation_id'] ?? 0) !== $reservationId
        || (int) ($prompt['user_id'] ?? 0) !== (int) (current_user()['id'] ?? 0)
        || (int) ($prompt['created_at'] ?? 0) < time() - 86400
        || !is_string($prompt['nonce'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/', $prompt['nonce'])) {
        return null;
    }
    $prompt['continue_url'] = booking_mail_continue_url($reservationId, (string) ($prompt['continue_url'] ?? ''));
    return $prompt;
}

function booking_mail_reservation_for_prompt(int $id): array
{
    $prompt = booking_mail_prompt($id);
    $user = find_user((int) (current_user()['id'] ?? 0));
    if (!$prompt || !$user || !(int) $user['active'] || !in_array($user['role'], ['admin', 'staff', 'finance'], true)) {
        throw new DomainException('Deze mailkeuze is niet meer beschikbaar.');
    }
    $reservation = find_reservation($id);
    if (!$reservation || (int) $reservation['created_by'] !== (int) $user['id']
        || !in_array($reservation['rental_kind'], ['rental', 'test'], true)
        || !in_array($reservation['status'], ['reserved', 'confirmed', 'picked_up'], true)) {
        throw new DomainException('Voor dit dossier kan geen afspraakmail worden verstuurd.');
    }
    if (!hash_equals((string) $prompt['fingerprint'], booking_mail_fingerprint($reservation))) {
        throw new DomainException('Het dossier is intussen gewijzigd. Controleer de afspraak in het dossier; er is geen mail verstuurd.');
    }
    return $reservation;
}

function booking_mail_finish_prompt(int $id, string $nonce): string
{
    $prompt = booking_mail_prompt($id);
    if (!$prompt || !hash_equals($prompt['nonce'], $nonce)) {
        throw new DomainException('Deze mailkeuze is verlopen. Er is geen nieuwe mail verstuurd.');
    }
    unset($_SESSION['booking_mail_prompts'][$id]);
    return $prompt['continue_url'];
}

/** A persistent claim prevents duplicate messages, including after a timeout. */
function booking_mail_attempt(int $id, string $nonce, ?string $recipient = null, ?callable $send = null): array
{
    $prompt = booking_mail_prompt($id);
    if (!$prompt || !hash_equals($prompt['nonce'], $nonce)) {
        throw new DomainException('Deze mailkeuze is verlopen. Er is geen nieuwe mail verstuurd.');
    }
    $reservation = booking_mail_reservation_for_prompt($id);
    $recipient = trim($recipient ?? (string) ($reservation['customer_email'] ?? ''));
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || strpbrk($recipient, "\r\n") !== false) {
        throw new DomainException('Vul een geldig e-mailadres van de klant in.');
    }
    $reservation['customer_email'] = $recipient;
    $message = booking_mail_message($reservation);
    if ($send === null) booking_mail_transport_ready();
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS reservation_booking_mail_runs (
        reservation_id INTEGER PRIMARY KEY REFERENCES reservations(id),
        recipient TEXT NOT NULL,
        status TEXT NOT NULL CHECK(status IN ('sending','sent','logged','failed','uncertain')),
        created_by INTEGER NOT NULL REFERENCES users(id),
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->beginTransaction();
    try {
        $current = booking_mail_reservation_for_prompt($id);
        $claim = $pdo->prepare("INSERT OR IGNORE INTO reservation_booking_mail_runs (reservation_id,recipient,status,created_by) VALUES (?,?,'sending',?)");
        $claim->execute([$id, $recipient, (int) current_user()['id']]);
        if ($claim->rowCount() === 0) {
            $existing = $pdo->prepare('SELECT status FROM reservation_booking_mail_runs WHERE reservation_id=?');
            $existing->execute([$id]);
            $status = (string) $existing->fetchColumn();
            if ($status === 'failed') {
                // The transport uses failed only when no delivery was attempted.
                $retry = $pdo->prepare("UPDATE reservation_booking_mail_runs SET status='sending',recipient=?,updated_at=CURRENT_TIMESTAMP WHERE reservation_id=? AND status='failed'");
                $retry->execute([$recipient, $id]);
                if ($retry->rowCount() !== 1) throw new DomainException('De mail wordt al verwerkt. Herlaad deze pagina.');
            } else {
                $pdo->commit();
                return ['status' => in_array($status, ['sent', 'logged'], true) ? $status : 'uncertain',
                    'error' => in_array($status, ['sent', 'logged'], true) ? null : 'Er is al een verzendpoging geregistreerd. Controleer de mailbox; er wordt geen tweede mail verstuurd.'];
            }
        }
        // Newly created bookings own a separate customer record. Never overwrite a
        // customer that another dossier has since started sharing.
        if ($recipient !== (string) ($current['customer_email'] ?? '')) {
            $shared = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE customer_id=? AND id!=?');
            $shared->execute([(int) $current['customer_id'], $id]);
            if ((int) $shared->fetchColumn() > 0) throw new DomainException('Pas het e-mailadres aan in het dossier: deze klant wordt door meerdere dossiers gebruikt.');
            $update = $pdo->prepare('UPDATE customers SET email=? WHERE id=?');
            $update->execute([$recipient, (int) $current['customer_id']]);
        }
        audit('booking_mail_requested', 'reservation', $id, ['rental_kind' => $current['rental_kind']]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    $_SESSION['booking_mail_prompts'][$id]['fingerprint'] = booking_mail_fingerprint(find_reservation($id));
    // The transaction is closed before contacting a mail provider.
    try {
        $result = ($send ?? 'send_booking_mail_message')($message);
        if (!is_array($result) || !in_array($result['status'] ?? '', ['sent', 'logged', 'failed', 'uncertain'], true)) {
            throw new RuntimeException('Ongeldig verzendresultaat.');
        }
    } catch (Throwable $error) {
        error_log('Afspraakmail niet bevestigd voor dossier #' . $id . '.');
        $result = ['status' => 'uncertain', 'error' => 'De verzending is niet bevestigd. Controleer de mailbox voordat je opnieuw mailt.'];
    }
    $update = $pdo->prepare('UPDATE reservation_booking_mail_runs SET status=?, updated_at=CURRENT_TIMESTAMP WHERE reservation_id=?');
    $update->execute([$result['status'], $id]);
    return $result;
}
