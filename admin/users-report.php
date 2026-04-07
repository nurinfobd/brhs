<?php
require __DIR__ . '/_lib/bootstrap.php';

$title = 'Hotspot User';
$active = 'hotspot';

function radius_password_display(array $row): string
{
    $enc = (string)($row['password_enc'] ?? '');
    $plain = store_decrypt_password($enc);
    if ($plain !== '') {
        return $plain;
    }
    $u = strtoupper(trim((string)($row['username'] ?? '')));
    if ($u === '' || !preg_match('/^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$/', $u)) {
        return '';
    }
    return $u;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'radius_create') {
        $username = trim((string)($_POST['username'] ?? ''));
        $profile = trim((string)($_POST['profile'] ?? ''));
        $packageId = null;
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
        $password = (string)($_POST['password'] ?? '');
        $disabled = (int)($_POST['disabled'] ?? 0) === 1 ? 1 : 0;

        $errors = [];
        if ($username === '') {
            $errors[] = 'Username is required.';
        } elseif (!preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $username)) {
            $errors[] = 'Username contains invalid characters.';
        }
        if ($password === '') {
            $errors[] = 'Password is required.';
        }
        if ($profile !== '' && !preg_match('/^[A-Za-z0-9._@ -]{1,64}$/', $profile)) {
            $errors[] = 'Profile contains invalid characters.';
        }
        if ($quotaBytes < 0) {
            $errors[] = 'Quota (GB) is invalid.';
        }

        if (count($errors) === 0) {
            try {
                store_create_radius_user($username, $profile, $packageId, $quotaBytes, $password, $disabled);
                flash_add('success', 'Hotspot user added.');
            } catch (Throwable $e) {
                flash_add('danger', 'Unable to add user (maybe username already exists).');
            }
            header('Location: ' . base_url('users-report.php'));
            exit;
        }

        foreach ($errors as $err) {
            flash_add('danger', $err);
        }
        header('Location: ' . base_url('users-report.php'));
        exit;
    }

    if ($action === 'radius_update') {
        $id = trim((string)($_POST['id'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $profile = trim((string)($_POST['profile'] ?? ''));
        $packageId = null;
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
        $newPassword = (string)($_POST['password'] ?? '');
        $disabled = (int)($_POST['disabled'] ?? 0) === 1 ? 1 : 0;

        $errors = [];
        if ($id === '') {
            $errors[] = 'Invalid user.';
        }
        if ($username === '') {
            $errors[] = 'Username is required.';
        } elseif (!preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $username)) {
            $errors[] = 'Username contains invalid characters.';
        }
        if ($profile !== '' && !preg_match('/^[A-Za-z0-9._@ -]{1,64}$/', $profile)) {
            $errors[] = 'Profile contains invalid characters.';
        }
        if ($quotaBytes < 0) {
            $errors[] = 'Quota (GB) is invalid.';
        }

        if (count($errors) === 0) {
            try {
                store_update_radius_user($id, $username, $profile, $packageId, $quotaBytes, $newPassword === '' ? null : $newPassword, $disabled);
                flash_add('success', 'Hotspot user updated.');
            } catch (Throwable $e) {
                flash_add('danger', 'Unable to update user.');
            }
            header('Location: ' . base_url('users-report.php'));
            exit;
        }

        foreach ($errors as $err) {
            flash_add('danger', $err);
        }
        header('Location: ' . base_url('users-report.php'));
        exit;
    }

    if ($action === 'radius_delete') {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id !== '') {
            store_delete_radius_user($id);
            flash_add('success', 'Hotspot user deleted.');
        }
        header('Location: ' . base_url('users-report.php'));
        exit;
    }
}

$store = store_load();
$routers = array_map('router_normalize', $store['routers']);
$defaultRouterId = '';
if (count($routers) > 0) {
    $defaultRouterId = (string)($routers[0]['id'] ?? '');
}

$rows = store_list_radius_users();

ob_start();
?>
<style>
    @media (max-width:575.98px){
        .ur-table{font-size:.84rem}
        .ur-table .badge{font-size:.70rem}
        .ur-table .btn{padding:.25rem .4rem}
    }
</style>
<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
            <div class="h6 mb-0">Hotspot Users (RADIUS)</div>
            <div class="d-flex align-items-center gap-2 flex-nowrap">
                <select class="form-select form-select-sm" id="profile_router_id" style="width: 280px; max-width: 100%;">
                    <?php if (count($routers) === 0): ?>
                        <option value="">No router</option>
                    <?php else: ?>
                        <?php foreach ($routers as $rt): ?>
                            <?php
                            $rid = (string)($rt['id'] ?? '');
                            $name = (string)($rt['name'] !== '' ? $rt['name'] : ($rt['ip'] !== '' ? $rt['ip'] : 'Router'));
                            $ip = (string)($rt['ip'] ?? '');
                            ?>
                            <option value="<?php echo e($rid); ?>" <?php echo $rid === $defaultRouterId ? 'selected' : ''; ?>>
                                <?php echo e($name . ($ip !== '' ? ' (' . $ip . ')' : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addRadiusUserModal">
                    <i class="bi bi-plus-circle me-1"></i>Add User
                </button>
            </div>
        </div>
        <div class="small text-body-secondary mt-2">
            Configure MikroTik Hotspot to use this portal as RADIUS server (Auth: 1812, Acct: 1813).
        </div>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3">
            <div class="input-group input-group-sm flex-grow-0" style="width: 320px; max-width: 100%;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input class="form-control" id="urSearch" placeholder="Search user / profile">
            </div>
            <select class="form-select form-select-sm" id="urRows" style="width: 110px;">
                <option value="10" selected>10 rows</option>
                <option value="25">25 rows</option>
                <option value="50">50 rows</option>
                <option value="100">100 rows</option>
            </select>
        </div>
        <div class="table-responsive mt-3">
            <table class="table table-sm align-middle ur-table">
                <thead>
                <tr>
                    <th>User</th>
                    <th class="d-none d-sm-table-cell">Password</th>
                    <th class="d-none d-sm-table-cell">Profile</th>
                    <th class="text-end d-none d-sm-table-cell">Quota</th>
                    <th class="text-end d-none d-sm-table-cell">Used</th>
                    <th class="text-end">Remaining</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                </tr>
                </thead>
                <tbody id="urBody">
                <?php if (count($rows) === 0): ?>
                    <tr>
                        <td colspan="8" class="text-body-secondary">No hotspot users. Add one to enable RADIUS authentication.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $usernameStr = (string)($r['username'] ?? '');
                        $passwordDisplay = radius_password_display($r);
                        $profileStr = (string)($r['profile'] ?? '');
                        $searchBlob = strtolower(trim($usernameStr . ' ' . $profileStr . ' ' . $passwordDisplay));
                        $quotaUser = (int)($r['quota_bytes'] ?? 0);
                        $quota = $quotaUser > 0 ? $quotaUser : 0;
                        $used = (int)($r['used_bytes'] ?? 0);
                        $quotaGb = $quota > 0 ? ($quota / 1024 / 1024 / 1024) : 0.0;
                        $usedGb = $used > 0 ? ($used / 1024 / 1024 / 1024) : 0.0;
                        $remain = $quota > 0 ? max(0, $quota - $used) : 0;
                        $remainGb = $quota > 0 ? ($remain / 1024 / 1024 / 1024) : 0.0;
                        $ratio = $quota > 0 ? ($remain / $quota) : 1.0;
                        ?>
                        <tr data-search="<?php echo e($searchBlob); ?>">
                            <td class="fw-semibold font-monospace">
                                <?php echo e($usernameStr); ?>
                                <div class="d-sm-none mt-1 fw-normal">
                                    <?php if ($passwordDisplay !== ''): ?>
                                        <span class="badge text-bg-light border font-monospace">Pass: <?php echo e($passwordDisplay); ?></span>
                                    <?php endif; ?>
                                    <?php if ($profileStr !== ''): ?>
                                        <span class="badge text-bg-light border"><?php echo e($profileStr); ?></span>
                                    <?php endif; ?>
                                    <?php if ($quota > 0): ?>
                                        <span class="badge text-bg-light border font-monospace"><?php echo e(number_format($quotaGb, 2)); ?> GB</span>
                                    <?php endif; ?>
                                    <span class="badge text-bg-light border font-monospace"><?php echo e(number_format($usedGb, 2)); ?> GB</span>
                                </div>
                            </td>
                            <td class="d-none d-sm-table-cell font-monospace">
                                <?php echo e($passwordDisplay !== '' ? $passwordDisplay : '-'); ?>
                            </td>
                            <td class="d-none d-sm-table-cell"><?php echo e($profileStr !== '' ? $profileStr : '-'); ?></td>
                            <td class="text-end font-monospace d-none d-sm-table-cell"><?php echo $quota > 0 ? e(number_format($quotaGb, 2)) . ' GB' : '-'; ?></td>
                            <td class="text-end font-monospace d-none d-sm-table-cell"><?php echo e(number_format($usedGb, 2)) . ' GB'; ?></td>
                            <td class="text-end">
                                <?php if ($quota <= 0): ?>
                                    <span class="text-body-secondary">-</span>
                                <?php else: ?>
                                    <?php
                                    $badge = 'text-bg-success';
                                    if ($ratio <= 0.10) {
                                        $badge = 'text-bg-danger';
                                    } elseif ($ratio <= 0.25) {
                                        $badge = 'text-bg-warning';
                                    }
                                    if ($remain <= 0) {
                                        $badge = 'text-bg-danger';
                                    }
                                    ?>
                                    <span class="badge <?php echo e($badge); ?> font-monospace">
                                        <?php echo e(number_format($remainGb, 2)); ?> GB
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int)($r['disabled'] ?? 0) === 1): ?>
                                    <span class="badge text-bg-secondary">Disabled</span>
                                <?php else: ?>
                                    <span class="badge text-bg-success">Enabled</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="d-flex flex-column flex-sm-row align-items-end justify-content-end gap-1 gap-sm-2">
                                    <button
                                        class="btn btn-sm btn-outline-primary js-edit-radius-user"
                                        type="button"
                                        data-id="<?php echo e((string)($r['id'] ?? '')); ?>"
                                        data-username="<?php echo e((string)($r['username'] ?? '')); ?>"
                                        data-profile="<?php echo e((string)($r['profile'] ?? '')); ?>"
                                        data-quota_gb="<?php echo e($quotaUser > 0 ? number_format($quotaGb, 2, '.', '') : ''); ?>"
                                        data-disabled="<?php echo e((string)($r['disabled'] ?? '0')); ?>"
                                        title="Edit"
                                    >
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <form method="post" class="m-0" onsubmit="return confirm('Delete this user?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="radius_delete">
                                        <input type="hidden" name="id" value="<?php echo e((string)($r['id'] ?? '')); ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="Delete" type="submit">
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
            <div class="small text-danger" id="urInfo" style="min-height: 18px;"></div>
            <nav>
                <ul class="pagination pagination-sm mb-0" id="urPagination"></ul>
            </nav>
        </div>
    </div>
</div>

<div class="modal fade" id="addRadiusUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title d-flex align-items-center gap-2">
                    <i class="bi bi-person-plus"></i>
                    <span>Add Hotspot User</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="radius_create">
                    <div class="vstack gap-3">
                        <div>
                            <label class="form-label">Username</label>
                            <input class="form-control" name="username" required autocomplete="off">
                        </div>
                        <div>
                            <label class="form-label">Profile</label>
                            <select class="form-select" name="profile" id="add_profile">
                                <option value="">Loading profiles...</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Quota (GB)</label>
                            <input class="form-control" type="number" step="0.01" min="0" name="quota_gb" id="add_quota_gb" placeholder="Optional">
                        </div>
                        <div>
                            <label class="form-label">Password</label>
                            <input class="form-control" type="password" name="password" required autocomplete="new-password">
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" name="disabled" id="add_disabled">
                            <label class="form-check-label" for="add_disabled">Disabled</label>
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

<div class="modal fade" id="editRadiusUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title d-flex align-items-center gap-2">
                    <i class="bi bi-pencil-square"></i>
                    <span>Edit Hotspot User</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" id="editRadiusUserForm">
                <div class="modal-body">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="radius_update">
                    <input type="hidden" name="id" id="edit_id" value="">
                    <div class="vstack gap-3">
                        <div>
                            <label class="form-label">Username</label>
                            <input class="form-control" name="username" id="edit_username" required autocomplete="off">
                        </div>
                        <div>
                            <label class="form-label">Profile</label>
                            <select class="form-select" name="profile" id="edit_profile">
                                <option value="">Loading profiles...</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Quota (GB)</label>
                            <input class="form-control" type="number" step="0.01" min="0" name="quota_gb" id="edit_quota_gb" placeholder="Optional">
                        </div>
                        <div>
                            <label class="form-label">New Password</label>
                            <input class="form-control" type="password" name="password" id="edit_password" placeholder="Leave blank to keep current" autocomplete="new-password">
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" name="disabled" id="edit_disabled">
                            <label class="form-check-label" for="edit_disabled">Disabled</label>
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
function fillProfileSelect(selectEl, profiles, selectedValue) {
    if (!selectEl) return;
    var current = selectedValue != null ? String(selectedValue) : (selectEl.value || '');
    selectEl.innerHTML = '';

    var optEmpty = document.createElement('option');
    optEmpty.value = '';
    optEmpty.textContent = '— Select profile —';
    selectEl.appendChild(optEmpty);

    var seen = {};
    (profiles || []).forEach(function (p) {
        var s = String(p || '').trim();
        if (!s || seen[s]) return;
        seen[s] = true;
        var opt = document.createElement('option');
        opt.value = s;
        opt.textContent = s;
        selectEl.appendChild(opt);
    });

    if (current && !seen[current]) {
        var optCustom = document.createElement('option');
        optCustom.value = current;
        optCustom.textContent = current + ' (custom)';
        selectEl.appendChild(optCustom);
    }
    selectEl.value = current;
}

function loadProfiles(routerId) {
    var addSel = document.getElementById('add_profile');
    var editSel = document.getElementById('edit_profile');

    if (!routerId) {
        fillProfileSelect(addSel, [], '');
        fillProfileSelect(editSel, [], editSel ? editSel.value : '');
        return;
    }

    fetch('<?php echo e(base_url('router-hotspot-profiles.php')); ?>?router_id=' + encodeURIComponent(routerId), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || data.ok !== true) {
                fillProfileSelect(addSel, [], addSel ? addSel.value : '');
                fillProfileSelect(editSel, [], editSel ? editSel.value : '');
                return;
            }
            fillProfileSelect(addSel, data.profiles || [], addSel ? addSel.value : '');
            fillProfileSelect(editSel, data.profiles || [], editSel ? editSel.value : '');
        })
        .catch(function () {
            fillProfileSelect(addSel, [], addSel ? addSel.value : '');
            fillProfileSelect(editSel, [], editSel ? editSel.value : '');
        });
}

var profileRouterSelect = document.getElementById('profile_router_id');
if (profileRouterSelect) {
    profileRouterSelect.addEventListener('change', function () {
        loadProfiles(profileRouterSelect.value || '');
    });
    loadProfiles(profileRouterSelect.value || '');
}

document.querySelectorAll('.js-edit-radius-user').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var modalEl = document.getElementById('editRadiusUserModal');
        if (!modalEl || typeof bootstrap === 'undefined') return;
        var idEl = document.getElementById('edit_id');
        var userEl = document.getElementById('edit_username');
        var profileEl = document.getElementById('edit_profile');
        var quotaEl = document.getElementById('edit_quota_gb');
        var passEl = document.getElementById('edit_password');
        var disEl = document.getElementById('edit_disabled');
        if (idEl) idEl.value = btn.dataset.id || '';
        if (userEl) userEl.value = btn.dataset.username || '';
        if (profileEl) profileEl.value = btn.dataset.profile || '';
        if (quotaEl) quotaEl.value = btn.dataset.quota_gb || '';
        if (passEl) passEl.value = '';
        if (disEl) disEl.checked = (btn.dataset.disabled || '0') === '1';
        var m = new bootstrap.Modal(modalEl);
        m.show();
    });
});

(function () {
    var tbody = document.getElementById('urBody');
    var searchEl = document.getElementById('urSearch');
    var rowsEl = document.getElementById('urRows');
    var infoEl = document.getElementById('urInfo');
    var pagEl = document.getElementById('urPagination');
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
