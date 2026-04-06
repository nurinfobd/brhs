<?php
declare(strict_types=1);

function app_config(): array
{
    static $cfg = null;
    if (is_array($cfg)) {
        return $cfg;
    }
    $cfg = [
        'db' => [
            'host' => '127.0.0.1',
            'port' => 3307,
            'name' => 'cityuniversity',
            'user' => 'root',
            'pass' => '',
            'charset' => 'utf8mb4',
        ],
    ];
    return $cfg;
}
