<?php
require __DIR__ . '/_lib/bootstrap.php';

$title = 'RADIUS Accounting';
$active = 'radius_acct';

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

$qUser = trim((string)($_GET['user'] ?? ''));
$qNas = trim((string)($_GET['nas'] ?? ''));
$qStatus = trim((string)($_GET['status'] ?? ''));

$where = [];
$params = [];
if ($qUser !== '') {
    $where[] = "username LIKE :u";
    $params[':u'] = '%' . $qUser . '%';
}
if ($qNas !== '') {
    $where[] = "nas_ip LIKE :nas";
    $params[':nas'] = '%' . $qNas . '%';
}
if ($qStatus !== '') {
    $where[] = "status_type = :st";
    $params[':st'] = $qStatus;
}
$sqlWhere = count($where) > 0 ? ('WHERE ' . implode(' AND ', $where)) : '';

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT ts, nas_ip, username, session_id, status_type, input_octets, output_octets, session_time
     FROM radius_accounting
     {$sqlWhere}
     ORDER BY ts DESC, id DESC
     LIMIT 200"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
if (!is_array($rows)) {
    $rows = [];
}

$statuses = ['Start', 'Interim-Update', 'Stop', 'Accounting-On', 'Accounting-Off'];

ob_start();
?>
<style>
    @media (max-width:575.98px){
        .ra-table{font-size:.84rem}
        .ra-table .badge{font-size:.70rem}
        .ra-table .btn{padding:.25rem .4rem}
    }
</style>
<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="h6 mb-0">Accounting Logs</div>
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo e(base_url('radius-accounting.php')); ?>">
                <i class="bi bi-arrow-clockwise me-1"></i>Refresh
            </a>
        </div>

        <form class="row g-2 mt-2" method="get">
            <div class="col-12 col-md-4">
                <input class="form-control form-control-sm" name="user" value="<?php echo e($qUser); ?>" placeholder="User">
            </div>
            <div class="col-12 col-md-4">
                <input class="form-control form-control-sm" name="nas" value="<?php echo e($qNas); ?>" placeholder="NAS IP">
            </div>
            <div class="col-12 col-md-3">
                <select class="form-select form-select-sm" name="status">
                    <option value="">All status</option>
                    <?php foreach ($statuses as $st): ?>
                        <option value="<?php echo e($st); ?>" <?php echo $qStatus === $st ? 'selected' : ''; ?>><?php echo e($st); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-1 d-grid">
                <button class="btn btn-sm btn-primary" type="submit">Go</button>
            </div>
        </form>

        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3">
            <div class="input-group input-group-sm flex-grow-0" style="width: 320px; max-width: 100%;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input class="form-control" id="raSearch" placeholder="Search router / user / status">
            </div>
            <select class="form-select form-select-sm" id="raRows" style="width: 110px;">
                <option value="10" selected>10 rows</option>
                <option value="25">25 rows</option>
                <option value="50">50 rows</option>
                <option value="100">100 rows</option>
            </select>
        </div>

        <div class="table-responsive mt-3">
            <table class="table table-sm align-middle ra-table">
                <thead>
                <tr>
                    <th>Time</th>
                    <th>Router</th>
                    <th>User</th>
                    <th class="d-none d-sm-table-cell">Status</th>
                    <th class="d-none d-sm-table-cell">Session</th>
                    <th class="text-end d-none d-sm-table-cell">In</th>
                    <th class="text-end d-none d-sm-table-cell">Out</th>
                    <th class="text-end d-none d-sm-table-cell">Time</th>
                </tr>
                </thead>
                <tbody id="raBody">
                <?php if (count($rows) === 0): ?>
                    <tr>
                        <td colspan="8" class="text-body-secondary">No accounting logs.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $ts = (int)($r['ts'] ?? 0);
                        $nas = (string)($r['nas_ip'] ?? '');
                        $routerName = $routerByIp[$nas] ?? ($nas !== '' ? $nas : '-');
                        $user = (string)($r['username'] ?? '');
                        $st = (string)($r['status_type'] ?? '');
                        $sid = (string)($r['session_id'] ?? '');
                        $inb = (int)($r['input_octets'] ?? 0);
                        $outb = (int)($r['output_octets'] ?? 0);
                        $stime = (int)($r['session_time'] ?? 0);
                        $searchBlob = strtolower(trim($routerName . ' ' . $nas . ' ' . $user . ' ' . $st . ' ' . $sid));
                        ?>
                        <tr data-search="<?php echo e($searchBlob); ?>">
                            <td class="font-monospace"><?php echo e($ts > 0 ? gmdate('Y-m-d H:i:s', $ts) : '-'); ?></td>
                            <td>
                                <div class="fw-semibold"><?php echo e($routerName); ?></div>
                                <div class="small text-body-secondary font-monospace"><?php echo e($nas); ?></div>
                            </td>
                            <td class="font-monospace">
                                <?php echo e($user !== '' ? $user : '-'); ?>
                                <div class="d-sm-none mt-1 fw-normal">
                                    <?php if ($st !== ''): ?>
                                        <span class="badge text-bg-light border"><?php echo e($st); ?></span>
                                    <?php endif; ?>
                                    <?php if ($sid !== ''): ?>
                                        <span class="badge text-bg-light border font-monospace"><?php echo e($sid); ?></span>
                                    <?php endif; ?>
                                    <span class="badge text-bg-light border font-monospace">In: <?php echo e(number_format($inb)); ?></span>
                                    <span class="badge text-bg-light border font-monospace">Out: <?php echo e(number_format($outb)); ?></span>
                                </div>
                            </td>
                            <td class="d-none d-sm-table-cell"><?php echo e($st !== '' ? $st : '-'); ?></td>
                            <td class="font-monospace d-none d-sm-table-cell"><?php echo e($sid !== '' ? $sid : '-'); ?></td>
                            <td class="text-end font-monospace d-none d-sm-table-cell"><?php echo e(number_format($inb)); ?></td>
                            <td class="text-end font-monospace d-none d-sm-table-cell"><?php echo e(number_format($outb)); ?></td>
                            <td class="text-end font-monospace d-none d-sm-table-cell"><?php echo e($stime > 0 ? (string)$stime : '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="d-flex align-items-center justify-content-between mt-2">
            <div class="small text-danger" id="raInfo" style="min-height: 18px;"></div>
            <nav>
                <ul class="pagination pagination-sm mb-0" id="raPagination"></ul>
            </nav>
        </div>
    </div>
</div>
<script>
(function () {
    var tbody = document.getElementById('raBody');
    var searchEl = document.getElementById('raSearch');
    var rowsEl = document.getElementById('raRows');
    var infoEl = document.getElementById('raInfo');
    var pagEl = document.getElementById('raPagination');
    if (!tbody || !searchEl || !rowsEl || !infoEl || !pagEl) return;

    var allRows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-search]'));
    if (allRows.length === 0) {
        infoEl.textContent = '';
        return;
    }

    var page = 1;

    function perPage() {
        var v = parseInt(String(rowsEl.value || '10'), 10);
        return v > 0 ? v : 10;
    }

    function query() {
        return String(searchEl.value || '').trim().toLowerCase();
    }

    function filtered() {
        var q = query();
        if (!q) return allRows;
        return allRows.filter(function (tr) {
            var s = (tr.getAttribute('data-search') || '').toLowerCase();
            return s.indexOf(q) !== -1;
        });
    }

    function renderPagination(totalPages) {
        pagEl.innerHTML = '';
        if (totalPages <= 1) return;

        function addItem(label, p, disabled, active) {
            var li = document.createElement('li');
            li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
            var a = document.createElement('a');
            a.className = 'page-link';
            a.href = '#';
            a.textContent = label;
            a.addEventListener('click', function (e) {
                e.preventDefault();
                if (disabled) return;
                page = p;
                render();
            });
            li.appendChild(a);
            pagEl.appendChild(li);
        }

        addItem('‹', Math.max(1, page - 1), page === 1, false);

        var start = Math.max(1, page - 2);
        var end = Math.min(totalPages, start + 4);
        start = Math.max(1, end - 4);
        for (var i = start; i <= end; i++) {
            addItem(String(i), i, false, i === page);
        }

        addItem('›', Math.min(totalPages, page + 1), page === totalPages, false);
    }

    function render() {
        var rows = filtered();
        var total = rows.length;
        var size = perPage();
        var totalPages = Math.max(1, Math.ceil(total / size));
        if (page > totalPages) page = totalPages;
        if (page < 1) page = 1;

        allRows.forEach(function (tr) { tr.style.display = 'none'; });

        var startIdx = (page - 1) * size;
        var endIdx = startIdx + size;
        rows.slice(startIdx, endIdx).forEach(function (tr) { tr.style.display = ''; });

        if (total === 0) {
            infoEl.textContent = '0 results';
        } else {
            infoEl.textContent = 'Showing ' + (startIdx + 1) + '–' + Math.min(endIdx, total) + ' of ' + total;
        }

        renderPagination(totalPages);
    }

    searchEl.addEventListener('input', function () {
        page = 1;
        render();
    });
    rowsEl.addEventListener('change', function () {
        page = 1;
        render();
    });
    render();
})();
</script>
<?php
$contentHtml = ob_get_clean();
require __DIR__ . '/_partials/layout.php';
