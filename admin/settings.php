<?php
require __DIR__ . '/_lib/bootstrap.php';

$title = 'Settings';
$active = 'settings';

$errors = [];
$pageToasts = [];
$lastAction = '';

$store = store_load();
$users = is_array($store['users'] ?? null) ? $store['users'] : [];
$me = current_user();
$canManageUsers = is_superadmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $lastAction = $action;

    if (in_array($action, ['create_user', 'update_user', 'delete_user'], true) && !$canManageUsers) {
        flash_add('danger', 'Permission denied.');
        header('Location: ' . base_url('settings.php'));
        exit;
    }

    if ($action === 'theme') {
        $theme = (string)($_POST['theme'] ?? 'light');
        if (!in_array($theme, ['light', 'dark'], true)) {
            $errors[] = 'Invalid theme.';
        } else {
            $_SESSION['theme'] = $theme;
            if ($me) {
                $me['theme'] = $theme;
                store_upsert_user($me);
            }
            $pageToasts[] = ['type' => 'success', 'message' => 'Theme updated.'];
        }
    }

    if ($action === 'create_user') {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $role = (string)($_POST['role'] ?? 'admin');

        if ($fullName === '') {
            $errors[] = 'Full name is required.';
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Valid email is required.';
        }
        if ($phone === '') {
            $errors[] = 'Phone number is required.';
        } elseif (!preg_match('/^[0-9+\-\s().]{6,32}$/', $phone)) {
            $errors[] = 'Phone number format is invalid.';
        }
        if ($username === '') {
            $errors[] = 'Username is required.';
        } elseif (store_find_user_by_username($username) !== null) {
            $errors[] = 'Username already exists.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        if (!in_array($role, ['superadmin', 'admin'], true)) {
            $errors[] = 'Role is invalid.';
        }

        $imagePath = null;
        if (count($errors) === 0) {
            try {
                $imagePath = save_uploaded_image('image');
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (count($errors) === 0) {
            store_upsert_user([
                'id' => bin2hex(random_bytes(16)),
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'full_name' => $fullName,
                'email' => $email,
                'phone' => $phone,
                'image_path' => $imagePath,
                'role' => $role,
                'theme' => 'light',
                'must_change_password' => 1,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            flash_add('success', 'User created. First login will require password change.');
            header('Location: ' . base_url('settings.php'));
            exit;
        }
    }

    if ($action === 'update_user') {
        $id = (string)($_POST['id'] ?? '');
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $role = (string)($_POST['role'] ?? 'admin');
        $newPassword = (string)($_POST['new_password'] ?? '');

        $existing = $id !== '' ? store_get_user($id) : null;
        if (!is_array($existing)) {
            $errors[] = 'User not found.';
        }

        if ($me && $id !== '' && $id === (string)($me['id'] ?? '')) {
            $errors[] = 'You cannot edit your own user from this page.';
        }

        if ($fullName === '') {
            $errors[] = 'Full name is required.';
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Valid email is required.';
        }
        if ($phone === '') {
            $errors[] = 'Phone number is required.';
        } elseif (!preg_match('/^[0-9+\-\s().]{6,32}$/', $phone)) {
            $errors[] = 'Phone number format is invalid.';
        }
        if ($username === '') {
            $errors[] = 'Username is required.';
        } else {
            $byName = store_find_user_by_username($username);
            if (is_array($byName) && (string)($byName['id'] ?? '') !== $id) {
                $errors[] = 'Username already exists.';
            }
        }
        if (!in_array($role, ['superadmin', 'admin'], true)) {
            $errors[] = 'Role is invalid.';
        }

        if ($existing && (string)($existing['role'] ?? 'admin') === 'superadmin' && $role !== 'superadmin') {
            if (store_count_superadmins() <= 1) {
                $errors[] = 'You must keep at least one superadmin.';
            }
        }

        $imagePath = $existing ? (string)($existing['image_path'] ?? '') : '';
        if (count($errors) === 0) {
            try {
                $newImage = save_uploaded_image('image');
                if (is_string($newImage) && $newImage !== '') {
                    $abs = uploaded_image_abs_path($imagePath);
                    if ($abs !== '' && is_file($abs)) {
                        @unlink($abs);
                    }
                    $imagePath = $newImage;
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($existing && count($errors) === 0) {
            $passwordHash = (string)($existing['password_hash'] ?? '');
            $mustChange = (int)($existing['must_change_password'] ?? 0);
            if ($newPassword !== '') {
                if (strlen($newPassword) < 8) {
                    $errors[] = 'New password must be at least 8 characters.';
                } else {
                    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $mustChange = 1;
                }
            }
            if (count($errors) === 0) {
                store_upsert_user([
                    'id' => (string)($existing['id'] ?? $id),
                    'username' => $username,
                    'password_hash' => $passwordHash,
                    'full_name' => $fullName,
                    'email' => $email,
                    'phone' => $phone,
                    'image_path' => $imagePath !== '' ? $imagePath : null,
                    'role' => $role,
                    'theme' => (string)($existing['theme'] ?? 'light'),
                    'must_change_password' => $mustChange,
                    'created_at' => (string)($existing['created_at'] ?? gmdate('Y-m-d H:i:s')),
                ]);
                flash_add('success', 'User updated.');
                header('Location: ' . base_url('settings.php'));
                exit;
            }
        }
    }

    if ($action === 'delete_user') {
        $id = (string)($_POST['id'] ?? '');
        if ($me && $id === (string)$me['id']) {
            $errors[] = 'You cannot delete your own account.';
        } else {
            $u = store_get_user($id);
            if (is_array($u)) {
                if ((string)($u['role'] ?? 'admin') === 'superadmin' && store_count_superadmins() <= 1) {
                    $errors[] = 'You must keep at least one superadmin.';
                }
                $path = (string)($u['image_path'] ?? '');
                $abs = uploaded_image_abs_path($path);
                if ($abs !== '' && is_file($abs)) {
                    @unlink($abs);
                }
            }
            if (count($errors) === 0) {
                store_delete_user($id);
                $pageToasts[] = ['type' => 'success', 'message' => 'User deleted.'];
                $store = store_load();
                $users = is_array($store['users'] ?? null) ? $store['users'] : [];
            }
        }
    }
}

usort($users, fn($a, $b) => strcmp((string)($a['username'] ?? ''), (string)($b['username'] ?? '')));
$openAddUserModal = $lastAction === 'create_user' && count($errors) > 0;
$openEditUserModal = $lastAction === 'update_user' && count($errors) > 0;

ob_start();
?>
<style>
    @media (max-width:575.98px){
        .settings-table{font-size:.84rem}
        .settings-table .badge{font-size:.70rem}
        .settings-table .btn{padding:.25rem .4rem}
    }
</style>
<?php if (count($errors) > 0): ?>
    <?php foreach ($errors as $err): ?>
        <?php $pageToasts[] = ['type' => 'danger', 'message' => (string)$err]; ?>
    <?php endforeach; ?>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-xl-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="h6">Theme</div>
                <form method="post" class="d-flex gap-2 align-items-end">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="theme">
                    <div class="flex-grow-1">
                        <label class="form-label">Select Theme</label>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Theme">
                            <input class="btn-check" type="radio" name="theme" id="themeLight" value="light" <?php echo app_theme() === 'light' ? 'checked' : ''; ?>>
                            <label class="btn btn-outline-primary" for="themeLight">Light</label>

                            <input class="btn-check" type="radio" name="theme" id="themeDark" value="dark" <?php echo app_theme() === 'dark' ? 'checked' : ''; ?>>
                            <label class="btn btn-outline-primary" for="themeDark">Dark</label>
                        </div>
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit">Save</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mt-3">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
            <div class="h6 mb-0">Users</div>
            <?php if ($canManageUsers): ?>
                <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    Add User
                </button>
            <?php else: ?>
                <span class="badge text-bg-secondary">Admin</span>
            <?php endif; ?>
        </div>
        <div class="table-responsive mt-3">
            <table class="table table-sm align-middle settings-table">
                <thead>
                <tr>
                    <th>User</th>
                    <th class="d-none d-sm-table-cell">Contact</th>
                    <th class="d-none d-sm-table-cell">Role</th>
                    <th class="d-none d-sm-table-cell">Theme</th>
                    <th class="d-none d-sm-table-cell">Status</th>
                    <th class="d-none d-sm-table-cell">Created</th>
                    <th class="text-end">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php if (count($users) === 0): ?>
                    <tr>
                        <td colspan="2" class="text-body-secondary">No users found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <?php
                        $img = (string)($u['image_path'] ?? '');
                        $imgUrl = uploaded_image_url($img);
                        $fullName = (string)($u['full_name'] ?? '');
                        $email = (string)($u['email'] ?? '');
                        $phone = (string)($u['phone'] ?? '');
                        $mustChange = ((int)($u['must_change_password'] ?? 0)) === 1;
                        $role = in_array((string)($u['role'] ?? 'admin'), ['superadmin', 'admin'], true) ? (string)$u['role'] : 'admin';
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <?php if ($imgUrl !== ''): ?>
                                        <img src="<?php echo e($imgUrl); ?>" width="34" height="34" class="rounded-circle object-fit-cover" alt="">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-body-secondary d-inline-flex align-items-center justify-content-center" style="width:34px;height:34px;">
                                            <span class="small fw-semibold text-body"><?php echo e(strtoupper(substr((string)($u['username'] ?? ''), 0, 1))); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="fw-semibold"><?php echo e($fullName !== '' ? $fullName : (string)($u['username'] ?? '')); ?></div>
                                        <div class="small text-body-secondary"><?php echo e((string)($u['username'] ?? '')); ?></div>
                                    </div>
                                </div>
                                <div class="d-sm-none mt-2 small">
                                    <div><?php echo e($email !== '' ? $email : '-'); ?></div>
                                    <div class="text-body-secondary"><?php echo e($phone !== '' ? $phone : '-'); ?></div>
                                    <div class="mt-1">
                                        <span class="badge text-bg-light border"><?php echo e($role === 'superadmin' ? 'Super Admin' : 'Admin'); ?></span>
                                        <span class="badge text-bg-light border"><?php echo e((string)($u['theme'] ?? 'light')); ?></span>
                                        <?php if ($mustChange): ?>
                                            <span class="badge text-bg-warning">Change Password</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-success">Active</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="small d-none d-sm-table-cell">
                                <div><?php echo e($email !== '' ? $email : '-'); ?></div>
                                <div class="text-body-secondary"><?php echo e($phone !== '' ? $phone : '-'); ?></div>
                            </td>
                            <td class="d-none d-sm-table-cell">
                                <?php if ($role === 'superadmin'): ?>
                                    <span class="badge text-bg-primary">Super Admin</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Admin</span>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-sm-table-cell"><?php echo e((string)($u['theme'] ?? 'light')); ?></td>
                            <td class="d-none d-sm-table-cell">
                                <?php if ($mustChange): ?>
                                    <span class="badge text-bg-warning">Change Password</span>
                                <?php else: ?>
                                    <span class="badge text-bg-success">Active</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-body-secondary small d-none d-sm-table-cell"><?php echo e((string)($u['created_at'] ?? '')); ?></td>
                            <td class="text-end">
                                <?php if ($me && (string)($u['id'] ?? '') === (string)$me['id']): ?>
                                    <span class="badge text-bg-primary">You</span>
                                <?php else: ?>
                                    <?php if ($canManageUsers): ?>
                                        <div class="d-flex flex-column flex-sm-row align-items-end justify-content-end gap-1 gap-sm-2">
                                            <button
                                                class="btn btn-sm btn-outline-primary js-edit-user"
                                                type="button"
                                                data-id="<?php echo e((string)($u['id'] ?? '')); ?>"
                                                data-full_name="<?php echo e($fullName); ?>"
                                                data-email="<?php echo e($email); ?>"
                                                data-phone="<?php echo e($phone); ?>"
                                                data-username="<?php echo e((string)($u['username'] ?? '')); ?>"
                                                data-role="<?php echo e($role); ?>"
                                            >Edit</button>
                                            <form method="post" class="m-0" onsubmit="return confirm('Delete this user?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="id" value="<?php echo e((string)($u['id'] ?? '')); ?>">
                                                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-body-secondary small">-</span>
                                    <?php endif; ?>
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

<?php if ($canManageUsers): ?>
    <div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" enctype="multipart/form-data">
                    <div class="modal-body">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="create_user">
                        <div class="row g-3">
                            <div class="col-12 col-lg-6">
                                <label class="form-label">Full Name</label>
                                <input class="form-control" name="full_name" value="<?php echo e((string)($_POST['full_name'] ?? '')); ?>" required>
                            </div>
                            <div class="col-12 col-lg-6">
                                <label class="form-label">Email Address</label>
                                <input class="form-control" type="email" name="email" value="<?php echo e((string)($_POST['email'] ?? '')); ?>" required>
                            </div>
                            <div class="col-12 col-lg-6">
                                <label class="form-label">Phone Number</label>
                                <input class="form-control" name="phone" value="<?php echo e((string)($_POST['phone'] ?? '')); ?>" required>
                            </div>
                            <div class="col-12 col-lg-6">
                                <label class="form-label">Image</label>
                                <input class="form-control" type="file" name="image" accept="image/png,image/jpeg,image/webp">
                            </div>
                            <div class="col-12 col-lg-4">
                                <label class="form-label">Role</label>
                                <select class="form-select" name="role">
                                    <option value="admin" selected>Admin</option>
                                    <option value="superadmin">Super Admin</option>
                                </select>
                            </div>
                            <div class="col-12 col-lg-4">
                                <label class="form-label">Username</label>
                                <input class="form-control" name="username" value="<?php echo e((string)($_POST['username'] ?? '')); ?>" required>
                            </div>
                            <div class="col-12 col-lg-4">
                                <label class="form-label">Password</label>
                                <input class="form-control" type="password" name="password" required>
                            </div>
                            <div class="col-12">
                                <div class="small text-body-secondary">
                                    User will be forced to change password on first login.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" enctype="multipart/form-data">
                    <div class="modal-body">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="update_user">
                        <input type="hidden" name="id" id="edit_id" value="<?php echo e((string)($_POST['id'] ?? '')); ?>">
                        <div class="row g-3">
                            <div class="col-12 col-lg-6">
                                <label class="form-label">Full Name</label>
                                <input class="form-control" name="full_name" id="edit_full_name" value="<?php echo e((string)($_POST['full_name'] ?? '')); ?>" required>
                            </div>
                            <div class="col-12 col-lg-6">
                                <label class="form-label">Email Address</label>
                                <input class="form-control" type="email" name="email" id="edit_email" value="<?php echo e((string)($_POST['email'] ?? '')); ?>" required>
                            </div>
                            <div class="col-12 col-lg-6">
                                <label class="form-label">Phone Number</label>
                                <input class="form-control" name="phone" id="edit_phone" value="<?php echo e((string)($_POST['phone'] ?? '')); ?>" required>
                            </div>
                            <div class="col-12 col-lg-6">
                                <label class="form-label">Image (optional)</label>
                                <input class="form-control" type="file" name="image" accept="image/png,image/jpeg,image/webp">
                            </div>
                            <div class="col-12 col-lg-4">
                                <label class="form-label">Role</label>
                                <select class="form-select" name="role" id="edit_role">
                                    <option value="admin">Admin</option>
                                    <option value="superadmin">Super Admin</option>
                                </select>
                            </div>
                            <div class="col-12 col-lg-4">
                                <label class="form-label">Username</label>
                                <input class="form-control" name="username" id="edit_username" value="<?php echo e((string)($_POST['username'] ?? '')); ?>" required>
                            </div>
                            <div class="col-12 col-lg-4">
                                <label class="form-label">New Password (optional)</label>
                                <input class="form-control" type="password" name="new_password" autocomplete="new-password">
                            </div>
                            <div class="col-12">
                                <div class="small text-body-secondary">
                                    If you set new password, user will be forced to change password on next login.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($openAddUserModal): ?>
    <script>
        window.addEventListener('load', function () {
            var el = document.getElementById('addUserModal');
            if (!el || typeof bootstrap === 'undefined') return;
            var m = new bootstrap.Modal(el);
            m.show();
        });
    </script>
<?php endif; ?>

<?php if ($openEditUserModal): ?>
    <script>
        window.addEventListener('load', function () {
            var el = document.getElementById('editUserModal');
            if (!el || typeof bootstrap === 'undefined') return;
            var m = new bootstrap.Modal(el);
            m.show();
        });
    </script>
<?php endif; ?>

<?php if ($canManageUsers): ?>
    <script>
        window.addEventListener('load', function () {
            var buttons = document.querySelectorAll('.js-edit-user');
            if (!buttons || typeof bootstrap === 'undefined') return;
            buttons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var el = document.getElementById('editUserModal');
                    if (!el) return;
                    document.getElementById('edit_id').value = btn.dataset.id || '';
                    document.getElementById('edit_full_name').value = btn.dataset.full_name || '';
                    document.getElementById('edit_email').value = btn.dataset.email || '';
                    document.getElementById('edit_phone').value = btn.dataset.phone || '';
                    document.getElementById('edit_username').value = btn.dataset.username || '';
                    document.getElementById('edit_role').value = btn.dataset.role || 'admin';
                    var m = new bootstrap.Modal(el);
                    m.show();
                });
            });
        });
    </script>
<?php endif; ?>
<?php
$contentHtml = ob_get_clean();
require __DIR__ . '/_partials/layout.php';
