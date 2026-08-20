<?php
declare(strict_types=1);

/** Minimal CSRF that stores token under $_SESSION[<prefix>]['_csrf']. */
function csrf_token(): string
{
    \App\Shared\SessionHelper::ensureSession();
    $root = getenv('APP_SESSION_PREFIX') ?: 'cbmsv21';
    $_SESSION[$root] ??= [];
    $tok = $_SESSION[$root]['_csrf'] ?? null;
    if (!is_string($tok) || $tok === '') {
        $tok = bin2hex(random_bytes(32));
        $_SESSION[$root]['_csrf'] = $tok;
        $_SESSION[$root]['_csrf_time'] = time();
    }
    return $tok;
}

function csrf_field(): string
{
    $token = csrf_token();
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_check(?string $provided): bool
{
    \App\Shared\SessionHelper::ensureSession();
    $root = getenv('APP_SESSION_PREFIX') ?: 'cbmsv21';
    $expected = $_SESSION[$root]['_csrf'] ?? null;

    $isValid = is_string($provided) && $provided !== '' && is_string($expected) && $expected !== '' && hash_equals($expected, $provided);
    
    if (!$isValid) {
        error_log("[CSRF ERROR] CSRF check failed: provided={$provided}, expected={$expected}");
    }
    
    return $isValid;
}

function csrf_regenerate(): string
{
    \App\Shared\SessionHelper::ensureSession();
    $root = getenv('APP_SESSION_PREFIX') ?: 'cbmsv21';
    $t = bin2hex(random_bytes(32));
    $_SESSION[$root]['_csrf'] = $t;
    $_SESSION[$root]['_csrf_time'] = time();
    return $t;
}
