<?php
// shared/windows_login.php

if (!function_exists('get_windows_login')) {
    /**
     * Get Windows login (dev override or real server var)
     */
    function get_windows_login(): ?string
    {
        if (envBool('DEV_WINDOWS_ENABLED', false)) {
            $dev = trim(envStr('DEV_WINDOWS_LOGIN', ''));
            if ($dev !== '') {
                return $dev;
            }
        }

        $raw = $_SERVER['AUTH_USER'] ?? $_SERVER['LOGON_USER'] ?? $_SERVER['REMOTE_USER'] ?? '';
        if ($raw === '') return null;

        if (str_contains($raw, '\\')) {
            $raw = explode('\\', $raw)[1];
        }

        return trim(mb_strtolower($raw, 'UTF-8'));
    }
}

if (!function_exists('find_user_by_windows_login')) {
    /**
     * Find user by Windows login (shared helper).
     * PDO only: auth now uses the same DB access path as the rest of the app.
     */
    function find_user_by_windows_login(\PDO $conn, string $windowsLogin): ?array
    {
        $w = trim(mb_strtolower($windowsLogin, 'UTF-8'));
        if ($w === '') return null;
        $wLike = $w . '@%';

        $st = $conn->prepare("
            SELECT TOP 1 *
            FROM dbo.tblUsers
            WHERE
                LOWER(LTRIM(RTRIM(WindowsLogin))) = :w1
                OR LOWER(LTRIM(RTRIM(Username))) = :w2
                OR LOWER(LTRIM(RTRIM(Email))) = :w3
                OR LOWER(LTRIM(RTRIM(Email))) LIKE :wlike
        ");
        $st->execute(['w1' => $w, 'w2' => $w, 'w3' => $w, 'wlike' => $wLike]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
}
