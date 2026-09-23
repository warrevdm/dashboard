<?php

final class WeeklyMailConfig
{
    public static function load(): array
    {
        return require __DIR__ . '/../config/weekly-mail.php';
    }

    public static function recipients(array $config): array
    {
        $result = [];
        foreach ((array) ($config['recipients'] ?? []) as $address) {
            if (is_string($address)) {
                $result[strtolower(trim($address))] = trim($address);
            }
        }
        return array_values($result);
    }

    public static function errors(array $config): array
    {
        $errors = [];
        if (!is_bool($config['enabled'] ?? null)) {
            $errors[] = 'Gebruik true of false om de weekmail in of uit te schakelen.';
        }
        if (!is_array($config['recipients'] ?? null)
            || count(array_filter((array) ($config['recipients'] ?? []), 'is_string')) !== count((array) ($config['recipients'] ?? []))) {
            $errors[] = 'De ontvangers moeten een lijst met e-mailadressen zijn.';
        }
        $recipients = self::recipients($config);
        if (!$recipients || count($recipients) > 20) {
            $errors[] = 'Stel één tot twintig interne ontvangers in.';
        }
        foreach ($recipients as $address) {
            if (!filter_var($address, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $address)) {
                $errors[] = 'Een ontvanger heeft geen geldig e-mailadres.';
                break;
            }
        }
        if (!filter_var($config['from_address'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Stel een geldig afzenderadres in.';
        }
        if (preg_match('/[\r\n]/', (string) ($config['from_name'] ?? ''))) {
            $errors[] = 'De afzendernaam is ongeldig.';
        }
        if (!is_int($config['send_hour'] ?? null) || $config['send_hour'] < 0 || $config['send_hour'] > 23) {
            $errors[] = 'Het verzenduur moet tussen 0 en 23 liggen.';
        }
        if (!self::validBaseUrl((string) ($config['base_url'] ?? ''))) {
            $errors[] = 'Stel een geldige HTTPS-link naar het leaseprogramma in.';
        }
        $smtp = $config['smtp'] ?? [];
        if (!is_array($smtp)) {
            $smtp = [];
        }
        if (!preg_match('/^[a-zA-Z0-9.-]+$/D', (string) ($smtp['host'] ?? ''))) {
            $errors[] = 'Stel de SMTP-server in.';
        }
        if (!in_array($smtp['encryption'] ?? '', ['tls', 'smtps'], true)) {
            $errors[] = 'Kies TLS of SMTPS voor de mailverbinding.';
        }
        if (!is_int($smtp['port'] ?? null) || $smtp['port'] < 1 || $smtp['port'] > 65535) {
            $errors[] = 'De SMTP-poort is ongeldig.';
        }
        if (($smtp['username'] ?? '') !== '' && (($smtp['password'] ?? '') === ''
            || str_contains((string) $smtp['password'], 'REPLACE_'))) {
            $errors[] = 'Het SMTP-wachtwoord ontbreekt.';
        }
        if (!is_string($config['state_directory'] ?? null) || $config['state_directory'] === '') {
            $errors[] = 'De opslagmap voor de verzendstatus ontbreekt.';
        }
        return $errors;
    }

    public static function validBaseUrl(string $url): bool
    {
        $parts = parse_url($url);
        return filter_var($url, FILTER_VALIDATE_URL) !== false && is_array($parts)
            && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment'])
            && !preg_match('/[\r\n]/', $url);
    }
}
