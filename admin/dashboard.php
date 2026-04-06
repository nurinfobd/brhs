<?php
require __DIR__ . '/_lib/bootstrap.php';

$title = 'Dashboard';
$active = 'dashboard';

function dec_str_norm(string $s): string
{
    $s = preg_replace('/[^0-9]+/', '', $s);
    if (!is_string($s) || $s === '') {
        return '0';
    }
    $s = ltrim($s, '0');
    return $s === '' ? '0' : $s;
}

function dec_str_cmp(string $a, string $b): int
{
    $a = dec_str_norm($a);
    $b = dec_str_norm($b);
    $la = strlen($a);
    $lb = strlen($b);
    if ($la !== $lb) {
        return $la < $lb ? -1 : 1;
    }
    if ($a === $b) {
        return 0;
    }
    return $a < $b ? -1 : 1;
}

function dec_str_sub(string $a, string $b): string
{
    $a = dec_str_norm($a);
    $b = dec_str_norm($b);
    if (dec_str_cmp($a, $b) < 0) {
        return '0';
    }

    $out = '';
    $carry = 0;
    $ai = strlen($a) - 1;
    $bi = strlen($b) - 1;
    while ($ai >= 0 || $bi >= 0) {
        $ad = $ai >= 0 ? (int)$a[$ai] : 0;
        $bd = $bi >= 0 ? (int)$b[$bi] : 0;
        $v = $ad - $carry - $bd;
        if ($v < 0) {
            $v += 10;
            $carry = 1;
        } else {
            $carry = 0;
        }
        $out = (string)$v . $out;
        $ai--;
        $bi--;
    }
    $out = ltrim($out, '0');
    return $out === '' ? '0' : $out;
}

$store = store_load();
$routers = array_map('router_normalize', $store['routers']);

$totalRouters = count($routers);
$totalUsers = 0;
$connectedUsers = 0;
$bandwidthTx = 0.0;
$bandwidthRx = 0.0;
$totalCapacity = 0.0;
$interfaceCards = [];
$routerStatusMap = [];
$routerMetricsMap = [];
$criticalAlerts = [];
$profileCards = [];
$profileUserCounts = [];

$pdo = db();
$profileCountsRows = $pdo->query(
    "SELECT profile, COUNT(*) AS c
     FROM radius_users
     WHERE COALESCE(profile, '') <> ''
     GROUP BY profile"
)->fetchAll();
if (is_array($profileCountsRows)) {
    foreach ($profileCountsRows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $p = trim((string)($r['profile'] ?? ''));
        if ($p === '') {
            continue;
        }
        $profileUserCounts[$p] = (int)($r['c'] ?? 0);
    }
}

foreach ($routers as $r) {
    $rid = (string)($r['id'] ?? '');
    $status = router_status($r);
    if ($rid !== '') {
        $routerStatusMap[$rid] = $status;
    }
    if ($status === 'online' && $rid !== '') {
        $routerMetricsMap[$rid] = mikrotik_router_metrics($r);
    }
}

foreach ($routers as $router) {
    $rid = (string)($router['id'] ?? '');
    if (($routerStatusMap[$rid] ?? 'offline') !== 'online') {
        continue;
    }
    $totalUsers += mikrotik_count_hotspot_users($router);
    $activeSessions = mikrotik_hotspot_active($router);
    $connectedUsers += count($activeSessions);
    $routerId = (string)($router['id'] ?? '');
    $routerName = (string)($router['name'] ?? '');
    if ($routerName === '') {
        $routerName = (string)($router['ip'] ?? 'Router');
    }

    $monitorItems = $routerId !== '' ? store_get_router_monitor_interfaces($routerId) : [];
    $ifaceCaps = [];
    foreach ($monitorItems as $mi) {
        if (!is_array($mi)) {
            continue;
        }
        $iface = trim((string)($mi['interface'] ?? ''));
        if ($iface === '') {
            continue;
        }
        $cap = (int)($mi['capacity_mbps'] ?? 100);
        if ($cap <= 0) {
            $cap = 100;
        }
        $ifaceCaps[$iface] = $cap;
    }

    if (count($ifaceCaps) === 0) {
        continue;
    }

    $nowTs = time();
    $snmpCache = $routerId !== '' ? store_get_router_interface_snmp_cache($routerId) : [];
    try {
        $octets = mikrotik_snmp_interface_octets($router, array_keys($ifaceCaps));
    } catch (Throwable $e) {
        $octets = [];
        $criticalAlerts[] = 'Critical: Router API timed out while reading interface mapping for ' . $routerName . '.';
    }
    if (!is_array($octets) || count($octets) === 0) {
        $criticalAlerts[] = 'Critical: Unable to read realtime traffic counters for ' . $routerName . '. Check SNMP and Router API.';
        $octets = [];
    }
    $traffic = [];
    foreach ($ifaceCaps as $iface => $cap) {
        $inNow = isset($octets[$iface]) && is_array($octets[$iface]) ? dec_str_norm((string)($octets[$iface]['in_octets'] ?? '0')) : '';
        $outNow = isset($octets[$iface]) && is_array($octets[$iface]) ? dec_str_norm((string)($octets[$iface]['out_octets'] ?? '0')) : '';

        $rxMbps = 0.0;
        $txMbps = 0.0;

        $prev = $snmpCache[$iface] ?? null;
        if (is_array($prev) && $inNow !== '' && $outNow !== '') {
            $prevIn = dec_str_norm((string)($prev['last_in_octets'] ?? '0'));
            $prevOut = dec_str_norm((string)($prev['last_out_octets'] ?? '0'));
            $prevTs = (int)($prev['last_ts'] ?? 0);
            $dt = $nowTs - $prevTs;
            if ($dt > 0 && $dt <= 600) {
                $dIn = (float)dec_str_sub($inNow, $prevIn);
                $dOut = (float)dec_str_sub($outNow, $prevOut);
                $rxMbps = ($dIn * 8) / ($dt * 1_000_000);
                $txMbps = ($dOut * 8) / ($dt * 1_000_000);
            }
        }

        if ($routerId !== '' && $inNow !== '' && $outNow !== '') {
            store_upsert_router_interface_snmp_cache($routerId, $iface, $inNow, $outNow, $nowTs);
        }

        $traffic[$iface] = [
            'tx_mbps' => max(0.0, $txMbps),
            'rx_mbps' => max(0.0, $rxMbps),
        ];
    }
    $maxMap = $routerId !== '' ? store_get_router_interface_traffic_max($routerId) : [];

    foreach ($ifaceCaps as $iface => $cap) {
        $tx = (float)($traffic[$iface]['tx_mbps'] ?? 0);
        $rx = (float)($traffic[$iface]['rx_mbps'] ?? 0);
        $bandwidthTx += $tx;
        $bandwidthRx += $rx;
        $totalCapacity += (float)$cap;

        if ($routerId !== '') {
            store_update_router_interface_traffic_max($routerId, $iface, $tx, $rx);
            $maxMap[$iface] = [
                'max_tx_mbps' => max((float)($maxMap[$iface]['max_tx_mbps'] ?? 0), $tx),
                'max_rx_mbps' => max((float)($maxMap[$iface]['max_rx_mbps'] ?? 0), $rx),
            ];
        }

        $maxTx = (float)($maxMap[$iface]['max_tx_mbps'] ?? 0);
        $maxRx = (float)($maxMap[$iface]['max_rx_mbps'] ?? 0);

        $txPct = $cap > 0 ? min(100, (int)round(($tx / $cap) * 100)) : 0;
        $rxPct = $cap > 0 ? min(100, (int)round(($rx / $cap) * 100)) : 0;

        $interfaceCards[] = [
            'router' => $routerName,
            'interface' => $iface,
            'cap' => $cap,
            'tx' => $tx,
            'rx' => $rx,
            'tx_pct' => $txPct,
            'rx_pct' => $rxPct,
            'max_tx' => $maxTx,
            'max_rx' => $maxRx,
        ];
    }

    if ($routerId !== '') {
        $limits = store_list_hotspot_profile_limits($routerId);
        foreach ($limits as $l) {
            if (!is_array($l)) {
                continue;
            }
            $pName = trim((string)($l['profile_name'] ?? ''));
            if ($pName === '') {
                continue;
            }
            $profileCards[] = [
                'router' => $routerName,
                'profile' => $pName,
                'rate_limit' => trim((string)($l['rate_limit'] ?? '')),
                'quota_bytes' => (int)($l['quota_bytes'] ?? 0),
                'user_count' => (int)($profileUserCounts[$pName] ?? 0),
            ];
        }
    }
}

usort($interfaceCards, function (array $a, array $b): int {
    $c = strcmp((string)$a['router'], (string)$b['router']);
    if ($c !== 0) {
        return $c;
    }
    return strcmp((string)$a['interface'], (string)$b['interface']);
});

$offlineUsers = max(0, $totalUsers - $connectedUsers);
$liveBandwidth = $bandwidthTx + $bandwidthRx;

ob_start();
?>
<style>
    .dash-card{position:relative;overflow:hidden;border-radius:.55rem;border:1px solid rgba(0,0,0,.06);background:var(--bs-body-bg)}
    [data-bs-theme="dark"] .dash-card{border-color:rgba(255,255,255,.10)}
    .dash-card::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--dash-accent,#0d6efd)}
    .dash-icon{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;position:absolute;top:10px;right:10px;background:var(--dash-pill-bg,rgba(13,110,253,.12));color:var(--dash-pill-text,#0d6efd)}
    .dash-sub{font-size:.78rem;color:rgba(108,117,125,1)}
    [data-bs-theme="dark"] .dash-sub{color:rgba(173,181,189,1)}
</style>
<div class="row g-3">
    <div class="col-6 col-md-6 col-xl-3">
        <div class="card dash-card shadow-sm" style="--dash-accent:#0d6efd;--dash-pill-bg:rgba(13,110,253,.12);--dash-pill-text:#0d6efd">
            <div class="card-body">
                <div class="dash-icon"><i class="bi bi-router"></i></div>
                <div class="text-body-secondary small">Routers</div>
                <div class="metric"><?php echo e((string)$totalRouters); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-6 col-xl-3">
        <div class="card dash-card shadow-sm" style="--dash-accent:#198754;--dash-pill-bg:rgba(25,135,84,.12);--dash-pill-text:#198754">
            <div class="card-body">
                <div class="dash-icon"><i class="bi bi-people"></i></div>
                <div class="text-body-secondary small">Total User</div>
                <div class="metric"><?php echo e((string)$totalUsers); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-6 col-xl-3">
        <div class="card dash-card shadow-sm" style="--dash-accent:#0dcaf0;--dash-pill-bg:rgba(13,202,240,.14);--dash-pill-text:#0dcaf0">
            <div class="card-body">
                <div class="dash-icon"><i class="bi bi-wifi"></i></div>
                <div class="text-body-secondary small">Connected Users</div>
                <div class="metric"><?php echo e((string)$connectedUsers); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-6 col-xl-3">
        <div class="card dash-card shadow-sm" style="--dash-accent:#dc3545;--dash-pill-bg:rgba(220,53,69,.12);--dash-pill-text:#dc3545">
            <div class="card-body">
                <div class="dash-icon"><i class="bi bi-person-x"></i></div>
                <div class="text-body-secondary small">Offline User</div>
                <div class="metric"><?php echo e((string)$offlineUsers); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-12 col-xl-6">
        <div class="card dash-card shadow-sm" style="--dash-accent:#0dcaf0;--dash-pill-bg:rgba(13,202,240,.14);--dash-pill-text:#0dcaf0">
            <div class="card-body">
                <div class="dash-icon"><i class="bi bi-activity"></i></div>
                <div class="h6 mb-0">Interface Live Traffic</div>
                <?php $uniqueAlerts = array_values(array_unique($criticalAlerts)); ?>
                <?php if (count($uniqueAlerts) > 0): ?>
                    <div class="alert alert-danger mt-3 mb-0" role="alert">
                        <div class="fw-semibold">Critical</div>
                        <div class="small mt-1">
                            <?php echo e(implode(' ', $uniqueAlerts)); ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (count($interfaceCards) === 0): ?>
                    <div class="text-body-secondary small mt-3">No monitoring interfaces selected. Set it in Router → Interface.</div>
                <?php else: ?>
                    <hr class="my-3">
                    <div class="row g-2">
                        <?php foreach ($interfaceCards as $row): ?>
                            <?php
                            $cap = (float)($row['cap'] ?? 0);
                            $rxNow = (float)($row['rx'] ?? 0);
                            $txNow = (float)($row['tx'] ?? 0);
                            $totalNow = $rxNow + $txNow;
                            ?>
                            <div class="col-12 col-lg-6">
                                <div class="p-2 rounded-3 border h-100">
                                    <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                                        <div>
                                            <div class="fw-semibold font-monospace"><?php echo e($row['interface']); ?></div>
                                            <div class="small text-body-secondary"><?php echo e($row['router']); ?></div>
                                        </div>
                                    </div>
                                    <div class="row g-2 mt-2">
                                        <div class="col-12">
                                            <div class="d-flex justify-content-between small text-body-secondary">
                                                <span>Rx: <span class="font-monospace"><?php echo e(number_format($rxNow, 2)); ?></span> Mbps</span>
                                                <span>Max Rx: <span class="font-monospace"><?php echo e(number_format((float)($row['max_rx'] ?? 0), 2)); ?></span></span>
                                            </div>
                                            <div class="progress mt-1" style="height:6px">
                                                <div class="progress-bar bg-info" style="width: <?php echo e((string)$row['rx_pct']); ?>%"></div>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="d-flex justify-content-between small text-body-secondary">
                                                <span>Tx: <span class="font-monospace"><?php echo e(number_format($txNow, 2)); ?></span> Mbps</span>
                                                <span>Max Tx: <span class="font-monospace"><?php echo e(number_format((float)($row['max_tx'] ?? 0), 2)); ?></span></span>
                                            </div>
                                            <div class="progress mt-1" style="height:6px">
                                                <div class="progress-bar bg-primary" style="width: <?php echo e((string)$row['tx_pct']); ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-6">
        <div class="card dash-card shadow-sm" style="--dash-accent:#0d6efd;--dash-pill-bg:rgba(13,110,253,.12);--dash-pill-text:#0d6efd">
            <div class="card-body">
                <div class="dash-icon"><i class="bi bi-hdd-network"></i></div>
                <div class="d-flex align-items-center justify-content-between">
                    <div class="h6 mb-0">Routers Status</div>
                </div>
                <div class="table-responsive mt-3">
                    <table class="table table-sm align-middle">
                        <thead>
                        <tr>
                            <th>Router</th>
                            <th>Status</th>
                            <th>CPU Uses</th>
                            <th>Uptime</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (count($routers) === 0): ?>
                            <tr>
                                <td colspan="4" class="text-body-secondary">No routers added. Add one in Router page.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($routers as $router): ?>
                                <?php
                                $routerId = (string)($router['id'] ?? '');
                                $status = (string)($routerStatusMap[$routerId] ?? router_status($router));
                                $m = $status === 'online' ? (array)($routerMetricsMap[$routerId] ?? []) : [];
                                $cpu = isset($m['cpu_load']) && is_numeric($m['cpu_load']) ? (float)$m['cpu_load'] : null;
                                $uptime = (string)($m['uptime'] ?? '');
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo e($router['name'] !== '' ? $router['name'] : 'Router'); ?></div>
                                        <div class="small text-body-secondary font-monospace"><?php echo e($router['ip']); ?></div>
                                    </td>
                                    <td>
                                        <?php if ($status === 'online'): ?>
                                            <span class="badge text-bg-success">Online</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">Offline</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="font-monospace">
                                        <?php echo $status === 'online' && $cpu !== null ? e(number_format($cpu, 0)) . '%' : '-'; ?>
                                    </td>
                                    <td class="font-monospace">
                                        <?php echo $status === 'online' && $uptime !== '' ? e($uptime) : '-'; ?>
                                    </td>
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
<?php if (count($profileCards) > 0): ?>
<div class="row g-3 mt-1">
    <div class="col-12">
        <div class="card dash-card shadow-sm" style="--dash-accent:#198754;--dash-pill-bg:rgba(25,135,84,.12);--dash-pill-text:#198754">
            <div class="card-body">
                <div class="dash-icon"><i class="bi bi-sliders"></i></div>
                <style>
                    .profile-mini-card{position:relative;overflow:hidden;border-radius:.55rem;background:var(--bs-body-bg);border:1px solid rgba(0,0,0,.06)}
                    [data-bs-theme="dark"] .profile-mini-card{border-color:rgba(255,255,255,.10)}
                    .profile-mini-card .bar{position:absolute;left:0;top:0;bottom:0;width:4px}
                    .profile-mini-card{padding-right:44px}
                    .profile-mini-card .icon-pill{width:32px;height:32px;border-radius:10px;display:flex;align-items:center;justify-content:center;position:absolute;top:10px;right:10px}
                    .profile-mini-card .label{font-size:.66rem;letter-spacing:.02em;text-transform:uppercase;color:rgba(108,117,125,1)}
                    [data-bs-theme="dark"] .profile-mini-card .label{color:rgba(173,181,189,1)}
                    .profile-mini-card .value{font-weight:600;font-size:.84rem}
                    .profile-mini-card .sub{font-size:.78rem;color:rgba(108,117,125,1)}
                    [data-bs-theme="dark"] .profile-mini-card .sub{color:rgba(173,181,189,1)}
                </style>
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="h6 mb-0">Profiles</div>
                    <span class="badge text-bg-light border"><?php echo e((string)count($profileCards)); ?></span>
                </div>
                <div class="row g-2 mt-2">
                    <?php foreach ($profileCards as $pc): ?>
                        <?php
                        $q = (int)($pc['quota_bytes'] ?? 0);
                        $qGb = $q > 0 ? ($q / 1024 / 1024 / 1024) : 0.0;
                        $rate = trim((string)($pc['rate_limit'] ?? ''));
                        $palette = [
                            ['bar' => '#0d6efd', 'pillBg' => 'rgba(13,110,253,.12)', 'pillText' => '#0d6efd', 'icon' => 'bi-speedometer2'],
                            ['bar' => '#198754', 'pillBg' => 'rgba(25,135,84,.12)', 'pillText' => '#198754', 'icon' => 'bi-wifi'],
                            ['bar' => '#fd7e14', 'pillBg' => 'rgba(253,126,20,.14)', 'pillText' => '#fd7e14', 'icon' => 'bi-lightning-charge'],
                            ['bar' => '#0dcaf0', 'pillBg' => 'rgba(13,202,240,.14)', 'pillText' => '#0dcaf0', 'icon' => 'bi-graph-up-arrow'],
                            ['bar' => '#dc3545', 'pillBg' => 'rgba(220,53,69,.12)', 'pillText' => '#dc3545', 'icon' => 'bi-shield-lock'],
                        ];
                        $h = (int)(abs(crc32((string)($pc['router'] ?? '') . '|' . (string)($pc['profile'] ?? ''))) % count($palette));
                        $sty = $palette[$h];
                        ?>
                        <div class="col-12 col-sm-6 col-md-4 col-lg-3 col-xl-2">
                            <div class="profile-mini-card p-2 h-100 shadow-sm">
                                <div class="bar" style="background: <?php echo e($sty['bar']); ?>"></div>
                                <div class="icon-pill" style="background: <?php echo e($sty['pillBg']); ?>; color: <?php echo e($sty['pillText']); ?>;">
                                    <i class="bi <?php echo e($sty['icon']); ?>"></i>
                                </div>
                                <div class="fw-semibold font-monospace"><?php echo e((string)($pc['profile'] ?? '')); ?></div>
                                <div class="sub"><?php echo e((string)($pc['router'] ?? '')); ?></div>
                                <div class="d-flex gap-3 mt-2">
                                    <div>
                                        <div class="label">Quota</div>
                                        <div class="value font-monospace"><?php echo $q > 0 ? e(number_format($qGb, 2)) . ' GB' : 'Unlimited'; ?></div>
                                    </div>
                                    <div>
                                        <div class="label">Speed</div>
                                        <div class="value font-monospace"><?php echo e($rate !== '' ? $rate : 'No limit'); ?></div>
                                    </div>
                                </div>
                                <div class="position-absolute bottom-0 end-0 p-2 text-end">
                                    <div class="label">Users</div>
                                    <div class="value font-monospace"><?php echo e((string)((int)($pc['user_count'] ?? 0))); ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (count($routers) > 0): ?>
<script>
setTimeout(function () { window.location.reload(); }, 5000);
</script>
<?php endif; ?>
<?php
$contentHtml = ob_get_clean();
require __DIR__ . '/_partials/layout.php';
