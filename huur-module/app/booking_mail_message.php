<?php

declare(strict_types=1);

/** Escape an RFC 5545 TEXT value without allowing injected content lines. */
function booking_mail_calendar_text(string $value): string
{
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    if ($value === null) throw new DomainException('De boekingsgegevens bevatten ongeldige tekst.');
    return str_replace(["\\", "\r\n", "\r", "\n", ';', ','], ["\\\\", '\\n', '\\n', '\\n', '\\;', '\\,'], $value);
}

/** RFC 5545 lines are at most 75 octets; never split a UTF-8 character. */
function booking_mail_calendar_line(string $line): string
{
    $characters = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
    if ($characters === false) throw new DomainException('De agenda bevat ongeldige tekst.');
    $result = '';
    $length = 0;
    foreach ($characters as $character) {
        $bytes = strlen($character);
        if ($length + $bytes > 75) {
            $result .= "\r\n ";
            $length = 1;
        }
        $result .= $character;
        $length += $bytes;
    }
    return $result;
}

/** @return array{address:string,name:string} */
function booking_mail_sender(): array
{
    $transport = strtolower(trim((string) env('MAIL_TRANSPORT', 'log')));
    $address = trim((string) env('MAIL_FROM_ADDRESS', env('COMPANY_EMAIL', 'info@aertsactionbike.be')));
    if ($transport === 'graph') $address = trim((string) env('GRAPH_FROM_ADDRESS', $address));
    $name = (string) env('MAIL_FROM_NAME', env('COMPANY_NAME', 'Aerts Action Bike'));
    if (!filter_var($address, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n\x00]/', $address . $name)) {
        throw new DomainException('Het afzenderadres of de afzendernaam van de boekingsmail is ongeldig.');
    }
    return ['address' => $address, 'name' => $name];
}

/** Build the customer mail and personal add-to-calendar attachment; never sends. */
function booking_mail_message(array $reservation, ?DateTimeImmutable $now = null): array
{
    $kind = (string) ($reservation['rental_kind'] ?? '');
    if (!in_array($kind, ['rental', 'test'], true)) {
        throw new DomainException('Een boekingsmail is alleen mogelijk voor Huur en Test.');
    }
    $id = (int) ($reservation['id'] ?? 0);
    $to = trim((string) ($reservation['customer_email'] ?? ''));
    $name = trim((string) ($reservation['customer_name'] ?? ''));
    if ($id < 1 || !filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n\x00]/', $to . $name)) {
        throw new DomainException('Vul eerst een geldig e-mailadres en correcte klantgegevens in.');
    }
    $timezone = new DateTimeZone('Europe/Brussels');
    $parse = static function (string $value) use ($timezone): DateTimeImmutable {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
        if (!$parsed || $parsed->format('Y-m-d H:i:s') !== $value) {
            throw new DomainException('De boekingsperiode is ongeldig.');
        }
        return $parsed;
    };
    $start = $parse((string) ($reservation['start_at'] ?? ''));
    $end = $parse((string) ($reservation['end_at'] ?? ''));
    if ($end <= $start) throw new DomainException('De boekingsperiode is ongeldig.');
    $bikes = $reservation['bikes'] ?? [];
    if (!is_array($bikes) || !$bikes || count($bikes) > 25) {
        throw new DomainException('Selecteer eerst de fiets(en) voor deze boeking.');
    }
    $bikeNames = [];
    foreach ($bikes as $bike) {
        if (!is_array($bike)) throw new DomainException('De fietsgegevens zijn ongeldig.');
        $bikeName = trim((string) ($bike['name'] ?? ''));
        $code = trim((string) ($bike['code'] ?? ''));
        if ($bikeName === '' && $code === '') throw new DomainException('De fietsgegevens zijn onvolledig.');
        $bikeNames[] = trim($code . ($code !== '' && $bikeName !== '' ? ' — ' : '') . $bikeName);
    }
    $company = (string) env('COMPANY_NAME', 'Aerts Action Bike');
    $address = (string) env('COMPANY_ADDRESS', 'Kapellensteenweg 394, 2920 Kalmthout');
    $sender = booking_mail_sender();
    $label = $kind === 'test' ? 'testrit' : 'fietsverhuur';
    $subject = 'Uw ' . $label . ' bij ' . $company . ' · ' . $start->format('d/m/Y H:i');
    if (preg_match('/[\r\n\x00]/', $subject)) throw new DomainException('De bedrijfsnaam voor de boekingsmail is ongeldig.');
    $startLabel = $start->format('d/m/Y \o\m H:i');
    $endLabel = $end->format('d/m/Y \o\m H:i');
    $agendaExplanation = 'Open de bijlage booking-' . $id . '.ics en voeg de afspraak toe aan uw agenda. De afspraak bevat een herinnering één uur vóór het afhalen. Uw agenda-app bepaalt of en hoe deze melding wordt getoond.';
    $plain = ($name !== '' ? 'Beste ' . $name . ',' : 'Beste,') . "\n\n"
        . 'Hierbij bevestigen we uw boeking voor ' . $label . ' bij ' . $company . ".\n\n"
        . 'Afhalen: ' . $startLabel . "\nRetour: " . $endLabel . "\nTijden: Belgische tijd (Europe/Brussels).\n"
        . 'Locatie: ' . $address . "\nFiets(en):\n- " . implode("\n- ", $bikeNames) . "\n\n"
        . $agendaExplanation . "\n\nDit is een agenda-item voor uw eigen agenda; u hoeft geen uitnodiging te accepteren of te beantwoorden. Bij wijzigingen neemt u contact op met de winkel.\n\n"
        . "Tot binnenkort!\n" . $company . "\n" . $address . "\n" . $sender['address'];
    $h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<!doctype html><html lang="nl-BE"><head><meta charset="UTF-8"></head><body style="margin:0;background:#f0f4ef;color:#17251b;font-family:Arial,Helvetica,sans-serif">'
        . '<div style="max-width:640px;margin:auto;padding:28px 18px"><div style="background:#17251b;padding:24px;color:white;border-radius:14px 14px 0 0">'
        . '<strong style="font-size:22px">' . $h($company) . '</strong><p style="margin:8px 0 0">Uw ' . $h($label) . ' staat ingepland</p></div>'
        . '<div style="background:white;padding:26px;border-radius:0 0 14px 14px"><p>' . ($name !== '' ? 'Beste ' . $h($name) . ',' : 'Beste,') . '</p>'
        . '<p>Hierbij bevestigen we uw boeking. We kijken ernaar uit u te verwelkomen.</p>'
        . '<table role="presentation" style="width:100%;border-collapse:collapse"><tr><td style="padding:10px 0"><strong>Afhalen</strong></td><td style="padding:10px 0">' . $h($startLabel) . '</td></tr>'
        . '<tr><td style="padding:10px 0"><strong>Retour</strong></td><td style="padding:10px 0">' . $h($endLabel) . '</td></tr>'
        . '<tr><td style="padding:10px 0;vertical-align:top"><strong>Locatie</strong></td><td style="padding:10px 0">' . $h($address) . '</td></tr></table>'
        . '<p style="font-size:13px;color:#526051">Alle tijden zijn Belgische tijd (Europe/Brussels).</p><p><strong>Uw fiets(en)</strong></p><ul><li>' . implode('</li><li>', array_map($h, $bikeNames)) . '</li></ul>'
        . '<div style="background:#edf6e8;padding:18px;border-radius:10px"><strong>Voeg uw afspraak toe aan uw agenda</strong><p>' . $h($agendaExplanation) . '</p>'
        . '<p style="margin-bottom:0;font-size:13px">Dit is een agenda-item voor uw eigen agenda; u hoeft geen uitnodiging te accepteren of te beantwoorden.</p></div>'
        . '<p>Wilt u iets wijzigen? Neem dan contact op met de winkel via <a href="mailto:' . $h($sender['address']) . '">' . $h($sender['address']) . '</a>.</p>'
        . '<p>Tot binnenkort!<br><strong>' . $h($company) . '</strong></p></div></div></body></html>';
    $summary = ($kind === 'test' ? 'Testrit' : 'Fietsverhuur') . ' bij ' . $company;
    $description = 'Afhalen: ' . $startLabel . "\nRetour: " . $endLabel . "\nFiets(en): " . implode('; ', $bikeNames)
        . "\nNeem bij wijzigingen contact op met de winkel: " . $sender['address'];
    $utc = new DateTimeZone('UTC');
    $lines = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Aerts Action Bike//Verhuurplanning//NL', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'BEGIN:VEVENT', 'UID:booking-' . $id . '@aertsactionbike.cc',
        'DTSTAMP:' . ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc)->format('Ymd\THis\Z'),
        'DTSTART:' . $start->setTimezone($utc)->format('Ymd\THis\Z'),
        'DTEND:' . $end->setTimezone($utc)->format('Ymd\THis\Z'),
        'SUMMARY:' . booking_mail_calendar_text($summary),
        'LOCATION:' . booking_mail_calendar_text($address),
        'DESCRIPTION:' . booking_mail_calendar_text($description),
        'ORGANIZER:mailto:' . $sender['address'], 'SEQUENCE:0', 'CLASS:PRIVATE',
        'STATUS:' . (($reservation['status'] ?? '') === 'reserved' ? 'TENTATIVE' : 'CONFIRMED'),
        'TRANSP:OPAQUE', 'BEGIN:VALARM', 'ACTION:DISPLAY', 'TRIGGER:-PT1H',
        'DESCRIPTION:' . booking_mail_calendar_text('Herinnering: ' . $summary),
        'END:VALARM', 'END:VEVENT', 'END:VCALENDAR',
    ];
    return [
        'to' => $to, 'to_name' => $name, 'subject' => $subject, 'html' => $html, 'plain' => $plain,
        'calendar' => implode("\r\n", array_map('booking_mail_calendar_line', $lines)) . "\r\n",
        'filename' => 'booking-' . $id . '.ics',
    ];
}

/** Fail before a delivery claim/network request if the transport cannot send. */
function booking_mail_transport_ready(): void
{
    $transport = strtolower(trim((string) env('MAIL_TRANSPORT', 'log')));
    if (!in_array($transport, ['smtp', 'graph', 'mail', 'log'], true)) {
        throw new DomainException('Het mailtransport voor de boekingsmail is niet ingesteld.');
    }
    booking_mail_sender();
    if ($transport === 'smtp') {
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) throw new DomainException('PHPMailer ontbreekt. Installeer de mailafhankelijkheden.');
        $host = trim((string) env('MAIL_HOST', ''));
        $port = filter_var(env('MAIL_PORT', '587'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($host === '' || preg_match('/[\r\n\x00]/', $host) || $port === false) throw new DomainException('De SMTP-server of poort is ongeldig.');
        if (!in_array(strtolower((string) env('MAIL_ENCRYPTION', 'tls')), ['tls', 'ssl', 'smtps'], true)) {
            throw new DomainException('Gebruik TLS of SSL voor boekingsmails.');
        }
    }
    if ($transport === 'graph') {
        foreach (['GRAPH_TENANT_ID', 'GRAPH_CLIENT_ID', 'GRAPH_CLIENT_SECRET'] as $key) {
            if (trim((string) env($key, '')) === '') throw new DomainException('Mailinstelling ontbreekt: ' . $key . '.');
        }
        if (!function_exists('graph_access_token') || !function_exists('graph_http_request')) throw new DomainException('De Microsoft Graph-mailfunctie ontbreekt.');
    }
    if ($transport === 'mail' && !function_exists('mail')) throw new DomainException('PHP mail() is niet beschikbaar.');
}

/** Build a MIME message for PHP mail() and private test logging. */
function booking_mail_mime(array $message, array $sender): array
{
    $mixed = 'aab-booking-' . bin2hex(random_bytes(18));
    $alternative = 'aab-alternative-' . bin2hex(random_bytes(18));
    $encodedName = '=?UTF-8?B?' . base64_encode($sender['name']) . '?=';
    $headers = [
        'MIME-Version: 1.0', 'From: ' . $encodedName . ' <' . $sender['address'] . '>',
        'Reply-To: ' . $sender['address'], 'Content-Type: multipart/mixed; boundary="' . $mixed . '"',
    ];
    $body = '--' . $mixed . "\r\nContent-Type: multipart/alternative; boundary=\"" . $alternative . "\"\r\n\r\n";
    foreach (['plain' => 'text/plain', 'html' => 'text/html'] as $key => $type) {
        $body .= '--' . $alternative . "\r\nContent-Type: " . $type . "; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode((string) $message[$key]), 76, "\r\n");
    }
    $body .= '--' . $alternative . "--\r\n\r\n--" . $mixed . "\r\nContent-Type: text/calendar; charset=UTF-8; method=PUBLISH; name=\"" . $message['filename']
        . "\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . $message['filename'] . "\"\r\n\r\n"
        . chunk_split(base64_encode((string) $message['calendar']), 76, "\r\n") . '--' . $mixed . "--\r\n";
    return ['headers' => implode("\r\n", $headers), 'body' => $body];
}

/** A network failure is uncertain: never silently retry a possibly accepted mail. */
function send_booking_mail_message(array $message): array
{
    $attempted = false;
    try {
        booking_mail_transport_ready();
        if (!filter_var($message['to'] ?? '', FILTER_VALIDATE_EMAIL)
            || preg_match('/[\r\n\x00]/', (string) ($message['to'] ?? '') . (string) ($message['subject'] ?? '') . (string) ($message['to_name'] ?? ''))
            || !preg_match('/\Abooking-[1-9][0-9]*\.ics\z/', (string) ($message['filename'] ?? ''))
            || !is_string($message['calendar'] ?? null) || !is_string($message['html'] ?? null) || !is_string($message['plain'] ?? null)) {
            throw new DomainException('De boekingsmail bevat ongeldige gegevens.');
        }
        $transport = strtolower(trim((string) env('MAIL_TRANSPORT', 'log')));
        $sender = booking_mail_sender();
        if ($transport === 'log' || $transport === 'mail') {
            $mime = booking_mail_mime($message, $sender);
            if ($transport === 'log') {
                $dir = ROOT_PATH . '/storage/private/mail';
                if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) throw new RuntimeException('De testmailmap kon niet worden aangemaakt.');
                $path = $dir . '/' . basename($message['filename'], '.ics') . '-' . bin2hex(random_bytes(8)) . '.eml';
                $contents = 'To: ' . $message['to'] . "\r\nSubject: =?UTF-8?B?" . base64_encode($message['subject']) . "?=\r\n" . $mime['headers'] . "\r\n\r\n" . $mime['body'];
                if (file_put_contents($path, $contents, LOCK_EX) === false) throw new RuntimeException('De testmail kon niet worden opgeslagen.');
                @chmod($path, 0640);
                return ['status' => 'logged', 'error' => 'Testmodus: de boekingsmail is lokaal opgeslagen en niet naar de klant verstuurd.'];
            }
            $attempted = true;
            if (!mail($message['to'], '=?UTF-8?B?' . base64_encode($message['subject']) . '?=', $mime['body'], $mime['headers'])) {
                return ['status' => 'uncertain', 'error' => 'De mailserver heeft de boekingsmail niet bevestigd. Controleer de mailprovider vóór opnieuw versturen.'];
            }
            return ['status' => 'sent', 'error' => null];
        }
        if ($transport === 'graph') {
            $token = graph_access_token((string) env('GRAPH_TENANT_ID', ''), (string) env('GRAPH_CLIENT_ID', ''), (string) env('GRAPH_CLIENT_SECRET', ''));
            $payload = ['message' => [
                'subject' => $message['subject'], 'body' => ['contentType' => 'HTML', 'content' => $message['html']],
                'toRecipients' => [['emailAddress' => ['address' => $message['to'], 'name' => (string) ($message['to_name'] ?? '')]]],
                'replyTo' => [['emailAddress' => ['address' => $sender['address'], 'name' => $sender['name']]]],
                'attachments' => [['@odata.type' => '#microsoft.graph.fileAttachment', 'name' => $message['filename'], 'contentType' => 'text/calendar', 'contentBytes' => base64_encode($message['calendar'])]],
            ], 'saveToSentItems' => true];
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $attempted = true;
            [$status] = graph_http_request('POST', 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($sender['address']) . '/sendMail',
                ['Authorization: Bearer ' . $token, 'Content-Type: application/json'], $body);
            return $status === 202
                ? ['status' => 'sent', 'error' => null]
                : ['status' => 'uncertain', 'error' => 'Microsoft Graph heeft de boekingsmail niet bevestigd (HTTP ' . $status . '). Controleer de mailprovider vóór opnieuw versturen.'];
        }
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 30;
        $mail->Host = (string) env('MAIL_HOST', '');
        $mail->Port = (int) env('MAIL_PORT', '587');
        $mail->SMTPAuth = env('MAIL_USERNAME', '') !== '';
        $mail->Username = (string) env('MAIL_USERNAME', '');
        $mail->Password = (string) env('MAIL_PASSWORD', '');
        $mail->SMTPSecure = strtolower((string) env('MAIL_ENCRYPTION', 'tls')) === 'tls' ? 'tls' : 'ssl';
        $mail->setFrom($sender['address'], $sender['name']);
        $mail->addReplyTo($sender['address'], $sender['name']);
        $mail->addAddress($message['to'], (string) ($message['to_name'] ?? ''));
        $mail->Subject = $message['subject'];
        $mail->isHTML(true);
        $mail->Body = $message['html'];
        $mail->AltBody = $message['plain'];
        $mail->addStringAttachment($message['calendar'], $message['filename'], 'base64', 'text/calendar; charset=UTF-8; method=PUBLISH', 'attachment');
        $attempted = true;
        $mail->send();
        return ['status' => 'sent', 'error' => null];
    } catch (Throwable $error) {
        // Provider errors can include credentials, addresses or response bodies; never surface them.
        if ($attempted) return ['status' => 'uncertain', 'error' => 'De verzending is niet bevestigd. Controleer de mailprovider vóór opnieuw versturen.'];
        return ['status' => 'failed', 'error' => $error instanceof DomainException ? $error->getMessage() : 'De boekingsmail kon niet worden voorbereid. Controleer de mailinstellingen.'];
    }
}
