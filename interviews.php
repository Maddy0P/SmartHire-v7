<?php
require_once 'includes/layout.php';
require_once 'includes/recruitment.php';
require_once 'includes/mailer.php';
require_once 'modules/interview/bootstrap.php';   // Module 9 — Interview Management
requireLogin();
requireRole('recruiter');           // recruiter-or-higher may schedule
if ($_SERVER['REQUEST_METHOD']==='POST') require_csrf();

// ── Handle form submissions (Module 9 — delegated to InterviewService) ───────
// The service owns validation, double-booking conflict detection, persistence,
// and the side-effects (notification, stage advance, invite email, audit). The
// page only maps the result to a flash message + redirect. Valid, non-conflicting
// input behaves exactly as before; invalid input / double-bookings are now
// rejected with a message instead of being written (handbook 6A-005).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fa    = $_POST['form_action'] ?? '';
    $ivSvc = \SmartHire\Interview\InterviewService::production();

    if ($fa === 'create') {
        $r = $ivSvc->schedule($_POST);
        if ($r['ok'])                    setFlash('success', 'Interview scheduled!');
        elseif (!empty($r['conflict']))  setFlash('error', 'That interviewer already has an interview at that date and time.');
        elseif (!empty($r['errors']))    setFlash('error', reset($r['errors']));
        else                             setFlash('error', 'Failed to schedule.');
        header('Location: interviews.php'); exit;

    } elseif ($fa === 'update') {
        $r = $ivSvc->reschedule((int)($_POST['interview_id'] ?? 0), $_POST);
        if ($r['ok'])                    setFlash('success', 'Interview updated!');
        elseif (!empty($r['conflict']))  setFlash('error', 'That interviewer already has an interview at that date and time.');
        elseif (!empty($r['errors']))    setFlash('error', reset($r['errors']));
        else                             setFlash('error', 'Update failed.');
        header('Location: interviews.php'); exit;

    } elseif ($fa === 'delete') {
        $ivSvc->remove((int)($_POST['interview_id'] ?? 0));
        setFlash('success', 'Interview removed.');
        header('Location: interviews.php'); exit;
    }
}

// ── Fetch data (Module 9 Phase 2 — read-path via InterviewService) ───────────
// Board list, the full set (KPIs/calendar/upcoming) and all status tallies now
// come from the service/repository. Status counts are a SINGLE grouped query
// (handbook 6A-005 / 6A-022) instead of five COUNT(*) round-trips. The candidate
// dropdown stays a candidate-domain read (future CandidateRepository, 6A-003).
$ivSvc  = \SmartHire\Interview\InterviewService::production();
$filter = $_GET['status'] ?? '';
$interviews = $ivSvc->listing($filter !== '' ? $filter : null);
$candidates = dbFetchAll("SELECT id, name, position FROM candidates ORDER BY name");

$sc     = $ivSvc->statusCounts();
$counts = [
    'all'       => $sc['all'],
    'scheduled' => $sc['scheduled'],
    'completed' => $sc['completed'],
    'cancelled' => $sc['cancelled'],
];

// Full set for KPIs / calendar / upcoming. With no status filter the board list
// already holds everything — reuse it (zero extra query); otherwise fetch all.
$allIvs = $filter !== '' ? $ivSvc->listing(null) : $interviews;

// No-show tally comes from the same single grouped query above.
$noShowCount = $sc['no-show'];

$today     = date('Y-m-d');
$weekEnd   = date('Y-m-d', strtotime('+7 days'));
$kToday = $kWeek = 0;
$upcoming = [];
foreach ($allIvs as $iv) {
    if ($iv['status'] === 'scheduled' && $iv['scheduled_date'] >= $today) {
        if ($iv['scheduled_date'] === $today) $kToday++;
        if ($iv['scheduled_date'] <= $weekEnd) $kWeek++;
        $upcoming[] = $iv;
    }
}
usort($upcoming, fn($a, $b) => [$a['scheduled_date'], $a['scheduled_time']] <=> [$b['scheduled_date'], $b['scheduled_time']]);
$upcoming = array_slice($upcoming, 0, 5);

// Search + sort + pagination — presentation-layer over the verbatim query result
$q = trim($_GET['q'] ?? '');
$rows = $interviews;
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_values(array_filter($rows, fn($iv) =>
        str_contains(mb_strtolower($iv['candidate_name'] . ' ' . ($iv['interviewer'] ?? '') . ' ' . ($iv['position'] ?? '') . ' ' . $iv['type']), $needle)));
}
$sorts = [
    'date_desc'   => fn($a, $b) => [$b['scheduled_date'], $b['scheduled_time']] <=> [$a['scheduled_date'], $a['scheduled_time']],
    'date_asc'    => fn($a, $b) => [$a['scheduled_date'], $a['scheduled_time']] <=> [$b['scheduled_date'], $b['scheduled_time']],
    'candidate'   => fn($a, $b) => strcasecmp($a['candidate_name'], $b['candidate_name']),
    'interviewer' => fn($a, $b) => strcasecmp($a['interviewer'] ?? '', $b['interviewer'] ?? ''),
    'status'      => fn($a, $b) => strcmp($a['status'], $b['status']),
];
$fSort = isset($sorts[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'date_desc';
if ($fSort !== 'date_desc') usort($rows, $sorts[$fSort]); // default keeps original DB order

$per   = 15;
$total = count($rows);
$pages = max(1, (int)ceil($total / $per));
$page  = max(1, min($pages, (int)($_GET['page'] ?? 1)));
$rows  = array_slice($rows, ($page - 1) * $per, $per);

// Latest scorecard per interview on this page (one batched read-only query)
$resByIv = [];
if ($rows) {
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    foreach (dbFetchAll(
        "SELECT interview_id, overall_score, technical_score, communication, problem_solving, cultural_fit,
                recommendation, feedback, created_at
         FROM results WHERE interview_id IN ($ph) ORDER BY created_at DESC",
        str_repeat('i', count($ids)), ...$ids) as $r) {
        $resByIv[$r['interview_id']] ??= $r; // keep latest only
    }
}

// Calendar state
$view = ($_GET['view'] ?? '') === 'calendar' ? 'calendar' : 'list';
$cal  = in_array($_GET['cal'] ?? '', ['month', 'week', 'day', 'agenda'], true) ? $_GET['cal'] : 'month';
$anchorRaw = $_GET['d'] ?? $today;
$anchorTs  = strtotime($anchorRaw) ?: strtotime($today);
$anchor    = date('Y-m-d', $anchorTs);
$byDate = [];
foreach ($allIvs as $iv) $byDate[$iv['scheduled_date']][] = $iv;
foreach ($byDate as &$d) usort($d, fn($a, $b) => strcmp($a['scheduled_time'], $b['scheduled_time'])); unset($d);

$qs = fn(array $over = []) => 'interviews.php?' . http_build_query(array_filter(array_merge(
    ['status' => $filter, 'q' => $q, 'sort' => $fSort === 'date_desc' ? '' : $fSort,
     'view' => $view === 'list' ? '' : $view, 'cal' => $view === 'calendar' && $cal !== 'month' ? $cal : '',
     'd' => $view === 'calendar' && $anchor !== $today ? $anchor : ''], $over),
    fn($v) => $v !== '' && $v !== null));

$ivTone  = ['scheduled' => 'info', 'completed' => 'success', 'cancelled' => 'danger', 'no-show' => 'warning'];
$recText = ['strong_yes' => 'Strong yes', 'yes' => 'Yes', 'maybe' => 'Maybe', 'no' => 'No'];

// Slide-over payload for a row (shared by table + calendar)
$ivJson = function (array $iv) use ($resByIv, $recText): string {
    $r = $resByIv[$iv['id']] ?? null;
    return htmlspecialchars(json_encode([
        'id' => (int)$iv['id'], 'cid' => (int)$iv['candidate_id'],
        'candidate' => $iv['candidate_name'], 'position' => $iv['position'], 'email' => $iv['candidate_email'] ?? '',
        'interviewer' => $iv['interviewer'], 'type' => ucfirst($iv['type']),
        'mode' => $iv['mode'] === 'online' ? 'Online' : 'In-person',
        'date' => date('d M Y', strtotime($iv['scheduled_date'])), 'time' => date('g:i A', strtotime($iv['scheduled_time'])),
        'status' => $iv['status'], 'notes' => $iv['notes'],
        'rd' => $iv['scheduled_date'], 'rt' => $iv['scheduled_time'], 'rty' => $iv['type'], 'rmo' => $iv['mode'],
        'created' => date('d M Y', strtotime($iv['created_at'])),
        'score' => $r ? (int)$r['overall_score'] : null,
        'scores' => $r ? ['Technical' => (int)$r['technical_score'], 'Communication' => (int)$r['communication'],
                          'Problem solving' => (int)$r['problem_solving'], 'Cultural fit' => (int)$r['cultural_fit']] : null,
        'rec' => $r ? ($recText[$r['recommendation']] ?? $r['recommendation']) : null,
        'feedback' => $r['feedback'] ?? null,
        'scored' => $r ? date('d M Y', strtotime($r['created_at'])) : null,
    ]), ENT_QUOTES);
};

renderHead('Interviews', true);
renderSidebar('interviews');
?>

<div class="sh-page-header">
  <div>
    <h1 class="sh-page-title">Interviews</h1>
    <p class="sh-page-sub">
      <span class="sh-tnum"><?= (int)$counts['all'] ?></span> total ·
      <span class="sh-tnum"><?= (int)$counts['scheduled'] ?></span> scheduled ·
      <span class="sh-tnum"><?= (int)$counts['completed'] ?></span> completed
    </p>
  </div>
  <button class="sh-btn sh-btn-primary" onclick="openModal('ivModal')">
    <i class="fa-solid fa-calendar-plus" aria-hidden="true"></i> Schedule interview
  </button>
</div>

<!-- KPI band -->
<div class="sh-kpi-grid">
  <div class="sh-kpi">
    <div class="sh-kpi-top"><i class="fa-solid fa-sun" aria-hidden="true"></i>Today</div>
    <div class="sh-kpi-value"><?= $kToday ?></div>
    <div class="sh-kpi-foot"><span>interviews scheduled today</span><a href="<?= $qs(['view' => 'calendar', 'cal' => 'day', 'd' => $today]) ?>">Open day →</a></div>
  </div>
  <div class="sh-kpi">
    <div class="sh-kpi-top"><i class="fa-solid fa-calendar-week" aria-hidden="true"></i>Next 7 days</div>
    <div class="sh-kpi-value"><?= $kWeek ?></div>
    <div class="sh-kpi-foot"><span>upcoming this week</span><a href="<?= $qs(['view' => 'calendar', 'cal' => 'agenda']) ?>">Agenda →</a></div>
  </div>
  <div class="sh-kpi">
    <div class="sh-kpi-top"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>Completed</div>
    <div class="sh-kpi-value"><?= (int)$counts['completed'] ?></div>
    <div class="sh-kpi-foot"><span>of <?= (int)$counts['all'] ?> total sessions</span><a href="interviews.php?status=completed">View →</a></div>
  </div>
  <div class="sh-kpi">
    <div class="sh-kpi-top"><i class="fa-solid fa-user-slash" aria-hidden="true"></i>No-shows</div>
    <div class="sh-kpi-value"><?= $noShowCount ?></div>
    <div class="sh-kpi-foot"><span>missed sessions</span><a href="interviews.php?status=no-show">Review →</a></div>
  </div>
</div>

<!-- View toggle + toolbar -->
<form class="sh-flex sh-items-center sh-gap-3 sh-wrap sh-mb-4" method="GET" action="interviews.php" role="search" aria-label="Search and sort interviews">
  <?php if ($filter): ?><input type="hidden" name="status" value="<?= e($filter) ?>"><?php endif; ?>
  <?php if ($view === 'calendar'): ?><input type="hidden" name="view" value="calendar"><input type="hidden" name="cal" value="<?= $cal ?>"><input type="hidden" name="d" value="<?= $anchor ?>"><?php endif; ?>
  <div class="sh-topbar-search sh-search-inline">
    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
    <label class="sh-sr-only" for="ivSearch">Search interviews</label>
    <input type="search" id="ivSearch" name="q" value="<?= e($q) ?>" placeholder="Search candidate, interviewer, role…" autocomplete="off">
  </div>
  <label class="sh-sr-only" for="ivSort">Sort interviews</label>
  <select id="ivSort" class="sh-input sh-input-auto" name="sort">
    <?php foreach (['date_desc'=>'Newest first','date_asc'=>'Oldest first','candidate'=>'Candidate A–Z','interviewer'=>'Interviewer A–Z','status'=>'Status'] as $k => $v): ?>
    <option value="<?= $k ?>" <?= $fSort === $k ? 'selected' : '' ?>><?= $v ?></option>
    <?php endforeach; ?>
  </select>
  <button class="sh-btn sh-btn-secondary sh-btn-sm">Apply</button>
  <div class="sh-segment" role="group" aria-label="Switch view">
    <a class="sh-btn sh-btn-sm <?= $view === 'list' ? 'sh-btn-secondary active' : 'sh-btn-ghost' ?>" <?= $view === 'list' ? 'aria-current="true"' : '' ?> href="<?= $qs(['view' => '', 'cal' => '', 'd' => '']) ?>"><i class="fa-solid fa-list" aria-hidden="true"></i> List</a>
    <a class="sh-btn sh-btn-sm <?= $view === 'calendar' ? 'sh-btn-secondary active' : 'sh-btn-ghost' ?>" <?= $view === 'calendar' ? 'aria-current="true"' : '' ?> href="<?= $qs(['view' => 'calendar']) ?>"><i class="fa-regular fa-calendar" aria-hidden="true"></i> Calendar</a>
  </div>
</form>

<nav class="sh-flex sh-gap-2 sh-mb-4 sh-wrap" aria-label="Filter interviews by status">
  <a href="<?= $qs(['status' => '', 'page' => '']) ?>" class="sh-chip <?= $filter === '' ? 'active' : '' ?>" <?= $filter === '' ? 'aria-current="page"' : '' ?>>All <span class="sh-count"><?= (int)$counts['all'] ?></span></a>
  <?php foreach (['scheduled'=>'Scheduled','completed'=>'Completed','cancelled'=>'Cancelled','no-show'=>'No-show'] as $st => $lbl): ?>
  <a href="<?= $qs(['status' => $st, 'page' => '']) ?>" class="sh-chip <?= $filter === $st ? 'active' : '' ?>" <?= $filter === $st ? 'aria-current="page"' : '' ?>><?= $lbl ?> <span class="sh-count"><?= $st === 'no-show' ? $noShowCount : (int)($counts[$st] ?? 0) ?></span></a>
  <?php endforeach; ?>
</nav>

<?php if ($view === 'calendar'):
  // ── Calendar (server-rendered; month / week / day / agenda) ────────────────
  $calQs = fn(array $over = []) => $qs(array_merge(['view' => 'calendar'], $over));
  if ($cal === 'month') { $prev = date('Y-m-d', strtotime('first day of previous month', $anchorTs)); $next = date('Y-m-d', strtotime('first day of next month', $anchorTs)); $rangeLbl = date('F Y', $anchorTs); }
  elseif ($cal === 'week') { $wkStart = strtotime('monday this week', $anchorTs); $prev = date('Y-m-d', strtotime('-7 days', $wkStart)); $next = date('Y-m-d', strtotime('+7 days', $wkStart)); $rangeLbl = date('d M', $wkStart) . ' – ' . date('d M Y', strtotime('+6 days', $wkStart)); }
  elseif ($cal === 'day') { $prev = date('Y-m-d', strtotime('-1 day', $anchorTs)); $next = date('Y-m-d', strtotime('+1 day', $anchorTs)); $rangeLbl = date('l, d F Y', $anchorTs); }
  else { $prev = $next = null; $rangeLbl = 'Next 30 days'; }
?>
<section class="sh-card sh-card-hero sh-card-flush" aria-label="Interview calendar">
  <div class="sh-card-header">
    <div class="sh-flex sh-items-center sh-gap-3">
      <?php if ($prev): ?>
      <a class="sh-iconbtn" href="<?= $calQs(['d' => $prev]) ?>" aria-label="Previous <?= $cal ?>"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></a>
      <a class="sh-iconbtn" href="<?= $calQs(['d' => $next]) ?>" aria-label="Next <?= $cal ?>"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></a>
      <?php endif; ?>
      <h2 class="sh-card-title"><?= $rangeLbl ?></h2>
      <?php if ($anchor !== $today && $cal !== 'agenda'): ?><a class="sh-btn sh-btn-ghost sh-btn-sm" href="<?= $calQs(['d' => $today]) ?>">Today</a><?php endif; ?>
    </div>
    <nav class="sh-segment" aria-label="Calendar view">
      <?php foreach (['month'=>'Month','week'=>'Week','day'=>'Day','agenda'=>'Agenda'] as $cv => $cl): ?>
      <a class="sh-btn sh-btn-sm <?= $cal === $cv ? 'sh-btn-secondary active' : 'sh-btn-ghost' ?>" <?= $cal === $cv ? 'aria-current="true"' : '' ?> href="<?= $calQs(['cal' => $cv]) ?>"><?= $cl ?></a>
      <?php endforeach; ?>
    </nav>
  </div>

  <?php if ($cal === 'month'):
    $first = strtotime(date('Y-m-01', $anchorTs));
    $daysInMonth = (int)date('t', $anchorTs);
    $lead = ((int)date('N', $first)) - 1; // Monday-first
  ?>
  <div class="sh-cal-dow" aria-hidden="true"><?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d): ?><span><?= $d ?></span><?php endforeach; ?></div>
  <div class="sh-cal-grid" role="list">
    <?php for ($i = 0; $i < $lead; $i++): ?><div class="sh-cal-cell sh-cal-pad" aria-hidden="true"></div><?php endfor;
    for ($d = 1; $d <= $daysInMonth; $d++):
      $ds = date('Y-m-', $anchorTs) . str_pad($d, 2, '0', STR_PAD_LEFT);
      $evs = $byDate[$ds] ?? []; ?>
    <div class="sh-cal-cell <?= $ds === $today ? 'is-today' : '' ?> <?= $evs ? 'has-events' : '' ?>" role="listitem">
      <a class="sh-cal-daynum" href="<?= $calQs(['cal' => 'day', 'd' => $ds]) ?>" aria-label="<?= date('d F Y', strtotime($ds)) ?><?= $evs ? ', ' . count($evs) . ' interview' . (count($evs) > 1 ? 's' : '') : '' ?>"><?= $d ?></a>
      <?php foreach (array_slice($evs, 0, 3) as $iv): ?>
      <button type="button" class="sh-cal-ev tone-<?= $ivTone[$iv['status']] ?? 'info' ?>" onclick='shShowIv(<?= $ivJson($iv) ?>, this)' aria-haspopup="dialog">
        <span class="sh-tnum"><?= date('g:i A', strtotime($iv['scheduled_time'])) ?></span> <?= e($iv['candidate_name']) ?>
      </button>
      <?php endforeach; if (count($evs) > 3): ?>
      <a class="sh-cal-more" href="<?= $calQs(['cal' => 'day', 'd' => $ds]) ?>">+<?= count($evs) - 3 ?> more</a>
      <?php endif; ?>
    </div>
    <?php endfor; ?>
  </div>

  <?php elseif ($cal === 'week'): $wkStart = strtotime('monday this week', $anchorTs); ?>
  <div class="sh-cal-week">
    <?php for ($i = 0; $i < 7; $i++): $ds = date('Y-m-d', strtotime("+$i days", $wkStart)); $evs = $byDate[$ds] ?? []; ?>
    <div class="sh-cal-wcol <?= $ds === $today ? 'is-today' : '' ?>">
      <a class="sh-cal-whead" href="<?= $calQs(['cal' => 'day', 'd' => $ds]) ?>"><?= date('D', strtotime($ds)) ?> <span class="sh-tnum"><?= date('d', strtotime($ds)) ?></span></a>
      <?php if (!$evs): ?><p class="sh-cal-none">—</p><?php endif;
      foreach ($evs as $iv): ?>
      <button type="button" class="sh-cal-ev tone-<?= $ivTone[$iv['status']] ?? 'info' ?>" onclick='shShowIv(<?= $ivJson($iv) ?>, this)' aria-haspopup="dialog">
        <span class="sh-tnum"><?= date('g:i A', strtotime($iv['scheduled_time'])) ?></span> <?= e($iv['candidate_name']) ?>
        <span class="sh-cell-sub sh-block"><?= e(ucfirst($iv['type'])) ?> · <?= e($iv['interviewer'] ?: '—') ?></span>
      </button>
      <?php endforeach; ?>
    </div>
    <?php endfor; ?>
  </div>

  <?php else:
    // Day view = agenda scoped to one date; agenda = next 30 days from anchor
    $agendaDays = [];
    if ($cal === 'day') { $agendaDays = [$anchor]; }
    else { for ($i = 0; $i < 30; $i++) { $ds = date('Y-m-d', strtotime("+$i days", strtotime($today))); if (!empty($byDate[$ds])) $agendaDays[] = $ds; } }
    $any = false; foreach ($agendaDays as $ds) if (!empty($byDate[$ds])) { $any = true; break; }
  ?>
  <div class="sh-cal-agenda">
    <?php if (!$any): ?>
    <div class="sh-empty">
      <div class="sh-empty-icon"><i class="fa-regular fa-calendar" aria-hidden="true"></i></div>
      <h2>Nothing scheduled</h2>
      <p><?= $cal === 'day' ? 'No interviews on this date.' : 'No upcoming interviews in the next 30 days.' ?></p>
      <button class="sh-btn sh-btn-primary sh-mt-2" onclick="openModal('ivModal')">Schedule an interview</button>
    </div>
    <?php endif; ?>
    <?php foreach ($agendaDays as $ds): $evs = $byDate[$ds] ?? []; if (!$evs) continue; ?>
    <h3 class="sh-cal-agenda-date <?= $ds === $today ? 'is-today' : '' ?>"><?= date('l, d F Y', strtotime($ds)) ?><?= $ds === $today ? ' · Today' : '' ?></h3>
    <ul class="sh-cal-agenda-list">
      <?php foreach ($evs as $iv): ?>
      <li>
        <button type="button" class="sh-cal-agenda-ev" onclick='shShowIv(<?= $ivJson($iv) ?>, this)' aria-haspopup="dialog">
          <span class="sh-tnum sh-cal-agenda-time"><?= date('g:i A', strtotime($iv['scheduled_time'])) ?></span>
          <span class="sh-avatar" aria-hidden="true"><?= strtoupper(substr($iv['candidate_name'], 0, 1)) ?></span>
          <span class="sh-flex-1">
            <strong><?= e($iv['candidate_name']) ?></strong>
            <span class="sh-cell-sub sh-block"><?= e(ucfirst($iv['type'])) ?> · <?= $iv['mode'] === 'online' ? 'Online' : 'In-person' ?> · <?= e($iv['interviewer'] ?: 'Interviewer TBD') ?></span>
          </span>
          <span class="sh-badge sh-badge-<?= $ivTone[$iv['status']] ?? 'info' ?>"><?= e($iv['status']) ?></span>
        </button>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<?php else: ?>
<!-- ── List view ── -->
<?php if ($upcoming && $filter === '' && $q === '' && $page === 1): ?>
<section class="sh-card sh-mb-4" aria-label="Next up">
  <div class="sh-card-header"><div><h2 class="sh-card-title">Next up</h2><p class="sh-card-sub">Your soonest scheduled interviews</p></div>
  <a class="sh-btn sh-btn-ghost sh-btn-sm" href="<?= $qs(['view' => 'calendar', 'cal' => 'agenda']) ?>">Full agenda →</a></div>
  <ul class="sh-upcoming">
    <?php foreach ($upcoming as $iv): ?>
    <li>
      <button type="button" class="sh-upcoming-ev" onclick='shShowIv(<?= $ivJson($iv) ?>, this)' aria-haspopup="dialog">
        <span class="sh-upcoming-date"><span class="sh-tnum sh-upcoming-day"><?= date('d', strtotime($iv['scheduled_date'])) ?></span><span class="sh-upcoming-mon"><?= date('M', strtotime($iv['scheduled_date'])) ?></span></span>
        <span class="sh-flex-1">
          <strong><?= e($iv['candidate_name']) ?></strong>
          <span class="sh-cell-sub sh-block"><?= date('g:i A', strtotime($iv['scheduled_time'])) ?> · <?= e(ucfirst($iv['type'])) ?> · <?= e($iv['interviewer'] ?: 'Interviewer TBD') ?></span>
        </span>
        <i class="fa-solid fa-chevron-right sh-text-muted" aria-hidden="true"></i>
      </button>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section class="sh-card sh-card-flush" aria-label="Interviews table">
  <?php if (!$rows): ?>
  <div class="sh-empty">
    <div class="sh-empty-icon"><i class="fa-solid fa-calendar-xmark" aria-hidden="true"></i></div>
    <h2>No interviews found</h2>
    <p><?= $q !== '' || $filter !== '' ? 'Try clearing the search or status filter.' : 'Schedule your first interview to get started.' ?></p>
    <?php if ($q === '' && $filter === ''): ?><button class="sh-btn sh-btn-primary sh-mt-2" onclick="openModal('ivModal')">Schedule an interview</button>
    <?php else: ?><a class="sh-btn sh-btn-secondary sh-mt-2" href="interviews.php">Clear filters</a><?php endif; ?>
  </div>
  <?php else: ?>
  <div class="sh-table-wrap">
    <table class="sh-table">
      <thead><tr>
        <th scope="col">Candidate</th><th scope="col">Interviewer</th><th scope="col">Date &amp; time</th>
        <th scope="col">Type</th><th scope="col">Mode</th><th scope="col">Status</th>
        <th scope="col"><span class="sh-sr-only">Actions</span></th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $iv): $r = $resByIv[$iv['id']] ?? null; ?>
        <tr>
          <td data-th="Candidate">
            <button class="sh-cellbtn" onclick='shShowIv(<?= $ivJson($iv) ?>, this)' aria-haspopup="dialog">
              <span class="sh-avatar" aria-hidden="true"><?= strtoupper(substr($iv['candidate_name'], 0, 1)) ?></span>
              <span><span class="sh-block"><?= e($iv['candidate_name']) ?></span>
              <span class="sh-cell-sub"><?= e($iv['position']) ?></span></span>
            </button>
          </td>
          <td data-th="Interviewer"><?= e($iv['interviewer'] ?: '—') ?></td>
          <td data-th="Date &amp; time" class="sh-tnum"><?= date('d M Y', strtotime($iv['scheduled_date'])) ?>
            <span class="sh-cell-sub sh-block"><?= date('g:i A', strtotime($iv['scheduled_time'])) ?></span></td>
          <td data-th="Type"><?= e(ucfirst($iv['type'])) ?></td>
          <td data-th="Mode"><i class="fa-solid <?= $iv['mode'] === 'online' ? 'fa-video' : 'fa-building' ?> sh-text-muted" aria-hidden="true"></i> <?= $iv['mode'] === 'online' ? 'Online' : 'In-person' ?></td>
          <td data-th="Status"><span class="sh-badge sh-badge-<?= $ivTone[$iv['status']] ?? 'info' ?>"><?= e($iv['status']) ?></span>
            <?php if ($r): ?><span class="sh-cell-sub sh-block sh-tnum">Score <?= (int)$r['overall_score'] ?>%</span><?php endif; ?></td>
          <td>
            <div class="sh-row-actions">
            <a class="sh-iconbtn" href="score_interview.php?interview_id=<?= (int)$iv['id'] ?>" aria-label="Score interview with <?= e($iv['candidate_name']) ?>"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i></a>
            <button class="sh-iconbtn" onclick='openEditIv(<?= htmlspecialchars(json_encode($iv), ENT_QUOTES) ?>)' aria-label="Edit interview with <?= e($iv['candidate_name']) ?>"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i></button>
            <form method="POST" class="sh-inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="form_action" value="delete">
              <input type="hidden" name="interview_id" value="<?= (int)$iv['id'] ?>">
              <button type="submit" class="sh-iconbtn sh-danger-text" data-confirm="Delete this interview?" aria-label="Delete interview with <?= e($iv['candidate_name']) ?>"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
            </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<?= sh_pagination($page, $pages, fn($p) => $qs(['page' => $p > 1 ? $p : ''])) ?>
<?php endif; ?>

<!-- ── Detail slide-over ── -->
<aside class="sh-slideover" id="ivPanel" role="dialog" aria-modal="false" aria-labelledby="ipName" aria-hidden="true">
  <div class="sh-slideover-head">
    <div class="sh-flex-1">
      <h2 class="sh-card-title sh-panel-title" id="ipName"></h2>
      <p class="sh-card-sub" id="ipMeta"></p>
    </div>
    <button class="sh-iconbtn" onclick="shCloseSlideover('ivPanel')" aria-label="Close panel"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
  </div>
  <div class="sh-slideover-body">
    <dl class="sh-dl">
      <dt>Status</dt><dd id="ipStatus"></dd>
      <dt>Type</dt><dd id="ipType"></dd>
      <dt>Mode</dt><dd id="ipMode"></dd>
      <dt>Interviewer</dt><dd id="ipInterviewer"></dd>
      <dt>Date &amp; time</dt><dd class="sh-tnum" id="ipWhen"></dd>
      <dt>Email</dt><dd id="ipEmail"></dd>
      <dt>Notes</dt><dd id="ipNotes"></dd>
    </dl>
    <div id="ipScoreWrap" hidden>
      <h3 class="sh-card-title sh-panel-title sh-mt-4">Scorecard — <span class="sh-tnum" id="ipScore"></span>%</h3>
      <p class="sh-card-sub">Recommendation (interviewer): <span id="ipRec"></span></p>
      <div id="ipScores"></div>
      <h3 class="sh-card-title sh-panel-title sh-mt-4" id="ipFbHead" hidden>Feedback</h3>
      <p class="sh-text-2 sh-mt-2" id="ipFeedback"></p>
    </div>
    <h3 class="sh-card-title sh-panel-title sh-mt-4">Timeline</h3>
    <ul class="sh-timeline" id="ipTimeline"></ul>
  </div>
  <div class="sh-slideover-foot">
    <a class="sh-btn sh-btn-primary" id="ipScoreLink" href="#">Score interview</a>
    <button class="sh-btn sh-btn-secondary" id="ipEditBtn">Edit</button>
    <a class="sh-btn sh-btn-secondary" id="ipCandLink" href="#">Candidate</a>
  </div>
</aside>

<!-- ── Schedule modal ── -->
<div class="modal-overlay" id="ivModal" role="dialog" aria-modal="true" aria-labelledby="ivModalTitle">
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title" id="ivModalTitle">Schedule interview</h2>
      <button class="modal-close" onclick="closeModal('ivModal')" aria-label="Close dialog">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="form_action" value="create">
      <?= csrf_field() ?>
      <div class="modal-body">
        <div class="sh-form-grid">
          <div class="sh-field sh-colspan">
            <label class="sh-label" for="sv_cid">Candidate <span class="req" aria-hidden="true">*</span></label>
            <select id="sv_cid" name="candidate_id" class="sh-input" required>
              <option value="">— Select candidate —</option>
              <?php foreach ($candidates as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?> (<?= e($c['position']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sh-field">
            <label class="sh-label" for="sv_interviewer">Interviewer <span class="req" aria-hidden="true">*</span></label>
            <input id="sv_interviewer" name="interviewer" type="text" class="sh-input" placeholder="e.g. Rahul Sharma" required>
          </div>
          <div class="sh-field">
            <label class="sh-label" for="sv_type">Type</label>
            <select id="sv_type" name="type" class="sh-input">
              <option value="technical">Technical</option>
              <option value="hr">HR</option>
              <option value="final">Final round</option>
            </select>
          </div>
          <div class="sh-field">
            <label class="sh-label" for="sv_date">Date <span class="req" aria-hidden="true">*</span></label>
            <input id="sv_date" name="scheduled_date" type="date" class="sh-input" required>
          </div>
          <div class="sh-field">
            <label class="sh-label" for="sv_time">Time <span class="req" aria-hidden="true">*</span></label>
            <input id="sv_time" name="scheduled_time" type="time" class="sh-input" required>
          </div>
          <div class="sh-field">
            <label class="sh-label" for="sv_mode">Mode</label>
            <select id="sv_mode" name="mode" class="sh-input">
              <option value="online">Online</option>
              <option value="in-person">In-person</option>
            </select>
          </div>
          <div class="sh-field sh-colspan">
            <label class="sh-label" for="sv_notes">Notes</label>
            <textarea id="sv_notes" name="notes" class="sh-input" placeholder="Preparation notes…"></textarea>
            <p class="sh-help">The candidate receives an email invite when this is saved.</p>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="sh-btn sh-btn-secondary" onclick="closeModal('ivModal')">Cancel</button>
        <button type="submit" class="sh-btn sh-btn-primary"><i class="fa-solid fa-check" aria-hidden="true"></i> Schedule</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Edit modal ── -->
<div class="modal-overlay" id="editIvModal" role="dialog" aria-modal="true" aria-labelledby="editIvTitle">
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title" id="editIvTitle">Edit interview</h2>
      <button class="modal-close" onclick="closeModal('editIvModal')" aria-label="Close dialog">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="form_action" value="update">
      <?= csrf_field() ?>
      <input type="hidden" name="interview_id" id="eiv_id">
      <div class="modal-body">
        <div class="sh-form-grid">
          <div class="sh-field sh-colspan">
            <label class="sh-label" for="eiv_cid">Candidate</label>
            <select id="eiv_cid" name="candidate_id" class="sh-input">
              <?php foreach ($candidates as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sh-field">
            <label class="sh-label" for="eiv_interviewer">Interviewer</label>
            <input id="eiv_interviewer" name="interviewer" type="text" class="sh-input">
          </div>
          <div class="sh-field">
            <label class="sh-label" for="eiv_type">Type</label>
            <select id="eiv_type" name="type" class="sh-input">
              <option value="technical">Technical</option>
              <option value="hr">HR</option>
              <option value="final">Final round</option>
            </select>
          </div>
          <div class="sh-field">
            <label class="sh-label" for="eiv_date">Date</label>
            <input id="eiv_date" name="scheduled_date" type="date" class="sh-input">
          </div>
          <div class="sh-field">
            <label class="sh-label" for="eiv_time">Time</label>
            <input id="eiv_time" name="scheduled_time" type="time" class="sh-input">
          </div>
          <div class="sh-field">
            <label class="sh-label" for="eiv_mode">Mode</label>
            <select id="eiv_mode" name="mode" class="sh-input">
              <option value="online">Online</option>
              <option value="in-person">In-person</option>
            </select>
          </div>
          <div class="sh-field">
            <label class="sh-label" for="eiv_status">Status</label>
            <select id="eiv_status" name="status" class="sh-input">
              <option value="scheduled">Scheduled</option>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
              <option value="no-show">No show</option>
            </select>
          </div>
          <div class="sh-field sh-colspan">
            <label class="sh-label" for="eiv_notes">Notes</label>
            <textarea id="eiv_notes" name="notes" class="sh-input"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="sh-btn sh-btn-secondary" onclick="closeModal('editIvModal')">Cancel</button>
        <button type="submit" class="sh-btn sh-btn-primary"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditIv(iv) {
  document.getElementById('eiv_id').value          = iv.id;
  document.getElementById('eiv_cid').value          = iv.candidate_id;
  document.getElementById('eiv_interviewer').value  = iv.interviewer || '';
  document.getElementById('eiv_date').value          = iv.scheduled_date || '';
  document.getElementById('eiv_time').value          = iv.scheduled_time || '';
  document.getElementById('eiv_type').value          = iv.type;
  document.getElementById('eiv_mode').value          = iv.mode;
  document.getElementById('eiv_status').value        = iv.status;
  document.getElementById('eiv_notes').value         = iv.notes || '';
  openModal('editIvModal');
}
var shIvCurrent = null;
function shShowIv(iv, trigger) {
  shIvCurrent = iv;
  var set = function (id, v) { document.getElementById(id).textContent = v || '—'; };
  set('ipName', iv.candidate);
  set('ipMeta', [iv.position, iv.type].filter(Boolean).join(' · '));
  var tones = {scheduled:'info', completed:'success', cancelled:'danger', 'no-show':'warning'};
  document.getElementById('ipStatus').innerHTML =
    '<span class="sh-badge sh-badge-' + (tones[iv.status] || 'info') + '"></span>';
  document.getElementById('ipStatus').firstChild.textContent = iv.status;
  set('ipType', iv.type); set('ipMode', iv.mode); set('ipInterviewer', iv.interviewer);
  set('ipWhen', iv.date + ' · ' + iv.time); set('ipEmail', iv.email); set('ipNotes', iv.notes);
  var sw = document.getElementById('ipScoreWrap');
  if (iv.score !== null && iv.score !== undefined) {
    sw.hidden = false;
    set('ipScore', iv.score); set('ipRec', iv.rec);
    var bars = '';
    Object.keys(iv.scores || {}).forEach(function (k) {
      var v = iv.scores[k], cls = v >= 75 ? 'hi' : v >= 40 ? 'mid' : 'lo';
      bars += '<div class="sh-flex sh-items-center sh-gap-3 sh-mt-2"><span class="sh-cell-sub sh-w-130">' + k + '</span>' +
              '<div class="sh-score sh-flex-1"><div class="sh-score-track"><div class="sh-score-fill ' + cls + '" style="width:' + v + '%"></div></div>' +
              '<span class="sh-score-n">' + v + '%</span></div></div>';
    });
    document.getElementById('ipScores').innerHTML = bars;
    var fb = document.getElementById('ipFeedback'), fh = document.getElementById('ipFbHead');
    fh.hidden = !iv.feedback; fb.textContent = iv.feedback || ''; fb.hidden = !iv.feedback;
  } else { sw.hidden = true; }
  var tl = document.getElementById('ipTimeline'); tl.textContent = '';
  [['Created', iv.created], ['Scheduled slot', iv.date + ' · ' + iv.time],
   iv.scored ? ['Scored — ' + iv.score + '%', iv.scored] : null,
   ['Current status: ' + iv.status, '']].forEach(function (row) {
    if (!row) return;
    var li = document.createElement('li'), b = document.createElement('strong');
    b.textContent = row[1] ? row[1] + ' — ' : ''; li.appendChild(b);
    li.appendChild(document.createTextNode(row[0])); tl.appendChild(li);
  });
  document.getElementById('ipScoreLink').href = 'score_interview.php?interview_id=' + iv.id;
  document.getElementById('ipCandLink').href = 'candidate_detail.php?candidate_id=' + iv.cid;
  shOpenSlideover('ivPanel', trigger);
}
document.getElementById('ipEditBtn').addEventListener('click', function () {
  if (!shIvCurrent) return;
  shCloseSlideover('ivPanel');
  openEditIv({id: shIvCurrent.id, candidate_id: shIvCurrent.cid, interviewer: shIvCurrent.interviewer,
              scheduled_date: shIvCurrent.rd, scheduled_time: shIvCurrent.rt, type: shIvCurrent.rty,
              mode: shIvCurrent.rmo, status: shIvCurrent.status, notes: shIvCurrent.notes});
});
</script>

<?php renderFooter(); ?>
