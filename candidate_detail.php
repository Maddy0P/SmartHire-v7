<?php
require_once 'includes/layout.php';
requireLogin();

$cid          = (int)($_GET['candidate_id'] ?? 0);
$interview_id = (int)($_GET['interview_id'] ?? 0);
if (!$cid) { header('Location: candidates.php'); exit; }

$candidate = dbFetchOne("SELECT * FROM candidates WHERE id=?", 'i', $cid);
if (!$candidate) { header('Location: candidates.php'); exit; }

// ── Fetch all interviews ──────────────────────────────────
$interviews = dbFetchAll("SELECT * FROM interviews WHERE candidate_id=? ORDER BY scheduled_date DESC", 'i', $cid);

// ── Main result ───────────────────────────────────────────
$result = dbFetchOne("
    SELECT r.*, i.type AS iv_type, i.scheduled_date, i.mode, i.interviewer
    FROM results r JOIN interviews i ON i.id=r.interview_id
    WHERE r.candidate_id=?
    ORDER BY r.created_at DESC LIMIT 1", 'i', $cid);

// ── Question responses ─────────────────────────────────────
$responses = dbFetchAll("
    SELECT cr.*, iq.question, iq.category, iq.max_score, iq.difficulty
    FROM candidate_responses cr
    JOIN interview_questions iq ON iq.id=cr.question_id
    WHERE cr.candidate_id=?
    ORDER BY iq.category, iq.difficulty", 'i', $cid);

// ── Latest ATS scan ────────────────────────────────────────
$atsScan = dbFetchOne("SELECT * FROM resume_scans WHERE candidate_id=? ORDER BY scanned_at DESC LIMIT 1", 'i', $cid);

// ── Overall computed score ─────────────────────────────────
$questionAvg = 0;
if (!empty($responses)) {
    $totalScored = array_sum(array_column($responses,'score_given'));
    $totalMax    = array_sum(array_column($responses,'max_score'));
    $questionAvg = $totalMax > 0 ? round($totalScored / $totalMax * 100) : 0;
}
$aiScore  = (int)$candidate['ai_score'];
$atsScore = $atsScan ? (int)$atsScan['ats_score'] : 0;
$ivScore  = $result  ? (int)$result['overall_score'] : 0;

// Final composite score
$weights = ['ai'=>0.2,'ats'=>0.25,'interview'=>0.35,'questions'=>0.2];
$finalScore = 0;
if ($ivScore)     $finalScore += $ivScore * $weights['interview'];
if ($aiScore)     $finalScore += $aiScore * $weights['ai'];
if ($atsScore)    $finalScore += $atsScore * $weights['ats'];
if ($questionAvg) $finalScore += $questionAvg * $weights['questions'];
$finalScore = (int)round($finalScore);
if (!$ivScore && !$atsScore && !$questionAvg) $finalScore = $aiScore;

$recMap = ['strong_yes'=>['✅ Strong Hire','green'],'yes'=>['👍 Recommend Hire','blue'],
           'maybe'=>['🤔 Borderline','amber'],'no'=>['❌ Do Not Hire','rose']];
[$recLabel,$recColor] = $recMap[$result['recommendation'] ?? ''] ?? ['—',''];

$catColors = ['technical'=>'blue','hr'=>'violet','behavioral'=>'amber','system_design'=>'rose','coding'=>'green'];

// ── v8: queries hoisted verbatim from the former markup region ───────────────
$testSubs = dbFetchAll("
    SELECT ts.*, ot.title AS test_title, ot.duration_minutes, ot.passing_marks, ot.total_marks AS test_total
    FROM test_submissions ts JOIN online_tests ot ON ot.id=ts.test_id
    WHERE ts.candidate_id=? AND ts.status IN ('submitted','auto_submitted')
    ORDER BY ts.submitted_at DESC", 'i', $cid);
$testAvg = !empty($testSubs) ? round(array_sum(array_column($testSubs,'percentage'))/count($testSubs)) : 0;
$qAnalyticsBySub = [];
if (!empty($testSubs)) {
    $subIds = array_column($testSubs, 'id');
    $placeholders = implode(',', array_fill(0, count($subIds), '?'));
    $allQA = dbFetchAll(
        "SELECT ta.submission_id, ta.time_spent_secs, ta.is_correct, iq.question_type
         FROM test_answers ta JOIN interview_questions iq ON iq.id=ta.question_id
         WHERE ta.submission_id IN ($placeholders)",
        str_repeat('i', count($subIds)), ...$subIds);
    foreach ($allQA as $row) { $qAnalyticsBySub[$row['submission_id']][] = $row; }
}

// ── v8 presentation additions (read-only) ────────────────────────────────────
// Stage events across this candidate's applications (timeline)
$appEvents = dbFetchAll("
    SELECT ae.to_stage, ae.note, ae.actor_role, ae.created_at, j.title AS job_title
    FROM application_events ae
    JOIN job_applications a ON a.id = ae.application_id
    JOIN jobs j ON j.id = a.job_id
    WHERE a.candidate_id = ?
    ORDER BY ae.created_at DESC LIMIT 30", 'i', $cid);
$apps = dbFetchAll("
    SELECT a.id, a.stage, a.final_score, a.applied_at, j.title AS job_title
    FROM job_applications a JOIN jobs j ON j.id = a.job_id
    WHERE a.candidate_id = ? ORDER BY a.applied_at DESC", 'i', $cid);

// Unified timeline (merged in PHP, newest first)
$tl = [];
$tl[] = ['t' => strtotime($candidate['created_at']), 'label' => 'Added to pipeline', 'sub' => ''];
foreach ($apps as $a)       $tl[] = ['t' => strtotime($a['applied_at']),  'label' => 'Applied — ' . $a['job_title'], 'sub' => ''];
foreach ($appEvents as $ev) $tl[] = ['t' => strtotime($ev['created_at']), 'label' => sh_stage_label($ev['to_stage']) . ' — ' . $ev['job_title'],
                                     'sub' => trim(($ev['actor_role'] ? 'by ' . $ev['actor_role'] : '') . ($ev['note'] ? ' · "' . $ev['note'] . '"' : ''))];
foreach ($interviews as $iv)$tl[] = ['t' => strtotime($iv['scheduled_date']), 'label' => ucfirst($iv['type']) . ' interview ' . ($iv['status'] === 'completed' ? 'completed' : $iv['status']),
                                     'sub' => ($iv['interviewer'] ? 'with ' . $iv['interviewer'] : '')];
if ($atsScan)               $tl[] = ['t' => strtotime($atsScan['scanned_at']), 'label' => 'ATS resume scan — ' . (int)$atsScan['ats_score'] . '%', 'sub' => ''];
foreach ($testSubs as $ts)  $tl[] = ['t' => strtotime($ts['submitted_at']), 'label' => 'Test submitted — ' . $ts['test_title'], 'sub' => (int)$ts['percentage'] . '%'];
if ($result)                $tl[] = ['t' => strtotime($result['created_at']), 'label' => 'Interview scored — ' . (int)$result['overall_score'] . '%', 'sub' => $recLabel !== '—' ? $recLabel : ''];
usort($tl, fn($x, $y) => $y['t'] <=> $x['t']);

$needsPdfLibs = true;
require_once 'includes/recruitment.php';
renderHead('Candidate — ' . $candidate['name'], true);
renderSidebar('candidates');
$score = fn(?int $v) => $v === null ? '<span class="sh-text-muted">—</span>'
    : '<div class="sh-score"><div class="sh-score-track"><div class="sh-score-fill ' . ($v >= 75 ? 'hi' : ($v >= 50 ? 'mid' : 'lo')) . '" style="width:' . $v . '%"></div></div><span class="sh-score-n sh-tnum">' . $v . '%</span></div>';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" defer></script>

<div id="reportContent">

<!-- ── Header (hero surface) ── -->
<section class="sh-card sh-card-hero sh-mb-6" aria-label="Candidate header">
  <div class="sh-flex sh-gap-4 sh-wrap sh-items-center">
    <span class="sh-avatar sh-avatar-lg" aria-hidden="true"><?= strtoupper(substr($candidate['name'], 0, 1)) ?></span>
    <div class="sh-flex-1">
      <h1 class="sh-page-title"><?= e($candidate['name']) ?></h1>
      <p class="sh-page-sub"><?= e($candidate['position']) ?> · <span class="sh-mono"><?= e($candidate['email']) ?></span><?= $candidate['phone'] ? ' · <span class="sh-mono">' . e($candidate['phone']) . '</span>' : '' ?></p>
      <div class="sh-flex sh-gap-2 sh-mt-2 sh-wrap sh-items-center">
        <?= sh_status_badge($candidate['status']) ?>
        <span class="sh-ai-chip" title="Composite of resume, ATS, interview and test scores computed by the SmartHire engine">AI composite <?= $finalScore ?>%</span>
        <?php if ($atsScan): ?><span class="sh-badge sh-badge-info">ATS <?= $atsScore ?>%</span><?php endif; ?>
        <?php if ($recLabel !== '—'): ?><span class="sh-badge sh-badge-neutral" title="Interviewer recommendation (human-entered)"><?= e(preg_replace('/^[^ ]+ /', '', $recLabel)) ?></span><?php endif; ?>
      </div>
    </div>
    <div class="sh-flex sh-gap-2 sh-wrap">
      <a href="candidates.php?action=edit&id=<?= $cid ?>" class="sh-btn sh-btn-secondary sh-btn-sm"><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit</a>
      <a href="candidate_final_result.php?id=<?= $cid ?>" class="sh-btn sh-btn-secondary sh-btn-sm"><i class="fa-solid fa-chart-bar" aria-hidden="true"></i> Final result</a>
      <button id="pdfBtn" onclick="downloadPDF()" class="sh-btn sh-btn-primary sh-btn-sm"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i> Download PDF</button>
    </div>
  </div>
</section>

<!-- ── Tabs ── -->
<div class="sh-tabs" role="tablist" aria-label="Candidate sections">
  <?php $tabs = ['overview'=>'Overview','ats'=>'ATS report','interviews'=>'Interviews','tests'=>'Tests','timeline'=>'Timeline','resume'=>'Resume & notes'];
  $first = true; foreach ($tabs as $k => $lbl): ?>
  <button class="sh-tab" role="tab" id="tab-<?= $k ?>" aria-controls="panel-<?= $k ?>" aria-selected="<?= $first ? 'true' : 'false' ?>" <?= $first ? '' : 'tabindex="-1"' ?>><?= $lbl ?></button>
  <?php $first = false; endforeach; ?>
</div>

<!-- ── Overview ── -->
<section class="sh-tabpanel" id="panel-overview" role="tabpanel" aria-labelledby="tab-overview">
  <div class="sh-tier2">
    <div class="sh-card">
      <div class="sh-card-header"><div><h2 class="sh-card-title">Score composition <span class="sh-ai-chip" title="Weights: interview 35% · ATS 25% · resume 20% · tests 20%">AI</span></h2>
      <p class="sh-card-sub">How the <?= $finalScore ?>% composite is built — every input is inspectable in its tab</p></div></div>
      <?php foreach ([['Interview score', $ivScore ?: null, '35%'], ['ATS scan', $atsScore ?: null, '25%'],
                     ['Resume score', $aiScore ?: null, '20%'], ['Question / test avg', $questionAvg ?: null, '20%']] as [$lbl, $v, $w]): ?>
      <div class="sh-flex sh-items-center sh-gap-3 sh-mt-2">
        <span class="sh-cell-sub sh-w-130"><?= $lbl ?> <span class="sh-text-muted">· <?= $w ?></span></span>
        <div class="sh-flex-1"><?= $score($v) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="sh-card">
      <div class="sh-card-header"><div><h2 class="sh-card-title">Details</h2></div></div>
      <dl class="sh-dl">
        <dt>Position</dt><dd><?= e($candidate['position']) ?></dd>
        <dt>Skills</dt><dd><?= e($candidate['skills'] ?: '—') ?></dd>
        <dt>Added</dt><dd class="sh-tnum"><?= date('d M Y', strtotime($candidate['created_at'])) ?></dd>
        <dt>Applications</dt><dd class="sh-tnum"><?= count($apps) ?></dd>
        <dt>Interviews</dt><dd class="sh-tnum"><?= count($interviews) ?></dd>
        <dt>Tests taken</dt><dd class="sh-tnum"><?= count($testSubs) ?></dd>
      </dl>
    </div>
  </div>
  <?php if ($apps): ?>
  <div class="sh-card sh-card-flush sh-mt-4">
    <div class="sh-card-header"><div><h2 class="sh-card-title">Applications</h2></div></div>
    <div class="sh-table-wrap"><table class="sh-table">
      <thead><tr><th scope="col">Job</th><th scope="col">Stage</th><th scope="col" class="num">Final score</th><th scope="col">Applied</th></tr></thead>
      <tbody><?php foreach ($apps as $a): ?>
        <tr><td data-th="Job" class="sh-cell-main"><?= e($a['job_title']) ?></td>
        <td data-th="Stage"><span class="sh-badge sh-badge-info"><?= sh_stage_label($a['stage']) ?></span></td>
        <td data-th="Final score" class="num sh-tnum"><?= (int)$a['final_score'] ?></td>
        <td data-th="Applied" class="sh-cell-sub"><?= date('d M Y', strtotime($a['applied_at'])) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  </div>
  <?php endif; ?>
</section>

<!-- ── ATS report ── -->
<section class="sh-tabpanel" id="panel-ats" role="tabpanel" aria-labelledby="tab-ats" hidden>
  <?php if (!$atsScan): ?>
  <div class="sh-card"><div class="sh-empty"><div class="sh-empty-icon"><i class="fa-solid fa-file-magnifying-glass" aria-hidden="true"></i></div>
    <h3>No ATS scan yet</h3><p>Run this candidate's resume through the ATS Scanner to see the evidence-linked breakdown here.</p>
    <a href="resume_scanner.php" class="sh-btn sh-btn-primary sh-mt-2">Open ATS Scanner</a></div></div>
  <?php else: ?>
  <div class="sh-card">
    <div class="sh-card-header"><div><h2 class="sh-card-title">ATS scan — <?= $atsScore ?>% <span class="sh-ai-chip" title="Computed by the SmartHire ATS engine">AI</span></h2>
    <p class="sh-card-sub">Scanned <?= date('d M Y', strtotime($atsScan['scanned_at'])) ?> · every dimension below is evidence-based, not predictive</p></div></div>
    <?php foreach ([['Keyword match', 'keyword_score'], ['Experience', 'experience_score'], ['Education', 'education_score'],
                   ['Formatting', 'format_score'], ['Contact info', 'contact_score'], ['Action verbs', 'action_verb_score']] as [$lbl, $col]): ?>
    <div class="sh-flex sh-items-center sh-gap-3 sh-mt-2">
      <span class="sh-cell-sub sh-w-130"><?= $lbl ?></span>
      <div class="sh-flex-1"><?= $score(isset($atsScan[$col]) ? (int)$atsScan[$col] : null) ?></div>
    </div>
    <?php endforeach; ?>
    <div class="sh-tier2 sh-mt-4">
      <div><h3 class="sh-card-title sh-panel-title">Matched keywords</h3>
        <div class="sh-flex sh-gap-2 sh-wrap sh-mt-2"><?php $mk = array_filter(array_map('trim', explode(',', (string)$atsScan['matched_keywords'])));
          foreach ($mk as $kw): ?><span class="sh-chip"><?= e($kw) ?></span><?php endforeach; if (!$mk): ?><span class="sh-text-muted">None recorded</span><?php endif; ?></div></div>
      <div><h3 class="sh-card-title sh-panel-title">Missing keywords</h3>
        <div class="sh-flex sh-gap-2 sh-wrap sh-mt-2"><?php $sk = array_filter(array_map('trim', explode(',', (string)$atsScan['missing_keywords'])));
          foreach ($sk as $kw): ?><span class="sh-chip sh-chip-danger"><?= e($kw) ?></span><?php endforeach; if (!$sk): ?><span class="sh-text-muted">None — full coverage</span><?php endif; ?></div></div>
    </div>
    <?php if (!empty($atsScan['recommendations'])): ?>
    <h3 class="sh-card-title sh-panel-title sh-mt-4">Engine recommendations</h3>
    <p class="sh-text-2 sh-mt-2"><?= nl2br(e($atsScan['recommendations'])) ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</section>

<!-- ── Interviews ── -->
<section class="sh-tabpanel" id="panel-interviews" role="tabpanel" aria-labelledby="tab-interviews" hidden>
  <?php if (!$interviews): ?>
  <div class="sh-card"><div class="sh-empty"><div class="sh-empty-icon"><i class="fa-regular fa-calendar" aria-hidden="true"></i></div>
    <h3>No interviews yet</h3><p>Scheduled and completed interviews for this candidate will appear here.</p>
    <a href="interviews.php" class="sh-btn sh-btn-primary sh-mt-2">Schedule an interview</a></div></div>
  <?php else: ?>
  <div class="sh-card sh-card-flush">
    <div class="sh-card-header"><div><h2 class="sh-card-title">Interview history</h2></div></div>
    <div class="sh-table-wrap"><table class="sh-table">
      <thead><tr><th scope="col">Date</th><th scope="col">Type</th><th scope="col">Mode</th><th scope="col">Interviewer</th><th scope="col">Status</th></tr></thead>
      <tbody><?php foreach ($interviews as $iv): ?>
      <tr><td data-th="Date" class="sh-tnum"><?= date('d M Y', strtotime($iv['scheduled_date'])) ?><span class="sh-cell-sub sh-block"><?= date('g:i A', strtotime($iv['scheduled_time'])) ?></span></td>
      <td data-th="Type"><?= e(ucfirst($iv['type'])) ?></td>
      <td data-th="Mode"><?= e(ucfirst($iv['mode'] ?? '—')) ?></td>
      <td data-th="Interviewer"><?= e($iv['interviewer'] ?: '—') ?></td>
      <td data-th="Status"><span class="sh-badge sh-badge-<?= $iv['status'] === 'completed' ? 'success' : ($iv['status'] === 'cancelled' ? 'danger' : 'info') ?>"><?= e($iv['status']) ?></span></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  </div>
  <?php if ($result): ?>
  <div class="sh-card sh-mt-4">
    <div class="sh-card-header"><div><h2 class="sh-card-title">Latest scored interview — <?= (int)$result['overall_score'] ?>%</h2>
    <p class="sh-card-sub"><?= e(ucfirst($result['iv_type'])) ?> · <?= date('d M Y', strtotime($result['scheduled_date'])) ?> · recommendation (interviewer): <?= e(preg_replace('/^[^ ]+ /', '', $recLabel)) ?></p></div></div>
    <?php if ($responses): $byCat = [];
      foreach ($responses as $r) { $byCat[$r['category']]['got'] = ($byCat[$r['category']]['got'] ?? 0) + (int)$r['score_given'];
                                   $byCat[$r['category']]['max'] = ($byCat[$r['category']]['max'] ?? 0) + (int)$r['max_score']; }
      foreach ($byCat as $cat => $v): $pct = $v['max'] ? (int)round($v['got'] / $v['max'] * 100) : 0; ?>
    <div class="sh-flex sh-items-center sh-gap-3 sh-mt-2">
      <span class="sh-cell-sub sh-w-130"><?= e(ucwords(str_replace('_', ' ', $cat))) ?></span>
      <div class="sh-flex-1"><?= $score($pct) ?></div>
    </div>
    <?php endforeach; endif; ?>
    <?php if (!empty($result['strengths'])): ?><h3 class="sh-card-title sh-panel-title sh-mt-4">Strengths (interviewer notes)</h3><p class="sh-text-2 sh-mt-2"><?= nl2br(e($result['strengths'])) ?></p><?php endif; ?>
    <?php if (!empty($result['weaknesses'])): ?><h3 class="sh-card-title sh-panel-title sh-mt-4">Areas of concern (interviewer notes)</h3><p class="sh-text-2 sh-mt-2"><?= nl2br(e($result['weaknesses'])) ?></p><?php endif; ?>
  </div>
  <?php endif; endif; ?>
</section>

<!-- ── Tests ── -->
<section class="sh-tabpanel" id="panel-tests" role="tabpanel" aria-labelledby="tab-tests" hidden>
  <?php if (!$testSubs): ?>
  <div class="sh-card"><div class="sh-empty"><div class="sh-empty-icon"><i class="fa-solid fa-laptop-code" aria-hidden="true"></i></div>
    <h3>No completed tests</h3><p>Online test submissions will appear here with per-question analytics.</p>
    <a href="online_tests.php" class="sh-btn sh-btn-primary sh-mt-2">Assign a test</a></div></div>
  <?php else: ?>
  <?php foreach ($testSubs as $ts): $qa = $qAnalyticsBySub[$ts['id']] ?? [];
    $correct = count(array_filter($qa, fn($x) => $x['is_correct']));
    $avgT = $qa ? (int)round(array_sum(array_column($qa, 'time_spent_secs')) / count($qa)) : 0;
    $passed = (int)$ts['obtained_marks'] >= (int)$ts['passing_marks']; ?>
  <div class="sh-card sh-mb-4">
    <div class="sh-card-header">
      <div><h2 class="sh-card-title"><?= e($ts['test_title']) ?> — <?= (int)$ts['percentage'] ?>%</h2>
      <p class="sh-card-sub">Submitted <?= date('d M Y', strtotime($ts['submitted_at'])) ?> · <?= (int)$ts['obtained_marks'] ?>/<?= (int)$ts['test_total'] ?> marks · pass mark <?= (int)$ts['passing_marks'] ?></p></div>
      <span class="sh-badge sh-badge-<?= $passed ? 'success' : 'danger' ?>"><?= $passed ? 'Passed' : 'Not passed' ?></span>
    </div>
    <div class="sh-flex sh-items-center sh-gap-3"><span class="sh-cell-sub sh-w-130">Score</span><div class="sh-flex-1"><?= $score((int)$ts['percentage']) ?></div></div>
    <?php if ($qa): ?>
    <p class="sh-cell-sub sh-mt-2"><span class="sh-tnum"><?= $correct ?>/<?= count($qa) ?></span> questions correct · avg <span class="sh-tnum"><?= $avgT ?>s</span> per question</p>
    <?php endif; ?>
  </div>
  <?php endforeach; endif; ?>
</section>

<!-- ── Timeline ── -->
<section class="sh-tabpanel" id="panel-timeline" role="tabpanel" aria-labelledby="tab-timeline" hidden>
  <div class="sh-card">
    <div class="sh-card-header"><div><h2 class="sh-card-title">Activity timeline</h2>
    <p class="sh-card-sub">Applications, stage moves, interviews, scans and tests — newest first</p></div></div>
    <ul class="sh-timeline">
      <?php foreach ($tl as $ev): ?>
      <li><strong><?= date('d M Y', $ev['t']) ?></strong> — <?= e($ev['label']) ?><?= $ev['sub'] ? ' <span class="sh-cell-sub">' . e($ev['sub']) . '</span>' : '' ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<!-- ── Resume & notes ── -->
<section class="sh-tabpanel" id="panel-resume" role="tabpanel" aria-labelledby="tab-resume" hidden>
  <div class="sh-tier2">
    <div class="sh-card">
      <div class="sh-card-header"><div><h2 class="sh-card-title">Resume notes</h2>
      <p class="sh-card-sub">Free-text notes stored on the candidate record</p></div>
      <a href="candidates.php?action=edit&id=<?= $cid ?>" class="sh-btn sh-btn-ghost sh-btn-sm">Edit</a></div>
      <p class="sh-text-2"><?= $candidate['resume_note'] ? nl2br(e($candidate['resume_note'])) : '<span class="sh-text-muted">No notes yet — add them via Edit.</span>' ?></p>
    </div>
    <div class="sh-card">
      <div class="sh-card-header"><div><h2 class="sh-card-title">Skills</h2></div></div>
      <div class="sh-flex sh-gap-2 sh-wrap">
        <?php $skl = array_filter(array_map('trim', explode(',', (string)$candidate['skills'])));
        foreach ($skl as $s): ?><span class="sh-chip"><?= e($s) ?></span><?php endforeach;
        if (!$skl): ?><span class="sh-text-muted">No skills recorded.</span><?php endif; ?>
      </div>
      <?php if ($atsScan && !empty($atsScan['raw_text'])): ?>
      <h3 class="sh-card-title sh-panel-title sh-mt-4">Scanned resume text</h3>
      <p class="sh-cell-sub">Extracted by the ATS parser (first 5,000 characters)</p>
      <pre class="sh-resume-text"><?= e($atsScan['raw_text']) ?></pre>
      <?php endif; ?>
    </div>
  </div>
</section>

</div><!-- /reportContent -->

<script>
// ── PDF Download via html2canvas + jsPDF (contract preserved; light bg) ──────
async function downloadPDF() {
  const btn = document.getElementById('pdfBtn');
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating…';
  btn.disabled = true;
  // Reveal all tab panels for capture, restore after
  const panels = document.querySelectorAll('.sh-tabpanel');
  const hiddenBefore = [];
  panels.forEach(p => { hiddenBefore.push(p.hidden); p.hidden = false; });
  try {
    const { jsPDF } = window.jspdf;
    const content = document.getElementById('reportContent');
    const canvas  = await html2canvas(content, { scale:1.5, backgroundColor:'#F8FAFC', useCORS:true, logging:false });
    const imgData = canvas.toDataURL('image/png');
    const pdf     = new jsPDF({ orientation:'portrait', unit:'mm', format:'a4' });
    const pdfW    = pdf.internal.pageSize.getWidth();
    const pdfH    = pdf.internal.pageSize.getHeight();
    const imgH    = (canvas.height * pdfW) / canvas.width;
    let pos = 0;
    pdf.addImage(imgData,'PNG',0,pos,pdfW,imgH);
    let remaining = imgH - pdfH;
    while (remaining > 0) { pdf.addPage(); pos -= pdfH; pdf.addImage(imgData,'PNG',0,pos,pdfW,imgH); remaining -= pdfH; }
    pdf.save('SmartHire_<?= preg_replace('/[^a-z0-9]/i', '_', $candidate['name']) ?>_Report.pdf');
  } catch(e) { alert('PDF generation failed. Try Print/Save instead.'); }
  panels.forEach((p, i) => { p.hidden = hiddenBefore[i]; });
  btn.innerHTML = '<i class="fa-solid fa-file-pdf"></i> Download PDF Report';
  btn.disabled  = false;
}
</script>

<?php renderFooter(); ?>
