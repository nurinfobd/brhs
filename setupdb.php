<?php
declare(strict_types=1);

session_start();

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    $t = $_SESSION['setupdb_csrf'] ?? null;
    if (is_string($t) && $t !== '') {
        return $t;
    }
    $t = bin2hex(random_bytes(16));
    $_SESSION['setupdb_csrf'] = $t;
    return $t;
}

function csrf_check(string $token): bool
{
    $t = $_SESSION['setupdb_csrf'] ?? null;
    return is_string($t) && hash_equals($t, $token);
}

$host = '127.0.0.1';
$port = 3306;
$charset = 'utf8mb4';

$dbName = trim((string)($_POST['dbname'] ?? ''));
$dbUser = trim((string)($_POST['dbuser'] ?? ''));
$dbPass = (string)($_POST['dbpass'] ?? '');

$errors = [];
$ok = false;
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf'] ?? '');
    if (!csrf_check($token)) {
        $errors[] = 'Invalid request. Refresh and try again.';
    }

    if ($dbName === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
        $errors[] = 'DB Name must be letters/numbers/underscore only.';
    }
    if ($dbUser === '') {
        $errors[] = 'DB User is required.';
    }

    if (count($errors) === 0) {
        try {
            $dsn = "mysql:host={$host};port={$port};charset={$charset}";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci");
            $ok = true;
            $message = 'Database created (or already exists).';
        } catch (Throwable $e) {
            $errors[] = 'Database create failed. Check host/port, user permission, and password.';
        }
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Setup DB</title>
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
                    <div class="h4 mb-0">Database Setup</div>
                    <div class="text-body-secondary small">Create a MySQL database.</div>
                </div>
                <a class="btn btn-outline-secondary btn-sm" href="install.php">Go to Install</a>
            </div>

            <div class="small text-body-secondary mt-2">
                Host: <span class="font-monospace"><?php echo e($host); ?></span>,
                Port: <span class="font-monospace"><?php echo e((string)$port); ?></span>
            </div>

            <?php if (count($errors) > 0): ?>
                <div class="alert alert-danger mt-3 mb-0">
                    <?php foreach ($errors as $err): ?>
                        <div><?php echo e((string)$err); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($ok): ?>
                <div class="alert alert-success mt-3 mb-0">
                    <div><?php echo e($message); ?></div>
                    <div class="small mt-2">Now update <span class="font-monospace">admin/_lib/config.php</span> with this DB name/user/password (if different).</div>
                </div>
            <?php endif; ?>

            <form method="post" class="mt-4">
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                <div class="row g-2">
                    <div class="col-12 col-md-4">
                        <label class="form-label">DB Name</label>
                        <input class="form-control" name="dbname" value="<?php echo e($dbName); ?>" placeholder="cityuniversity" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">DB User</label>
                        <input class="form-control" name="dbuser" value="<?php echo e($dbUser); ?>" placeholder="root" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">DB Password</label>
                        <input class="form-control" type="password" name="dbpass" value="<?php echo e($dbPass); ?>" placeholder="">
                    </div>
                </div>
                <button class="btn btn-primary mt-3" type="submit">Create Database</button>
            </form>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

