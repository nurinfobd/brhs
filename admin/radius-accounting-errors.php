<?php
require __DIR__ . '/_lib/bootstrap.php';

$title = 'RADIUS Accounting Errors';
$active = 'status';

$qUser = trim((string)($_GET['user'] ?? ''));
$qNas = trim((string)($_GET['nas'] ?? ''));
$qType = trim((string)($_GET['type'] ?? ''));

$where = [];
$params = [];
if ($qUser !== '') {
    $where[] = "username LIKE :u";
    $params[':u'] = '%' . $qUser . '%';
}
if ($qNas !== '') {
    $where[] = "(router_ip LIKE :nas OR peer_ip LIKE :nas OR nas_ip LIKE :nas)";
    $params[':nas'] = '%' . $qNas . '%';
}
if ($qType !== '') {
    $where[] = "error_type = :t";
    $params[':t'] = $qType;
}
$sqlWhere = count($where) > 0 ? ('WHERE ' . implode(' AND ', $where)) : '';

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT ts, router_ip, peer_ip, nas_ip, username, session_id, status_type, error_type, message, raw_attrs
     FROM radius_accounting_errors
     {$sqlWhere}
     ORDER BY ts DESC, id DESC
     LIMIT 300"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
if (!is_array($rows)) {
    $rows = [];
}

$types = ['error', 'warning'];

ob_start();
?>
<style>
    @media (max-width:575.98px){
        .rae-table{font-size:.84rem}
        .rae-table .badge{font-size:.70rem}
    }
</style>
<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="h6 mb-0">Accounting Errors</div>
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo e(base_url('radius-accounting-errors.php')); ?>">
                <i class="bi bi-arrow-clockwise me-1"></i>Refresh
            </a>
        </div>

        <form class="row g-2 mt-2" method="get">
            <div class="col-12 col-md-4">
                <input class="form-control form-control-sm" name="user" value="<?php echo e($qUser); ?>" placeholder="User">
            </div>
            <div class="col-12 col-md-4">
                <input class="form-control form-control-sm" name="nas" value="<?php echo e($qNas); ?>" placeholder="Router/NAS/Peer IP">
            </div>
            <div class="col-12 col-md-3">
                <select class="form-select form-select-sm" name="type">
                    <option value="">All types</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?php echo e($t); ?>" <?php echo $qType === $t ? 'selected' : ''; ?>><?php echo e($t); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-1 d-grid">
                <button class="btn btn-sm btn-primary" type="submit">Go</button>
            </div>
        </form>

        <div class="table-responsive mt-3">
            <table class="table table-sm align-middle rae-table">
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
                <?php if (count($rows) === 0): ?>
                    <tr>
                        <td colspan="5" class="text-body-secondary">No errors.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $ts = (int)($r['ts'] ?? 0);
                        $typ = (string)($r['error_type'] ?? '');
                        $routerIp = (string)($r['router_ip'] ?? '');
                        $peerIp = (string)($r['peer_ip'] ?? '');
                        $nasIp = (string)($r['nas_ip'] ?? '');
                        $user = (string)($r['username'] ?? '');
                        $msg = (string)($r['message'] ?? '');
                        $statusType = (string)($r['status_type'] ?? '');
                        $sid = (string)($r['session_id'] ?? '');
                        $raw = (string)($r['raw_attrs'] ?? '');
                        $badge = $typ === 'error' ? 'danger' : 'warning';
                        $ipLine = trim(($routerIp !== '' ? $routerIp : '') . ($nasIp !== '' && $nasIp !== $routerIp ? ' / ' . $nasIp : '') . ($peerIp !== '' && $peerIp !== $routerIp ? ' / peer ' . $peerIp : ''));
                        ?>
                        <tr>
                            <td class="font-monospace"><?php echo e($ts > 0 ? gmdate('Y-m-d H:i:s', $ts) : '-'); ?></td>
                            <td><span class="badge text-bg-<?php echo e($badge); ?>"><?php echo e($typ !== '' ? strtoupper($typ) : 'WARN'); ?></span></td>
                            <td class="font-monospace">
                                <?php echo e($ipLine !== '' ? $ipLine : '-'); ?>
                                <?php if ($statusType !== '' || $sid !== ''): ?>
                                    <div class="small text-body-secondary">
                                        <?php echo e(trim(($statusType !== '' ? $statusType : '') . ($sid !== '' ? ' ' . $sid : ''))); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="font-monospace"><?php echo e($user !== '' ? $user : '-'); ?></td>
                            <td>
                                <div><?php echo e($msg); ?></div>
                                <?php if ($raw !== ''): ?>
                                    <div class="small text-body-secondary font-monospace text-truncate" style="max-width: 620px;"><?php echo e($raw); ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
$contentHtml = ob_get_clean();
require __DIR__ . '/_partials/layout.php';

