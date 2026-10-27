<?php

declare(strict_types=1);

final class ImportValues
{
    /** A partial partner export must not erase existing contact or contract data. */
    public static function preserveExisting(array $incoming, ?array $existing): array
    {
        if ($existing === null) return $incoming;
        $fields = ['customer_name', 'email', 'phone', 'company', 'lease_partner', 'order_status',
            'bike_type', 'bike_name', 'frame_number', 'lease_start_date', 'lease_end_date',
            'maintenance_budget', 'yearly_maintenance_end_date'];
        foreach ($fields as $field) {
            if (!isset($incoming[$field]) || trim((string)$incoming[$field]) === '') {
                $source = $field === 'order_status' ? 'order_status_raw' : $field;
                $incoming[$field] = $existing[$source] ?? null;
            }
        }
        return $incoming;
    }
}
