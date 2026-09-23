<?php

require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(float $value): string
{
    return '€ ' . number_format($value, 2, ',', '.');
}

function monthLabel(string $monthKey): string
{
    $months = [
        '01' => 'jan',
        '02' => 'feb',
        '03' => 'mrt',
        '04' => 'apr',
        '05' => 'mei',
        '06' => 'jun',
        '07' => 'jul',
        '08' => 'aug',
        '09' => 'sep',
        '10' => 'okt',
        '11' => 'nov',
        '12' => 'dec',
    ];

    [$year, $month] = explode('-', $monthKey);

    return ($months[$month] ?? $month) . ' ' . $year;
}

$summaryStmt = $pdo->query("
    SELECT
        COUNT(*) AS total_contracts,
        SUM(CASE WHEN lease_end_date IS NOT NULL AND lease_end_date >= CURDATE() THEN 1 ELSE 0 END) AS active_contracts,
        SUM(CASE WHEN lease_end_date IS NOT NULL AND lease_end_date < CURDATE() THEN 1 ELSE 0 END) AS expired_contracts,
        SUM(CASE WHEN lease_end_date IS NULL THEN 1 ELSE 0 END) AS no_end_date_contracts,
        SUM(CASE
            WHEN lease_end_date IS NOT NULL
             AND lease_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
            THEN 1 ELSE 0
        END) AS expiring_contracts,
        SUM(CASE
            WHEN yearly_maintenance_end_date IS NOT NULL
             AND yearly_maintenance_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
            THEN 1 ELSE 0
        END) AS expiring_maintenance,
        SUM(CASE WHEN maintenance_budget IS NOT NULL AND maintenance_budget > 0 THEN 1 ELSE 0 END) AS contracts_with_budget,
        COALESCE(SUM(maintenance_budget), 0) AS total_maintenance_budget,
        COALESCE(AVG(CASE WHEN maintenance_budget IS NOT NULL AND maintenance_budget > 0 THEN maintenance_budget END), 0) AS average_maintenance_budget
    FROM lease_orders
    WHERE archived = 0
");

$summary = $summaryStmt->fetch();

$inProgress = (int) $pdo->query("
    SELECT COUNT(DISTINCT lo.id)
    FROM lease_orders lo
    INNER JOIN customer_logbook cl ON cl.lease_order_id = lo.id
    WHERE lo.archived = 0
")->fetchColumn();

$archivedContracts = (int) $pdo->query("
    SELECT COUNT(*)
    FROM lease_orders
    WHERE archived = 1
")->fetchColumn();

$partnerStatsStmt = $pdo->query("
    SELECT
        COALESCE(NULLIF(TRIM(lo.lease_partner), ''), 'Onbekend') AS lease_partner,
        COUNT(*) AS contracts,
        SUM(CASE WHEN lo.lease_end_date IS NOT NULL AND lo.lease_end_date >= CURDATE() THEN 1 ELSE 0 END) AS active_contracts,
        SUM(CASE
            WHEN lo.lease_end_date IS NOT NULL
             AND lo.lease_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
            THEN 1 ELSE 0
        END) AS expiring_contracts,
        SUM(CASE
            WHEN EXISTS (
                SELECT 1
                FROM customer_logbook cl
                WHERE cl.lease_order_id = lo.id
            )
            THEN 1 ELSE 0
        END) AS in_progress,
        COALESCE(SUM(lo.maintenance_budget), 0) AS total_budget,
        COALESCE(AVG(CASE WHEN lo.maintenance_budget IS NOT NULL AND lo.maintenance_budget > 0 THEN lo.maintenance_budget END), 0) AS average_budget
    FROM lease_orders lo
    WHERE lo.archived = 0
    GROUP BY COALESCE(NULLIF(TRIM(lo.lease_partner), ''), 'Onbekend')
    ORDER BY contracts DESC, lease_partner ASC
");

$partnerStats = $partnerStatsStmt->fetchAll();

$bikeTypeStatsStmt = $pdo->query("
    SELECT
        COALESCE(NULLIF(TRIM(bike_type), ''), 'Onbekend') AS bike_type,
        COUNT(*) AS contracts
    FROM lease_orders
    WHERE archived = 0
    GROUP BY COALESCE(NULLIF(TRIM(bike_type), ''), 'Onbekend')
    ORDER BY contracts DESC, bike_type ASC
");

$bikeTypeStats = $bikeTypeStatsStmt->fetchAll();

$contractStartStmt = $pdo->query("
    SELECT
        DATE_FORMAT(lease_start_date, '%Y-%m') AS month_key,
        COUNT(*) AS contracts
    FROM lease_orders
    WHERE archived = 0
      AND lease_start_date IS NOT NULL
      AND lease_start_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
    GROUP BY DATE_FORMAT(lease_start_date, '%Y-%m')
    ORDER BY month_key ASC
");

$contractStartsRaw = [];
foreach ($contractStartStmt->fetchAll() as $row) {
    $contractStartsRaw[$row['month_key']] = (int) $row['contracts'];
}

$contractStartTrend = [];
$startMonth = new DateTime('first day of this month');
$startMonth->modify('-11 months');

for ($i = 0; $i < 12; $i++) {
    $key = $startMonth->format('Y-m');
    $contractStartTrend[] = [
        'month' => monthLabel($key),
        'contracts' => $contractStartsRaw[$key] ?? 0,
    ];
    $startMonth->modify('+1 month');
}

$contractExpiryStmt = $pdo->query("
    SELECT
        DATE_FORMAT(lease_end_date, '%Y-%m') AS month_key,
        COUNT(*) AS contracts
    FROM lease_orders
    WHERE archived = 0
      AND lease_end_date IS NOT NULL
      AND lease_end_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
      AND lease_end_date < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(lease_end_date, '%Y-%m')
    ORDER BY month_key ASC
");

$contractExpiryRaw = [];
foreach ($contractExpiryStmt->fetchAll() as $row) {
    $contractExpiryRaw[$row['month_key']] = (int) $row['contracts'];
}

$contractExpiryTrend = [];
$expiryMonth = new DateTime('first day of this month');

for ($i = 0; $i < 12; $i++) {
    $key = $expiryMonth->format('Y-m');
    $contractExpiryTrend[] = [
        'month' => monthLabel($key),
        'contracts' => $contractExpiryRaw[$key] ?? 0,
    ];
    $expiryMonth->modify('+1 month');
}

$maintenanceExpiryStmt = $pdo->query("
    SELECT
        DATE_FORMAT(yearly_maintenance_end_date, '%Y-%m') AS month_key,
        COUNT(*) AS contracts
    FROM lease_orders
    WHERE archived = 0
      AND yearly_maintenance_end_date IS NOT NULL
      AND yearly_maintenance_end_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
      AND yearly_maintenance_end_date < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(yearly_maintenance_end_date, '%Y-%m')
    ORDER BY month_key ASC
");

$maintenanceExpiryRaw = [];
foreach ($maintenanceExpiryStmt->fetchAll() as $row) {
    $maintenanceExpiryRaw[$row['month_key']] = (int) $row['contracts'];
}

$maintenanceExpiryTrend = [];
$maintenanceMonth = new DateTime('first day of this month');

for ($i = 0; $i < 12; $i++) {
    $key = $maintenanceMonth->format('Y-m');
    $maintenanceExpiryTrend[] = [
        'month' => monthLabel($key),
        'contracts' => $maintenanceExpiryRaw[$key] ?? 0,
    ];
    $maintenanceMonth->modify('+1 month');
}

$topPartnersByContracts = array_slice($partnerStats, 0, 10);

$topPartnersByBudget = $partnerStats;
usort($topPartnersByBudget, static function (array $a, array $b): int {
    return (float) $b['total_budget'] <=> (float) $a['total_budget'];
});
$topPartnersByBudget = array_slice($topPartnersByBudget, 0, 10);

$statusData = [
    'labels' => ['Actief', 'Verlopen', 'Geen einddatum'],
    'values' => [
        (int) ($summary['active_contracts'] ?? 0),
        (int) ($summary['expired_contracts'] ?? 0),
        (int) ($summary['no_end_date_contracts'] ?? 0),
    ],
];

$chartPayload = [
    'status' => $statusData,
    'partnersByContracts' => [
        'labels' => array_column($topPartnersByContracts, 'lease_partner'),
        'values' => array_map('intval', array_column($topPartnersByContracts, 'contracts')),
    ],
    'partnersByBudget' => [
        'labels' => array_column($topPartnersByBudget, 'lease_partner'),
        'values' => array_map('floatval', array_column($topPartnersByBudget, 'total_budget')),
    ],
    'bikeTypes' => [
        'labels' => array_column($bikeTypeStats, 'bike_type'),
        'values' => array_map('intval', array_column($bikeTypeStats, 'contracts')),
    ],
    'contractStarts' => $contractStartTrend,
    'contractExpiries' => $contractExpiryTrend,
    'maintenanceExpiries' => $maintenanceExpiryTrend,
];

?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Analytics | Lease Import Manager</title>
    <link rel="stylesheet" href="assets.css">
    <style>
        .analytics-intro {
            display: flex;
            justify-content: space-between;
            gap: 24px;
            align-items: flex-start;
        }

        .analytics-intro p {
            margin-bottom: 0;
        }

        .analytics-note {
            font-size: 0.9rem;
            opacity: 0.75;
            max-width: 520px;
        }

        .analytics-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .analytics-kpi {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 18px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.05);
        }

        .analytics-kpi .label {
            display: block;
            color: #64748b;
            font-size: 0.84rem;
            margin-bottom: 8px;
        }

        .analytics-kpi strong {
            display: block;
            font-size: 1.75rem;
            line-height: 1.1;
            color: #0f172a;
        }

        .analytics-kpi small {
            display: block;
            margin-top: 8px;
            color: #64748b;
        }

        .analytics-chart-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .analytics-chart-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.05);
            min-width: 0;
        }

        .analytics-chart-card h2 {
            margin-top: 0;
            margin-bottom: 4px;
        }

        .analytics-chart-card p {
            margin-top: 0;
            color: #64748b;
            font-size: 0.9rem;
        }

        .chart-wrap {
            position: relative;
            min-height: 320px;
            height: 320px;
        }

        .analytics-table td,
        .analytics-table th {
            white-space: nowrap;
        }

        .analytics-table td:first-child,
        .analytics-table th:first-child {
            white-space: normal;
            min-width: 140px;
        }

        .metric-good {
            color: #166534;
        }

        .metric-warning {
            color: #b45309;
        }

        @media (max-width: 900px) {
            .analytics-chart-grid {
                grid-template-columns: 1fr;
            }

            .analytics-intro {
                display: block;
            }

            .analytics-note {
                margin-top: 12px;
            }
        }
    </style>
</head>
<body>

<header class="page-header">
    <h1>Analytics</h1>

    <?php require __DIR__ . '/partials/navigation.php'; ?>
</header>

<main>
    <section class="card analytics-intro">
        <div>
            <h2>Overzicht leasingportefeuille</h2>
            <p>Contracten, onderhoudsbudgetten, opvolging en evolutie op basis van de actuele database.</p>
        </div>
        <p class="analytics-note">
            Budgetten zijn de bedragen die momenteel in <code>maintenance_budget</code> geregistreerd staan. Ze worden niet geïnterpreteerd als gerealiseerde omzet of effectief verbruik.
        </p>
    </section>

    <section class="analytics-kpi-grid">
        <article class="analytics-kpi">
            <span class="label">Totaal contracten</span>
            <strong><?= e((int) ($summary['total_contracts'] ?? 0)) ?></strong>
            <small>Niet-gearchiveerde dossiers</small>
        </article>

        <article class="analytics-kpi">
            <span class="label">Actieve contracten</span>
            <strong class="metric-good"><?= e((int) ($summary['active_contracts'] ?? 0)) ?></strong>
            <small>Einddatum vandaag of later</small>
        </article>

        <article class="analytics-kpi">
            <span class="label">Contracten binnen 3 maanden</span>
            <strong class="metric-warning"><?= e((int) ($summary['expiring_contracts'] ?? 0)) ?></strong>
            <small>Opvolging contracteinde</small>
        </article>

        <article class="analytics-kpi">
            <span class="label">Onderhoud binnen 3 maanden</span>
            <strong class="metric-warning"><?= e((int) ($summary['expiring_maintenance'] ?? 0)) ?></strong>
            <small>Opvolging onderhoud</small>
        </article>

        <article class="analytics-kpi">
            <span class="label">Dossiers in behandeling</span>
            <strong><?= e($inProgress) ?></strong>
            <small>Minstens één logboekitem</small>
        </article>

        <article class="analytics-kpi">
            <span class="label">Totaal onderhoudsbudget</span>
            <strong><?= e(money((float) ($summary['total_maintenance_budget'] ?? 0))) ?></strong>
            <small>Som van geregistreerde budgetten</small>
        </article>

        <article class="analytics-kpi">
            <span class="label">Gemiddeld onderhoudsbudget</span>
            <strong><?= e(money((float) ($summary['average_maintenance_budget'] ?? 0))) ?></strong>
            <small>Alleen contracten met budget &gt; 0</small>
        </article>

        <article class="analytics-kpi">
            <span class="label">Contracten met onderhoudsbudget</span>
            <strong><?= e((int) ($summary['contracts_with_budget'] ?? 0)) ?></strong>
            <small>Budget groter dan € 0</small>
        </article>

        <article class="analytics-kpi">
            <span class="label">Gearchiveerde contracten</span>
            <strong><?= e($archivedContracts) ?></strong>
            <small>Buiten het actieve portefeuilleoverzicht</small>
        </article>
    </section>

    <section class="analytics-chart-grid">
        <article class="analytics-chart-card">
            <h2>Contractstatus</h2>
            <p>Verdeling over actief, verlopen en zonder einddatum.</p>
            <div class="chart-wrap">
                <canvas id="statusChart"></canvas>
            </div>
        </article>

        <article class="analytics-chart-card">
            <h2>Top leasepartners op aantal contracten</h2>
            <p>De tien grootste partners binnen de niet-gearchiveerde portefeuille.</p>
            <div class="chart-wrap">
                <canvas id="partnerContractsChart"></canvas>
            </div>
        </article>

        <article class="analytics-chart-card">
            <h2>Onderhoudsbudget per leasepartner</h2>
            <p>Top tien leasepartners op totaal geregistreerd onderhoudsbudget.</p>
            <div class="chart-wrap">
                <canvas id="partnerBudgetChart"></canvas>
            </div>
        </article>

        <article class="analytics-chart-card">
            <h2>Contracten per fietstype</h2>
            <p>Verdeling van de portefeuille volgens het geregistreerde fietstype.</p>
            <div class="chart-wrap">
                <canvas id="bikeTypeChart"></canvas>
            </div>
        </article>

        <article class="analytics-chart-card">
            <h2>Nieuwe contractstarts</h2>
            <p>Aantal contracten met startdatum per maand over de laatste 12 maanden.</p>
            <div class="chart-wrap">
                <canvas id="contractStartChart"></canvas>
            </div>
        </article>

        <article class="analytics-chart-card">
            <h2>Verwachte contracteindes</h2>
            <p>Aantal contracten dat per maand afloopt in de komende 12 maanden.</p>
            <div class="chart-wrap">
                <canvas id="contractExpiryChart"></canvas>
            </div>
        </article>

        <article class="analytics-chart-card">
            <h2>Verwachte onderhoudseindes</h2>
            <p>Aantal jaarlijkse onderhoudscontracten dat per maand afloopt in de komende 12 maanden.</p>
            <div class="chart-wrap">
                <canvas id="maintenanceExpiryChart"></canvas>
            </div>
        </article>
    </section>

    <section class="card">
        <h2>Leasepartners in detail</h2>
        <p>Contracten, actieve dossiers, opvolging en geregistreerde onderhoudsbudgetten per partner.</p>

        <div class="table-wrapper">
            <table class="analytics-table">
                <thead>
                    <tr>
                        <th>Leasepartner</th>
                        <th>Contracten</th>
                        <th>Actief</th>
                        <th>Binnen 3 maanden</th>
                        <th>In behandeling</th>
                        <th>Totaal budget</th>
                        <th>Gemiddeld budget</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($partnerStats as $partner): ?>
                        <tr>
                            <td><?= e($partner['lease_partner']) ?></td>
                            <td><?= e((int) $partner['contracts']) ?></td>
                            <td><?= e((int) $partner['active_contracts']) ?></td>
                            <td><?= e((int) $partner['expiring_contracts']) ?></td>
                            <td><?= e((int) $partner['in_progress']) ?></td>
                            <td><?= e(money((float) $partner['total_budget'])) ?></td>
                            <td><?= e(money((float) $partner['average_budget'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
    const analyticsData = <?= json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const palette = {
        green: '#15803d',
        lightGreen: '#86efac',
        orange: '#f59e0b',
        red: '#dc2626',
        blue: '#2563eb',
        cyan: '#0891b2',
        slate: '#64748b',
        purple: '#7c3aed',
        pink: '#db2777',
        teal: '#0f766e'
    };

    const euroFormatter = new Intl.NumberFormat('nl-BE', {
        style: 'currency',
        currency: 'EUR',
        maximumFractionDigits: 2
    });

    const integerFormatter = new Intl.NumberFormat('nl-BE', {
        maximumFractionDigits: 0
    });

    const baseOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    };

    new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',
        data: {
            labels: analyticsData.status.labels,
            datasets: [{
                data: analyticsData.status.values,
                backgroundColor: [palette.green, palette.red, palette.slate],
                borderWidth: 0
            }]
        },
        options: {
            ...baseOptions,
            cutout: '62%'
        }
    });

    new Chart(document.getElementById('partnerContractsChart'), {
        type: 'bar',
        data: {
            labels: analyticsData.partnersByContracts.labels,
            datasets: [{
                label: 'Contracten',
                data: analyticsData.partnersByContracts.values,
                backgroundColor: palette.green,
                borderRadius: 6
            }]
        },
        options: {
            ...baseOptions,
            indexAxis: 'y',
            plugins: {
                legend: { display: false }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        }
    });

    new Chart(document.getElementById('partnerBudgetChart'), {
        type: 'bar',
        data: {
            labels: analyticsData.partnersByBudget.labels,
            datasets: [{
                label: 'Onderhoudsbudget',
                data: analyticsData.partnersByBudget.values,
                backgroundColor: palette.blue,
                borderRadius: 6
            }]
        },
        options: {
            ...baseOptions,
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: context => euroFormatter.format(context.raw || 0)
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        callback: value => euroFormatter.format(value)
                    }
                }
            }
        }
    });

    const bikeColors = [
        palette.green,
        palette.blue,
        palette.orange,
        palette.purple,
        palette.cyan,
        palette.teal,
        palette.pink,
        palette.slate
    ];

    new Chart(document.getElementById('bikeTypeChart'), {
        type: 'bar',
        data: {
            labels: analyticsData.bikeTypes.labels,
            datasets: [{
                label: 'Contracten',
                data: analyticsData.bikeTypes.values,
                backgroundColor: analyticsData.bikeTypes.labels.map((_, index) => bikeColors[index % bikeColors.length]),
                borderRadius: 6
            }]
        },
        options: {
            ...baseOptions,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        }
    });

    new Chart(document.getElementById('contractStartChart'), {
        type: 'line',
        data: {
            labels: analyticsData.contractStarts.map(item => item.month),
            datasets: [{
                label: 'Nieuwe contractstarts',
                data: analyticsData.contractStarts.map(item => item.contracts),
                borderColor: palette.green,
                backgroundColor: palette.lightGreen,
                tension: 0.28,
                fill: true,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            ...baseOptions,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        }
    });

    new Chart(document.getElementById('contractExpiryChart'), {
        type: 'bar',
        data: {
            labels: analyticsData.contractExpiries.map(item => item.month),
            datasets: [{
                label: 'Contracteindes',
                data: analyticsData.contractExpiries.map(item => item.contracts),
                backgroundColor: palette.orange,
                borderRadius: 6
            }]
        },
        options: {
            ...baseOptions,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: context => integerFormatter.format(context.raw || 0) + ' contract(en)'
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        }
    });

    new Chart(document.getElementById('maintenanceExpiryChart'), {
        type: 'bar',
        data: {
            labels: analyticsData.maintenanceExpiries.map(item => item.month),
            datasets: [{
                label: 'Onderhoudseindes',
                data: analyticsData.maintenanceExpiries.map(item => item.contracts),
                backgroundColor: palette.cyan,
                borderRadius: 6
            }]
        },
        options: {
            ...baseOptions,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: context => integerFormatter.format(context.raw || 0) + ' onderhoudscontract(en)'
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        }
    });
</script>

</body>
</html>
