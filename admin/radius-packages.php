<?php
require __DIR__ . '/_lib/bootstrap.php';

$title = 'Packages';
$active = 'radius_packages';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $rate = trim((string)($_POST['rate_limit'] ?? ''));
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

        $errors = [];
        if ($name === '') {
            $errors[] = 'Package name is required.';
        }
        if ($quotaBytes < 0) {
            $errors[] = 'Quota (GB) is invalid.';
        }

        if (count($errors) === 0) {
            try {
                store_create_radius_package($name, $rate, $quotaBytes);
                flash_add('success', 'Package added.');
            } catch (Throwable $e) {
                flash_add('danger', 'Unable to add package (maybe name already exists).');
            }
            header('Location: ' . base_url('radius-packages.php'));
            exit;
        }

        foreach ($errors as $err) {
            flash_add('danger', $err);
        }
        header('Location: ' . base_url('radius-packages.php'));
        exit;
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $rate = trim((string)($_POST['rate_limit'] ?? ''));
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

        $errors = [];
        if ($id <= 0) {
            $errors[] = 'Invalid package.';
        }
        if ($name === '') {
            $errors[] = 'Package name is required.';
        }
        if ($quotaBytes < 0) {
            $errors[] = 'Quota (GB) is invalid.';
        }

        if (count($errors) === 0) {
            try {
                store_update_radius_package($id, $name, $rate, $quotaBytes);
                flash_add('success', 'Package updated.');
            } catch (Throwable $e) {
                flash_add('danger', 'Unable to update package.');
            }
            header('Location: ' . base_url('radius-packages.php'));
            exit;
        }

        foreach ($errors as $err) {
            flash_add('danger', $err);
        }
        header('Location: ' . base_url('radius-packages.php'));
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            store_delete_radius_package($id);
            flash_add('success', 'Package deleted.');
        }
        header('Location: ' . base_url('radius-packages.php'));
        exit;
    }
}

$packages = store_list_radius_packages();

ob_start();
?>
<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="h6 mb-0">Bandwidth Packages</div>
            <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addPkgModal">
                <i class="bi bi-plus-circle me-1"></i>Add Package
            </button>
        </div>
        <div class="small text-body-secondary mt-2">
            Rate Limit uses MikroTik format (example: <span class="font-monospace">1M/2M</span>). Quota is total data (GB). If quota is set, user will be blocked when quota ends.
        </div>
        <div class="table-responsive mt-3">
            <table class="table table-sm align-middle">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Rate Limit</th>
                    <th class="text-end">Quota</th>
                    <th class="text-end">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php if (count($packages) === 0): ?>
                    <tr><td colspan="4" class="text-body-secondary">No packages.</td></tr>
                <?php else: ?>
                    <?php foreach ($packages as $p): ?>
                        <?php
                        if (!is_array($p)) {
                            continue;
                        }
                        $q = (int)($p['quota_bytes'] ?? 0);
                        $qGb = $q > 0 ? ($q / 1024 / 1024 / 1024) : 0.0;
                        ?>
                        <tr>
                            <td class="fw-semibold"><?php echo e((string)($p['name'] ?? '')); ?></td>
                            <td class="font-monospace"><?php echo e((string)($p['rate_limit'] ?? '') !== '' ? (string)$p['rate_limit'] : '-'); ?></td>
                            <td class="text-end font-monospace"><?php echo $q > 0 ? e(number_format($qGb, 2)) . ' GB' : '-'; ?></td>
                            <td class="text-end">
                                <div class="d-flex justify-content-end gap-2">
                                    <button
                                        class="btn btn-sm btn-outline-primary js-edit-pkg"
                                        type="button"
                                        data-id="<?php echo e((string)($p['id'] ?? '')); ?>"
                                        data-name="<?php echo e((string)($p['name'] ?? '')); ?>"
                                        data-rate="<?php echo e((string)($p['rate_limit'] ?? '')); ?>"
                                        data-quota_gb="<?php echo e($q > 0 ? number_format($qGb, 2, '.', '') : ''); ?>"
                                    >
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <form method="post" class="m-0" onsubmit="return confirm('Delete this package?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo e((string)($p['id'] ?? '')); ?>">
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
    </div>
</div>

<div class="modal fade" id="addPkgModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title d-flex align-items-center gap-2">
                    <i class="bi bi-box-seam"></i>
                    <span>Add Package</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create">
                    <div class="vstack gap-3">
                        <div>
                            <label class="form-label">Name</label>
                            <input class="form-control" name="name" required>
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

<div class="modal fade" id="editPkgModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title d-flex align-items-center gap-2">
                    <i class="bi bi-pencil-square"></i>
                    <span>Edit Package</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="edit_pkg_id" value="">
                    <div class="vstack gap-3">
                        <div>
                            <label class="form-label">Name</label>
                            <input class="form-control" name="name" id="edit_pkg_name" required>
                        </div>
                        <div>
                            <label class="form-label">Rate Limit</label>
                            <input class="form-control font-monospace" name="rate_limit" id="edit_pkg_rate" placeholder="1M/2M (optional)">
                        </div>
                        <div>
                            <label class="form-label">Quota (GB)</label>
                            <input class="form-control" type="number" step="0.01" min="0" name="quota_gb" id="edit_pkg_quota" placeholder="0 = unlimited">
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
document.querySelectorAll('.js-edit-pkg').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var modalEl = document.getElementById('editPkgModal');
        if (!modalEl || typeof bootstrap === 'undefined') return;
        var idEl = document.getElementById('edit_pkg_id');
        var nameEl = document.getElementById('edit_pkg_name');
        var rateEl = document.getElementById('edit_pkg_rate');
        var quotaEl = document.getElementById('edit_pkg_quota');
        if (idEl) idEl.value = btn.dataset.id || '';
        if (nameEl) nameEl.value = btn.dataset.name || '';
        if (rateEl) rateEl.value = btn.dataset.rate || '';
        if (quotaEl) quotaEl.value = btn.dataset.quota_gb || '';
        var m = new bootstrap.Modal(modalEl);
        m.show();
    });
});
</script>
<?php
$contentHtml = ob_get_clean();
require __DIR__ . '/_partials/layout.php';

