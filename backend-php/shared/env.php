<?php
declare(strict_types=1);

/**
 * Loads .env file into getenv(), $_ENV, $_SERVER
 */
if (!function_exists('loadEnv')) {
    function loadEnv(string $path): void
    {
        if (!is_file($path)) {
            error_log("env.php ERROR: .env file not found at: $path");
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $loadedCount = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;

            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $key   = trim($key);
            $value = trim($value, '"\' ');

            if ($key === '') continue;

            putenv("$key=$value");
            $_ENV[$key]   = $value;
            $_SERVER[$key] = $value;

            $loadedCount++;
        }

    }
}

if (!function_exists('envStr')) {
    function envStr(string $key, string $default = ''): string
    {
        $val = getenv($key);
        return $val !== false ? $val : $default;
    }
}

if (!function_exists('envBool')) {
    function envBool(string $key, bool $default = false): bool
    {
        $val = envStr($key, $default ? 'true' : 'false');
        $val = strtolower(trim($val));
        return in_array($val, ['1', 'true', 'yes', 'on'], true);
    }
}

// ────────────────────────────────────────────────
// Automatically load .env when this file is included
// Try common paths - adjust if needed
// ────────────────────────────────────────────────

$possiblePaths = [
    __DIR__ . '/../.env',               // most common: root one level up
    __DIR__ . '/../../.env',            // deeper nesting
    dirname(__DIR__, 3) . '/.env',      // project root
];

$envLoaded = false;
foreach ($possiblePaths as $path) {
    if (is_file($path)) {
        loadEnv($path);
        $envLoaded = true;
        break;
    }
}

if (!$envLoaded) {
    error_log("env.php WARNING: No .env file found in any path");
}
