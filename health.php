<?php
// ═════════════════════════════════════════════════════════════════════════════
//  health.php — lightweight health check for load balancers / uptime monitors /
//  Render health checks. Returns JSON; no auth; leaks no sensitive data.
//  200 = healthy, 503 = degraded (DB unreachable).
// ═════════════════════════════════════════════════════════════════════════════
require_once 'includes/config.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$status = ['status' => 'ok', 'app' => SITE_NAME, 'version' => SITE_VERSION,
           'php' => PHP_VERSION, 'time' => date('c'), 'checks' => []];

// DB connectivity (direct PDO probe — must not trigger getDB()'s hard-exit path)
try {
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', DB_HOST, (int)DB_PORT, DB_NAME, DB_SSLMODE);
    $probe = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
    $probe->query('SELECT 1');
    $probe = null;
    $status['checks']['database'] = 'ok';
} catch (Throwable $e) {
    $status['status'] = 'degraded';
    $status['checks']['database'] = 'unreachable';
}

// Writable paths (uploads + logs) — required for resume upload & logging
$status['checks']['uploads_writable'] = is_writable(SH_UPLOAD_DIR) ? 'ok' : 'readonly';
$status['checks']['logs_writable']    = is_writable(SH_LOG_DIR) ? 'ok' : 'readonly';

http_response_code($status['status'] === 'ok' ? 200 : 503);
echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
