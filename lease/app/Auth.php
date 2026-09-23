<?php

final class Auth
{
    private const SESSION_KEY = 'aab_authenticated';
    private const SESSION_LOGIN_AT = 'aab_login_at';
    private const SESSION_CSRF = 'aab_csrf_token';
    private const SESSION_ATTEMPTS = 'aab_login_attempts';
    private const SESSION_LOCKED_UNTIL = 'aab_locked_until';

    private static bool $booted = false;
    private static array $config = [];

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');

            session_name('aab_lease_session');
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => self::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            session_start();
        }

        $configPath = __DIR__ . '/../config/auth.php';

        if (is_file($configPath)) {
            $config = require $configPath;

            if (is_array($config)) {
                self::$config = $config;
            }
        }
    }

    public static function isConfigured(): bool
    {
        self::boot();

        $hash = (string) (self::$config['access_key_hash'] ?? '');
        $secret = (string) (self::$config['cookie_secret'] ?? '');

        return $hash !== ''
            && !str_contains($hash, 'REPLACE_')
            && strlen($secret) >= 32
            && !str_contains($secret, 'REPLACE_');
    }

    public static function isAuthenticated(): bool
    {
        self::boot();

        if (($_SESSION[self::SESSION_KEY] ?? false) === true) {
            return true;
        }

        return self::restoreRememberedLogin();
    }

    public static function requireLogin(): void
    {
        self::boot();
        self::sendSecurityHeaders();

        if (self::isAuthenticated()) {
            return;
        }

        $next = self::currentTarget();
        header('Location: login.php?next=' . rawurlencode($next), true, 302);
        exit;
    }

    public static function attempt(string $accessKey, bool $remember): array
    {
        self::boot();

        if (!self::isConfigured()) {
            return [
                'success' => false,
                'message' => 'Deze omgeving is nog niet geconfigureerd voor login.',
            ];
        }

        $remainingLockout = self::remainingLockoutSeconds();

        if ($remainingLockout > 0) {
            return [
                'success' => false,
                'message' => 'Te veel mislukte pogingen. Probeer opnieuw over ' . $remainingLockout . ' seconden.',
            ];
        }

        $hash = (string) self::$config['access_key_hash'];

        if (!password_verify($accessKey, $hash)) {
            self::registerFailedAttempt();

            $remainingLockout = self::remainingLockoutSeconds();

            return [
                'success' => false,
                'message' => $remainingLockout > 0
                    ? 'Te veel mislukte pogingen. Probeer opnieuw over ' . $remainingLockout . ' seconden.'
                    : 'De toegangssleutel is niet correct.',
            ];
        }

        self::clearFailedAttempts();
        session_regenerate_id(true);

        $_SESSION[self::SESSION_KEY] = true;
        $_SESSION[self::SESSION_LOGIN_AT] = time();

        if ($remember) {
            self::setRememberCookie();
        } else {
            self::clearRememberCookie();
        }

        return [
            'success' => true,
            'message' => null,
        ];
    }

    public static function logout(): void
    {
        self::boot();

        self::clearRememberCookie();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool) ($params['secure'] ?? false),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        session_destroy();
    }

    public static function csrfToken(): string
    {
        self::boot();

        if (empty($_SESSION[self::SESSION_CSRF])) {
            $_SESSION[self::SESSION_CSRF] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[self::SESSION_CSRF];
    }

    public static function verifyCsrf(?string $token): bool
    {
        self::boot();

        $storedToken = (string) ($_SESSION[self::SESSION_CSRF] ?? '');

        return $storedToken !== ''
            && is_string($token)
            && hash_equals($storedToken, $token);
    }

    public static function redirectAfterLogin(?string $next = null): void
    {
        $target = self::sanitizeTarget($next);
        header('Location: ' . $target, true, 302);
        exit;
    }

    public static function sendSecurityHeaders(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
    }

    private static function restoreRememberedLogin(): bool
    {
        if (!self::isConfigured()) {
            return false;
        }

        $cookieName = self::rememberCookieName();
        $cookieValue = (string) ($_COOKIE[$cookieName] ?? '');

        if ($cookieValue === '') {
            return false;
        }

        $parts = explode('.', $cookieValue);

        if (count($parts) !== 3) {
            self::clearRememberCookie();
            return false;
        }

        [$expiry, $nonce, $signature] = $parts;

        if (!ctype_digit($expiry) || (int) $expiry < time()) {
            self::clearRememberCookie();
            return false;
        }

        if (!preg_match('/^[a-f0-9]{32}$/', $nonce)) {
            self::clearRememberCookie();
            return false;
        }

        $payload = $expiry . '.' . $nonce;
        $expectedSignature = hash_hmac('sha256', $payload, self::cookieSecret());

        if (!hash_equals($expectedSignature, $signature)) {
            self::clearRememberCookie();
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = true;
        $_SESSION[self::SESSION_LOGIN_AT] = time();

        return true;
    }

    private static function setRememberCookie(): void
    {
        $days = max(1, (int) (self::$config['remember_days'] ?? 30));
        $expiry = time() + ($days * 86400);
        $nonce = bin2hex(random_bytes(16));
        $payload = $expiry . '.' . $nonce;
        $signature = hash_hmac('sha256', $payload, self::cookieSecret());
        $value = $payload . '.' . $signature;

        setcookie(self::rememberCookieName(), $value, [
            'expires' => $expiry,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearRememberCookie(): void
    {
        setcookie(self::rememberCookieName(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        unset($_COOKIE[self::rememberCookieName()]);
    }

    private static function registerFailedAttempt(): void
    {
        $attempts = (int) ($_SESSION[self::SESSION_ATTEMPTS] ?? 0) + 1;
        $maxAttempts = max(1, (int) (self::$config['max_attempts'] ?? 5));

        if ($attempts >= $maxAttempts) {
            $lockoutSeconds = max(30, (int) (self::$config['lockout_seconds'] ?? 300));
            $_SESSION[self::SESSION_LOCKED_UNTIL] = time() + $lockoutSeconds;
            $_SESSION[self::SESSION_ATTEMPTS] = 0;
            return;
        }

        $_SESSION[self::SESSION_ATTEMPTS] = $attempts;
    }

    private static function remainingLockoutSeconds(): int
    {
        $lockedUntil = (int) ($_SESSION[self::SESSION_LOCKED_UNTIL] ?? 0);

        if ($lockedUntil <= time()) {
            unset($_SESSION[self::SESSION_LOCKED_UNTIL]);
            return 0;
        }

        return $lockedUntil - time();
    }

    private static function clearFailedAttempts(): void
    {
        unset(
            $_SESSION[self::SESSION_ATTEMPTS],
            $_SESSION[self::SESSION_LOCKED_UNTIL]
        );
    }

    private static function currentTarget(): string
    {
        $page = basename((string) ($_SERVER['PHP_SELF'] ?? 'index.php'));
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');

        return $page . ($query !== '' ? '?' . $query : '');
    }

    private static function sanitizeTarget(?string $target): string
    {
        $target = trim((string) $target);

        if ($target === '' || preg_match('/[\r\n]/', $target)) {
            return 'index.php';
        }

        $parts = parse_url($target);

        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return 'index.php';
        }

        $page = basename((string) ($parts['path'] ?? ''));

        if (!preg_match('/^[A-Za-z0-9._-]+\.php$/', $page)) {
            return 'index.php';
        }

        if (in_array($page, ['login.php', 'logout.php'], true)) {
            return 'index.php';
        }

        $query = (string) ($parts['query'] ?? '');

        return $page . ($query !== '' ? '?' . $query : '');
    }

    private static function rememberCookieName(): string
    {
        return (string) (self::$config['cookie_name'] ?? 'aab_lease_remember');
    }

    private static function cookieSecret(): string
    {
        return (string) (self::$config['cookie_secret'] ?? '');
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}
