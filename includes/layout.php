<?php
require_once __DIR__ . '/config.php';

function renderHead(string $pageTitle = 'SmartHire'): void { ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?> — SmartHire</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="assets/css/main.css">
  <link rel="stylesheet" href="assets/css/v7.css">
  <meta name="csrf-token" content="<?= csrf_token() ?>">
</head>
<body>
<?php }

function renderSidebar(string $active = ''): void {
    $user  = currentUser();
    $flash = getFlash();
    // Count unread HR-user notifications only (not candidate notifications which have user_id=NULL).
    // The idx_notif_user_read index makes this a fast index-only scan.
    try {
        $notifCount = dbFetchOne(
            "SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND is_read = 0",
            'i', $user['id']
        )['n'] ?? 0;
    } catch (Throwable $e) {
        $notifCount = 0;
    }
    $nav = [
        ['dashboard',        'fa-gauge-high',           'Dashboard',        'dashboard.php'],
        ['candidates',       'fa-users',                'Candidates',       'candidates.php'],
        ['jobs',             'fa-briefcase',            'Jobs',             'jobs.php'],
        ['applications',     'fa-people-arrows',        'Applicants',       'applications.php'],
        ['interviews',       'fa-calendar-check',       'Interviews',       'interviews.php'],
        ['online_tests',     'fa-laptop-code',          'Online Tests',     'online_tests.php'],
        ['questions',        'fa-circle-question',      'Question Bank',    'questions.php'],
        ['results',          'fa-chart-bar',            'Results',          'results.php'],
        ['analytics',        'fa-chart-line',           'Analytics',        'analytics.php'],
        ['recruitment_analytics','fa-chart-pie',        'Hiring Analytics', 'recruitment_analytics.php'],
        ['resume_scanner',   'fa-file-magnifying-glass','ATS Scanner',      'resume_scanner.php'],
        ['candidate_resumes','fa-file-chart-column',    'Resume Scores',    'candidate_resumes.php'],
        ['analyze',          'fa-robot',                'AI Analyzer',      'analyze.php'],
    ];
?>
<div class="layout">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
      <div class="logo-icon"><i class="fa-solid fa-bolt"></i></div>
      <div class="logo-text">
        <span class="brand">SmartHire</span>
        <span class="tagline">v7.0</span>
      </div>
    </div>
    <nav class="sidebar-nav">
      <?php foreach ($nav as [$key, $icon, $label, $href]): ?>
      <a href="<?= $href ?>" class="nav-item <?= $active === $key ? 'active' : '' ?>">
        <i class="fa-solid <?= $icon ?>"></i><span><?= $label ?></span>
      </a>
      <?php endforeach; ?>
      <div style="height:1px;background:var(--border);margin:10px 6px"></div>
      <a href="score_interview.php" class="nav-item <?= $active==='score'?'active':'' ?>" style="font-size:12.5px;color:var(--text-muted)">
        <i class="fa-solid fa-star"></i><span>Score Interview</span>
      </a>
    </nav>
    <div class="sidebar-footer">
      <div class="user-info">
        <div class="avatar"><?= strtoupper(substr($user['name'],0,1)) ?></div>
        <div>
          <p class="user-name"><?= htmlspecialchars($user['name']) ?></p>
          <p class="user-role"><?= ucfirst($user['role']) ?></p>
        </div>
      </div>
      <a href="logout.php" class="logout-btn" title="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
  </aside>

  <div class="main-wrapper">
    <header class="topbar">
      <button class="sidebar-toggle" onclick="toggleSidebar()" title="Toggle menu">
        <i class="fa-solid fa-bars"></i>
      </button>
      <div class="topbar-right">
        <div class="topbar-search">
          <i class="fa-solid fa-search"></i>
          <input type="text" placeholder="Quick search…" id="globalSearch">
        </div>

        <!-- ── Notification Bell with Dropdown ── -->
        <div class="notif-wrapper" id="notifWrapper">
          <div class="notif-bell" id="notifBell" onclick="toggleNotifDropdown(event)" title="Notifications">
            <i class="fa-solid fa-bell"></i>
            <?php if ($notifCount > 0): ?>
            <span class="badge" id="notifBadge"><?= $notifCount ?></span>
            <?php else: ?>
            <span class="badge" id="notifBadge" style="display:none">0</span>
            <?php endif; ?>
          </div>

          <!-- Dropdown Panel -->
          <div class="notif-dropdown" id="notifDropdown">
            <div class="notif-dropdown-header">
              <h4><i class="fa-solid fa-bell" style="color:var(--accent)"></i> Notifications</h4>
              <button class="notif-mark-all" onclick="markAllRead()">Mark all read</button>
            </div>
            <div class="notif-list" id="notifList">
              <div class="notif-empty">
                <i class="fa-solid fa-spinner fa-spin"></i>
                <p>Loading…</p>
              </div>
            </div>
            <div class="notif-footer">
              <a href="notifications.php">View all notifications →</a>
            </div>
          </div>
        </div>

        <a href="profile.php" class="topbar-user" style="text-decoration:none;color:inherit" title="My profile">
          <div class="avatar sm"><?= strtoupper(substr($user['name'],0,1)) ?></div>
          <span><?= htmlspecialchars(explode(' ',$user['name'])[0]) ?></span>
        </a>
      </div>
    </header>

    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?>" id="flash-msg">
      <i class="fa-solid <?= $flash['type']==='success'?'fa-check-circle':'fa-triangle-exclamation' ?>"></i>
      <?= htmlspecialchars($flash['msg']) ?>
      <button onclick="this.parentElement.remove()" class="close-alert">×</button>
    </div>
    <?php endif; ?>

    <main class="page-content">
<?php } ?>

<?php
function renderFooter(): void { ?>
    </main>
  </div>
</div>
<script src="assets/js/main.js"></script>
<script src="assets/js/v7.js"></script>
</body>
</html>
<?php } ?>
