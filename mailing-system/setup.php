<?php

declare(strict_types=1);

require __DIR__ . '/src/auth.php';
authSessionStart();

if (authUserCount() > 0) {
    http_response_code(404);
    exit('Setup is niet meer beschikbaar.');
}

$error = '';
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $setupKey = (string) ($_POST['setup_key'] ?? '');
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if (!authVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Sessie verlopen. Herlaad de pagina.';
    } elseif (!defined('AUTH_SETUP_KEY') || strlen((string) AUTH_SETUP_KEY) < 24 || !hash_equals((string) AUTH_SETUP_KEY, $setupKey)) {
        $error = 'De setup-sleutel is ongeldig.';
    } elseif ($name === '' || mb_strlen($name) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Vul een geldige naam en e-mailadres in.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'De wachtwoorden komen niet overeen.';
    } elseif (($passwordError = authValidatePassword($password)) !== null) {
        $error = $passwordError;
    } else {
        $now = authNow();
        $stmt = authDb()->prepare('INSERT INTO users (name,email,password_hash,role,active,created_at,updated_at,password_changed_at) VALUES (?,?,?,?,1,?,?,?)');
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'admin', $now, $now, $now]);
        authAudit('admin_bootstrap_created', (int) authDb()->lastInsertId(), null);
        authRotateCsrf();
        $success = true;
    }
}
?>
<!doctype html>
<html lang="nl-BE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Eerste admin instellen | Aerts Action Bike</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="auth-shell">
    <section class="auth-card auth-card-wide">
        <div class="auth-logo-wrap"><img src="assets/aab-logo.svg" alt="Aerts Action Bike" class="auth-logo"></div>
        <p class="eyebrow">Eenmalige setup</p>
        <h1 class="auth-title">Adminaccount aanmaken</h1>
        <p class="auth-lead">Deze pagina sluit automatisch zodra het eerste adminaccount bestaat.</p>

        <?php if ($error !== ''): ?><div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($success): ?>
            <div class="alert success">Adminaccount aangemaakt. <a href="login.php">Ga naar inloggen</a>.</div>
        <?php else: ?>
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(authCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="field"><label for="setup_key">Setup-sleutel</label><input id="setup_key" name="setup_key" type="password" required autocomplete="off"></div>
                <div class="field"><label for="name">Naam admin</label><input id="name" name="name" type="text" maxlength="100" required></div>
                <div class="field"><label for="email">E-mailadres</label><input id="email" name="email" type="email" maxlength="190" required></div>
                <div class="field"><label for="password">Wachtwoord</label><input id="password" name="password" type="password" maxlength="128" minlength="14" required autocomplete="new-password"><small>Minstens 14 tekens. Gebruik een uniek wachtwoord.</small></div>
                <div class="field"><label for="password_confirm">Wachtwoord herhalen</label><input id="password_confirm" name="password_confirm" type="password" maxlength="128" minlength="14" required autocomplete="new-password"></div>
                <button type="submit" class="button button-primary auth-submit">Admin aanmaken</button>
            </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
