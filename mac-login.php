<?php
require __DIR__ . '/admin/_lib/bootstrap.php';

$store = store_load();
$routers = array_map('router_normalize', $store['routers'] ?? []);

$routerId = trim((string)($_GET['router_id'] ?? $_POST['router_id'] ?? ''));
$nasIp = trim((string)($_GET['nas_ip'] ?? $_POST['nas_ip'] ?? $_GET['router_ip'] ?? $_POST['router_ip'] ?? ''));
if ($routerId === '' && $nasIp !== '') {
    $byIp = store_find_router_by_ip($nasIp);
    if (is_array($byIp)) {
        $routerId = (string)($byIp['id'] ?? '');
    }
}
if ($routerId === '' && count($routers) === 1) {
    $routerId = (string)($routers[0]['id'] ?? '');
}

$routerRow = $routerId !== '' ? store_get_router($routerId) : null;
$router = is_array($routerRow) ? router_normalize($routerRow) : null;

function mac_norm(string $mac): string
{
    $m = strtoupper(trim($mac));
    $m = preg_replace('/[^0-9A-F]/', '', $m);
    if (!is_string($m) || strlen($m) !== 12) {
        return '';
    }
    return substr($m, 0, 2) . ':' . substr($m, 2, 2) . ':' . substr($m, 4, 2) . ':' . substr($m, 6, 2) . ':' . substr($m, 8, 2) . ':' . substr($m, 10, 2);
}

$mac = mac_norm((string)($_GET['mac'] ?? $_POST['mac'] ?? ''));
$loginUrl = trim((string)($_GET['login_url'] ?? $_POST['login_url'] ?? ''));
$dst = trim((string)($_GET['dst'] ?? $_POST['dst'] ?? ''));
$popup = trim((string)($_GET['popup'] ?? $_POST['popup'] ?? ''));

function normalize_login_url(string $loginUrl, string $routerIp): string
{
    $u = trim($loginUrl);
    if ($u === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $u)) {
        return $u;
    }
    $rip = trim($routerIp);
    if ($rip === '') {
        return $u;
    }
    if (str_starts_with($u, '/')) {
        return 'http://' . $rip . $u;
    }
    return 'http://' . $rip . '/' . $u;
}

$routerIpForLogin = $nasIp !== '' ? $nasIp : (is_array($router) ? trim((string)($router['ip'] ?? '')) : '');
$loginActionUrl = normalize_login_url($loginUrl, $routerIpForLogin);

$profiles = [];
$profile = trim((string)($_POST['profile'] ?? $_GET['profile'] ?? ''));

if (is_array($router)) {
    $api = mikrotik_api_connect($router);
    if ($api !== null) {
        try {
            $rows = $api->comm('/ip/hotspot/user/profile/print', ['.proplist' => 'name']);
            $names = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = trim((string)($row['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $names[$name] = true;
            }
            $profiles = array_keys($names);
            sort($profiles, SORT_NATURAL | SORT_FLAG_CASE);
        } catch (Throwable $e) {
        } finally {
            $api->disconnect();
        }
    }
}

$created = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($routerId === '' || !is_array($router)) {
        $error = 'Router is not configured.';
    } elseif ($mac === '') {
        $error = 'MAC address is required.';
    } elseif ($profile === '') {
        $error = 'Profile is required.';
    } elseif (count($profiles) > 0 && !in_array($profile, $profiles, true)) {
        $error = 'Invalid profile.';
    } else {
        try {
            $existing = store_find_radius_user_by_username($mac);
            if (is_array($existing)) {
                store_update_radius_user((string)($existing['id'] ?? ''), $mac, $profile, null, 0, $mac, 0);
            } else {
                store_create_radius_user($mac, $profile, null, 0, $mac, 0);
            }
            $created = true;
        } catch (Throwable $e) {
            $error = 'Unable to create user.';
        }
    }
}

$title = 'MAC Login';
?>
<!doctype html>
<html lang="en" data-bs-theme="<?php echo e(app_theme()); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body{font-family:"Rajdhani",system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}
        .brand-logo{width:50px;height:50px;object-fit:contain}
    </style>
</head>
<body class="bg-light">
<div class="container py-4" style="max-width: 560px;">
    <div class="d-flex align-items-center justify-content-center gap-2 mb-3">
        <img src="<?php echo e(base_url('admin/logo.png')); ?>" alt="Logo" class="brand-logo">
        <div class="fw-semibold">Hotspot Portal</div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="h5 mb-0">MAC Login</div>
            <div class="text-body-secondary small mt-1">Select profile and continue.</div>

            <?php if (count($routers) > 1 || $routerId === ''): ?>
                <form method="get" class="mt-3">
                    <input type="hidden" name="mac" value="<?php echo e($mac); ?>">
                    <input type="hidden" name="login_url" value="<?php echo e($loginUrl); ?>">
                    <input type="hidden" name="dst" value="<?php echo e($dst); ?>">
                    <input type="hidden" name="popup" value="<?php echo e($popup); ?>">
                    <input type="hidden" name="nas_ip" value="<?php echo e($nasIp); ?>">
                    <label class="form-label">Router</label>
                    <select class="form-select" name="router_id" onchange="this.form.submit()" required>
                        <option value="">Select router</option>
                        <?php foreach ($routers as $rt): ?>
                            <?php
                            if (!is_array($rt)) {
                                continue;
                            }
                            $rid = (string)($rt['id'] ?? '');
                            $nm = (string)($rt['name'] ?? '');
                            $ip = (string)($rt['ip'] ?? '');
                            $label = trim($nm !== '' ? $nm : $ip);
                            if ($ip !== '' && $label !== $ip) {
                                $label .= ' (' . $ip . ')';
                            }
                            ?>
                            <option value="<?php echo e($rid); ?>" <?php echo $rid !== '' && $rid === $routerId ? 'selected' : ''; ?>>
                                <?php echo e($label !== '' ? $label : 'Router'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($routerId === '' && $nasIp !== ''): ?>
                        <div class="small text-body-secondary mt-1">NAS IP: <span class="font-monospace"><?php echo e($nasIp); ?></span></div>
                    <?php endif; ?>
                </form>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger mt-3 mb-0"><?php echo e($error); ?></div>
            <?php endif; ?>

            <?php if ($created): ?>
                <?php if ($loginActionUrl !== ''): ?>
                    <div class="alert alert-success mt-3">
                        <div class="fw-semibold">Connecting...</div>
                        <div class="small mt-1">Please wait.</div>
                    </div>
                    <form id="mkLoginForm" method="post" action="<?php echo e($loginActionUrl); ?>" class="d-none">
                        <input type="hidden" name="username" value="<?php echo e($mac); ?>">
                        <input type="hidden" name="password" value="<?php echo e($mac); ?>">
                        <?php if ($dst !== ''): ?>
                            <input type="hidden" name="dst" value="<?php echo e($dst); ?>">
                        <?php endif; ?>
                        <?php if ($popup !== ''): ?>
                            <input type="hidden" name="popup" value="<?php echo e($popup); ?>">
                        <?php endif; ?>
                        <button type="submit">Connect</button>
                    </form>
                    <script>
                        (function () {
                            var f = document.getElementById('mkLoginForm');
                            if (f) f.submit();
                        })();
                    </script>
                <?php else: ?>
                    <div class="alert alert-success mt-3">
                        <div class="fw-semibold">Created</div>
                        <div class="small mt-1">Username: <span class="font-monospace"><?php echo e($mac); ?></span></div>
                        <div class="small">Password: <span class="font-monospace"><?php echo e($mac); ?></span></div>
                        <div class="small mt-2 text-body-secondary">Auto-connect needs login_url from MikroTik.</div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <form method="post" class="mt-3">
                    <input type="hidden" name="router_id" value="<?php echo e($routerId); ?>">
                    <input type="hidden" name="nas_ip" value="<?php echo e($nasIp); ?>">
                    <input type="hidden" name="login_url" value="<?php echo e($loginUrl); ?>">
                    <input type="hidden" name="dst" value="<?php echo e($dst); ?>">
                    <input type="hidden" name="popup" value="<?php echo e($popup); ?>">

                    <div class="mb-3">
                        <label class="form-label">MAC Address</label>
                        <input class="form-control font-monospace" name="mac" value="<?php echo e($mac); ?>" <?php echo $mac !== '' ? 'readonly' : ''; ?> placeholder="AA:BB:CC:DD:EE:FF" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Profile</label>
                        <select class="form-select" name="profile" required <?php echo !is_array($router) ? 'disabled' : ''; ?>>
                            <option value="">Select profile</option>
                            <?php foreach ($profiles as $p): ?>
                                <option value="<?php echo e($p); ?>" <?php echo $profile === $p ? 'selected' : ''; ?>><?php echo e($p); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!is_array($router) && count($routers) > 0): ?>
                            <div class="small text-danger mt-1">Select router to load profiles.</div>
                        <?php elseif (!is_array($router) && count($routers) === 0): ?>
                            <div class="small text-danger mt-1">No router configured in portal.</div>
                        <?php elseif (count($profiles) === 0): ?>
                            <div class="small text-danger mt-1">Unable to load profiles. Check Router API.</div>
                        <?php endif; ?>
                    </div>

                    <button class="btn btn-primary w-100" type="submit" <?php echo !is_array($router) ? 'disabled' : ''; ?>>Continue</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

