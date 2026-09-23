<?php

final class ExpiringContracts
{
    public static function window(DateTimeImmutable $now): array
    {
        $start = $now->setTimezone(new DateTimeZone('Europe/Brussels'))->setTime(0, 0);
        // Match three calendar months, clamping month-end instead of overflowing.
        $month = $start->modify('first day of this month')->modify('+3 months');
        $end = $month->setDate((int) $month->format('Y'), (int) $month->format('m'),
            min((int) $start->format('d'), (int) $month->format('t')));
        return [$start, $end];
    }

    public static function find(PDO $pdo, DateTimeImmutable $now, bool $withoutLogbook = true): array
    {
        [$start, $end] = self::window($now);
        $logbookFilter = $withoutLogbook
            ? ' AND NOT EXISTS (SELECT 1 FROM customer_logbook cl WHERE cl.lease_order_id = lo.id)'
            : '';
        $stmt = $pdo->prepare("
            SELECT lo.*,
                EXISTS (SELECT 1 FROM customer_logbook cl WHERE cl.lease_order_id = lo.id) AS in_progress,
                (SELECT MAX(cl.created_at) FROM customer_logbook cl WHERE cl.lease_order_id = lo.id) AS last_logbook_activity
            FROM lease_orders lo
            WHERE lo.archived = 0 AND lo.lease_end_date IS NOT NULL
              AND lo.lease_end_date BETWEEN :window_start AND :window_end
            $logbookFilter
            ORDER BY lo.lease_end_date ASC, lo.so_number ASC, lo.id ASC
        ");
        $stmt->execute([':window_start' => $start->format('Y-m-d'), ':window_end' => $end->format('Y-m-d')]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
