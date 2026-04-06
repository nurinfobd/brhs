<?php
require __DIR__ . '/_lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

csrf_verify();

$routerId = trim((string)($_POST['router_id'] ?? ''));
$stored = null;
if ($routerId !== '') {
    $r = store_get_router($routerId);
    if (is_array($r)) {
        $stored = router_normalize($r);
    }
}

$ip = trim((string)($_POST['ip'] ?? ($stored['ip'] ?? '')));
$apiPort = (int)($_POST['api_port'] ?? ($stored['api_port'] ?? 8728));
$username = trim((string)($_POST['username'] ?? ($stored['username'] ?? '')));
$password = (string)($_POST['password'] ?? '');
if ($password === '' && is_array($stored)) {
    $password = (string)($stored['password'] ?? '');
}

$snmpPort = (int)($_POST['snmp_port'] ?? ($stored['snmp_port'] ?? 161));
$snmpCommunity = trim((string)($_POST['snmp_community'] ?? ($stored['snmp_community'] ?? 'public')));
$snmpVersion = (string)($_POST['snmp_version'] ?? ($stored['snmp_version'] ?? '2c'));
if (!in_array($snmpVersion, ['1', '2c'], true)) {
    $snmpVersion = '2c';
}

$apiOk = false;
$apiError = '';
$snmpOk = false;
$snmpError = '';

if ($ip === '' || $apiPort < 1 || $apiPort > 65535) {
    $apiError = 'Invalid API address/port.';
} else {
    try {
        $api = new RouterOSAPI();
        if ($username !== '' && $password !== '' && $api->connect($ip, $username, $password, $apiPort, 3)) {
            $apiOk = true;
        } else {
            $apiError = 'API connection failed.';
        }
        $api->disconnect();
    } catch (Throwable $e) {
        $apiError = 'API error.';
    }
}

if ($ip === '' || $snmpPort < 1 || $snmpPort > 65535) {
    $snmpError = 'Invalid SNMP address/port.';
} else {
    $snmpRes = mikrotik_snmp_test([
        'ip' => $ip,
        'snmp_port' => $snmpPort,
        'snmp_community' => $snmpCommunity,
        'snmp_version' => $snmpVersion,
    ]);
    $snmpOk = (bool)($snmpRes['ok'] ?? false);
    $snmpError = (string)($snmpRes['error'] ?? '');
}

echo json_encode([
    'api' => ['ok' => $apiOk, 'error' => $apiError],
    'snmp' => ['ok' => $snmpOk, 'error' => $snmpError],
]);
