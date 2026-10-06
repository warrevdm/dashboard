<?php

declare(strict_types=1);

function daily_rental_message(PDO $pdo, DateTimeImmutable $now): array
{
    $now = $now->setTimezone(new DateTimeZone('Europe/Brussels'));
    $rows = $pdo->query("SELECT r.id, r.start_at, r.end_at, r.rental_kind, c.name AS customer_name,
        b.code, b.name AS bike_name
        FROM reservations r JOIN customers c ON c.id=r.customer_id
        JOIN reservation_bikes rb ON rb.reservation_id=r.id JOIN bikes b ON b.id=rb.bike_id
        WHERE r.status='picked_up' ORDER BY r.end_at, r.id, b.code")->fetchAll(PDO::FETCH_ASSOC);
    $groups = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        if (!isset($groups[$id])) $groups[$id] = $row + ['bikes' => []];
        $groups[$id]['bikes'][] = $row['code'] . ' — ' . $row['bike_name'];
    }
    $subject = 'Verhuren onderweg · ' . $now->format('d/m/Y') . ' · ' . count($groups) . ' dossiers';
    $plain = $subject . "\nPeilmoment: " . $now->format('d/m/Y H:i') . " (Europe/Brussels)\n\n";
    $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<html lang="nl"><body style="background:#f2f6f0;color:#17251b;font-family:Arial,sans-serif;padding:24px"><div style="max-width:760px;margin:auto"><h1 style="color:#397c2a">Verhuren onderweg</h1><p>' . $h($now->format('d/m/Y H:i')) . ' · ' . count($groups) . ' dossiers · ' . count($rows) . ' fietsen</p>';
    if (!$groups) {
        $plain .= "Er zijn momenteel geen verhuren onderweg.\n";
        $html .= '<p>Er zijn momenteel geen verhuren onderweg.</p>';
    }
    foreach ($groups as $row) {
        $start = new DateTimeImmutable($row['start_at'], new DateTimeZone('Europe/Brussels'));
        $end = new DateTimeImmutable($row['end_at'], new DateTimeZone('Europe/Brussels'));
        $kind = ['rental'=>'Huur', 'test'=>'Test', 'replacement'=>'Vervang'][$row['rental_kind']] ?? 'Huur';
        $late = $end < $now ? ' · TE LAAT TERUG' : '';
        $url = 'https://aertsactionbike.cc/huur-module/reservation.php?id=' . (int) $row['id'];
        $title = '#' . $row['id'] . ' · ' . $row['customer_name'] . ' · ' . $kind . $late;
        $period = $start->format('d/m/Y H:i') . ' → ' . $end->format('d/m/Y H:i');
        $plain .= $title . "\n" . implode("\n", $row['bikes']) . "\n" . $period . "\n" . $url . "\n\n";
        $html .= '<div style="background:white;border-left:4px solid ' . ($late ? '#c34b30' : '#60bb46') . ';padding:18px;margin:14px 0"><h2 style="font-size:18px;margin:0 0 10px">' . $h($title) . '</h2><p>' . implode('<br>', array_map($h, $row['bikes'])) . '</p><p>' . $h($period) . '</p><a href="' . $h($url) . '">Dossier openen</a></div>';
    }
    return ['subject'=>$subject, 'html'=>$html . '</div></body></html>', 'plain'=>$plain];
}

function send_daily_rental_message(string $to, array $message): void
{
    $transport = strtolower((string) env('MAIL_TRANSPORT', 'log'));
    $from = (string) env('MAIL_FROM_ADDRESS', env('COMPANY_EMAIL', 'info@aertsactionbike.be'));
    if ($transport === 'graph') {
        $from = (string) env('GRAPH_FROM_ADDRESS', $from);
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Ongeldige Graph-afzender.');
        $token = graph_access_token((string) env('GRAPH_TENANT_ID', ''), (string) env('GRAPH_CLIENT_ID', ''), (string) env('GRAPH_CLIENT_SECRET', ''));
        [$status] = graph_http_request('POST', 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($from) . '/sendMail',
            ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            json_encode(['message'=>['subject'=>$message['subject'], 'body'=>['contentType'=>'HTML','content'=>$message['html']], 'toRecipients'=>[['emailAddress'=>['address'=>$to]]]], 'saveToSentItems'=>true], JSON_THROW_ON_ERROR));
        if ($status !== 202) throw new RuntimeException('Graph-mail niet bevestigd (HTTP ' . $status . ').');
        return;
    }
    if ($transport !== 'smtp' || !class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        throw new RuntimeException('Stel MAIL_TRANSPORT=smtp of graph in; voor SMTP moet PHPMailer geïnstalleerd zijn.');
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
    $encryption = strtolower((string) env('MAIL_ENCRYPTION', 'tls'));
    if (!in_array($encryption, ['tls','ssl','smtps'], true)) throw new RuntimeException('Gebruik tls of ssl voor de dagmail.');
    $mail->SMTPSecure = $encryption === 'tls' ? 'tls' : 'ssl';
    $mail->setFrom($from, (string) env('MAIL_FROM_NAME', 'Aerts Action Bike'));
    $mail->addAddress($to);
    $mail->Subject = $message['subject'];
    $mail->isHTML(true);
    $mail->Body = $message['html'];
    $mail->AltBody = $message['plain'];
    $mail->send();
}

/** A persisted claim per recipient prevents overlapping cron jobs and duplicate sends. */
function run_daily_rental_mail(PDO $pdo, DateTimeImmutable $now, callable $send): string
{
    $now = $now->setTimezone(new DateTimeZone('Europe/Brussels'));
    if ((int) $now->format('H') < 17) return 'Nog geen 17:00 in België.';
    $pdo->exec("CREATE TABLE IF NOT EXISTS daily_rental_mail_runs (
        day TEXT NOT NULL, recipient TEXT NOT NULL, status TEXT NOT NULL,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(day,recipient))");
    $message = daily_rental_message($pdo, $now);
    $sent = 0;
    $uncertain = false;
    foreach (['werkplaats@aertsactionbike.be','marketing@aertsactionbike.be'] as $to) {
        $claim = $pdo->prepare("INSERT OR IGNORE INTO daily_rental_mail_runs(day,recipient,status) VALUES (?,?,'sending')");
        $claim->execute([$now->format('Y-m-d'),$to]);
        if ($claim->rowCount() === 0) {
            $check = $pdo->prepare('SELECT status FROM daily_rental_mail_runs WHERE day=? AND recipient=?');
            $check->execute([$now->format('Y-m-d'),$to]);
            $uncertain = $uncertain || $check->fetchColumn() !== 'sent';
            continue;
        }
        try {
            $send($to, $message);
            $status = 'sent';
            $sent++;
        } catch (Throwable $e) {
            // A timeout can occur after acceptance: do not automatically resend.
            $status = 'uncertain';
            $uncertain = true;
            error_log('Dagmail niet bevestigd voor ' . $to . '; controleer mailprovider en daily_rental_mail_runs.');
        }
        $update = $pdo->prepare('UPDATE daily_rental_mail_runs SET status=?, updated_at=CURRENT_TIMESTAMP WHERE day=? AND recipient=?');
        $update->execute([$status,$now->format('Y-m-d'),$to]);
    }
    if ($uncertain) throw new RuntimeException('Een verzending is niet bevestigd. Controleer de mailprovider vóór opnieuw verzenden; automatische herhaling is geblokkeerd.');
    return $sent . ' dagmails verzonden; overige ontvangers waren al verwerkt.';
}
