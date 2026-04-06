<?php
require __DIR__ . '/_lib/bootstrap.php';

$title = 'RADIUS Server';
$active = 'radius_server';

$pdo = db();
$rows = $pdo->query(
    "SELECT nas_ip, MAX(ts) AS last_ts, COUNT(*) AS c
     FROM radius_accounting
     GROUP BY nas_ip
     ORDER BY last_ts DESC"
)->fetchAll();
if (!is_array($rows)) {
    $rows = [];
}

$store = store_load();
$routers = array_map('router_normalize', $store['routers']);
$routerByIp = [];
foreach ($routers as $r) {
    $ip = trim((string)($r['ip'] ?? ''));
    if ($ip === '') {
        continue;
    }
    $routerByIp[$ip] = $r;
}

ob_start();
?>
<div class="row g-3">
    <div class="col-12 col-xl-6">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="h6 mb-2">Server Status</div>
                <div class="small text-body-secondary">
                    Start the RADIUS server on this portal host using:
                    <span class="font-monospace">php admin\\radiusd.php</span>
                </div>
                <div class="small text-body-secondary mt-2">
                    UDP Ports: <span class="font-monospace">1812</span> (Auth), <span class="font-monospace">1813</span> (Accounting)
                </div>
                <div class="mt-3">
                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo e(base_url('radius-server.php')); ?>">
                        <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                    </a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-6">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="h6 mb-2">Routers (RADIUS Clients)</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Router</th>
                            <th>RADIUS</th>
                            <th class="text-end">Last Accounting</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (count($routers) === 0): ?>
                            <tr><td colspan="3" class="text-body-secondary">No routers added.</td></tr>
                        <?php else: ?>
                            <?php
                            $lastMap = [];
                            foreach ($rows as $r) {
                                if (!is_array($r)) {
                                    continue;
                                }
                                $ip = (string)($r['nas_ip'] ?? '');
                                if ($ip === '') {
                                    continue;
                                }
                                $lastMap[$ip] = (int)($r['last_ts'] ?? 0);
                            }
                            ?>
                            <?php foreach ($routers as $rt): ?>
                                <?php
                                $ip = (string)($rt['ip'] ?? '');
                                $name = (string)($rt['name'] !== '' ? $rt['name'] : $ip);
                                $enabled = (int)($rt['radius_enabled'] ?? 0) === 1;
                                $last = (int)($lastMap[$ip] ?? 0);
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo e($name); ?></div>
                                        <div class="small text-body-secondary font-monospace"><?php echo e($ip); ?></div>
                                    </td>
                                    <td>
                                        <?php if ($enabled): ?>
                                            <span class="badge text-bg-success">Enabled</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">Disabled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end font-monospace">
                                        <?php echo e($last > 0 ? gmdate('Y-m-d H:i:s', $last) : '-'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="small text-body-secondary mt-2">
                    Configure each router secret in Router → Edit.
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$contentHtml = ob_get_clean();
require __DIR__ . '/_partials/layout.php';

