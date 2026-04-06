<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/routeros_api.php';
require_once __DIR__ . '/mikrotik.php';

try {
    db_migrate();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Database connection failed</h1>';
    echo '<p>Check MySQL is running and update admin/_lib/config.php</p>';
    exit;
}

function ensure_admin_uploads_dir(): string
{
    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function save_uploaded_image(string $fieldName): ?string
{
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return null;
    }
    $f = $_FILES[$fieldName];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed.');
    }
    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid upload.');
    }
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0 || $size > 2 * 1024 * 1024) {
        throw new RuntimeException('Image must be <= 2MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($map[$mime])) {
        throw new RuntimeException('Only JPG, PNG, WEBP images are allowed.');
    }

    $ext = $map[$mime];
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $dir = ensure_admin_uploads_dir();
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Unable to save image.');
    }
    return 'uploads/' . $name;
}

function normalize_uploaded_image_path(string $path): string
{
    $p = trim($path);
    if ($p === '') {
        return '';
    }
    $p = ltrim($p, '/');
    if (str_starts_with($p, 'admin/uploads/')) {
        $p = substr($p, strlen('admin/'));
    }
    if (!str_starts_with($p, 'uploads/')) {
        return '';
    }
    return $p;
}

function uploaded_image_url(string $path): string
{
    $p = normalize_uploaded_image_path($path);
    if ($p === '') {
        return '';
    }
    return base_url($p);
}

function uploaded_image_abs_path(string $path): string
{
    $p = normalize_uploaded_image_path($path);
    if ($p === '') {
        return '';
    }
    return __DIR__ . '/../' . $p;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(string $path = ''): string
{
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $base = $scriptDir === '' ? '' : $scriptDir;
    $full = $base . '/' . ltrim($path, '/');
    return preg_replace('#/+#', '/', $full) ?? $full;
}

function app_theme(): string
{
    $theme = $_SESSION['theme'] ?? null;
    if (is_string($theme) && in_array($theme, ['light', 'dark'], true)) {
        return $theme;
    }
    return 'light';
}

function flash_add(string $type, string $message): void
{
    $type = strtolower(trim($type));
    if ($type === 'error') {
        $type = 'danger';
    }
    if (!in_array($type, ['success', 'info', 'warning', 'danger'], true)) {
        $type = 'info';
    }

    if (!is_array($_SESSION['flashes'] ?? null)) {
        $_SESSION['flashes'] = [];
    }
    $_SESSION['flashes'][] = ['type' => $type, 'message' => $message];
}

function flash_all(): array
{
    $flashes = $_SESSION['flashes'] ?? [];
    if (!is_array($flashes)) {
        $flashes = [];
    }
    $_SESSION['flashes'] = [];

    $out = [];
    foreach ($flashes as $f) {
        if (!is_array($f)) {
            continue;
        }
        $type = strtolower((string)($f['type'] ?? 'info'));
        $message = (string)($f['message'] ?? '');
        if ($message === '') {
            continue;
        }
        if ($type === 'error') {
            $type = 'danger';
        }
        if (!in_array($type, ['success', 'info', 'warning', 'danger'], true)) {
            $type = 'info';
        }
        $out[] = ['type' => $type, 'message' => $message];
    }
    return $out;
}
