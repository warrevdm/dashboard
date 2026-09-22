<?php

declare(strict_types=1);

require __DIR__ . '/src/auth.php';
$currentUser = authRequireLogin();
$status = $_GET['status'] ?? '';
$message = $_GET['message'] ?? '';
?>
<!doctype html>
<html lang="nl-BE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Collect & Go | Aerts Action Bike</title>
    <link rel="stylesheet" href="assets/style.css?v=20260825-comms1">
    <link rel="stylesheet" href="assets/communication.css?v=20260825-1">
</head>
<body class="collect-page">
<header class="topbar app-topbar" style="grid-template-columns:1fr auto;">
    <div class="topbar-brand">
        <img src="assets/aab-logo.svg" alt="Aerts Action Bike">
        <div><strong>Interne mailingtool</strong><small><?= htmlspecialchars((string) $currentUser['name'], ENT_QUOTES, 'UTF-8') ?></small></div>
    </div>

    <nav class="topbar-actions">
        <a class="button button-secondary" href="communication-dashboard.php">Communicatie</a>
        <?php if (($currentUser['role'] ?? '') === 'admin'): ?><a class="button button-secondary" href="admin.php">Gebruikersbeheer</a><?php endif; ?>
        <a class="button button-secondary" href="logout.php">Uitloggen</a>
    </nav>
</header>

<nav class="topbar-systems" aria-label="Kies mailsysteem" style="width:min(1120px,calc(100% - 32px));margin:12px auto 0;justify-content:center;">
    <a class="topbar-system system-bike" href="index.php">
        <span class="system-icon" aria-hidden="true">🚲</span>
        <span class="system-copy"><strong>Nieuwe fiets</strong><small>Afhaling met afspraak</small></span>
    </a>
    <a class="topbar-system system-collect active" href="collect-go.php" aria-current="page">
        <span class="system-icon" aria-hidden="true">📦</span>
        <span class="system-copy"><strong>Collect & Go</strong><small>Product ophalen zonder afspraak</small></span>
    </a>
</nav>

<main class="app-shell">
    <section class="panel intro-panel">
        <div class="brand-logo-wrap"><img src="assets/aab-logo.svg" alt="Aerts Action Bike" class="brand-logo"></div>
        <p class="eyebrow">Collect & Go</p>
        <h1>Bestelling klaar voor afhaling</h1>
        <p class="lead">Laat de klant weten dat een besteld product klaarstaat en dat hij of zij gewoon tijdens de openingsuren kan langskomen.</p>
        <div class="info-card"><strong>Geen afspraak nodig</strong><p>Deze mail bevat geen bookinglink. De klant komt rechtstreeks naar de winkel tijdens de openingsuren.</p></div>
    </section>

    <section class="panel form-panel">
        <?php if ($status === 'success'): ?><div class="alert success"><?= htmlspecialchars($message ?: 'De mail werd succesvol verstuurd.', ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($status === 'error'): ?><div class="alert error"><?= htmlspecialchars($message ?: 'De mail kon niet worden verstuurd.', ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <form id="collectForm" action="send.php" method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(authCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="mail_type" value="collect_go">
            <div class="form-grid">
                <div class="field"><label for="customer_name">Naam klant</label><input id="customer_name" name="customer_name" type="text" autocomplete="name" required maxlength="100" placeholder="Bijvoorbeeld: Jan Peeters"><small class="field-error"></small></div>
                <div class="field"><label for="customer_email">E-mailadres</label><input id="customer_email" name="customer_email" type="email" autocomplete="email" required maxlength="190" placeholder="jan@example.be"><small class="field-error"></small></div>
                <div class="field field-full"><label for="bike_type">Besteld product</label><input id="bike_type" name="bike_type" type="text" required maxlength="150" placeholder="Bijvoorbeeld: Thule Epos 2 fietsendrager"><small>Vermeld kort wat er voor de klant klaarstaat.</small><small class="field-error"></small></div>

                <div id="duplicateWarning" class="duplicate-warning field-full" hidden aria-live="polite"></div>

                <div class="field field-full"><label for="pickup_note">Extra boodschap <span>(optioneel)</span></label><textarea id="pickup_note" name="pickup_note" rows="4" maxlength="500" placeholder="Bijvoorbeeld: Vraag aan de kassa naar je bestelling."></textarea><small class="counter"><span id="noteCount">0</span>/500</small></div>
            </div>
            <div class="actions">
                <button type="button" class="button button-secondary" id="previewButton">Voorbeeld bekijken</button>
                <button type="submit" class="button button-secondary" name="mode" value="eml">Outlook .eml maken</button>
                <button type="submit" class="button button-primary" name="mode" value="graph">Mail direct versturen</button>
            </div>
        </form>
    </section>
</main>

<dialog id="previewDialog">
    <div class="dialog-header"><div><p class="eyebrow">Voorbeeld</p><h2>Collect & Go-mail</h2></div><button type="button" class="icon-button" id="closePreview" aria-label="Voorbeeld sluiten">×</button></div>
    <div id="previewContent" class="preview-content"></div>
</dialog>
<script src="assets/duplicate-check.js?v=20260825-1" defer></script>
<script src="assets/collect-go.js?v=20260825-1" defer></script>
</body>
</html>
