<?php
require __DIR__ . '/_lib/bootstrap.php';

require_auth();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$routerId = trim((string)($_GET['router_id'] ?? ''));
if ($routerId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'router_id is required']);
    exit;
}

$r = store_get_router($routerId);
if (!is_array($r)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Router not found']);
    exit;
}

$router = router_normalize($r);
$api = mikrotik_api_connect($router);
if ($api === null) {
    echo json_encode(['ok' => false, 'error' => 'API connection failed', 'profiles' => []]);
    exit;
}

try {
    $rows = $api->comm('/ip/hotspot/user/profile/print', ['.proplist' => 'name']);
    $names = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $names[$name] = true;
    }
    $profiles = array_keys($names);
    sort($profiles, SORT_NATURAL | SORT_FLAG_CASE);
    echo json_encode(['ok' => true, 'profiles' => $profiles]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Unable to load profiles', 'profiles' => []]);
} finally {
    $api->disconnect();
}

