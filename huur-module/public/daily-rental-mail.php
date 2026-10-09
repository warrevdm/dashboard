<?php

declare(strict_types=1);

// Deliberately no interactive bootstrap/session: the cron has its own credential.
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store, private, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
ini_set('display_errors', '0');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if (!$isHttps) { http_response_code(403); exit('HTTPS vereist.'); }

const ROOT_PATH = __DIR__ . '/..';
require_once ROOT_PATH . '/app/env.php';
require_once ROOT_PATH . '/app/daily_rental_mail.php';
load_env(ROOT_PATH . '/.env');
// A header can be used by schedulers that support it; this hosting UI uses key=.
$key = $_SERVER['HTTP_X_CRON_KEY'] ?? $_GET['key'] ?? null;
if (!daily_rental_cron_authorized($key, (string) env('DAILY_RENTAL_CRON_KEY', ''))) {
    http_response_code(403);
    exit('Geen toegang.');
}
unset($key);
if (!filter_var(env('DAILY_RENTAL_MAIL_ENABLED', '0'), FILTER_VALIDATE_BOOLEAN)) {
    exit('Dagmail uitgeschakeld.');
}
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels'));
if ((int) $now->format('H') < 17) exit('Nog geen 17:00 in België.');
try {
    $autoload = ROOT_PATH . '/vendor/autoload.php';
    if (is_file($autoload)) require_once $autoload;
    require_once ROOT_PATH . '/app/database.php';
    require_once ROOT_PATH . '/app/graph_mailer.php';
    validate_daily_rental_mail_config();
    echo run_daily_rental_mail(db(), $now, 'send_daily_rental_message');
} catch (Throwable $e) {
    // Do not expose credentials, customer data or request URLs to cron logs.
    error_log('Daily rental web cron failed; inspect mail configuration and daily_rental_mail_runs.');
    http_response_code(500);
    echo 'Dagmail niet voltooid. Controleer de mailconfiguratie en verzendregistratie.';
}
