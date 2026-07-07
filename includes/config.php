<?php
// ═════════════════════════════════════════════════════════════════════════════
//  SmartHire v7 — includes/config.php   (Hardened Core)
//  Backward compatible: every v6 helper keeps its exact signature.
//  Adds: security headers, session hardening, CSRF, RBAC, validation,
//        brute-force protection, audit logging, safe error handling,
//        secure file uploads.  Load this at the TOP of every page (before output).
// ═════════════════════════════════════════════════════════════════════════════

// ── App identity ─────────────────────────────────────────────────────────────
define('SITE_NAME',    'SmartHire');
define('SITE_VERSION', '7.0');

// ── Environment / DB credentials ─────────────────────────────────────────────
// Precedence: real environment variables (Render/Docker/hosting) → config.local.php
// → safe XAMPP defaults. This lets the same code run locally and in production
// without editing files: set DB_HOST, DB_USER, DB_PASS, DB_NAME, SH_DEBUG,
// SH_HTTPS, and the mail vars as environment variables in your host.
if (is_file(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}
// Promote environment variables to constants when not already defined locally.
$__envmap = [
    'DB_HOST' => 'string', 'DB_USER' => 'string', 'DB_PASS' => 'string', 'DB_NAME' => 'string',
    'DB_PORT' => 'int', 'DB_SSLMODE' => 'string',
    'SH_DEBUG' => 'bool',  'SH_HTTPS' => 'bool',
    'SH_MAIL_TRANSPORT' => 'string', 'SH_MAIL_FROM' => 'string', 'SH_MAIL_FROM_NAME' => 'string',
    'SH_SMTP_HOST' => 'string', 'SH_SMTP_PORT' => 'int', 'SH_SMTP_USER' => 'string',
    'SH_SMTP_PASS' => 'string', 'SH_SMTP_SECURE' => 'string',
];
// Neon/Render commonly inject a single DATABASE_URL — parse it into DB_* if present.
$__dburl = getenv('DATABASE_URL');
if ($__dburl && ($__p = parse_url($__dburl)) && !empty($__p['host'])) {
    defined('DB_HOST') || define('DB_HOST', $__p['host']);
    defined('DB_PORT') || define('DB_PORT', $__p['port'] ?? 5432);
    defined('DB_USER') || define('DB_USER', urldecode($__p['user'] ?? 'postgres'));
    defined('DB_PASS') || define('DB_PASS', urldecode($__p['pass'] ?? ''));
    defined('DB_NAME') || define('DB_NAME', ltrim($__p['path'] ?? '', '/'));
    if (!defined('DB_SSLMODE')) {
        parse_str($__p['query'] ?? '', $__q);
        define('DB_SSLMODE', $__q['sslmode'] ?? 'require');
    }
}
foreach ($__envmap as $__k => $__type) {
    if (defined($__k)) continue;
    $__v = getenv($__k);
    if ($__v === false || $__v === '') continue;
    if ($__type === 'bool') define($__k, in_array(strtolower($__v), ['1','true','on','yes'], true));
    elseif ($__type === 'int') define($__k, (int)$__v);
    else define($__k, $__v);
}
defined('DB_HOST')   || define('DB_HOST', 'localhost');
defined('DB_PORT')   || define('DB_PORT', 5432);
defined('DB_USER')   || define('DB_USER', 'postgres');
defined('DB_PASS')   || define('DB_PASS', '');
defined('DB_NAME')   || define('DB_NAME', 'smarthire');
defined('DB_SSLMODE')|| define('DB_SSLMODE', getenv('DB_SSLMODE') ?: 'prefer'); // 'require' for Neon
defined('SH_DEBUG')  || define('SH_DEBUG', false);   // true only on local dev
defined('SH_HTTPS')  || define('SH_HTTPS', false);   // true when served over TLS
defined('SH_LOG_DIR')|| define('SH_LOG_DIR', __DIR__ . '/../logs');
defined('SH_UPLOAD_DIR') || define('SH_UPLOAD_DIR', __DIR__ . '/../uploads');

// ── Error handling: log everything, never leak stack traces to users ─────────
@is_dir(SH_LOG_DIR) || @mkdir(SH_LOG_DIR, 0775, true);
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', SH_LOG_DIR . '/php_errors.log');
ini_set('display_errors', SH_DEBUG ? '1' : '0');

function sh_log_error(string $msg): void {
    @error_log('[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, 3, SH_LOG_DIR . '/app.log');
}
function sh_friendly_error(int $code = 500): void {
    if (!headers_sent()) http_response_code($code);
    echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title>'
       . '<div style="font-family:system-ui,sans-serif;max-width:520px;margin:14vh auto;'
       . 'padding:32px;text-align:center;color:#334155">'
       . '<div style="font-size:44px">&#9888;&#65039;</div>'
       . '<h1 style="margin:12px 0 6px;font-size:20px;color:#0f172a">Something went wrong</h1>'
       . '<p style="color:#64748b;font-size:14px">An unexpected error occurred and has been '
       . 'logged. Please try again, or contact the administrator if it persists.</p>'
       . '<a href="dashboard.php" style="color:#7c3aed;font-weight:600;font-size:14px">&larr; Back to safety</a></div>';
}

set_exception_handler(function (Throwable $e) {
    sh_log_error('UNCAUGHT ' . get_class($e) . ': ' . $e->getMessage() .
                 ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (SH_DEBUG) {
        if (!headers_sent()) http_response_code(500);
        echo '<pre style="padding:20px;font-family:monospace">' . htmlspecialchars((string)$e) . '</pre>';
    } else {
        sh_friendly_error();
    }
    exit;
});

// ── Security response headers (safe defaults; CSP allows the CDNs the app uses)─
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    // CSP: unsafe-inline for scripts is retained because pages use inline JS;
    // migrate inline scripts to assets/js/v7.js to remove it (Phase 4).
    header("Content-Security-Policy: default-src 'self'; "
        . "img-src 'self' data: https:; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; "
        . "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com; "
        . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
        . "connect-src 'self'; "
        . "form-action 'self'; "
        . "base-uri 'self'; "
        . "frame-ancestors 'none'");        // stronger than SAMEORIGIN for embedding
    if (SH_HTTPS) header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
}

// ── Hardened session (HttpOnly, SameSite, idle timeout, fixation defence) ─────
// Guarded for CLI/test contexts where headers are already sent (no web behavior change).
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli' && !headers_sent()) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => SH_HTTPS,
    ]);
    session_name('SHSESID');
    session_start();
}
// Idle timeout (30 min) + periodic rotation of the session id (every 15 min).
if (session_status() === PHP_SESSION_ACTIVE) {
    $__now = time();
    if (!empty($_SESSION['_last']) && ($__now - $_SESSION['_last']) > 1800) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['_last'] = $__now;
    if (empty($_SESSION['_born'])) { $_SESSION['_born'] = $__now; }
    elseif ($__now - $_SESSION['_born'] > 900) { session_regenerate_id(true); $_SESSION['_born'] = $__now; }
}

// ═════════════════════════════════════════════════════════════════════════════
//  DATABASE  (PDO / PostgreSQL — Neon-ready; unchanged public helper API)
// ═════════════════════════════════════════════════════════════════════════════
function getDB(): PDO {
    static $conn = null;
    if ($conn instanceof PDO) return $conn;
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
                   DB_HOST, (int)DB_PORT, DB_NAME, DB_SSLMODE);
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => false,   // use Neon's pooled endpoint for connection pooling
        PDO::ATTR_TIMEOUT            => 5,        // 5 s connect timeout (Neon cold-start guard)
    ];
    $attempts = 0;
    while (true) {
        try {
            $conn = new PDO($dsn, DB_USER, DB_PASS, $opts);
            // Set statement timeout to prevent long-running queries from blocking the server
            $conn->exec("SET statement_timeout = '10s'");
            return $conn;
        } catch (Throwable $e) {
            if (++$attempts < 2) { usleep(300000); continue; }   // one quick retry (300 ms)
            sh_log_error('DB connect failed: ' . $e->getMessage());
            if (SH_DEBUG) {
                die('<pre style="padding:24px;font-family:monospace;color:#b91c1c">'
                    . 'Database connection failed: ' . htmlspecialchars($e->getMessage())
                    . "\n\nEnsure PostgreSQL/Neon is reachable and the schema is loaded.</pre>");
            }
            sh_friendly_error(503);
            exit;
        }
    }
}

// NOTE: the $types parameter (mysqli bind-type string) is retained for signature
// compatibility with all existing call sites but is IGNORED — PDO binds positional
// (?) params by value. This let the MySQL→PostgreSQL port avoid touching 100+ queries.
function dbFetchAll(string $sql, string $types = '', ...$params): array {
    try {
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params ?: []);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        sh_log_error('query failed: ' . $e->getMessage() . ' | ' . $sql);
        return [];
    }
}
function dbFetchOne(string $sql, string $types = '', ...$params): ?array {
    return dbFetchAll($sql, $types, ...$params)[0] ?? null;
}
/**
 * Execute a write. Returns: new id for INSERT (when a serial exists), true for a
 * successful UPDATE/DELETE, false on failure — preserving the original contract.
 */
function dbExecute(string $sql, string $types = '', ...$params): bool|int {
    $db = getDB();
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params ?: []);
    } catch (Throwable $e) {
        sh_log_error('execute failed: ' . $e->getMessage() . ' | ' . $sql);
        return false;
    }
    if (stripos(ltrim($sql), 'INSERT') === 0) {
        try { $id = (int)$db->lastInsertId(); if ($id > 0) return $id; } catch (Throwable $e) { /* no serial */ }
        return true;
    }
    return true;
}
/** Run $fn inside a transaction; auto rollback on exception. */
function withTransaction(callable $fn) {
    $db = getDB();
    $db->beginTransaction();
    try { $res = $fn($db); $db->commit(); return $res; }
    catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); sh_log_error('txn rollback: ' . $e->getMessage()); throw $e; }
}

// ═════════════════════════════════════════════════════════════════════════════
//  OUTPUT ESCAPING (XSS)
// ═════════════════════════════════════════════════════════════════════════════
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ═════════════════════════════════════════════════════════════════════════════
//  CSRF
// ═════════════════════════════════════════════════════════════════════════════
function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">'; }
function verify_csrf(?string $token): bool {
    return !empty($_SESSION['_csrf']) && is_string($token) && hash_equals($_SESSION['_csrf'], $token);
}
/** Call at the top of every POST handler. Accepts token from form field or header. */
function require_csrf(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    $tok = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!verify_csrf($tok)) {
        audit_log('csrf_block', 'security', null, 'Rejected POST to ' . ($_SERVER['REQUEST_URI'] ?? '?'));
        http_response_code(419);
        die('<!doctype html><meta charset="utf-8"><div style="font-family:system-ui;max-width:480px;'
          . 'margin:15vh auto;text-align:center;color:#334155"><div style="font-size:42px">&#128274;</div>'
          . '<h1 style="font-size:19px;color:#0f172a">Session expired</h1>'
          . '<p style="color:#64748b">Your security token was invalid or expired. Please go back and try again.</p>'
          . '<a href="javascript:history.back()" style="color:#7c3aed;font-weight:600">&larr; Go back</a></div>');
    }
}

// ═════════════════════════════════════════════════════════════════════════════
//  RBAC  — roles: super_admin > admin > hr > recruiter > interviewer
// ═════════════════════════════════════════════════════════════════════════════
const SH_ROLES = ['super_admin' => 100, 'admin' => 80, 'hr' => 60, 'recruiter' => 40, 'interviewer' => 20];

function requireLogin(): void {
    if (empty($_SESSION['user_id'])) { redirect('index.php'); }
}
function currentUser(): array {
    return [
        'id'    => $_SESSION['user_id']    ?? 0,
        'name'  => $_SESSION['user_name']  ?? 'Guest',
        'email' => $_SESSION['user_email'] ?? '',
        'role'  => $_SESSION['user_role']  ?? 'recruiter',
    ];
}
function isLoggedIn(): bool { return !empty($_SESSION['user_id']); }

/** hasRole('hr') → true if current user is hr-or-higher; array → exact membership. */
function hasRole(string|array $min): bool {
    $role = currentUser()['role'];
    if (is_array($min)) return in_array($role, $min, true);
    $have = SH_ROLES[$role] ?? 0;
    $need = SH_ROLES[$min]  ?? 999;
    return $have >= $need;
}
function requireRole(string|array $min): void {
    requireLogin();
    if (!hasRole($min)) {
        audit_log('rbac_block', 'security', null, 'role=' . currentUser()['role'] . ' needed=' .
            (is_array($min) ? implode('|', $min) : $min));
        http_response_code(403);
        die('<!doctype html><meta charset="utf-8"><div style="font-family:system-ui;max-width:480px;'
          . 'margin:15vh auto;text-align:center;color:#334155"><div style="font-size:42px">&#9940;</div>'
          . '<h1 style="font-size:19px;color:#0f172a">Access denied</h1>'
          . '<p style="color:#64748b">You don\'t have permission to view this page.</p>'
          . '<a href="dashboard.php" style="color:#7c3aed;font-weight:600">&larr; Back to dashboard</a></div>');
    }
}

// ── Candidate realm ──────────────────────────────────────────────────────────
function requireCandidateLogin(): void { if (empty($_SESSION['candidate_id'])) { redirect('candidate_login.php'); } }
function currentCandidate(): array {
    return [
        'id'    => $_SESSION['candidate_id']    ?? 0,
        'name'  => $_SESSION['candidate_name']  ?? 'Guest',
        'email' => $_SESSION['candidate_email'] ?? '',
    ];
}
function isCandidateLoggedIn(): bool { return !empty($_SESSION['candidate_id']); }

/** Safe internal redirect (prevents open-redirect / header injection). */
function redirect(string $to): void {
    if (preg_match('#^https?://#i', $to) || str_contains($to, "\r") || str_contains($to, "\n")) {
        $to = 'index.php';
    }
    if (!headers_sent()) header('Location: ' . $to);
    else echo '<script>location.href=' . json_encode($to) . '</script>';
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
//  FLASH
// ═════════════════════════════════════════════════════════════════════════════
function setFlash(string $type, string $msg): void { $_SESSION['flash'] = ['type' => $type, 'msg' => $msg]; }
function getFlash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }

// ═════════════════════════════════════════════════════════════════════════════
//  VALIDATION
// ═════════════════════════════════════════════════════════════════════════════
function v_email(string $s): bool { return (bool)filter_var(trim($s), FILTER_VALIDATE_EMAIL); }
function v_phone(string $s): bool { return $s === '' || (bool)preg_match('/^[+\d][\d\s\-().]{6,19}$/', $s); }
function v_len(string $s, int $min, int $max): bool { $n = mb_strlen(trim($s)); return $n >= $min && $n <= $max; }
function v_url(string $s): bool { return $s === '' || (bool)filter_var($s, FILTER_VALIDATE_URL); }
function v_int($s, int $min, int $max): bool { return is_numeric($s) && (int)$s >= $min && (int)$s <= $max; }

/** Strong-password policy: ≥8 chars, upper, lower, digit. Returns error string or ''. */
function password_policy_error(string $pw): string {
    if (mb_strlen($pw) < 8)          return 'Password must be at least 8 characters.';
    if (!preg_match('/[A-Z]/', $pw))  return 'Password must include an uppercase letter.';
    if (!preg_match('/[a-z]/', $pw))  return 'Password must include a lowercase letter.';
    if (!preg_match('/\d/',   $pw))   return 'Password must include a number.';
    return '';
}

// ═════════════════════════════════════════════════════════════════════════════
//  BRUTE-FORCE PROTECTION
// ═════════════════════════════════════════════════════════════════════════════
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

function record_login_attempt(string $identifier, bool $success, string $realm = 'hr'): void {
    try {
        dbExecute("INSERT INTO login_attempts (identifier, ip, realm, success) VALUES (?,?,?,?)",
            'sssi', mb_substr($identifier, 0, 150), client_ip(), $realm, $success ? 1 : 0);
    } catch (Throwable $e) {}
}
/** Locked out after 5 failed attempts (per email+IP) within 15 minutes. */
function is_locked_out(string $identifier, string $realm = 'hr'): bool {
    return failed_attempt_count($identifier, $realm) >= 5;
}
function failed_attempt_count(string $identifier, string $realm = 'hr'): int {
    try {
        $row = dbFetchOne(
            "SELECT COUNT(*) AS n FROM login_attempts
             WHERE identifier=? AND ip=? AND realm=? AND success=0
               AND attempted_at > (NOW() - INTERVAL '15 minutes')",
            'sss', $identifier, client_ip(), $realm);
        return (int)($row['n'] ?? 0);
    } catch (Throwable $e) { return 0; }
}

// ═════════════════════════════════════════════════════════════════════════════
//  AUDIT LOG
// ═════════════════════════════════════════════════════════════════════════════
function audit_log(string $action, ?string $entity = null, ?int $entityId = null, ?string $detail = null): void {
    try {
        $type = 'anon'; $id = 0; $email = null;
        if (!empty($_SESSION['user_id']))        { $type = 'user';      $id = (int)$_SESSION['user_id'];      $email = $_SESSION['user_email'] ?? null; }
        elseif (!empty($_SESSION['candidate_id'])){ $type = 'candidate'; $id = (int)$_SESSION['candidate_id']; $email = $_SESSION['candidate_email'] ?? null; }
        dbExecute(
            "INSERT INTO audit_logs (actor_type,actor_id,actor_email,action,entity,entity_id,detail,ip,user_agent)
             VALUES (?,?,?,?,?,?,?,?,?)",
            'sisssisss',
            $type, $id, $email, $action, $entity, $entityId ?? 0,
            $detail !== null ? mb_substr($detail, 0, 500) : null,
            client_ip(), mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
        );
    } catch (Throwable $e) { /* auditing must never break the request */ }
}

// ═════════════════════════════════════════════════════════════════════════════
//  SECURE FILE UPLOAD
// ═════════════════════════════════════════════════════════════════════════════
const SH_RESUME_MIME = [
    'application/pdf'                                                          => 'pdf',
    'application/msword'                                                       => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'  => 'docx',
    'text/plain'                                                              => 'txt',
];
/**
 * Validate + store an uploaded resume. Returns ['ok'=>bool,'path'=>relative,'error'=>str].
 * MIME sniffed with finfo (not the browser type); extension whitelisted; ≤3MB;
 * random non-executable filename under uploads/resumes.
 */
function store_resume_upload(array $file, string $subdir = 'resumes'): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'path' => null, 'error' => 'No file uploaded.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'path' => null, 'error' => 'Upload failed. Please retry.'];
    }
    if ($file['size'] > 3 * 1024 * 1024) {
        return ['ok' => false, 'path' => null, 'error' => 'File too large (max 3 MB).'];
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $ext = SH_RESUME_MIME[$mime] ?? null;
    $userExt = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if ($ext === null && $mime === 'application/zip' && $userExt === 'docx') $ext = 'docx';  // finfo quirk
    if ($ext === null) {
        return ['ok' => false, 'path' => null, 'error' => 'Unsupported file type. Upload PDF, DOCX, DOC or TXT.'];
    }
    $dir = rtrim(SH_UPLOAD_DIR, '/') . '/' . $subdir;
    @is_dir($dir) || @mkdir($dir, 0775, true);
    $name = bin2hex(random_bytes(16)) . '.' . $ext;   // random, non-executable
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'path' => null, 'error' => 'Could not save the file.'];
    }
    @chmod($dest, 0644);
    return ['ok' => true, 'path' => 'uploads/' . $subdir . '/' . $name, 'error' => ''];
}

// ═════════════════════════════════════════════════════════════════════════════
//  HELPERS (unchanged public behaviour)
// ═════════════════════════════════════════════════════════════════════════════
function generateToken(int $len = 32): string { return bin2hex(random_bytes($len)); }

function getScoreColor(int $score): string {
    if ($score >= 80) return 'green';
    if ($score >= 60) return 'amber';
    if ($score >= 40) return 'blue';
    return 'rose';
}
function getScoreLabel(int $score): string {
    if ($score >= 80) return 'Excellent';
    if ($score >= 60) return 'Good';
    if ($score >= 40) return 'Average';
    return 'Below Average';
}
function calculateAIScore(string $skills, string $position, string $resume = ''): int {
    $text  = strtolower($skills . ' ' . $resume);
    $score = 40;
    $tech  = ['python','java','javascript','react','node','php','laravel','vue','angular',
              'docker','kubernetes','aws','azure','gcp','mysql','mongodb','postgresql',
              'redis','terraform','ci/cd','spring','django','flask','tensorflow','pytorch','sql'];
    $soft  = ['project','team','lead','experience','years','award','portfolio','certified','agile','scrum','mentor'];
    foreach ($tech as $kw) if (str_contains($text, $kw)) $score += 3;
    foreach ($soft as $kw) if (str_contains($text, $kw)) $score += 2;
    return min(100, $score);
}

/** Notify all HR/admin/recruiter users via a single INSERT…SELECT (no N+1). */
function addNotification(string $type, string $message, ?int $candidateId = null): void {
    try {
        dbExecute(
            "INSERT INTO notifications (user_id, candidate_id, type, message, is_read)
             SELECT id, ?, ?, ?, 0
             FROM users
             WHERE role IN ('super_admin','admin','hr','recruiter')
               AND (is_active = 1 OR is_active IS NULL)",
            'iss', $candidateId ?? 0, $type, $message
        );
    } catch (Throwable $e) {}
}
/** Notify a single candidate (new in v7). */
function notifyCandidate(int $candidateId, string $type, string $message): void {
    try {
        dbExecute("INSERT INTO notifications (user_id, candidate_id, type, message, is_read) VALUES (NULL,?,?,?,0)",
            'iss', $candidateId, $type, $message);
    } catch (Throwable $e) {}
}
