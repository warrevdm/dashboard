<?php

declare(strict_types=1);

function require_tablet_contract_access(): void
{
    require_auth();
    $stmt = db()->prepare('SELECT role, active FROM users WHERE id = ?');
    $stmt->execute([(int) (current_user()['id'] ?? 0)]);
    $user = $stmt->fetch();
    if (!$user || !(int) $user['active'] || !in_array($user['role'], ['admin', 'staff'], true)) {
        http_response_code(403);
        exit('Geen toegang tot de ondertekenpagina.');
    }
}
