<?php
require __DIR__ . '/_lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

csrf_verify();

$routerId = trim((string)($_POST['router_id'] ?? ''));
if ($routerId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Router id is required']);
    exit;
}

$router = store_get_router($routerId);
if (!is_array($router)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Router not found']);
    exit;
}
$router = router_normalize($router);

$api = mikrotik_api_connect($router);
if ($api === null) {
    echo json_encode(['ok' => false, 'error' => 'API connection failed.']);
    exit;
}

try {
    $rows = $api->comm('/interface/print', ['.proplist' => 'name,type,running,disabled']);
    $interfaces = [];
    foreach ($rows as $r) {
        $name = (string)($r['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $interfaces[] = [
            'name' => $name,
            'type' => (string)($r['type'] ?? ''),
            'running' => (string)($r['running'] ?? ''),
            'disabled' => (string)($r['disabled'] ?? ''),
        ];
    }
    usort($interfaces, fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));

    $monitorItems = store_get_router_monitor_interfaces((string)$router['id']);

    echo json_encode([
        'ok' => true,
        'interfaces' => $interfaces,
        'current' => [
            'monitor_interface' => (string)$router['monitor_interface'],
            'monitor_capacity_mbps' => (int)$router['monitor_capacity_mbps'],
        ],
        'monitor_items' => $monitorItems,
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Unable to load interfaces.']);
} finally {
    $api->disconnect();
}
