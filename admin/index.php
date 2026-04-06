<?php
require __DIR__ . '/_lib/bootstrap.php';

if (is_logged_in()) {
    header('Location: ' . base_url('dashboard.php'));
    exit;
}

header('Location: ' . base_url('login.php'));
exit;
