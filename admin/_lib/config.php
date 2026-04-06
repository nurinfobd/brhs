<?php
declare(strict_types=1);

function app_env_first(array $keys): ?string
{
    foreach ($keys as $k) {
        if (!is_string($k) || $k === '') {
            continue;
        }
        $v = getenv($k);
        if (is_string($v) && $v !== '') {
            return $v;
        }
    }
    return null;
}

function app_config(): array
{
    static $cfg = null;
    if (is_array($cfg)) {
        return $cfg;
    }
    $envHost = app_env_first(['CITYU_DB_HOST', 'brhs_DB_HOST']);
    $envPort = app_env_first(['CITYU_DB_PORT', 'brhs_DB_PORT']);
    $envName = app_env_first(['CITYU_DB_NAME', 'brhs_DB_NAME']);
    $envUser = app_env_first(['CITYU_DB_USER', 'brhs_DB_USER']);
    $envPass = app_env_first(['CITYU_DB_PASS', 'brhs_DB_PASS']);
    $cfg = [
        'db' => [
            'host' => is_string($envHost) ? $envHost : '127.0.0.1',
            'port' => is_string($envPort) && ctype_digit($envPort) ? (int)$envPort : 3307,
            'name' => is_string($envName) ? $envName : 'cityuniversity',
            'user' => is_string($envUser) ? $envUser : 'root',
            'pass' => is_string($envPass) ? $envPass : '',
            'charset' => 'utf8mb4',
        ],
    ];
    return $cfg;
}
