<?php
require __DIR__ . '/_lib/bootstrap.php';

$title = 'Status';
$active = 'status';

$pdo = db();

$counts = [
    'app_total' => 0,
    'app_error_24h' => 0,
    'radius_acct_total' => 0,
    'radius_acct_24h' => 0,
];
try {
    $counts['app_total'] = (int)$pdo->query("SELECT COUNT(*) FROM app_logs")->fetchColumn();
    $counts['app_error_24h'] = (int)$pdo->query("SELECT COUNT(*) FROM app_logs WHERE level = 'error' AND ts >= UNIX_TIMESTAMP(UTC_TIMESTAMP() - INTERVAL 24 HOUR)")->fetchColumn();
    $counts['radius_acct_total'] = (int)$pdo->query("SELECT COUNT(*) FROM radius_accounting")->fetchColumn();
    $counts['radius_acct_24h'] = (int)$pdo->query("SELECT COUNT(*) FROM radius_accounting WHERE ts >= UNIX_TIMESTAMP(UTC_TIMESTAMP() - INTERVAL 24 HOUR)")->fetchColumn();
} catch (Throwable $e) {
}

$apps = [];
try {
    $apps = $pdo->query(
        "SELECT ts, level, category, message, context_json
         FROM app_logs
         ORDER BY ts DESC, id DESC
         LIMIT 300"
    )->fetchAll();
    if (!is_array($apps)) {
        $apps = [];
    }
} catch (Throwable $e) {
    $apps = [];
}

$acct = [];
try {
    $acct = $pdo->query(
        "SELECT ts, nas_ip, username, status_type, session_id, input_octets, output_octets, session_time
         FROM radius_accounting
         ORDER BY ts DESC, id DESC
         LIMIT 120"
    )->fetchAll();
    if (!is_array($acct)) {
        $acct = [];
    }
} catch (Throwable $e) {
    $acct = [];
}

$acctErr = [];
try {
    $acctErr = $pdo->query(
        "SELECT ts, router_ip, peer_ip, nas_ip, username, status_type, error_type, message
         FROM radius_accounting_errors
         ORDER BY ts DESC, id DESC
         LIMIT 120"
    )->fetchAll();
    if (!is_array($acctErr)) {
        $acctErr = [];
    }
} catch (Throwable $e) {
    $acctErr = [];
}

$store = store_load();
$routers = array_map('router_normalize', $store['routers']);
$routerByIp = [];
foreach ($routers as $r) {
    $ip = trim((string)($r['ip'] ?? ''));
    if ($ip === '') {
        continue;
    }
    $routerByIp[$ip] = (string)($r['name'] !== '' ? $r['name'] : $ip);
}

function app_level_badge(string $lvl): string
{
    $lvl = strtolower(trim($lvl));
    if ($lvl === 'error') {
        return 'danger';
    }
    if ($lvl === 'warning') {
        return 'warning';
    }
    if ($lvl === 'debug') {
        return 'secondary';
    }
    return 'primary';
}

ob_start();
?>
<style>
    @media (max-width:575.98px){
        .status-table{font-size:.84rem}
        .status-table .badge{font-size:.70rem}
    }
</style>

<div class="row g-3">
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <div class="h6 mb-0">System Status</div>
                        <div class="small text-body-secondary">Recent events from RADIUS, login, and MikroTik connections.</div>
                    </div>
                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo e(base_url('status.php')); ?>">
                        <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                    </a>
                </div>
                <div class="row g-2 mt-3">
                    <div class="col-12 col-md-3">
                        <div class="border rounded p-2">
                            <div class="small text-body-secondary">App logs</div>
                            <div class="metric"><?php echo e(number_format($counts['app_total'])); ?></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="border rounded p-2">
                            <div class="small text-body-secondary">Errors (24h)</div>
                            <div class="metric text-danger"><?php echo e(number_format($counts['app_error_24h'])); ?></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="border rounded p-2">
                            <div class="small text-body-secondary">Accounting logs</div>
                            <div class="metric"><?php echo e(number_format($counts['radius_acct_total'])); ?></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="border rounded p-2">
                            <div class="small text-body-secondary">Accounting (24h)</div>
                            <div class="metric"><?php echo e(number_format($counts['radius_acct_24h'])); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="h6 mb-0">RADIUS Accounting (Latest)</div>
                <div class="small text-body-secondary mt-1">From database table <span class="font-monospace">radius_accounting</span>.</div>
                <div class="table-responsive mt-3">
                    <table class="table table-sm align-middle status-table">
                        <thead>
                        <tr>
                            <th>Time</th>
                            <th>Router</th>
                            <th>User</th>
                            <th>Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (count($acct) === 0): ?>
                            <tr>
                                <td colspan="4" class="text-body-secondary">No accounting logs.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($acct as $r): ?>
                                <?php
                                $ts = (int)($r['ts'] ?? 0);
                                $nas = (string)($r['nas_ip'] ?? '');
                                $routerName = $routerByIp[$nas] ?? ($nas !== '' ? $nas : '-');
                                $user = (string)($r['username'] ?? '');
                                $st = (string)($r['status_type'] ?? '');
                                ?>
                                <tr>
                                    <td class="font-monospace"><?php echo e($ts > 0 ? gmdate('Y-m-d H:i:s', $ts) : '-'); ?></td>
                                    <td>
                                        <div class="fw-semibold"><?php echo e($routerName); ?></div>
                                        <div class="small text-body-secondary font-monospace"><?php echo e($nas !== '' ? $nas : '-'); ?></div>
                                    </td>
                                    <td class="font-monospace"><?php echo e($user !== '' ? $user : '-'); ?></td>
                                    <td><?php echo e($st !== '' ? $st : '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-2">
                    <a class="btn btn-sm btn-outline-primary" href="<?php echo e(base_url('radius-accounting.php')); ?>">
                        Open full accounting
                    </a>
                    <a class="btn btn-sm btn-outline-danger ms-2" href="<?php echo e(base_url('radius-accounting-errors.php')); ?>">
                        Open accounting errors
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="h6 mb-0">App Logs (Latest)</div>
                <div class="small text-body-secondary mt-1">Includes login, MikroTik API, and RADIUS accept/reject/drop.</div>
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3">
                    <div class="input-group input-group-sm flex-grow-0" style="width: 320px; max-width: 100%;">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input class="form-control" id="slSearch" placeholder="Search level / category / message">
                    </div>
                    <select class="form-select form-select-sm" id="slLevel" style="width: 140px;">
                        <option value="" selected>All levels</option>
                        <option value="error">Error</option>
                        <option value="warning">Warning</option>
                        <option value="info">Info</option>
                        <option value="debug">Debug</option>
                    </select>
                </div>
                <div class="table-responsive mt-3">
                    <table class="table table-sm align-middle status-table">
                        <thead>
                        <tr>
                            <th>Time</th>
                            <th>Level</th>
                            <th>Category</th>
                            <th>Message</th>
                        </tr>
                        </thead>
                        <tbody id="slBody">
                        <?php if (count($apps) === 0): ?>
                            <tr>
                                <td colspan="4" class="text-body-secondary">No app logs.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($apps as $a): ?>
                                <?php
                                $ts = (int)($a['ts'] ?? 0);
                                $lvl = (string)($a['level'] ?? '');
                                $cat = (string)($a['category'] ?? '');
                                $msg = (string)($a['message'] ?? '');
                                $ctx = (string)($a['context_json'] ?? '');
                                $search = strtolower(trim($lvl . ' ' . $cat . ' ' . $msg . ' ' . $ctx));
                                ?>
                                <tr data-search="<?php echo e($search); ?>" data-level="<?php echo e(strtolower($lvl)); ?>">
                                    <td class="font-monospace"><?php echo e($ts > 0 ? gmdate('Y-m-d H:i:s', $ts) : '-'); ?></td>
                                    <td><span class="badge text-bg-<?php echo e(app_level_badge($lvl)); ?>"><?php echo e($lvl !== '' ? strtoupper($lvl) : 'INFO'); ?></span></td>
                                    <td class="font-monospace"><?php echo e($cat !== '' ? $cat : '-'); ?></td>
                                    <td>
                                        <div><?php echo e($msg); ?></div>
                                        <?php if ($ctx !== ''): ?>
                                            <div class="small text-body-secondary font-monospace text-truncate" style="max-width: 520px;">
                                                <?php echo e($ctx); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="small text-body-secondary mt-2">
                    For full RADIUS daemon output, use <span class="font-monospace">journalctl -u brhs-radiusd.service -f</span> on the server.
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="h6 mb-0">RADIUS Accounting Errors (Latest)</div>
                <div class="small text-body-secondary mt-1">Shown when accounting packets are dropped or DB insert fails.</div>
                <div class="table-responsive mt-3">
                    <table class="table table-sm align-middle status-table">
                        <thead>
                        <tr>
                            <th>Time</th>
                            <th>Type</th>
                            <th>Router/NAS</th>
                            <th>User</th>
                            <th>Message</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (count($acctErr) === 0): ?>
                            <tr>
                                <td colspan="5" class="text-body-secondary">No accounting errors.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($acctErr as $r): ?>
                                <?php
                                $ts = (int)($r['ts'] ?? 0);
                                $typ = (string)($r['error_type'] ?? '');
                                $routerIp = (string)($r['router_ip'] ?? '');
                                $peerIp = (string)($r['peer_ip'] ?? '');
                                $nasIp = (string)($r['nas_ip'] ?? '');
                                $user = (string)($r['username'] ?? '');
                                $msg = (string)($r['message'] ?? '');
                                $badge = strtolower($typ) === 'error' ? 'danger' : 'warning';
                                $ipLine = trim(($routerIp !== '' ? $routerIp : '') . ($nasIp !== '' && $nasIp !== $routerIp ? ' / ' . $nasIp : '') . ($peerIp !== '' && $peerIp !== $routerIp ? ' / peer ' . $peerIp : ''));
                                ?>
                                <tr>
                                    <td class="font-monospace"><?php echo e($ts > 0 ? gmdate('Y-m-d H:i:s', $ts) : '-'); ?></td>
                                    <td><span class="badge text-bg-<?php echo e($badge); ?>"><?php echo e($typ !== '' ? strtoupper($typ) : 'WARN'); ?></span></td>
                                    <td class="font-monospace"><?php echo e($ipLine !== '' ? $ipLine : '-'); ?></td>
                                    <td class="font-monospace"><?php echo e($user !== '' ? $user : '-'); ?></td>
                                    <td><?php echo e($msg); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var tbody = document.getElementById('slBody');
    var searchEl = document.getElementById('slSearch');
    var levelEl = document.getElementById('slLevel');
    if (!tbody || !searchEl || !levelEl) return;

    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-search]'));
    if (rows.length === 0) return;

    function apply() {
        var q = String(searchEl.value || '').trim().toLowerCase();
        var lvl = String(levelEl.value || '').trim().toLowerCase();
        var shown = 0;
        rows.forEach(function (tr) {
            var s = (tr.getAttribute('data-search') || '').toLowerCase();
            var rowLvl = (tr.getAttribute('data-level') || '').toLowerCase();
            var ok = true;
            if (q && s.indexOf(q) === -1) ok = false;
            if (lvl && rowLvl !== lvl) ok = false;
            tr.style.display = ok ? '' : 'none';
            if (ok) shown += 1;
        });
    }

    searchEl.addEventListener('input', apply);
    levelEl.addEventListener('change', apply);
})();
</script>
<?php
$contentHtml = ob_get_clean();
require __DIR__ . '/_partials/layout.php';
