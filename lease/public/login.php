<?php

require_once __DIR__ . '/../app/Auth.php';

Auth::boot();
Auth::sendSecurityHeaders();

header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

$next = (string) ($_GET['next'] ?? $_POST['next'] ?? 'index.php');
$error = null;
$loggedOut = isset($_GET['logged_out']);

if (Auth::isAuthenticated()) {
    Auth::redirectAfterLogin($next);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');

    if (!Auth::verifyCsrf($csrfToken)) {
        $error = 'Je sessie is verlopen. Vernieuw de pagina en probeer opnieuw.';
    } else {
        $accessKey = (string) ($_POST['access_key'] ?? '');
        $remember = isset($_POST['remember']);
        $result = Auth::attempt($accessKey, $remember);

        if ($result['success'] === true) {
            Auth::redirectAfterLogin($next);
        }

        $error = (string) ($result['message'] ?? 'Inloggen is niet gelukt.');
    }
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inloggen | Aerts Action Bike Lease Import Manager</title>
    <link rel="stylesheet" href="login.css">
    <link rel="stylesheet" href="login-logo.css">
</head>
<body>
    <main class="login-shell">
        <section class="brand-panel" aria-label="Aerts Action Bike">
            <div class="brand-orbit brand-orbit-one" aria-hidden="true"></div>
            <div class="brand-orbit brand-orbit-two" aria-hidden="true"></div>

            <div class="brand-content">
                <div class="brand-logo-wrap">
                    <img
                        class="brand-logo"
                        src="assets/aerts-logo.svg"
                        alt="Aerts Action Bike"
                        width="650"
                        height="255"
                    >
                </div>

                <p class="eyebrow">Lease Import Manager</p>
                <h1>Grip op elk leasingdossier.</h1>
                <p class="brand-intro">
                    Contracten, onderhoud, budgetten en opvolging in één interne omgeving.
                </p>

                <div class="brand-pills" aria-label="Belangrijkste functies">
                    <span>Contracten</span>
                    <span>Onderhoud</span>
                    <span>Analytics</span>
                </div>
            </div>

            <div class="brand-footer">
                <span class="status-dot" aria-hidden="true"></span>
                Interne omgeving · Aerts Action Bike · Kalmthout
            </div>
        </section>

        <section class="login-panel">
            <div class="login-card">
                <div class="login-card-header">
                    <span class="login-kicker">Welkom terug</span>
                    <h2>Log in om verder te gaan</h2>
                    <p>Gebruik de persoonlijke toegangssleutel voor deze omgeving.</p>
                </div>

                <?php if ($loggedOut): ?>
                    <div class="notice notice-success" role="status">
                        Je bent veilig afgemeld.
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="notice notice-error" role="alert">
                        <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <?php if (!Auth::isConfigured()): ?>
                    <div class="notice notice-error" role="alert">
                        Deze omgeving is nog niet geconfigureerd voor login. Neem contact op met de beheerder.
                    </div>
                <?php endif; ?>

                <form method="POST" class="login-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                    <input type="hidden" name="next" value="<?= e($next) ?>">

                    <div class="field-group">
                        <label for="access_key">Toegangssleutel</label>
                        <input
                            type="password"
                            name="access_key"
                            id="access_key"
                            autocomplete="current-password"
                            required
                            autofocus
                            <?= !Auth::isConfigured() ? 'disabled' : '' ?>
                        >
                        <small>De sleutel wordt nooit in leesbare vorm opgeslagen.</small>
                    </div>

                    <label class="remember-row">
                        <input type="checkbox" name="remember" value="1" checked>
                        <span>
                            <strong>Ingelogd blijven</strong>
                            <small>Deze browser maximaal 30 dagen onthouden.</small>
                        </span>
                    </label>

                    <button type="submit" class="login-button" <?= !Auth::isConfigured() ? 'disabled' : '' ?>>
                        Veilig inloggen
                        <span aria-hidden="true">→</span>
                    </button>
                </form>

                <div class="security-note">
                    <span class="security-icon" aria-hidden="true">✓</span>
                    <div>
                        <strong>Beveiligde interne toegang</strong>
                        <p>Sessies, veilige cookies en tijdelijke blokkering na meerdere mislukte pogingen.</p>
                    </div>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
