<?php
// ═════════════════════════════════════════════════════════════════════════════
//  application_detail.php — Single application: ATS breakdown · timeline ·
//  stage controls · offer release.  Recruiter-or-higher, CSRF, prepared SQL.
// ═════════════════════════════════════════════════════════════════════════════
require_once 'includes/config.php';
require_once 'includes/layout.php';
require_once 'includes/recruitment.php';
require_once 'includes/mailer.php';
requireRole('recruiter');
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

$appId = (int)($_GET['id'] ?? ($_POST['app_id'] ?? 0));

// ── Actions ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';
    if ($act === 'move')   { $ok = sh_move_stage($appId, $_POST['to_stage'] ?? '', trim($_POST['note'] ?? '') ?: null);
                             setFlash($ok?'success':'error', $ok?'Stage updated.':'Move not allowed.'); }
    elseif ($act === 'reject') { $ok = sh_move_stage($appId, 'rejected', trim($_POST['reason'] ?? 'Not a fit'));
                             if ($ok) dbExecute("UPDATE job_applications SET rejection_reason=? WHERE id=?", 'si', trim($_POST['reason'] ?? ''), $appId);
                             setFlash($ok?'success':'error', $ok?'Application rejected.':'Action failed.'); }
    elseif ($act === 'set_interview') { $sc = max(0,min(100,(int)($_POST['interview_score'] ?? 0)));
                             $app = dbFetchOne("SELECT ats_score FROM job_applications WHERE id=?", 'i', $appId);
                             $final = sh_final_score((int)($app['ats_score'] ?? 0), $sc);
                             dbExecute("UPDATE job_applications SET interview_score=?, final_score=? WHERE id=?", 'iii', $sc, $final, $appId);
                             audit_log('app_interview_score','application',$appId,'score='.$sc);
                             setFlash('success','Interview score recorded. Final score recomputed.'); }
    elseif ($act === 'release_offer') {
        $app = dbFetchOne("SELECT * FROM job_applications WHERE id=?", 'i', $appId);
        if ($app) {
            $desig = trim($_POST['designation'] ?? '');
            $ctc   = ($_POST['ctc'] ?? '') !== '' ? (int)$_POST['ctc'] : null;
            $join  = ($_POST['joining_date'] ?? '') !== '' ? $_POST['joining_date'] : null;
            $body  = trim($_POST['letter_body'] ?? '');
            if (v_len($desig,2,150)) {
                withTransaction(function () use ($appId,$app,$desig,$ctc,$join,$body) {
                    dbExecute("INSERT INTO offers (application_id,candidate_id,job_id,designation,ctc,joining_date,letter_body,released_by)
                               VALUES (?,?,?,?,?,?,?,?)",
                        'iiisissi', $appId,(int)$app['candidate_id'],(int)$app['job_id'],$desig,$ctc,$join,$body,currentUser()['id']);
                    sh_move_stage($appId, 'offer_released', 'Offer released: '.$desig);
                    notifyCandidate((int)$app['candidate_id'],'offer_released','🎉 You have received an offer: '.$desig);
                    sh_email_candidate((int)$app['candidate_id'],'offer_released',['job'=>$app['job_title'],'extra'=>($ctc?('CTC: '.number_format($ctc).'. '):'').'Designation: '.$desig.'.']);
                });
                setFlash('success','Offer released to candidate.');
            } else setFlash('error','Designation is required.');
        }
    }
    redirect('application_detail.php?id=' . $appId);
}

// ── Load ─────────────────────────────────────────────────────────────────────
$app = dbFetchOne(
    "SELECT a.*, c.name AS cand_name, c.email AS cand_email, c.phone AS cand_phone,
            c.position AS cand_position, c.skills AS cand_skills, c.resume_path AS cand_resume,
            j.title AS job_title, j.skills_required, j.experience_min, j.experience_max, j.location, j.department
     FROM job_applications a
     JOIN candidates c ON c.id=a.candidate_id
     JOIN jobs j ON j.id=a.job_id WHERE a.id=?", 'i', $appId);

if (!$app) { renderHead('Not found'); renderSidebar('applications');
    echo '<div class="sh-empty"><i class="fa-solid fa-triangle-exclamation"></i><h3>Application not found</h3><p><a href="applications.php" class="btn btn-primary">Back to applicants</a></p></div>';
    renderFooter(); exit; }

$events = dbFetchAll("SELECT * FROM application_events WHERE application_id=? ORDER BY created_at ASC, id ASC", 'i', $appId);
$offer  = dbFetchOne("SELECT * FROM offers WHERE application_id=? ORDER BY id DESC LIMIT 1", 'i', $appId);

$subs = [
    ['Skill Match', (int)$app['skill_match'], 'fa-code'],
    ['Experience',  (int)$app['experience_match'], 'fa-clock'],
    ['Education',   (int)$app['education_match'], 'fa-graduation-cap'],
    ['Resume Quality', (int)$app['resume_quality'], 'fa-file-lines'],
];

renderHead('Application · '.$app['cand_name']);
renderSidebar('applications');
?>
<div class="page-header sh-between sh-wrap">
  <div class="page-header-left">
    <a href="applications.php?job_id=<?= (int)$app['job_id'] ?>" style="color:#7c3aed;text-decoration:none;font-size:13px;font-weight:600">&larr; Back to applicants</a>
    <h1><?= e($app['cand_name']) ?></h1>
    <p class="sh-muted"><?= e($app['job_title']) ?> · applied <?= date('M j, Y', strtotime($app['applied_at'])) ?></p>
  </div>
  <span class="stage-badge stage-<?= sh_stage_color($app['stage']) ?>" style="font-size:13px"><i class="fa-solid <?= sh_stage_icon($app['stage']) ?>"></i> <?= sh_stage_label($app['stage']) ?></span>
</div>

<div class="sh-grid" style="grid-template-columns:1.4fr 1fr">
  <!-- Left column -->
  <div>
    <!-- ATS breakdown -->
    <div class="card sh-mb">
      <div class="card-header sh-between"><h3 class="card-title"><i class="fa-solid fa-robot"></i> ATS Breakdown</h3>
        <a href="ats_report.php?id=<?= $appId ?>" class="btn btn-sm btn-primary"><i class="fa-solid fa-gauge-high"></i> Full ATS Report</a></div>
      <div class="card-body">
        <div class="sh-flex sh-mb" style="gap:20px">
          <div class="ats-score" style="--pct:<?= (int)$app['ats_score'] ?>;--ring:<?= (int)$app['ats_score']>=75?'#10b981':((int)$app['ats_score']>=50?'#7c3aed':'#f59e0b') ?>;width:84px;height:84px;font-size:24px"><span style="width:66px;height:66px"><?= (int)$app['ats_score'] ?></span></div>
          <div>
            <div style="font-weight:800;font-size:16px;color:#0f172a">Overall ATS Score</div>
            <div class="sh-muted" style="font-size:13px">Final (with interview): <strong style="color:#7c3aed"><?= (int)$app['final_score'] ?></strong></div>
          </div>
        </div>
        <?php foreach ($subs as [$label,$val,$icon]): ?>
        <div class="subscore">
          <span><i class="fa-solid <?= $icon ?>" style="color:#7c3aed;width:16px"></i> <?= $label ?></span>
          <span class="score-bar"><i style="width:<?= $val ?>%"></i></span>
          <span style="text-align:right;font-weight:700"><?= $val ?>%</span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Timeline -->
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-timeline"></i> Application Timeline</h3></div>
      <div class="card-body">
        <div class="timeline">
          <?php foreach (array_reverse($events) as $ev): ?>
          <div class="tl-item <?= $ev['actor_role']==='system'?'sys':'' ?>">
            <div class="tl-head"><?= $ev['from_stage'] ? sh_stage_label($ev['from_stage']).' → ' : '' ?><?= sh_stage_label($ev['to_stage']) ?></div>
            <div class="tl-meta"><i class="fa-regular fa-clock"></i> <?= date('M j, Y g:i A', strtotime($ev['created_at'])) ?> · <?= e(ucfirst($ev['actor_role'] ?: 'system')) ?></div>
            <?php if ($ev['note']): ?><div class="tl-note"><?= e($ev['note']) ?></div><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Right column -->
  <div>
    <!-- Candidate -->
    <div class="card sh-mb">
      <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-user"></i> Candidate</h3></div>
      <div class="card-body">
        <p style="margin:0 0 6px"><strong><?= e($app['cand_name']) ?></strong></p>
        <p class="sh-muted" style="margin:0 0 4px;font-size:13px"><i class="fa-solid fa-envelope"></i> <?= e($app['cand_email']) ?></p>
        <?php if ($app['cand_phone']): ?><p class="sh-muted" style="margin:0 0 4px;font-size:13px"><i class="fa-solid fa-phone"></i> <?= e($app['cand_phone']) ?></p><?php endif; ?>
        <?php if ($app['cand_skills']): ?><div class="skill-tags sh-mt"><?php foreach (array_slice(sh_parse_skills($app['cand_skills']),0,8) as $sk): ?><span class="skill-tag"><?= e(ucfirst($sk)) ?></span><?php endforeach; ?></div><?php endif; ?>
        <?php if ($app['cand_resume']): ?><a href="<?= e($app['cand_resume']) ?>" target="_blank" class="btn btn-sm btn-secondary sh-mt"><i class="fa-solid fa-file-arrow-down"></i> View Resume</a><?php endif; ?>
        <?php if ($app['cover_note']): ?><div class="sh-mt"><label style="font-size:12px;font-weight:700;color:#64748b">COVER NOTE</label><p style="font-size:13px;color:#475569;margin:4px 0 0"><?= e($app['cover_note']) ?></p></div><?php endif; ?>
      </div>
    </div>

    <!-- Stage controls -->
    <?php if (!in_array($app['stage'],['joined','rejected'],true)): ?>
    <div class="card sh-mb">
      <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-diagram-project"></i> Move Stage</h3></div>
      <div class="card-body">
        <form method="POST" action="application_detail.php" class="sh-mb">
          <?= csrf_field() ?><input type="hidden" name="form_action" value="move"><input type="hidden" name="app_id" value="<?= $appId ?>">
          <div class="form-group"><label>Advance to</label>
            <select class="form-control" name="to_stage">
              <?php foreach (sh_stage_flow() as $st): if (sh_stage_index($st)<=sh_stage_index($app['stage'])) continue; ?>
              <option value="<?= $st ?>"><?= sh_stage_label($st) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="form-group"><label>Note (optional)</label><input class="form-control" name="note" placeholder="e.g. Strong technical round"></div>
          <button class="btn btn-primary w-100"><i class="fa-solid fa-arrow-right"></i> Update Stage</button>
        </form>
        <form method="POST" action="application_detail.php" class="sh-mb">
          <?= csrf_field() ?><input type="hidden" name="form_action" value="set_interview"><input type="hidden" name="app_id" value="<?= $appId ?>">
          <div class="form-group"><label>Interview Score (0–100)</label>
            <input class="form-control" type="number" min="0" max="100" name="interview_score" value="<?= $app['interview_score']!==null?(int)$app['interview_score']:'' ?>"></div>
          <button class="btn btn-secondary w-100"><i class="fa-solid fa-star"></i> Save Interview Score</button>
        </form>
        <form method="POST" action="application_detail.php">
          <?= csrf_field() ?><input type="hidden" name="form_action" value="reject"><input type="hidden" name="app_id" value="<?= $appId ?>">
          <div class="form-group"><label>Reject reason</label><input class="form-control" name="reason" placeholder="Reason (shared internally)"></div>
          <button class="btn btn-danger w-100" data-confirm="Reject this candidate?"><i class="fa-solid fa-xmark"></i> Reject</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Offer -->
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-file-signature"></i> Offer</h3></div>
      <div class="card-body">
        <?php if ($offer): ?>
          <p style="margin:0 0 8px"><strong><?= e($offer['designation']) ?></strong></p>
          <p class="sh-muted" style="font-size:13px;margin:0 0 4px">Status:
            <span class="stage-badge stage-<?= $offer['status']==='accepted'||$offer['status']==='joined'?'green':($offer['status']==='declined'?'rose':'amber') ?>"><?= ucfirst($offer['status']) ?></span></p>
          <?php if ($offer['ctc']): ?><p class="sh-muted" style="font-size:13px;margin:0"><i class="fa-solid fa-indian-rupee-sign"></i> <?= number_format((int)$offer['ctc']) ?> CTC</p><?php endif; ?>
          <?php if ($offer['joining_date']): ?><p class="sh-muted" style="font-size:13px;margin:4px 0 0"><i class="fa-solid fa-calendar"></i> Joining <?= date('M j, Y', strtotime($offer['joining_date'])) ?></p><?php endif; ?>
        <?php elseif (in_array($app['stage'],['selected','interview_completed','offer_released'],true) || sh_stage_index($app['stage'])>=sh_stage_index('shortlisted')): ?>
          <form method="POST" action="application_detail.php">
            <?= csrf_field() ?><input type="hidden" name="form_action" value="release_offer"><input type="hidden" name="app_id" value="<?= $appId ?>">
            <div class="form-group"><label>Designation *</label><input class="form-control" name="designation" required value="<?= e($app['cand_position'] ?: $app['job_title']) ?>"></div>
            <div class="form-group"><label>CTC (INR)</label><input class="form-control" type="number" name="ctc"></div>
            <div class="form-group"><label>Joining Date</label><input class="form-control" type="date" name="joining_date"></div>
            <div class="form-group"><label>Offer Note</label><textarea class="form-control" name="letter_body" rows="2"></textarea></div>
            <button class="btn btn-success w-100"><i class="fa-solid fa-paper-plane"></i> Release Offer</button>
          </form>
        <?php else: ?>
          <p class="sh-muted" style="font-size:13px;margin:0">Shortlist and progress the candidate to release an offer.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php renderFooter(); ?>
