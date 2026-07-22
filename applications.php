<?php
// ═════════════════════════════════════════════════════════════════════════════
//  applications.php — Recruiter Applicant Dashboard
//    • ATS-ranked applicant table (sortable) + Kanban pipeline board
//    • Stage moves via form OR AJAX (drag-and-drop) — both CSRF-protected
//  Security: recruiter-or-higher, CSRF, prepared statements, audit + events.
// ═════════════════════════════════════════════════════════════════════════════
require_once 'includes/config.php';
require_once 'includes/recruitment.php';
require_once 'includes/mailer.php';

$isAjax = isset($_GET['ajax']);

// AJAX endpoint must resolve auth/CSRF before any HTML output.
if ($isAjax) {
    header('Content-Type: application/json');
    if (!isLoggedIn() || !hasRole('recruiter')) { http_response_code(403); echo json_encode(['ok'=>false,'message'=>'Forbidden']); exit; }
    if ($_SERVER['REQUEST_METHOD']!=='POST' || !verify_csrf($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
        http_response_code(419); echo json_encode(['ok'=>false,'message'=>'Invalid CSRF token']); exit;
    }
    $appId = (int)($_POST['app_id'] ?? 0);
    $to    = $_POST['to_stage'] ?? '';
    $ok    = sh_move_stage($appId, $to, 'Moved via board');
    echo json_encode(['ok'=>$ok, 'message'=>$ok ? 'Moved to '.sh_stage_label($to) : 'Move not allowed', 'stage'=>$to]);
    exit;
}

require_once 'includes/layout.php';
requireRole('recruiter');
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

// ── Non-AJAX stage actions (progressive-enhancement fallback) ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act   = $_POST['form_action'] ?? '';
    $appId = (int)($_POST['app_id'] ?? 0);
    if ($act === 'move_stage')   { $ok = sh_move_stage($appId, $_POST['to_stage'] ?? '', $_POST['note'] ?? null);
                                   setFlash($ok?'success':'error', $ok?'Candidate moved.':'Move not allowed.'); }
    elseif ($act === 'shortlist'){ $ok = sh_move_stage($appId, 'shortlisted', 'Shortlisted');
                                   if ($ok) { $__a=dbFetchOne("SELECT a.candidate_id,j.title FROM job_applications a JOIN jobs j ON j.id=a.job_id WHERE a.id=?",'i',$appId); if($__a) sh_email_candidate((int)$__a['candidate_id'],'shortlisted',['job'=>$__a['title']]); }
                                   setFlash($ok?'success':'error', $ok?'Shortlisted.':'Action failed.'); }
    elseif ($act === 'reject')   { $ok = sh_move_stage($appId, 'rejected', trim($_POST['reason'] ?? 'Not a fit'));
                                   if ($ok) dbExecute("UPDATE job_applications SET rejection_reason=? WHERE id=?", 'si', trim($_POST['reason'] ?? ''), $appId);
                                   setFlash($ok?'success':'error', $ok?'Application rejected.':'Action failed.'); }
    $qs = http_build_query(array_filter(['job_id'=>(int)($_GET['job_id']??0)?:'', 'view'=>$_GET['view']??'', 'stage'=>$_GET['stage']??'']));
    redirect('applications.php' . ($qs ? "?$qs" : ''));
}

// ── Filters ──────────────────────────────────────────────────────────────────
$jobId = (int)($_GET['job_id'] ?? 0);
$stage = $_GET['stage'] ?? '';
$q     = trim($_GET['q'] ?? '');
$view  = ($_GET['view'] ?? 'list') === 'board' ? 'board' : 'list';
$sortMap = ['final'=>'a.final_score','ats'=>'a.ats_score','skill'=>'a.skill_match',
            'experience'=>'a.experience_match','education'=>'a.education_match',
            'quality'=>'a.resume_quality','interview'=>'a.interview_score','recent'=>'a.applied_at'];
$sort  = $sortMap[$_GET['sort'] ?? 'final'] ?? 'a.final_score';

$jobs = dbFetchAll("SELECT id,title FROM jobs ORDER BY created_at DESC");
$activeJob = $jobId ? dbFetchOne("SELECT * FROM jobs WHERE id=?", 'i', $jobId) : null;

$where = "1=1"; $types=''; $args=[];
if ($jobId>0)    { $where.=" AND a.job_id=?"; $types.='i'; $args[]=$jobId; }
if ($stage!=='' && isset(SH_STAGES[$stage])) { $where.=" AND a.stage=?"; $types.='s'; $args[]=$stage; }
if ($q!=='')     { $where.=" AND (c.name LIKE ? OR c.email LIKE ?)"; $types.='ss'; $args[]="%$q%"; $args[]="%$q%"; }

$rows = dbFetchAll(
    "SELECT a.*, c.name AS cand_name, c.email AS cand_email, c.position AS cand_position,
            j.title AS job_title
     FROM job_applications a
     JOIN candidates c ON c.id=a.candidate_id
     JOIN jobs j       ON j.id=a.job_id
     WHERE $where
     ORDER BY $sort DESC, a.applied_at DESC",
    $types, ...$args);

// ── CSV export of the current ranked view (recruiter) ────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    audit_log('applicants_export', 'applications', null, count($rows) . ' rows');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="applicants_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Rank','Candidate','Email','Job','Stage','ATS','Skill','Experience','Education','Quality','Interview','Final']);
    foreach ($rows as $i => $r) {
        fputcsv($out, [$i+1, $r['cand_name'], $r['cand_email'], $r['job_title'], sh_stage_label($r['stage']),
                       (int)$r['ats_score'], (int)$r['skill_match'], (int)$r['experience_match'],
                       (int)$r['education_match'], (int)$r['resume_quality'],
                       $r['interview_score'] !== null ? (int)$r['interview_score'] : '', (int)$r['final_score']]);
    }
    fclose($out); exit;
}

// stage counts for board / summary
$counts = [];
foreach (array_keys(SH_STAGES) as $st) $counts[$st] = 0;
foreach ($rows as $r) { $counts[$r['stage']] = ($counts[$r['stage']] ?? 0) + 1; }

// ── v8 presentation additions (read-only; Module 5) ──────────────────────────
// List-view pagination (presentation-layer slice — query, board and CSV untouched)
$per   = 20;
$page  = max(1, (int)($_GET['page'] ?? 1));
$pages = max(1, (int)ceil(count($rows) / $per));
$page  = min($page, $pages);
$listRows = $view === 'list' ? array_slice($rows, ($page - 1) * $per, $per) : $rows;

// Stage history for detail slide-over (batched, capped)
$events = [];
$evIds = array_map(fn($r) => (int)$r['id'], $listRows);
if ($evIds) {
    $ph = implode(',', array_fill(0, count($evIds), '?'));
    foreach (dbFetchAll(
        "SELECT application_id, from_stage, to_stage, note, actor_role, created_at
         FROM application_events WHERE application_id IN ($ph)
         ORDER BY created_at DESC LIMIT 400",
        str_repeat('i', count($evIds)), ...$evIds) as $ev) {
        if (count($events[$ev['application_id']] ?? []) < 6) $events[$ev['application_id']][] = $ev;
    }
}

$qsv = fn(array $over = []) => 'applications.php?' . http_build_query(array_filter(array_merge(
    ['job_id' => $jobId ?: '', 'stage' => $stage, 'q' => $q, 'view' => $view === 'list' ? '' : $view,
     'sort' => ($_GET['sort'] ?? 'final') === 'final' ? '' : $_GET['sort']], $over)));

function sh_app_stage_badge(string $st): string {
    $tone = ['joined'=>'success','selected'=>'success','offer_released'=>'success',
             'rejected'=>'danger','applied'=>'neutral','resume_screening'=>'info',
             'ats_analysis'=>'info','shortlisted'=>'warning'][$st] ?? 'info';
    return '<span class="sh-badge sh-badge-' . $tone . '">' . sh_stage_label($st) . '</span>';
}
function sh_score_cell(?int $v): string {
    if ($v === null) return '<span class="sh-text-muted">—</span>';
    $cls = $v >= 75 ? 'hi' : ($v >= 50 ? 'mid' : 'lo');
    return '<div class="sh-score"><div class="sh-score-track"><div class="sh-score-fill ' . $cls . '" style="width:' . $v . '%"></div></div><span class="sh-score-n sh-tnum">' . $v . '%</span></div>';
}

renderHead('Applicants', true);
renderSidebar('applications');
?>

<div class="sh-page-header">
  <div>
    <h1 class="sh-page-title">Applicants<?= $activeJob ? ' · ' . e($activeJob['title']) : '' ?></h1>
    <p class="sh-page-sub"><span class="sh-tnum"><?= count($rows) ?></span> application<?= count($rows) === 1 ? '' : 's' ?> · ranked by ATS &amp; interview score</p>
  </div>
  <div class="sh-flex sh-gap-2 sh-wrap">
    <nav class="sh-flex sh-gap-1" aria-label="View mode">
      <a href="<?= $qsv(['view' => 'list', 'page' => '']) ?>" class="sh-btn sh-btn-sm <?= $view === 'list' ? 'sh-btn-secondary' : 'sh-btn-ghost' ?>" <?= $view === 'list' ? 'aria-current="page"' : '' ?>><i class="fa-solid fa-list" aria-hidden="true"></i> List</a>
      <a href="<?= $qsv(['view' => 'board', 'stage' => '', 'page' => '']) ?>" class="sh-btn sh-btn-sm <?= $view === 'board' ? 'sh-btn-secondary' : 'sh-btn-ghost' ?>" <?= $view === 'board' ? 'aria-current="page"' : '' ?>><i class="fa-solid fa-table-columns" aria-hidden="true"></i> Pipeline</a>
    </nav>
    <a href="<?= $qsv(['export' => 'csv', 'view' => '']) ?>" class="sh-btn sh-btn-secondary sh-btn-sm"><i class="fa-solid fa-download" aria-hidden="true"></i> Export CSV</a>
  </div>
</div>

<!-- Filters (GET contract preserved) -->
<form method="GET" action="applications.php" class="sh-flex sh-items-center sh-gap-3 sh-wrap sh-mb-4">
  <input type="hidden" name="view" value="<?= e($view) ?>">
  <div class="sh-topbar-search sh-search-inline">
    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
    <label class="sh-sr-only" for="appSearch">Search candidate name or email</label>
    <input type="search" id="appSearch" name="q" value="<?= e($q) ?>" placeholder="Search name or email…" autocomplete="off">
  </div>
  <label class="sh-sr-only" for="appJob">Filter by job</label>
  <select id="appJob" class="sh-input sh-input-auto" name="job_id" onchange="this.form.submit()">
    <option value="0">All jobs</option>
    <?php foreach ($jobs as $j): ?><option value="<?= (int)$j['id'] ?>" <?= $jobId === (int)$j['id'] ? 'selected' : '' ?>><?= e($j['title']) ?></option><?php endforeach; ?>
  </select>
  <?php if ($view === 'list'): ?>
  <label class="sh-sr-only" for="appStage">Filter by stage</label>
  <select id="appStage" class="sh-input sh-input-auto" name="stage" onchange="this.form.submit()">
    <option value="">All stages</option>
    <?php foreach (SH_STAGES as $k => $m): ?><option value="<?= $k ?>" <?= $stage === $k ? 'selected' : '' ?>><?= $m['label'] ?></option><?php endforeach; ?>
  </select>
  <label class="sh-sr-only" for="appSort">Sort applicants</label>
  <select id="appSort" class="sh-input sh-input-auto" name="sort" onchange="this.form.submit()">
    <?php foreach (['final'=>'Final score','ats'=>'ATS score','skill'=>'Skill match','experience'=>'Experience','education'=>'Education','quality'=>'Resume quality','interview'=>'Interview','recent'=>'Most recent'] as $k => $v): ?>
    <option value="<?= $k ?>" <?= ($_GET['sort'] ?? 'final') === $k ? 'selected' : '' ?>>Sort: <?= $v ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
  <button class="sh-btn sh-btn-secondary sh-btn-sm">Apply</button>
</form>

<?php if (!$rows): ?>
<div class="sh-card sh-card-flush"><div class="sh-empty">
  <div class="sh-empty-icon"><i class="fa-solid fa-people-arrows" aria-hidden="true"></i></div>
  <h3><?= ($q || $stage || $jobId) ? 'No applications match these filters' : 'No applications yet' ?></h3>
  <p><?= ($q || $stage || $jobId) ? 'Try different filters, or clear them to see every application.' : 'Applications appear here as candidates apply from the Careers page.' ?></p>
  <?php if ($q || $stage || $jobId): ?><a href="applications.php" class="sh-btn sh-btn-secondary sh-btn-sm sh-mt-2">Clear filters</a><?php endif; ?>
</div></div>

<?php elseif ($view === 'board'): /* ── PIPELINE BOARD (v8) ── */ ?>
<div class="sh-pipeline" role="application" aria-label="Candidate pipeline board">
  <?php foreach (sh_stage_flow() as $st): ?>
  <section class="pipe-col" data-stage="<?= $st ?>" aria-label="<?= sh_stage_label($st) ?>: <?= (int)$counts[$st] ?> candidates">
    <h4 class="pipe-head"><span><i class="fa-solid <?= sh_stage_icon($st) ?>" aria-hidden="true"></i> <?= sh_stage_label($st) ?></span><span class="cnt sh-tnum"><?= (int)$counts[$st] ?></span></h4>
    <div class="pipe-body">
      <?php foreach ($rows as $r): if ($r['stage'] !== $st) continue; $rid = (int)$r['id']; ?>
      <article class="pipe-card" draggable="true" data-app-id="<?= $rid ?>" data-stage="<?= $st ?>" tabindex="0" aria-label="<?= e($r['cand_name']) ?>, <?= e($r['job_title']) ?>">
        <p class="nm sh-cell-main sh-truncate"><?= e($r['cand_name']) ?></p>
        <p class="rl sh-cell-sub sh-truncate"><?= e($r['job_title']) ?></p>
        <div class="sh-flex sh-items-center sh-justify-between sh-gap-2 sh-mt-2">
          <span class="sh-ai-chip" title="ATS composite score">ATS <?= (int)$r['ats_score'] ?>%</span>
          <a href="application_detail.php?id=<?= $rid ?>" class="sh-btn sh-btn-ghost sh-btn-sm">Open</a>
        </div>
        <label class="sh-sr-only" for="mv-<?= $rid ?>">Move <?= e($r['cand_name']) ?> to stage</label>
        <select id="mv-<?= $rid ?>" class="sh-input pipe-move" data-app-id="<?= $rid ?>">
          <option value="">Move to…</option>
          <?php foreach (SH_STAGES as $k => $m): if ($k === $st) continue; ?>
          <option value="<?= $k ?>"><?= $m['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </article>
      <?php endforeach; ?>
      <?php if ((int)$counts[$st] === 0): ?><p class="sh-cell-sub pipe-empty">No candidates</p><?php endif; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>
<p class="sh-cell-sub sh-mt-2" id="boardStatus" role="status" aria-live="polite"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Drag a card — or use its "Move to…" control — to change stage. Changes save instantly.</p>

<?php else: /* ── RANKED LIST (v8) ── */ ?>
<div class="sh-bulkbar" id="shBulkBar" role="toolbar" aria-label="Bulk actions">
  <span><span id="shBulkCount" class="sh-tnum">0</span> selected</span>
  <button class="sh-btn sh-btn-secondary sh-btn-sm" onclick="shAppsBulk('shortlist')"><i class="fa-solid fa-star" aria-hidden="true"></i> Shortlist</button>
  <button class="sh-btn sh-btn-danger sh-btn-sm" onclick="shAppsBulk('reject')"><i class="fa-solid fa-xmark" aria-hidden="true"></i> Reject</button>
</div>

<section class="sh-card sh-card-flush" aria-label="Ranked applicants">
  <div class="sh-table-wrap">
    <table class="sh-table">
      <thead>
        <tr>
          <th scope="col" class="sh-col-check"><input type="checkbox" class="sh-check" id="shCheckAll" aria-label="Select all applications"></th>
          <th scope="col">Candidate</th>
          <th scope="col">Job</th>
          <th scope="col">ATS <span class="sh-ai-chip" title="Composite of skill, experience, education and resume-quality match — full breakdown in the detail panel">AI</span></th>
          <th scope="col">Interview</th>
          <th scope="col" class="num">Final</th>
          <th scope="col">Stage</th>
          <th scope="col"><span class="sh-sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($listRows as $i => $r): $rid = (int)$r['id']; $rank = ($page - 1) * $per + $i + 1; ?>
        <tr>
          <td><input type="checkbox" class="sh-check sh-row-check" value="<?= $rid ?>" aria-label="Select <?= e($r['cand_name']) ?>"></td>
          <td data-th="Candidate">
            <button class="sh-cellbtn" onclick='shShowApp(<?= htmlspecialchars(json_encode([
                "id"=>$rid,"name"=>$r["cand_name"],"email"=>$r["cand_email"],"position"=>$r["cand_position"],
                "job"=>$r["job_title"],"stage"=>sh_stage_label($r["stage"]),"stage_key"=>$r["stage"],
                "ats"=>(int)$r["ats_score"],"skill"=>(int)$r["skill_match"],"exp"=>(int)$r["experience_match"],
                "edu"=>(int)$r["education_match"],"quality"=>(int)$r["resume_quality"],
                "interview"=>$r["interview_score"] !== null ? (int)$r["interview_score"] : null,
                "final"=>(int)$r["final_score"],"applied"=>date("d M Y", strtotime($r["applied_at"])),
                "reject_reason"=>$r["rejection_reason"] ?? "",
                "next"=>sh_next_stage($r["stage"]) ? sh_stage_label(sh_next_stage($r["stage"])) : null,
                "next_key"=>sh_next_stage($r["stage"]),
                "events"=>array_map(fn($ev) => ["to"=>sh_stage_label($ev["to_stage"]),"note"=>$ev["note"],
                    "by"=>$ev["actor_role"],"t"=>date("d M Y", strtotime($ev["created_at"]))], $events[$rid] ?? []),
              ], JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES) ?>, this)' aria-haspopup="dialog">
              <span class="sh-flex-1">
                <span class="sh-cell-main sh-truncate sh-block">#<?= $rank ?> · <?= e($r['cand_name']) ?></span>
                <span class="sh-cell-sub sh-truncate sh-mono sh-block"><?= e($r['cand_email']) ?></span>
              </span>
            </button>
          </td>
          <td data-th="Job"><span class="sh-truncate sh-block"><?= e($r['job_title']) ?></span></td>
          <td data-th="ATS"><?= sh_score_cell((int)$r['ats_score']) ?></td>
          <td data-th="Interview"><?= sh_score_cell($r['interview_score'] !== null ? (int)$r['interview_score'] : null) ?></td>
          <td data-th="Final" class="num"><span class="sh-cell-main sh-tnum"><?= (int)$r['final_score'] ?></span></td>
          <td data-th="Stage"><?= sh_app_stage_badge($r['stage']) ?></td>
          <td>
            <div class="sh-row-actions">
              <a href="application_detail.php?id=<?= $rid ?>" class="sh-iconbtn" aria-label="Open application of <?= e($r['cand_name']) ?>" title="Open"><i class="fa-solid fa-eye" aria-hidden="true"></i></a>
              <?php if (!in_array($r['stage'], ['joined','rejected'], true)): ?>
                <?php if ($r['stage'] !== 'shortlisted' && sh_stage_index($r['stage']) < sh_stage_index('shortlisted')): ?>
                <form method="POST" action="applications.php?<?= http_build_query(array_filter(['job_id'=>$jobId?:'','stage'=>$stage])) ?>" class="sh-inline-form">
                  <?= csrf_field() ?><input type="hidden" name="form_action" value="shortlist"><input type="hidden" name="app_id" value="<?= $rid ?>">
                  <button class="sh-iconbtn" aria-label="Shortlist <?= e($r['cand_name']) ?>" title="Shortlist"><i class="fa-solid fa-star" aria-hidden="true"></i></button>
                </form>
                <?php elseif (sh_next_stage($r['stage'])): ?>
                <form method="POST" action="applications.php?<?= http_build_query(array_filter(['job_id'=>$jobId?:'','stage'=>$stage])) ?>" class="sh-inline-form">
                  <?= csrf_field() ?><input type="hidden" name="form_action" value="move_stage"><input type="hidden" name="app_id" value="<?= $rid ?>">
                  <input type="hidden" name="to_stage" value="<?= sh_next_stage($r['stage']) ?>">
                  <button class="sh-iconbtn" aria-label="Advance <?= e($r['cand_name']) ?> to <?= sh_stage_label(sh_next_stage($r['stage'])) ?>" title="Advance to <?= sh_stage_label(sh_next_stage($r['stage'])) ?>"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
                </form>
                <?php endif; ?>
                <form method="POST" action="applications.php?<?= http_build_query(array_filter(['job_id'=>$jobId?:'','stage'=>$stage])) ?>" class="sh-inline-form">
                  <?= csrf_field() ?><input type="hidden" name="form_action" value="reject"><input type="hidden" name="app_id" value="<?= $rid ?>">
                  <button class="sh-iconbtn sh-danger-text" aria-label="Reject <?= e($r['cand_name']) ?>" title="Reject" data-confirm="Reject this candidate?"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?= sh_pagination($page, $pages, fn($p) => $qsv(['view' => 'list', 'page' => $p])) ?>

<!-- Application detail slide-over -->
<aside class="sh-slideover" id="appPanel" role="dialog" aria-modal="false" aria-labelledby="apName" aria-hidden="true">
  <div class="sh-slideover-head">
    <span class="sh-avatar" id="apAvatar" aria-hidden="true"></span>
    <div class="sh-flex-1">
      <h2 class="sh-card-title sh-panel-title" id="apName"></h2>
      <p class="sh-card-sub" id="apMeta"></p>
    </div>
    <button class="sh-iconbtn" onclick="shCloseSlideover('appPanel')" aria-label="Close panel"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
  </div>
  <div class="sh-slideover-body">
    <dl class="sh-dl">
      <dt>Email</dt><dd class="sh-mono" id="apEmail"></dd>
      <dt>Applied for</dt><dd id="apJob"></dd>
      <dt>Applied on</dt><dd class="sh-tnum" id="apApplied"></dd>
      <dt>Stage</dt><dd id="apStage"></dd>
      <dt>Final score</dt><dd class="sh-tnum" id="apFinal"></dd>
    </dl>
    <h3 class="sh-card-title sh-panel-title sh-mt-4">Score breakdown <span class="sh-ai-chip" title="Computed by the SmartHire ATS engine">AI</span></h3>
    <div id="apScores" class="sh-mt-2"></div>
    <div id="apRejectWrap" class="sh-mt-2" hidden><dl class="sh-dl"><dt>Reject note</dt><dd id="apReject"></dd></dl></div>
    <h3 class="sh-card-title sh-panel-title sh-mt-4">Stage history</h3>
    <ul class="sh-timeline" id="apTimeline"></ul>
  </div>
  <div class="sh-slideover-foot">
    <a class="sh-btn sh-btn-primary" id="apOpenLink" href="#">Open full application</a>
    <form method="POST" action="applications.php" class="sh-inline-form" id="apAdvanceForm" hidden>
      <?= csrf_field() ?><input type="hidden" name="form_action" value="move_stage">
      <input type="hidden" name="app_id" id="apAdvanceId"><input type="hidden" name="to_stage" id="apAdvanceStage">
      <button class="sh-btn sh-btn-secondary" id="apAdvanceBtn">Advance</button>
    </form>
  </div>
</aside>

<script>
function shAppsBulk(act) {
  var labels = {shortlist: 'shortlist', reject: 'reject'};
  shBulkPost('applications.php',
    function (id) { return {form_action: act, app_id: id}; },
    'Really ' + labels[act] + ' {n} selected application(s)?');
}
function shShowApp(a, trigger) {
  var set = function (id, v) { document.getElementById(id).textContent = (v === null || v === '') ? '—' : v; };
  document.getElementById('apAvatar').textContent = (a.name || '?').charAt(0).toUpperCase();
  set('apName', a.name);
  set('apMeta', a.position);
  set('apEmail', a.email);
  set('apJob', a.job);
  set('apApplied', a.applied);
  set('apStage', a.stage);
  set('apFinal', a.final);
  var scores = document.getElementById('apScores');
  scores.textContent = '';
  [['Skill match', a.skill], ['Experience', a.exp], ['Education', a.edu], ['Resume quality', a.quality],
   ['ATS composite', a.ats], ['Interview', a.interview]].forEach(function (p) {
    var row = document.createElement('div');
    row.className = 'sh-flex sh-items-center sh-gap-3 sh-mt-2';
    var lbl = document.createElement('span');
    lbl.className = 'sh-cell-sub'; lbl.style.width = '110px'; lbl.textContent = p[0];
    var bar = document.createElement('div'); bar.className = 'sh-score sh-flex-1';
    if (p[1] === null) { bar.innerHTML = '<span class="sh-text-muted">—</span>'; }
    else {
      var cls = p[1] >= 75 ? 'hi' : (p[1] >= 50 ? 'mid' : 'lo');
      bar.innerHTML = '<div class="sh-score-track"><div class="sh-score-fill ' + cls + '" style="width:' + p[1] + '%"></div></div><span class="sh-score-n sh-tnum">' + p[1] + '%</span>';
    }
    row.appendChild(lbl); row.appendChild(bar); scores.appendChild(row);
  });
  var rw = document.getElementById('apRejectWrap');
  rw.hidden = !a.reject_reason;
  set('apReject', a.reject_reason);
  var tl = document.getElementById('apTimeline');
  tl.textContent = '';
  if (!a.events.length) { var li = document.createElement('li'); li.textContent = 'Applied ' + a.applied; tl.appendChild(li); }
  a.events.forEach(function (ev) {
    var li = document.createElement('li');
    var b = document.createElement('strong'); b.textContent = ev.t;
    li.appendChild(b);
    li.appendChild(document.createTextNode(' — ' + ev.to + (ev.by ? ' · by ' + ev.by : '') + (ev.note ? ' · "' + ev.note + '"' : '')));
    tl.appendChild(li);
  });
  document.getElementById('apOpenLink').href = 'application_detail.php?id=' + a.id;
  var af = document.getElementById('apAdvanceForm');
  af.hidden = !a.next_key;
  if (a.next_key) {
    document.getElementById('apAdvanceId').value = a.id;
    document.getElementById('apAdvanceStage').value = a.next_key;
    document.getElementById('apAdvanceBtn').textContent = 'Advance to ' + a.next;
  }
  shOpenSlideover('appPanel', trigger);
}
</script>
<?php endif; ?>

<?php renderFooter(); ?>
