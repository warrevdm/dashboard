<?php

declare(strict_types=1);

require __DIR__ . '/src/auth.php';
$currentUser = authRequireLogin();
$pdo = authDb();

$search = trim((string) ($_GET['search'] ?? ''));
$params = [];
$where = "WHERE ml.status = 'sent'";

if ($search !== '') {
    $where .= " AND (ml.customer_name LIKE ? OR ml.customer_email LIKE ? OR ml.bike_type LIKE ? OR u.name LIKE ?)";
    $needle = '%' . $search . '%';
    $params = [$needle, $needle, $needle, $needle];
}

$stmt = $pdo->prepare(
    "SELECT ml.id, ml.customer_name, ml.customer_email, ml.bike_type, ml.pickup_note, ml.subject, ml.created_at,
            COALESCE(u.name, 'Onbekende gebruiker') AS sender_name
     FROM mail_log ml
     LEFT JOIN users u ON u.id = ml.user_id
     {$where}
     ORDER BY ml.created_at DESC
     LIMIT 300"
);
$stmt->execute($params);
$mails = $stmt->fetchAll();

$totalSent = (int) $pdo->query("SELECT COUNT(*) FROM mail_log WHERE status = 'sent'")->fetchColumn();
$uniqueCustomers = (int) $pdo->query("SELECT COUNT(DISTINCT lower(customer_email)) FROM mail_log WHERE status = 'sent'")->fetchColumn();
$todayStart = gmdate('Y-m-d\T00:00:00P');
$stmtToday = $pdo->prepare("SELECT COUNT(*) FROM mail_log WHERE status = 'sent' AND created_at >= ?");
$stmtToday->execute([$todayStart]);
$sentToday = (int) $stmtToday->fetchColumn();

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatDashboardDate(string $value): string
{
    try {
        $date = new DateTimeImmutable($value);
        $date = $date->setTimezone(new DateTimeZone('Europe/Brussels'));
        return $date->format('d/m/Y H:i');
    } catch (Throwable) {
        return $value;
    }
}

function dashboardMailType(string $subject): string
{
    return str_contains(mb_strtolower($subject), 'bestelling') ? 'Collect & Go' : 'Nieuwe fiets';
}
?>
<!doctype html>
<html lang="nl-BE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Communicatiedashboard | Aerts Action Bike</title>
    <link rel="stylesheet" href="assets/style.css?v=20260825-comms1">
    <link rel="stylesheet" href="assets/communication.css?v=20260825-1">
</head>
<body>
<header class="topbar app-topbar" style="grid-template-columns:1fr auto;">
    <div class="topbar-brand">
        <img src="assets/aab-logo.svg" alt="Aerts Action Bike">
        <div><strong>Communicatiedashboard</strong><small><?= h((string) $currentUser['name']) ?></small></div>
    </div>

    <nav class="topbar-actions">
        <?php if (($currentUser['role'] ?? '') === 'admin'): ?><a class="button button-secondary" href="admin.php">Gebruikersbeheer</a><?php endif; ?>
        <a class="button button-secondary" href="logout.php">Uitloggen</a>
    </nav>
</header>

<nav class="topbar-systems" aria-label="Mailingsystemen" style="width:min(1120px,calc(100% - 32px));margin:12px auto 0;justify-content:center;">
    <a class="topbar-system system-bike" href="index.php">
        <span class="system-icon" aria-hidden="true">🚲</span>
        <span class="system-copy"><strong>Nieuwe fiets</strong><small>Afhaling met afspraak</small></span>
    </a>
    <a class="topbar-system system-collect" href="collect-go.php">
        <span class="system-icon" aria-hidden="true">📦</span>
        <span class="system-copy"><strong>Collect & Go</strong><small>Product ophalen zonder afspraak</small></span>
    </a>
</nav>

<main class="communication-shell">
    <section class="communication-hero">
        <div>
            <p class="eyebrow">Interne communicatie</p>
            <h1>Wie kreeg al een mail?</h1>
            <p>Controleer eerdere communicatie vóór je een nieuwe afhaalmail verstuurt.</p>
        </div>
        <form class="communication-search" method="get">
            <label for="search">Zoek klant, e-mail, product of medewerker</label>
            <div>
                <input id="search" name="search" type="search" value="<?= h($search) ?>" placeholder="Bijvoorbeeld: klant@mail.be of Trek Madone">
                <button class="button button-primary" type="submit">Zoeken</button>
                <?php if ($search !== ''): ?><a class="button button-secondary" href="communication-dashboard.php">Wissen</a><?php endif; ?>
            </div>
        </form>
    </section>

    <section class="communication-stats">
        <article><span>Totaal verzonden</span><strong><?= $totalSent ?></strong></article>
        <article><span>Unieke klanten</span><strong><?= $uniqueCustomers ?></strong></article>
        <article><span>Vandaag verzonden</span><strong><?= $sentToday ?></strong></article>
        <article><span>Getoonde resultaten</span><strong><?= count($mails) ?></strong></article>
    </section>

    <section class="panel communication-panel">
        <div class="communication-heading">
            <div><p class="eyebrow">Historiek</p><h2>Verzonden mails</h2></div>
            <span>Laatste 300 resultaten</span>
        </div>

        <?php if (!$mails): ?>
            <div class="communication-empty">Geen verzonden mails gevonden voor deze zoekopdracht.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="communication-table">
                    <thead>
                        <tr><th>Datum</th><th>Klant</th><th>Product / fiets</th><th>Type</th><th>Verzonden door</th><th>Extra info</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($mails as $mail): ?>
                        <tr>
                            <td><?= h(formatDashboardDate((string) $mail['created_at'])) ?></td>
                            <td><strong><?= h((string) $mail['customer_name']) ?></strong><small><?= h((string) $mail['customer_email']) ?></small></td>
                            <td><?= h((string) $mail['bike_type']) ?></td>
                            <td><span class="communication-type <?= dashboardMailType((string) $mail['subject']) === 'Collect & Go' ? 'collect' : 'bike' ?>"><?= h(dashboardMailType((string) $mail['subject'])) ?></span></td>
                            <td><?= h((string) $mail['sender_name']) ?></td>
                            <td>
                                <?php if (trim((string) $mail['pickup_note']) !== ''): ?>
                                    <details><summary>Bekijken</summary><p><?= nl2br(h((string) $mail['pickup_note'])) ?></p></details>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
