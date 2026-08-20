<?php
declare(strict_types=1);

/**
 * Returns a per-request ID, stable for the life of the PHP request.
 * - Uses incoming X-Request-Id if present (sanitized)
 * - Otherwise generates a random id
 * - Exposes it in $_SERVER['CBMS_REQUEST_ID'] and as an X-Request-Id header
 */
if (!function_exists('cbms_request_id')) {
    function cbms_request_id(): string
    {
        static $rid = null;
        if ($rid !== null) {
            return $rid;
        }

        if (!empty($_SERVER['HTTP_X_REQUEST_ID'])) {
            $rid = preg_replace('/[^a-zA-Z0-9._-]/', '', (string)$_SERVER['HTTP_X_REQUEST_ID']);
        } elseif (!empty($_SERVER['CBMS_REQUEST_ID'])) {
            $rid = (string)$_SERVER['CBMS_REQUEST_ID'];
        } else {
            try {
                $rid = bin2hex(random_bytes(12)); // 24 hex chars
            } catch (\Throwable $e) {
                $rid = str_replace('.', '', uniqid('', true));
            }
        }

        $_SERVER['CBMS_REQUEST_ID'] = $rid;
        if (!headers_sent()) {
            @header('X-Request-Id: ' . $rid, true);
        }
        return $rid;
    }
}
