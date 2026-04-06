<?php
require __DIR__ . '/_lib/bootstrap.php';

$active = 'router';
$id = (string)($_GET['id'] ?? '');
$id = trim($id);
if ($id === '') {
    header('Location: ' . base_url('routers.php?add=1'));
    exit;
}
$url = base_url('routers.php?edit=' . urlencode($id));
header('Location: ' . $url);
exit;
$existing = $id !== '' ? store_get_router($id) : null;
$existing = is_array($existing) ? router_normalize($existing) : null;

$title = $existing ? 'Edit Router' : 'Add Router';

$errors = [];
$pageToasts = [];

$form = $existing ?? [
    'id' => '',
    'name' => '',
    'ip' => '',
    'api_port' => 8728,
    'snmp_port' => 161,
    'snmp_version' => '2c',
    'username' => '',
    'password' => '',
    'snmp_community' => 'public',
    'monitor_interface' => 'ether1',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? 'save');

    $name = trim((string)($_POST['name'] ?? ''));
    $ip = trim((string)($_POST['ip'] ?? ''));
    $apiPort = (int)($_POST['api_port'] ?? 8728);
    $snmpPort = (int)($_POST['snmp_port'] ?? 161);
    $snmpVersion = (string)($_POST['snmp_version'] ?? '2c');
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $snmpCommunity = trim((string)($_POST['snmp_community'] ?? 'public'));
    $monitorInterface = trim((string)($_POST['monitor_interface'] ?? 'ether1'));

    if (!in_array($snmpVersion, ['1', '2c'], true)) {
        $errors[] = 'SNMP version is invalid.';
    }
    if ($ip === '') {
        $errors[] = 'IP is required.';
    }
    if ($apiPort < 1 || $apiPort > 65535) {
        $errors[] = 'API port is invalid.';
    }
    if ($snmpPort < 1 || $snmpPort > 65535) {
        $errors[] = 'SNMP port is invalid.';
    }
    if ($existing && $monitorInterface === '') {
        $errors[] = 'Monitor interface is required.';
    }

    $router = [
        'id' => $existing['id'] ?? ($form['id'] !== '' ? $form['id'] : bin2hex(random_bytes(16))),
        'name' => $name,
        'ip' => $ip,
        'api_port' => $apiPort,
        'snmp_port' => $snmpPort,
        'snmp_version' => in_array($snmpVersion, ['1', '2c'], true) ? $snmpVersion : '2c',
        'username' => $username,
        'password' => $password !== '' ? $password : (string)($existing['password'] ?? ''),
        'snmp_community' => $snmpCommunity !== '' ? $snmpCommunity : 'public',
        'monitor_interface' => $monitorInterface !== '' ? $monitorInterface : (string)($existing['monitor_interface'] ?? 'ether1'),
        'updated_at' => gmdate('Y-m-d H:i:s'),
        'created_at' => (string)($existing['created_at'] ?? gmdate('Y-m-d H:i:s')),
    ];

    $form = $router;

    if (count($errors) === 0 && $action === 'test') {
        $apiOk = false;
        $api = mikrotik_api_connect($router);
        if ($api !== null) {
            $apiOk = true;
            $api->disconnect();
        }
        $snmpOk = router_check_tcp($router['ip'], $router['snmp_port'], 2);
        $pageToasts[] = [
            'type' => $apiOk ? 'success' : 'warning',
            'message' => 'API: ' . ($apiOk ? 'OK' : 'Fail') . ' | SNMP Port: ' . ($snmpOk ? 'Open' : 'Closed'),
        ];
    }

    if (count($errors) === 0 && $action === 'save') {
        store_upsert_router($router);
        flash_add('success', 'Router saved.');
        header('Location: ' . base_url('routers.php'));
        exit;
    }
}

ob_start();
?>
<div class="card shadow-sm">
    <div class="card-body">
        <?php if (count($errors) > 0): ?>
            <?php foreach ($errors as $err): ?>
                <?php $pageToasts[] = ['type' => 'danger', 'message' => (string)$err]; ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <form method="post" class="row g-3">
            <?php echo csrf_field(); ?>

            <div class="col-12 col-lg-6">
                <label class="form-label">Router Name</label>
                <input class="form-control" name="name" value="<?php echo e((string)$form['name']); ?>" placeholder="Main Office">
            </div>
            <div class="col-12 col-lg-6">
                <label class="form-label">IP Address</label>
                <input class="form-control" name="ip" value="<?php echo e((string)$form['ip']); ?>" placeholder="192.168.88.1" required>
            </div>

            <div class="col-12 col-lg-4">
                <label class="form-label">API Port</label>
                <input class="form-control" type="number" name="api_port" value="<?php echo e((string)$form['api_port']); ?>" min="1" max="65535">
            </div>
            <div class="col-12 col-lg-4">
                <label class="form-label">SNMP Version</label>
                <select class="form-select" name="snmp_version">
                    <option value="2c" <?php echo ((string)$form['snmp_version'] ?? '2c') === '2c' ? 'selected' : ''; ?>>v2c</option>
                    <option value="1" <?php echo ((string)$form['snmp_version'] ?? '2c') === '1' ? 'selected' : ''; ?>>v1</option>
                </select>
            </div>
            <div class="col-12 col-lg-4">
                <label class="form-label">SNMP Port</label>
                <input class="form-control" type="number" name="snmp_port" value="<?php echo e((string)$form['snmp_port']); ?>" min="1" max="65535">
            </div>

            <div class="col-12 col-lg-4">
                <label class="form-label">API Username</label>
                <input class="form-control" name="username" value="<?php echo e((string)$form['username']); ?>" placeholder="admin">
            </div>
            <div class="col-12 col-lg-4">
                <label class="form-label">API Password</label>
                <input class="form-control" type="password" name="password" value="" placeholder="<?php echo e($existing ? 'Leave blank to keep current' : ''); ?>">
            </div>
            <div class="col-12 col-lg-4">
                <label class="form-label">SNMP Community</label>
                <input class="form-control" name="snmp_community" value="<?php echo e((string)$form['snmp_community']); ?>" placeholder="public">
            </div>

            <?php if ($existing): ?>
                <div class="col-12">
                    <div class="fw-semibold mt-2">Bandwidth Monitor (Optional)</div>
                    <div class="text-body-secondary small">Set interface after router is added.</div>
                </div>
                <div class="col-12 col-lg-4">
                    <label class="form-label">Monitor Interface</label>
                    <input class="form-control" name="monitor_interface" value="<?php echo e((string)$form['monitor_interface']); ?>" placeholder="ether1">
                </div>
            <?php endif; ?>

            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary" name="action" value="save" type="submit">Save</button>
                <button class="btn btn-outline-secondary" name="action" value="test" type="submit">Test Connection</button>
                <a class="btn btn-outline-secondary ms-auto" href="<?php echo e(base_url('routers.php')); ?>">Back</a>
            </div>
        </form>
    </div>
</div>
<?php
$contentHtml = ob_get_clean();
require __DIR__ . '/_partials/layout.php';
