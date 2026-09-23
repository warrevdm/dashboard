<?php

// Run with PHP CLI + pdo_sqlite, or through the isolated Node/Wasm runner.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/WeeklyMailRunner.php';
require_once __DIR__ . '/../app/WeeklySmtpMailer.php';

$checks = 0;
function expect($condition, string $label): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $checks++;
}
function throws(callable $callback, string $label): void
{
    $thrown = false;
    try { $callback(); } catch (Throwable $error) { $thrown = true; }
    expect($thrown, $label);
}
function removeTree(string $path): void
{
    if (!is_dir($path)) { return; }
    foreach (scandir($path) as $name) {
        if ($name === '.' || $name === '..') { continue; }
        $file = $path . '/' . $name;
        if (is_dir($file)) { removeTree($file); } else { unlink($file); }
    }
    rmdir($path);
}

$temp = sys_get_temp_dir() . '/aab-weekly-tests-' . bin2hex(random_bytes(8));
mkdir($temp, 0700, true);
try {
    $now = new DateTimeImmutable('2026-09-22 09:00:00', new DateTimeZone('Europe/Brussels'));
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE lease_orders (id INTEGER PRIMARY KEY, so_number TEXT, customer_name TEXT, lease_partner TEXT, bike_name TEXT, lease_end_date TEXT, maintenance_budget REAL, archived INTEGER)');
    $pdo->exec('CREATE TABLE customer_logbook (id INTEGER PRIMARY KEY, lease_order_id INTEGER, created_at TEXT)');
    $insert = $pdo->prepare('INSERT INTO lease_orders VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ([
        [1, '2026-09-22', 0], [2, '2026-12-22', 0], [3, '2026-09-21', 0],
        [4, '2026-12-23', 0], [5, '2026-10-15', 0], [6, '2026-10-20', 1],
        [7, null, 0], [8, '', 0], [9, '2026-09-30', 0],
    ] as [$id, $date, $archived]) {
        $insert->execute([$id, 'SO-' . $id, $id === 9 ? '<script>alert(1)</script> & klant' : 'Testklant ' . $id,
            'Testpartner', 'Testfiets', $date, 1200.08, $archived]);
    }
    $pdo->exec("INSERT INTO customer_logbook VALUES (1, 5, '2024-01-01'), (2, 5, '2026-09-01')");
    $orders = ExpiringContracts::find($pdo, $now);
    expect(array_column($orders, 'id') === [1, 9, 2], 'Exact selection: inclusive boundaries, sorted, no archived/expired/logged/missing dates');
    expect(count(ExpiringContracts::find($pdo, $now, false)) === 4, 'Unfiltered web list retains followed-up contracts');
    expect(ExpiringContracts::window(new DateTimeImmutable('2027-01-31'))[1]->format('Y-m-d') === '2027-04-30', 'Calendar month end clamps');
    expect(ExpiringContracts::window(new DateTimeImmutable('2024-02-29'))[1]->format('Y-m-d') === '2024-05-29', 'Leap-day window');
    expect(ExpiringContracts::window(new DateTimeImmutable('2026-09-21T23:00:00Z'))[0]->format('Y-m-d') === '2026-09-22', 'Selection uses Belgian date');

    foreach ([
        ['2026-09-22T06:59:00Z', false], ['2026-09-22T07:00:00Z', true],
        ['2026-09-21T10:00:00Z', false], ['2026-09-23T10:00:00Z', false],
        ['2026-03-24T07:59:00Z', false], ['2026-03-24T08:00:00Z', true],
        ['2026-03-31T06:59:00Z', false], ['2026-03-31T07:00:00Z', true],
        ['2026-10-27T07:59:00Z', false], ['2026-10-27T08:00:00Z', true],
    ] as [$date, $due]) {
        expect(WeeklyMailRunner::isDue(new DateTimeImmutable($date), 9) === $due, 'Tuesday and DST: ' . $date);
    }
    $config = [
        'enabled' => true, 'send_hour' => 9,
        'recipients' => ['internal@example.test', 'second@example.test', ' INTERNAL@example.test '],
        'from_address' => 'sender@example.test', 'from_name' => 'Aerts Action Bike',
        'base_url' => 'https://example.test/lease/public', 'state_directory' => $temp . '/state',
        'smtp' => ['host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'test', 'password' => 'synthetic-password'],
    ];
    expect(WeeklyMailConfig::errors($config) === [], 'Valid private settings');
    expect(count(WeeklyMailConfig::recipients($config)) === 2, 'Recipients deduplicated case-insensitively');
    expect(count(WeeklyMailConfig::errors(array_replace($config, ['recipients' => []]))) > 0, 'Missing recipients blocked');
    expect(count(WeeklyMailConfig::errors(array_replace($config, ['recipients' => ["test@example.test\r\nBcc: other@example.test"]]))) > 0, 'Header-injection recipient blocked');
    expect(count(WeeklyMailConfig::errors(array_replace($config, ['send_hour' => 24]))) > 0, 'Invalid schedule blocked');
    foreach (['http://example.test', 'javascript:alert(1)', 'https://user@example.test', 'https://example.test/?token=123', 'https://example.test/#fragment'] as $url) {
        expect(!WeeklyMailConfig::validBaseUrl($url), 'Unsafe base URL rejected: ' . $url);
    }
    $message = WeeklyMailMessage::build($orders, $now, $config['base_url']);
    expect(!str_contains($message['html'], '<script>') && str_contains($message['html'], '&lt;script&gt;'), 'Imported customer content escaped in HTML');
    expect(str_contains($message['html'], '€ 1.200,08') && str_contains($message['text'], '€ 1.200,08'), 'Budget formatting in both alternatives');
    expect(str_contains($message['text'], 'contract-detail.php?id=1') && str_contains($message['html'], 'without_logbook=1'), 'Protected detail and filtered overview links');
    expect(str_contains($message['text'], 'Vandaag') && str_contains($message['html'], '22/12/2026'), 'Expiry dates and days displayed');
    expect(str_contains(WeeklyMailMessage::build([], $now, $config['base_url'])['text'], 'geen aflopende contracten'), 'Empty digest has a useful confirmation');
    throws(fn () => WeeklyMailMessage::build([], $now, 'javascript:alert(1)'), 'Unsafe mail link cannot be rendered');

    $sent = [];
    $queries = 0;
    $load = static function (DateTimeImmutable $date) use ($pdo, &$queries): array {
        $queries++;
        return ExpiringContracts::find($pdo, $date);
    };
    $send = static function (string $recipient, array $message) use (&$sent): void { $sent[] = [$recipient, $message]; };
    $runner = new WeeklyMailRunner($config, $load, $send);
    expect($runner->run($now->modify('-1 day'))['status'] === 'not_due' && $queries === 0 && !$sent, 'No query or delivery on Monday');
    expect($runner->run($now->modify('-1 minute'))['status'] === 'not_due', 'Not before configured hour');
    expect((new WeeklyMailRunner(array_replace($config, ['enabled' => false]), $load, $send))->run($now)['status'] === 'disabled', 'Disabled does not send');
    throws(fn () => (new WeeklyMailRunner(array_replace($config, ['recipients' => []]), $load, $send))->run($now), 'Missing recipients fail before delivery');
    expect($runner->run($now)['sent'] === 2 && count($sent) === 2, 'First Tuesday sends once per internal recipient');
    expect($runner->run($now->modify('+1 hour'))['status'] === 'already_sent' && count($sent) === 2 && $queries === 1, 'Repeat cron does not send or requery');
    expect((int) $pdo->query('SELECT COUNT(*) FROM customer_logbook')->fetchColumn() === 2, 'Digest never creates a customer logbook entry');
    expect($runner->run($now->modify('+7 days'))['sent'] === 2 && count($sent) === 4, 'New Tuesday sends again');
    $pdo->exec("INSERT INTO customer_logbook VALUES (3, 9, '2026-09-29')");
    $runner->run($now->modify('+14 days'));
    expect(!str_contains($sent[4][1]['text'], 'SO-9'), 'A later logbook entry removes a contract from the next digest');
    $stateJson = file_get_contents($config['state_directory'] . '/status.json');
    expect(!str_contains($stateJson, 'Testklant') && !str_contains($stateJson, 'internal@example.test') && !str_contains($stateJson, 'synthetic-password'), 'State contains no message contents or credentials');

    $emptyConfig = array_replace($config, ['state_directory' => $temp . '/empty']);
    $emptySent = [];
    $emptyRunner = new WeeklyMailRunner($emptyConfig, static fn () => [], static function ($to, $body) use (&$emptySent) { $emptySent[] = $body; });
    expect($emptyRunner->run($now)['sent'] === 2 && $emptySent[0]['count'] === 0, 'A weekly zero-result confirmation is sent');
    $failureConfig = array_replace($config, ['state_directory' => $temp . '/failure']);
    $calls = [];
    $failSecond = static function ($to, $body) use (&$calls) {
        $calls[] = $to;
        if ($to === 'second@example.test') { throw new RuntimeException('Synthetic uncertain transport failure'); }
    };
    $failureRunner = new WeeklyMailRunner($failureConfig, $load, $failSecond);
    $result = $failureRunner->run($now);
    expect($result['sent'] === 1 && $result['failed'] === 1 && $result['status'] === 'needs_review', 'Partial failure tracked per recipient');
    expect($failureRunner->run($now)['status'] === 'needs_review' && count($calls) === 2, 'Uncertain delivery never retries automatically');
    $recover = new WeeklyMailRunner($failureConfig, $load, static function ($to, $body) use (&$calls) { $calls[] = $to; });
    expect($recover->run($now, true)['sent'] === 1 && end($calls) === 'second@example.test', 'Explicit retry only retries unconfirmed recipient');
    expect($recover->run($now, true)['status'] === 'already_sent', 'Retry flag does not resend successful deliveries');
    $brokenConfig = array_replace($config, ['state_directory' => $temp . '/broken']);
    mkdir($brokenConfig['state_directory']);
    file_put_contents($brokenConfig['state_directory'] . '/status.json', '{broken');
    throws(fn () => (new WeeklyMailRunner($brokenConfig, $load, $send))->run($now), 'Corrupt state stops delivery instead of losing deduplication');
    $crashConfig = array_replace($config, ['state_directory' => $temp . '/crash']);
    $crashStore = new WeeklyMailState($crashConfig['state_directory']);
    $crashStore->locked(static function ($store) use ($now, $crashConfig) {
        $state = ['version' => 1, 'weeks' => [$now->format('Y-m-d') => ['recipients' => []]]];
        foreach (WeeklyMailConfig::recipients($crashConfig) as $to) {
            $state['weeks'][$now->format('Y-m-d')]['recipients'][hash('sha256', strtolower($to))] = ['status' => 'sending'];
        }
        $store->write($state);
        return [];
    });
    expect((new WeeklyMailRunner($crashConfig, $load, $send))->run($now)['status'] === 'needs_review', 'Interrupted sending state does not resend');
    $nestedResult = $crashStore->locked(static fn ($store) => $store->locked(static fn () => ['status' => 'unexpected']));
    expect($nestedResult['status'] === 'busy', 'Overlapping job cannot acquire delivery lock');

    // A local transport double captures PHPMailer configuration; never opens SMTP.
    require __DIR__ . '/fixtures/SmtpDouble.php';
    (new WeeklySmtpMailer($config))->send('internal@example.test', $message);
    $mail = \PHPMailer\PHPMailer\PHPMailer::$last;
    expect($mail->smtp && $mail->SMTPSecure === 'tls' && $mail->SMTPAuth && $mail->SMTPDebug === 0, 'SMTP uses authentication, TLS and no debug output');
    expect($mail->addresses === ['internal@example.test'] && $mail->Body === $message['html'] && $mail->AltBody === $message['text'], 'Mailer sends to explicit internal address with both bodies');
    expect($mail->from === 'sender@example.test' && $mail->CharSet === 'UTF-8', 'Sender and UTF-8 configured');
    $ssl = $config; $ssl['smtp']['encryption'] = 'smtps'; $ssl['smtp']['port'] = 465;
    (new WeeklySmtpMailer($ssl))->send('internal@example.test', $message);
    expect(\PHPMailer\PHPMailer\PHPMailer::$last->SMTPSecure === 'ssl', 'Implicit TLS supported');
    echo 'PASS: ' . $checks . " weekly-mail checks. Synthetic data only; no messages sent.\n";
} finally {
    removeTree($temp);
}
