<?php

declare(strict_types=1);

/** Return only finite, non-negative measurements; never expose raw diagnostics. */
function hosting_diagnostics_number(mixed $value): ?float
{
    if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0) {
        return null;
    }

    return (float) $value;
}

function hosting_diagnostics_mib(mixed $bytes): ?float
{
    $number = hosting_diagnostics_number($bytes);
    return $number === null ? null : round($number / 1048576, 2);
}

function hosting_diagnostics_percent(mixed $value): ?float
{
    $number = hosting_diagnostics_number($value);
    return $number === null || $number > 100 ? null : round($number, 3);
}

function hosting_diagnostics_configured_enabled(mixed $value): ?bool
{
    if (!is_string($value)) {
        return null;
    }

    return match (strtolower(trim($value))) {
        '1', 'on', 'true', 'yes' => true,
        '', '0', 'off', 'false', 'no' => false,
        default => null,
    };
}

/** An allowlist prevents script paths, configuration values and other raw data leaking. */
function hosting_diagnostics_opcache_summary(
    bool $extensionLoaded,
    bool $statusFunctionAvailable,
    mixed $rawStatus,
    bool $readFailed = false,
    ?bool $configuredEnabled = null
): array {
    $readable = $statusFunctionAvailable && !$readFailed && is_array($rawStatus);
    $status = $readable ? $rawStatus : [];
    $enabled = is_bool($status['opcache_enabled'] ?? null) ? $status['opcache_enabled'] : null;
    $memory = is_array($status['memory_usage'] ?? null) ? $status['memory_usage'] : [];
    $statistics = is_array($status['opcache_statistics'] ?? null) ? $status['opcache_statistics'] : [];

    return [
        'extension_loaded' => $extensionLoaded,
        'status_function_available' => $statusFunctionAvailable,
        'configured_enabled' => $configuredEnabled,
        'status_readable' => $readable,
        'enabled' => $enabled,
        'status' => $enabled === null ? 'unavailable' : ($enabled ? 'enabled' : 'disabled'),
        'memory' => [
            'used_mib' => hosting_diagnostics_mib($memory['used_memory'] ?? null),
            'free_mib' => hosting_diagnostics_mib($memory['free_memory'] ?? null),
            'wasted_mib' => hosting_diagnostics_mib($memory['wasted_memory'] ?? null),
            'wasted_percent' => hosting_diagnostics_percent($memory['current_wasted_percentage'] ?? null),
        ],
        'statistics' => [
            'hit_rate_percent' => hosting_diagnostics_percent($statistics['opcache_hit_rate'] ?? null),
            'cached_scripts' => hosting_diagnostics_number($statistics['num_cached_scripts'] ?? null),
            'hits' => hosting_diagnostics_number($statistics['hits'] ?? null),
            'misses' => hosting_diagnostics_number($statistics['misses'] ?? null),
            'oom_restarts' => hosting_diagnostics_number($statistics['oom_restarts'] ?? null),
            'hash_restarts' => hosting_diagnostics_number($statistics['hash_restarts'] ?? null),
            'manual_restarts' => hosting_diagnostics_number($statistics['manual_restarts'] ?? null),
        ],
        'cache_full' => is_bool($status['cache_full'] ?? null) ? $status['cache_full'] : null,
        'restart_pending' => is_bool($status['restart_pending'] ?? null) ? $status['restart_pending'] : null,
        'restart_in_progress' => is_bool($status['restart_in_progress'] ?? null) ? $status['restart_in_progress'] : null,
    ];
}

function hosting_diagnostics_load_summary(mixed $rawLoad): array
{
    $averages = ['one_minute' => null, 'five_minutes' => null, 'fifteen_minutes' => null];
    $available = is_array($rawLoad) && count($rawLoad) === 3;
    if ($available) {
        foreach (array_keys($averages) as $index => $key) {
            $value = hosting_diagnostics_number($rawLoad[$index] ?? null);
            if ($value === null) {
                $available = false;
                break;
            }
            $averages[$key] = round($value, 3);
        }
    }

    return [
        'available' => $available,
        'scope' => 'host_or_container_not_hosting_account',
        'averages' => $available ? $averages : ['one_minute' => null, 'five_minutes' => null, 'fifteen_minutes' => null],
    ];
}

/** Restricted or disabled hosting functions may warn or throw. Neither detail is public. */
function hosting_diagnostics_read(callable $reader): array
{
    $warning = false;
    set_error_handler(static function () use (&$warning): bool {
        $warning = true;
        return true;
    });

    try {
        $value = $reader();
        return ['ok' => !$warning, 'value' => $warning ? null : $value];
    } catch (Throwable $error) {
        return ['ok' => false, 'value' => null];
    } finally {
        restore_error_handler();
    }
}

/** Read-only: no database connection, filesystem scan, cache reset or configuration changes. */
function hosting_diagnostics_collect(float $scriptStartedAt, float $bootstrapAndSessionMs): array
{
    $extensionLoaded = extension_loaded('Zend OPcache') || extension_loaded('opcache');
    $statusAvailable = function_exists('opcache_get_status');
    $status = $statusAvailable
        ? hosting_diagnostics_read(static fn () => opcache_get_status(false))
        : ['ok' => false, 'value' => null];
    $configuration = function_exists('ini_get')
        ? hosting_diagnostics_read(static fn () => ini_get('opcache.enable'))
        : ['ok' => false, 'value' => null];
    $load = function_exists('sys_getloadavg')
        ? hosting_diagnostics_read(static fn () => sys_getloadavg())
        : ['ok' => false, 'value' => null];

    return [
        'schema_version' => 1,
        'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'php' => ['version' => PHP_VERSION, 'sapi' => PHP_SAPI],
        'request' => [
            'bootstrap_and_session_ms' => round(max(0, $bootstrapAndSessionMs), 3),
            'endpoint_until_sample_ms' => round(max(0, (microtime(true) - $scriptStartedAt) * 1000), 3),
            'memory_used_mib' => hosting_diagnostics_mib(memory_get_usage(true)),
            'memory_peak_mib' => hosting_diagnostics_mib(memory_get_peak_usage(true)),
        ],
        'opcache' => hosting_diagnostics_opcache_summary(
            $extensionLoaded,
            $statusAvailable,
            $status['value'],
            !$status['ok'],
            hosting_diagnostics_configured_enabled($configuration['value'])
        ),
        'host_load' => hosting_diagnostics_load_summary($load['value']),
        'limitations' => [
            'Timings cover this PHP endpoint until sampling, not network time, PHP-FPM queue time or other pages.',
            'Bootstrap time includes session startup and any waiting for this session; those phases are not measured separately.',
            'Request memory is PHP memory for this request, not host RAM or hosting-account RAM.',
            'Load averages concern the host or container, not CPU percentages or this hosting account; capacity is unknown.',
            'OPcache statistics may be shared across sites in the same PHP pool and are cumulative, not page-speed benchmarks.',
            'A restricted or unreadable OPcache status is unknown, not evidence that OPcache is disabled.',
            'Hosting-account CPU, RAM, throttling, I/O limits and PHP-FPM workers or queues require hosting-provider metrics.',
        ],
    ];
}
