<?php
require __DIR__ . '/_lib/bootstrap.php';

$title = 'Change Password';
$active = '';

$me = current_user();
if (!$me) {
    header('Location: ' . base_url('login.php'));
    exit;
}

$errors = [];
$pageToasts = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    $hash = (string)($me['password_hash'] ?? '');
    if ($hash === '' || !password_verify($currentPassword, $hash)) {
        $errors[] = 'Current password is incorrect.';
    }
    if (strlen($newPassword) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }
    if ($newPassword !== $confirmPassword) {
        $errors[] = 'New password and confirm password do not match.';
    }

    if (count($errors) === 0) {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        store_update_user_password((string)$me['id'], $newHash, 0);
        $_SESSION['must_change_password'] = false;
        $pageToasts[] = ['type' => 'success', 'message' => 'Password updated.'];
        header('Location: ' . base_url('dashboard.php'));
        exit;
    }
}

ob_start();
?>
<?php if (count($errors) > 0): ?>
    <?php foreach ($errors as $err): ?>
        <?php $pageToasts[] = ['type' => 'danger', 'message' => (string)$err]; ?>
    <?php endforeach; ?>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-6 col-xl-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="h6 mb-1">Change Password</div>
                <div class="text-body-secondary small mb-3">For security, please change your password.</div>
                <form method="post" class="vstack gap-3">
                    <?php echo csrf_field(); ?>
                    <div>
                        <label class="form-label">Current Password</label>
                        <input class="form-control" type="password" name="current_password" required autocomplete="current-password">
                    </div>
                    <div>
                        <label class="form-label">New Password</label>
                        <input class="form-control" type="password" name="new_password" required autocomplete="new-password">
                    </div>
                    <div>
                        <label class="form-label">Confirm New Password</label>
                        <input class="form-control" type="password" name="confirm_password" required autocomplete="new-password">
                    </div>
                    <button class="btn btn-primary" type="submit">Update Password</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
$contentHtml = ob_get_clean();
require __DIR__ . '/_partials/layout.php';

