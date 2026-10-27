<?php

declare(strict_types=1);

/** CSV escaping is not sufficient to prevent spreadsheet formula execution. */
final class CsvExport
{
    public static function cell($value, bool $numeric = false): string
    {
        if ($value === null) return '';
        $text = (string) $value;
        // Only database budget columns use this opt-in; text such as phone
        // numbers and frame numbers must retain their literal meaning.
        if ($numeric && is_numeric($text)) return $text;
        if (preg_match('/^[\x00-\x20\x{FEFF}]*[=+\-@]/u', $text)
            || preg_match('/^[\x00-\x20]*[\t\r\n]/', $text)) {
            return "'" . $text;
        }
        return $text;
    }
}
