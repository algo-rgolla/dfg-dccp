<?php
declare(strict_types=1);

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/request_id.php'; // provides cbms_request_id() if present

/**
 * Tiny internal translator wrapper: use __t if available, else fallback.
 */
if (!function_exists('_et')) {
    function _et(string $key, string $fallback): string {
        return function_exists('__t') ? __t($key) : $fallback;
    }
}

/**
 * Convert warnings/notices into exceptions
 */
set_error_handler(function ($severity, $message, $file, $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

/**
 * Render a friendly error box (used when APP_DEBUG=false)
 */
function renderFriendlyErrorBox(?string $title = null): void {
    $title      = $title ?? _et('error_generic_title', 'Sorry, something went wrong');
    $message    = _et('error_generic_message', 'Our technical team has been notified. Please try again later.');
    $homeLabel  = _et('home', 'Home');
    $backLabel  = _et('back', 'Back');
    $refLabel   = _et('reference_id', 'Reference ID');

    // Try to get a request id if generator is available
    $rid = function_exists('cbms_request_id') ? cbms_request_id() : null;

    echo "<div style='
              max-width:600px;
              margin:2em auto;
              padding:1em;
              border:1px solid #ebccd1;
              border-radius:6px;
              background:#f2dede;
              color:#a94442;
              font-family:sans-serif;'>
            <h2 style='margin-top:0;'>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</h2>
            <p>" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</p>";

    if ($rid) {
        echo "<p style='margin:.75em 0 0;color:#6c757d;'>
                <small>" . htmlspecialchars($refLabel, ENT_QUOTES, 'UTF-8') . ": "
                    . htmlspecialchars((string)$rid, ENT_QUOTES, 'UTF-8') .
               "</small>
              </p>";
    }

    echo "  <div style='margin-top:1em;'>
              <a href='index.php?route=home/index' 
                 style='display:inline-block;padding:6px 12px;margin-right:6px;
                        background:#0d6efd;color:#fff;text-decoration:none;
                        border-radius:4px;'>" . htmlspecialchars($homeLabel, ENT_QUOTES, 'UTF-8') . "</a>
              <a href='javascript:history.back()' 
                 style='display:inline-block;padding:6px 12px;
                        background:#6c757d;color:#fff;text-decoration:none;
                        border-radius:4px;'>" . htmlspecialchars($backLabel, ENT_QUOTES, 'UTF-8') . "</a>
            </div>
          </div>";
}

/**
 * Handle uncaught exceptions
 */
set_exception_handler(function (Throwable $e): void {
    // include RID in the log context (logger already adds it too, but harmless)
    $rid = function_exists('cbms_request_id') ? cbms_request_id() : null;

    app_log("Unhandled Exception: " . $e->getMessage(), [
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
        'trace' => $e->getTraceAsString(),
        'RequestID' => $rid,
    ], 'error');

    http_response_code(500);

    $isDebug = envFlag('APP_DEBUG', false);

    if ($isDebug) {
        $title = _et('application_error', 'Application Error');
        echo "<div style='padding:1em;background:#fee;color:#900;border:1px solid #c00'>
                <h2>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</h2>";
        if ($rid) {
            echo "<p><small style='color:#555'>" . htmlspecialchars(_et('reference_id', 'Reference ID'), ENT_QUOTES, 'UTF-8')
               . ": " . htmlspecialchars((string)$rid, ENT_QUOTES, 'UTF-8') . "</small></p>";
        }
        echo "  <pre>" . htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8') . "</pre>
              </div>";
    } else {
        renderFriendlyErrorBox(_et('application_error', 'Application Error'));
    }

    if (ob_get_length()) { ob_end_flush(); }
    flush();
    exit(1);
});

/**
 * Handle fatal errors (E_ERROR, E_PARSE, etc.)
 */
register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $rid = function_exists('cbms_request_id') ? cbms_request_id() : null;

        app_log("Fatal Error: " . $error['message'], [
            'file' => $error['file'],
            'line' => $error['line'],
            'RequestID' => $rid,
        ], 'error');

        http_response_code(500);

        $isDebug = envFlag('APP_DEBUG', false);

        if ($isDebug) {
            $title  = _et('fatal_error', 'Fatal Error');
            $detail = $error['message'] . ' in ' . $error['file'] . ':' . $error['line'];
            echo "<div style='padding:1em;background:#fee;color:#900;border:1px solid #c00'>
                    <h2>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</h2>";
            if ($rid) {
                echo "<p><small style='color:#555'>" . htmlspecialchars(_et('reference_id', 'Reference ID'), ENT_QUOTES, 'UTF-8')
                   . ": " . htmlspecialchars((string)$rid, ENT_QUOTES, 'UTF-8') . "</small></p>";
            }
            echo "    <pre>" . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . "</pre>
                  </div>";
        } else {
            renderFriendlyErrorBox(_et('fatal_error', 'Fatal Error'));
        }

        if (ob_get_length()) { ob_end_flush(); }
        flush();
        exit(1);
    }
});

/**
 * Safe DB query wrapper for Diagnostics and critical queries.
 */
if (!function_exists('db_query')) {
    function db_query(\PDO $conn, string $sql): \PDOStatement {
        $st = $conn->query($sql);
        if ($st === false) {
            $info = $conn->errorInfo();
            throw new \RuntimeException("DB Error [{$info[0]}]: {$info[2]} in SQL: {$sql}");
        }
        return $st;
    }
}
