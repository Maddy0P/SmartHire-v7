<?php
// ─────────────────────────────────────────────────────────
//  SmartHire — notifications.php
//  Full notifications management page
// ─────────────────────────────────────────────────────────
require_once 'includes/layout.php';
requireLogin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

$uid = (int)currentUser()['id'];

// Mark all as read — scoped to this HR user only (not candidate notifications)
if (isset($_GET['mark_all'])) {
    require_csrf(); // protect the GET-triggered action with CSRF from URL token
    dbExecute("UPDATE notifications SET is_read = 1 WHERE user_id = ?", 'i', $uid);
    setFlash('success', 'All notifications marked as read.');
    header('Location: notifications.php'); exit;
}

// Delete single — scoped to this HR user so no cross-user delete is possible
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $nid = (int)($_POST['notif_id'] ?? 0);
    if ($nid > 0) {
        dbExecute("DELETE FROM notifications WHERE id = ? AND user_id = ?", 'ii', $nid, $uid);
    }
    setFlash('success', 'Notification removed.');
    header('Location: notifications.php'); exit;
}

// Safely fetch HR-user notifications only (candidate notifications have user_id=NULL)
$notifs = [];
$unread = 0;
try {
    $notifs = dbFetchAll(
        "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50",
        'i', $uid
    );
    $unread = count(array_filter($notifs, fn($n) => !$n['is_read']));
    // Mark all fetched items as read in one UPDATE
    if ($unread > 0) {
        dbExecute("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0", 'i', $uid);
    }
} catch (Throwable $e) {}

$typeMap = [
    'test_submitted'      => ['fa-paper-plane',       'violet', 'Test Submitted'],
    'interview_scheduled' => ['fa-calendar-check',    'blue',   'Interview Scheduled'],
    'result_added'        => ['fa-chart-bar',          'green',  'Result Added'],
    'ats_scanned'         => ['fa-file-magnifying-glass','amber','ATS Scanned'],
    'hired'               => ['fa-handshake',          'green',  'Hired'],
    'rejected'            => ['fa-user-xmark',         'rose',   'Rejected'],
];

renderHead('Notifications');
renderSidebar('');
?>

<div class="page-header">
  <div class="page-header-left">
    <div class="breadcrumb"><a href="dashboard.php">Home</a> <i class="fa-solid fa-chevron-right"></i> Notifications</div>
    <h1 class="page-title">Notifications</h1>
    <p class="page-subtitle"><?= count($notifs) ?> total — <?= $unread ?> unread</p>
  </div>
  <?php if (!empty($notifs)): ?>
  <a href="?mark_all=1" class="btn btn-secondary">
    <i class="fa-solid fa-check-double"></i> Mark All Read
  </a>
  <?php endif; ?>
</div>

<div class="card">
  <?php if (empty($notifs)): ?>
  <div style="text-align:center;padding:80px 20px;color:var(--text-muted)">
    <i class="fa-solid fa-bell-slash" style="font-size:48px;display:block;margin-bottom:16px;opacity:.4"></i>
    <h3 style="font-weight:700;margin-bottom:8px;color:var(--text-secondary)">No notifications yet</h3>
    <p style="font-size:13px">Notifications will appear here when candidates submit tests, interviews are scheduled, or results are added.</p>
  </div>
  <?php else: ?>
  <?php foreach ($notifs as $n):
    [$icon,$color,$typeLabel] = $typeMap[$n['type']] ?? ['fa-bell','blue','Notification'];
    $isUnread = !$n['is_read'];
  ?>
  <div class="notif-page-item <?= $isUnread ? 'unread' : '' ?>">
    <div style="width:42px;height:42px;border-radius:50%;background:var(--<?= $color ?>-bg,rgba(59,130,246,.1));
                display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:16px;color:var(--<?= $color ?>)">
      <i class="fa-solid <?= $icon ?>"></i>
    </div>
    <div style="flex:1;min-width:0">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px">
        <span class="badge badge-<?= $color ?>"><?= htmlspecialchars($typeLabel) ?></span>
        <?php if ($isUnread): ?>
        <span style="width:7px;height:7px;border-radius:50%;background:var(--accent);display:inline-block"></span>
        <?php endif; ?>
      </div>
      <div style="font-size:13.5px;color:var(--text-primary);margin-bottom:4px"><?= htmlspecialchars($n['message']) ?></div>
      <div style="font-size:12px;color:var(--text-muted)">
        <i class="fa-regular fa-clock"></i>
        <?= date('d M Y, g:i A', strtotime($n['created_at'])) ?>
      </div>
    </div>
    <form method="POST" style="flex-shrink:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="notif_id" value="<?= $n['id'] ?>">
      <button type="submit" class="btn btn-secondary btn-sm btn-icon"
              data-confirm="Remove this notification?"
              title="Delete">
        <i class="fa-solid fa-trash"></i>
      </button>
    </form>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php renderFooter(); ?>
