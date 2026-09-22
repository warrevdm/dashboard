<?php

declare(strict_types=1);

require __DIR__ . '/src/auth.php';
authSessionStart();

if (!defined('AUTH_ADMIN_RESET_ENABLED') || AUTH_ADMIN_RESET_ENABLED !== true) {
    http_response_code(404);
    exit('Niet beschikbaar.');
}

if (!defined('AUTH_ADMIN_RESET_KEY') || strlen((string) AUTH_ADMIN_RESET_KEY) < 32) {
    http_response_code(500);
    exit('Resetconfiguratie ontbreekt.');
}

$httpsDetected = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if (defined('AUTH_FORCE_SECURE_COOKIE') && AUTH_FORCE_SECURE_COOKIE === true && !$httpsDetected) {
    http_response_code(403);
    exit('Deze resetpagina werkt alleen via HTTPS.');
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resetKey = (string) ($_POST['reset_key'] ?? '');
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if (!authVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Sessie verlopen. Herlaad de pagina.';
    } elseif (!hash_equals((string) AUTH_ADMIN_RESET_KEY, $resetKey)) {
        usleep(random_int(250000, 500000));
        $error = 'Resetgegevens zijn ongeldig.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Vul een geldig e-mailadres in.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'De wachtwoorden komen niet overeen.';
    } elseif (($passwordError = authValidatePassword($password)) !== null) {
        $error = $passwordError;
    } else {
        $stmt = authDb()->prepare("SELECT id FROM users WHERE email = ? AND role = 'admin' AND active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $adminId = $stmt->fetchColumn();

        if (!$adminId) {
            usleep(random_int(250000, 500000));
            $error = 'Resetgegevens zijn ongeldig.';
        } else {
            $now = authNow();
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $update = authDb()->prepare('UPDATE users SET password_hash = ?, password_changed_at = ?, updated_at = ? WHERE id = ?');
            $update->execute([$hash, $now, $now, (int) $adminId]);

            // Wis de rate-limit na een geldige noodreset zodat het nieuwe wachtwoord meteen bruikbaar is.
            $emailHash = authPrivacyHash(mb_strtolower($email));
            $ipHash = authPrivacyHash(authIp());
            $clearAttempts = authDb()->prepare('DELETE FROM login_attempts WHERE email_hash = ? OR ip_hash = ?');
            $clearAttempts->execute([$emailHash, $ipHash]);

            authAudit('admin_password_emergency_reset', (int) $adminId, null);
            authRotateCsrf();
            $success = true;
        }
    }
}
?>
<!doctype html>
<html lang="nl-BE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Adminwachtwoord resetten | Aerts Action Bike</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="auth-shell">
    <section class="auth-card auth-card-wide">
        <div class="auth-logo-wrap"><img src="assets/aab-logo.svg" alt="Aerts Action Bike" class="auth-logo"></div>
        <p class="eyebrow">Beveiligde noodreset</p>
        <h1 class="auth-title">Adminwachtwoord resetten</h1>
        <p class="auth-lead">Gebruik deze pagina alleen tijdelijk. Schakel de reset na succes onmiddellijk opnieuw uit in <code>src/config.php</code>.</p>

        <?php if ($error !== ''): ?><div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <?php if ($success): ?>
            <div class="alert success">Het adminwachtwoord is gewijzigd en de tijdelijke loginblokkering is gewist. Zet nu <code>AUTH_ADMIN_RESET_ENABLED</code> terug op <code>false</code> en <a href="login.php">log opnieuw in</a>.</div>
        <?php else: ?>
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(authCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="field"><label for="reset_key">Reset-sleutel</label><input id="reset_key" name="reset_key" type="password" required autocomplete="off"></div>
                <div class="field"><label for="email">Admin e-mailadres</label><input id="email" name="email" type="email" maxlength="190" required autocomplete="username"></div>
                <div class="field"><label for="password">Nieuw wachtwoord</label><input id="password" name="password" type="password" minlength="14" maxlength="128" required autocomplete="new-password"><small>Minstens 14 tekens en uniek voor dit systeem.</small></div>
                <div class="field"><label for="password_confirm">Nieuw wachtwoord herhalen</label><input id="password_confirm" name="password_confirm" type="password" minlength="14" maxlength="128" required autocomplete="new-password"></div>
                <button type="submit" class="button button-primary auth-submit">Wachtwoord resetten</button>
            </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
