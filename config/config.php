<?php

declare(strict_types=1);

$environment = paint_string_config('APP_ENV', 'local');

return [
    'app' => [
        'environment' => $environment,
        'debug' => paint_bool_config('APP_DEBUG', false),
        'url' => paint_service_url('APP_URL', $environment, 'https://paint.elonn.local', 'https://paint.elonn.com'),
    ],
    'database' => [
        'driver' => 'mysql',
        'host' => paint_string_config('DB_HOST', '127.0.0.1'),
        'port' => paint_int_config('DB_PORT', 3306),
        'name' => paint_database_name($environment, paint_string_config('DB_DATABASE', '')),
        'username' => paint_string_config('DB_USERNAME', paint_string_config('DB_USER', 'elonn_paint')),
        'password' => paint_string_config('DB_PASSWORD', paint_string_config('DB_PASS', '')),
        'charset' => paint_string_config('DB_CHARSET', 'utf8mb4'),
    ],
    'storage_service' => [
        'base_url' => paint_service_url('STORAGE_BASE_URL', $environment, 'https://storage.elonn.local', 'https://storage.elonn.com'),
        'resource_url' => rtrim(paint_string_config('STORAGE_RESOURCE_URL', paint_service_url('STORAGE_BASE_URL', $environment, 'https://storage.elonn.local', 'https://storage.elonn.com') . '/resources'), '/'),
        'timeout_seconds' => paint_int_config('STORAGE_TIMEOUT_SECONDS', 8),
        'service_name' => paint_string_config('ELONN_STORAGE_SERVICE_CALLER', 'paint.elonn'),
        'token' => paint_string_config('ELONN_STORAGE_SERVICE_TOKEN'),
    ],
    'service_auth' => [
        'mind.elonn' => paint_string_config('ELONN_MIND_SERVICE_TOKEN'),
        'admin.elonn' => paint_string_config('ELONN_ADMIN_SERVICE_TOKEN'),
    ],
];

function paint_string_config(string $key, string $default = ''): string
{
    $value = $_SERVER[$key] ?? $_ENV[$key] ?? $default;
    return trim((string) $value);
}

function paint_int_config(string $key, int $default): int
{
    $value = $_SERVER[$key] ?? $_ENV[$key] ?? $default;
    if (!is_numeric($value)) {
        return $default;
    }

    return max(1, (int) $value);
}

function paint_bool_config(string $key, bool $default): bool
{
    $value = $_SERVER[$key] ?? $_ENV[$key] ?? $default;
    if (is_bool($value)) {
        return $value;
    }

    return filter_var($value, FILTER_VALIDATE_BOOL);
}

function paint_service_url(string $key, string $environment, string $localDefault, string $productionDefault): string
{
    $default = in_array(strtolower($environment), ['local', 'development', 'dev', 'testing'], true)
        ? $localDefault
        : $productionDefault;

    return rtrim(paint_string_config($key, $default), '/');
}

function paint_database_name(string $environment, string $fallback): string
{
    if ($fallback !== '') {
        return $fallback;
    }

    return in_array(strtolower($environment), ['local', 'development', 'dev', 'testing'], true)
        ? 'elonn_paint'
        : 'ljholida_elonn_paint';
}
