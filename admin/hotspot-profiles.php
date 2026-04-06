<?php
require __DIR__ . '/_lib/bootstrap.php';

$title = 'Hotspot Profiles';
$active = 'hotspot_profiles';

$store = store_load();
$routers = array_map('router_normalize', $store['routers']);
$routerId = trim((string)($_GET['router_id'] ?? ''));
if ($routerId === '' && count($routers) > 0) {
    $routerId = (string)($routers[0]['id'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $routerIdPost = trim((string)($_POST['router_id'] ?? ''));
    if ($routerIdPost !== '') {
        $routerId = $routerIdPost;
    }

    $r = $routerId !== '' ? store_get_router($routerId) : null;
    if (!is_array($r)) {
        flash_add('danger', 'Router not found.');
        header('Location: ' . base_url('hotspot-profiles.php'));
        exit;
    }
    $router = router_normalize($r);
    $api = mikrotik_api_connect($router);
    if ($api === null) {
        flash_add('danger', 'Router API connection failed.');
        header('Location: ' . base_url('hotspot-profiles.php?router_id=' . urlencode($routerId)));
        exit;
    }

    try {
        if ($action === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            $rate = trim((string)($_POST['rate_limit'] ?? ''));
            $pool = trim((string)($_POST['address_pool'] ?? ''));
            $quotaGbRaw = trim((string)($_POST['quota_gb'] ?? ''));
            $quotaBytes = 0;
            if ($quotaGbRaw !== '') {
                if (!is_numeric($quotaGbRaw)) {
                    $quotaBytes = -1;
                } else {
                    $q = (float)$quotaGbRaw;
                    if ($q < 0) {
                        $quotaBytes = -1;
                    } else {
                        $quotaBytes = (int)round($q * 1024 * 1024 * 1024);
                    }
                }
            }

            if ($name === '') {
                flash_add('danger', 'Profile name is required.');
            } elseif ($quotaBytes < 0) {
                flash_add('danger', 'Quota (GB) is invalid.');
            } else {
                $poolVal = $pool !== '' ? $pool : 'none';
                $api->comm('/ip/hotspot/user/profile/add', [
                    'name' => $name,
                    'rate-limit' => $rate,
                    'address-pool' => $poolVal,
                ]);
                store_upsert_hotspot_profile_limit($routerId, $name, $rate, $quotaBytes);
                flash_add('success', 'Profile added.');
            }

            header('Location: ' . base_url('hotspot-profiles.php?router_id=' . urlencode($routerId)));
            exit;
        }

        if ($action === 'update') {
            $id = trim((string)($_POST['id'] ?? ''));
            $oldName = trim((string)($_POST['old_name'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            $rate = trim((string)($_POST['rate_limit'] ?? ''));
            $pool = trim((string)($_POST['address_pool'] ?? ''));
            $quotaGbRaw = trim((string)($_POST['quota_gb'] ?? ''));
            $quotaBytes = 0;
            if ($quotaGbRaw !== '') {
                if (!is_numeric($quotaGbRaw)) {
                    $quotaBytes = -1;
                } else {
                    $q = (float)$quotaGbRaw;
                    if ($q < 0) {
                        $quotaBytes = -1;
                    } else {
                        $quotaBytes = (int)round($q * 1024 * 1024 * 1024);
                    }
                }
            }

            if ($id === '' || $name === '') {
                flash_add('danger', 'Invalid profile.');
            } elseif ($quotaBytes < 0) {
                flash_add('danger', 'Quota (GB) is invalid.');
            } else {
                $poolVal = $pool !== '' ? $pool : 'none';
                $api->comm('/ip/hotspot/user/profile/set', [
                    '.id' => $id,
                    'name' => $name,
                    'rate-limit' => $rate,
                    'address-pool' => $poolVal,
                ]);
                if ($oldName !== '' && $oldName !== $name) {
                    store_delete_hotspot_profile_limit($routerId, $oldName);
                }
                store_upsert_hotspot_profile_limit($routerId, $name, $rate, $quotaBytes);
                flash_add('success', 'Profile updated.');
            }

            header('Location: ' . base_url('hotspot-profiles.php?router_id=' . urlencode($routerId)));
            exit;
        }

        if ($action === 'delete') {
            $id = trim((string)($_POST['id'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            if ($id !== '') {
                $api->comm('/ip/hotspot/user/profile/remove', ['.id' => $id]);
                if ($name !== '') {
                    store_delete_hotspot_profile_limit($routerId, $name);
                }
                flash_add('success', 'Profile deleted.');
            }
            header('Location: ' . base_url('hotspot-profiles.php?router_id=' . urlencode($routerId)));
            exit;
        }
    } catch (Throwable $e) {
        flash_add('danger', 'Operation failed.');
        header('Location: ' . base_url('hotspot-profiles.php?router_id=' . urlencode($routerId)));
        exit;
    } finally {
        $api->disconnect();
    }
}

$router = null;
foreach ($routers as $rt) {
    if ((string)($rt['id'] ?? '') === $routerId) {
        $router = $rt;
        break;
    }
}

$profiles = [];
$pools = [];
if (is_array($router) && $routerId !== '' && router_status($router) === 'online') {
    $api = mikrotik_api_connect($router);
    if ($api !== null) {
        try {
            $rows = $api->comm('/ip/hotspot/user/profile/print');
            foreach ($rows as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $id = (string)($r['.id'] ?? '');
                $name = trim((string)($r['name'] ?? ''));
                if ($id === '' || $name === '') {
                    continue;
                }
                $profiles[] = [
                    'id' => $id,
                    'name' => $name,
                    'rate_limit' => (string)($r['rate-limit'] ?? ''),
                    'address_pool' => (string)($r['address-pool'] ?? ''),
                ];
            }

            $poolRows = $api->comm('/ip/pool/print', ['.proplist' => 'name']);
            $names = [];
            foreach ($poolRows as $pr) {
                if (!is_array($pr)) {
                    continue;
                }
                $pn = trim((string)($pr['name'] ?? ''));
                if ($pn === '') {
                    continue;
                }
                $names[$pn] = true;
            }
            $pools = array_keys($names);
            sort($pools, SORT_NATURAL | SORT_FLAG_CASE);
        } finally {
            $api->disconnect();
        }
    }
}

$limits = $routerId !== '' ? store_list_hotspot_profile_limits($routerId) : [];
$limitMap = [];
foreach ($limits as $l) {
    if (is_array($l) && isset($l['profile_name'])) {
        $limitMap[(string)$l['profile_name']] = $l;
    }
}

ob_start();
?>
<style>
    .hp-card{position:relative;overflow:hidden;border-radius:.55rem;border:1px solid rgba(0,0,0,.06);background:var(--bs-body-bg)}
    [data-bs-theme="dark"] .hp-card{border-color:rgba(255,255,255,.10)}
    .hp-card::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--hp-accent,#198754)}
    .hp-icon{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;position:absolute;top:10px;right:10px;background:var(--hp-pill-bg,rgba(25,135,84,.12));color:var(--hp-pill-text,#198754)}
    .hp-card .card-body{padding:.85rem 1rem;padding-right:3.25rem}
    .hp-sub{font-size:.78rem;color:rgba(108,117,125,1)}
    [data-bs-theme="dark"] .hp-sub{color:rgba(173,181,189,1)}
    .hp-kpi{font-weight:700;font-size:1.35rem}
    .hp-kpi-text{font-weight:700;font-size:1.05rem;line-height:1.15}
    @media (max-width:575.98px){
        .hp-icon{top:8px;right:8px;width:32px;height:32px}
        .hp-card .card-body{padding-right:3.05rem}
        .hp-kpi{font-size:1.10rem}
        .hp-kpi-text{font-size:.92rem}
        .hp-card .text-body-secondary.small{font-size:.72rem}
        .hp-table{font-size:.84rem}
        .hp-table .badge{font-size:.70rem}
        .hp-table .btn{padding:.25rem .4rem}
    }
</style>
<?php
$routerLabel = 'Router';
$routerNameOnly = 'Router';
$routerIpOnly = '';
foreach ($routers as $rt) {
    if ((string)($rt['id'] ?? '') === $routerId) {
        $routerNameOnly = (string)($rt['name'] !== '' ? $rt['name'] : ($rt['ip'] !== '' ? $rt['ip'] : 'Router'));
        $routerIpOnly = (string)($rt['ip'] ?? '');
        $routerLabel = $routerNameOnly;
        if ($routerIpOnly !== '' && $routerLabel !== $routerIpOnly) {
            $routerLabel .= ' (' . $routerIpOnly . ')';
        }
        break;
    }
}
$profileTotal = count($profiles);
$poolTotal = count($pools);
$quotaTotal = 0;
foreach ($limits as $l) {
    if (is_array($l) && (int)($l['quota_bytes'] ?? 0) > 0) {
        $quotaTotal++;
    }
}
?>
<div class="row g-2 g-md-3 mb-2">
    <div class="col-6 col-md-6 col-xl-3">
        <div class="card hp-card shadow-sm h-100" style="--hp-accent:#0d6efd;--hp-pill-bg:rgba(13,110,253,.12);--hp-pill-text:#0d6efd">
            <div class="card-body">
                <div class="hp-icon"><i class="bi bi-hdd-network"></i></div>
                <div class="text-body-secondary small">Router</div>
                <div class="hp-kpi-text text-truncate" title="<?php echo e($routerLabel); ?>"><?php echo e($routerNameOnly); ?></div>
                <?php if ($routerIpOnly !== '' && $routerIpOnly !== $routerNameOnly): ?>
                    <div class="hp-sub font-monospace text-truncate"><?php echo e($routerIpOnly); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-6 col-xl-3">
        <div class="card hp-card shadow-sm h-100" style="--hp-accent:#198754;--hp-pill-bg:rgba(25,135,84,.12);--hp-pill-text:#198754">
            <div class="card-body">
                <div class="hp-icon"><i class="bi bi-sliders"></i></div>
                <div class="text-body-secondary small">Profiles</div>
                <div class="hp-kpi"><?php echo e((string)$profileTotal); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-6 col-xl-3">
        <div class="card hp-card shadow-sm h-100" style="--hp-accent:#0dcaf0;--hp-pill-bg:rgba(13,202,240,.14);--hp-pill-text:#0dcaf0">
            <div class="card-body">
                <div class="hp-icon"><i class="bi bi-diagram-3"></i></div>
                <div class="text-body-secondary small">IP Pools</div>
                <div class="hp-kpi"><?php echo e((string)$poolTotal); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-6 col-xl-3">
        <div class="card hp-card shadow-sm h-100" style="--hp-accent:#fd7e14;--hp-pill-bg:rgba(253,126,20,.14);--hp-pill-text:#fd7e14">
            <div class="card-body">
                <div class="hp-icon"><i class="bi bi-hourglass-split"></i></div>
                <div class="text-body-secondary small">Quota Profiles</div>
                <div class="hp-kpi"><?php echo e((string)$quotaTotal); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="h6 mb-0">Profiles</div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <form method="get" class="m-0">
                    <select class="form-select form-select-sm" name="router_id" onchange="this.form.submit()" style="width: 280px; max-width: 100%;">
                        <?php foreach ($routers as $rt): ?>
                            <?php
                            $rid = (string)($rt['id'] ?? '');
                            $nm = (string)($rt['name'] !== '' ? $rt['name'] : ($rt['ip'] !== '' ? $rt['ip'] : 'Router'));
                            $ip = (string)($rt['ip'] ?? '');
                            ?>
                            <option value="<?php echo e($rid); ?>" <?php echo $rid === $routerId ? 'selected' : ''; ?>>
                                <?php echo e($nm . ($ip !== '' ? ' (' . $ip . ')' : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addProfileModal">
                    <i class="bi bi-plus-circle me-1"></i>Add
                </button>
            </div>
        </div>
        <div class="small text-body-secondary mt-2">
            Edit profile will update MikroTik profile via API. Quota is stored in portal and applied by RADIUS per profile.
        </div>

        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3">
            <div class="input-group input-group-sm flex-grow-0" style="width: 320px; max-width: 100%;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input class="form-control" id="hpSearch" placeholder="Search profile / rate / pool / quota">
            </div>
            <select class="form-select form-select-sm" id="hpRows" style="width: 110px;">
                <option value="10" selected>10 rows</option>
                <option value="25">25 rows</option>
                <option value="50">50 rows</option>
                <option value="100">100 rows</option>
            </select>
        </div>

        <div class="table-responsive mt-3">
            <table class="table table-sm align-middle hp-table">
                <thead>
                <tr>
                    <th>Name</th>
                    <th class="d-none d-sm-table-cell">Rate Limit</th>
                    <th class="d-none d-sm-table-cell">Address Pool</th>
                    <th class="text-end">Quota</th>
                    <th class="text-end">Action</th>
                </tr>
                </thead>
                <tbody id="hpBody">
                <?php if (count($profiles) === 0): ?>
                    <tr><td colspan="5" class="text-body-secondary">No profiles loaded (router offline / API fail).</td></tr>
                <?php else: ?>
                    <?php foreach ($profiles as $p): ?>
                        <?php
                        $name = (string)($p['name'] ?? '');
                        $lim = $limitMap[$name] ?? null;
                        $quotaBytes = is_array($lim) ? (int)($lim['quota_bytes'] ?? 0) : 0;
                        $quotaGb = $quotaBytes > 0 ? ($quotaBytes / 1024 / 1024 / 1024) : 0.0;
                        $rate = trim((string)($p['rate_limit'] ?? ''));
                        $addrPool = trim((string)($p['address_pool'] ?? ''));
                        $searchBlob = strtolower($name . ' ' . $rate . ' ' . $addrPool . ' ' . ($quotaBytes > 0 ? (string)number_format($quotaGb, 2, '.', '') : ''));
                        ?>
                        <tr class="hp-row" data-search="<?php echo e($searchBlob); ?>">
                            <td class="fw-semibold font-monospace">
                                <?php echo e($name); ?>
                                <div class="d-sm-none mt-1">
                                    <?php if ($rate !== ''): ?>
                                        <span class="badge text-bg-light border font-monospace"><?php echo e($rate); ?></span>
                                    <?php endif; ?>
                                    <?php if ($addrPool !== ''): ?>
                                        <span class="badge text-bg-light border font-monospace"><?php echo e($addrPool); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="font-monospace d-none d-sm-table-cell">
                                <?php if ($rate !== ''): ?>
                                    <span class="badge text-bg-light border"><?php echo e($rate); ?></span>
                                <?php else: ?>
                                    <span class="text-body-secondary">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="font-monospace d-none d-sm-table-cell">
                                <?php if ($addrPool !== ''): ?>
                                    <span class="badge text-bg-light border"><?php echo e($addrPool); ?></span>
                                <?php else: ?>
                                    <span class="text-body-secondary">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end font-monospace">
                                <?php if ($quotaBytes > 0): ?>
                                    <span class="badge text-bg-warning border"><?php echo e(number_format($quotaGb, 2)); ?> GB</span>
                                <?php else: ?>
                                    <span class="text-body-secondary">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="d-flex flex-column flex-sm-row align-items-end justify-content-end gap-1 gap-sm-2">
                                    <button
                                        class="btn btn-sm btn-outline-primary js-edit-profile"
                                        type="button"
                                        data-id="<?php echo e((string)($p['id'] ?? '')); ?>"
                                        data-name="<?php echo e($name); ?>"
                                        data-rate="<?php echo e($rate); ?>"
                                        data-pool="<?php echo e($addrPool); ?>"
                                        data-quota_gb="<?php echo e($quotaBytes > 0 ? number_format($quotaGb, 2, '.', '') : ''); ?>"
                                    >
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <form method="post" class="m-0" onsubmit="return confirm('Delete this profile?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="router_id" value="<?php echo e($routerId); ?>">
                                        <input type="hidden" name="id" value="<?php echo e((string)($p['id'] ?? '')); ?>">
                                        <input type="hidden" name="name" value="<?php echo e($name); ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">
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
        <div class="d-flex align-items-center justify-content-between mt-2">
            <div class="small text-danger" id="hpInfo" style="min-height: 18px;"></div>
            <nav>
                <ul class="pagination pagination-sm mb-0" id="hpPagination"></ul>
            </nav>
        </div>
    </div>
</div>

<div class="modal fade" id="addProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title d-flex align-items-center gap-2">
                    <i class="bi bi-plus-circle"></i>
                    <span>Add Profile</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="router_id" value="<?php echo e($routerId); ?>">
                    <div class="vstack gap-3">
                        <div>
                            <label class="form-label">Name</label>
                            <input class="form-control" name="name" required>
                        </div>
                        <div>
                            <label class="form-label">Address Pool</label>
                            <select class="form-select font-monospace" name="address_pool" id="add_profile_pool">
                                <option value="">none</option>
                                <?php foreach ($pools as $pn): ?>
                                    <option value="<?php echo e($pn); ?>"><?php echo e($pn); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Rate Limit</label>
                            <input class="form-control font-monospace" name="rate_limit" placeholder="1M/2M (optional)">
                        </div>
                        <div>
                            <label class="form-label">Quota (GB)</label>
                            <input class="form-control" type="number" step="0.01" min="0" name="quota_gb" placeholder="0 = unlimited">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>Add
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title d-flex align-items-center gap-2">
                    <i class="bi bi-pencil-square"></i>
                    <span>Edit Profile</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="router_id" value="<?php echo e($routerId); ?>">
                    <input type="hidden" name="id" id="edit_profile_id" value="">
                    <input type="hidden" name="old_name" id="edit_profile_old_name" value="">
                    <div class="vstack gap-3">
                        <div>
                            <label class="form-label">Name</label>
                            <input class="form-control" name="name" id="edit_profile_name" required>
                        </div>
                        <div>
                            <label class="form-label">Address Pool</label>
                            <select class="form-select font-monospace" name="address_pool" id="edit_profile_pool">
                                <option value="">none</option>
                                <?php foreach ($pools as $pn): ?>
                                    <option value="<?php echo e($pn); ?>"><?php echo e($pn); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Rate Limit</label>
                            <input class="form-control font-monospace" name="rate_limit" id="edit_profile_rate" placeholder="1M/2M (optional)">
                        </div>
                        <div>
                            <label class="form-label">Quota (GB)</label>
                            <input class="form-control" type="number" step="0.01" min="0" name="quota_gb" id="edit_profile_quota" placeholder="0 = unlimited">
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

<script>
function hpPaginate() {
    var tbody = document.getElementById('hpBody');
    var searchEl = document.getElementById('hpSearch');
    var rowsEl = document.getElementById('hpRows');
    var infoEl = document.getElementById('hpInfo');
    var pagEl = document.getElementById('hpPagination');
    if (!tbody || !searchEl || !rowsEl || !pagEl) return;

    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr.hp-row'));
    var q = String(searchEl.value || '').trim().toLowerCase();
    var perPage = parseInt(rowsEl.value || '10', 10);
    if (!perPage || perPage <= 0) perPage = 10;

    var filtered = rows.filter(function (r) {
        if (!q) return true;
        var s = String(r.dataset.search || '');
        return s.indexOf(q) !== -1;
    });

    var state = window.__hpState || { page: 1 };
    var total = filtered.length;
    var pages = Math.max(1, Math.ceil(total / perPage));
    if (state.page > pages) state.page = pages;
    if (state.page < 1) state.page = 1;
    window.__hpState = state;

    rows.forEach(function (r) { r.style.display = 'none'; });
    var start = (state.page - 1) * perPage;
    var end = start + perPage;
    filtered.slice(start, end).forEach(function (r) { r.style.display = ''; });

    if (infoEl) {
        if (total === 0) {
            infoEl.textContent = '0 results';
        } else {
            infoEl.textContent = 'Showing ' + (start + 1) + '–' + Math.min(end, total) + ' of ' + total;
        }
    }

    function li(label, page, disabled, active) {
        var cls = 'page-item';
        if (disabled) cls += ' disabled';
        if (active) cls += ' active';
        var a = '<a class="page-link" href="#" data-page="' + page + '">' + label + '</a>';
        return '<li class="' + cls + '">' + a + '</li>';
    }

    var html = '';
    html += li('&laquo;', state.page - 1, state.page === 1, false);
    var maxBtns = 7;
    var half = Math.floor(maxBtns / 2);
    var pStart = Math.max(1, state.page - half);
    var pEnd = Math.min(pages, pStart + maxBtns - 1);
    pStart = Math.max(1, pEnd - maxBtns + 1);
    for (var p = pStart; p <= pEnd; p++) {
        html += li(String(p), p, false, p === state.page);
    }
    html += li('&raquo;', state.page + 1, state.page === pages, false);
    pagEl.innerHTML = html;
}

document.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;
    if (!t.matches('#hpPagination a.page-link')) return;
    e.preventDefault();
    var p = parseInt(String(t.getAttribute('data-page') || ''), 10);
    if (!p || p < 1) return;
    window.__hpState = window.__hpState || { page: 1 };
    window.__hpState.page = p;
    hpPaginate();
});

var hpSearchEl = document.getElementById('hpSearch');
if (hpSearchEl) {
    hpSearchEl.addEventListener('input', function () {
        window.__hpState = window.__hpState || { page: 1 };
        window.__hpState.page = 1;
        hpPaginate();
    });
}
var hpRowsEl = document.getElementById('hpRows');
if (hpRowsEl) {
    hpRowsEl.addEventListener('change', function () {
        window.__hpState = window.__hpState || { page: 1 };
        window.__hpState.page = 1;
        hpPaginate();
    });
}

document.querySelectorAll('.js-edit-profile').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var modalEl = document.getElementById('editProfileModal');
        if (!modalEl || typeof bootstrap === 'undefined') return;
        var idEl = document.getElementById('edit_profile_id');
        var oldEl = document.getElementById('edit_profile_old_name');
        var nameEl = document.getElementById('edit_profile_name');
        var poolEl = document.getElementById('edit_profile_pool');
        var rateEl = document.getElementById('edit_profile_rate');
        var quotaEl = document.getElementById('edit_profile_quota');
        if (idEl) idEl.value = btn.dataset.id || '';
        if (oldEl) oldEl.value = btn.dataset.name || '';
        if (nameEl) nameEl.value = btn.dataset.name || '';
        if (poolEl) poolEl.value = btn.dataset.pool || '';
        if (rateEl) rateEl.value = btn.dataset.rate || '';
        if (quotaEl) quotaEl.value = btn.dataset.quota_gb || '';
        var m = new bootstrap.Modal(modalEl);
        m.show();
    });
});

hpPaginate();
</script>
<?php
$contentHtml = ob_get_clean();
require __DIR__ . '/_partials/layout.php';
