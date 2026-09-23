<?php

require_once __DIR__ . '/../../app/Auth.php';

Auth::requireLogin();

$currentPage = basename($_SERVER['PHP_SELF']);

function navActive(string $page, string $currentPage): string
{
    return $page === $currentPage ? 'nav-active' : '';
}

function navGroupActive(array $pages, string $currentPage): string
{
    return in_array($currentPage, $pages, true) ? 'nav-active' : '';
}

?>

<style>
    .nav-logout-form {
        display: inline-flex;
        align-items: center;
        margin: 0;
    }

    .nav-logout-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        padding: 9px 12px;
        border: 1px solid rgba(255, 255, 255, 0.18);
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.08);
        color: rgba(255, 255, 255, 0.92);
        font: inherit;
        font-weight: 800;
        line-height: 1;
        cursor: pointer;
        backdrop-filter: blur(8px);
        transition: transform 0.18s ease, background 0.18s ease, border-color 0.18s ease, color 0.18s ease, box-shadow 0.18s ease;
    }

    .nav-logout-button:hover {
        transform: translateY(-1px);
        background: #ffffff;
        border-color: #ffffff;
        color: #111827;
        box-shadow: 0 8px 22px rgba(0, 0, 0, 0.18);
    }

    .nav-logout-button:focus-visible {
        outline: 3px solid rgba(145, 189, 44, 0.45);
        outline-offset: 3px;
    }

    .nav-logout-icon {
        width: 16px;
        height: 16px;
        flex: 0 0 16px;
        color: #91bd2c;
    }

    .nav-logout-button:hover .nav-logout-icon {
        color: #6f9720;
    }

    @media (max-width: 700px) {
        .nav-logout-form {
            display: flex;
            width: 100%;
            margin-top: 4px;
        }

        .nav-logout-button {
            width: 100%;
        }
    }
</style>

<nav class="main-nav">
    <a class="<?= e(navActive('index.php', $currentPage)) ?>" href="index.php">
        Alle contracten
    </a>

    <a class="<?= e(navActive('analytics.php', $currentPage)) ?>" href="analytics.php">
        Analytics
    </a>

    <a class="<?= e(navActive('update-connectors.php', $currentPage)) ?>" href="update-connectors.php">
        Updates
    </a>

    <div class="nav-dropdown <?= e(navGroupActive([
        'upload.php',
        'import-source-o2o.php',
        'import-source-joule.php',
        'import-source-cyclobility.php',
    ], $currentPage)) ?>">
        <button type="button" class="nav-dropdown-toggle">
            Imports
            <span aria-hidden="true">▾</span>
        </button>

        <div class="nav-dropdown-menu">
            <a href="upload.php">
                Excel importeren
            </a>

            <form method="POST" action="import-source-o2o.php" class="nav-import-form">
                <?= Auth::csrfField() ?>
                <button type="submit">O2O import</button>
            </form>

            <form method="POST" action="import-source-joule.php" class="nav-import-form">
                <?= Auth::csrfField() ?>
                <button type="submit">Joule import</button>
            </form>

            <form method="POST" action="import-source-cyclobility.php" class="nav-import-form">
                <?= Auth::csrfField() ?>
                <button type="submit">Cyclobility import</button>
            </form>
        </div>
    </div>

    <a class="<?= e(navActive('import-history.php', $currentPage)) ?>" href="import-history.php">
        Importgeschiedenis
    </a>

    <a class="<?= e(navActive('import-errors.php', $currentPage)) ?>" href="import-errors.php">
        Importfouten
    </a>

    <a class="<?= e(navActive('mapping-templates.php', $currentPage)) ?>" href="mapping-templates.php">
        Mappingtemplates
    </a>

    <a class="<?= e(navActive('expiring-contracts.php', $currentPage)) ?>" href="expiring-contracts.php">
        Contracten verlopen
    </a>

    <a class="<?= e(navActive('weekly-mail.php', $currentPage)) ?>" href="weekly-mail.php">
        Dinsdagmail
    </a>

    <a class="<?= e(navActive('expiring-maintenance.php', $currentPage)) ?>" href="expiring-maintenance.php">
        Onderhoud verloopt
    </a>

    <a class="<?= e(navActive('archived-contracts.php', $currentPage)) ?>" href="archived-contracts.php">
        Archief
    </a>

    <form method="POST" action="logout.php" class="nav-logout-form">
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <button type="submit" class="nav-logout-button" aria-label="Veilig afmelden">
            <svg class="nav-logout-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M10 5H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M14 8l4 4-4 4M18 12H9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span>Afmelden</span>
        </button>
    </form>
</nav>
