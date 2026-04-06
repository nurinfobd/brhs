<?php
declare(strict_types=1);

function is_logged_in(): bool
{
    return is_string($_SESSION['user_id'] ?? null) && $_SESSION['user_id'] !== '';
}

function require_auth(): void
{
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script === 'login.php') {
        return;
    }
    if (!is_logged_in()) {
        header('Location: ' . base_url('login.php'));
        exit;
    }

    $mustChange = (bool)($_SESSION['must_change_password'] ?? false);
    if ($mustChange && !in_array($script, ['change-password.php', 'logout.php'], true)) {
        header('Location: ' . base_url('change-password.php'));
        exit;
    }
}

function current_user(): ?array
{
    if (!is_logged_in()) {
        return null;
    }
    $user = store_get_user((string)$_SESSION['user_id']);
    return is_array($user) ? $user : null;
}

function current_role(): string
{
    $role = $_SESSION['role'] ?? null;
    if (is_string($role) && in_array($role, ['superadmin', 'admin'], true)) {
        return $role;
    }
    $u = current_user();
    $r = is_array($u) ? (string)($u['role'] ?? 'admin') : 'admin';
    if (!in_array($r, ['superadmin', 'admin'], true)) {
        $r = 'admin';
    }
    $_SESSION['role'] = $r;
    return $r;
}

function is_superadmin(): bool
{
    return current_role() === 'superadmin';
}

function login_attempt(string $username, string $password): bool
{
    $user = store_find_user_by_username($username);
    if (!$user) {
        return false;
    }
    $hash = $user['password_hash'] ?? '';
    if (!is_string($hash) || $hash === '') {
        return false;
    }
    if (!password_verify($password, $hash)) {
        return false;
    }
    $_SESSION['user_id'] = (string)$user['id'];
    $_SESSION['theme'] = (string)($user['theme'] ?? 'light');
    $_SESSION['must_change_password'] = ((int)($user['must_change_password'] ?? 0)) === 1;
    $_SESSION['role'] = in_array((string)($user['role'] ?? 'admin'), ['superadmin', 'admin'], true) ? (string)$user['role'] : 'admin';
    session_regenerate_id(true);
    return true;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}
