<?php

declare(strict_types=1);

function reservation_can_mark_no_show(array $reservation, ?DateTimeImmutable $now = null): bool
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels'));
    return in_array($reservation['rental_kind'] ?? '', ['rental','test'], true)
        && in_array($reservation['status'] ?? '', ['reserved','confirmed'], true)
        && new DateTimeImmutable($reservation['start_at'], new DateTimeZone('Europe/Brussels')) <= $now
        && !array_filter($reservation['bikes'] ?? [], static fn(array $bike): bool => !empty($bike['returned_at']));
}

/** Explicit transition; no fees, refunds or removal of historical records. */
function change_reservation_no_show(int $id, string $target, string $reason = '', bool $confirmed = false): void
{
    $pdo = db();
    $user = find_user((int)(current_user()['id'] ?? 0));
    if (!$user || !(int)$user['active'] || !in_array($user['role'], ['admin','staff'], true)) throw new DomainException('Geen rechten om een no-show te registreren.');
    $pdo->beginTransaction();
    try {
        $reservation = find_reservation($id);
        if (!$reservation) throw new DomainException('Reservatie niet gevonden.');
        $reason = trim($reason);
        if (mb_strlen($reason) > 1000) throw new DomainException('De reden mag maximaal 1000 tekens bevatten.');
        if ($target === 'no_show') {
            if ($reservation['status'] === 'no_show') { $pdo->commit(); return; }
            if (!$confirmed) throw new DomainException('Bevestig dat de klant niet is komen opdagen.');
            if (!reservation_can_mark_no_show($reservation)) throw new DomainException('No-show is alleen mogelijk bij een niet-afgehaalde huur- of testreservatie vanaf het afgesproken startuur.');
            $stmt = $pdo->prepare("UPDATE reservations SET status='no_show', no_show_reason=?, closed_by=?, closed_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $stmt->execute([$reason ?: null, $user['id'], $id]);
            $stmt = $pdo->prepare('UPDATE rental_contracts SET public_token_hash=NULL, public_token_expires_at=NULL, updated_at=CURRENT_TIMESTAMP WHERE reservation_id=? AND signed_at IS NULL');
            $stmt->execute([$id]);
            audit('reservation_no_show', 'reservation', $id, ['from_status'=>$reservation['status'], 'reason'=>$reason, 'bike_ids'=>array_column($reservation['bikes'],'id')]);
        } else {
            if ($reservation['status'] !== 'no_show' || !in_array($target, ['reserved','confirmed'], true)) throw new DomainException('Heropen een no-show als gereserveerd of bevestigd.');
            $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels'));
            if (new DateTimeImmutable($reservation['end_at'], new DateTimeZone('Europe/Brussels')) <= $now) throw new DomainException('De oorspronkelijke periode is voorbij. Maak een nieuwe reservatie met de juiste periode.');
            foreach ($reservation['bikes'] as $bike) {
                if ($bike['status'] !== 'active' || reservation_conflicts((int)$bike['id'], $reservation['start_at'], $reservation['end_at'], $id)) throw new DomainException('Heropenen kan niet: ' . $bike['code'] . ' is niet beschikbaar in deze periode. Maak een nieuwe reservatie met een andere fiets of periode.');
            }
            $stmt = $pdo->prepare('UPDATE reservations SET status=?, closed_by=NULL, closed_at=NULL, no_show_reason=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $stmt->execute([$target, $id]);
            audit('reservation_no_show_reopened', 'reservation', $id, ['to_status'=>$target, 'previous_reason'=>$reservation['no_show_reason'] ?? null]);
        }
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function reservation_no_shows(DateTimeImmutable $start, DateTimeImmutable $end, string $category = '', string $usage = ''): array
{
    $sql = "SELECT r.id,r.start_at,r.no_show_reason,c.name AS customer_name,
        (SELECT GROUP_CONCAT(b.code || ' — ' || b.name, ', ') FROM reservation_bikes rb JOIN bikes b ON b.id=rb.bike_id WHERE rb.reservation_id=r.id) AS bikes
        FROM reservations r JOIN customers c ON c.id=r.customer_id
        WHERE r.status='no_show' AND r.start_at >= :start AND r.start_at < :end";
    $params = [':start'=>$start->format('Y-m-d H:i:s'), ':end'=>$end->format('Y-m-d H:i:s')];
    if ($category !== '' || $usage !== '') {
        $sql .= ' AND EXISTS(SELECT 1 FROM reservation_bikes nb JOIN bikes b ON b.id=nb.bike_id WHERE nb.reservation_id=r.id';
        if ($category !== '') { $sql .= ' AND b.category=:category'; $params[':category']=$category; }
        if ($usage !== '') {
            $sql .= $usage === 'test' ? ' AND b.usage_type=:usage' : " AND b.usage_type IN (:usage,'replacement_rental')";
            $params[':usage']=$usage;
        }
        $sql .= ')';
    }
    $stmt = db()->prepare($sql . ' ORDER BY r.start_at DESC,r.id DESC LIMIT 100');
    $stmt->execute($params);
    return $stmt->fetchAll();
}
