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

function ats_ring(int $pct): string {
    $col = $pct>=75?'#10b981':($pct>=50?'#7c3aed':($pct>=30?'#f59e0b':'#ef4444'));
    return '<div class="ats-score" style="--pct:'.$pct.';--ring:'.$col.'"><span>'.$pct.'</span></div>';
}

renderHead('Applicants');
renderSidebar('applications');
?>
<div class="page-header sh-between sh-wrap">
  <div class="page-header-left">
    <h1>Applicants<?= $activeJob ? ' · '.e($activeJob['title']) : '' ?></h1>
    <p class="sh-muted"><?= count($rows) ?> application<?= count($rows)===1?'':'s' ?> · ranked by ATS &amp; interview score</p>
  </div>
  <div class="sh-flex sh-wrap">
    <a href="applications.php?<?= http_build_query(array_filter(['job_id'=>$jobId?:'', 'stage'=>$stage, 'view'=>'list'])) ?>" class="btn btn-sm <?= $view==='list'?'btn-primary':'btn-secondary' ?>"><i class="fa-solid fa-list"></i> List</a>
    <a href="applications.php?<?= http_build_query(array_filter(['job_id'=>$jobId?:'', 'view'=>'board'])) ?>" class="btn btn-sm <?= $view==='board'?'btn-primary':'btn-secondary' ?>"><i class="fa-solid fa-table-columns"></i> Pipeline</a>
    <a href="applications.php?<?= http_build_query(array_filter(['job_id'=>$jobId?:'', 'stage'=>$stage, 'q'=>$q, 'sort'=>$_GET['sort']??'', 'export'=>'csv'])) ?>" class="btn btn-sm btn-secondary" title="Export current view"><i class="fa-solid fa-file-csv"></i> Export</a>
  </div>
</div>

<!-- ── Filters ── -->
<form method="GET" action="applications.php" class="filter-bar">
  <input type="hidden" name="view" value="<?= e($view) ?>">
  <div class="search"><i class="fa-solid fa-search"></i>
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search candidate name or email…" aria-label="Search applicants"></div>
  <select name="job_id" aria-label="Filter by job" onchange="this.form.submit()">
    <option value="0">All jobs</option>
    <?php foreach ($jobs as $j): ?><option value="<?= (int)$j['id'] ?>" <?= $jobId===(int)$j['id']?'selected':'' ?>><?= e($j['title']) ?></option><?php endforeach; ?>
  </select>
  <?php if ($view==='list'): ?>
  <select name="stage" aria-label="Filter by stage" onchange="this.form.submit()">
    <option value="">All stages</option>
    <?php foreach (SH_STAGES as $k=>$m): ?><option value="<?= $k ?>" <?= $stage===$k?'selected':'' ?>><?= $m['label'] ?></option><?php endforeach; ?>
  </select>
  <select name="sort" aria-label="Sort by" onchange="this.form.submit()">
    <?php foreach (['final'=>'Final Score','ats'=>'ATS Score','skill'=>'Skill Match','experience'=>'Experience','education'=>'Education','quality'=>'Resume Quality','interview'=>'Interview','recent'=>'Most Recent'] as $k=>$v): ?>
    <option value="<?= $k ?>" <?= ($_GET['sort']??'final')===$k?'selected':'' ?>>Sort: <?= $v ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <button class="btn btn-secondary"><i class="fa-solid fa-filter"></i> Apply</button>
</form>

<?php if (!$rows): ?>
<div class="sh-empty"><i class="fa-solid fa-people-arrows"></i><h3>No applications yet</h3>
  <p>Applications will appear here as candidates apply from the Careers page.</p></div>

<?php elseif ($view==='board'): /* ── PIPELINE BOARD ── */ ?>
<div class="pipeline" role="list" aria-label="Candidate pipeline">
  <?php foreach (sh_stage_flow() as $st): ?>
  <div class="pipe-col" data-stage="<?= $st ?>" role="listitem">
    <h4><span><i class="fa-solid <?= sh_stage_icon($st) ?>"></i> <?= sh_stage_label($st) ?></span><span class="cnt"><?= (int)$counts[$st] ?></span></h4>
    <div class="pipe-body">
      <?php foreach ($rows as $r): if ($r['stage']!==$st) continue; ?>
      <div class="pipe-card" draggable="true" data-app-id="<?= (int)$r['id'] ?>" data-stage="<?= $st ?>" tabindex="0">
        <p class="nm"><?= e($r['cand_name']) ?></p>
        <p class="rl"><?= e($r['job_title']) ?></p>
        <div class="sh-between">
          <span class="stage-badge stage-<?= sh_stage_color($st) ?>" style="font-size:10.5px">ATS <?= (int)$r['ats_score'] ?>%</span>
          <a href="application_detail.php?id=<?= (int)$r['id'] ?>" class="btn btn-xs btn-secondary">Open</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<p class="sh-muted sh-mt" style="font-size:12.5px"><i class="fa-solid fa-circle-info"></i> Drag a card to another column to move the candidate. Changes save instantly.</p>

<?php else: /* ── RANKED LIST ── */ ?>
<div class="table-container">
  <table class="table sh-rtable">
    <thead>
      <tr>
        <th>#</th><th>Candidate</th><th>Job</th><th>ATS</th>
        <th>Skill</th><th>Exp</th><th>Edu</th><th>Quality</th><th>Interview</th><th>Final</th><th>Stage</th><th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $i=>$r): ?>
      <tr>
        <td data-label="#"><?= $i+1 ?></td>
        <td data-label="Candidate"><strong><?= e($r['cand_name']) ?></strong><br><small class="sh-muted"><?= e($r['cand_email']) ?></small></td>
        <td data-label="Job"><?= e($r['job_title']) ?></td>
        <td data-label="ATS"><?= ats_ring((int)$r['ats_score']) ?></td>
        <td data-label="Skill"><?= (int)$r['skill_match'] ?>%</td>
        <td data-label="Exp"><?= (int)$r['experience_match'] ?>%</td>
        <td data-label="Edu"><?= (int)$r['education_match'] ?>%</td>
        <td data-label="Quality"><?= (int)$r['resume_quality'] ?>%</td>
        <td data-label="Interview"><?= $r['interview_score']!==null ? (int)$r['interview_score'].'%' : '—' ?></td>
        <td data-label="Final"><strong style="color:#7c3aed"><?= (int)$r['final_score'] ?></strong></td>
        <td data-label="Stage"><span class="stage-badge stage-<?= sh_stage_color($r['stage']) ?>"><i class="fa-solid <?= sh_stage_icon($r['stage']) ?>"></i> <?= sh_stage_label($r['stage']) ?></span></td>
        <td data-label="Actions">
          <div class="sh-flex">
            <a href="application_detail.php?id=<?= (int)$r['id'] ?>" class="btn btn-xs btn-secondary" title="Open"><i class="fa-solid fa-eye"></i></a>
            <?php if (!in_array($r['stage'],['joined','rejected'],true)): ?>
              <?php if ($r['stage']!=='shortlisted' && sh_stage_index($r['stage'])<sh_stage_index('shortlisted')): ?>
              <form method="POST" action="applications.php?<?= http_build_query(array_filter(['job_id'=>$jobId?:'','stage'=>$stage])) ?>" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="form_action" value="shortlist"><input type="hidden" name="app_id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-xs btn-success" title="Shortlist"><i class="fa-solid fa-star"></i></button>
              </form>
              <?php elseif (sh_next_stage($r['stage'])): ?>
              <form method="POST" action="applications.php?<?= http_build_query(array_filter(['job_id'=>$jobId?:'','stage'=>$stage])) ?>" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="form_action" value="move_stage"><input type="hidden" name="app_id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="to_stage" value="<?= sh_next_stage($r['stage']) ?>">
                <button class="btn btn-xs btn-primary" title="Advance to <?= sh_stage_label(sh_next_stage($r['stage'])) ?>"><i class="fa-solid fa-arrow-right"></i></button>
              </form>
              <?php endif; ?>
              <form method="POST" action="applications.php?<?= http_build_query(array_filter(['job_id'=>$jobId?:'','stage'=>$stage])) ?>" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="form_action" value="reject"><input type="hidden" name="app_id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-xs btn-danger" title="Reject" data-confirm="Reject this candidate?"><i class="fa-solid fa-xmark"></i></button>
              </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php renderFooter(); ?>
