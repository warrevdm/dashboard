<?php

declare(strict_types=1);

require __DIR__ . '/src/auth.php';
$admin = authRequireAdmin();
$pdo = authDb();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!authVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Sessie verlopen. Herlaad de pagina.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'create') {
                $name = trim((string) ($_POST['name'] ?? ''));
                $email = trim((string) ($_POST['email'] ?? ''));
                $password = (string) ($_POST['password'] ?? '');
                if ($name === '' || mb_strlen($name) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Vul een geldige naam en e-mailadres in.');
                }
                if (($passwordError = authValidatePassword($password)) !== null) {
                    throw new RuntimeException($passwordError);
                }
                $now = authNow();
                $stmt = $pdo->prepare('INSERT INTO users (name,email,password_hash,role,active,created_at,updated_at,password_changed_at) VALUES (?,?,?,?,1,?,?,?)');
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'user', $now, $now, $now]);
                $target = (int) $pdo->lastInsertId();
                authAudit('user_created', $target, (int) $admin['id']);
                $message = 'Gebruiker aangemaakt.';
            } elseif ($action === 'toggle') {
                $target = (int) ($_POST['user_id'] ?? 0);
                $stmt = $pdo->prepare('SELECT id,role,active FROM users WHERE id = ?');
                $stmt->execute([$target]);
                $user = $stmt->fetch();
                if (!$user || $user['role'] === 'admin' || $target === (int) $admin['id']) {
                    throw new RuntimeException('Deze gebruiker kan niet worden aangepast.');
                }
                $newActive = (int) $user['active'] === 1 ? 0 : 1;
                $pdo->prepare('UPDATE users SET active = ?, updated_at = ? WHERE id = ?')->execute([$newActive, authNow(), $target]);
                authAudit($newActive ? 'user_enabled' : 'user_disabled', $target, (int) $admin['id']);
                $message = $newActive ? 'Gebruiker geactiveerd.' : 'Gebruiker gedeactiveerd.';
            } elseif ($action === 'reset_password') {
                $target = (int) ($_POST['user_id'] ?? 0);
                $password = (string) ($_POST['new_password'] ?? '');
                if (($passwordError = authValidatePassword($password)) !== null) {
                    throw new RuntimeException($passwordError);
                }
                $stmt = $pdo->prepare('SELECT id,role FROM users WHERE id = ?');
                $stmt->execute([$target]);
                $user = $stmt->fetch();
                if (!$user || $user['role'] === 'admin') {
                    throw new RuntimeException('Ongeldige gebruiker.');
                }
                $now = authNow();
                $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = ?, password_changed_at = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $now, $now, $target]);
                authAudit('user_password_reset', $target, (int) $admin['id']);
                $message = 'Wachtwoord gewijzigd.';
            } elseif ($action === 'change_admin_password') {
                $current = (string) ($_POST['current_password'] ?? '');
                $new = (string) ($_POST['new_password'] ?? '');
                $confirm = (string) ($_POST['new_password_confirm'] ?? '');
                $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
                $stmt->execute([(int) $admin['id']]);
                $hash = (string) $stmt->fetchColumn();
                if (!password_verify($current, $hash)) {
                    throw new RuntimeException('Huidig wachtwoord is niet correct.');
                }
                if ($new !== $confirm) {
                    throw new RuntimeException('Nieuwe wachtwoorden komen niet overeen.');
                }
                if (($passwordError = authValidatePassword($new)) !== null) {
                    throw new RuntimeException($passwordError);
                }
                $now = authNow();
                $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = ?, password_changed_at = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $now, $now, (int) $admin['id']]);
                authAudit('admin_password_changed', (int) $admin['id'], (int) $admin['id']);
                session_regenerate_id(true);
                $message = 'Adminwachtwoord gewijzigd.';
            }
            authRotateCsrf();
        } catch (PDOException $e) {
            $error = str_contains(strtolower($e->getMessage()), 'unique') ? 'Dit e-mailadres bestaat al.' : 'Databasefout bij gebruikersbeheer.';
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

$users = $pdo->query("SELECT u.id,u.name,u.email,u.role,u.active,u.created_at,u.last_login_at,
    SUM(CASE WHEN ml.status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
    SUM(CASE WHEN ml.status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
    SUM(CASE WHEN ml.status = 'eml_created' THEN 1 ELSE 0 END) AS eml_count
    FROM users u
    LEFT JOIN mail_log ml ON ml.user_id = u.id
    GROUP BY u.id,u.name,u.email,u.role,u.active,u.created_at,u.last_login_at
    ORDER BY u.role ASC, u.name ASC")->fetchAll();

$mailStats = $pdo->query("SELECT
    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
    SUM(CASE WHEN status = 'eml_created' THEN 1 ELSE 0 END) AS eml_count,
    COUNT(*) AS total_count
    FROM mail_log")->fetch() ?: ['sent_count' => 0, 'failed_count' => 0, 'eml_count' => 0, 'total_count' => 0];

$mailHistory = $pdo->query("SELECT ml.id,ml.customer_name,ml.customer_email,ml.bike_type,ml.pickup_note,ml.subject,ml.mode,ml.status,ml.error_message,ml.created_at,
    COALESCE(u.name, 'Onbekende gebruiker') AS user_name,
    COALESCE(u.email, '') AS user_email
    FROM mail_log ml
    LEFT JOIN users u ON u.id = ml.user_id
    ORDER BY ml.id DESC
    LIMIT 250")->fetchAll();
?>
<!doctype html>
<html lang="nl-BE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Beheer | Aerts Action Bike</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="admin-shell">
    <header class="topbar">
        <div class="topbar-brand"><img src="assets/aab-logo.svg" alt="Aerts Action Bike"><div><strong>Admin dashboard</strong><small><?= htmlspecialchars((string) $admin['name'], ENT_QUOTES, 'UTF-8') ?> · Admin</small></div></div>
        <nav><a class="button button-secondary" href="index.php">Mailingtool</a><a class="button button-secondary" href="logout.php">Uitloggen</a></nav>
    </header>

    <?php if ($message !== ''): ?><div class="alert success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <section class="mail-stats-grid">
        <article class="stat-card"><span>Succesvol verstuurd</span><strong><?= (int) ($mailStats['sent_count'] ?? 0) ?></strong></article>
        <article class="stat-card"><span>Mislukt</span><strong><?= (int) ($mailStats['failed_count'] ?? 0) ?></strong></article>
        <article class="stat-card"><span>EML aangemaakt</span><strong><?= (int) ($mailStats['eml_count'] ?? 0) ?></strong></article>
        <article class="stat-card"><span>Totaal gelogd</span><strong><?= (int) ($mailStats['total_count'] ?? 0) ?></strong></article>
    </section>

    <section class="admin-grid">
        <article class="panel form-panel">
            <p class="eyebrow">Nieuwe gebruiker</p>
            <h2>Account toevoegen</h2>
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(authCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="create">
                <div class="field"><label>Naam</label><input name="name" type="text" maxlength="100" required></div>
                <div class="field"><label>E-mailadres</label><input name="email" type="email" maxlength="190" required></div>
                <div class="field"><label>Tijdelijk wachtwoord</label><input name="password" type="password" minlength="14" maxlength="128" required autocomplete="new-password"><small>Minstens 14 tekens. Deel dit apart met de gebruiker.</small></div>
                <button class="button button-primary auth-submit" type="submit">Gebruiker aanmaken</button>
            </form>
        </article>

        <article class="panel form-panel">
            <p class="eyebrow">Adminbeveiliging</p>
            <h2>Adminwachtwoord wijzigen</h2>
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(authCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="change_admin_password">
                <div class="field"><label>Huidig wachtwoord</label><input name="current_password" type="password" required autocomplete="current-password"></div>
                <div class="field"><label>Nieuw wachtwoord</label><input name="new_password" type="password" minlength="14" maxlength="128" required autocomplete="new-password"></div>
                <div class="field"><label>Nieuw wachtwoord herhalen</label><input name="new_password_confirm" type="password" minlength="14" maxlength="128" required autocomplete="new-password"></div>
                <button class="button button-secondary auth-submit" type="submit">Wachtwoord wijzigen</button>
            </form>
        </article>
    </section>

    <section class="panel users-panel">
        <div class="users-heading"><div><p class="eyebrow">Accounts</p><h2>Gebruikers & mailvolume</h2></div><span><?= count($users) ?> accounts</span></div>
        <div class="table-wrap">
            <table class="users-table">
                <thead><tr><th>Naam</th><th>E-mail</th><th>Rol</th><th>Status</th><th>Verstuurd</th><th>Mislukt</th><th>EML</th><th>Laatste login</th><th>Acties</th></tr></thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $user['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $user['email'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $user['role'] === 'admin' ? 'Admin' : 'Gebruiker' ?></td>
                        <td><span class="status-pill <?= (int) $user['active'] === 1 ? 'active' : 'inactive' ?>"><?= (int) $user['active'] === 1 ? 'Actief' : 'Geblokkeerd' ?></span></td>
                        <td><strong><?= (int) ($user['sent_count'] ?? 0) ?></strong></td>
                        <td><?= (int) ($user['failed_count'] ?? 0) ?></td>
                        <td><?= (int) ($user['eml_count'] ?? 0) ?></td>
                        <td><?= $user['last_login_at'] ? htmlspecialchars((string) $user['last_login_at'], ENT_QUOTES, 'UTF-8') : 'Nog niet' ?></td>
                        <td>
                            <?php if ($user['role'] !== 'admin'): ?>
                                <div class="user-actions">
                                    <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(authCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><button class="button button-secondary" type="submit"><?= (int) $user['active'] === 1 ? 'Blokkeren' : 'Activeren' ?></button></form>
                                    <form method="post" class="reset-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(authCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><input name="new_password" type="password" minlength="14" maxlength="128" placeholder="Nieuw wachtwoord" required><button class="button button-secondary" type="submit">Reset</button></form>
                                </div>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel users-panel mail-history-panel">
        <div class="users-heading"><div><p class="eyebrow">Controle & opvolging</p><h2>Mailhistoriek</h2></div><span>Laatste <?= count($mailHistory) ?> registraties</span></div>
        <p class="history-note">Hier zie je wat vanuit de tool werd verstuurd of geprobeerd. Gebruik “Details” om de ingevulde boodschap en eventuele foutmelding na te kijken.</p>
        <div class="table-wrap">
            <table class="users-table mail-history-table">
                <thead><tr><th>Tijdstip</th><th>Gebruiker</th><th>Klant</th><th>Ontvanger</th><th>Fiets</th><th>Status</th><th>Details</th></tr></thead>
                <tbody>
                <?php if (!$mailHistory): ?>
                    <tr><td colspan="7">Er zijn nog geen mails geregistreerd.</td></tr>
                <?php endif; ?>
                <?php foreach ($mailHistory as $mail): ?>
                    <?php
                    $statusClass = $mail['status'] === 'sent' ? 'active' : ($mail['status'] === 'failed' ? 'inactive' : 'neutral');
                    $statusLabel = $mail['status'] === 'sent' ? 'Verstuurd' : ($mail['status'] === 'failed' ? 'Mislukt' : 'EML gemaakt');
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $mail['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><strong><?= htmlspecialchars((string) $mail['user_name'], ENT_QUOTES, 'UTF-8') ?></strong><?php if ($mail['user_email'] !== ''): ?><br><small><?= htmlspecialchars((string) $mail['user_email'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?></td>
                        <td><?= htmlspecialchars((string) $mail['customer_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $mail['customer_email'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $mail['bike_type'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="status-pill <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                        <td>
                            <details class="mail-details">
                                <summary>Details</summary>
                                <div><strong>Onderwerp</strong><p><?= htmlspecialchars((string) $mail['subject'], ENT_QUOTES, 'UTF-8') ?></p></div>
                                <div><strong>Verzendwijze</strong><p><?= $mail['mode'] === 'graph' ? 'Microsoft 365' : 'Outlook .eml' ?></p></div>
                                <div><strong>Extra boodschap</strong><p><?= $mail['pickup_note'] !== '' ? nl2br(htmlspecialchars((string) $mail['pickup_note'], ENT_QUOTES, 'UTF-8')) : 'Geen extra boodschap.' ?></p></div>
                                <?php if ($mail['error_message']): ?><div class="mail-error"><strong>Foutmelding</strong><p><?= htmlspecialchars((string) $mail['error_message'], ENT_QUOTES, 'UTF-8') ?></p></div><?php endif; ?>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
