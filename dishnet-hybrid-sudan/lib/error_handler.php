<?php
/**
 * DishNet Hybrid Telecom — Global Error Handler
 *
 * Include this at the top of EVERY entry point:
 *   public.php, wa_webhook.php, main.php, cron/master.php, webhook.php
 *
 * What it does:
 *   1. Catches fatal errors (E_ERROR, E_PARSE, E_COMPILE_ERROR) via shutdown function
 *   2. Catches uncaught exceptions via exception handler
 *   3. Logs everything to error_log() (goes to UCRM plugin log)
 *   4. Returns a safe error message instead of blank white page
 *
 * Entry points can override the output format AFTER including this file
 * by setting $GLOBALS['_DISHNET_ERROR_FORMAT'] = 'json' or 'html' (default).
 *
 * PHP 7.4 compatible.
 */

// Only register once (multiple includes are safe but redundant)
if (!empty($GLOBALS['_DISHNET_ERROR_HANDLER_LOADED'])) return;
$GLOBALS['_DISHNET_ERROR_HANDLER_LOADED'] = true;

// ── 1. Uncaught exception handler ────────────────────────────────────────────
set_exception_handler(function (\Throwable $e) {
    error_log('[DishNet UNCAUGHT] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    error_log('[DishNet TRACE] ' . substr($e->getTraceAsString(), 0, 1000));

    if (headers_sent()) return;

    $isJson = ($GLOBALS['_DISHNET_ERROR_FORMAT'] ?? '') === 'json'
           || (isset($_GET['page']) && in_array($_GET['page'], ['api','stock_api','wa_webhook','evo_webhook'], true))
           || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    http_response_code(500);
    if ($isJson) {
        while (ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'status'  => 'error',
            'message' => 'Uncaught: ' . $e->getMessage(),
            'file'    => basename($e->getFile()),
            'line'    => $e->getLine(),
        ]);
    } else {
        echo '<div style="padding:20px;font-family:sans-serif;color:#721c24;background:#f8d7da;border:1px solid #f5c6cb;border-radius:4px;margin:20px;">'
           . '<strong>System Error</strong><br>An unexpected error occurred. It has been logged.<br>'
           . '<small style="color:#999">' . htmlspecialchars(basename($e->getFile())) . ':' . $e->getLine() . '</small>'
           . '</div>';
    }
});

// ── 2. Fatal error shutdown handler ──────────────────────────────────────────
register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error) return;
    if (!in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;

    error_log('[DishNet FATAL] ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);

    if (headers_sent()) return;

    $isJson = ($GLOBALS['_DISHNET_ERROR_FORMAT'] ?? '') === 'json'
           || (isset($_GET['page']) && in_array($_GET['page'], ['api','stock_api','wa_webhook','evo_webhook'], true))
           || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    http_response_code(500);
    if ($isJson) {
        while (ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'status'  => 'error',
            'message' => 'Fatal: ' . $error['message'],
            'file'    => basename($error['file']),
            'line'    => $error['line'],
        ]);
    } else {
        echo '<div style="padding:20px;font-family:sans-serif;color:#721c24;background:#f8d7da;border:1px solid #f5c6cb;border-radius:4px;margin:20px;">'
           . '<strong>System Error</strong><br>A fatal error occurred. It has been logged.<br>'
           . '<small style="color:#999">' . htmlspecialchars(basename($error['file'])) . ':' . $error['line'] . '</small>'
           . '</div>';
    }
});
