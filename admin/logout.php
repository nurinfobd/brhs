<?php
require __DIR__ . '/_lib/bootstrap.php';

logout();
header('Location: ' . base_url('login.php'));
exit;

