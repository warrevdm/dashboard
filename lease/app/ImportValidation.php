<?php

declare(strict_types=1);

/** Shared import/manual-edit parsing: an invalid supplied value is never silently erased. */
final class ImportValidation
{
    public const FIELDS = ['so_number', 'customer_name', 'email', 'phone', 'company', 'lease_partner',
        'order_status', 'bike_type', 'bike_name', 'frame_number', 'lease_start_date', 'lease_end_date',
        'maintenance_budget', 'yearly_maintenance_end_date'];

    public static function date(mixed $value): ?string
    {
        if ($value === null || (is_scalar($value) && trim((string)$value) === '')) return null;
        if (!is_scalar($value)) throw new InvalidArgumentException('Ongeldige datum.');
        $value = trim((string)$value);
        if (preg_match('/^\d+(?:\.\d+)?$/D', $value)) {
            $serial = (float)$value;
            if ($serial < 1 || $serial > 2958465) throw new InvalidArgumentException('Ongeldige Excel-datum.');
            $origin = $serial < 60 ? '1899-12-31' : '1899-12-30';
            return (new DateTimeImmutable($origin, new DateTimeZone('UTC')))->modify('+' . (int)floor($serial) . ' days')->format('Y-m-d');
        }
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'd-m-y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || (!$errors['warning_count'] && !$errors['error_count']))) {
                $year = (int)$date->format('Y');
                if ($year >= 1900 && $year <= 9999) return $date->format('Y-m-d');
            }
        }
        throw new InvalidArgumentException('Ongeldige datum. Gebruik bijvoorbeeld 24/10/2026 of 2026-10-24.');
    }

    public static function money(mixed $value): ?float
    {
        if ($value === null || (is_scalar($value) && trim((string)$value) === '')) return null;
        if (!is_scalar($value)) throw new InvalidArgumentException('Ongeldig bedrag.');
        $text = preg_replace('/[\s\x{00A0}\x{202F}€]/u', '', (string)$value);
        if (!is_string($text) || !preg_match('/^[+-]?\d[\d.,]*$/D', $text)) throw new InvalidArgumentException('Ongeldig bedrag. Gebruik bijvoorbeeld 1234,56.');
        $sign = '';
        if ($text[0] === '-' || $text[0] === '+') { $sign = $text[0]; $text = substr($text, 1); }
        $comma = strrpos($text, ',');
        $dot = strrpos($text, '.');
        if ($comma !== false && $dot !== false) {
            $decimal = $comma > $dot ? ',' : '.';
            $grouping = $decimal === ',' ? '.' : ',';
            [$whole, $fraction] = explode($decimal, $text, 2);
            if (!preg_match('/^\d{1,3}(?:' . preg_quote($grouping, '/') . '\d{3})+$/D', $whole)
                || !preg_match('/^\d{1,2}$/D', $fraction)) throw new InvalidArgumentException('Ongeldige scheidingstekens in bedrag.');
            $normalized = str_replace($grouping, '', $whole) . '.' . $fraction;
        } elseif ($comma !== false || $dot !== false) {
            $separator = $comma !== false ? ',' : '.';
            $parts = explode($separator, $text);
            if (count($parts) === 2 && strlen($parts[1]) >= 1 && strlen($parts[1]) <= 2) {
                $normalized = $parts[0] . '.' . $parts[1];
            } elseif (preg_match('/^\d{1,3}(?:' . preg_quote($separator, '/') . '\d{3})+$/D', $text)) {
                $normalized = str_replace($separator, '', $text);
            } else throw new InvalidArgumentException('Ongeldige scheidingstekens in bedrag.');
        } else $normalized = $text;
        $number = (float)($sign . $normalized);
        if (!is_finite($number) || abs($number) > 99999999.99) throw new InvalidArgumentException('Bedrag valt buiten het toegestane bereik.');
        return round($number, 2);
    }

    public static function mappingErrors(array $mapping, int $columnCount): array
    {
        $errors = [];
        if (!isset($mapping['so_number']) || $mapping['so_number'] === '') $errors[] = ['row'=>null, 'message'=>'Koppel de kolom SO-number voordat je importeert.'];
        foreach ($mapping as $field => $index) {
            if (!in_array($field, self::FIELDS, true)) { $errors[] = ['row'=>null, 'message'=>'Onbekend databaseveld in de kolomkoppeling.']; continue; }
            if ($index === '' || $index === null) continue;
            if (!is_scalar($index) || !ctype_digit((string)$index) || (int)$index >= $columnCount) $errors[] = ['row'=>null, 'message'=>'Een kolomkoppeling verwijst niet naar een bestaande kolom. Kies de kolommen opnieuw.'];
        }
        return $errors;
    }

    public static function row(array $row, array $mapping): array
    {
        $data = [];
        foreach ($mapping as $field=>$index) $data[$field] = ($index !== '' && $index !== null) ? ($row[(int)$index] ?? null) : null;
        return $data;
    }

    /** Validate the complete file, including rows beyond the ten-row preview. */
    public static function errors(array $rows, array $mapping, int $columnCount): array
    {
        $errors = self::mappingErrors($mapping, $columnCount);
        if ($errors) return $errors;
        $seen = [];
        $validIds = 0;
        foreach ($rows as $index=>$row) {
            $number = $index + 2;
            $data = self::row($row, $mapping);
            $so = trim((string)($data['so_number'] ?? ''));
            if ($so === '') continue; // Existing workflow deliberately skips missing identifiers.
            $validIds++;
            $key = function_exists('mb_strtolower') ? mb_strtolower($so, 'UTF-8') : strtolower($so);
            if (isset($seen[$key])) $errors[] = ['row'=>$number, 'message'=>'Dubbel SO-number; komt ook voor op rij ' . $seen[$key] . '. Verwijder de dubbele rij.'];
            else $seen[$key] = $number;
            if (strlen($so) > 100) $errors[] = ['row'=>$number, 'message'=>'SO-number is te lang (maximaal 100 tekens).'];
            $dates = [];
            foreach (['lease_start_date'=>'Startdatum', 'lease_end_date'=>'Einddatum', 'yearly_maintenance_end_date'=>'Einde onderhoud'] as $field=>$label) {
                try { $dates[$field] = self::date($data[$field] ?? null); }
                catch (InvalidArgumentException $e) { $errors[] = ['row'=>$number, 'message'=>$label . ': ' . $e->getMessage()]; }
            }
            if (!empty($dates['lease_start_date']) && !empty($dates['lease_end_date']) && $dates['lease_start_date'] > $dates['lease_end_date']) $errors[] = ['row'=>$number, 'message'=>'De einddatum ligt vóór de startdatum.'];
            try { self::money($data['maintenance_budget'] ?? null); }
            catch (InvalidArgumentException $e) { $errors[] = ['row'=>$number, 'message'=>'Onderhoudsbudget: ' . $e->getMessage()]; }
        }
        if (!$validIds) $errors[] = ['row'=>null, 'message'=>'Het bestand bevat geen gegevensrijen met een SO-number.'];
        return $errors;
    }

    public static function reject(array $errors, string $filename): never
    {
        http_response_code(422);
        $e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><title>Import controleren</title><link rel="stylesheet" href="assets.css"></head><body><main><section class="card"><h1>Import niet uitgevoerd</h1><p>Controleer het bestand en de kolomkoppeling.</p><ul>';
        foreach (array_slice($errors, 0, 20) as $error) echo '<li>' . ($error['row'] !== null ? 'Rij ' . (int)$error['row'] . ': ' : '') . $e($error['message']) . '</li>';
        if (count($errors) > 20) echo '<li>En nog ' . (count($errors) - 20) . ' melding(en).</li>';
        echo '</ul><form method="post" action="import-preview.php">' . Auth::csrfField() . '<input type="hidden" name="uploaded_file" value="' . $e($filename) . '"><button type="submit">Terug naar kolomkoppeling</button></form><p><a href="upload.php">Ander bestand uploaden</a></p></section></main></body></html>';
        exit;
    }
}
