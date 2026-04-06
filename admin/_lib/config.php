<?php
declare(strict_types=1);

function app_config(): array
{
    static $cfg = null;
    if (is_array($cfg)) {
        return $cfg;
    }
    $envHost = getenv('CITYU_DB_HOST');
    $envPort = getenv('CITYU_DB_PORT');
    $envName = getenv('CITYU_DB_NAME');
    $envUser = getenv('CITYU_DB_USER');
    $envPass = getenv('CITYU_DB_PASS');
    $cfg = [
        'db' => [
            'host' => is_string($envHost) && $envHost !== '' ? $envHost : '127.0.0.1',
            'port' => is_string($envPort) && ctype_digit($envPort) ? (int)$envPort : 3307,
            'name' => is_string($envName) && $envName !== '' ? $envName : 'cityuniversity',
            'user' => is_string($envUser) && $envUser !== '' ? $envUser : 'root',
            'pass' => is_string($envPass) ? $envPass : '',
            'charset' => 'utf8mb4',
        ],
    ];
    return $cfg;
}
