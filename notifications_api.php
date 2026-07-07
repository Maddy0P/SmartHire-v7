<?php
// ─────────────────────────────────────────────────────────────────────────────
//  SmartHire — notifications_api.php
//  JSON API for the HR notification bell dropdown.
//  Only serves notifications where user_id matches the logged-in HR user.
//  Candidate notifications (user_id=NULL) are intentionally excluded here.
// ─────────────────────────────────────────────────────────────────────────────
require_once 'includes/config.php';
requireLogin();
header('Content-Type: application/json');
header('Cache-Control: no-store');

$action = $_GET['action'] ?? 'list';
$uid    = (int)currentUser()['id'];

// State-changing actions require a valid CSRF token (sent as X-CSRF-Token header).
if (in_array($action, ['mark_read', 'mark_all'], true)) {
    if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

// Ensure notifications table exists (graceful fallback during fresh installs)
try {
    getDB()->query("SELECT 1 FROM notifications LIMIT 1");
} catch (Throwable $e) {
    echo json_encode(['notifications' => [], 'unread_count' => 0, 'count' => 0]);
    exit;
}

switch ($action) {
    case 'list':
        // Only fetch this HR user's notifications (not candidate notifications).
        // idx_notif_user_read covers this scan.
        $notifs = dbFetchAll(
            "SELECT * FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC LIMIT 20",
            'i', $uid
        );
        $unread = dbFetchOne(
            "SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND is_read = 0",
            'i', $uid
        )['n'] ?? 0;
        echo json_encode(['notifications' => $notifs, 'unread_count' => (int)$unread]);
        break;

    case 'count':
        $count = dbFetchOne(
            "SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND is_read = 0",
            'i', $uid
        )['n'] ?? 0;
        echo json_encode(['count' => (int)$count]);
        break;

    case 'mark_read':
        $id = (int)($_GET['id'] ?? 0);
        if ($id > 0) {
            // Scope the update to this user to prevent cross-user mark attacks.
            dbExecute(
                "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?",
                'ii', $id, $uid
            );
        }
        echo json_encode(['ok' => true]);
        break;

    case 'mark_all':
        dbExecute(
            "UPDATE notifications SET is_read = 1 WHERE user_id = ?",
            'i', $uid
        );
        echo json_encode(['ok' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}
