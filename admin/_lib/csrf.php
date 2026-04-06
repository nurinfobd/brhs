<?php
declare(strict_types=1);

function csrf_token(): string
{
    $token = $_SESSION['csrf_token'] ?? null;
    if (!is_string($token) || strlen($token) < 32) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
    }
    return $token;
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        echo 'Invalid CSRF token.';
        exit;
    }
}

