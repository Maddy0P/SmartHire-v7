<?php
// ═════════════════════════════════════════════════════════════════════════════
//  candidate_ats_report.php — Candidate-facing ATS V2 report + improvement roadmap.
//  Security: candidate login; application strictly scoped to the logged-in
//  candidate (IDOR-safe); read-only; all output escaped.
// ═════════════════════════════════════════════════════════════════════════════
require_once 'includes/config.php';
require_once 'includes/recruitment.php';
require_once 'includes/resume_parser.php';
require_once 'includes/ats.php';
requireCandidateLogin();

$cand  = currentCandidate();
$appId = (int)($_GET['id'] ?? 0);
$app = dbFetchOne(
    "SELECT a.*, j.title AS job_title, j.description, j.requirements, j.skills_required,
            j.experience_min, j.experience_max, c.skills AS cand_skills, c.resume_path AS cand_resume
     FROM job_applications a JOIN jobs j ON j.id=a.job_id JOIN candidates c ON c.id=a.candidate_id
     WHERE a.id=? AND a.candidate_id=?", 'ii', $appId, $cand['id']);
if (!$app) { http_response_code(404); exit('Report not found.'); }

$resumeText = '';
$rp = $app['resume_path'] ?: $app['cand_resume'];
if ($rp && is_file(__DIR__ . '/' . $rp)) $resumeText = extract_resume_text(__DIR__ . '/' . $rp)['text'];
if (trim($resumeText) === '') $resumeText = trim(($app['cand_skills'] ?? '') . ' ' . ($app['cover_note'] ?? ''));

$R = sh_full_ats_report($resumeText, $app, $app['interview_score'] !== null ? (int)$app['interview_score'] : null);
$V = $R['v2']; $g = $V['grade'];
$gcol = ['green'=>'#10b981','blue'=>'#3b82f6','amber'=>'#f59e0b','orange'=>'#fb923c','rose'=>'#f43f5e'][$g['color']] ?? '#3b82f6';

// Improvement roadmap: every component suggestion, ordered by weight (impact)
$roadmap = [];
foreach ($V['components'] as $c) {
    foreach ($c['suggestions'] as $s) $roadmap[] = ['area' => $c['label'], 'weight' => $c['weight'], 'tip' => $s];
}
usort($roadmap, fn($a,$b) => $b['weight'] <=> $a['weight']);
$roadmap = array_slice($roadmap, 0, 10);
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>My ATS Report — SmartHire</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="assets/css/main.css"><link rel="stylesheet" href="assets/css/v7.css">
<style>
 body{background:linear-gradient(135deg,#0f172a,#1e1b4b);min-height:100vh}
 .cp-header{background:linear-gradient(135deg,#7c3aed,#4338ca);padding:18px 32px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
 .cp-header .brand{display:flex;align-items:center;gap:12px;color:#fff}.cp-header .brand h1{font-size:18px;font-weight:700;margin:0}
 .cp-nav a{color:rgba(255,255,255,.82);text-decoration:none;font-size:13px;font-weight:600;padding:8px 14px;border-radius:9px}
 .cp-nav a:hover{background:rgba(255,255,255,.16);color:#fff}
 .wrap{max-width:900px;margin:0 auto;padding:26px 20px}
 .panel{background:#fff;border-radius:16px;padding:22px;margin-bottom:16px;box-shadow:0 12px 30px -16px rgba(0,0,0,.5)}
 .panel h3{margin:0 0 12px;color:#0f172a;font-size:15px}
 .muted{color:#64748b;font-size:12.5px}
 .bar{height:8px;background:#eef2f7;border-radius:6px;overflow:hidden}.bar>i{display:block;height:100%;background:#7c3aed}
</style></head>
<body>
<div class="cp-header">
  <div class="brand"><i class="fa-solid fa-bolt"></i><h1>SmartHire</h1></div>
  <nav class="cp-nav">
    <a href="candidate_portal.php"><i class="fa-solid fa-house"></i> Portal</a>
    <a href="careers.php"><i class="fa-solid fa-briefcase"></i> Careers</a>
    <a href="my_applications.php"><i class="fa-solid fa-list-check"></i> My Applications</a>
    <a href="candidate_logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
  </nav>
</div>
<div class="wrap">
  <a href="my_applications.php" style="color:#a78bfa;text-decoration:none;font-size:13px;font-weight:600">&larr; My Applications</a>

  <div class="panel" style="display:flex;gap:22px;align-items:center;flex-wrap:wrap;border-left:4px solid <?= $gcol ?>">
    <div class="ats-score" style="--pct:<?= (int)$V['overall'] ?>;--ring:<?= $gcol ?>;width:110px;height:110px;font-size:32px"><span style="width:88px;height:88px"><?= (int)$V['overall'] ?></span></div>
    <div style="flex:1;min-width:220px">
      <h2 style="margin:0 0 4px;color:#0f172a"><?= e($app['job_title']) ?></h2>
      <div style="font-weight:800;color:<?= $gcol ?>;font-size:15px"><?= e($g['label']) ?></div>
      <p class="muted" style="margin:8px 0 0">This is how our ATS scored your resume against this job. Every point is explained below, with a roadmap of the highest-impact improvements first.</p>
    </div>
  </div>

  <div class="panel">
    <h3><i class="fa-solid fa-scale-balanced" style="color:#7c3aed"></i> Your Score Breakdown</h3>
    <?php foreach ($V['components'] as $c): ?>
    <div style="margin-bottom:12px">
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px">
        <strong><?= e($c['label']) ?> <span class="muted">(<?= $c['weight'] ?>% of score)</span></strong><span><?= (int)$c['score'] ?>%</span>
      </div>
      <div class="bar"><i style="width:<?= (int)$c['score'] ?>%;background:<?= $c['score']>=75?'#10b981':($c['score']>=50?'#7c3aed':'#f59e0b') ?>"></i></div>
      <?php foreach (array_slice($c['deductions'],0,1) as $d): ?><div class="muted" style="margin-top:3px"><i class="fa-solid fa-circle-info"></i> <?= e($d) ?></div><?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="panel">
    <h3><i class="fa-solid fa-route" style="color:#7c3aed"></i> Improvement Roadmap <span class="muted">(highest impact first)</span></h3>
    <?php if (!$roadmap): ?><p class="muted">No major improvements needed — strong profile for this role. 🎉</p><?php endif; ?>
    <?php foreach ($roadmap as $i => $r): ?>
    <div style="display:flex;gap:12px;margin-bottom:10px;font-size:13.5px">
      <span style="background:#ede9fe;color:#6d28d9;font-weight:800;border-radius:8px;min-width:26px;height:26px;display:flex;align-items:center;justify-content:center"><?= $i+1 ?></span>
      <div><strong><?= e($r['area']) ?>:</strong> <span style="color:#334155"><?= e($r['tip']) ?></span></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="panel">
    <h3><i class="fa-solid fa-key" style="color:#7c3aed"></i> Skills & Keywords for this Job</h3>
    <?php if ($V['skills']['missing']): ?>
    <div class="muted" style="margin-bottom:6px">MISSING REQUIRED SKILLS — add these if you have them:</div>
    <div class="skill-tags" style="margin-bottom:12px"><?php foreach ($V['skills']['missing'] as $s): ?><span class="stage-badge stage-rose"><?= e(ucfirst($s)) ?></span><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if ($V['skills']['related']): ?>
    <div class="muted" style="margin-bottom:6px">GOOD NEWS — related skills you already have (partial credit):</div>
    <?php foreach ($V['skills']['related'] as $r): ?><div style="font-size:13px;margin-bottom:4px">Your <strong><?= e(ucfirst($r['have'])) ?></strong> counts toward <strong><?= e(ucfirst($r['need'])) ?></strong> (<?= e($r['cat']) ?>)</div><?php endforeach; ?>
    <?php endif; ?>
    <?php if ($R['keyword']['missing']): ?>
    <div class="muted" style="margin:12px 0 6px">JOB KEYWORDS NOT ON YOUR RESUME:</div>
    <div class="skill-tags"><?php foreach (array_slice($R['keyword']['missing'],0,12) as $k): ?><span class="sh-chip"><?= e($k) ?></span><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if ($V['certs']['missing']): ?>
    <div class="muted" style="margin:12px 0 6px">CERTIFICATIONS THE JOB MENTIONS:</div>
    <div class="skill-tags"><?php foreach ($V['certs']['missing'] as $c): ?><span class="stage-badge stage-amber"><?= e($c) ?></span><?php endforeach; ?></div>
    <?php endif; ?>
  </div>
</div>
</body></html>
