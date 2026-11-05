<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/daily_rental_mail.php';
try {
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels'));
    if (in_array('--preview', $argv, true)) {
        echo daily_rental_message(db(), $now)['html'];
        exit(0);
    }
    if (!filter_var(env('DAILY_RENTAL_MAIL_ENABLED', '0'), FILTER_VALIDATE_BOOLEAN)) {
        echo "Dagmail uitgeschakeld. Stel DAILY_RENTAL_MAIL_ENABLED=1 in.\n";
        exit(0);
    }
    validate_daily_rental_mail_config();
    echo run_daily_rental_mail(db(), $now, 'send_daily_rental_message') . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
