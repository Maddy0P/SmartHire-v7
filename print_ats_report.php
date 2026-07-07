<?php
// ═════════════════════════════════════════════════════════════════════════════
//  print_ats_report.php — printable / PDF-ready ATS report for one application.
//  Auth resolved BEFORE any output (no headers-already-sent). Recruiter-only.
// ═════════════════════════════════════════════════════════════════════════════
require_once 'includes/config.php';
require_once 'includes/recruitment.php';
require_once 'includes/resume_parser.php';
require_once 'includes/ats.php';
requireRole('recruiter');

$appId = (int)($_GET['id'] ?? 0);
$app = dbFetchOne(
    "SELECT a.*, c.name AS cand_name, c.email AS cand_email, c.skills AS cand_skills, c.resume_path AS cand_resume,
            j.title AS job_title, j.description, j.requirements, j.skills_required, j.experience_min, j.experience_max
     FROM job_applications a JOIN candidates c ON c.id=a.candidate_id JOIN jobs j ON j.id=a.job_id WHERE a.id=?",
    'i', $appId);
if (!$app) { http_response_code(404); exit('Application not found.'); }

$resumeText = '';
$rp = $app['resume_path'] ?: $app['cand_resume'];
if ($rp && is_file(__DIR__ . '/' . $rp)) $resumeText = extract_resume_text(__DIR__ . '/' . $rp)['text'];
if (trim($resumeText) === '') $resumeText = trim(($app['cand_skills'] ?? '') . ' ' . ($app['cover_note'] ?? ''));

$job = ['description'=>$app['description'],'requirements'=>$app['requirements'],'skills_required'=>$app['skills_required'],
        'experience_min'=>(int)$app['experience_min'],'experience_max'=>(int)$app['experience_max']];
$R = sh_full_ats_report($resumeText, $job, $app['interview_score'] !== null ? (int)$app['interview_score'] : null);
$rec = $R['recommendation'];
audit_log('ats_report_print', 'application', $appId);
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><title>ATS Report — <?= e($app['cand_name']) ?></title>
<style>
  *{box-sizing:border-box;font-family:Arial,Helvetica,sans-serif}
  body{margin:0;color:#0f172a;font-size:13px;line-height:1.5}
  .wrap{max-width:800px;margin:0 auto;padding:32px}
  .head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #7c3aed;padding-bottom:14px;margin-bottom:20px}
  .brand{font-size:20px;font-weight:800;color:#7c3aed}
  h1{font-size:18px;margin:2px 0}
  .muted{color:#64748b;font-size:12px}
  .score{display:inline-block;width:90px;height:90px;border-radius:50%;background:#7c3aed;color:#fff;text-align:center;line-height:90px;font-size:30px;font-weight:800}
  .grid{display:flex;gap:20px;flex-wrap:wrap;margin:18px 0}
  .box{flex:1;min-width:220px;border:1px solid #e2e8f0;border-radius:10px;padding:14px}
  .box h3{margin:0 0 10px;font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:#7c3aed}
  .bar{height:8px;background:#eef2f7;border-radius:6px;overflow:hidden;margin:4px 0 10px}
  .bar>i{display:block;height:100%;background:#7c3aed}
  .row{display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:2px}
  .tag{display:inline-block;padding:3px 9px;border-radius:12px;font-size:11px;font-weight:700;margin:2px}
  .ok{background:#dcfce7;color:#166534}.no{background:#fee2e2;color:#991b1b}.kw{background:#ede9fe;color:#6d28d9}
  .band{display:inline-block;padding:6px 14px;border-radius:8px;font-weight:800;background:#ede9fe;color:#6d28d9}
  ul{margin:6px 0;padding-left:18px}
  @media print{.noprint{display:none}body{font-size:12px}}
  .btn{background:#7c3aed;color:#fff;border:none;padding:8px 16px;border-radius:8px;font-weight:600;cursor:pointer}
</style></head>
<body>
<div class="wrap">
  <div class="noprint" style="text-align:right;margin-bottom:10px"><button class="btn" onclick="window.print()">🖨 Print / Save as PDF</button></div>
  <div class="head">
    <div><div class="brand">⚡ SmartHire</div><div class="muted">ATS Compatibility Report</div></div>
    <div style="text-align:right"><h1><?= e($app['cand_name']) ?></h1>
      <div class="muted"><?= e($app['job_title']) ?> · <?= date('M j, Y') ?></div></div>
  </div>

  <div class="grid">
    <div style="text-align:center"><div class="score"><?= (int)$R['breakdown']['ats_score'] ?></div>
      <div class="muted" style="margin-top:6px">ATS Score · Final <?= (int)$R['final_score'] ?></div></div>
    <div class="box" style="flex:2">
      <h3>Recommendation</h3>
      <span class="band"><?= e($rec['band']) ?></span>
      <p style="margin:10px 0 0"><?= e($rec['text']) ?></p>
      <div class="row" style="margin-top:10px"><span>Hiring probability</span><b><?= (int)$R['hire_prob'] ?>%</b></div>
      <div class="row"><span>Interview probability</span><b><?= (int)$R['interview_prob'] ?>%</b></div>
      <div class="row"><span>JD keyword match</span><b><?= (int)$R['jd_match'] ?>%</b></div>
      <div class="row"><span>ATS compatibility</span><b><?= (int)$R['ats_compat'] ?>%</b></div>
    </div>
  </div>

  <div class="grid">
    <div class="box"><h3>Match Breakdown</h3>
      <?php foreach ([['Skill',$R['breakdown']['skill_match']],['Experience',$R['breakdown']['experience_match']],
                     ['Education',$R['breakdown']['education_match']],['Resume Quality',$R['breakdown']['resume_quality']],
                     ['Keyword Coverage',$R['jd_match']],['Formatting',$R['formatting']['score']],['Readability',$R['readability']]] as [$l,$v]): ?>
        <div class="row"><span><?= $l ?></span><b><?= (int)$v ?>%</b></div><div class="bar"><i style="width:<?= (int)$v ?>%"></i></div>
      <?php endforeach; ?>
    </div>
    <div class="box"><h3>Skills</h3>
      <div class="muted">Matched</div>
      <div><?php foreach ($R['matched_skills'] as $s): ?><span class="tag ok"><?= e(ucfirst($s)) ?></span><?php endforeach; if(!$R['matched_skills'])echo '<span class="muted">None</span>'; ?></div>
      <div class="muted" style="margin-top:8px">Missing</div>
      <div><?php foreach ($R['missing_skills'] as $s): ?><span class="tag no"><?= e(ucfirst($s)) ?></span><?php endforeach; if(!$R['missing_skills'])echo '<span class="tag ok">All present</span>'; ?></div>
    </div>
  </div>

  <div class="box"><h3>Keyword Coverage (<?= (int)$R['jd_match'] ?>%)</h3>
    <div class="muted">Found</div><div><?php foreach ($R['keyword']['matched'] as $k): ?><span class="tag kw"><?= e($k) ?></span><?php endforeach; ?></div>
    <div class="muted" style="margin-top:8px">Missing</div><div><?php foreach ($R['keyword']['missing'] as $k): ?><span class="tag" style="background:#f1f5f9;color:#64748b"><?= e($k) ?></span><?php endforeach; ?></div>
  </div>

  <div class="grid">
    <div class="box"><h3>Strengths</h3><ul>
      <?php foreach ($R['strengths'] as $s): ?><li><?= e($s['label']) ?> — <?= $s['value'] ?>%</li><?php endforeach; if(!$R['strengths'])echo '<li class="muted">—</li>'; ?></ul>
      <h3 style="margin-top:12px">Weaknesses</h3><ul>
      <?php foreach ($R['weaknesses'] as $w): ?><li><?= e($w['label']) ?> — <?= $w['value'] ?>%</li><?php endforeach; if(!$R['weaknesses'])echo '<li class="muted">None</li>'; ?></ul>
    </div>
    <div class="box"><h3>Improvement Suggestions</h3><ul>
      <?php foreach ($R['suggestions'] as $s): ?><li><?= e($s) ?></li><?php endforeach; ?></ul>
    </div>
  </div>

  <p class="muted" style="text-align:center;margin-top:20px">Generated by SmartHire ATS · <?= date('Y-m-d H:i') ?></p>
</div>

<!-- ═══ ATS V2 Engine (semantic analysis) — print summary ═══ -->
<?php $V = $R['v2']; $g = $V['grade']; ?>
<div class="card" style="page-break-inside:avoid">
  <h3 style="margin:0 0 8px">ATS V2 Engine — <?= e($g['label']) ?> (<?= (int)$V['overall'] ?>/100) · <?= e($V['insights']['priority']['band']) ?></h3>
  <table style="width:100%;border-collapse:collapse;font-size:11.5px">
    <tr style="border-bottom:1px solid #ddd;text-align:left"><th style="padding:4px 6px">Component</th><th>Score</th><th>Weight</th><th>Points</th><th>Notes</th></tr>
    <?php foreach ($V['components'] as $c): ?>
    <tr style="border-bottom:1px solid #eee">
      <td style="padding:4px 6px"><?= e($c['label']) ?></td><td><?= (int)$c['score'] ?>%</td><td><?= $c['weight'] ?>%</td><td><strong><?= $c['points'] ?></strong></td>
      <td style="max-width:300px"><?= e(implode(' · ', array_slice(array_merge($c['reasons'],$c['deductions']),0,2))) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p style="font-size:11.5px;margin:8px 0 0">
    <strong>Skills:</strong> matched <?= count($V['skills']['matched']) ?> · transferable <?= count($V['skills']['related']) ?> · missing <?= e(implode(', ', $V['skills']['missing']) ?: 'none') ?>.
    <strong>Certifications:</strong> <?= e(implode(', ', $V['certs']['found']) ?: 'none detected') ?><?= $V['certs']['missing'] ? ' (JD asks: '.e(implode(', ',$V['certs']['missing'])).')' : '' ?>.
  </p>
  <?php if ($V['insights']['notes']): ?>
  <p style="font-size:11.5px;margin:6px 0 0"><strong>Recruiter insights:</strong>
    <?= e(implode(' | ', array_map(fn($n) => $n['tag'] . ': ' . $n['why'], array_slice($V['insights']['notes'],0,3)))) ?></p>
  <?php endif; ?>
</div>

</body></html>
