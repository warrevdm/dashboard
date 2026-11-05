<?php

declare(strict_types=1);
require_once __DIR__ . '/WeeklyMailState.php';

/** Private scheduler credential and explicit dashboard activation. */
final class WeeklyMailAutomation
{
    public static function read(string $directory): ?array
    {
        $path = $directory . '/automation.json';
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        $settings = $raw === false ? null : json_decode($raw, true);
        if (!is_array($settings) || ($settings['version'] ?? null) !== 1
            || !is_string($settings['key'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $settings['key'])
            || !is_bool($settings['enabled'] ?? null)) throw new RuntimeException('De instellingen voor de dinsdagplanning zijn onleesbaar.');
        return $settings;
    }

    public static function initialize(array $config): array
    {
        $result = (new WeeklyMailState($config['state_directory']))->locked(static function () use ($config): array {
            $settings = self::read($config['state_directory']);
            if ($settings !== null) return $settings;
            $settings = ['version'=>1, 'key'=>bin2hex(random_bytes(32)), 'enabled'=>($config['enabled'] ?? false) === true];
            self::write($config['state_directory'], $settings);
            return $settings;
        });
        if (($result['status'] ?? '') === 'busy') throw new RuntimeException('De weekmail wordt momenteel verwerkt. Probeer opnieuw.');
        return $result;
    }

    public static function setEnabled(array $config, bool $enabled): void
    {
        self::initialize($config);
        $result = (new WeeklyMailState($config['state_directory']))->locked(static function () use ($config, $enabled): array {
            $settings = self::read($config['state_directory']);
            if ($settings === null) throw new RuntimeException('De dinsdagplanning is niet voorbereid.');
            $settings['enabled'] = $enabled;
            self::write($config['state_directory'], $settings);
            return ['status'=>'saved'];
        });
        if (($result['status'] ?? '') === 'busy') throw new RuntimeException('De weekmail wordt momenteel verwerkt. Probeer opnieuw.');
    }

    public static function authorized(mixed $provided, ?array $settings): bool
    {
        return $settings !== null && is_string($provided) && preg_match('/^[a-f0-9]{64}$/D', $provided) === 1
            && hash_equals($settings['key'], $provided);
    }

    private static function write(string $directory, array $settings): void
    {
        $temporary = tempnam($directory, '.automation-');
        if ($temporary === false) throw new RuntimeException('De dinsdagplanning kon niet worden opgeslagen.');
        try {
            @chmod($temporary, 0600);
            $json = json_encode($settings, JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)
                || !rename($temporary, $directory . '/automation.json')) throw new RuntimeException('De dinsdagplanning kon niet worden opgeslagen.');
        } finally { if (is_file($temporary)) unlink($temporary); }
    }
}
