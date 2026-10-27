<?php

require_once __DIR__ . '/../app/Database.php';
require_once __DIR__ . '/../app/ExpiringContracts.php';

$pdo = Database::connect();

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function dateStatusDays(?string $dateValue): ?int
{
    if (!$dateValue) {
        return null;
    }

    $zone = new DateTimeZone('Europe/Brussels');
    $today = new DateTimeImmutable('today', $zone);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue, $zone);

    if (!$date || $date->format('Y-m-d') !== $dateValue) {
        return null;
    }

    return (int) $today->diff($date)->format('%r%a');
}

function threeMonthWindowDays(): int
{
    static $days = null;
    if ($days === null) {
        [$start, $end] = ExpiringContracts::window(new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels')));
        $days = (int) $start->diff($end)->days;
    }
    return $days;
}

function contractStatusIcon(array $order): array
{
    $leaseEndDate = $order['lease_end_date'] ?? null;
    $leasePartner = strtolower((string) ($order['lease_partner'] ?? ''));
    $frameNumber = trim((string) ($order['frame_number'] ?? ''));
    $bikeName = trim((string) ($order['bike_name'] ?? ''));

    if (!$leaseEndDate) {
        if ($leasePartner === 'o2o' && $frameNumber !== '') {
            return [
                'icon' => '🚲',
                'class' => 'icon-status-neutral',
                'label' => 'Fiets ontvangen, maar lease-start/einddatum ontbreekt. Waarschijnlijk overname/migratie vanuit Ubike.',
            ];
        }

        if ($frameNumber !== '') {
            return [
                'icon' => '🚲',
                'class' => 'icon-status-neutral',
                'label' => 'Fiets ontvangen of besteld, maar contracteinddatum ontbreekt.',
            ];
        }

        if ($bikeName !== '') {
            return [
                'icon' => '📦',
                'class' => 'icon-status-neutral',
                'label' => 'Geen einddatum en geen framenummer: fiets waarschijnlijk besteld of contract nog niet volledig actief.',
            ];
        }

        return [
            'icon' => '❔',
            'class' => 'icon-status-neutral',
            'label' => 'Contractstatus onbekend: geen einddatum beschikbaar.',
        ];
    }

    $days = dateStatusDays($leaseEndDate);

    if ($days === null) {
        return [
            'icon' => '❔',
            'class' => 'icon-status-neutral',
            'label' => 'Contractstatus onbekend.',
        ];
    }

    if ($days < 0) {
        return [
            'icon' => '🔴',
            'class' => 'icon-status-danger',
            'label' => 'Leasingcontract is verlopen.',
        ];
    }

    if ($days <= threeMonthWindowDays()) {
        return [
            'icon' => '🟠',
            'class' => 'icon-status-warning',
            'label' => 'Leasingcontract verloopt binnen 3 maanden.',
        ];
    }

    return [
        'icon' => '🟢',
        'class' => 'icon-status-success',
        'label' => 'Leasingcontract is actief.',
    ];
}

function maintenanceStatusIcon(array $order): ?array
{
    $maintenanceEndDate = $order['yearly_maintenance_end_date'] ?? null;

    if (!$maintenanceEndDate) {
        return null;
    }

    $days = dateStatusDays($maintenanceEndDate);

    if ($days === null) {
        return null;
    }

    if ($days < 0) {
        return [
            'icon' => '⚠️',
            'class' => 'icon-status-danger',
            'label' => 'Jaarlijks onderhoudscontract is verlopen.',
        ];
    }

    if ($days <= threeMonthWindowDays()) {
        return [
            'icon' => '🔧',
            'class' => 'icon-status-warning',
            'label' => 'Jaarlijks onderhoudscontract verloopt binnen 3 maanden.',
        ];
    }

    return null;
}

function queryText(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_scalar($value) ? trim((string) $value) : $default;
}

$search = queryText('search');
$leasePartner = queryText('lease_partner');
$bikeType = queryText('bike_type');
$contractStatus = queryText('contract_status');
if (!in_array($contractStatus, ['', 'active', 'expiring_soon', 'expired', 'no_end_date'], true)) $contractStatus = '';
$sort = queryText('sort', 'newest_start');
$perPage = (int) queryText('per_page', '50');
if (!in_array($perPage, [50, 100, 200], true)) $perPage = 50;
$page = max(1, (int) queryText('page', '1'));
[$windowStart, $windowEnd] = ExpiringContracts::window(new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels')));
$todayValue = $windowStart->format('Y-m-d');
$windowEndValue = $windowEnd->format('Y-m-d');

$sortOptions = [
    'newest_start' => [
        'label' => 'Nieuwste contractstart eerst',
        'sql' => 'lease_start_date IS NULL ASC, lease_start_date DESC, created_at DESC',
    ],
    'oldest_start' => [
        'label' => 'Oudste contractstart eerst',
        'sql' => 'lease_start_date IS NULL ASC, lease_start_date ASC, created_at ASC',
    ],
    'oldest_end' => [
        'label' => 'Langst verlopen / oudste einddatum eerst',
        'sql' => 'lease_end_date IS NULL ASC, lease_end_date ASC, created_at DESC',
    ],
    'newest_end' => [
        'label' => 'Nieuwste einddatum eerst',
        'sql' => 'lease_end_date IS NULL ASC, lease_end_date DESC, created_at DESC',
    ],
    'budget_high' => [
        'label' => 'Hoogste onderhoudsbudget eerst',
        'sql' => 'maintenance_budget IS NULL ASC, maintenance_budget DESC, created_at DESC',
    ],
    'budget_low' => [
        'label' => 'Laagste onderhoudsbudget eerst',
        'sql' => 'maintenance_budget IS NULL ASC, maintenance_budget ASC, created_at DESC',
    ],
    'oldest_maintenance_end' => [
        'label' => 'Oudste onderhoudseinddatum eerst',
        'sql' => 'yearly_maintenance_end_date IS NULL ASC, yearly_maintenance_end_date ASC, created_at DESC',
    ],
    'newest_maintenance_end' => [
        'label' => 'Nieuwste onderhoudseinddatum eerst',
        'sql' => 'yearly_maintenance_end_date IS NULL ASC, yearly_maintenance_end_date DESC, created_at DESC',
    ],
    'name_az' => [
        'label' => 'Naam A–Z',
        'sql' => 'customer_name IS NULL ASC, customer_name ASC, created_at DESC',
    ],
];

if (!isset($sortOptions[$sort])) {
    $sort = 'newest_start';
}

$orderBySql = $sortOptions[$sort]['sql'] . ', id ASC';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        so_number LIKE :search_so
        OR customer_name LIKE :search_name
        OR email LIKE :search_email
        OR phone LIKE :search_phone
        OR company LIKE :search_company
        OR bike_name LIKE :search_bike
        OR frame_number LIKE :search_frame
    )";

    $searchValue = '%' . $search . '%';

    $params[':search_so'] = $searchValue;
    $params[':search_name'] = $searchValue;
    $params[':search_email'] = $searchValue;
    $params[':search_phone'] = $searchValue;
    $params[':search_company'] = $searchValue;
    $params[':search_bike'] = $searchValue;
    $params[':search_frame'] = $searchValue;
}

if ($leasePartner !== '') {
    $where[] = "lease_partner = :lease_partner";
    $params[':lease_partner'] = $leasePartner;
}

if ($bikeType !== '') {
    $where[] = "bike_type = :bike_type";
    $params[':bike_type'] = $bikeType;
}

if (in_array($contractStatus, ['active', 'expiring_soon', 'expired'], true)) $params[':filter_today'] = $todayValue;
if ($contractStatus === 'expiring_soon') $params[':filter_end'] = $windowEndValue;

if ($contractStatus === 'active') {
    $where[] = "lease_end_date IS NOT NULL AND lease_end_date >= :filter_today";
}

if ($contractStatus === 'expiring_soon') {
    $where[] = "lease_end_date IS NOT NULL AND lease_end_date BETWEEN :filter_today AND :filter_end";
}

if ($contractStatus === 'expired') {
    $where[] = "lease_end_date IS NOT NULL AND lease_end_date < :filter_today";
}

if ($contractStatus === 'no_end_date') {
    $where[] = "lease_end_date IS NULL";
}

$where[] = "archived = 0";

$whereSql = '';

if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM lease_orders $whereSql");
$countStmt->execute($params);
$totalResults = (int) $countStmt->fetchColumn();
$pageCount = max(1, (int) ceil($totalResults / $perPage));
$page = min($page, $pageCount);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT * FROM lease_orders $whereSql ORDER BY $orderBySql LIMIT :result_limit OFFSET :result_offset");
foreach ($params as $key => $value) $stmt->bindValue($key, $value, PDO::PARAM_STR);
$stmt->bindValue(':result_limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':result_offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll();

$filterQuery = array_filter(['search'=>$search, 'lease_partner'=>$leasePartner, 'bike_type'=>$bikeType,
    'contract_status'=>$contractStatus, 'sort'=>$sort, 'per_page'=>$perPage], static fn($value): bool => $value !== '');
$pageUrl = static fn(int $number): string => 'index.php?' . http_build_query($filterQuery + ['page'=>$number]);

$leasePartnersStmt = $pdo->query("
    SELECT DISTINCT lease_partner
    FROM lease_orders
    WHERE archived = 0 AND lease_partner IS NOT NULL
      AND lease_partner != ''
    ORDER BY lease_partner ASC
");

$leasePartners = $leasePartnersStmt->fetchAll();

$bikeTypesStmt = $pdo->query("
    SELECT DISTINCT bike_type
    FROM lease_orders
    WHERE archived = 0 AND bike_type IS NOT NULL
      AND bike_type != ''
    ORDER BY bike_type ASC
");

$bikeTypes = $bikeTypesStmt->fetchAll();

$statsStmt = $pdo->prepare("SELECT COUNT(*) AS total_contracts,
    SUM(CASE WHEN lease_end_date BETWEEN :contract_from AND :contract_to THEN 1 ELSE 0 END) AS expiring_contracts,
    SUM(CASE WHEN yearly_maintenance_end_date BETWEEN :maintenance_from AND :maintenance_to THEN 1 ELSE 0 END) AS expiring_maintenance
    FROM lease_orders WHERE archived = 0");
$statsStmt->execute([':contract_from'=>$todayValue, ':contract_to'=>$windowEndValue,
    ':maintenance_from'=>$todayValue, ':maintenance_to'=>$windowEndValue]);
$stats = $statsStmt->fetch();
$totalContracts = (int) $stats['total_contracts'];
$expiringContracts = (int) $stats['expiring_contracts'];
$expiringMaintenance = (int) $stats['expiring_maintenance'];

$lastImportDate = $pdo->query("
    SELECT MAX(created_at)
    FROM import_batches
")->fetchColumn();

?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Lease Import Manager</title>
    <link rel="stylesheet" href="assets.css">
</head>
<body>

<header class="page-header">
    <h1>Lease Import Manager</h1>

    <?php require __DIR__ . '/partials/navigation.php'; ?>
</header>

<main>
    <section class="card">
        <h2>Alle leasingcontracten</h2>
        <p>Zoek, filter en sorteer in de geïmporteerde leasingcontracten.</p>
    </section>

    <section class="dashboard-grid">
        <article class="dashboard-card">
            <span class="dashboard-label">Totaal contracten</span>
            <strong><?= e($totalContracts) ?></strong>
            <small>Alle geïmporteerde leasingcontracten</small>
        </article>

        <article class="dashboard-card">
            <span class="dashboard-label">Contracten binnen 3 maanden</span>
            <strong><?= e($expiringContracts) ?></strong>
            <small><a href="expiring-contracts.php">Bekijk opvolglijst</a></small>
        </article>

        <article class="dashboard-card">
            <span class="dashboard-label">Onderhoud binnen 3 maanden</span>
            <strong><?= e($expiringMaintenance) ?></strong>
            <small><a href="expiring-maintenance.php">Bekijk onderhoudslijst</a></small>
        </article>

        <article class="dashboard-card">
            <span class="dashboard-label">Laatste import</span>
            <strong><?= e($lastImportDate ?: 'Nog geen import') ?></strong>
            <small>Gebaseerd op importgeschiedenis</small>
        </article>
    </section>

    <section class="card">
        <form method="GET" action="index.php" class="filter-form">
            <div class="form-grid">
                <div class="form-group">
                    <label for="search">Zoeken</label>
                    <input
                        type="text"
                        name="search"
                        id="search"
                        value="<?= e($search) ?>"
                        placeholder="Zoek op naam, SO-number, email, fiets..."
                    >
                </div>

                <div class="form-group">
                    <label for="lease_partner">Leasepartner</label>
                    <select name="lease_partner" id="lease_partner">
                        <option value="">Alle leasepartners</option>

                        <?php foreach ($leasePartners as $partner): ?>
                            <option
                                value="<?= e($partner['lease_partner']) ?>"
                                <?= $leasePartner === $partner['lease_partner'] ? 'selected' : '' ?>
                            >
                                <?= e($partner['lease_partner']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="bike_type">Fietstype</label>
                    <select name="bike_type" id="bike_type">
                        <option value="">Alle fietstypes</option>

                        <?php foreach ($bikeTypes as $type): ?>
                            <option
                                value="<?= e($type['bike_type']) ?>"
                                <?= $bikeType === $type['bike_type'] ? 'selected' : '' ?>
                            >
                                <?= e($type['bike_type']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="contract_status">Contractstatus</label>
                    <select name="contract_status" id="contract_status">
                        <option value="">Alle statussen</option>
                        <option value="active" <?= $contractStatus === 'active' ? 'selected' : '' ?>>Actief</option>
                        <option value="expiring_soon" <?= $contractStatus === 'expiring_soon' ? 'selected' : '' ?>>Verloopt binnen 3 maanden</option>
                        <option value="expired" <?= $contractStatus === 'expired' ? 'selected' : '' ?>>Verlopen</option>
                        <option value="no_end_date" <?= $contractStatus === 'no_end_date' ? 'selected' : '' ?>>Geen einddatum</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="sort">Sorteren op</label>
                    <select name="sort" id="sort">
                        <?php foreach ($sortOptions as $sortKey => $sortOption): ?>
                            <option value="<?= e($sortKey) ?>" <?= $sort === $sortKey ? 'selected' : '' ?>>
                                <?= e($sortOption['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="per_page">Contracten per pagina</label>
                    <select name="per_page" id="per_page">
                        <?php foreach ([50, 100, 200] as $size): ?><option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="filter-actions">
                <button type="submit">Filters toepassen</button>
                <a href="index.php" class="button-secondary">Filters wissen</a>

                <a
                    href="export-contracts.php?<?= e(http_build_query($filterQuery)) ?>"
                    class="button-secondary"
                >
                    Export CSV
                </a>
            </div>
        </form>
    </section>

    <section class="card result-card">
        <strong><?= e($totalResults) ?></strong> resultaat/resultaten gevonden.
        <?php if ($totalResults > 0): ?><span> Getoond: <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalResults) ?>.</span><?php endif; ?>
        <span> Gesorteerd op: <strong><?= e($sortOptions[$sort]['label']) ?></strong>.</span>
    </section>

    <?php if ($pageCount > 1): ?>
        <nav class="contract-pagination" aria-label="Pagina's leasingcontracten">
            <?php if ($page > 1): ?><a class="button-secondary" href="<?= e($pageUrl(1)) ?>">Eerste</a><a class="button-secondary" rel="prev" href="<?= e($pageUrl($page - 1)) ?>">← Vorige</a><?php endif; ?>
            <span>Pagina <?= $page ?> van <?= $pageCount ?></span>
            <?php if ($page < $pageCount): ?><a class="button-secondary" rel="next" href="<?= e($pageUrl($page + 1)) ?>">Volgende →</a><a class="button-secondary" href="<?= e($pageUrl($pageCount)) ?>">Laatste</a><?php endif; ?>
        </nav>
    <?php endif; ?>

    <section class="table-scroll-block">
        <div class="table-scroll-top" aria-hidden="true">
            <div class="table-scroll-top-inner"></div>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>SO-number</th>
                        <th>Naam</th>
                        <th>Email</th>
                        <th>Telefoon</th>
                        <th>Bedrijf</th>
                        <th>Leasepartner</th>
                        <th>Status</th>
                        <th>Fiets</th>
                        <th>Type</th>
                        <th>Framenummer</th>
                        <th>Start contract</th>
                        <th>Einde contract</th>
                        <th>Budget onderhoud</th>
                        <th>Einde onderhoud</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="15">Geen leasingcontracten gevonden met deze filters.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <?php
                                $contractIcon = contractStatusIcon($order);
                                $maintenanceIcon = maintenanceStatusIcon($order);
                            ?>

                            <td class="status-icons-cell">
                                <span
                                    class="status-icon <?= e($contractIcon['class']) ?>"
                                    title="<?= e($contractIcon['label']) ?>"
                                    aria-label="<?= e($contractIcon['label']) ?>"
                                >
                                    <?= e($contractIcon['icon']) ?>
                                </span>

                                <?php if ($maintenanceIcon): ?>
                                    <span
                                        class="status-icon <?= e($maintenanceIcon['class']) ?>"
                                        title="<?= e($maintenanceIcon['label']) ?>"
                                        aria-label="<?= e($maintenanceIcon['label']) ?>"
                                    >
                                        <?= e($maintenanceIcon['icon']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <a href="contract-detail.php?id=<?= e($order['id']) ?>">
                                    <?= e($order['so_number']) ?>
                                </a>
                            </td>
                            <td><?= e($order['customer_name']) ?></td>
                            <td><?= e($order['email']) ?></td>
                            <td><?= e($order['phone']) ?></td>
                            <td><?= e($order['company']) ?></td>
                            <td><?= e($order['lease_partner']) ?></td>
                            <td>
                                <?php if (!empty($order['order_status_raw'])): ?>
                                    <span class="status-badge <?= e($order['order_status_code'] === '5' ? 'status-success' : ($order['order_status_code'] === '3' ? 'status-warning' : 'status-neutral')) ?>">
                                        <?= e($order['order_status_raw']) ?>
                                    </span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?= e($order['bike_name']) ?></td>
                            <td><?= e($order['bike_type']) ?></td>
                            <td><?= e($order['frame_number']) ?></td>
                            <td><?= e($order['lease_start_date']) ?></td>
                            <td><?= e($order['lease_end_date']) ?></td>
                            <td>€ <?= e($order['maintenance_budget']) ?></td>
                            <td><?= e($order['yearly_maintenance_end_date']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php if ($pageCount > 1): ?>
        <nav class="contract-pagination" aria-label="Pagina's leasingcontracten">
            <?php if ($page > 1): ?><a class="button-secondary" href="<?= e($pageUrl(1)) ?>">Eerste</a><a class="button-secondary" rel="prev" href="<?= e($pageUrl($page - 1)) ?>">← Vorige</a><?php endif; ?>
            <span>Pagina <?= $page ?> van <?= $pageCount ?></span>
            <?php if ($page < $pageCount): ?><a class="button-secondary" rel="next" href="<?= e($pageUrl($page + 1)) ?>">Volgende →</a><a class="button-secondary" href="<?= e($pageUrl($pageCount)) ?>">Laatste</a><?php endif; ?>
        </nav>
    <?php endif; ?>

</main>

<script>
    const tableWrapper = document.querySelector('.table-scroll-block .table-wrapper');
    const topScroll = document.querySelector('.table-scroll-block .table-scroll-top');
    const topScrollInner = document.querySelector('.table-scroll-block .table-scroll-top-inner');

    if (tableWrapper && topScroll && topScrollInner) {
        topScrollInner.style.width = tableWrapper.scrollWidth + 'px';

        topScroll.addEventListener('scroll', () => {
            tableWrapper.scrollLeft = topScroll.scrollLeft;
        });

        tableWrapper.addEventListener('scroll', () => {
            topScroll.scrollLeft = tableWrapper.scrollLeft;
        });

        window.addEventListener('resize', () => {
            topScrollInner.style.width = tableWrapper.scrollWidth + 'px';
        });
    }
</script>
</body>
</html>
