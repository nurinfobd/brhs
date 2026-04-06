<?php
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($base === '' || $base === '.') {
    $base = '';
}
header('Location: ' . $base . '/admin/');
exit;
