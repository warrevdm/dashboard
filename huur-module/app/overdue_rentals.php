<?php

declare(strict_types=1);

/** Read-only warnings, independent of the visible planning period and filters. */
function overdue_rentals(PDO $pdo, DateTimeImmutable $now, ?int $reservationId = null): array
{
    $localNow = $now->setTimezone(new DateTimeZone('Europe/Brussels'))->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare("SELECT r.id, r.end_at, c.name AS customer_name, c.phone,
        b.id AS bike_id, b.code, b.name AS bike_name,
        next.id AS next_id, next.start_at AS next_start
        FROM reservations r
        JOIN customers c ON c.id = r.customer_id
        JOIN reservation_bikes rb ON rb.reservation_id = r.id
        JOIN bikes b ON b.id = rb.bike_id
        LEFT JOIN reservations next ON next.id = (
            SELECT n.id FROM reservation_bikes nb JOIN reservations n ON n.id = nb.reservation_id
            WHERE nb.bike_id = rb.bike_id AND n.id != r.id
                AND n.status IN ('reserved', 'confirmed') AND n.end_at > :now
            ORDER BY n.start_at, n.id LIMIT 1
        )
        WHERE r.status = 'picked_up' AND r.end_at < :now"
        . ($reservationId !== null ? ' AND r.id = :id' : '') . ' ORDER BY r.end_at, r.id, b.code');
    $params = [':now' => $localNow];
    if ($reservationId !== null) $params[':id'] = $reservationId;
    $stmt->execute($params);
    $groups = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row['id'];
        if (!isset($groups[$id])) $groups[$id] = $row + ['bikes' => []];
        $groups[$id]['bikes'][] = $row;
    }
    return array_values($groups);
}

function render_overdue_rentals(array $reservations): void
{
    if (!$reservations) return;
    ?>
    <section class="card mt-18" aria-labelledby="overdue-title">
        <h2 id="overdue-title">⚠ Te late retour · <?= count($reservations) ?> dossier(s)</h2>
        <p>De retourtijd is verstreken en deze fietsen staan nog op Afgehaald. Controleer de terugkomst en werk het dossier bij.</p>
        <div class="grid">
        <?php foreach ($reservations as $reservation): ?>
            <article class="col-12 alert alert-warning">
                <strong>#<?= (int) $reservation['id'] ?> · <?= e($reservation['customer_name']) ?></strong>
                <p>Verwacht op <?= e((new DateTimeImmutable($reservation['end_at']))->format('d/m/Y H:i')) ?>.</p>
                <ul>
                <?php foreach ($reservation['bikes'] as $bike): ?>
                    <li><?= e($bike['code'] . ' — ' . $bike['bike_name']) ?>
                    <?php if ($bike['next_id']): ?>
                        <br><strong>Volgende reservatie mogelijk in gevaar:</strong>
                        <a href="reservation.php?id=<?= (int) $bike['next_id'] ?>">#<?= (int) $bike['next_id'] ?> · <?= e((new DateTimeImmutable($bike['next_start']))->format('d/m/Y H:i')) ?></a>
                    <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
                <div class="actions">
                    <a class="button" href="reservation.php?id=<?= (int) $reservation['id'] ?>">Dossier openen</a>
                    <?php if (trim((string) $reservation['phone']) !== ''): ?>
                        <a class="button button-secondary" href="tel:<?= e((string) preg_replace('/[^0-9+]/', '', $reservation['phone'])) ?>">Bel <?= e($reservation['phone']) ?></a>
                    <?php else: ?><span>Geen telefoonnummer geregistreerd.</span><?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
    </section>
    <?php
}
