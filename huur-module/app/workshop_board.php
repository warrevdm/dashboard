<?php

declare(strict_types=1);

function workshop_board_data(PDO $pdo, DateTimeImmutable $now): array
{
    $now = $now->setTimezone(new DateTimeZone('Europe/Brussels'));
    $stmt = $pdo->prepare("SELECT r.id, r.start_at, r.end_at, r.status, r.rental_kind,
        c.name AS customer_name, b.code, b.name AS bike_name
        FROM reservations r JOIN customers c ON c.id=r.customer_id
        JOIN reservation_bikes rb ON rb.reservation_id=r.id JOIN bikes b ON b.id=rb.bike_id
        WHERE rb.returned_at IS NULL AND ((r.start_at >= :today AND r.start_at < :tomorrow AND r.status IN ('reserved','confirmed'))
            OR (r.end_at >= :today AND r.end_at < :tomorrow AND r.status IN ('confirmed','picked_up'))
            OR (r.status='picked_up' AND r.end_at < :now))
        ORDER BY r.start_at, r.id, b.code");
    $stmt->execute([':today'=>$now->format('Y-m-d 00:00:00'), ':tomorrow'=>$now->modify('+1 day')->format('Y-m-d 00:00:00'), ':now'=>$now->format('Y-m-d H:i:s')]);
    $groups = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row['id'];
        if (!isset($groups[$id])) $groups[$id] = $row + ['bikes'=>[]];
        $groups[$id]['bikes'][] = $row['code'] . ' — ' . $row['bike_name'];
    }
    $columns = ['pickups'=>[], 'returns'=>[], 'overdue'=>[]];
    foreach ($groups as $row) {
        if (substr($row['start_at'],0,10)===$now->format('Y-m-d') && in_array($row['status'],['reserved','confirmed'],true)) $columns['pickups'][] = $row;
        $late = $row['status']==='picked_up' && $row['end_at'] < $now->format('Y-m-d H:i:s');
        if ($late) $columns['overdue'][] = $row;
        elseif (substr($row['end_at'],0,10)===$now->format('Y-m-d') && in_array($row['status'],['confirmed','picked_up'],true)) $columns['returns'][] = $row;
    }
    foreach (['returns','overdue'] as $column) usort($columns[$column], static fn(array $a,array $b): int => [$a['end_at'],$a['id']] <=> [$b['end_at'],$b['id']]);
    return $columns;
}

function render_workshop_columns(array $columns): void
{
    foreach (['pickups'=>'Afhalingen vandaag','returns'=>'Retours vandaag','overdue'=>'Te laat terug'] as $key=>$label):
        $rows = $columns[$key]; ?>
        <section class="board-column board-<?= e($key) ?>" aria-labelledby="title-<?= e($key) ?>">
            <h2 id="title-<?= e($key) ?>"><?= e($label) ?> <span><?= count($rows) ?></span></h2>
            <div class="board-list" tabindex="0" aria-label="<?= e($label) ?>; scroll voor meer dossiers">
            <?php if (!$rows): ?><p class="board-empty"><?= $key==='overdue' ? 'Alles op tijd.' : 'Geen openstaande dossiers.' ?></p><?php endif; ?>
            <?php foreach ($rows as $row): $date = new DateTimeImmutable($row[$key==='pickups' ? 'start_at' : 'end_at']); ?>
                <a class="board-card" href="reservation.php?id=<?= (int)$row['id'] ?>">
                    <div class="board-card-top"><time><?= e($date->format($key==='overdue' ? 'd/m · H:i' : 'H:i')) ?></time><span>#<?= (int)$row['id'] ?> · <?= e(rental_kind_label($row['rental_kind'])) ?></span></div>
                    <h3><?= e($row['customer_name']) ?></h3>
                    <ul><?php foreach ($row['bikes'] as $bike): ?><li><?= e($bike) ?></li><?php endforeach; ?></ul>
                    <span class="board-status"><?= e(status_label($row['status'])) ?> → Dossier</span>
                </a>
            <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach;
}
