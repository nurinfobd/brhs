<?php
require __DIR__ . '/_lib/bootstrap.php';

$title = 'Live Monitor';
$active = 'live';

$store = store_load();
$routers = array_map('router_normalize', $store['routers']);

$isAjax = (string)($_GET['ajax'] ?? '') === '1';
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');

    $now = microtime(true);
    if (!isset($_SESSION['lm_rate_cache']) || !is_array($_SESSION['lm_rate_cache'])) {
        $_SESSION['lm_rate_cache'] = [];
    }
    $cache = (array)$_SESSION['lm_rate_cache'];

    $sessionsOut = [];
    $seen = [];

    foreach ($routers as $router) {
        $routerId = (string)($router['id'] ?? '');
        $routerName = (string)($router['name'] !== '' ? $router['name'] : ($router['ip'] !== '' ? $router['ip'] : 'Router'));

        $sessions = mikrotik_hotspot_active($router);
        foreach ($sessions as $s) {
            if (!is_array($s)) {
                continue;
            }
            $uName = trim((string)($s['user'] ?? ''));
            $uIp = trim((string)($s['address'] ?? ''));
            if ($uName === '' && $uIp === '') {
                continue;
            }
            $sid = (string)($s['session_id'] ?? '');
            if ($sid === '') {
                $sid = (string)($s['id'] ?? '');
            }
            if ($sid === '') {
                $sid = $uName . '|' . $uIp;
            }
            $key = $routerId . '|' . $sid;
            $seen[$key] = true;

            $in = (int)($s['bytes_in'] ?? 0);
            $outb = (int)($s['bytes_out'] ?? 0);

            $rxBps = 0.0;
            $txBps = 0.0;
            if (isset($cache[$key]) && is_array($cache[$key])) {
                $prevIn = (int)($cache[$key]['in'] ?? 0);
                $prevOut = (int)($cache[$key]['out'] ?? 0);
                $prevTs = (float)($cache[$key]['t'] ?? 0.0);
                $dt = $now - $prevTs;
                if ($dt > 0.2 && $dt <= 30) {
                    $dIn = $in - $prevIn;
                    $dOut = $outb - $prevOut;
                    if ($dIn < 0) {
                        $dIn = 0;
                    }
                    if ($dOut < 0) {
                        $dOut = 0;
                    }
                    $rxBps = ($dIn * 8) / $dt;
                    $txBps = ($dOut * 8) / $dt;
                }
            }

            $cache[$key] = ['in' => $in, 'out' => $outb, 't' => $now];

            $fmt = function (float $bps): string {
                if ($bps < 1000) {
                    return number_format($bps, 0) . ' bps';
                }
                if ($bps < 1000 * 1000) {
                    return number_format($bps / 1000, 1) . ' Kbps';
                }
                return number_format($bps / 1000 / 1000, 2) . ' Mbps';
            };

            $sessionsOut[] = [
                'key' => $key,
                'router' => $routerName,
                'user' => $uName,
                'address' => $uIp,
                'uptime' => (string)($s['uptime'] ?? ''),
                'tx' => $fmt($txBps),
                'rx' => $fmt($rxBps),
            ];
        }
    }

    foreach (array_keys($cache) as $k) {
        if (!isset($seen[$k])) {
            unset($cache[$k]);
        }
    }
    $_SESSION['lm_rate_cache'] = $cache;

    echo json_encode(['ok' => true, 'count' => count($sessionsOut), 'sessions' => $sessionsOut], JSON_UNESCAPED_SLASHES);
    exit;
}

$allSessions = [];
$now = time();
$pdo = db();
$maxUsers24h = 0;
$activeCounts = [];

foreach ($routers as $router) {
    $routerId = $router['id'];
    $routerName = $router['name'] !== '' ? $router['name'] : ($router['ip'] !== '' ? $router['ip'] : 'Router');

    if (router_status($router) !== 'online') {
        continue;
    }

    $sessions = mikrotik_hotspot_active($router);
    if ($routerId !== '') {
        $activeCounts[$routerId] = count($sessions);
    }

    foreach ($sessions as $s) {
        $allSessions[] = [
            'router' => $routerName,
            'user' => $s['user'],
            'address' => $s['address'],
            'tx_rate' => $s['tx_rate'],
            'rx_rate' => $s['rx_rate'],
            'uptime' => $s['uptime'],
            'bytes_in' => $s['bytes_in'],
            'bytes_out' => $s['bytes_out'],
        ];
    }
}

if (count($activeCounts) > 0) {
    $insert = $pdo->prepare("INSERT INTO router_stats (router_id, ts, active_users) VALUES (:rid, :ts, :v)");
    foreach ($activeCounts as $rid => $v) {
        $insert->execute([
            ':rid' => $rid,
            ':ts' => $now,
            ':v' => (int)$v,
        ]);
    }
}

$cutoff = $now - 86400;
$stmt = $pdo->prepare("SELECT router_id, MAX(active_users) AS m FROM router_stats WHERE ts >= :cutoff GROUP BY router_id");
$stmt->execute([':cutoff' => $cutoff]);
$maxRows = $stmt->fetchAll();
foreach ($maxRows as $r) {
    $maxUsers24h += (int)($r['m'] ?? 0);
}

$pdo->prepare("DELETE FROM router_stats WHERE ts < :old")->execute([':old' => $now - 86400 * 7]);

ob_start();
?>
<style>
    .lm-card{position:relative;overflow:hidden;border-radius:.55rem;border:1px solid rgba(0,0,0,.06);background:var(--bs-body-bg)}
    [data-bs-theme="dark"] .lm-card{border-color:rgba(255,255,255,.10)}
    .lm-card::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--lm-accent,#0d6efd)}
    .lm-icon{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;position:absolute;top:10px;right:10px;background:var(--lm-pill-bg,rgba(13,110,253,.12));color:var(--lm-pill-text,#0d6efd)}
    .lm-sub{font-size:.78rem;color:rgba(108,117,125,1)}
    [data-bs-theme="dark"] .lm-sub{color:rgba(173,181,189,1)}
    .lm-kpi .card-body{padding:.9rem 1rem;padding-right:3.25rem}
    .lm-badge{display:inline-flex;gap:.35rem;align-items:center;padding:.35rem .55rem;border-radius:999px;font-weight:600;font-size:.80rem;white-space:nowrap}
    .lm-badge-tx{background:rgba(25,135,84,.12);color:rgba(25,135,84,1)}
    .lm-badge-rx{background:rgba(13,110,253,.12);color:rgba(13,110,253,1)}
    [data-bs-theme="dark"] .lm-badge-tx{background:rgba(25,135,84,.22);color:rgba(111, 216, 172, 1)}
    [data-bs-theme="dark"] .lm-badge-rx{background:rgba(13,110,253,.22);color:rgba(109, 168, 255, 1)}
</style>
<div class="row g-3">
    <div class="col-6 col-lg-4">
        <div class="card lm-card lm-kpi shadow-sm" style="--lm-accent:#198754;--lm-pill-bg:rgba(25,135,84,.12);--lm-pill-text:#198754">
            <div class="card-body">
                <div class="lm-icon"><i class="bi bi-wifi"></i></div>
                <div class="text-body-secondary small">Connected Online Hotspot Users</div>
                <div class="metric" id="lmCount"><?php echo e((string)count($allSessions)); ?></div>
                <div class="lm-sub mt-1">Realtime update (every 1 second)</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="card lm-card lm-kpi shadow-sm" style="--lm-accent:#0dcaf0;--lm-pill-bg:rgba(13,202,240,.14);--lm-pill-text:#0dcaf0">
            <div class="card-body">
                <div class="lm-icon"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="text-body-secondary small">Max Users (24 hrs)</div>
                <div class="metric"><?php echo e((string)$maxUsers24h); ?></div>
                <div class="lm-sub mt-1">Sum of per-router peak active users</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="card lm-card lm-kpi shadow-sm" style="--lm-accent:#fd7e14;--lm-pill-bg:rgba(253,126,20,.14);--lm-pill-text:#fd7e14">
            <div class="card-body">
                <div class="lm-icon"><i class="bi bi-router"></i></div>
                <div class="text-body-secondary small">Offline Routers</div>
                <?php
                $offline = 0;
                foreach ($routers as $r) {
                    if (router_status($r) !== 'online') {
                        $offline++;
                    }
                }
                ?>
                <div class="metric"><?php echo e((string)$offline); ?></div>
                <div class="lm-sub mt-1">Routers not reachable right now</div>
            </div>
        </div>
    </div>
</div>

<div class="card lm-card shadow-sm mt-3" style="--lm-accent:#0d6efd;--lm-pill-bg:rgba(13,110,253,.12);--lm-pill-text:#0d6efd">
    <div class="card-body">
        <div class="lm-icon"><i class="bi bi-activity"></i></div>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="h6 mb-0">Online Hotspot Users</div>
        </div>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3">
            <div class="input-group input-group-sm flex-grow-0" style="width: 320px; max-width: 100%;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input class="form-control" id="lmSearch" placeholder="Search router / user / ip">
            </div>
            <div class="d-flex align-items-center gap-2">
                <select class="form-select form-select-sm" id="lmRows" style="width: 110px;">
                    <option value="10" selected>10 rows</option>
                    <option value="25">25 rows</option>
                    <option value="50">50 rows</option>
                    <option value="100">100 rows</option>
                </select>
                <a class="btn btn-sm btn-outline-secondary" href="<?php echo e(base_url('live-monitor.php')); ?>">Refresh</a>
            </div>
        </div>
        <div class="table-responsive mt-3">
            <table class="table table-sm align-middle">
                <thead>
                <tr>
                    <th>Router</th>
                    <th>User</th>
                    <th>IP</th>
                    <th>Live Tx</th>
                    <th>Live Rx</th>
                    <th>Uptime</th>
                </tr>
                </thead>
                <tbody id="lmBody">
                <?php if (count($allSessions) === 0): ?>
                    <tr>
                        <td colspan="6" class="text-body-secondary">No active hotspot users (or router API not configured).</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($allSessions as $row): ?>
                        <tr class="lm-row">
                            <td class="fw-semibold"><?php echo e($row['router']); ?></td>
                            <td><?php echo e($row['user']); ?></td>
                            <td><?php echo e($row['address']); ?></td>
                            <td>
                                <span class="lm-badge lm-badge-tx">
                                    <i class="bi bi-arrow-up-right"></i>
                                    <span class="font-monospace"><?php echo e($row['tx_rate'] !== '' ? $row['tx_rate'] : '-'); ?></span>
                                </span>
                            </td>
                            <td>
                                <span class="lm-badge lm-badge-rx">
                                    <i class="bi bi-arrow-down-left"></i>
                                    <span class="font-monospace"><?php echo e($row['rx_rate'] !== '' ? $row['rx_rate'] : '-'); ?></span>
                                </span>
                            </td>
                            <td class="font-monospace"><?php echo e($row['uptime'] !== '' ? $row['uptime'] : '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="d-flex align-items-center justify-content-between mt-2">
            <div class="small text-danger" id="lmInfo" style="min-height: 18px;"></div>
            <nav>
                <ul class="pagination pagination-sm mb-0" id="lmPagination"></ul>
            </nav>
        </div>
    </div>
</div>
<script>
(function () {
    var busy = false;
    var state = { q: '', perPage: 10, page: 1, rows: [] };
    function esc(s) {
        return String(s || '').replace(/[&<>"']/g, function (c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);
        });
    }

    function render() {
        var body = document.getElementById('lmBody');
        var infoEl = document.getElementById('lmInfo');
        var pagEl = document.getElementById('lmPagination');
        if (!body || !pagEl) return;

        var q = (state.q || '').trim().toLowerCase();
        var filtered = (state.rows || []).filter(function (r) {
            if (!q) return true;
            var blob = (r.search || '').toLowerCase();
            return blob.indexOf(q) !== -1;
        });

        var total = filtered.length;
        var perPage = state.perPage || 10;
        var pages = Math.max(1, Math.ceil(total / perPage));
        if (state.page > pages) state.page = pages;
        if (state.page < 1) state.page = 1;
        var start = (state.page - 1) * perPage;
        var end = start + perPage;
        var pageRows = filtered.slice(start, end);

        if (infoEl) {
            if (total === 0) {
                infoEl.textContent = '0 results';
            } else {
                infoEl.textContent = 'Showing ' + (start + 1) + '–' + Math.min(end, total) + ' of ' + total;
            }
        }

        if (pageRows.length === 0) {
            body.innerHTML = '<tr><td colspan="6" class="text-body-secondary">No active hotspot users.</td></tr>';
        } else {
            var html = '';
            pageRows.forEach(function (row) {
                html += '<tr class="lm-row">'
                    + '<td class="fw-semibold">' + esc(row.router) + '</td>'
                    + '<td>' + esc(row.user) + '</td>'
                    + '<td>' + esc(row.address) + '</td>'
                    + '<td><span class="lm-badge lm-badge-tx"><i class="bi bi-arrow-up-right"></i><span class="font-monospace">' + esc(row.tx) + '</span></span></td>'
                    + '<td><span class="lm-badge lm-badge-rx"><i class="bi bi-arrow-down-left"></i><span class="font-monospace">' + esc(row.rx) + '</span></span></td>'
                    + '<td class="font-monospace">' + esc(row.uptime || '-') + '</td>'
                    + '</tr>';
            });
            body.innerHTML = html;
        }

        function li(label, page, disabled, active) {
            var cls = 'page-item';
            if (disabled) cls += ' disabled';
            if (active) cls += ' active';
            return '<li class="' + cls + '"><a class="page-link" href="#" data-page="' + page + '">' + label + '</a></li>';
        }

        var pHtml = '';
        pHtml += li('&laquo;', state.page - 1, state.page === 1, false);
        var maxBtns = 7;
        var half = Math.floor(maxBtns / 2);
        var pStart = Math.max(1, state.page - half);
        var pEnd = Math.min(pages, pStart + maxBtns - 1);
        pStart = Math.max(1, pEnd - maxBtns + 1);
        for (var p = pStart; p <= pEnd; p++) {
            pHtml += li(String(p), p, false, p === state.page);
        }
        pHtml += li('&raquo;', state.page + 1, state.page === pages, false);
        pagEl.innerHTML = pHtml;
    }

    function tick() {
        if (busy) return;
        busy = true;
        fetch('<?php echo e(base_url('live-monitor.php')); ?>?ajax=1', { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.ok !== true) return;
                var countEl = document.getElementById('lmCount');
                if (countEl) countEl.textContent = String(data.count || 0);
                var rows = data.sessions || [];
                state.rows = rows.map(function (r) {
                    var router = String(r.router || '');
                    var user = String(r.user || '');
                    var ip = String(r.address || '');
                    return {
                        router: router,
                        user: user,
                        address: ip,
                        tx: String(r.tx || ''),
                        rx: String(r.rx || ''),
                        uptime: String(r.uptime || ''),
                        search: (router + ' ' + user + ' ' + ip).toLowerCase()
                    };
                });
                render();
            })
            .catch(function () {})
            .finally(function () { busy = false; });
    }

    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!(t instanceof HTMLElement)) return;
        if (!t.matches('#lmPagination a.page-link')) return;
        e.preventDefault();
        var p = parseInt(String(t.getAttribute('data-page') || ''), 10);
        if (!p || p < 1) return;
        state.page = p;
        render();
    });

    var searchEl = document.getElementById('lmSearch');
    if (searchEl) {
        searchEl.addEventListener('input', function () {
            state.q = String(searchEl.value || '');
            state.page = 1;
            render();
        });
    }
    var rowsEl = document.getElementById('lmRows');
    if (rowsEl) {
        rowsEl.addEventListener('change', function () {
            var v = parseInt(String(rowsEl.value || '10'), 10);
            state.perPage = v > 0 ? v : 10;
            state.page = 1;
            render();
        });
    }

    tick();
    setInterval(tick, 1000);
})();
</script>
<?php
$contentHtml = ob_get_clean();
require __DIR__ . '/_partials/layout.php';
