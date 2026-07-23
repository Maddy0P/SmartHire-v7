<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ui_helpers.php';

/**
 * SmartHire dual shell.
 * Legacy pages: renderHead('Title')            -> v7 shell, byte-identical markup/assets.
 * Migrated pages: renderHead('Title', true)    -> v8 enterprise shell (Design Bible v1.2).
 * A page is fully old or fully new — never hybrid (Blueprint rule).
 */

$GLOBALS['__SH_V8'] = false;

function renderHead(string $pageTitle = 'SmartHire', bool $v8 = false): void {
    $GLOBALS['__SH_V8'] = $v8;
    if (!$v8) { sh_legacy_head($pageTitle); return; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?> — SmartHire</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="assets/css/tokens.css">
  <link rel="stylesheet" href="assets/css/utilities.css">
  <link rel="stylesheet" href="assets/css/layout.css">
  <link rel="stylesheet" href="assets/css/components.css">
  <link rel="stylesheet" href="assets/css/cards.css">
  <link rel="stylesheet" href="assets/css/tables.css">
  <link rel="stylesheet" href="assets/css/forms.css">
  <link rel="stylesheet" href="assets/css/modals.css">
  <link rel="stylesheet" href="assets/css/animations.css">
  <meta name="csrf-token" content="<?= csrf_token() ?>">
</head>
<body class="sh-v8">
<a class="sh-skip-link" href="#main-content">Skip to main content</a>
<?php }

function renderSidebar(string $active = ''): void {
    if (!$GLOBALS['__SH_V8']) { sh_legacy_sidebar($active); return; }
    $user  = currentUser();
    $flash = getFlash();
    try {
        $notifCount = dbFetchOne(
            "SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND is_read = 0",
            'i', $user['id']
        )['n'] ?? 0;
    } catch (Throwable $e) {
        $notifCount = 0;
    }
    // Bible P13 grouped IA. Keys unchanged — every page keeps its existing $active value.
    $groups = [
        'Recruit' => [
            ['dashboard',    'fa-gauge-high',     'Dashboard',    'dashboard.php'],
            ['candidates',   'fa-users',          'Candidates',   'candidates.php'],
            ['jobs',         'fa-briefcase',      'Jobs',         'jobs.php'],
            ['applications', 'fa-people-arrows',  'Applicants',   'applications.php'],
            ['offers',       'fa-file-signature', 'Offers',       'offers.php'],
        ],
        'Assess' => [
            ['assessment_center', 'fa-layer-group', 'Assessment Center', 'assessment_center.php'],
            ['interviews',   'fa-calendar-check', 'Interviews',   'interviews.php'],
            ['score',        'fa-star',           'Score Interview','score_interview.php'],
            ['online_tests', 'fa-laptop-code',    'Online Tests', 'online_tests.php'],
            ['questions',    'fa-circle-question','Question Bank','questions.php'],
            ['results',      'fa-chart-bar',      'Results',      'results.php'],
        ],
        'Intelligence' => [
            ['analytics',            'fa-chart-line',            'Analytics',       'analytics.php'],
            ['recruitment_analytics','fa-chart-pie',             'Hiring Analytics','recruitment_analytics.php'],
            ['resume_scanner',       'fa-file-magnifying-glass', 'ATS Scanner',     'resume_scanner.php'],
            ['candidate_resumes',    'fa-file-lines',            'Resume Scores',   'candidate_resumes.php'],
            ['analyze',              'fa-robot',                 'AI Analyzer',     'analyze.php'],
        ],
    ];
    $activeLabel = 'Dashboard'; $activeHref = 'dashboard.php';
    foreach ($groups as $items) foreach ($items as [$k,, $label, $href]) {
        if ($k === $active) { $activeLabel = $label; $activeHref = $href; }
    }
?>
<div class="sh-layout">
  <aside class="sh-sidebar" id="sidebar" aria-label="Primary">
    <div class="sh-brand">
      <div class="sh-brand-mark" aria-hidden="true"><i class="fa-solid fa-bolt"></i></div>
      <span class="sh-brand-name">SmartHire</span>
    </div>
    <nav class="sh-nav" aria-label="Main navigation">
      <?php foreach ($groups as $groupLabel => $items): ?>
      <div class="sh-nav-group">
        <p class="sh-nav-group-label" id="navg-<?= strtolower($groupLabel) ?>"><?= $groupLabel ?></p>
        <?php foreach ($items as [$key, $icon, $label, $href]): ?>
        <a href="<?= $href ?>" class="sh-nav-item" <?= $active === $key ? 'aria-current="page"' : '' ?>>
          <i class="fa-solid <?= $icon ?>" aria-hidden="true"></i><span><?= $label ?></span>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </nav>
    <div class="sh-sidebar-footer">
      <div class="sh-avatar" aria-hidden="true"><?= strtoupper(substr($user['name'],0,1)) ?></div>
      <div class="sh-user-meta sh-flex-1 sh-truncate">
        <div class="sh-truncate sh-user-name"><?= htmlspecialchars($user['name']) ?></div>
        <div class="sh-fs-xs sh-text-muted"><?= ucfirst($user['role']) ?></div>
      </div>
    </div>
  </aside>
  <div class="sh-scrim" id="shScrim" aria-hidden="true"></div>

  <div class="sh-main">
    <header class="sh-topbar">
      <button class="sh-iconbtn" id="shSidebarToggle" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="sidebar">
        <i class="fa-solid fa-bars" aria-hidden="true"></i>
      </button>
      <nav class="sh-breadcrumb" aria-label="Breadcrumb">
        <a href="dashboard.php">Home</a>
        <?php if ($active !== 'dashboard'): ?>
        <i class="fa-solid fa-chevron-right sh-fs-xs" aria-hidden="true"></i>
        <a href="<?= $activeHref ?>" aria-current="page"><?= $activeLabel ?></a>
        <?php endif; ?>
      </nav>

      <div class="sh-topbar-search">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <label class="sh-sr-only" for="globalSearch">Search SmartHire</label>
        <input type="search" id="globalSearch" placeholder="Search…" autocomplete="off">
        <span class="sh-topbar-kbd" aria-hidden="true">/</span>
      </div>

      <div class="notif-wrapper sh-rel" id="notifWrapper">
        <button class="sh-iconbtn" id="notifBell" onclick="toggleNotifDropdown(event)"
                aria-label="Notifications<?= $notifCount > 0 ? ", $notifCount unread" : '' ?>"
                aria-haspopup="true" aria-controls="notifDropdown">
          <i class="fa-solid fa-bell" aria-hidden="true"></i>
          <span class="sh-badge-count badge" id="notifBadge" <?= $notifCount > 0 ? '' : 'style="display:none"' ?>><?= $notifCount ?></span>
        </button>
        <div class="notif-dropdown sh-menu sh-notif-panel" id="notifDropdown" role="region" aria-label="Notifications panel">
          <div class="sh-notif-head">
            <strong>Notifications</strong>
            <button class="sh-btn sh-btn-ghost sh-btn-sm notif-mark-all" onclick="markAllRead()">Mark all read</button>
          </div>
          <div class="notif-list sh-notif-list" id="notifList" aria-live="polite">
            <div class="notif-empty sh-empty sh-empty-pad">
              <p class="sh-skeleton sh-skel-200">Loading notifications…</p>
            </div>
          </div>
          <div class="sh-notif-foot">
            <a href="notifications.php">View all notifications →</a>
          </div>
        </div>
      </div>

      <div class="sh-rel">
        <button class="sh-iconbtn sh-userbtn" data-sh-menu="shUserMenu" aria-label="User menu" aria-haspopup="true" aria-expanded="false" aria-controls="shUserMenu">
          <span class="sh-avatar sh-avatar-sm" aria-hidden="true"><?= strtoupper(substr($user['name'],0,1)) ?></span>
          <i class="fa-solid fa-chevron-down sh-fs-xs" aria-hidden="true"></i>
        </button>
        <div class="sh-menu" id="shUserMenu" role="menu" aria-label="User menu">
          <div class="sh-menu-head">
            <div class="sh-menu-name"><?= htmlspecialchars($user['name']) ?></div>
            <div class="sh-fs-xs sh-text-muted"><?= ucfirst($user['role']) ?></div>
          </div>
          <div class="sh-menu-sep" role="separator"></div>
          <a class="sh-menu-item" role="menuitem" href="profile.php"><i class="fa-regular fa-user" aria-hidden="true"></i>My profile</a>
          <a class="sh-menu-item" role="menuitem" href="notifications.php"><i class="fa-regular fa-bell" aria-hidden="true"></i>Notifications</a>
          <div class="sh-menu-sep" role="separator"></div>
          <a class="sh-menu-item sh-danger-text" role="menuitem" href="logout.php"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>Log out</a>
        </div>
      </div>
    </header>

    <?php if ($flash): ?>
    <div class="sh-flash sh-flash-<?= $flash['type'] ?>" id="flash-msg" role="status">
      <i class="fa-solid <?= $flash['type']==='success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>" aria-hidden="true"></i>
      <?= htmlspecialchars($flash['msg']) ?>
      <button onclick="this.parentElement.remove()" aria-label="Dismiss message">×</button>
    </div>
    <?php endif; ?>

    <main class="sh-page" id="main-content">
<?php }

function renderFooter(): void {
    if (!$GLOBALS['__SH_V8']) { sh_legacy_footer(); return; }
?>
    </main>
  </div>
</div>
<script src="assets/js/main.min.js"></script>
<script src="assets/js/shell.js"></script>
<?php if (SH_DEBUG): $__qs = sh_query_stats(); ?>
<!-- perf: <?= $__qs['count'] ?> queries, <?= $__qs['total_ms'] ?>ms db time, <?= round((microtime(true)-SH_REQUEST_START)*1000,2) ?>ms total php time -->
<?php endif; ?>
</body>
</html>
<?php } ?>

<?php
// ─── Legacy v7 shell (byte-preserved bodies) — consumed by all unmigrated pages ───
function sh_legacy_head(string $pageTitle = 'SmartHire'): void { ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?> — SmartHire</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="assets/css/main.min.css">
  <link rel="stylesheet" href="assets/css/v7.min.css">
  <meta name="csrf-token" content="<?= csrf_token() ?>">
</head>
<body>
<?php }

function sh_legacy_sidebar(string $active = ''): void {
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
        ['offers',           'fa-file-signature',       'Offers',           'offers.php'],
        ['assessment_center','fa-layer-group',          'Assessment Center','assessment_center.php'],
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
function sh_legacy_footer(): void { ?>
    </main>
  </div>
</div>
<script src="assets/js/main.min.js"></script>
<script src="assets/js/v7.min.js"></script>
<?php if (SH_DEBUG): $__qs = sh_query_stats(); ?>
<!-- perf: <?= $__qs['count'] ?> queries, <?= $__qs['total_ms'] ?>ms db time, <?= round((microtime(true)-SH_REQUEST_START)*1000,2) ?>ms total php time -->
<?php endif; ?>
</body>
</html>
<?php } ?>
