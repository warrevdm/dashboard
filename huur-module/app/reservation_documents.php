<?php

declare(strict_types=1);

function attach_reservation_identity_document(int $reservationId, array $file, string $retention): void
{
    $user = current_user();
    $stmt = db()->prepare('SELECT role, active FROM users WHERE id = ?');
    $stmt->execute([(int) ($user['id'] ?? 0)]);
    $actor = $stmt->fetch();
    if (!$actor || !(int) $actor['active'] || !in_array($actor['role'], ['admin', 'staff'], true)) {
        throw new DomainException('Geen toegang om een identiteitsdocument toe te voegen.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $retention);
    if (!$date || $date->format('Y-m-d') !== $retention || $date <= new DateTimeImmutable('today')) {
        throw new DomainException('Kies een geldige bewaardatum vanaf morgen.');
    }
    $path = null;
    db()->beginTransaction();
    try {
        $reservation = find_reservation($reservationId);
        if (!$reservation || $reservation['status'] === 'cancelled') {
            throw new DomainException('Dit dossier kan niet meer worden aangepast.');
        }
        if ($reservation['document_id'] && !$reservation['document_deleted_at']) {
            throw new DomainException('Dit dossier heeft al een identiteitsdocument. Vernieuw de pagina om het te bekijken.');
        }
        $documentId = upload_identity_document($file, (int) $reservation['customer_id'], $retention);
        if ($documentId === null) throw new DomainException('Kies eerst een foto of PDF om te uploaden.');
        $stored = db()->prepare('SELECT stored_name FROM identity_documents WHERE id = ?');
        $stored->execute([$documentId]);
        $path = ROOT_PATH . '/storage/private/ids/' . $stored->fetchColumn();
        $update = db()->prepare('UPDATE reservations SET identity_document_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $update->execute([$documentId, $reservationId]);
        audit('upload_identity_document', 'reservation', $reservationId, ['document_id' => $documentId]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        if ($path !== null) @unlink($path);
        throw $e;
    }
}
