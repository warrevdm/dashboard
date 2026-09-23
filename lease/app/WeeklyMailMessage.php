<?php

require_once __DIR__ . '/ExpiringContracts.php';
require_once __DIR__ . '/WeeklyMailConfig.php';

final class WeeklyMailMessage
{
    public static function build(array $orders, DateTimeImmutable $now, string $baseUrl): array
    {
        if (!WeeklyMailConfig::validBaseUrl($baseUrl)) {
            throw new InvalidArgumentException('De link naar het leaseprogramma is ongeldig.');
        }
        [$start, $end] = ExpiringContracts::window($now);
        $baseUrl = rtrim($baseUrl, '/');
        $h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $count = count($orders);
        $subject = 'Aerts Lease | ' . $count . ' contract' . ($count === 1 ? '' : 'en') . ' zonder logboek | ' . $start->format('d/m/Y');
        $period = $start->format('d/m/Y') . ' t.e.m. ' . $end->format('d/m/Y');
        $intro = $count === 0
            ? 'Er zijn geen aflopende contracten zonder logboekactie in deze periode.'
            : 'Deze contracten lopen binnen drie maanden af en hebben nog geen enkele logboekactie.';
        $plain = "Aerts Action Bike — wekelijkse leaseopvolging\nPeriode: $period\n\n$intro\n\n";
        $rows = '';
        foreach ($orders as $order) {
            $expiry = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $order['lease_end_date'], $start->getTimezone());
            $date = $expiry ? $expiry->format('d/m/Y') : (string) $order['lease_end_date'];
            $days = $expiry ? (int) $start->diff($expiry)->format('%r%a') : null;
            $remaining = $days === 0 ? 'Vandaag' : ($days === 1 ? 'Nog 1 dag' : 'Nog ' . $days . ' dagen');
            $link = $baseUrl . '/contract-detail.php?id=' . (int) $order['id'];
            $budget = isset($order['maintenance_budget']) && $order['maintenance_budget'] !== ''
                ? '€ ' . number_format((float) $order['maintenance_budget'], 2, ',', '.') : 'Onbekend';
            $cell = 'style="padding:12px 10px;border-bottom:1px solid #e5e7eb;vertical-align:top;text-align:left"';
            $rows .= '<tr><td ' . $cell . '><strong>' . $h($order['customer_name'] ?: 'Naam ontbreekt') . '</strong><br>'
                . '<a style="color:#357c29" href="' . $h($link) . '">' . $h($order['so_number']) . '</a></td>'
                . '<td ' . $cell . '>' . $h($order['lease_partner'] ?: 'Onbekend') . '<br>' . $h($order['bike_name'] ?? '') . '</td>'
                . '<td ' . $cell . '><strong>' . $h($date) . '</strong><br>' . $h($remaining) . '</td>'
                . '<td ' . $cell . '>' . $h($budget) . '</td></tr>';
            $plain .= ($order['customer_name'] ?: 'Naam ontbreekt') . ' | ' . $order['so_number'] . "\n"
                . ($order['lease_partner'] ?? '') . ' | ' . ($order['bike_name'] ?? '') . "\n"
                . "Einddatum: $date ($remaining) | Onderhoudsbudget: $budget\nDossier: $link\n\n";
        }
        $overview = $baseUrl . '/expiring-contracts.php?without_logbook=1';
        $footer = 'Een interne overzichtsmail telt niet als klantcontact. Voeg na je opvolging een logboekactie toe in het dossier. '
            . 'Tot dan blijft het contract in de volgende weekmail staan zolang het binnen de selectieperiode valt.';
        $plain .= "Overzicht: $overview\n\n$footer\nDe budgetten zijn de laatst geïmporteerde bedragen.\n";
        $html = '<!doctype html><html lang="nl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>@media(max-width:600px){.contract-table thead{display:none}.contract-table tr{display:block;border-bottom:2px solid #dce7da;margin-bottom:12px}.contract-table td{display:block!important;border:0!important;padding:6px 0!important}}</style></head>'
            . '<body style="margin:0;background:#f3f5f3;color:#17211a;font-family:Arial,Helvetica,sans-serif">'
            . '<div style="max-width:760px;margin:0 auto;padding:24px 12px"><div style="background:#17211a;color:white;padding:24px;border-top:5px solid #60bb46">'
            . '<strong style="font-size:22px">Aerts Action Bike</strong><div style="margin-top:8px">Wekelijkse leaseopvolging</div></div>'
            . '<div style="background:white;padding:24px"><h1 style="font-size:23px;margin-top:0">' . $count . ' contract' . ($count === 1 ? '' : 'en') . ' zonder logboek</h1>'
            . '<p>' . $h($intro) . '</p><p style="color:#59625b">Periode: ' . $h($period) . '</p>'
            . ($count ? '<table class="contract-table" style="width:100%;border-collapse:collapse;font-size:14px"><thead><tr style="background:#edf5ea">'
                . '<th style="text-align:left;padding:10px">Klant / contract</th><th style="text-align:left;padding:10px">Partner / fiets</th>'
                . '<th style="text-align:left;padding:10px">Einde contract</th><th style="text-align:left;padding:10px">Budget</th></tr></thead><tbody>' . $rows . '</tbody></table>' : '')
            . '<p style="margin:28px 0"><a href="' . $h($overview) . '" style="display:inline-block;background:#60bb46;color:#13200e;text-decoration:none;padding:13px 18px;font-weight:bold">Open de opvolglijst</a></p>'
            . '<p style="font-size:13px;color:#59625b">' . $h($footer) . '</p><p style="font-size:12px;color:#59625b">'
            . 'Intern overzicht. Budgetten zijn de laatst geïmporteerde bedragen. Dossierlinks vereisen aanmelding.</p></div></div></body></html>';
        return ['subject' => $subject, 'html' => $html, 'text' => $plain, 'count' => $count];
    }
}
