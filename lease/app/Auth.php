<?php

require_once __DIR__ . '/LoginThrottle.php';

final class Auth
{
    private const SESSION_KEY = 'aab_authenticated';
    private const SESSION_LOGIN_AT = 'aab_login_at';
    private const SESSION_LAST_SEEN = 'aab_last_seen';
    private const SESSION_VERSION = 'aab_auth_version';
    private const SESSION_CSRF = 'aab_csrf_token';

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

        $config = require __DIR__ . '/../config/auth.php';
        if (is_array($config)) {
            self::$config = $config;
        }
    }

    public static function isConfigured(): bool
    {
        self::boot();
        $hash = (string) (self::$config['access_key_hash'] ?? '');
        $secret = (string) (self::$config['cookie_secret'] ?? '');

        return (password_get_info($hash)['algoName'] ?? 'unknown') !== 'unknown'
            && preg_match('/^[a-f0-9]{64}$/D', $secret) === 1;
    }

    public static function isAuthenticated(): bool
    {
        self::boot();
        // An old authenticated session must never bypass missing configuration.
        if (!self::isConfigured()) {
            self::forgetAuthentication();
            return false;
        }

        if (($_SESSION[self::SESSION_KEY] ?? false) === true) {
            $version = $_SESSION[self::SESSION_VERSION] ?? '';
            if (!is_string($version) || !hash_equals(self::authVersion(), $version)) {
                self::forgetAuthentication();
                self::clearRememberCookie();
                return false;
            }

            $now = time();
            $loginAt = (int) ($_SESSION[self::SESSION_LOGIN_AT] ?? 0);
            $lastSeen = (int) ($_SESSION[self::SESSION_LAST_SEEN] ?? 0);
            if ($loginAt > 0 && $lastSeen > 0 && $loginAt <= $now && $lastSeen <= $now
                && $now - $loginAt < max(60, (int) self::$config['session_max_seconds'])
                && $now - $lastSeen < max(60, (int) self::$config['session_idle_seconds'])) {
                $_SESSION[self::SESSION_LAST_SEEN] = $now;
                return true;
            }
            self::forgetAuthentication();
            // A valid, explicitly requested remember-cookie may start a new session.
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
        // POST data is never replayed after login.
        $next = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? self::currentTarget() : 'index.php';
        header('Location: login.php?next=' . rawurlencode($next), true, 302);
        exit;
    }

    public static function requirePost(): void
    {
        self::requireLogin();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            http_response_code(405);
            exit('Gebruik het formulier om deze actie uit te voeren.');
        }
        if (!self::verifyCsrf($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            exit('Je sessie of formulier is verlopen. Vernieuw de pagina en probeer opnieuw.');
        }
    }

    public static function attempt(string $accessKey, bool $remember): array
    {
        self::boot();
        if (!self::isConfigured()) {
            return ['success' => false, 'message' => 'Deze omgeving is nog niet geconfigureerd voor login.'];
        }

        try {
            $result = LoginThrottle::attempt($accessKey, self::$config);
        } catch (Throwable $error) {
            error_log('Lease login: ' . $error->getMessage());
            return ['success' => false, 'message' => 'Aanmelden is tijdelijk niet beschikbaar. Neem contact op met de beheerder.'];
        }
        if (!$result['success']) {
            return [
                'success' => false,
                'message' => $result['wait'] > 0
                    ? 'Te veel mislukte pogingen. Probeer opnieuw over ' . $result['wait'] . ' seconden.'
                    : 'De toegangssleutel is niet correct.',
            ];
        }

        self::startAuthenticatedSession();
        if ($remember) {
            self::setRememberCookie();
        } else {
            self::clearRememberCookie();
        }
        return ['success' => true, 'message' => null];
    }

    public static function logout(): void
    {
        self::boot();
        self::clearRememberCookie();
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
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

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(self::csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function verifyCsrf($token): bool
    {
        self::boot();
        $storedToken = (string) ($_SESSION[self::SESSION_CSRF] ?? '');
        return $storedToken !== '' && is_string($token) && hash_equals($storedToken, $token);
    }

    public static function redirectAfterLogin(?string $next = null): void
    {
        header('Location: ' . self::sanitizeTarget($next), true, 303);
        exit;
    }

    public static function sendSecurityHeaders(): void
    {
        // Navigation repeats the login check after the page has started rendering.
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
        header('Referrer-Policy: same-origin');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
    }

    private static function startAuthenticatedSession(): void
    {
        session_regenerate_id(true);
        $_SESSION = [
            self::SESSION_KEY => true,
            self::SESSION_LOGIN_AT => time(),
            self::SESSION_LAST_SEEN => time(),
            self::SESSION_VERSION => self::authVersion(),
            self::SESSION_CSRF => bin2hex(random_bytes(32)),
        ];
    }

    private static function forgetAuthentication(): void
    {
        unset($_SESSION[self::SESSION_KEY], $_SESSION[self::SESSION_LOGIN_AT],
            $_SESSION[self::SESSION_LAST_SEEN], $_SESSION[self::SESSION_VERSION], $_SESSION[self::SESSION_CSRF]);
    }

    private static function restoreRememberedLogin(): bool
    {
        $value = $_COOKIE[self::rememberCookieName()] ?? '';
        if ($value === '') {
            return false;
        }
        // Version 1 cookies from the exposed secret are deliberately unsupported.
        if (!is_string($value) || !preg_match('/^v2\.([0-9]{1,12})\.([a-f0-9]{32})\.([a-f0-9]{64})$/D', $value, $parts)) {
            self::clearRememberCookie();
            return false;
        }
        [, $expiry, $nonce, $signature] = $parts;
        if ((int) $expiry <= time() || (int) $expiry > time() + self::rememberSeconds()) {
            self::clearRememberCookie();
            return false;
        }
        $payload = 'v2.' . $expiry . '.' . $nonce;
        $expected = hash_hmac('sha256', $payload . '.' . self::authVersion(), self::cookieSecret());
        if (!hash_equals($expected, $signature)) {
            self::clearRememberCookie();
            return false;
        }
        self::startAuthenticatedSession();
        return true;
    }

    private static function setRememberCookie(): void
    {
        $expiry = time() + self::rememberSeconds();
        $payload = 'v2.' . $expiry . '.' . bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $payload . '.' . self::authVersion(), self::cookieSecret());
        setcookie(self::rememberCookieName(), $payload . '.' . $signature, self::rememberOptions($expiry));
    }

    private static function clearRememberCookie(): void
    {
        setcookie(self::rememberCookieName(), '', self::rememberOptions(time() - 3600));
        unset($_COOKIE[self::rememberCookieName()]);
    }

    private static function rememberOptions(int $expiry): array
    {
        return ['expires' => $expiry, 'path' => '/', 'secure' => self::isHttps(), 'httponly' => true, 'samesite' => 'Lax'];
    }

    private static function rememberSeconds(): int
    {
        return min(30, max(1, (int) (self::$config['remember_days'] ?? 30))) * 86400;
    }

    private static function authVersion(): string
    {
        // Rotating either credential revokes existing sessions and remember-cookies.
        return hash_hmac('sha256', 'aab-lease-v2:' . self::$config['access_key_hash'], self::cookieSecret());
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
        if (!preg_match('/^[A-Za-z0-9._-]+\.php$/D', $page) || in_array($page, ['login.php', 'logout.php'], true)) {
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
        return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }
}
