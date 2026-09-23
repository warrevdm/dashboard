<?php

// CLI only: no installation or reset endpoint is exposed on the website.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['output:', 'rotate']);
$path = $options['output'] ?? (getenv('AAB_LEASE_AUTH_FILE') ?: __DIR__ . '/../config/auth.local.php');
if (!is_string($path) || !str_ends_with($path, '.php')) {
    fwrite(STDERR, "Kies een PHP-configuratiebestand met --output=/privaat/pad/lease-auth.php.\n");
    exit(1);
}
if (file_exists($path) && !isset($options['rotate'])) {
    fwrite(STDERR, "Configuratie bestaat al. Gebruik --rotate om de toegangssleutel te vervangen en alle aanmeldingen in te trekken.\n");
    exit(1);
}

$directory = realpath(dirname($path));
if ($directory === false || !is_dir($directory)) {
    fwrite(STDERR, "De doelmap bestaat niet of is niet bereikbaar: " . dirname($path) . "\n"
        . "Controleer het pad; gebruik vanuit de projectmap eventueel --output=lease/config/auth.local.php.\n");
    exit(1);
}
$path = $directory . DIRECTORY_SEPARATOR . basename($path);
$trackedConfig = realpath(__DIR__ . '/../config');
if ($directory === $trackedConfig && basename($path) !== 'auth.local.php') {
    fwrite(STDERR, "Gebruik in config uitsluitend auth.local.php; overschrijf nooit een gevolgd configuratiebestand.\n");
    exit(1);
}

$accessKey = bin2hex(random_bytes(16));
$config = [
    'access_key_hash' => password_hash($accessKey, PASSWORD_DEFAULT),
    'cookie_secret' => bin2hex(random_bytes(32)),
];
// Preserve operational settings (e.g. private throttle storage) on rotation.
if (isset($options['rotate']) && is_file($path)) {
    $previous = require $path;
    if (is_array($previous)) {
        $config = array_replace($previous, $config);
    }
}
$contents = "<?php\n\n// Private installation credentials. Never commit this file.\nreturn " . var_export($config, true) . ";\n";
umask(0077);
// Directory metadata can report read-only on Windows while creating files is allowed.
// Try the real operation, exclusively in the requested directory; never fall back to /tmp.
$temporary = $directory . DIRECTORY_SEPARATOR . '.lease-auth-' . bin2hex(random_bytes(12));
$handle = @fopen($temporary, 'xb');
if ($handle === false) {
    fwrite(STDERR, "PHP kan geen configuratiebestand aanmaken in: " . $directory . "\n"
        . "Controleer schrijfrechten en maak een eventuele OneDrive-map lokaal beschikbaar.\n");
    exit(1);
}
$written = @fwrite($handle, $contents);
$flushed = @fflush($handle);
fclose($handle);
if ($written !== strlen($contents) || !$flushed) {
    @unlink($temporary);
    fwrite(STDERR, "Configuratie kon niet volledig worden geschreven in: " . $directory . "\n");
    exit(1);
}
@chmod($temporary, 0600);
if (!@rename($temporary, $path)) {
    @unlink($temporary);
    fwrite(STDERR, "Configuratie kon niet worden geïnstalleerd op: " . $path . "\n"
        . "Controleer schrijfrechten en of het bestand door een ander programma wordt vastgehouden.\n");
    exit(1);
}

fwrite(STDOUT, "Configuratie opgeslagen in: " . $path . "\n");
fwrite(STDOUT, "Nieuwe toegangssleutel (bewaar in je wachtwoordmanager): " . $accessKey . "\n");
fwrite(STDOUT, "Zorg dat PHP dit bestand kan lezen. Upload het uitsluitend via een beveiligde verbinding en nooit naar GitHub.\n");
