<?php
// ═════════════════════════════════════════════════════════════════════════════
//  ats_report.php — Professional ATS Dashboard for one application (Build 4)
//  Jobscan/Resume-Worded style: score ring, radar, sub-scores, keyword coverage,
//  missing skills, strengths/weaknesses, recommendation, hire/interview odds.
//  Recruiter-or-higher, prepared SQL, XSS-escaped. Charts are pure inline SVG/CSS.
// ═════════════════════════════════════════════════════════════════════════════
require_once 'includes/config.php';
require_once 'includes/layout.php';
require_once 'includes/recruitment.php';
require_once 'includes/resume_parser.php';
require_once 'includes/ats.php';
requireRole('recruiter');

$appId = (int)($_GET['id'] ?? 0);
$app = dbFetchOne(
    "SELECT a.*, c.name AS cand_name, c.email AS cand_email, c.skills AS cand_skills, c.resume_path AS cand_resume,
            j.title AS job_title, j.description, j.requirements, j.skills_required, j.experience_min, j.experience_max
     FROM job_applications a
     JOIN candidates c ON c.id=a.candidate_id
     JOIN jobs j ON j.id=a.job_id WHERE a.id=?", 'i', $appId);

if (!$app) { renderHead('ATS Report'); renderSidebar('applications');
    echo '<div class="sh-empty"><i class="fa-solid fa-triangle-exclamation"></i><h3>Application not found</h3><p><a href="applications.php" class="btn btn-primary">Back</a></p></div>';
    renderFooter(); exit; }

// Resolve resume text (re-extract from stored file; fall back to skills + cover note)
$resumeText = '';
$rp = $app['resume_path'] ?: $app['cand_resume'];
if ($rp && is_file(__DIR__ . '/' . $rp)) $resumeText = extract_resume_text(__DIR__ . '/' . $rp)['text'];
if (trim($resumeText) === '') $resumeText = trim(($app['cand_skills'] ?? '') . ' ' . ($app['cover_note'] ?? ''));

$job = [
    'description' => $app['description'], 'requirements' => $app['requirements'],
    'skills_required' => $app['skills_required'],
    'experience_min' => (int)$app['experience_min'], 'experience_max' => (int)$app['experience_max'],
];
$R = sh_full_ats_report($resumeText, $job, $app['interview_score'] !== null ? (int)$app['interview_score'] : null);

// ── Inline SVG radar (6 axes) ────────────────────────────────────────────────
function sh_radar_svg(array $dims): string {
    $n = count($dims); $cx = 130; $cy = 130; $r = 100; $a0 = -M_PI/2;
    $ring = ''; $axes = ''; $poly = ''; $labels = '';
    // grid rings
    foreach ([0.25,0.5,0.75,1.0] as $g) {
        $pts = [];
        for ($i=0;$i<$n;$i++){ $ang=$a0+2*M_PI*$i/$n; $pts[]=round($cx+cos($ang)*$r*$g,1).','.round($cy+sin($ang)*$r*$g,1); }
        $ring .= '<polygon points="'.implode(' ',$pts).'" fill="none" stroke="rgba(148,163,184,.18)" stroke-width="1"/>';
    }
    $vpts = [];
    $i = 0;
    foreach ($dims as $label => $val) {
        $ang = $a0 + 2*M_PI*$i/$n;
        $ex = $cx+cos($ang)*$r; $ey = $cy+sin($ang)*$r;
        $axes .= '<line x1="'.$cx.'" y1="'.$cy.'" x2="'.round($ex,1).'" y2="'.round($ey,1).'" stroke="rgba(148,163,184,.18)" stroke-width="1"/>';
        $vx = $cx+cos($ang)*$r*($val/100); $vy = $cy+sin($ang)*$r*($val/100);
        $vpts[] = round($vx,1).','.round($vy,1);
        $lx = $cx+cos($ang)*($r+18); $ly = $cy+sin($ang)*($r+14);
        $anchor = abs(cos($ang))<0.3?'middle':(cos($ang)>0?'start':'end');
        $labels .= '<text x="'.round($lx,1).'" y="'.round($ly+3,1).'" font-size="10.5" fill="#94a3b8" text-anchor="'.$anchor.'">'.htmlspecialchars($label).'</text>';
        $i++;
    }
    $poly = '<polygon points="'.implode(' ',$vpts).'" fill="rgba(59,130,246,.25)" stroke="#3b82f6" stroke-width="2"/>';
    return '<svg viewBox="0 0 260 260" width="100%" style="max-width:300px" role="img" aria-label="ATS radar chart">'.$ring.$axes.$poly.$labels.'</svg>';
}
$radar = sh_radar_svg([
    'Skill'    => $R['breakdown']['skill_match'],
    'Exp'      => $R['breakdown']['experience_match'],
    'Edu'      => $R['breakdown']['education_match'],
    'Quality'  => $R['breakdown']['resume_quality'],
    'Keywords' => $R['jd_match'],
    'Format'   => $R['formatting']['score'],
]);

function bar(string $label, int $val, string $icon=''): string {
    $c = $val>=75?'#10b981':($val>=50?'#3b82f6':($val>=35?'#f59e0b':'#f43f5e'));
    return '<div class="subscore"><span>'.($icon?'<i class="fa-solid '.$icon.'" style="color:#60a5fa;width:16px"></i> ':'').htmlspecialchars($label).'</span>'
         . '<span class="score-bar"><i style="width:'.$val.'%;background:'.$c.'"></i></span>'
         . '<span style="text-align:right;font-weight:700">'.$val.'%</span></div>';
}
function gauge(string $label, int $pct, string $sub): string {
    $c = $pct>=66?'#10b981':($pct>=40?'#f59e0b':'#f43f5e');
    return '<div class="card" style="text-align:center;padding:18px"><div class="ats-score" style="--pct:'.$pct.';--ring:'.$c.';width:88px;height:88px;font-size:24px;margin:0 auto"><span style="width:70px;height:70px">'.$pct.'%</span></div>'
         . '<div style="margin-top:10px;font-weight:700">'.htmlspecialchars($label).'</div><div class="sh-muted" style="font-size:12px">'.htmlspecialchars($sub).'</div></div>';
}

renderHead('ATS Report · '.$app['cand_name']);
renderSidebar('applications');
$rec = $R['recommendation'];
?>
<div class="page-header sh-between sh-wrap">
  <div class="page-header-left">
    <a href="application_detail.php?id=<?= $appId ?>" style="color:var(--accent-light);text-decoration:none;font-size:13px;font-weight:600">&larr; Back to application</a>
    <h1><i class="fa-solid fa-gauge-high"></i> ATS Report</h1>
    <p class="sh-muted"><?= e($app['cand_name']) ?> · <?= e($app['job_title']) ?></p>
  </div>
  <a href="print_ats_report.php?id=<?= $appId ?>" target="_blank" class="btn btn-secondary"><i class="fa-solid fa-print"></i> Print / PDF</a>
</div>

<!-- Top: score + recommendation + radar -->
<div class="sh-grid-report">
  <div class="card" style="text-align:center;padding:24px">
    <div class="ats-score" style="--pct:<?= (int)$R['breakdown']['ats_score'] ?>;--ring:<?= $R['breakdown']['ats_score']>=75?'#10b981':($R['breakdown']['ats_score']>=50?'#3b82f6':'#f59e0b') ?>;width:130px;height:130px;font-size:40px;margin:0 auto">
      <span style="width:104px;height:104px"><?= (int)$R['breakdown']['ats_score'] ?></span></div>
    <div style="margin-top:12px;font-weight:800;font-size:15px">ATS Match Score</div>
    <div class="sh-muted" style="font-size:12.5px">Final (with interview): <strong style="color:var(--accent-light)"><?= (int)$R['final_score'] ?></strong></div>
  </div>
  <div class="card" style="padding:22px;display:flex;flex-direction:column;justify-content:center">
    <div class="sh-muted" style="font-size:11px;letter-spacing:1px;text-transform:uppercase;margin-bottom:8px">Recruiter Recommendation</div>
    <div class="stage-badge stage-<?= $rec['color'] ?>" style="font-size:15px;align-self:flex-start;margin-bottom:10px"><i class="fa-solid fa-thumbs-up"></i> <?= e($rec['band']) ?></div>
    <p style="font-size:13.5px;color:var(--text-secondary);margin:0 0 14px"><?= e($rec['text']) ?></p>
    <div class="sh-flex" style="gap:8px">
      <span class="sh-chip"><i class="fa-solid fa-percent"></i> Ranking uses final score</span>
    </div>
  </div>
  <div class="card" style="padding:18px;text-align:center">
    <div class="sh-muted" style="font-size:11px;letter-spacing:1px;text-transform:uppercase;margin-bottom:6px">Competency Radar</div>
    <?= $radar ?>
  </div>
</div>

<!-- Probability gauges -->
<div class="sh-grid sh-grid-4 sh-mt animate-in">
  <?= gauge('Hiring Probability', (int)$R['hire_prob'], 'blended score model') ?>
  <?= gauge('Interview Probability', (int)$R['interview_prob'], 'from ATS score') ?>
  <?= gauge('JD Keyword Match', (int)$R['jd_match'], $R['keyword']['coverage'].'% of job keywords') ?>
  <?= gauge('ATS Compatibility', (int)$R['ats_compat'], 'parser-friendliness') ?>
</div>

<div class="sh-grid-2 sh-mt">
  <!-- Match breakdown -->
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-chart-simple"></i> Match Breakdown</h3></div>
    <div class="card-body">
      <?= bar('Skill Match', (int)$R['breakdown']['skill_match'], 'fa-code') ?>
      <?= bar('Experience Match', (int)$R['breakdown']['experience_match'], 'fa-clock') ?>
      <?= bar('Education Match', (int)$R['breakdown']['education_match'], 'fa-graduation-cap') ?>
      <?= bar('Resume Quality', (int)$R['breakdown']['resume_quality'], 'fa-file-lines') ?>
      <?= bar('Keyword Coverage', (int)$R['jd_match'], 'fa-key') ?>
      <?= bar('Formatting', (int)$R['formatting']['score'], 'fa-table-cells') ?>
      <?= bar('Readability', (int)$R['readability'], 'fa-book-open') ?>
    </div>
  </div>

  <!-- Skills -->
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-list-check"></i> Skill Coverage</h3></div>
    <div class="card-body">
      <div class="sh-muted" style="font-size:12px;margin-bottom:6px">MATCHED SKILLS (<?= count($R['matched_skills']) ?>)</div>
      <div class="skill-tags sh-mb">
        <?php foreach ($R['matched_skills'] as $sk): ?><span class="stage-badge stage-green"><i class="fa-solid fa-check"></i> <?= e(ucfirst($sk)) ?></span><?php endforeach; ?>
        <?php if (!$R['matched_skills']): ?><span class="sh-muted" style="font-size:13px">None detected.</span><?php endif; ?>
      </div>
      <div class="sh-muted" style="font-size:12px;margin-bottom:6px">MISSING SKILLS (<?= count($R['missing_skills']) ?>)</div>
      <div class="skill-tags">
        <?php foreach ($R['missing_skills'] as $sk): ?><span class="stage-badge stage-rose"><i class="fa-solid fa-xmark"></i> <?= e(ucfirst($sk)) ?></span><?php endforeach; ?>
        <?php if (!$R['missing_skills']): ?><span class="stage-badge stage-green"><i class="fa-solid fa-check"></i> All required skills present</span><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Keyword coverage -->
<div class="card sh-mt">
  <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-key"></i> Keyword Coverage <span class="sh-muted" style="font-weight:500">(<?= (int)$R['jd_match'] ?>% of job keywords found)</span></h3></div>
  <div class="card-body">
    <div class="sh-muted" style="font-size:12px;margin-bottom:6px">FOUND IN RESUME</div>
    <div class="skill-tags sh-mb"><?php foreach ($R['keyword']['matched'] as $kw): ?><span class="skill-tag"><?= e($kw) ?></span><?php endforeach; ?><?php if(!$R['keyword']['matched']):?><span class="sh-muted" style="font-size:13px">—</span><?php endif;?></div>
    <div class="sh-muted" style="font-size:12px;margin-bottom:6px">MISSING FROM RESUME</div>
    <div class="skill-tags"><?php foreach ($R['keyword']['missing'] as $kw): ?><span class="sh-chip" style="opacity:.75"><?= e($kw) ?></span><?php endforeach; ?><?php if(!$R['keyword']['missing']):?><span class="sh-muted" style="font-size:13px">Full coverage 🎉</span><?php endif;?></div>
  </div>
</div>

<div class="sh-grid-2 sh-mt">
  <!-- Strengths / weaknesses -->
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-scale-balanced"></i> Strengths &amp; Weaknesses</h3></div>
    <div class="card-body">
      <div class="sh-muted" style="font-size:12px;margin-bottom:8px">STRENGTHS</div>
      <?php foreach ($R['strengths'] as $s): ?>
        <div class="sh-between" style="margin-bottom:6px"><span style="font-size:13px"><i class="fa-solid fa-circle-check" style="color:#10b981"></i> <?= e($s['label']) ?></span><strong style="color:#10b981"><?= $s['value'] ?>%</strong></div>
      <?php endforeach; if(!$R['strengths']): ?><p class="sh-muted" style="font-size:13px">No standout strengths yet.</p><?php endif; ?>
      <div class="sh-muted" style="font-size:12px;margin:14px 0 8px">WEAKNESSES</div>
      <?php foreach ($R['weaknesses'] as $w): ?>
        <div class="sh-between" style="margin-bottom:6px"><span style="font-size:13px"><i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b"></i> <?= e($w['label']) ?></span><strong style="color:#f59e0b"><?= $w['value'] ?>%</strong></div>
      <?php endforeach; if(!$R['weaknesses']): ?><p class="sh-muted" style="font-size:13px">No major weaknesses detected.</p><?php endif; ?>
    </div>
  </div>

  <!-- Formatting checklist + suggestions -->
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-clipboard-check"></i> Resume Checks &amp; Suggestions</h3></div>
    <div class="card-body">
      <?php foreach ($R['formatting']['checks'] as $label=>$pass): ?>
        <div style="font-size:13px;margin-bottom:5px"><i class="fa-solid <?= $pass?'fa-circle-check':'fa-circle-xmark' ?>" style="color:<?= $pass?'#10b981':'#f43f5e' ?>"></i> <?= e($label) ?></div>
      <?php endforeach; ?>
      <div class="sh-muted" style="font-size:12px;margin:14px 0 8px">IMPROVEMENT SUGGESTIONS</div>
      <ul style="margin:0;padding-left:18px;font-size:13px;color:var(--text-secondary);line-height:1.7">
        <?php foreach ($R['suggestions'] as $sug): ?><li><?= e($sug) ?></li><?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>

<?php
// ═══ ATS V2 — Intelligence sections (semantic engine) ═══
$V = $R['v2']; $g = $V['grade']; $pri = $V['insights']['priority'];
$gcol = ['green'=>'#10b981','blue'=>'#3b82f6','amber'=>'#f59e0b','orange'=>'#fb923c','rose'=>'#f43f5e'][$g['color']] ?? '#3b82f6';
?>
<!-- V2 overview: grade + priority + weighted engine score -->
<div class="card sh-mt" style="border-left:4px solid <?= $gcol ?>">
  <div class="card-body sh-between sh-wrap" style="gap:16px">
    <div class="sh-flex" style="gap:18px;align-items:center">
      <div class="ats-score" style="--pct:<?= (int)$V['overall'] ?>;--ring:<?= $gcol ?>;width:78px;height:78px;font-size:22px"><span style="width:62px;height:62px" data-count="<?= (int)$V['overall'] ?>"><?= (int)$V['overall'] ?></span></div>
      <div>
        <div style="font-weight:800;font-size:16px;color:var(--text-primary)">ATS V2 Engine Score · <span style="color:<?= $gcol ?>"><?= e($g['label']) ?></span></div>
        <div class="sh-muted" style="font-size:12.5px">Semantic ontology matching · configurable weighted ensemble · every point explained below</div>
      </div>
    </div>
    <div style="text-align:right">
      <span class="stage-badge stage-<?= $pri['color'] ?>" style="font-size:13px"><i class="fa-solid fa-flag"></i> <?= e($pri['band']) ?></span>
      <div class="sh-muted" style="font-size:12px;margin-top:6px;max-width:260px"><?= e($pri['action']) ?></div>
    </div>
  </div>
</div>

<!-- V2 weighted component breakdown with WHY -->
<div class="card sh-mt">
  <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-scale-balanced"></i> Score Breakdown — every point explained</h3></div>
  <div class="card-body">
    <div class="table-container"><table class="table sh-rtable">
      <thead><tr><th>Component</th><th>Score</th><th>Weight</th><th>Points</th><th>Why</th></tr></thead>
      <tbody>
      <?php foreach ($V['components'] as $c): ?>
        <tr>
          <td data-label="Component"><strong><?= e($c['label']) ?></strong></td>
          <td data-label="Score"><span class="score-bar" style="display:inline-block;width:90px;vertical-align:middle;margin-right:8px"><i style="width:<?= (int)$c['score'] ?>%"></i></span><?= (int)$c['score'] ?>%</td>
          <td data-label="Weight"><?= $c['weight'] ?>%</td>
          <td data-label="Points"><strong style="color:var(--accent-light)"><?= $c['points'] ?></strong></td>
          <td data-label="Why" style="font-size:12.5px;max-width:380px">
            <?php foreach (array_slice($c['reasons'],0,2) as $r): ?><div style="color:#34d399"><i class="fa-solid fa-plus" style="width:12px"></i> <?= e($r) ?></div><?php endforeach; ?>
            <?php foreach (array_slice($c['deductions'],0,2) as $d): ?><div style="color:#fbbf24"><i class="fa-solid fa-minus" style="width:12px"></i> <?= e($d) ?></div><?php endforeach; ?>
            <?php if (!$c['reasons'] && !$c['deductions']): ?><span class="sh-muted">—</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <tr><td colspan="3" style="text-align:right;font-weight:700">Total</td><td><strong style="color:<?= $gcol ?>"><?= (int)$V['overall'] ?></strong></td><td class="sh-muted" style="font-size:12px">weights configurable via SH_ATS_WEIGHTS_JSON</td></tr>
      </tbody>
    </table></div>
  </div>
</div>

<div class="sh-grid-2 sh-mt">
  <!-- Semantic skills -->
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-diagram-project"></i> Semantic Skill Analysis</h3></div>
    <div class="card-body">
      <div class="sh-muted" style="font-size:12px;margin-bottom:6px">REQUIRED — MATCHED (full credit, incl. aliases)</div>
      <div class="skill-tags sh-mb"><?php foreach ($V['skills']['matched'] as $s): ?><span class="stage-badge stage-green"><i class="fa-solid fa-check"></i> <?= e(ucfirst($s)) ?></span><?php endforeach; if(!$V['skills']['matched']):?><span class="sh-muted" style="font-size:13px">None</span><?php endif;?></div>
      <?php if ($V['skills']['related']): ?>
      <div class="sh-muted" style="font-size:12px;margin-bottom:6px">TRANSFERABLE (50% credit — related technology)</div>
      <div class="sh-mb"><?php foreach ($V['skills']['related'] as $r): ?><div style="font-size:12.5px;margin-bottom:4px"><span class="stage-badge stage-blue"><?= e(ucfirst($r['have'])) ?></span> <span class="sh-muted">covers</span> <strong><?= e(ucfirst($r['need'])) ?></strong> <span class="sh-muted">(<?= e($r['cat']) ?>)</span></div><?php endforeach; ?></div>
      <?php endif; ?>
      <div class="sh-muted" style="font-size:12px;margin-bottom:6px">REQUIRED — MISSING</div>
      <div class="skill-tags sh-mb"><?php foreach ($V['skills']['missing'] as $s): ?><span class="stage-badge stage-rose"><i class="fa-solid fa-xmark"></i> <?= e(ucfirst($s)) ?></span><?php endforeach; if(!$V['skills']['missing']):?><span class="stage-badge stage-green"><i class="fa-solid fa-check"></i> Fully covered</span><?php endif;?></div>
      <?php if ($V['skills']['preferred_hit'] || $V['skills']['preferred_miss']): ?>
      <div class="sh-muted" style="font-size:12px;margin-bottom:6px">PREFERRED / NICE-TO-HAVE</div>
      <div class="skill-tags sh-mb">
        <?php foreach ($V['skills']['preferred_hit'] as $s): ?><span class="skill-tag" style="border-color:rgba(16,185,129,.4)">✓ <?= e(ucfirst($s)) ?></span><?php endforeach; ?>
        <?php foreach ($V['skills']['preferred_miss'] as $s): ?><span class="sh-chip" style="opacity:.7"><?= e(ucfirst($s)) ?></span><?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($V['skills']['extra']): ?>
      <div class="sh-muted" style="font-size:12px;margin-bottom:6px">BONUS SKILLS (not requested)</div>
      <div class="skill-tags"><?php foreach ($V['skills']['extra'] as $s): ?><span class="skill-tag"><?= e(ucfirst($s)) ?></span><?php endforeach; ?></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Certifications + quality detail -->
  <div>
    <div class="card sh-mb">
      <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-certificate"></i> Certifications</h3></div>
      <div class="card-body">
        <?php if ($V['certs']['found']): foreach ($V['certs']['found'] as $c): ?><span class="stage-badge stage-green" style="margin:2px"><i class="fa-solid fa-certificate"></i> <?= e($c) ?></span><?php endforeach; else: ?><span class="sh-muted" style="font-size:13px">No certifications detected on resume.</span><?php endif; ?>
        <?php if ($V['certs']['missing']): ?><div class="sh-muted" style="font-size:12px;margin:10px 0 6px">REQUESTED BY JD — MISSING</div><?php foreach ($V['certs']['missing'] as $c): ?><span class="stage-badge stage-rose" style="margin:2px"><?= e($c) ?></span><?php endforeach; endif; ?>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-pen-nib"></i> Writing Quality (V2)</h3></div>
      <div class="card-body" style="font-size:13px">
        <div class="sh-between"><span>Action verbs</span><strong><?= count($V['quality']['action_verbs']) ?></strong></div>
        <div class="sh-between"><span>Quantified achievements</span><strong><?= (int)$V['quality']['quantified'] ?></strong></div>
        <div class="sh-between"><span>Weak phrases</span><strong style="color:<?= $V['quality']['weak_words']?'#fbbf24':'#34d399' ?>"><?= count($V['quality']['weak_words']) ?></strong></div>
        <div class="sh-between"><span>Buzzwords</span><strong style="color:<?= $V['quality']['buzzwords']?'#fbbf24':'#34d399' ?>"><?= count($V['quality']['buzzwords']) ?></strong></div>
        <div class="sh-between"><span>Passive constructions</span><strong><?= (int)$V['quality']['passive_count'] ?></strong></div>
        <?php if ($V['quality']['suggestions']): ?><div class="sh-muted" style="font-size:12px;margin:10px 0 4px">SUGGESTIONS</div>
        <ul style="margin:0;padding-left:16px;color:var(--text-secondary)"><?php foreach (array_slice($V['quality']['suggestions'],0,4) as $s): ?><li><?= e($s) ?></li><?php endforeach; ?></ul><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Recruiter insights -->
<?php if ($V['insights']['notes']): ?>
<div class="card sh-mt">
  <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-user-tie"></i> Recruiter Insights</h3></div>
  <div class="card-body">
    <?php foreach ($V['insights']['notes'] as $n): ?>
    <div style="margin-bottom:8px"><span class="stage-badge stage-violet"><?= e($n['tag']) ?></span> <span style="font-size:13px;color:var(--text-secondary)"><?= e($n['why']) ?></span></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php renderFooter(); ?>
