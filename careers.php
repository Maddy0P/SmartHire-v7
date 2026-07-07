<?php
// ═════════════════════════════════════════════════════════════════════════════
//  careers.php — Candidate Job Search · Details · Apply
//  Security: candidate login, CSRF on apply, secure resume upload, prepared SQL.
//  Applying auto-runs the ATS engine (JD-aware) and creates the application.
// ═════════════════════════════════════════════════════════════════════════════
require_once 'includes/config.php';
require_once 'includes/resume_parser.php';
require_once 'includes/recruitment.php';
require_once 'includes/mailer.php';
requireCandidateLogin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

$cand      = currentCandidate();
$candidate = dbFetchOne("SELECT * FROM candidates WHERE id=?", 'i', $cand['id']);
$flash     = null;

// ── Apply handler ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'apply') {
    $jobId = (int)($_POST['job_id'] ?? 0);
    $cover = trim($_POST['cover_note'] ?? '');
    $job   = dbFetchOne("SELECT * FROM jobs WHERE id=? AND status='open'", 'i', $jobId);

    if (!$job) {
        setFlash('error', 'This job is no longer open for applications.');
        redirect('careers.php');
    }
    if (dbFetchOne("SELECT id FROM job_applications WHERE job_id=? AND candidate_id=?", 'ii', $jobId, $cand['id'])) {
        setFlash('error', 'You have already applied to this role.');
        redirect('careers.php?id=' . $jobId);
    }

    // Resolve resume text: uploaded file > pasted text > candidate's stored resume
    $resumeText = ''; $resumePath = $candidate['resume_path'] ?? null;
    $err = '';
    if (!empty($_FILES['resume']['name'])) {
        $up = store_resume_upload($_FILES['resume']);
        if ($up['ok']) {
            $resumePath = $up['path'];
            $resumeText = extract_resume_text(__DIR__ . '/' . $up['path'])['text'];
            dbExecute("UPDATE candidates SET resume_path=? WHERE id=?", 'si', $resumePath, $cand['id']);
        } else { $err = $up['error']; }
    }
    if ($resumeText === '' && trim($_POST['resume_text'] ?? '') !== '') {
        $resumeText = trim($_POST['resume_text']);
    }
    if ($resumeText === '' && $resumePath && is_file(__DIR__ . '/' . $resumePath)) {
        $resumeText = extract_resume_text(__DIR__ . '/' . $resumePath)['text'];
    }
    // Fall back to candidate skills so ATS still produces a signal
    if ($resumeText === '') $resumeText = (string)($candidate['skills'] ?? '');

    if ($err) {
        setFlash('error', $err);
        redirect('careers.php?id=' . $jobId);
    }
    $appId = sh_create_application($jobId, $cand['id'], $cover, $resumePath, $resumeText);
    if ($appId) {
        sh_email_candidate($cand['id'], 'application_confirmation', ['job'=>$job['title']]);
        setFlash('success', 'Application submitted! Your resume was auto-scored by our ATS.');
        redirect('my_applications.php');
    } else {
        setFlash('error', 'Could not submit application. Please try again.');
        redirect('careers.php?id=' . $jobId);
    }
}

$flash = getFlash();

// ── Detail view? ─────────────────────────────────────────────────────────────
$viewId = (int)($_GET['id'] ?? 0);
$job = $viewId ? dbFetchOne(
    "SELECT j.*, c.name AS category_name FROM jobs j LEFT JOIN job_categories c ON c.id=j.category_id WHERE j.id=?",
    'i', $viewId) : null;
$alreadyApplied = $job ? (bool)dbFetchOne("SELECT id FROM job_applications WHERE job_id=? AND candidate_id=?", 'ii', $viewId, $cand['id']) : false;

// ── List view ────────────────────────────────────────────────────────────────
$q     = trim($_GET['q'] ?? '');
$fCat  = (int)($_GET['category'] ?? 0);
$fType = $_GET['type'] ?? '';
$categories = dbFetchAll("SELECT * FROM job_categories ORDER BY name");

$jobs = [];
if (!$job) {
    $where = "j.status='open'"; $types=''; $args=[];
    if ($q !== '')  { $where .= " AND (j.title LIKE ? OR j.skills_required LIKE ?)"; $types.='ss'; $args[]="%$q%"; $args[]="%$q%"; }
    if ($fCat > 0)  { $where .= " AND j.category_id=?"; $types.='i'; $args[]=$fCat; }
    if ($fType!=='' && in_array($fType,['full_time','part_time','contract','internship','remote'],true)) { $where.=" AND j.employment_type=?"; $types.='s'; $args[]=$fType; }
    $jobs = dbFetchAll(
        "SELECT j.*, c.name AS category_name,
                (SELECT COUNT(*) FROM job_applications a WHERE a.job_id=j.id AND a.candidate_id=?) AS mine
         FROM jobs j LEFT JOIN job_categories c ON c.id=j.category_id
         WHERE $where ORDER BY j.created_at DESC",
        'i'.$types, $cand['id'], ...$args);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Careers — SmartHire</title>
  <meta name="csrf-token" content="<?= csrf_token() ?>">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="assets/css/main.css">
  <link rel="stylesheet" href="assets/css/v7.css">
  <style>
    body{background:linear-gradient(135deg,#0f172a,#1e1b4b);min-height:100vh}
    .cp-header{background:linear-gradient(135deg,#7c3aed,#4338ca);padding:18px 32px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
    .cp-header .brand{display:flex;align-items:center;gap:12px;color:#fff}
    .cp-header .brand i{font-size:22px}.cp-header .brand h1{font-size:18px;font-weight:700;margin:0}
    .cp-nav{display:flex;gap:6px;flex-wrap:wrap}
    .cp-nav a{color:rgba(255,255,255,.82);text-decoration:none;font-size:13px;font-weight:600;padding:8px 14px;border-radius:9px;transition:all .15s}
    .cp-nav a:hover,.cp-nav a.active{background:rgba(255,255,255,.16);color:#fff}
    .cp-content{max-width:1080px;margin:0 auto;padding:26px 20px}
    .cp-title{color:#fff;font-size:22px;font-weight:800;margin:0 0 4px}
    .cp-sub{color:#94a3b8;font-size:13.5px;margin:0 0 22px}
    .detail-card{background:#fff;border-radius:16px;padding:28px;box-shadow:0 20px 50px -20px rgba(0,0,0,.5)}
    .detail-card h2{margin:0 0 6px;font-size:22px;color:#0f172a}
    label{display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:6px}
    .prose{color:#334155;font-size:14px;line-height:1.7;white-space:pre-wrap}
  </style>
</head>
<body>
<div class="cp-header">
  <div class="brand"><i class="fa-solid fa-bolt"></i><h1>SmartHire</h1></div>
  <nav class="cp-nav">
    <a href="candidate_portal.php"><i class="fa-solid fa-house"></i> Portal</a>
    <a href="careers.php" class="active"><i class="fa-solid fa-briefcase"></i> Careers</a>
    <a href="my_applications.php"><i class="fa-solid fa-list-check"></i> My Applications</a>
    <a href="candidate_logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
  </nav>
</div>

<div class="cp-content">
  <?php if ($flash): ?>
  <div class="alert alert-<?= e($flash['type']) ?>" style="margin-bottom:18px">
    <i class="fa-solid <?= $flash['type']==='success'?'fa-check-circle':'fa-triangle-exclamation' ?>"></i> <?= e($flash['msg']) ?>
  </div>
  <?php endif; ?>

  <?php if ($job): /* ── DETAIL + APPLY ── */ ?>
    <a href="careers.php" style="color:#a78bfa;text-decoration:none;font-size:13px;font-weight:600">&larr; Back to all jobs</a>
    <div class="detail-card sh-mt">
      <div class="sh-between sh-wrap">
        <div>
          <h2><?= e($job['title']) ?></h2>
          <div class="job-meta">
            <?php if ($job['category_name']): ?><span><i class="fa-solid fa-layer-group"></i> <?= e($job['category_name']) ?></span><?php endif; ?>
            <?php if ($job['location']): ?><span><i class="fa-solid fa-location-dot"></i> <?= e($job['location']) ?></span><?php endif; ?>
            <span><i class="fa-solid fa-briefcase"></i> <?= ucfirst(str_replace('_',' ',$job['employment_type'])) ?></span>
            <?php if ((int)$job['experience_max']>0): ?><span><i class="fa-solid fa-clock"></i> <?= (int)$job['experience_min'] ?>–<?= (int)$job['experience_max'] ?> yrs</span><?php endif; ?>
            <?php if ($job['salary_min']||$job['salary_max']): ?><span><i class="fa-solid fa-indian-rupee-sign"></i> <?= e(number_format((int)$job['salary_min'])) ?><?= $job['salary_max']?'–'.number_format((int)$job['salary_max']):'' ?></span><?php endif; ?>
          </div>
        </div>
        <?php if ($job['status']==='open' && !$alreadyApplied): ?>
        <a href="#applyform" class="btn btn-primary btn-lg"><i class="fa-solid fa-paper-plane"></i> Apply Now</a>
        <?php elseif ($alreadyApplied): ?>
        <span class="stage-badge stage-green"><i class="fa-solid fa-check"></i> Applied</span>
        <?php endif; ?>
      </div>

      <?php if ($job['skills_required']): ?>
      <div class="sh-mt"><label>Required Skills</label>
        <div class="skill-tags"><?php foreach (sh_parse_skills($job['skills_required']) as $sk): ?><span class="skill-tag"><?= e(ucfirst($sk)) ?></span><?php endforeach; ?></div>
      </div>
      <?php endif; ?>
      <?php if ($job['description']): ?><div class="sh-mt"><label>About the Role</label><div class="prose"><?= e($job['description']) ?></div></div><?php endif; ?>
      <?php if ($job['requirements']): ?><div class="sh-mt"><label>Requirements</label><div class="prose"><?= e($job['requirements']) ?></div></div><?php endif; ?>

      <?php if ($job['status']==='open' && !$alreadyApplied): ?>
      <hr style="margin:24px 0;border:none;border-top:1px solid #eef2f7">
      <form method="POST" action="careers.php" enctype="multipart/form-data" id="applyform">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="apply">
        <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
        <h3 style="margin:0 0 14px;color:#0f172a"><i class="fa-solid fa-paper-plane" style="color:#7c3aed"></i> Apply for this role</h3>
        <div class="form-group"><label>Cover Note <span class="sh-muted">(optional)</span></label>
          <textarea class="form-control" name="cover_note" rows="3" placeholder="Why you're a great fit…"></textarea></div>
        <div class="form-group"><label>Resume <span class="sh-muted">(PDF/DOCX/TXT — powers your ATS score)</span></label>
          <input class="form-control" type="file" name="resume" accept=".pdf,.doc,.docx,.txt">
          <?php if (!empty($candidate['resume_path'])): ?><small class="sh-muted">Leave empty to reuse your uploaded resume.</small><?php endif; ?></div>
        <div class="form-group"><label>Or paste resume text <span class="sh-muted">(optional fallback)</span></label>
          <textarea class="form-control" name="resume_text" rows="4" placeholder="Paste your resume text here if you don't have a file…"></textarea></div>
        <button class="btn btn-primary btn-lg"><i class="fa-solid fa-paper-plane"></i> Submit Application</button>
      </form>
      <?php endif; ?>
    </div>

  <?php else: /* ── LIST ── */ ?>
    <h1 class="cp-title">Open Positions</h1>
    <p class="cp-sub">Find your next role and apply in one click. Your resume is auto-scored against each job.</p>

    <form method="GET" action="careers.php" class="filter-bar">
      <div class="search"><i class="fa-solid fa-search"></i>
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search roles or skills…" aria-label="Search jobs"></div>
      <select name="category" aria-label="Category" onchange="this.form.submit()">
        <option value="0">All categories</option>
        <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $fCat===(int)$c['id']?'selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select>
      <select name="type" aria-label="Employment type" onchange="this.form.submit()">
        <option value="">All types</option>
        <?php foreach (['full_time'=>'Full-time','part_time'=>'Part-time','contract'=>'Contract','internship'=>'Internship','remote'=>'Remote'] as $k=>$v): ?>
        <option value="<?= $k ?>" <?= $fType===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-primary"><i class="fa-solid fa-filter"></i> Search</button>
    </form>

    <?php if (!$jobs): ?>
    <div class="sh-empty" style="color:#94a3b8">
      <i class="fa-solid fa-briefcase"></i><h3 style="color:#cbd5e1">No open roles match your search</h3>
      <p>Try clearing filters or check back soon.</p>
    </div>
    <?php else: ?>
    <div class="sh-grid sh-grid-3">
      <?php foreach ($jobs as $j): ?>
      <div class="job-card">
        <div class="sh-between"><h3><?= e($j['title']) ?></h3>
          <?php if ((int)$j['mine']>0): ?><span class="stage-badge stage-green"><i class="fa-solid fa-check"></i> Applied</span><?php endif; ?></div>
        <div class="job-meta">
          <?php if ($j['category_name']): ?><span><i class="fa-solid fa-layer-group"></i> <?= e($j['category_name']) ?></span><?php endif; ?>
          <?php if ($j['location']): ?><span><i class="fa-solid fa-location-dot"></i> <?= e($j['location']) ?></span><?php endif; ?>
          <span><i class="fa-solid fa-briefcase"></i> <?= ucfirst(str_replace('_',' ',$j['employment_type'])) ?></span>
        </div>
        <?php if ($j['skills_required']): ?>
        <div class="skill-tags"><?php foreach (array_slice(sh_parse_skills($j['skills_required']),0,4) as $sk): ?><span class="skill-tag"><?= e(ucfirst($sk)) ?></span><?php endforeach; ?></div>
        <?php endif; ?>
        <div class="job-foot">
          <span class="sh-muted" style="font-size:12px"><i class="fa-regular fa-clock"></i> <?= date('M j', strtotime($j['created_at'])) ?></span>
          <a href="careers.php?id=<?= (int)$j['id'] ?>" class="btn btn-sm btn-primary">View & Apply <i class="fa-solid fa-arrow-right"></i></a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<script src="assets/js/v7.js"></script>
</body>
</html>
