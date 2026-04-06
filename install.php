<?php
declare(strict_types=1);

session_start();

require __DIR__ . '/admin/_lib/config.php';
require __DIR__ . '/admin/_lib/db.php';
require __DIR__ . '/admin/_lib/store.php';

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    $t = $_SESSION['install_csrf'] ?? null;
    if (is_string($t) && $t !== '') {
        return $t;
    }
    $t = bin2hex(random_bytes(16));
    $_SESSION['install_csrf'] = $t;
    return $t;
}

function csrf_check(string $token): bool
{
    $t = $_SESSION['install_csrf'] ?? null;
    return is_string($t) && hash_equals($t, $token);
}

$cfg = app_config();
$dbCfg = $cfg['db'] ?? [];

$errors = [];
$success = '';

try {
    db_migrate();
} catch (Throwable $e) {
    $errors[] = 'Database connection failed. Check admin/_lib/config.php and MySQL service.';
}

$installed = false;
try {
    $installed = store_count_superadmins() > 0;
} catch (Throwable $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    $token = (string)($_POST['csrf'] ?? '');
    if (!csrf_check($token)) {
        $errors[] = 'Invalid request. Refresh the page and try again.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $password2 = (string)($_POST['password2'] ?? '');

        if ($username === '' || strlen($username) < 3) {
            $errors[] = 'Username must be at least 3 characters.';
        }
        if ($password === '' || strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters.';
        }
        if ($password !== $password2) {
            $errors[] = 'Password confirmation does not match.';
        }

        if (count($errors) === 0) {
            try {
                $existing = store_find_user_by_username($username);
                if (is_array($existing)) {
                    $errors[] = 'Username already exists.';
                } else {
                    $now = gmdate('Y-m-d H:i:s');
                    $user = [
                        'id' => bin2hex(random_bytes(16)),
                        'username' => $username,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'full_name' => null,
                        'email' => null,
                        'phone' => null,
                        'image_path' => null,
                        'role' => 'superadmin',
                        'theme' => 'light',
                        'must_change_password' => 0,
                        'created_at' => $now,
                    ];
                    store_upsert_user($user);
                    $success = 'Super Admin created. You can login now.';
                    $installed = true;
                }
            } catch (Throwable $e) {
                $errors[] = 'Failed to create admin user.';
            }
        }
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body{font-family:"Rajdhani",system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}
    </style>
</head>
<body class="bg-light">
<div class="container py-4" style="max-width: 720px;">
    <div class="card shadow-sm">
        <div class="card-body">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <div class="h4 mb-0">Portal Install</div>
                    <div class="text-body-secondary small">Database setup and first admin creation.</div>
                </div>
                <a class="btn btn-outline-secondary btn-sm" href="admin/login.php">Admin Login</a>
            </div>

            <div class="mt-3">
                <div class="fw-semibold">Database</div>
                <div class="small text-body-secondary">
                    Host: <span class="font-monospace"><?php echo e((string)($dbCfg['host'] ?? '')); ?></span>,
                    Port: <span class="font-monospace"><?php echo e((string)($dbCfg['port'] ?? '')); ?></span>,
                    Name: <span class="font-monospace"><?php echo e((string)($dbCfg['name'] ?? '')); ?></span>,
                    User: <span class="font-monospace"><?php echo e((string)($dbCfg['user'] ?? '')); ?></span>
                </div>
            </div>

            <?php if (count($errors) > 0): ?>
                <div class="alert alert-danger mt-3 mb-0">
                    <?php foreach ($errors as $err): ?>
                        <div><?php echo e((string)$err); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="alert alert-success mt-3 mb-0"><?php echo e($success); ?></div>
            <?php endif; ?>

            <?php if ($installed): ?>
                <div class="alert alert-info mt-3 mb-0">
                    <div class="fw-semibold">Already installed</div>
                    <div class="small mt-1">At least one Super Admin exists.</div>
                    <div class="small mt-2">For security, delete <span class="font-monospace">install.php</span> after setup.</div>
                </div>
            <?php else: ?>
                <div class="mt-4">
                    <div class="h6 mb-2">Create Super Admin</div>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                        <div class="row g-2">
                            <div class="col-12 col-md-6">
                                <label class="form-label">Username</label>
                                <input class="form-control" name="username" autocomplete="username" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Password</label>
                                <input class="form-control" type="password" name="password" autocomplete="new-password" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Confirm Password</label>
                                <input class="form-control" type="password" name="password2" autocomplete="new-password" required>
                            </div>
                        </div>
                        <button class="btn btn-primary mt-3" type="submit">Install</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

