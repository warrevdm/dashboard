<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $relativePath = env('DB_PATH', 'storage/database.sqlite');
    $dbPath = str_starts_with((string) $relativePath, '/')
        ? (string) $relativePath
        : ROOT_PATH . '/' . $relativePath;

    $directory = dirname($dbPath);
    if (!is_dir($directory)) {
        mkdir($directory, 0770, true);
    }

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    ensure_user_role_schema($pdo);
    ensure_reservation_kind_schema($pdo);
    ensure_replacement_management_schema($pdo);

    return $pdo;
}


function ensure_reservation_kind_schema(PDO $pdo): void
{
    $readSchema = static function () use ($pdo): array {
        $sql = $pdo->query(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'reservations'"
        )->fetchColumn();
        $columns = $sql ? $pdo->query('PRAGMA table_xinfo(reservations)')->fetchAll(PDO::FETCH_ASSOC) : [];
        $hasKind = false;
        foreach ($columns as $column) {
            $hasKind = $hasKind || $column['name'] === 'rental_kind';
        }
        $hasIndex = (bool) $pdo->query(
            "SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = 'idx_reservations_rental_kind'"
        )->fetchColumn();
        return ['sql' => $sql, 'columns' => $columns, 'has_kind' => $hasKind, 'has_index' => $hasIndex];
    };
    $kindCheck = '/CHECK\s*\(\s*(?:"rental_kind"|`rental_kind`|\[rental_kind\]|rental_kind)\s+IN\s*\(\s*((?:\'[^\']*\'\s*,\s*)*\'[^\']*\')\s*\)\s*\)/i';
    $acceptsTest = static function (array $schema) use ($kindCheck): bool {
        return $schema['has_kind']
            && preg_match($kindCheck, (string) $schema['sql'], $match) === 1
            && preg_match('/(?:^|,)\s*\'test\'\s*(?:,|$)/', $match[1]) === 1;
    };
    $schema = $readSchema();
    if (!$schema['sql'] || ($acceptsTest($schema) && $schema['has_index'])) {
        return;
    }
    if ($pdo->inTransaction()) {
        throw new RuntimeException('De reservatieschemamigratie moet buiten een transactie starten.');
    }

    $foreignKeys = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn();
    $transactionStarted = false;
    $pdo->exec('PRAGMA foreign_keys = OFF');
    try {
        // Serialize schema upgrades, then re-read: another request may have finished the migration.
        $pdo->exec('BEGIN IMMEDIATE');
        $transactionStarted = true;
        $schema = $readSchema();
        if (!$schema['sql']) {
            $pdo->exec('COMMIT');
            $transactionStarted = false;
            return;
        }

        if (!$schema['has_kind']) {
            $pdo->exec(
                "ALTER TABLE reservations
                 ADD COLUMN rental_kind TEXT NOT NULL DEFAULT 'rental'
                 CHECK(rental_kind IN ('rental', 'test', 'replacement'))"
            );
            // Only classify legacy rows when the column is first introduced. An explicit later
            // edit to Huur or Test must never be undone by the old quick-registration note.
            $pdo->exec(
                "UPDATE reservations SET rental_kind = 'replacement'
                 WHERE notes LIKE 'Snelle fietsregistratie via werkplaats.%'"
            );
        } elseif (!$acceptsTest($schema)) {
            $updatedSql = preg_replace_callback(
                $kindCheck,
                static fn (array $match): string => "CHECK(rental_kind IN (" . $match[1] . ", 'test'))",
                (string) $schema['sql'],
                -1,
                $checkCount
            );
            if ($updatedSql === null || $checkCount !== 1) {
                throw new RuntimeException('De bestaande reservatie-typebeperking kan niet veilig worden gemigreerd.');
            }
            $temporaryTable = 'reservations_kind_migration';
            $temporarySql = preg_replace(
                '/\ACREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"reservations"|`reservations`|\[reservations\]|reservations)(?=\s*\()/i',
                'CREATE TABLE ' . $temporaryTable,
                $updatedSql,
                1,
                $tableCount
            );
            if ($temporarySql === null || $tableCount !== 1) {
                throw new RuntimeException('De bestaande reservatietabel kan niet veilig worden gemigreerd.');
            }
            $objects = $pdo->query(
                "SELECT sql FROM sqlite_master
                 WHERE tbl_name = 'reservations' AND type IN ('index', 'trigger') AND sql IS NOT NULL
                 ORDER BY CASE type WHEN 'index' THEN 0 ELSE 1 END, name"
            )->fetchAll(PDO::FETCH_COLUMN);
            $hasSequence = (bool) $pdo->query(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'"
            )->fetchColumn();
            $oldSequence = $hasSequence
                ? $pdo->query("SELECT seq FROM sqlite_sequence WHERE name = 'reservations'")->fetchColumn()
                : false;
            $columns = [];
            foreach ($schema['columns'] as $column) {
                // Generated columns are re-evaluated by SQLite; every stored ordinary column is copied.
                if ((int) ($column['hidden'] ?? 0) === 0) {
                    $columns[] = '"' . str_replace('"', '""', (string) $column['name']) . '"';
                }
            }
            $columnList = implode(', ', $columns);
            $pdo->exec($temporarySql);
            $pdo->exec("INSERT INTO {$temporaryTable} ({$columnList}) SELECT {$columnList} FROM reservations");
            $pdo->exec('DROP TABLE reservations');
            // Recreate under the original name, so views and triggers on other tables retain
            // their references without relying on ALTER TABLE's SQLite-version-specific rewriting.
            $pdo->exec($updatedSql);
            $pdo->exec("INSERT INTO reservations ({$columnList}) SELECT {$columnList} FROM {$temporaryTable}");
            $pdo->exec("DROP TABLE {$temporaryTable}");
            foreach ($objects as $sql) {
                $pdo->exec((string) $sql);
            }
            if ($oldSequence !== false) {
                $currentSequence = (int) $pdo->query("SELECT seq FROM sqlite_sequence WHERE name = 'reservations'")->fetchColumn();
                $sequence = max((int) $oldSequence, $currentSequence);
                $pdo->exec("DELETE FROM sqlite_sequence WHERE name = 'reservations'");
                $sequenceStmt = $pdo->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (:name, :seq)');
                $sequenceStmt->execute([':name' => 'reservations', ':seq' => $sequence]);
            }
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reservations_rental_kind ON reservations(rental_kind)');
        if ($pdo->query('PRAGMA foreign_key_check')->fetch()) {
            throw new RuntimeException('De reservatiemigratie is teruggedraaid wegens een foreign-keyfout.');
        }
        $pdo->exec('COMMIT');
        $transactionStarted = false;
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $pdo->exec('ROLLBACK');
        }
        throw $e;
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ' . ($foreignKeys ? 'ON' : 'OFF'));
    }
}

function ensure_user_role_schema(PDO $pdo): void
{
    $tableSql = $pdo->query(
        "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'users' LIMIT 1"
    )->fetchColumn();

    if (!$tableSql || str_contains((string) $tableSql, "'finance'")) {
        return;
    }

    $pdo->exec('PRAGMA foreign_keys = OFF');

    try {
        $pdo->beginTransaction();

        $pdo->exec(
            "CREATE TABLE users_role_migration (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'staff' CHECK(role IN ('admin', 'staff', 'finance')),
                active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );

        $pdo->exec(
            "INSERT INTO users_role_migration (id, name, email, password_hash, role, active, created_at)
             SELECT id, name, email, password_hash, role, active, created_at
             FROM users"
        );

        $pdo->exec('DROP TABLE users');
        $pdo->exec('ALTER TABLE users_role_migration RENAME TO users');

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
        throw $e;
    }

    $pdo->exec('PRAGMA foreign_keys = ON');

    $violations = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    if ($violations) {
        throw new RuntimeException('Gebruikersrolmigratie veroorzaakte een foreign-keyfout.');
    }
}


function ensure_replacement_management_schema(PDO $pdo): void
{
    $tableExists = (bool) $pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'reservations' LIMIT 1"
    )->fetchColumn();

    if (!$tableExists) {
        return;
    }

    $columns = [];
    foreach ($pdo->query('PRAGMA table_info(reservations)')->fetchAll() as $column) {
        $columns[(string) $column['name']] = true;
    }

    $migrations = [
        'replacement_cost_note' => 'ALTER TABLE reservations ADD COLUMN replacement_cost_note TEXT',
        'cancelled_reason' => 'ALTER TABLE reservations ADD COLUMN cancelled_reason TEXT',
        'cancelled_by' => 'ALTER TABLE reservations ADD COLUMN cancelled_by INTEGER REFERENCES users(id)',
        'cancelled_at' => 'ALTER TABLE reservations ADD COLUMN cancelled_at TEXT',
    ];

    foreach ($migrations as $column => $sql) {
        if (!isset($columns[$column])) {
            $pdo->exec($sql);
        }
    }
}
