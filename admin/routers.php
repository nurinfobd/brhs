<?php
require __DIR__ . '/_lib/bootstrap.php';

$title = 'Router';
$active = 'router';
$openAddRouterModal = (string)($_GET['add'] ?? '') === '1';
$openEditRouterId = trim((string)($_GET['edit'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $ip = trim((string)($_POST['ip'] ?? ''));
        $apiPort = (int)($_POST['api_port'] ?? 8728);
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $snmpVersion = (string)($_POST['snmp_version'] ?? '2c');
        $snmpPort = (int)($_POST['snmp_port'] ?? 161);
        $snmpCommunity = trim((string)($_POST['snmp_community'] ?? 'public'));
        $radiusSecret = trim((string)($_POST['radius_secret'] ?? ''));
        $radiusEnabled = (int)($_POST['radius_enabled'] ?? 0) === 1 ? 1 : 0;

        if (!in_array($snmpVersion, ['1', '2c'], true)) {
            $snmpVersion = '2c';
        }

        $errors = [];
        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if ($ip === '') {
            $errors[] = 'Address is required.';
        }
        if ($apiPort < 1 || $apiPort > 65535) {
            $errors[] = 'API port is invalid.';
        }
        if ($username === '') {
            $errors[] = 'API user is required.';
        }
        if ($password === '') {
            $errors[] = 'API password is required.';
        }
        if ($snmpPort < 1 || $snmpPort > 65535) {
            $errors[] = 'SNMP port is invalid.';
        }
        if ($snmpCommunity === '') {
            $errors[] = 'SNMP community is required.';
        }
        if ($radiusEnabled === 1 && $radiusSecret === '') {
            $errors[] = 'RADIUS secret is required when RADIUS is enabled.';
        }

        if (count($errors) === 0) {
            store_upsert_router([
                'id' => bin2hex(random_bytes(16)),
                'name' => $name,
                'ip' => $ip,
                'api_port' => $apiPort,
                'snmp_port' => $snmpPort,
                'snmp_version' => $snmpVersion,
                'username' => $username,
                'password' => $password,
                'snmp_community' => $snmpCommunity,
                'monitor_interface' => 'ether1',
                'monitor_capacity_mbps' => 100,
                'radius_secret' => $radiusSecret,
                'radius_enabled' => $radiusEnabled,
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            flash_add('success', 'Router added.');
            header('Location: ' . base_url('routers.php'));
            exit;
        }
        foreach ($errors as $err) {
            flash_add('danger', $err);
        }
        header('Location: ' . base_url('routers.php?add=1'));
        exit;
    }
    if ($action === 'update') {
        $routerId = trim((string)($_POST['router_id'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $ip = trim((string)($_POST['ip'] ?? ''));
        $apiPort = (int)($_POST['api_port'] ?? 8728);
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $snmpVersion = (string)($_POST['snmp_version'] ?? '2c');
        $snmpPort = (int)($_POST['snmp_port'] ?? 161);
        $snmpCommunity = trim((string)($_POST['snmp_community'] ?? 'public'));
        $radiusSecret = trim((string)($_POST['radius_secret'] ?? ''));
        $radiusEnabled = (int)($_POST['radius_enabled'] ?? 0) === 1 ? 1 : 0;
        if (!in_array($snmpVersion, ['1', '2c'], true)) {
            $snmpVersion = '2c';
        }

        $errors = [];
        if ($routerId === '') {
            $errors[] = 'Router id is required.';
        }
        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if ($ip === '') {
            $errors[] = 'Address is required.';
        }
        if ($apiPort < 1 || $apiPort > 65535) {
            $errors[] = 'API port is invalid.';
        }
        if ($username === '') {
            $errors[] = 'API user is required.';
        }
        if ($snmpPort < 1 || $snmpPort > 65535) {
            $errors[] = 'SNMP port is invalid.';
        }
        if ($snmpCommunity === '') {
            $errors[] = 'SNMP community is required.';
        }
        if ($radiusEnabled === 1 && $radiusSecret === '') {
            $errors[] = 'RADIUS secret is required when RADIUS is enabled.';
        }

        $existing = $routerId !== '' ? store_get_router($routerId) : null;
        if (!is_array($existing)) {
            $errors[] = 'Router not found.';
        }

        if (count($errors) === 0 && is_array($existing)) {
            $existing = router_normalize($existing);
            $newPassword = $password !== '' ? $password : (string)($existing['password'] ?? '');
            if ($newPassword === '') {
                $errors[] = 'API password is required.';
            } else {
                $existing['name'] = $name;
                $existing['ip'] = $ip;
                $existing['api_port'] = $apiPort;
                $existing['username'] = $username;
                $existing['password'] = $newPassword;
                $existing['snmp_version'] = $snmpVersion;
                $existing['snmp_port'] = $snmpPort;
                $existing['snmp_community'] = $snmpCommunity;
                $existing['radius_secret'] = $radiusSecret;
                $existing['radius_enabled'] = $radiusEnabled;
                $existing['updated_at'] = gmdate('Y-m-d H:i:s');
                store_upsert_router($existing);
                flash_add('success', 'Router updated.');
                header('Location: ' . base_url('routers.php'));
                exit;
            }
        }

        foreach ($errors as $err) {
            flash_add('danger', $err);
        }
        header('Location: ' . base_url('routers.php?edit=' . urlencode($routerId)));
        exit;
    }
    if ($action === 'monitor_update') {
        $routerId = trim((string)($_POST['router_id'] ?? ''));
        $itemsJson = (string)($_POST['monitor_items_json'] ?? '');
        $items = json_decode($itemsJson, true);

        $errors = [];
        if ($routerId === '') {
            $errors[] = 'Router id is required.';
        }

        $router = $routerId !== '' ? store_get_router($routerId) : null;
        if (!is_array($router)) {
            $errors[] = 'Router not found.';
        }

        $normalizedItems = [];
        if (is_array($items)) {
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $iface = trim((string)($it['interface'] ?? $it['interface_name'] ?? ''));
                if ($iface === '') {
                    continue;
                }
                $normalizedItems[] = ['interface' => $iface];
            }
        }

        if (count($errors) === 0 && is_array($router)) {
            $router = router_normalize($router);
            store_replace_router_monitor_interfaces((string)$router['id'], $normalizedItems);
            if (count($normalizedItems) > 0) {
                $router['monitor_interface'] = (string)$normalizedItems[0]['interface'];
                $router['monitor_capacity_mbps'] = max(100, count($normalizedItems) * 100);
            } else {
                $router['monitor_interface'] = '';
                $router['monitor_capacity_mbps'] = 0;
            }
            $router['updated_at'] = gmdate('Y-m-d H:i:s');
            store_upsert_router($router);
            flash_add('success', 'Monitoring interfaces saved.');
            header('Location: ' . base_url('routers.php'));
            exit;
        }

        foreach ($errors as $err) {
            flash_add('danger', $err);
        }
        header('Location: ' . base_url('routers.php'));
        exit;
    }
    if ($action === 'delete') {
        $id = (string)($_POST['id'] ?? '');
        if ($id !== '') {
            store_delete_router($id);
            flash_add('success', 'Router deleted.');
        }
        header('Location: ' . base_url('routers.php'));
        exit;
    }
}

$store = store_load();
$routers = array_map('router_normalize', $store['routers']);

$topActionsHtml = '<button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addRouterModal"><i class="bi bi-plus-lg me-1"></i>Add Router</button>';

ob_start();
?>
<style>
    @media (max-width:575.98px){
        .router-table{font-size:.84rem}
        .router-table .badge{font-size:.70rem}
        .router-table .btn{padding:.25rem .4rem}
    }
</style>
<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex flex-column flex-lg-row gap-2 align-items-lg-center justify-content-between mb-3">
            <div class="input-group input-group-sm flex-grow-0" style="width: 320px; max-width: 100%;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input class="form-control" id="routerSearch" placeholder="Search router..." autocomplete="off">
            </div>
            <select class="form-select form-select-sm" id="routerPageSize" style="width: 110px;">
                <option value="10" selected>10 rows</option>
                <option value="25">25 rows</option>
                <option value="50">50 rows</option>
            </select>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle router-table">
                <thead>
                <tr>
                    <th>Router</th>
                    <th class="d-none d-sm-table-cell">Address</th>
                    <th class="d-none d-md-table-cell">SNMP</th>
                    <th class="d-none d-md-table-cell">API</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php if (count($routers) === 0): ?>
                    <tr>
                        <td colspan="6" class="text-body-secondary">No routers added yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($routers as $router): ?>
                        <?php $status = router_status($router); ?>
                        <?php
                        $searchParts = [];
                        $searchParts[] = (string)($router['name'] ?? '');
                        $searchParts[] = (string)($router['ip'] ?? '');
                        $searchParts[] = (string)($router['snmp_version'] ?? '');
                        $searchParts[] = (string)($router['snmp_community'] ?? '');
                        $searchParts[] = (string)($router['username'] ?? '');
                        $searchParts[] = (string)$status;
                        ?>
                        <tr data-search="<?php echo e(strtolower(implode(' ', $searchParts))); ?>">
                            <td>
                                <div class="fw-semibold"><?php echo e($router['name'] !== '' ? $router['name'] : 'Router'); ?></div>
                                <div class="small text-body-secondary font-monospace d-sm-none"><?php echo e($router['ip']); ?></div>
                            </td>
                            <td class="d-none d-sm-table-cell"><?php echo e($router['ip']); ?></td>
                            <td class="d-none d-md-table-cell">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge text-bg-light border">v<?php echo e($router['snmp_version']); ?></span>
                                    <span class="small text-body-secondary">Port: <?php echo e((string)$router['snmp_port']); ?></span>
                                </div>
                                <div class="small text-body-secondary">Community: <?php echo e($router['snmp_community']); ?></div>
                            </td>
                            <td class="d-none d-md-table-cell">
                                <div class="small fw-semibold"><?php echo e($router['username']); ?></div>
                                <div class="small text-body-secondary">Port: <?php echo e((string)$router['api_port']); ?></div>
                            </td>
                            <td>
                                <?php if ($status === 'online'): ?>
                                    <span class="badge text-bg-success"><i class="bi bi-wifi me-1"></i>Online</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary"><i class="bi bi-wifi-off me-1"></i>Offline</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="d-flex flex-column flex-sm-row align-items-end justify-content-end gap-1 gap-sm-2">
                                    <button
                                        class="btn btn-sm btn-outline-secondary js-monitor-btn"
                                        type="button"
                                        data-router_id="<?php echo e((string)$router['id']); ?>"
                                        data-router_name="<?php echo e($router['name'] !== '' ? $router['name'] : 'Router'); ?>"
                                        title="Monitor interface"
                                    >
                                        <i class="bi bi-graph-up-arrow"></i>
                                    </button>
                                    <button
                                        class="btn btn-sm btn-outline-primary js-edit-router"
                                        type="button"
                                        title="Edit router"
                                        data-router_id="<?php echo e((string)$router['id']); ?>"
                                        data-name="<?php echo e((string)($router['name'] ?? '')); ?>"
                                        data-ip="<?php echo e((string)($router['ip'] ?? '')); ?>"
                                        data-api_port="<?php echo e((string)($router['api_port'] ?? '8728')); ?>"
                                        data-username="<?php echo e((string)($router['username'] ?? '')); ?>"
                                        data-snmp_version="<?php echo e((string)($router['snmp_version'] ?? '2c')); ?>"
                                        data-snmp_port="<?php echo e((string)($router['snmp_port'] ?? '161')); ?>"
                                        data-snmp_community="<?php echo e((string)($router['snmp_community'] ?? 'public')); ?>"
                                        data-radius_secret="<?php echo e((string)($router['radius_secret'] ?? '')); ?>"
                                        data-radius_enabled="<?php echo e((string)($router['radius_enabled'] ?? '0')); ?>"
                                    >
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <form method="post" class="m-0" onsubmit="return confirm('Delete this router?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo e($router['id']); ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="Delete router" type="submit">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($routers) > 0): ?>
            <div class="d-flex align-items-center justify-content-between mt-2">
                <div class="small text-danger" id="routerCount" style="min-height: 18px;"></div>
                <nav>
                    <ul class="pagination pagination-sm mb-0" id="routerPagination"></ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="addRouterModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title d-flex align-items-center gap-2">
                    <i class="bi bi-hdd-network"></i>
                    <span>Add Router</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" id="addRouterForm">
                <div class="modal-body">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create">
                    <div class="row g-2">
                        <div class="col-12">
                            <div class="row g-2">
                                <div class="col-12 col-lg-6">
                                    <label class="form-label mb-1">Name</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-tag"></i></span>
                                        <input class="form-control" name="name" placeholder="Main Office" required>
                                    </div>
                                </div>
                                <div class="col-12 col-lg-6">
                                    <label class="form-label mb-1">Address</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-globe2"></i></span>
                                        <input class="form-control" name="ip" placeholder="192.168.88.1" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-xl-6">
                            <div class="card border-0 bg-body-tertiary">
                                <div class="card-body p-2">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <i class="bi bi-diagram-3"></i>
                                        <div class="fw-semibold">API</div>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-12 col-lg-4">
                                            <label class="form-label mb-1">API Port</label>
                                            <input class="form-control" type="number" name="api_port" value="8728" min="1" max="65535" required>
                                        </div>
                                        <div class="col-12 col-lg-4">
                                            <label class="form-label mb-1">API User</label>
                                            <input class="form-control" name="username" required>
                                        </div>
                                        <div class="col-12 col-lg-4">
                                            <label class="form-label mb-1">API Password</label>
                                            <input class="form-control" type="password" name="password" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-xl-6">
                            <div class="card border-0 bg-body-tertiary">
                                <div class="card-body p-2">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <i class="bi bi-broadcast"></i>
                                        <div class="fw-semibold">SNMP</div>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-12 col-lg-4">
                                            <label class="form-label mb-1">SNMP Version</label>
                                            <select class="form-select" name="snmp_version">
                                                <option value="2c" selected>v2c</option>
                                                <option value="1">v1</option>
                                            </select>
                                        </div>
                                        <div class="col-12 col-lg-4">
                                            <label class="form-label mb-1">SNMP Port</label>
                                            <input class="form-control" type="number" name="snmp_port" value="161" min="1" max="65535" required>
                                        </div>
                                        <div class="col-12 col-lg-4">
                                            <label class="form-label mb-1">Community</label>
                                            <input class="form-control" name="snmp_community" value="public" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-xl-6">
                            <div class="card border-0 bg-body-tertiary">
                                <div class="card-body p-2">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <i class="bi bi-shield-lock"></i>
                                        <div class="fw-semibold">RADIUS</div>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-12 col-lg-8">
                                            <label class="form-label mb-1">RADIUS Secret</label>
                                            <input class="form-control" name="radius_secret" placeholder="Shared secret (same as MikroTik RADIUS client)">
                                        </div>
                                        <div class="col-12 col-lg-4 d-flex align-items-end">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" value="1" name="radius_enabled" id="add_radius_enabled">
                                                <label class="form-check-label" for="add_radius_enabled">Enable</label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="small text-body-secondary mt-1">UDP 1812 / 1813</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-xl-6">
                            <div class="card border-0">
                                <div class="card-body p-0">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div>
                                            <div class="fw-semibold">Check Connection</div>
                                            <div class="small text-body-secondary">API and SNMP check run separately.</div>
                                        </div>
                                        <button class="btn btn-sm btn-outline-secondary" type="button" id="btnCheckRouter">
                                            <i class="bi bi-check2-circle me-1"></i>Check
                                        </button>
                                    </div>
                                    <div class="row g-2 mt-2">
                                        <div class="col-12 col-lg-6">
                                            <div class="p-2 border rounded-2 d-flex align-items-center justify-content-between">
                                                <div class="d-flex align-items-center gap-2">
                                                    <span id="apiStatusIcon"><i class="bi bi-dash-circle text-body-secondary"></i></span>
                                                    <div>
                                                        <div class="fw-semibold">API</div>
                                                        <div class="small text-body-secondary" id="apiStatusText"></div>
                                                    </div>
                                                </div>
                                                <span class="badge text-bg-light border" id="apiStatusBadge">Not checked</span>
                                            </div>
                                        </div>
                                        <div class="col-12 col-lg-6">
                                            <div class="p-2 border rounded-2 d-flex align-items-center justify-content-between">
                                                <div class="d-flex align-items-center gap-2">
                                                    <span id="snmpStatusIcon"><i class="bi bi-dash-circle text-body-secondary"></i></span>
                                                    <div>
                                                        <div class="fw-semibold">SNMP</div>
                                                        <div class="small text-body-secondary" id="snmpStatusText"></div>
                                                    </div>
                                                </div>
                                                <span class="badge text-bg-light border" id="snmpStatusBadge">Not checked</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btnAddRouter">
                        <i class="bi bi-plus-circle me-1"></i>Add
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editRouterModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title d-flex align-items-center gap-2">
                    <i class="bi bi-pencil-square"></i>
                    <span>Edit Router</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" id="editRouterForm">
                <div class="modal-body">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="router_id" id="edit_router_id" value="">
                    <div class="row g-2">
                        <div class="col-12">
                            <div class="row g-2">
                                <div class="col-12 col-lg-6">
                                    <label class="form-label mb-1">Name</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-tag"></i></span>
                                        <input class="form-control" name="name" id="edit_name" required>
                                    </div>
                                </div>
                                <div class="col-12 col-lg-6">
                                    <label class="form-label mb-1">Address</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-globe2"></i></span>
                                        <input class="form-control" name="ip" id="edit_ip" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-xl-6">
                            <div class="card border-0 bg-body-tertiary">
                                <div class="card-body p-2">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <i class="bi bi-diagram-3"></i>
                                        <div class="fw-semibold">API</div>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-12 col-lg-4">
                                            <label class="form-label mb-1">API Port</label>
                                            <input class="form-control" type="number" name="api_port" id="edit_api_port" min="1" max="65535" required>
                                        </div>
                                        <div class="col-12 col-lg-4">
                                            <label class="form-label mb-1">API User</label>
                                            <input class="form-control" name="username" id="edit_username" required>
                                        </div>
                                        <div class="col-12 col-lg-4">
                                            <label class="form-label mb-1">API Password</label>
                                            <input class="form-control" type="password" name="password" id="edit_password" placeholder="Leave blank to keep current">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-xl-6">
                            <div class="card border-0 bg-body-tertiary">
                                <div class="card-body p-2">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <i class="bi bi-broadcast"></i>
                                        <div class="fw-semibold">SNMP</div>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-12 col-lg-4">
                                            <label class="form-label mb-1">SNMP Version</label>
                                            <select class="form-select" name="snmp_version" id="edit_snmp_version">
                                                <option value="2c">v2c</option>
                                                <option value="1">v1</option>
                                            </select>
                                        </div>
                                        <div class="col-12 col-lg-4">
                                            <label class="form-label mb-1">SNMP Port</label>
                                            <input class="form-control" type="number" name="snmp_port" id="edit_snmp_port" min="1" max="65535" required>
                                        </div>
                                        <div class="col-12 col-lg-4">
                                            <label class="form-label mb-1">Community</label>
                                            <input class="form-control" name="snmp_community" id="edit_snmp_community" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-xl-6">
                            <div class="card border-0 bg-body-tertiary">
                                <div class="card-body p-2">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <i class="bi bi-shield-lock"></i>
                                        <div class="fw-semibold">RADIUS</div>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-12 col-lg-8">
                                            <label class="form-label mb-1">RADIUS Secret</label>
                                            <input class="form-control" name="radius_secret" id="edit_radius_secret" placeholder="Shared secret (same as MikroTik RADIUS client)">
                                        </div>
                                        <div class="col-12 col-lg-4 d-flex align-items-end">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" value="1" name="radius_enabled" id="edit_radius_enabled">
                                                <label class="form-check-label" for="edit_radius_enabled">Enable</label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="small text-body-secondary mt-1">UDP 1812 / 1813</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-xl-6">
                            <div class="card border-0">
                                <div class="card-body p-0">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div>
                                            <div class="fw-semibold">Check Connection</div>
                                            <div class="small text-body-secondary">API and SNMP check run separately.</div>
                                        </div>
                                        <button class="btn btn-sm btn-outline-secondary" type="button" id="btnCheckRouterEdit">
                                            <i class="bi bi-check2-circle me-1"></i>Check
                                        </button>
                                    </div>
                                    <div class="row g-2 mt-2">
                                        <div class="col-12 col-lg-6">
                                            <div class="p-2 border rounded-2 d-flex align-items-center justify-content-between">
                                                <div class="d-flex align-items-center gap-2">
                                                    <span id="apiStatusIconEdit"><i class="bi bi-dash-circle text-body-secondary"></i></span>
                                                    <div>
                                                        <div class="fw-semibold">API</div>
                                                        <div class="small text-body-secondary" id="apiStatusTextEdit"></div>
                                                    </div>
                                                </div>
                                                <span class="badge text-bg-light border" id="apiStatusBadgeEdit">Not checked</span>
                                            </div>
                                        </div>
                                        <div class="col-12 col-lg-6">
                                            <div class="p-2 border rounded-2 d-flex align-items-center justify-content-between">
                                                <div class="d-flex align-items-center gap-2">
                                                    <span id="snmpStatusIconEdit"><i class="bi bi-dash-circle text-body-secondary"></i></span>
                                                    <div>
                                                        <div class="fw-semibold">SNMP</div>
                                                        <div class="small text-body-secondary" id="snmpStatusTextEdit"></div>
                                                    </div>
                                                </div>
                                                <span class="badge text-bg-light border" id="snmpStatusBadgeEdit">Not checked</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check2-circle me-1"></i>Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="monitorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title d-flex align-items-center gap-2">
                    <i class="bi bi-graph-up-arrow"></i>
                    <span id="monitorModalTitle">Monitoring Interface</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" id="monitorForm">
                <div class="modal-body">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="monitor_update">
                    <input type="hidden" name="router_id" id="monitor_router_id" value="">
                    <input type="hidden" name="monitor_items_json" id="monitor_items_json" value="[]">

                    <div class="row g-3">
                        <div class="col-12 col-lg-10">
                            <div class="d-flex align-items-center justify-content-between">
                                <label class="form-label mb-0">Interfaces</label>
                                <button class="btn btn-outline-primary btn-sm" type="button" id="btnAddMonitorItem" disabled>
                                    <i class="bi bi-plus-lg me-1"></i>Add
                                </button>
                            </div>
                            <div id="monitorInterfaceList" class="border rounded-3 p-2 mt-2" style="max-height: 220px; overflow: auto;">
                                <div class="small text-body-secondary">Loading...</div>
                            </div>
                            <div class="small text-body-secondary mt-1" id="monitor_interface_help"></div>
                        </div>
                        <div class="col-12 col-lg-2 d-flex align-items-end justify-content-end">
                        </div>
                        <div class="col-12">
                            <div class="table-responsive">
                                <table class="table table-sm mb-0 align-middle">
                                    <thead>
                                    <tr>
                                        <th>Interface</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                    </thead>
                                    <tbody id="monitorItemsTbody">
                                    <tr>
                                        <td colspan="2" class="text-body-secondary">No interfaces added.</td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="d-flex justify-content-end mt-2">
                                <button class="btn btn-sm btn-outline-danger" type="button" id="btnClearMonitorItems" title="Clear">
                                    <i class="bi bi-x-circle"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="d-flex align-items-center justify-content-between">
                                <div class="small text-body-secondary" id="monitor_load_status"></div>
                                <button class="btn btn-sm btn-outline-secondary" type="button" id="btnReloadInterfaces">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Reload
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btnSaveMonitor">
                        <i class="bi bi-check2-circle me-1"></i>Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    window.addEventListener('load', function () {
        function wireRouterCheck(cfg) {
            if (!cfg || !cfg.form || !cfg.btn) return;

            function setNeutral() {
                if (cfg.apiIcon) cfg.apiIcon.innerHTML = '<i class="bi bi-dash-circle text-body-secondary"></i>';
                if (cfg.snmpIcon) cfg.snmpIcon.innerHTML = '<i class="bi bi-dash-circle text-body-secondary"></i>';
                if (cfg.apiText) cfg.apiText.textContent = '';
                if (cfg.snmpText) cfg.snmpText.textContent = '';
                if (cfg.apiBadge) cfg.apiBadge.className = 'badge text-bg-light border';
                if (cfg.snmpBadge) cfg.snmpBadge.className = 'badge text-bg-light border';
                if (cfg.apiBadge) cfg.apiBadge.textContent = 'Not checked';
                if (cfg.snmpBadge) cfg.snmpBadge.textContent = 'Not checked';
            }

            function setPending() {
                if (cfg.apiIcon) cfg.apiIcon.innerHTML = '<span class="spinner-border spinner-border-sm text-body-secondary" role="status"></span>';
                if (cfg.snmpIcon) cfg.snmpIcon.innerHTML = '<span class="spinner-border spinner-border-sm text-body-secondary" role="status"></span>';
                if (cfg.apiText) cfg.apiText.textContent = '';
                if (cfg.snmpText) cfg.snmpText.textContent = '';
                if (cfg.apiBadge) cfg.apiBadge.className = 'badge text-bg-light border';
                if (cfg.snmpBadge) cfg.snmpBadge.className = 'badge text-bg-light border';
                if (cfg.apiBadge) cfg.apiBadge.textContent = 'Checking...';
                if (cfg.snmpBadge) cfg.snmpBadge.textContent = 'Checking...';
            }

            function setResult(target, ok, text) {
                if (!target.icon || !target.text) return;
                target.icon.innerHTML = ok
                    ? '<i class="bi bi-check-circle-fill text-success"></i>'
                    : '<i class="bi bi-x-circle-fill text-danger"></i>';
                target.text.textContent = text || '';
                if (target.badge) {
                    target.badge.className = ok ? 'badge text-bg-success' : 'badge text-bg-danger';
                    target.badge.textContent = ok ? 'OK' : 'Fail';
                }
            }

            setNeutral();
            cfg.form.querySelectorAll('input,select').forEach(function (el) {
                el.addEventListener('input', setNeutral);
                el.addEventListener('change', setNeutral);
            });

            cfg.btn.addEventListener('click', function () {
                setPending();
                cfg.btn.disabled = true;
                var fd = new FormData(cfg.form);
                fetch('<?php echo e(base_url('router-test.php')); ?>', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                }).then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.api || !data.snmp) {
                        setResult({icon: cfg.apiIcon, text: cfg.apiText, badge: cfg.apiBadge}, false, 'Failed');
                        setResult({icon: cfg.snmpIcon, text: cfg.snmpText, badge: cfg.snmpBadge}, false, 'Failed');
                        return;
                    }
                    setResult({icon: cfg.apiIcon, text: cfg.apiText, badge: cfg.apiBadge}, !!data.api.ok, data.api.ok ? 'Connected' : (data.api.error || 'Failed'));
                    setResult({icon: cfg.snmpIcon, text: cfg.snmpText, badge: cfg.snmpBadge}, !!data.snmp.ok, data.snmp.ok ? 'OK' : (data.snmp.error || 'Failed'));
                })
                .catch(function () {
                    setResult({icon: cfg.apiIcon, text: cfg.apiText, badge: cfg.apiBadge}, false, 'Failed');
                    setResult({icon: cfg.snmpIcon, text: cfg.snmpText, badge: cfg.snmpBadge}, false, 'Failed');
                })
                .finally(function () {
                    setTimeout(function () { cfg.btn.disabled = false; }, 300);
                });
            });
        }

        <?php if ($openAddRouterModal): ?>
        (function () {
            var el = document.getElementById('addRouterModal');
            if (!el || typeof bootstrap === 'undefined') return;
            var m = new bootstrap.Modal(el);
            m.show();
        })();
        <?php endif; ?>

        wireRouterCheck({
            form: document.getElementById('addRouterForm'),
            btn: document.getElementById('btnCheckRouter'),
            apiIcon: document.getElementById('apiStatusIcon'),
            apiText: document.getElementById('apiStatusText'),
            apiBadge: document.getElementById('apiStatusBadge'),
            snmpIcon: document.getElementById('snmpStatusIcon'),
            snmpText: document.getElementById('snmpStatusText'),
            snmpBadge: document.getElementById('snmpStatusBadge')
        });

        wireRouterCheck({
            form: document.getElementById('editRouterForm'),
            btn: document.getElementById('btnCheckRouterEdit'),
            apiIcon: document.getElementById('apiStatusIconEdit'),
            apiText: document.getElementById('apiStatusTextEdit'),
            apiBadge: document.getElementById('apiStatusBadgeEdit'),
            snmpIcon: document.getElementById('snmpStatusIconEdit'),
            snmpText: document.getElementById('snmpStatusTextEdit'),
            snmpBadge: document.getElementById('snmpStatusBadgeEdit')
        });

        var editModalEl = document.getElementById('editRouterModal');
        var editRouterIdEl = document.getElementById('edit_router_id');
        var editNameEl = document.getElementById('edit_name');
        var editIpEl = document.getElementById('edit_ip');
        var editApiPortEl = document.getElementById('edit_api_port');
        var editUserEl = document.getElementById('edit_username');
        var editPassEl = document.getElementById('edit_password');
        var editSnmpVersionEl = document.getElementById('edit_snmp_version');
        var editSnmpPortEl = document.getElementById('edit_snmp_port');
        var editSnmpCommunityEl = document.getElementById('edit_snmp_community');
        var editRadiusSecretEl = document.getElementById('edit_radius_secret');
        var editRadiusEnabledEl = document.getElementById('edit_radius_enabled');

        document.querySelectorAll('.js-edit-router').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!editModalEl || typeof bootstrap === 'undefined') return;
                if (editRouterIdEl) editRouterIdEl.value = btn.dataset.router_id || '';
                if (editNameEl) editNameEl.value = btn.dataset.name || '';
                if (editIpEl) editIpEl.value = btn.dataset.ip || '';
                if (editApiPortEl) editApiPortEl.value = btn.dataset.api_port || '8728';
                if (editUserEl) editUserEl.value = btn.dataset.username || '';
                if (editPassEl) editPassEl.value = '';
                if (editSnmpVersionEl) editSnmpVersionEl.value = btn.dataset.snmp_version || '2c';
                if (editSnmpPortEl) editSnmpPortEl.value = btn.dataset.snmp_port || '161';
                if (editSnmpCommunityEl) editSnmpCommunityEl.value = btn.dataset.snmp_community || 'public';
                if (editRadiusSecretEl) editRadiusSecretEl.value = btn.dataset.radius_secret || '';
                if (editRadiusEnabledEl) editRadiusEnabledEl.checked = (btn.dataset.radius_enabled || '0') === '1';

                var m = new bootstrap.Modal(editModalEl);
                m.show();
            });
        });

        var openEditId = '<?php echo e($openEditRouterId); ?>';
        if (openEditId) {
            var btn = document.querySelector('.js-edit-router[data-router_id="' + openEditId.replace(/"/g, '\\"') + '"]');
            if (btn) {
                btn.click();
            }
        }

        var csrfToken = '<?php echo e(csrf_token()); ?>';
        var monitorModalEl = document.getElementById('monitorModal');
        var monitorTitleEl = document.getElementById('monitorModalTitle');
        var monitorRouterIdEl = document.getElementById('monitor_router_id');
        var monitorInterfaceListEl = document.getElementById('monitorInterfaceList');
        var monitorItemsJsonEl = document.getElementById('monitor_items_json');
        var monitorItemsTbodyEl = document.getElementById('monitorItemsTbody');
        var btnAddMonitorItemEl = document.getElementById('btnAddMonitorItem');
        var btnClearMonitorItemsEl = document.getElementById('btnClearMonitorItems');
        var monitorHelpEl = document.getElementById('monitor_interface_help');
        var monitorLoadEl = document.getElementById('monitor_load_status');
        var btnReloadEl = document.getElementById('btnReloadInterfaces');
        var btnSaveEl = document.getElementById('btnSaveMonitor');
        var interfaceDotMap = {};
        var monitorItems = [];

        function syncMonitorJson() {
            if (monitorItemsJsonEl) {
                monitorItemsJsonEl.value = JSON.stringify(monitorItems);
            }
        }

        function renderMonitorItems() {
            if (!monitorItemsTbodyEl) {
                return;
            }
            monitorItemsTbodyEl.innerHTML = '';
            if (!Array.isArray(monitorItems) || monitorItems.length === 0) {
                monitorItemsTbodyEl.innerHTML = '<tr><td colspan="2" class="text-body-secondary">No interfaces added.</td></tr>';
                syncMonitorJson();
                return;
            }
            monitorItems.forEach(function (it, idx) {
                var name = (it && it.interface) ? String(it.interface) : '';
                var dot = interfaceDotMap[name] || '🔴';
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td class="fw-semibold">' + dot + ' ' + name.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</td>' +
                    '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" title="Remove" data-idx="' + idx + '"><i class="bi bi-trash"></i></button></td>';

                var b = tr.querySelector('button');
                if (b) {
                    b.addEventListener('click', function () {
                        var i = parseInt(this.getAttribute('data-idx') || '0', 10);
                        monitorItems.splice(i, 1);
                        renderMonitorItems();
                    });
                }
                monitorItemsTbodyEl.appendChild(tr);
            });
            if (btnSaveEl) btnSaveEl.disabled = false;
            syncMonitorJson();
        }

        function setMonitorLoading(text) {
            if (monitorLoadEl) monitorLoadEl.textContent = text || '';
            if (btnSaveEl) btnSaveEl.disabled = true;
            if (btnAddMonitorItemEl) btnAddMonitorItemEl.disabled = true;
            if (monitorInterfaceListEl) {
                monitorInterfaceListEl.innerHTML = '<div class="small text-body-secondary">Loading...</div>';
            }
            monitorItems = [];
            renderMonitorItems();
        }

        function setMonitorError(text) {
            if (monitorLoadEl) monitorLoadEl.textContent = text || 'Failed to load interfaces.';
            if (btnSaveEl) btnSaveEl.disabled = true;
            if (btnAddMonitorItemEl) btnAddMonitorItemEl.disabled = true;
            if (monitorInterfaceListEl) {
                monitorInterfaceListEl.innerHTML = '<div class="small text-body-secondary">(No data)</div>';
            }
            monitorItems = [];
            renderMonitorItems();
        }

        function setMonitorReady(interfaces, current, loadedItems) {
            if (!monitorInterfaceListEl) return;
            interfaceDotMap = {};
            if (btnAddMonitorItemEl) btnAddMonitorItemEl.disabled = false;
            if (btnSaveEl) btnSaveEl.disabled = false;

            monitorInterfaceListEl.innerHTML = '';
            if (!Array.isArray(interfaces) || interfaces.length === 0) {
                monitorInterfaceListEl.innerHTML = '<div class="small text-body-secondary">No interfaces found.</div>';
            } else {
                interfaces.forEach(function (it, idx) {
                    var name = it.name || '';
                    if (!name) return;
                    var isDisabled = it.disabled === 'true';
                    var isRunning = it.running === 'true';
                    var dot = (!isDisabled && isRunning) ? '🟢' : '🔴';
                    interfaceDotMap[name] = dot;

                    var id = 'iface_pick_' + idx;
                    var wrap = document.createElement('div');
                    wrap.className = 'form-check py-1';
                    var input = document.createElement('input');
                    input.className = 'form-check-input';
                    input.type = 'checkbox';
                    input.name = 'monitor_iface_pick';
                    input.id = id;
                    input.value = name;

                    var label = document.createElement('label');
                    label.className = 'form-check-label w-100';
                    label.htmlFor = id;
                    label.textContent = dot + ' ' + name;

                    wrap.appendChild(input);
                    wrap.appendChild(label);
                    monitorInterfaceListEl.appendChild(wrap);
                });
            }
            if (monitorHelpEl) monitorHelpEl.textContent = 'Add multiple interfaces for monitoring.';
            if (monitorLoadEl) monitorLoadEl.textContent = 'Interfaces loaded.';
            monitorItems = Array.isArray(loadedItems) ? loadedItems.map(function (x) {
                return {
                    interface: String(x.interface || x.interface_name || '')
                };
            }).filter(function (x) { return x.interface !== ''; }) : [];
            renderMonitorItems();
        }

        function loadInterfaces(routerId) {
            setMonitorLoading('Loading interfaces...');
            var fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('router_id', routerId);
            fetch('<?php echo e(base_url('router-interfaces.php')); ?>', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    setMonitorError((data && data.error) ? data.error : 'Failed to load interfaces.');
                    return;
                }
                setMonitorReady(data.interfaces || [], data.current || {}, data.monitor_items || []);
            })
            .catch(function () {
                setMonitorError('Failed to load interfaces.');
            });
        }

        document.querySelectorAll('.js-monitor-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var routerId = btn.dataset.router_id || '';
                var routerName = btn.dataset.router_name || 'Router';
                if (!routerId || !monitorModalEl || typeof bootstrap === 'undefined') return;
                if (monitorTitleEl) monitorTitleEl.textContent = 'Monitoring Interface • ' + routerName;
                if (monitorRouterIdEl) monitorRouterIdEl.value = routerId;
                monitorItems = [];
                renderMonitorItems();
                var m = new bootstrap.Modal(monitorModalEl);
                m.show();
                loadInterfaces(routerId);
            });
        });

        if (btnAddMonitorItemEl) {
            btnAddMonitorItemEl.addEventListener('click', function () {
                var selected = [];
                if (monitorInterfaceListEl) {
                    monitorInterfaceListEl.querySelectorAll('input[name="monitor_iface_pick"]:checked').forEach(function (el) {
                        selected.push(String(el.value || ''));
                    });
                }
                selected = selected.filter(function (x) { return x !== ''; });
                if (selected.length === 0) {
                    return;
                }
                selected.forEach(function (iface) {
                    var exists = false;
                    for (var i = 0; i < monitorItems.length; i++) {
                        if (monitorItems[i].interface === iface) {
                            exists = true;
                            break;
                        }
                    }
                    if (!exists) {
                        monitorItems.push({ interface: iface });
                    }
                });
                renderMonitorItems();
            });
        }

        if (btnClearMonitorItemsEl) {
            btnClearMonitorItemsEl.addEventListener('click', function () {
                monitorItems = [];
                renderMonitorItems();
            });
        }

        var monitorFormEl = document.getElementById('monitorForm');
        if (monitorFormEl) {
            monitorFormEl.addEventListener('submit', function () {
                syncMonitorJson();
            });
        }

        if (btnReloadEl) {
            btnReloadEl.addEventListener('click', function () {
                var routerId = monitorRouterIdEl ? monitorRouterIdEl.value : '';
                if (routerId) {
                    loadInterfaces(routerId);
                }
            });
        }

        var routerSearchEl = document.getElementById('routerSearch');
        var routerPageSizeEl = document.getElementById('routerPageSize');
        var routerCountEl = document.getElementById('routerCount');
        var routerPaginationEl = document.getElementById('routerPagination');
        var routerTbody = document.querySelector('table tbody');
        var allRouterRows = routerTbody ? Array.prototype.slice.call(routerTbody.querySelectorAll('tr[data-search]')) : [];
        var currentPage = 1;

        function getPageSize() {
            var v = routerPageSizeEl ? parseInt(String(routerPageSizeEl.value || '10'), 10) : 10;
            return v > 0 ? v : 10;
        }

        function getQuery() {
            var q = routerSearchEl ? String(routerSearchEl.value || '').trim().toLowerCase() : '';
            return q;
        }

        function filteredRows() {
            var q = getQuery();
            if (!q) return allRouterRows;
            return allRouterRows.filter(function (tr) {
                var s = (tr.getAttribute('data-search') || '').toLowerCase();
                return s.indexOf(q) !== -1;
            });
        }

        function renderPagination(totalPages) {
            if (!routerPaginationEl) return;
            routerPaginationEl.innerHTML = '';
            if (totalPages <= 1) return;

            function addItem(label, page, disabled, active) {
                var li = document.createElement('li');
                li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
                var a = document.createElement('a');
                a.className = 'page-link';
                a.href = '#';
                a.textContent = label;
                a.addEventListener('click', function (e) {
                    e.preventDefault();
                    if (disabled) return;
                    currentPage = page;
                    renderTable();
                });
                li.appendChild(a);
                routerPaginationEl.appendChild(li);
            }

            addItem('‹', Math.max(1, currentPage - 1), currentPage === 1, false);

            var start = Math.max(1, currentPage - 2);
            var end = Math.min(totalPages, start + 4);
            start = Math.max(1, end - 4);
            for (var p = start; p <= end; p++) {
                addItem(String(p), p, false, p === currentPage);
            }

            addItem('›', Math.min(totalPages, currentPage + 1), currentPage === totalPages, false);
        }

        function renderTable() {
            if (!routerTbody) return;
            var rows = filteredRows();
            var size = getPageSize();
            var total = rows.length;
            var totalPages = Math.max(1, Math.ceil(total / size));
            if (currentPage > totalPages) currentPage = totalPages;
            if (currentPage < 1) currentPage = 1;

            allRouterRows.forEach(function (tr) { tr.style.display = 'none'; });
            var startIdx = (currentPage - 1) * size;
            var endIdx = startIdx + size;
            rows.slice(startIdx, endIdx).forEach(function (tr) { tr.style.display = ''; });

            if (routerCountEl) {
                if (total === 0) {
                    routerCountEl.textContent = '0 results';
                } else {
                    routerCountEl.textContent = 'Showing ' + (startIdx + 1) + '–' + Math.min(endIdx, total) + ' of ' + total;
                }
            }
            renderPagination(totalPages);
        }

        if (routerSearchEl) {
            routerSearchEl.addEventListener('input', function () {
                currentPage = 1;
                renderTable();
            });
        }
        if (routerPageSizeEl) {
            routerPageSizeEl.addEventListener('change', function () {
                currentPage = 1;
                renderTable();
            });
        }
        renderTable();
    });
</script>
<?php
$contentHtml = ob_get_clean();
require __DIR__ . '/_partials/layout.php';
