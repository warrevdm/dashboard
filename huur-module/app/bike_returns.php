<?php

declare(strict_types=1);

function return_reservation_bike(int $reservationId, int $bikeId): void
{
    $pdo = db();
    $user = find_user((int) (current_user()['id'] ?? 0));
    if (!$user || !(int)$user['active'] || !in_array($user['role'], ['admin','staff'], true)) throw new DomainException('Geen rechten om fietsen terug te nemen.');
    $pdo->beginTransaction();
    try {
        $reservation = find_reservation($reservationId);
        $bike = null;
        foreach ($reservation['bikes'] ?? [] as $candidate) if ((int)$candidate['id'] === $bikeId) $bike = $candidate;
        if (!$bike) throw new DomainException('Deze fiets hoort niet bij dit dossier.');
        if (!empty($bike['returned_at'])) { $pdo->commit(); return; }
        if ($reservation['status'] !== 'picked_up') throw new DomainException('Een fiets kan alleen teruggenomen worden uit een afgehaald dossier.');
        $now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels')))->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare('UPDATE reservation_bikes SET returned_at=?, returned_by=? WHERE reservation_id=? AND bike_id=? AND returned_at IS NULL');
        $stmt->execute([$now, $user['id'], $reservationId, $bikeId]);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM reservation_bikes WHERE reservation_id=? AND returned_at IS NULL');
        $stmt->execute([$reservationId]);
        $allReturned = (int)$stmt->fetchColumn() === 0;
        $stmt = $pdo->prepare("UPDATE reservations SET updated_at=CURRENT_TIMESTAMP, status=?,
            closed_at=CASE WHEN ? THEN CURRENT_TIMESTAMP ELSE closed_at END,
            closed_by=CASE WHEN ? THEN ? ELSE closed_by END WHERE id=?");
        $stmt->execute([$allReturned ? 'returned' : 'picked_up', $allReturned, $allReturned, $user['id'], $reservationId]);
        audit('return_bike', 'reservation', $reservationId, ['bike_id'=>$bikeId,'returned_at'=>$now,'all_returned'=>$allReturned]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
