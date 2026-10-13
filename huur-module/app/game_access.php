<?php

declare(strict_types=1);

/** Lightweight navigation check: never create or migrate the game database. */
function can_open_secret_game(): bool
{
    $userId = (int) (current_user()['id'] ?? 0);
    $path = ROOT_PATH . '/storage/private/secret-game.sqlite';
    if ($userId < 1 || !is_file($path) || !is_readable($path)) {
        return false;
    }

    try {
        $game = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 0,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
        ]);
        $players = $game->prepare('SELECT 1 FROM players WHERE id = 1 AND (warre = ? OR berten = ?)');
        $players->execute([$userId, $userId]);
        if (!$players->fetchColumn()) {
            return false;
        }
        $active = db()->prepare("SELECT 1 FROM users WHERE id = ? AND active = 1 AND role IN ('admin', 'staff')");
        $active->execute([$userId]);
        return (bool) $active->fetchColumn();
    } catch (Throwable) {
        // Missing, busy or damaged game storage must never hold up the planning.
        return false;
    }
}
