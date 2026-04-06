<?php
require_auth();
$theme = app_theme();
$bodyClass = $theme === 'dark' ? 'bg-body-tertiary' : 'bg-light';
$cardClass = $theme === 'dark' ? 'bg-dark text-white border-secondary' : '';
$sidebarBg = $theme === 'dark' ? 'bg-dark border-secondary' : 'bg-white';
$sidebarText = $theme === 'dark' ? 'text-white' : 'text-dark';
$activeClass = $theme === 'dark' ? 'active text-white' : 'active';
$user = current_user();
$toasts = flash_all();
if (is_array($pageToasts ?? null)) {
    foreach ($pageToasts as $t) {
        if (is_array($t) && isset($t['message'])) {
            $toasts[] = [
                'type' => (string)($t['type'] ?? 'info'),
                'message' => (string)$t['message'],
            ];
        }
    }
}

ob_start();
?>
<div class="p-3 border-bottom">
    <div class="d-flex align-items-center gap-2">
        <img src="<?php echo e(base_url('logo.png')); ?>" alt="Logo" class="brand-logo">
        <div>
            <div class="fw-semibold <?php echo e($sidebarText); ?>">City Univercity</div>
            <div class="small text-body-secondary">Hotspot Portal</div>
        </div>
    </div>
</div>
<?php
$sidebarHeaderHtml = ob_get_clean();

ob_start();
?>
<ul class="nav nav-pills flex-column gap-1">
    <li class="nav-item">
        <a class="nav-link <?php echo ($active ?? '') === 'dashboard' ? e($activeClass) : ''; ?>" href="<?php echo e(base_url('dashboard.php')); ?>">
            <i class="bi bi-speedometer2 me-2"></i>Dashboard
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo ($active ?? '') === 'live' ? e($activeClass) : ''; ?>" href="<?php echo e(base_url('live-monitor.php')); ?>">
            <i class="bi bi-activity me-2"></i>Live Monitor
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo ($active ?? '') === 'hotspot' ? e($activeClass) : ''; ?>" href="<?php echo e(base_url('users-report.php')); ?>">
            <i class="bi bi-people me-2"></i>Hotspot User
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo ($active ?? '') === 'hotspot_profiles' ? e($activeClass) : ''; ?>" href="<?php echo e(base_url('hotspot-profiles.php')); ?>">
            <i class="bi bi-sliders me-2"></i>Hotspot Profiles
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo ($active ?? '') === 'radius_acct' ? e($activeClass) : ''; ?>" href="<?php echo e(base_url('radius-accounting.php')); ?>">
            <i class="bi bi-receipt me-2"></i>RADIUS Accounting
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo ($active ?? '') === 'radius_server' ? e($activeClass) : ''; ?>" href="<?php echo e(base_url('radius-server.php')); ?>">
            <i class="bi bi-shield-lock me-2"></i>RADIUS Server
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo ($active ?? '') === 'status' ? e($activeClass) : ''; ?>" href="<?php echo e(base_url('status.php')); ?>">
            <i class="bi bi-clipboard-data me-2"></i>Status
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo ($active ?? '') === 'router' ? e($activeClass) : ''; ?>" href="<?php echo e(base_url('routers.php')); ?>">
            <i class="bi bi-hdd-network me-2"></i>Router
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo ($active ?? '') === 'settings' ? e($activeClass) : ''; ?>" href="<?php echo e(base_url('settings.php')); ?>">
            <i class="bi bi-gear me-2"></i>Settings
        </a>
    </li>
</ul>
<?php
$sidebarMenuHtml = ob_get_clean();

ob_start();
?>
<div class="d-flex align-items-center justify-content-between">
    <div>
        <div class="small text-body-secondary">Signed in</div>
        <div class="fw-semibold"><?php echo e((string)($user['username'] ?? '')); ?></div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?php echo e(base_url('logout.php')); ?>">Logout</a>
</div>
<?php
$sidebarFooterHtml = ob_get_clean();
?>
<!doctype html>
<html lang="en" data-bs-theme="<?php echo e($theme); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($title ?? 'Admin'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body{font-family:"Rajdhani",system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}
        .app-shell{min-height:100vh}
        .sidebar{width:280px;position:sticky;top:0;height:100vh}
        .sidebar-mobile{width:auto;position:static;height:auto}
        .brand-logo{width:50px;height:50px;object-fit:contain;flex:0 0 auto}
        .brand-logo-sm{width:50px;height:50px}
        .mobile-topbar{position:fixed;top:0;left:0;right:0;z-index:1040}
        .mobile-topbar .mobile-topbar-inner{display:grid;grid-template-columns:44px 1fr 44px;align-items:center;gap:.5rem}
        .mobile-topbar .mobile-brand{font-weight:700;letter-spacing:.01em}
        .mobile-topbar .mobile-menu-btn{width:38px;height:38px;display:inline-flex;align-items:center;justify-content:center;padding:0}
        .mobile-bottomnav{position:fixed;left:0;right:0;bottom:0;z-index:1040;border-top:1px solid rgba(0,0,0,.08);background:var(--bs-body-bg);padding:.35rem .25rem calc(.35rem + env(safe-area-inset-bottom))}
        [data-bs-theme="dark"] .mobile-bottomnav{border-top-color:rgba(255,255,255,.10)}
        .mobile-bottomnav .nav-item{flex:1;text-decoration:none;color:rgba(33,37,41,.82);text-align:center;font-weight:600;font-size:.68rem}
        [data-bs-theme="dark"] .mobile-bottomnav .nav-item{color:rgba(255,255,255,.82)}
        .mobile-bottomnav .nav-item .ico{display:block;font-size:1.2rem;line-height:1}
        .mobile-bottomnav .nav-item.active{color:#0d6efd}
        .mobile-bottomnav .nav-center{flex:1;text-align:center}
        .mobile-bottomnav .nav-center .center-btn{width:52px;height:52px;border-radius:50%;background:#0d6efd;color:#fff;display:flex;align-items:center;justify-content:center;margin:-24px auto 2px;box-shadow:0 .5rem 1.25rem rgba(13,110,253,.28)}
        .mobile-bottomnav .nav-center .center-label{font-weight:700;font-size:.70rem;color:rgba(33,37,41,.82)}
        [data-bs-theme="dark"] .mobile-bottomnav .nav-center .center-label{color:rgba(255,255,255,.82)}
        .mobile-bottomnav .nav-center.active .center-btn{background:#0d6efd}
        .mobile-bottomnav .nav-center.active .center-label{color:#0d6efd}
        .sidebar .nav-link{
            border-radius:.55rem;
            padding:.6rem .75rem;
            display:flex;
            align-items:center;
            gap:.55rem;
            font-weight:600;
            color:rgba(33,37,41,.9);
        }
        .sidebar .nav-link i{opacity:.9}
        .sidebar .nav-link:hover{background:rgba(13,110,253,.10);color:rgba(33,37,41,1)}
        .sidebar .nav-link.active{
            background:#0d6efd;
            color:#fff;
            box-shadow:0 .25rem .75rem rgba(13,110,253,.18);
        }
        .sidebar .nav-link.active i{opacity:1}
        [data-bs-theme="dark"] .sidebar .nav-link{color:rgba(255,255,255,.88)}
        [data-bs-theme="dark"] .sidebar .nav-link:hover{background:rgba(13,110,253,.22);color:#fff}
        [data-bs-theme="dark"] .sidebar .nav-link.active{background:rgba(13,110,253,.88);box-shadow:0 .25rem .75rem rgba(13,110,253,.22)}
        .metric{font-size:1.75rem;font-weight:700;letter-spacing:-.02em}
        .table td,.table th{vertical-align:middle}
        .table-responsive{border:1px solid rgba(0,0,0,.06);border-radius:.55rem;overflow:auto;-webkit-overflow-scrolling:touch;max-width:100%}
        [data-bs-theme="dark"] .table-responsive{border-color:rgba(255,255,255,.10)}
        .table{margin-bottom:0}
        .table > :not(caption) > * > *{border-color:rgba(0,0,0,.06)}
        [data-bs-theme="dark"] .table > :not(caption) > * > *{border-color:rgba(255,255,255,.08)}
        .table thead th{background:rgba(13,110,253,.04);font-weight:700}
        [data-bs-theme="dark"] .table thead th{background:rgba(255,255,255,.04)}
        .card{border-radius:.55rem}
        .modal-content{border-radius:.55rem}
        @media (max-width:575.98px){
            .table{font-size:.84rem}
            .table thead th{font-size:.78rem}
            .table td,.table th{padding:.45rem .5rem}
            .pagination-sm .page-link{padding:.25rem .45rem}
        }
        @media (max-width:991.98px){
            .mobile-content{padding-top:3.75rem!important;padding-bottom:5.75rem!important}
        }
    </style>
</head>
<body class="<?php echo e($bodyClass); ?>">
<?php if (count($toasts) > 0): ?>
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1080;">
        <?php foreach ($toasts as $t): ?>
            <?php
            $type = strtolower((string)($t['type'] ?? 'info'));
            if ($type === 'error') {
                $type = 'danger';
            }
            if (!in_array($type, ['success', 'info', 'warning', 'danger'], true)) {
                $type = 'info';
            }
            ?>
            <div class="toast text-bg-<?php echo e($type); ?> border-0 mb-2" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body"><?php echo e((string)($t['message'] ?? '')); ?></div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<nav class="mobile-topbar border-bottom bg-body d-lg-none">
    <div class="mobile-topbar-inner px-3 py-2">
        <div class="d-flex align-items-center justify-content-start">
            <img src="<?php echo e(base_url('logo.png')); ?>" alt="Logo" class="brand-logo brand-logo-sm">
        </div>
        <div class="mobile-brand text-center text-truncate">Hotspot Portal</div>
        <div class="d-flex align-items-center justify-content-end">
            <button class="btn btn-outline-secondary btn-sm mobile-menu-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-controls="mobileSidebar">
                <i class="bi bi-three-dots-vertical"></i>
            </button>
        </div>
    </div>
</nav>

<div class="offcanvas offcanvas-start <?php echo e($sidebarBg); ?>" tabindex="-1" id="mobileSidebar" aria-labelledby="mobileSidebarLabel">
    <div class="offcanvas-header border-bottom">
        <div class="d-flex align-items-center gap-2" id="mobileSidebarLabel">
            <img src="<?php echo e(base_url('logo.png')); ?>" alt="Logo" class="brand-logo">
            <div>
                <div class="fw-semibold <?php echo e($sidebarText); ?>">Bangladesh Railway</div>
                <div class="small text-body-secondary">Hotspot Portal</div>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-0">
        <div class="sidebar sidebar-mobile d-flex flex-column h-100">
            <div class="p-3 d-flex flex-column flex-grow-1">
                <div class="small text-body-secondary mb-2">Menu</div>
                <?php echo $sidebarMenuHtml; ?>
                <hr class="mt-auto">
                <?php echo $sidebarFooterHtml; ?>
            </div>
        </div>
    </div>
</div>

<div class="app-shell d-flex">
    <aside class="sidebar border-end <?php echo e($sidebarBg); ?> d-none d-lg-flex flex-column">
        <?php echo $sidebarHeaderHtml; ?>
        <div class="p-3 d-flex flex-column flex-grow-1">
            <div class="small text-body-secondary mb-2">Menu</div>
            <?php echo $sidebarMenuHtml; ?>
            <hr class="mt-auto">
            <?php echo $sidebarFooterHtml; ?>
        </div>
    </aside>
    <main class="flex-grow-1">
        <div class="container-fluid p-3 p-lg-4 mobile-content">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h1 class="h4 mb-0"><?php echo e($title ?? ''); ?></h1>
                <?php if (!empty($topActionsHtml ?? '')): ?>
                    <div class="d-flex gap-2"><?php echo $topActionsHtml; ?></div>
                <?php endif; ?>
            </div>
            <?php echo $contentHtml ?? ''; ?>
        </div>
    </main>
</div>
<nav class="mobile-bottomnav d-lg-none">
    <?php $a = (string)($active ?? ''); ?>
    <div class="d-flex align-items-end">
        <a class="nav-item <?php echo $a === 'live' ? 'active' : ''; ?>" href="<?php echo e(base_url('live-monitor.php')); ?>">
            <span class="ico"><i class="bi bi-activity"></i></span>
            Live
        </a>
        <a class="nav-item <?php echo $a === 'hotspot' ? 'active' : ''; ?>" href="<?php echo e(base_url('users-report.php')); ?>">
            <span class="ico"><i class="bi bi-people"></i></span>
            Users
        </a>
        <a class="nav-center <?php echo $a === 'dashboard' ? 'active' : ''; ?>" href="<?php echo e(base_url('dashboard.php')); ?>">
            <div class="center-btn"><i class="bi bi-speedometer2 fs-4"></i></div>
            <div class="center-label">Dashboard</div>
        </a>
        <a class="nav-item <?php echo $a === 'hotspot_profiles' ? 'active' : ''; ?>" href="<?php echo e(base_url('hotspot-profiles.php')); ?>">
            <span class="ico"><i class="bi bi-sliders"></i></span>
            Profiles
        </a>
        <a class="nav-item <?php echo $a === 'settings' ? 'active' : ''; ?>" href="<?php echo e(base_url('settings.php')); ?>">
            <span class="ico"><i class="bi bi-gear"></i></span>
            Settings
        </a>
    </div>
</nav>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.querySelectorAll('.toast').forEach(function (el) {
        var t = new bootstrap.Toast(el, { delay: 3500 });
        t.show();
    });
</script>
</body>
</html>
