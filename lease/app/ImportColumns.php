<?php

declare(strict_types=1);

/** Shared header recognition for lease import previews. */
final class ImportColumns
{
    private const SYNONYMS = [
        'so_number' => [
            'so number',
            'so-number',
            'so_number',
            'sonumber',
            'sales order',
            'sales order #',
            'salesorder',
            'order number',
            'ordernumber',
            'bestelnummer',
            'bestel nr',
            'bestelnr',
            'bestelcode',
            'contract id',
            'contract-id',
            'contract_id',
            'contractnummer',
            'contract number',
            'order nr',
            'ordernr',
        ],
        'customer_name' => [
            'naam',
            'name',
            'customer',
            'customer name',
            'klant',
            'klantnaam',
            'gebruiker',
            'voornaam naam',
            'full name',
            'fullname',
        ],
        'email' => [
            'email',
            'e-mail',
            'mail',
            'email address',
            'e-mailadres',
            'emailadres',
        ],
        'phone' => [
            'telefoon',
            'telefoonnummer',
            'phone',
            'mobile',
            'gsm',
            'phone number',
            'tel',
        ],
        'company' => [
            'bedrijf',
            'company',
            'employer',
            'werkgever',
            'onderneming',
            'firma',
        ],
        'lease_partner' => [
            'lease maatschap',
            'leasemaatschappij',
            'leasepartner',
            'lease partner',
            'leasingpartner',
            'leasing partner',
            'partner',
            'leasing',
        ],
        'order_status' => [
            'status',
            'order status',
            'orderstatus',
            'bestelstatus',
            'fiets status',
            'bike status',
            'delivery status',
            'leverstatus',
            'levering status',
        ],
    
        'bike_type' => [
            'soort fiets',
            'type fiets',
            'bike type',
            'fietstype',
            'categorie',
            'category',
        ],
        'bike_name' => [
            'fietsnaam',
            'fiets',
            'bike',
            'bike name',
            'model',
            'product',
            'artikel',
            'description',
            'omschrijving',
        ],
        'frame_number' => [
            'framenummer',
            'frame number',
            'frame',
            'frame no',
            'frame nr',
            'serienummer',
            'serial',
            'serial number',
        ],
        'lease_start_date' => [
            'pickup date',
            'afhaaldatum',
            'startdatum',
            'start date',
            'lease start',
            'leasing start',
            'startdatum leasing',
            'startdatum leasingcontract',
            'startdatum leasecontract',
            'contract start',
            'begindatum',
        ],
        'lease_end_date' => [
            'einddatum',
            'end date',
            'lease end',
            'leasing end',
            'einddatum leasing',
            'einddatum leasingcontract',
            'einddatum leasecontract',
            'contract end',
            'contract einde',
            'einde contract',
        ],
        'maintenance_budget' => [
            'available maintenance budget',
            'budget',
            'onderhoudsbudget',
            'beschikbaar budget',
            'beschikbaar onderhoudsbudget',
            'maintenance budget',
            'service budget',
            'available budget',
        ],
        'yearly_maintenance_end_date' => [
            'einde jaarlijks onderhoudscontract',
            'onderhoud einddatum',
            'einddatum onderhoud',
            'maintenance end',
            'service end',
            'yearly maintenance end',
            'einde onderhoud',
        ],
    ];

    private static function normalize($value): string
    {
        $value = str_replace("\xEF\xBB\xBF", '', (string) $value);
        $value = strtolower(trim($value));
        $value = str_replace(['_', '-', '.', ':', ';', '/', '\\', '(', ')', '[', ']'], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        return trim($value);
    }

    public static function guess(string $field, array $headers): ?int
    {
        $synonyms = self::SYNONYMS[$field] ?? [];

        foreach ($headers as $index => $header) {
            $normalizedHeader = self::normalize($header);
            foreach ($synonyms as $synonym) {
                if ($normalizedHeader === self::normalize($synonym)) {
                    return (int) $index;
                }
            }
        }

        foreach ($headers as $index => $header) {
            $normalizedHeader = self::normalize($header);
            foreach ($synonyms as $synonym) {
                $normalizedSynonym = self::normalize($synonym);
                if ($normalizedSynonym !== '' && str_contains($normalizedHeader, $normalizedSynonym)) {
                    return (int) $index;
                }
            }
        }

        return null;
    }
}
