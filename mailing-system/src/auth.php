<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function authSessionStart(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');

    $httpsDetected = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $secure = defined('AUTH_FORCE_SECURE_COOKIE')
        ? (bool) AUTH_FORCE_SECURE_COOKIE
        : $httpsDetected;
    $cookiePath = defined('AUTH_COOKIE_PATH') && trim((string) AUTH_COOKIE_PATH) !== ''
        ? (string) AUTH_COOKIE_PATH
        : '/';

    session_name('AAB_INTERNAL');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function authDbPath(): string
{
    if (defined('AUTH_DB_PATH') && trim((string) AUTH_DB_PATH) !== '') {
        return (string) AUTH_DB_PATH;
    }

    return dirname(__DIR__) . '/data/auth.sqlite';
}

function authDb(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = authDbPath();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Authenticatiedatamap kon niet worden aangemaakt.');
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    authMigrate($pdo);

    return $pdo;
}

function authMigrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE COLLATE NOCASE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL CHECK(role IN ('admin','user')) DEFAULT 'user',
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        last_login_at TEXT NULL,
        password_changed_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email_hash TEXT NOT NULL,
        ip_hash TEXT NOT NULL,
        attempted_at INTEGER NOT NULL,
        success INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attempt_email_time ON login_attempts(email_hash, attempted_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attempt_ip_time ON login_attempts(ip_hash, attempted_at)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        event TEXT NOT NULL,
        target_user_id INTEGER NULL,
        ip_hash TEXT NOT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY(target_user_id) REFERENCES users(id) ON DELETE SET NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        customer_name TEXT NOT NULL,
        customer_email TEXT NOT NULL,
        bike_type TEXT NOT NULL,
        pickup_note TEXT NOT NULL DEFAULT '',
        subject TEXT NOT NULL,
        mode TEXT NOT NULL CHECK(mode IN ('graph','eml')),
        status TEXT NOT NULL CHECK(status IN ('sent','failed','eml_created')),
        error_message TEXT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mail_log_user_time ON mail_log(user_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mail_log_status_time ON mail_log(status, created_at)');
}

function authNow(): string
{
    return gmdate('c');
}

function authIp(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function authPrivacyHash(string $value): string
{
    $pepper = defined('AUTH_AUDIT_PEPPER') ? (string) AUTH_AUDIT_PEPPER : 'aab-local-audit';
    return hash_hmac('sha256', $value, $pepper);
}

function authCsrfToken(): string
{
    authSessionStart();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function authVerifyCsrf(?string $token): bool
{
    authSessionStart();
    return is_string($token) && isset($_SESSION['csrf_token']) && hash_equals((string) $_SESSION['csrf_token'], $token);
}

function authRotateCsrf(): void
{
    authSessionStart();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function authAudit(string $event, ?int $targetUserId = null, ?int $actorUserId = null): void
{
    $pdo = authDb();
    if ($actorUserId === null) {
        $actorUserId = isset($_SESSION['auth_user_id']) ? (int) $_SESSION['auth_user_id'] : null;
    }
    $stmt = $pdo->prepare('INSERT INTO audit_log (user_id,event,target_user_id,ip_hash,created_at) VALUES (?,?,?,?,?)');
    $stmt->execute([$actorUserId, $event, $targetUserId, authPrivacyHash(authIp()), authNow()]);
}

function authLogMail(
    int $userId,
    string $customerName,
    string $customerEmail,
    string $bikeType,
    string $pickupNote,
    string $subject,
    string $mode,
    string $status,
    ?string $errorMessage = null
): void {
    $stmt = authDb()->prepare('INSERT INTO mail_log (user_id,customer_name,customer_email,bike_type,pickup_note,subject,mode,status,error_message,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $userId,
        $customerName,
        $customerEmail,
        $bikeType,
        $pickupNote,
        $subject,
        $mode,
        $status,
        $errorMessage !== null ? mb_substr($errorMessage, 0, 500) : null,
        authNow(),
    ]);
}

function authCurrentUser(): ?array
{
    authSessionStart();
    if (empty($_SESSION['auth_user_id'])) {
        return null;
    }

    $now = time();
    $idleLimit = defined('AUTH_IDLE_TIMEOUT') ? (int) AUTH_IDLE_TIMEOUT : 1800;
    $absoluteLimit = defined('AUTH_ABSOLUTE_TIMEOUT') ? (int) AUTH_ABSOLUTE_TIMEOUT : 28800;
    $startedAt = (int) ($_SESSION['auth_started_at'] ?? 0);
    $lastSeen = (int) ($_SESSION['auth_last_seen'] ?? 0);

    if ($startedAt <= 0 || $lastSeen <= 0 || ($now - $lastSeen) > $idleLimit || ($now - $startedAt) > $absoluteLimit) {
        authLogout(false);
        return null;
    }

    $stmt = authDb()->prepare('SELECT id,name,email,role,active,password_changed_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $_SESSION['auth_user_id']]);
    $user = $stmt->fetch();
    if (!$user || (int) $user['active'] !== 1) {
        authLogout(false);
        return null;
    }

    $sessionPasswordChangedAt = (string) ($_SESSION['auth_password_changed_at'] ?? '');
    if ($sessionPasswordChangedAt === '' || !hash_equals((string) $user['password_changed_at'], $sessionPasswordChangedAt)) {
        authLogout(false);
        return null;
    }

    $_SESSION['auth_last_seen'] = $now;
    return $user;
}

function authRequireLogin(): array
{
    $user = authCurrentUser();
    if ($user === null) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

function authRequireAdmin(): array
{
    $user = authRequireLogin();
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Geen toegang.');
    }
    return $user;
}

function authRateLimited(string $email): bool
{
    $pdo = authDb();
    $cutoff = time() - 900;
    $emailHash = authPrivacyHash(mb_strtolower(trim($email)));
    $ipHash = authPrivacyHash(authIp());

    $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([time() - 86400]);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email_hash = ? AND attempted_at >= ? AND success = 0');
    $stmt->execute([$emailHash, $cutoff]);
    $emailFailures = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_hash = ? AND attempted_at >= ? AND success = 0');
    $stmt->execute([$ipHash, $cutoff]);
    $ipFailures = (int) $stmt->fetchColumn();

    return $emailFailures >= 5 || $ipFailures >= 20;
}

function authRecordAttempt(string $email, bool $success): void
{
    $stmt = authDb()->prepare('INSERT INTO login_attempts (email_hash,ip_hash,attempted_at,success) VALUES (?,?,?,?)');
    $stmt->execute([
        authPrivacyHash(mb_strtolower(trim($email))),
        authPrivacyHash(authIp()),
        time(),
        $success ? 1 : 0,
    ]);
}

function authLogin(string $email, string $password): bool
{
    authSessionStart();
    if (authRateLimited($email)) {
        authAudit('login_rate_limited');
        return false;
    }

    $stmt = authDb()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([trim($email)]);
    $user = $stmt->fetch();
    $valid = $user && (int) $user['active'] === 1 && password_verify($password, (string) $user['password_hash']);
    authRecordAttempt($email, (bool) $valid);

    if (!$valid) {
        authAudit('login_failed');
        usleep(random_int(150000, 350000));
        return false;
    }

    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        authDb()->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?')->execute([$newHash, authNow(), (int) $user['id']]);
    }

    session_regenerate_id(true);
    $_SESSION['auth_user_id'] = (int) $user['id'];
    $_SESSION['auth_started_at'] = time();
    $_SESSION['auth_last_seen'] = time();
    $_SESSION['auth_password_changed_at'] = (string) $user['password_changed_at'];
    authRotateCsrf();
    authDb()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([authNow(), (int) $user['id']]);
    authAudit('login_success', (int) $user['id'], (int) $user['id']);
    return true;
}

function authLogout(bool $audit = true): void
{
    authSessionStart();
    if ($audit && !empty($_SESSION['auth_user_id'])) {
        authAudit('logout', (int) $_SESSION['auth_user_id'], (int) $_SESSION['auth_user_id']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], true);
    }
    session_destroy();
}

function authValidatePassword(string $password): ?string
{
    if (mb_strlen($password) < 14) {
        return 'Gebruik minstens 14 tekens.';
    }
    if (mb_strlen($password) > 128) {
        return 'Het wachtwoord is te lang.';
    }
    return null;
}

function authUserCount(): int
{
    return (int) authDb()->query('SELECT COUNT(*) FROM users')->fetchColumn();
}
