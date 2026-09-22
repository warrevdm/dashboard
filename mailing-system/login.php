<?php

declare(strict_types=1);

require __DIR__ . '/src/auth.php';
authSessionStart();

if (authCurrentUser() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!authVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Sessie verlopen. Herlaad de pagina en probeer opnieuw.';
    } elseif ($email === '' || $password === '') {
        $error = 'Vul je e-mailadres en wachtwoord in.';
    } elseif (authLogin($email, $password)) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Aanmelden mislukt. Controleer je gegevens of probeer later opnieuw.';
    }
}
?>
<!doctype html>
<html lang="nl-BE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inloggen | Aerts Action Bike</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="auth-shell">
    <section class="auth-card">
        <div class="auth-logo-wrap"><img src="assets/aab-logo.svg" alt="Aerts Action Bike" class="auth-logo"></div>
        <p class="eyebrow">Interne toegang</p>
        <h1 class="auth-title">Aanmelden</h1>
        <p class="auth-lead">Log in met je persoonlijke account om het interne mailsysteem te gebruiken.</p>

        <?php if ($error !== ''): ?>
            <div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if (authUserCount() === 0): ?>
            <div class="alert error">Er bestaat nog geen adminaccount. Open eerst <strong>setup.php</strong>.</div>
        <?php endif; ?>

        <form method="post" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(authCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="field">
                <label for="email">E-mailadres</label>
                <input id="email" name="email" type="email" autocomplete="username" required maxlength="190">
            </div>
            <div class="field auth-password-field">
                <label for="password">Wachtwoord</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="128">
            </div>
            <button type="submit" class="button button-primary auth-submit">Inloggen</button>
        </form>
    </section>
</main>
</body>
</html>
