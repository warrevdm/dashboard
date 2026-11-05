<?php

declare(strict_types=1);
require_once __DIR__ . '/ImportValidation.php';

final class ContractUpdate
{
    public static function version(array $order): string
    {
        ksort($order);
        return hash('sha256', serialize($order));
    }

    public static function values(array $input, array $existing): array
    {
        $values = [];
        foreach (['so_number'=>100, 'customer_name'=>255, 'email'=>255, 'phone'=>100,
            'company'=>255, 'lease_partner'=>255, 'bike_type'=>64, 'bike_name'=>255, 'frame_number'=>255] as $field=>$limit) {
            $value = $input[$field] ?? '';
            if (!is_scalar($value)) throw new InvalidArgumentException('Ongeldige invoer voor ' . $field . '.');
            $values[$field] = trim((string)$value);
            if (mb_strlen($values[$field]) > $limit) throw new InvalidArgumentException('De invoer voor ' . $field . ' is te lang.');
        }
        if ($values['so_number'] === '') throw new InvalidArgumentException('SO-number is verplicht.');
        if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Vul een geldig e-mailadres in of laat dit veld leeg.');
        if (!in_array($values['bike_type'], ['elektrisch','racefiets','gravelfiets','speedpedelec','elektrische gezinsfiets','niet elektrisch city bike','mountainbike','onbekend'], true)) throw new InvalidArgumentException('Kies een geldig fietstype.');
        foreach (['lease_start_date','lease_end_date','yearly_maintenance_end_date'] as $field) $values[$field] = ImportValidation::date($input[$field] ?? null);
        if ($values['lease_start_date'] && $values['lease_end_date'] && $values['lease_end_date'] < $values['lease_start_date']) throw new InvalidArgumentException('De einddatum mag niet vóór de startdatum liggen.');
        $values['maintenance_budget'] = ImportValidation::money($input['maintenance_budget'] ?? null);
        if ($values['maintenance_budget'] !== null && abs($values['maintenance_budget']) > 99999999.99) throw new InvalidArgumentException('Het onderhoudsbudget is te groot.');
        foreach (['o2o_budget_grace_start_date','o2o_budget_valid_until','cyclis_budget_grace_start_date','cyclis_budget_valid_until'] as $field) $values[$field] = null;
        $partner = mb_strtolower($values['lease_partner']);
        if ($partner === 'o2o') {
            $values['o2o_budget_grace_start_date'] = self::months($values['lease_start_date'], 36);
            $values['o2o_budget_valid_until'] = self::months($values['lease_start_date'], 37);
            $values['lease_end_date'] ??= $values['o2o_budget_grace_start_date'];
            $values['yearly_maintenance_end_date'] = null;
        } elseif ($partner === 'cyclis') {
            $values['cyclis_budget_grace_start_date'] = $values['lease_end_date'];
            $values['cyclis_budget_valid_until'] = self::months($values['lease_end_date'], 12);
            $values['yearly_maintenance_end_date'] = null;
        } elseif (str_contains($partner, 'lease a bike') && $values['lease_start_date']
            && ($values['lease_start_date'] !== ($existing['lease_start_date'] ?? null)
                || $values['lease_end_date'] !== ($existing['lease_end_date'] ?? null)
                || mb_strtolower((string)($existing['lease_partner'] ?? '')) !== $partner)) {
            $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Brussels'));
            $end = $values['lease_end_date'];
            if ($end && $end < $today->format('Y-m-d')) $values['yearly_maintenance_end_date'] = $end;
            else {
                $months = 12;
                do { $annual = self::months($values['lease_start_date'], $months); $months += 12; } while ($annual < $today->format('Y-m-d'));
                $values['yearly_maintenance_end_date'] = $end && $annual > $end ? $end : $annual;
            }
        }
        return $values;
    }

    private static function months(?string $value, int $months): ?string
    {
        if (!$value) return null;
        $date = new DateTimeImmutable($value, new DateTimeZone('Europe/Brussels'));
        $month = $date->modify('first day of this month')->modify('+' . $months . ' months');
        return $month->setDate((int)$month->format('Y'), (int)$month->format('m'), min((int)$date->format('d'), (int)$month->format('t')))->format('Y-m-d');
    }
}
