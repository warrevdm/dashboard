<?php

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function buildMailHtml(string $name, string $bikeType, string $pickupNote = ''): string
{
    $safeName = e($name);
    $safeBikeType = e($bikeType);
    $safeNote = nl2br(e($pickupNote));
    $noteBlock = $pickupNote !== ''
        ? '<tr><td style="padding:0 32px 24px;"><div style="padding:16px 18px;background:#f4f7f2;border-left:4px solid #60bb46;border-radius:8px;color:#243229;font-size:15px;line-height:1.6;"><strong>Extra informatie</strong><br>' . $safeNote . '</div></td></tr>'
        : '';

    $bookingUrl = e(BOOKING_URL);

    return <<<HTML
<!doctype html>
<html lang="nl-BE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Je nieuwe fiets staat klaar</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f2;font-family:Arial,Helvetica,sans-serif;color:#172019;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f3f4f2;padding:24px 12px;">
<tr>
<td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:640px;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 8px 24px rgba(20,35,24,.08);">
    <tr>
        <td align="center" style="background:#ffffff;padding:20px 32px 18px;">
            <img src="cid:aab-logo" width="270" alt="Aerts Action Bike" style="display:block;width:270px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;margin:0 auto;">
        </td>
    </tr>
    <tr>
        <td align="center" style="background:#172019;padding:24px 32px 26px;text-align:center;">
            <h1 style="margin:0;color:#ffffff;font-size:30px;line-height:1.2;text-align:center;">Je nieuwe fiets staat klaar</h1>
        </td>
    </tr>
    <tr>
        <td style="padding:32px 32px 18px;font-size:16px;line-height:1.7;color:#263229;">
            Dag {$safeName},
            <br><br>
            <strong>Proficiat met je nieuwe fiets!</strong>
            <br><br>
            Goed nieuws: je nieuwe <strong>{$safeBikeType}</strong> staat volledig klaar voor afhaling bij Aerts Action Bike.
            <br><br>
            Tijdens de afhaling nemen we de tijd om je nieuwe fiets samen te overlopen, correct af te stellen en alle belangrijke uitleg mee te geven.
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:8px 32px 30px;">
            <a href="{$bookingUrl}" target="_blank" style="display:inline-block;background:#60bb46;color:#102012;text-decoration:none;font-weight:700;font-size:16px;padding:15px 24px;border-radius:8px;">Plan de afhaling van je nieuwe fiets</a>
        </td>
    </tr>
    {$noteBlock}
    <tr>
        <td style="padding:0 32px 30px;font-size:15px;line-height:1.7;color:#455148;">
            Heb je nog een vraag? Antwoord gerust op deze mail of neem contact op met onze winkel.
            <br><br>
            Sportieve groeten,<br>
            <strong>Team Aerts Action Bike</strong>
        </td>
    </tr>
    <tr>
        <td style="background:#eef1ed;padding:22px 32px;font-size:13px;line-height:1.6;color:#5b665e;">
            Aerts Action Bike<br>
            Kapellensteenweg 394, 2920 Kalmthout<br>
            +32 (0)3 666 97 01 · www.aertsactionbike.be
        </td>
    </tr>
</table>
</td>
</tr>
</table>
</body>
</html>
HTML;
}

function buildMailText(string $name, string $bikeType, string $pickupNote = ''): string
{
    $text = "Dag {$name},\n\nProficiat met je nieuwe fiets!\n\nGoed nieuws: je nieuwe {$bikeType} staat volledig klaar voor afhaling bij Aerts Action Bike.\n\nTijdens de afhaling nemen we de tijd om je nieuwe fiets samen te overlopen, correct af te stellen en alle belangrijke uitleg mee te geven.\n\nPlan de afhaling van je nieuwe fiets via:\n" . BOOKING_URL . "\n";

    if ($pickupNote !== '') {
        $text .= "\nExtra informatie:\n{$pickupNote}\n";
    }

    return $text . "\nHeb je nog een vraag? Antwoord gerust op deze mail of neem contact op met onze winkel.\n\nSportieve groeten,\nTeam Aerts Action Bike\nKapellensteenweg 394, 2920 Kalmthout\n+32 (0)3 666 97 01";
}

function buildCollectGoHtml(string $name, string $product, string $pickupNote = ''): string
{
    $safeName = e($name);
    $safeProduct = e($product);
    $safeNote = nl2br(e($pickupNote));
    $noteBlock = $pickupNote !== ''
        ? '<tr><td style="padding:0 32px 24px;"><div style="padding:16px 18px;background:#f4f7f2;border-left:4px solid #60bb46;border-radius:8px;color:#243229;font-size:15px;line-height:1.6;"><strong>Extra informatie</strong><br>' . $safeNote . '</div></td></tr>'
        : '';

    return <<<HTML
<!doctype html>
<html lang="nl-BE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Je bestelling staat klaar voor afhaling</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f2;font-family:Arial,Helvetica,sans-serif;color:#172019;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f3f4f2;padding:24px 12px;">
<tr>
<td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:640px;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 8px 24px rgba(20,35,24,.08);">
    <tr>
        <td align="center" style="background:#ffffff;padding:20px 32px 18px;">
            <img src="cid:aab-logo" width="270" alt="Aerts Action Bike" style="display:block;width:270px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;margin:0 auto;">
        </td>
    </tr>
    <tr>
        <td align="center" style="background:#172019;padding:24px 32px 26px;text-align:center;">
            <h1 style="margin:0;color:#ffffff;font-size:30px;line-height:1.2;text-align:center;">Je bestelling staat klaar</h1>
        </td>
    </tr>
    <tr>
        <td style="padding:32px 32px 20px;font-size:16px;line-height:1.7;color:#263229;">
            Dag {$safeName},
            <br><br>
            Goed nieuws: je bestelling <strong>{$safeProduct}</strong> staat klaar voor afhaling bij Aerts Action Bike.
            <br><br>
            Je hoeft hiervoor geen afspraak te maken. Kom gerust langs tijdens onze openingsuren en ons team helpt je verder.
        </td>
    </tr>
    <tr>
        <td style="padding:0 32px 28px;">
            <div style="padding:18px 20px;background:#f4f7f2;border-radius:10px;color:#243229;font-size:15px;line-height:1.7;">
                <strong>Openingsuren</strong><br>
                Dinsdag t.e.m. vrijdag: 09:00–12:30 &amp; 13:30–18:00<br>
                Zaterdag: 09:00–17:00<br>
                Zondag &amp; maandag: gesloten
            </div>
        </td>
    </tr>
    {$noteBlock}
    <tr>
        <td style="padding:0 32px 30px;font-size:15px;line-height:1.7;color:#455148;">
            Heb je nog een vraag? Antwoord gerust op deze mail of neem contact op met onze winkel.
            <br><br>
            Tot snel!<br>
            <strong>Team Aerts Action Bike</strong>
        </td>
    </tr>
    <tr>
        <td style="background:#eef1ed;padding:22px 32px;font-size:13px;line-height:1.6;color:#5b665e;">
            Aerts Action Bike<br>
            Kapellensteenweg 394, 2920 Kalmthout<br>
            +32 (0)3 666 97 01 · www.aertsactionbike.be
        </td>
    </tr>
</table>
</td>
</tr>
</table>
</body>
</html>
HTML;
}

function buildCollectGoText(string $name, string $product, string $pickupNote = ''): string
{
    $text = "Dag {$name},\n\nGoed nieuws: je bestelling {$product} staat klaar voor afhaling bij Aerts Action Bike.\n\nJe hoeft hiervoor geen afspraak te maken. Kom gerust langs tijdens onze openingsuren en ons team helpt je verder.\n\nOpeningsuren:\nDinsdag t.e.m. vrijdag: 09:00–12:30 & 13:30–18:00\nZaterdag: 09:00–17:00\nZondag & maandag: gesloten\n";

    if ($pickupNote !== '') {
        $text .= "\nExtra informatie:\n{$pickupNote}\n";
    }

    return $text . "\nHeb je nog een vraag? Antwoord gerust op deze mail of neem contact op met onze winkel.\n\nTot snel!\nTeam Aerts Action Bike\nKapellensteenweg 394, 2920 Kalmthout\n+32 (0)3 666 97 01";
}
