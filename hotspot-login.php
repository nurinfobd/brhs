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

$profile = trim((string)($_POST['profile'] ?? $_GET['profile'] ?? ''));
$profileLimits = is_array($router) ? store_list_hotspot_profile_limits((string)($router['id'] ?? '')) : [];

$error = '';
$created = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_array($router) || $routerId === '') {
        $error = 'Router is not configured.';
    } elseif ($mac === '') {
        $error = 'MAC address is required.';
    } elseif ($profile === '') {
        $error = 'Profile is required.';
    } else {
        $limitRow = store_get_hotspot_profile_limit((string)($router['id'] ?? ''), $profile);
        $quotaBytes = is_array($limitRow) ? (int)($limitRow['quota_bytes'] ?? 0) : 0;
        $rateLimit = is_array($limitRow) ? trim((string)($limitRow['rate_limit'] ?? '')) : '';

        if ($quotaBytes <= 0) {
            $error = 'Quota is not set for this profile. Set quota in Hotspot Profiles.';
        } else {
            try {
                $existing = store_find_radius_user_by_username($mac);
                if (is_array($existing)) {
                    store_update_radius_user((string)($existing['id'] ?? ''), $mac, $profile, null, $quotaBytes, $mac, 0);
                } else {
                    store_create_radius_user($mac, $profile, null, $quotaBytes, $mac, 0);
                }
                store_insert_app_log('info', 'hotspot', 'mac user provisioned', ['router_id' => (string)($router['id'] ?? ''), 'router_ip' => $routerIpForLogin, 'mac' => $mac, 'profile' => $profile, 'rate_limit' => $rateLimit, 'quota_bytes' => $quotaBytes]);
                $created = true;
            } catch (Throwable $e) {
                store_insert_app_log('error', 'hotspot', 'mac user provision failed', ['router_id' => (string)($router['id'] ?? ''), 'router_ip' => $routerIpForLogin, 'mac' => $mac, 'profile' => $profile, 'error' => $e->getMessage()]);
                $error = 'Unable to create user.';
            }
        }
    }
}

$title = 'Hotspot Login';

function quota_label(int $bytes): string
{
    if ($bytes <= 0) {
        return 'Not set';
    }
    $gb = bytes_to_gb($bytes);
    if ($gb >= 1) {
        return rtrim(rtrim(number_format($gb, 2, '.', ''), '0'), '.') . ' GB';
    }
    $mb = $bytes / 1024 / 1024;
    return rtrim(rtrim(number_format($mb, 0, '.', ''), '0'), '.') . ' MB';
}

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
        .profile-card{cursor:pointer}
        .profile-card.disabled{opacity:.6}
    </style>
</head>
<body class="bg-light">
<div class="container py-4" style="max-width: 980px;">
    <div class="d-flex align-items-center justify-content-center gap-2 mb-3">
        <img src="<?php echo e(base_url('admin/logo.png')); ?>" alt="Logo" class="brand-logo">
        <div class="fw-semibold">Hotspot Portal</div>
    </div>

    <?php if ($created): ?>
        <?php if ($loginActionUrl !== ''): ?>
            <div class="alert alert-success">
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
            <div class="alert alert-success">
                <div class="fw-semibold">User created</div>
                <div class="small mt-1">MAC: <span class="font-monospace"><?php echo e($mac); ?></span></div>
                <div class="small text-body-secondary mt-2">Missing login_url. Ensure MikroTik login page passes link-login-only.</div>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <div class="h5 mb-0">Select a package</div>
                        <div class="text-body-secondary small mt-1">Username and password will be your device MAC.</div>
                    </div>
                    <div class="small text-body-secondary">
                        <div>Router: <span class="font-monospace"><?php echo e(is_array($router) ? (string)($router['ip'] ?? '') : ($nasIp !== '' ? $nasIp : '-')); ?></span></div>
                        <div>MAC: <span class="font-monospace"><?php echo e($mac !== '' ? $mac : '-'); ?></span></div>
                    </div>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger mt-3 mb-0"><?php echo e($error); ?></div>
                <?php endif; ?>

                <?php if (!is_array($router)): ?>
                    <div class="alert alert-warning mt-3 mb-0">
                        <div class="fw-semibold">Router not found</div>
                        <div class="small mt-1">Ensure this router NAS IP is added in the portal Router list and RADIUS is enabled.</div>
                    </div>
                <?php elseif (count($profileLimits) === 0): ?>
                    <div class="alert alert-warning mt-3 mb-0">
                        <div class="fw-semibold">No profiles configured</div>
                        <div class="small mt-1">Set quotas in Portal → Hotspot Profiles before using hotspot login.</div>
                    </div>
                <?php else: ?>
                    <div class="row g-3 mt-1">
                        <?php foreach ($profileLimits as $pl): ?>
                            <?php
                            if (!is_array($pl)) {
                                continue;
                            }
                            $pName = trim((string)($pl['profile_name'] ?? ''));
                            if ($pName === '') {
                                continue;
                            }
                            $rate = trim((string)($pl['rate_limit'] ?? ''));
                            $quota = (int)($pl['quota_bytes'] ?? 0);
                            $disabled = $quota <= 0;
                            ?>
                            <div class="col-12 col-md-6 col-lg-4">
                                <div class="card shadow-sm profile-card <?php echo $disabled ? 'disabled' : ''; ?>">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="h6 mb-0"><?php echo e($pName); ?></div>
                                            <?php if ($disabled): ?>
                                                <span class="badge text-bg-warning">Quota not set</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-primary"><?php echo e(quota_label($quota)); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="small text-body-secondary mt-2">
                                            Speed: <span class="font-monospace"><?php echo e($rate !== '' ? $rate : '-'); ?></span>
                                        </div>
                                        <form method="post" class="mt-3">
                                            <input type="hidden" name="router_id" value="<?php echo e($routerId); ?>">
                                            <input type="hidden" name="nas_ip" value="<?php echo e($nasIp); ?>">
                                            <input type="hidden" name="mac" value="<?php echo e($mac); ?>">
                                            <input type="hidden" name="login_url" value="<?php echo e($loginUrl); ?>">
                                            <input type="hidden" name="dst" value="<?php echo e($dst); ?>">
                                            <input type="hidden" name="popup" value="<?php echo e($popup); ?>">
                                            <input type="hidden" name="profile" value="<?php echo e($pName); ?>">
                                            <button class="btn btn-primary w-100" type="submit" <?php echo $disabled ? 'disabled' : ''; ?>>
                                                Connect
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

