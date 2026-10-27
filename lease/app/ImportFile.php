<?php

declare(strict_types=1);

/** Keep CSV identifiers and text untouched; spreadsheets use the existing reader. */
final class ImportFile
{
    public static function rows(string $path): array
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'csv') {
            require_once __DIR__ . '/../vendor/autoload.php';
            $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet()->toArray();
            if (count($rows) < 2) throw new RuntimeException('Het bestand bevat geen gegevensrijen.');
            return $rows;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('Bestand kon niet geopend worden.');
        try {
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") rewind($handle);
            $offset = ftell($handle);
            $delimiter = ';';
            $largest = 0;
            foreach ([';', ',', "\t"] as $candidate) {
                fseek($handle, $offset);
                $header = fgetcsv($handle, 0, $candidate, '"', '');
                if (is_array($header) && count($header) > $largest) {
                    $delimiter = $candidate;
                    $largest = count($header);
                }
            }
            fseek($handle, $offset);
            $rows = [];
            while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                if (count(array_filter($row, static fn($value): bool => trim((string)$value) !== '')) === 0) continue;
                if ($rows && count($row) !== count($rows[0])) throw new RuntimeException('CSV bevat een rij met een afwijkend aantal kolommen. Controleer het bestand.');
                $rows[] = $row;
            }
            if (count($rows) < 2) throw new RuntimeException('Het bestand is leeg of bevat geen gegevensrijen.');
            return $rows;
        } finally { fclose($handle); }
    }
}
