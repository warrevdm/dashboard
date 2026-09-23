<?php

/** Shared between browser sessions; updates are serialized under a file lock. */
final class LoginThrottle
{
    public static function attempt(string $accessKey, array $config): array
    {
        $directory = (string) $config['auth_storage_dir'];
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Loginbeveiliging kan niet worden opgeslagen.');
        }

        $file = @fopen($directory . '/login-attempts.json', 'c+');
        if ($file === false) {
            throw new RuntimeException('Loginbeveiliging kan niet worden geopend.');
        }
        @chmod($directory . '/login-attempts.json', 0600);

        try {
            if (!flock($file, LOCK_EX)) {
                throw new RuntimeException('Loginbeveiliging kan niet worden vergrendeld.');
            }
            $contents = stream_get_contents($file);
            $attempts = $contents === '' ? [] : json_decode($contents, true);
            if (!is_array($attempts)) {
                throw new RuntimeException('Loginbeveiliging bevat ongeldige gegevens.');
            }

            $now = time();
            foreach ($attempts as $key => $entry) {
                if ((int) ($entry['expires'] ?? 0) <= $now) {
                    unset($attempts[$key]);
                }
            }
            // Do not trust a caller-supplied X-Forwarded-For header.
            $client = hash_hmac('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $config['cookie_secret']);
            $entry = $attempts[$client] ?? ['count' => 0, 'locked_until' => 0, 'expires' => $now + 900];
            $wait = max(0, (int) $entry['locked_until'] - $now);
            $success = false;

            if ($wait === 0) {
                if (strlen($accessKey) <= 1024 && password_verify($accessKey, $config['access_key_hash'])) {
                    $success = true;
                    unset($attempts[$client]);
                } else {
                    $entry['count']++;
                    if ($entry['count'] >= max(1, (int) $config['max_attempts'])) {
                        $wait = max(30, (int) $config['lockout_seconds']);
                        $entry['locked_until'] = $now + $wait;
                        $entry['expires'] = $entry['locked_until'];
                    }
                    // Bound disk use under requests from many different addresses.
                    if (count($attempts) >= 10000 && !isset($attempts[$client])) {
                        throw new RuntimeException('Loginbeveiliging is tijdelijk bezet.');
                    }
                    $attempts[$client] = $entry;
                }
            }

            $json = json_encode($attempts, JSON_THROW_ON_ERROR);
            rewind($file);
            if (!ftruncate($file, 0) || fwrite($file, $json) !== strlen($json) || !fflush($file)) {
                throw new RuntimeException('Loginbeveiliging kan niet worden bijgewerkt.');
            }
            return ['success' => $success, 'wait' => $wait];
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}
