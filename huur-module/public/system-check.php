<?php

declare(strict_types=1);

$diagnosticsStartedAt = microtime(true);

// Keep authentication failures private too, including redirects during bootstrap.
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('Expires: 0');
// session_start() may replace cache headers before an early bootstrap redirect.
header_register_callback(static function (): void {
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
    header('Expires: 0');
});
require_once __DIR__ . '/../app/bootstrap.php';
$diagnosticsBootstrapMs = (microtime(true) - $diagnosticsStartedAt) * 1000;
header('Cache-Control: no-store, private');
require_admin();

$diagnosticsMethod = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($diagnosticsMethod, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit('Deze controle is alleen-lezen. Open de pagina met GET.');
}

require_once __DIR__ . '/../app/diagnostics.php';
$diagnosticsJson = ($_GET['format'] ?? '') === 'json';
$diagnosticsHeader = '';
if (!$diagnosticsJson && $diagnosticsMethod !== 'HEAD') {
    // The normal header creates a logout token and consumes flashes. Save those first.
    ob_start();
    render_header('Hostingcontrole');
    $diagnosticsHeader = (string) ob_get_clean();
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$diagnostics = hosting_diagnostics_collect($diagnosticsStartedAt, $diagnosticsBootstrapMs);
header('Server-Timing: php;dur=' . number_format($diagnostics['request']['endpoint_until_sample_ms'], 3, '.', '')
    . ';desc="PHP endpoint until sample"');
if ($diagnosticsJson) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="aab-hostingcontrole.json"');
    if ($diagnosticsMethod !== 'HEAD') {
        echo json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
if ($diagnosticsMethod === 'HEAD') {
    exit;
}

echo $diagnosticsHeader;
$diagnosticsDisplay = static function (mixed $value, string $unit = ''): string {
    if ($value === null) {
        return 'Onbekend / niet beschikbaar';
    }
    if (is_bool($value)) {
        return $value ? 'Ja' : 'Nee';
    }
    if (is_float($value)) {
        $value = rtrim(rtrim(number_format($value, 3, ',', '.'), '0'), ',');
    }
    return e((string) $value . $unit);
};
$diagnosticsOpcache = $diagnostics['opcache'];
?>
<section class="card">
    <p>Deze controle leest de PHP-instellingen en beschikbare metingen van de hosting. Er worden geen instellingen, reservaties of databases gewijzigd.</p>
    <p>Meting: <?= e($diagnostics['generated_at_utc']) ?> (UTC). <a class="button" href="system-check.php?format=json">Download meetrapport (JSON)</a></p>
    <p>De download maakt een nieuwe meting en bevat geen wachtwoorden, bestandslocaties of klantgegevens.</p>
</section>

<section class="card">
    <h2>PHP en dit verzoek</h2>
    <table>
        <thead><tr><th>Meting</th><th>Waarde</th></tr></thead>
        <tbody>
            <tr><td>PHP-versie</td><td><?= e($diagnostics['php']['version']) ?></td></tr>
            <tr><td>PHP-uitvoering (SAPI)</td><td><?= e($diagnostics['php']['sapi']) ?></td></tr>
            <tr><td>Applicatie opstarten, inclusief sessie openen</td><td><?= $diagnosticsDisplay($diagnostics['request']['bootstrap_and_session_ms'], ' ms') ?></td></tr>
            <tr><td>Deze PHP-controle tot het meetmoment</td><td><?= $diagnosticsDisplay($diagnostics['request']['endpoint_until_sample_ms'], ' ms') ?></td></tr>
            <tr><td>PHP-geheugen van dit verzoek</td><td><?= $diagnosticsDisplay($diagnostics['request']['memory_used_mib'], ' MiB') ?></td></tr>
            <tr><td>Piekgeheugen van dit verzoek</td><td><?= $diagnosticsDisplay($diagnostics['request']['memory_peak_mib'], ' MiB') ?></td></tr>
        </tbody>
    </table>
    <p>De opstarttijd kan wachttijd op dezelfde sessie bevatten; die wordt niet afzonderlijk gemeten. Deze tijden bevatten geen netwerkvertraging of wachtrij vóór PHP en meten niet de snelheid van andere pagina’s. Het geheugen is geen meting van het totale servergeheugen of je hostingaccount.</p>
</section>

<section class="card">
    <h2>OPcache</h2>
    <table>
        <thead><tr><th>Meting</th><th>Waarde</th></tr></thead>
        <tbody>
            <tr><td>Extensie geladen</td><td><?= $diagnosticsDisplay($diagnosticsOpcache['extension_loaded']) ?></td></tr>
            <tr><td>Statusfunctie beschikbaar</td><td><?= $diagnosticsDisplay($diagnosticsOpcache['status_function_available']) ?></td></tr>
            <tr><td>Ingeschakeld volgens instelling (opcache.enable)</td><td><?= $diagnosticsDisplay($diagnosticsOpcache['configured_enabled']) ?></td></tr>
            <tr><td>Status uitleesbaar</td><td><?= $diagnosticsDisplay($diagnosticsOpcache['status_readable']) ?></td></tr>
            <tr><td>Actief volgens runtime-status</td><td><?= $diagnosticsDisplay($diagnosticsOpcache['enabled']) ?></td></tr>
            <tr><td>Gebruikt cachegeheugen</td><td><?= $diagnosticsDisplay($diagnosticsOpcache['memory']['used_mib'], ' MiB') ?></td></tr>
            <tr><td>Vrij cachegeheugen</td><td><?= $diagnosticsDisplay($diagnosticsOpcache['memory']['free_mib'], ' MiB') ?></td></tr>
            <tr><td>Verspild cachegeheugen</td><td><?= $diagnosticsDisplay($diagnosticsOpcache['memory']['wasted_mib'], ' MiB') ?></td></tr>
            <tr><td>Cachetreffers</td><td><?= $diagnosticsDisplay($diagnosticsOpcache['statistics']['hit_rate_percent'], '%') ?></td></tr>
            <tr><td>Scripts in cache</td><td><?= $diagnosticsDisplay($diagnosticsOpcache['statistics']['cached_scripts']) ?></td></tr>
            <tr><td>Cache vol</td><td><?= $diagnosticsDisplay($diagnosticsOpcache['cache_full']) ?></td></tr>
            <tr><td>Herstart gepland / bezig</td><td><?= $diagnosticsDisplay($diagnosticsOpcache['restart_pending']) ?> / <?= $diagnosticsDisplay($diagnosticsOpcache['restart_in_progress']) ?></td></tr>
            <tr><td>Herstarts door geheugentekort</td><td><?= $diagnosticsDisplay($diagnosticsOpcache['statistics']['oom_restarts']) ?></td></tr>
            <tr><td>Herstarts door volle hashtabel</td><td><?= $diagnosticsDisplay($diagnosticsOpcache['statistics']['hash_restarts']) ?></td></tr>
            <tr><td>Handmatige herstarts</td><td><?= $diagnosticsDisplay($diagnosticsOpcache['statistics']['manual_restarts']) ?></td></tr>
        </tbody>
    </table>
    <p>Een niet-uitleesbare status betekent niet dat OPcache uitgeschakeld is. De host kan het uitlezen beperken. De tellers kunnen gedeeld zijn met andere websites in dezelfde PHP-pool en lopen op sinds de cache is gestart; het zijn geen snelheidsmetingen van deze pagina.</p>
</section>

<section class="card">
    <h2>Belasting van de host</h2>
    <table>
        <thead><tr><th>Gemiddelde belasting</th><th>Waarde</th></tr></thead>
        <tbody>
            <tr><td>Laatste minuut</td><td><?= $diagnosticsDisplay($diagnostics['host_load']['averages']['one_minute']) ?></td></tr>
            <tr><td>Laatste 5 minuten</td><td><?= $diagnosticsDisplay($diagnostics['host_load']['averages']['five_minutes']) ?></td></tr>
            <tr><td>Laatste 15 minuten</td><td><?= $diagnosticsDisplay($diagnostics['host_load']['averages']['fifteen_minutes']) ?></td></tr>
        </tbody>
    </table>
    <p>Dit zijn ruwe load averages voor de host of container. Het zijn geen CPU-percentages of metingen van jouw hostingaccount. Zonder de beschikbare capaciteit kan hieruit geen oordeel over overbelasting volgen.</p>
    <p>Voor CPU- en RAM-verbruik van je account, I/O-limieten, afremming door de provider en bezette PHP-processen of PHP-FPM-wachtrijen zijn de statistieken van je hostingprovider nodig.</p>
</section>
<?php render_footer(); ?>
