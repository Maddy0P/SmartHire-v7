<?php
require_once 'includes/layout.php';
requireLogin();

$cid = (int)($_GET['id'] ?? 0);
$candidate = dbFetchOne("SELECT * FROM candidates WHERE id=?", 'i', $cid);
if (!$candidate) { header('Location: candidates.php'); exit; }

// All test submissions
$testResults = dbFetchAll("SELECT ts.*, ot.title as test_title, ot.duration_minutes, ot.passing_marks
    FROM test_submissions ts JOIN online_tests ot ON ot.id=ts.test_id
    WHERE ts.candidate_id=? AND ts.status IN ('submitted','auto_submitted')
    ORDER BY ts.submitted_at DESC", 'i', $cid);

// Interview results
$intResults = dbFetchAll("SELECT r.*, i.type as int_type, i.mode, i.scheduled_date, i.interviewer, i.notes as int_notes
    FROM results r JOIN interviews i ON i.id=r.interview_id
    WHERE r.candidate_id=? ORDER BY r.created_at DESC", 'i', $cid);

// ATS scans
$atsScans = dbFetchAll("SELECT * FROM resume_scans WHERE candidate_id=? ORDER BY scanned_at DESC LIMIT 3", 'i', $cid);

// All interviews
$interviews = dbFetchAll("SELECT * FROM interviews WHERE candidate_id=? ORDER BY scheduled_date DESC", 'i', $cid);

// Compute aggregate score
$allScores = [];
foreach ($testResults as $tr) $allScores[] = round($tr['percentage']);
foreach ($intResults as $ir) $allScores[] = $ir['overall_score'];
if ($candidate['ai_score'] > 0) $allScores[] = $candidate['ai_score'];
$overallAvg = count($allScores) > 0 ? round(array_sum($allScores) / count($allScores)) : 0;

renderHead('Final Result — ' . $candidate['name']);
renderSidebar('candidates');
?>
<style>
@media print {
  .sidebar,.topbar,.btn,.page-header .btn{display:none!important}
  .main-wrapper{margin:0!important}
  .page-content{padding:0!important}
  body{background:#fff;color:#000}
  .table-card,.stat-card{border:1px solid #ddd!important;background:#fff!important;box-shadow:none!important}
  .stat-value,.page-title{color:#000!important}
  .badge{border:1px solid #999!important;color:#000!important;background:#eee!important}
}
.result-section{background:var(--surface);border:1px solid var(--border);border-radius:14px;margin-bottom:20px;overflow:hidden}
.result-section-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:rgba(255,255,255,0.02)}
.result-section-header h3{margin:0;font-size:15px;color:var(--text)}
.result-section-body{padding:20px}
.score-ring-mini{width:80px;height:80px;position:relative;flex-shrink:0}
.score-ring-mini svg{transform:rotate(-90deg)}
.score-center-mini{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center}
</style>

<div class="page-header">
  <div class="page-header-left">
    <div class="breadcrumb"><a href="dashboard.php">Home</a> <i class="fa-solid fa-chevron-right"></i> <a href="candidates.php">Candidates</a> <i class="fa-solid fa-chevron-right"></i> Final Result</div>
    <h1 class="page-title"><?= htmlspecialchars($candidate['name']) ?> — Final Report</h1>
    <p class="page-subtitle">Complete evaluation across all assessments</p>
  </div>
  <div style="display:flex;gap:8px">
    <a href="candidate_detail.php?candidate_id=<?= $cid ?>" class="btn btn-secondary"><i class="fa-solid fa-user"></i> Profile</a>
    <button onclick="window.print()" class="btn btn-secondary"><i class="fa-solid fa-print"></i> Print</button>
  </div>
</div>

<!-- Overall Score Banner -->
<div style="background:linear-gradient(135deg,<?= $overallAvg>=80?'#10b981,#059669':($overallAvg>=60?'#6366f1,#4338ca':($overallAvg>=40?'#f59e0b,#d97706':'#ef4444,#dc2626')) ?>);border-radius:16px;padding:28px 32px;margin-bottom:24px;display:flex;align-items:center;gap:24px">
  <div>
    <div style="font-size:56px;font-weight:800;color:#fff;line-height:1"><?= $overallAvg ?>%</div>
    <div style="color:rgba(255,255,255,0.8);font-size:14px;margin-top:4px">Overall Score</div>
  </div>
  <div style="flex:1;border-left:1px solid rgba(255,255,255,0.2);padding-left:24px">
    <h2 style="color:#fff;font-size:20px;margin:0 0 6px"><?= htmlspecialchars($candidate['name']) ?></h2>
    <p style="color:rgba(255,255,255,0.8);margin:0 0 4px"><?= htmlspecialchars($candidate['position'] ?? '—') ?></p>
    <p style="color:rgba(255,255,255,0.7);margin:0;font-size:13px"><?= htmlspecialchars($candidate['email']) ?> &nbsp;•&nbsp; <?= htmlspecialchars($candidate['phone'] ?? '—') ?></p>
    <div style="margin-top:10px">
      <span style="background:rgba(255,255,255,0.2);color:#fff;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600">
        <?= ucfirst($candidate['status']) ?>
      </span>
      <span style="background:rgba(255,255,255,0.2);color:#fff;padding:4px 12px;border-radius:20px;font-size:12px;margin-left:6px">
        <?= getScoreLabel($overallAvg) ?>
      </span>
    </div>
  </div>
  <div style="text-align:right;color:rgba(255,255,255,0.7);font-size:13px">
    <div>Tests: <?= count($testResults) ?></div>
    <div>Interviews: <?= count($intResults) ?></div>
    <div>ATS Scans: <?= count($atsScans) ?></div>
    <div>Applied: <?= date('d M Y', strtotime($candidate['created_at'])) ?></div>
  </div>
</div>

<!-- Score Cards -->
<div class="stats-grid" style="margin-bottom:24px">
  <div class="stat-card <?= getScoreColor($candidate['ai_score']) ?>">
    <div class="stat-icon <?= getScoreColor($candidate['ai_score']) ?>"><i class="fa-solid fa-robot"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $candidate['ai_score'] ?>%</div>
      <div class="stat-label">AI Match Score</div>
      <div class="stat-delta up"><?= getScoreLabel($candidate['ai_score']) ?></div>
    </div>
  </div>
  <?php if (!empty($testResults)): $avgTest = round(array_sum(array_column($testResults,'percentage'))/count($testResults)); ?>
  <div class="stat-card <?= getScoreColor($avgTest) ?>">
    <div class="stat-icon <?= getScoreColor($avgTest) ?>"><i class="fa-solid fa-laptop-code"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $avgTest ?>%</div>
      <div class="stat-label">Avg Test Score</div>
      <div class="stat-delta up"><?= count($testResults) ?> test(s)</div>
    </div>
  </div>
  <?php endif; ?>
  <?php if (!empty($intResults)): $avgInt = round(array_sum(array_column($intResults,'overall_score'))/count($intResults)); ?>
  <div class="stat-card <?= getScoreColor($avgInt) ?>">
    <div class="stat-icon <?= getScoreColor($avgInt) ?>"><i class="fa-solid fa-comments"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $avgInt ?>%</div>
      <div class="stat-label">Avg Interview Score</div>
      <div class="stat-delta up"><?= count($intResults) ?> interview(s)</div>
    </div>
  </div>
  <?php endif; ?>
  <?php if (!empty($atsScans)): ?>
  <div class="stat-card <?= getScoreColor($atsScans[0]['ats_score']) ?>">
    <div class="stat-icon <?= getScoreColor($atsScans[0]['ats_score']) ?>"><i class="fa-solid fa-file-magnifying-glass"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $atsScans[0]['ats_score'] ?>%</div>
      <div class="stat-label">Latest ATS Score</div>
      <div class="stat-delta up">Resume scan</div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Test Results -->
<?php if (!empty($testResults)): ?>
<div class="result-section">
  <div class="result-section-header">
    <i class="fa-solid fa-laptop-code" style="color:var(--accent)"></i>
    <h3>Online Test Results</h3>
    <span class="badge badge-blue"><?= count($testResults) ?></span>
  </div>
  <div class="result-section-body">
    <?php foreach ($testResults as $tr):
      $tpct = round($tr['percentage']);
      $tc   = getScoreColor($tpct);
      $pass = $tpct >= ($tr['passing_marks'] ?? 40);
    ?>
    <div style="border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:14px;background:rgba(255,255,255,0.02)">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
        <div>
          <h4 style="margin:0 0 4px;color:var(--text)"><?= htmlspecialchars($tr['test_title']) ?></h4>
          <div style="font-size:12px;color:var(--text-muted)">
            Submitted: <?= $tr['submitted_at'] ? date('d M Y, h:i A',strtotime($tr['submitted_at'])) : '—' ?>
            &nbsp;•&nbsp; Time: <?= $tr['time_taken_mins'] ?>/<?= $tr['duration_minutes'] ?> mins
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:12px">
          <div style="text-align:center">
            <div style="font-size:28px;font-weight:800;color:var(--<?= $tc ?>)"><?= $tpct ?>%</div>
            <div style="font-size:11px;color:var(--text-muted)"><?= $tr['total_score'] ?>/<?= $tr['max_score'] ?> marks</div>
          </div>
          <span class="badge badge-<?= $pass?'green':'rose' ?>"><?= $pass?'PASSED':'FAILED' ?></span>
        </div>
      </div>
      <!-- Score bar -->
      <div style="margin-top:12px;height:8px;background:rgba(255,255,255,0.07);border-radius:99px;overflow:hidden">
        <div style="height:100%;width:<?= $tpct ?>%;background:var(--<?= $tc ?>);border-radius:99px;transition:width 1s"></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Interview Results -->
<?php if (!empty($intResults)): ?>
<div class="result-section">
  <div class="result-section-header">
    <i class="fa-solid fa-comments" style="color:#6366f1"></i>
    <h3>Interview Evaluations</h3>
    <span class="badge badge-violet"><?= count($intResults) ?></span>
  </div>
  <div class="result-section-body">
    <?php foreach ($intResults as $ir): ?>
    <div style="border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:14px;background:rgba(255,255,255,0.02)">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:14px">
        <div>
          <h4 style="margin:0 0 4px;color:var(--text)"><?= ucfirst($ir['int_type']) ?> Interview</h4>
          <div style="font-size:12px;color:var(--text-muted)">
            <?= htmlspecialchars($ir['scheduled_date'] ?? '') ?> &nbsp;•&nbsp; <?= $ir['mode'] ?>
            &nbsp;•&nbsp; Interviewer: <?= htmlspecialchars($ir['interviewer'] ?? 'N/A') ?>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px">
          <span style="font-size:28px;font-weight:800;color:var(--<?= getScoreColor($ir['overall_score']) ?>)"><?= $ir['overall_score'] ?>%</span>
          <span class="badge badge-<?= ['strong_yes'=>'green','yes'=>'blue','maybe'=>'amber','no'=>'rose'][$ir['recommendation']] ?? 'blue' ?>">
            <?= str_replace('_',' ', strtoupper($ir['recommendation'])) ?>
          </span>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px">
        <?php foreach (['Technical'=>$ir['technical_score'],'Communication'=>$ir['communication'],'Problem Solving'=>$ir['problem_solving'],'Cultural Fit'=>$ir['cultural_fit']] as $label=>$val): ?>
        <div style="background:rgba(255,255,255,0.03);border-radius:8px;padding:10px;text-align:center">
          <div style="font-size:18px;font-weight:700;color:var(--<?= getScoreColor($val) ?>)"><?= $val ?></div>
          <div style="font-size:10px;color:var(--text-muted)"><?= $label ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if ($ir['feedback']): ?>
      <div style="background:rgba(255,255,255,0.03);border-radius:8px;padding:10px;font-size:13px;color:var(--text-muted)">
        <i class="fa-solid fa-quote-left" style="color:var(--accent);margin-right:6px"></i><?= htmlspecialchars($ir['feedback']) ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ATS Scans -->
<?php if (!empty($atsScans)): ?>
<div class="result-section">
  <div class="result-section-header">
    <i class="fa-solid fa-file-magnifying-glass" style="color:#f59e0b"></i>
    <h3>ATS Resume Scans</h3>
    <span class="badge badge-amber"><?= count($atsScans) ?></span>
  </div>
  <div class="result-section-body">
    <?php foreach ($atsScans as $scan): ?>
    <div style="border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:14px;background:rgba(255,255,255,0.02)">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
        <div>
          <h4 style="margin:0 0 4px;color:var(--text)">ATS Score: <?= $scan['ats_score'] ?>%</h4>
          <div style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($scan['position_applied'] ?? '—') ?> &nbsp;•&nbsp; <?= date('d M Y', strtotime($scan['scanned_at'])) ?></div>
        </div>
        <span class="badge badge-<?= getScoreColor($scan['ats_score']) ?>" style="font-size:16px;padding:6px 14px"><?= $scan['ats_score'] ?>%</span>
      </div>
      <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:8px">
        <?php
        $cats = ['Contact'=>$scan['contact_score'],'Keywords'=>$scan['keyword_score'],'Format'=>$scan['format_score'],'Experience'=>$scan['experience_score'],'Education'=>$scan['education_score'],'Action Verbs'=>$scan['action_verb_score']];
        foreach ($cats as $cl => $cv): ?>
        <div style="background:rgba(255,255,255,0.03);border-radius:8px;padding:8px;text-align:center">
          <div style="font-size:16px;font-weight:700;color:var(--<?= getScoreColor($cv) ?>)"><?= $cv ?></div>
          <div style="font-size:9px;color:var(--text-muted)"><?= $cl ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- No data fallback -->
<?php if (empty($testResults) && empty($intResults) && empty($atsScans)): ?>
<div style="text-align:center;padding:60px;color:var(--text-muted)">
  <i class="fa-solid fa-clipboard-list" style="font-size:48px;display:block;margin-bottom:16px;opacity:.3"></i>
  <h3>No Assessment Data Yet</h3>
  <p>This candidate hasn't completed any tests or interviews yet.</p>
</div>
<?php endif; ?>

<?php renderFooter(); ?>
