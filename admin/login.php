<?php
require __DIR__ . '/_lib/bootstrap.php';

if (is_logged_in()) {
    header('Location: ' . base_url('dashboard.php'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        $error = 'Enter username and password.';
        store_insert_app_log('warning', 'auth', 'login failed: missing credentials', ['username' => $username]);
    } elseif (!login_attempt($username, $password)) {
        $error = 'Invalid credentials.';
        store_insert_app_log('warning', 'auth', 'login failed: invalid credentials', ['username' => $username]);
    } else {
        store_insert_app_log('info', 'auth', 'login ok', ['username' => $username]);
        if ((bool)($_SESSION['must_change_password'] ?? false)) {
            header('Location: ' . base_url('change-password.php'));
        } else {
            header('Location: ' . base_url('dashboard.php'));
        }
        exit;
    }
}

$theme = app_theme();
?>
<!doctype html>
<html lang="en" data-bs-theme="<?php echo e($theme); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body{min-height:100vh;display:flex;align-items:center}
        .card{max-width:420px;width:100%}
    </style>
</head>
<body class="bg-body-tertiary">
<?php if ($error !== ''): ?>
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1080;">
        <div class="toast text-bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body"><?php echo e($error); ?></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    </div>
<?php endif; ?>
<div class="container">
    <div class="mx-auto card shadow-sm">
        <div class="card-body p-4">
            <div class="d-flex align-items-center gap-2 mb-3">
                <i class="bi bi-router fs-3"></i>
                <div>
                    <div class="h5 mb-0">Admin Portal</div>
                    <div class="text-body-secondary small">Mikrotik Hotspot</div>
                </div>
            </div>
            <form method="post" class="vstack gap-3">
                <?php echo csrf_field(); ?>
                <div>
                    <label class="form-label">Username</label>
                    <input class="form-control" name="username" value="<?php echo e((string)($_POST['username'] ?? '')); ?>" autocomplete="username" required>
                </div>
                <div>
                    <label class="form-label">Password</label>
                    <input class="form-control" type="password" name="password" autocomplete="current-password" required>
                </div>
                <button class="btn btn-primary w-100" type="submit">Sign in</button>
                <div class="small text-body-secondary">
                    Default: admin / admin123
                </div>
            </form>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.querySelectorAll('.toast').forEach(function (el) {
        var t = new bootstrap.Toast(el, { delay: 3500 });
        t.show();
    });
</script>
</body>
</html>
