<?php
require __DIR__ . '/_lib/bootstrap.php';

if (is_logged_in()) {
    $u = current_user();
    $uname = is_array($u) ? (string)($u['username'] ?? '') : '';
    store_insert_app_log('info', 'auth', 'logout', ['username' => $uname]);
}

logout();
header('Location: ' . base_url('login.php'));
exit;
