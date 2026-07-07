<?php
require_once 'includes/config.php';
requireCandidateLogin();
$cand = currentCandidate();
$tid = (int)($_GET['sub_id'] ?? 0);

$submission = dbFetchOne("SELECT ts.*, ot.title as test_title, ot.passing_marks, ot.duration_minutes
    FROM test_submissions ts JOIN online_tests ot ON ot.id=ts.test_id
    WHERE ot.id=? AND ts.candidate_id=? AND ts.status IN ('submitted','auto_submitted')", 'ii', $tid, $cand['id']);
if (!$submission) { header('Location: candidate_portal.php'); exit; }

$answers = dbFetchAll("SELECT ta.*, iq.question, iq.question_type, iq.correct_option,
    iq.option_a, iq.option_b, iq.option_c, iq.option_d, iq.max_score, iq.category
    FROM test_answers ta JOIN interview_questions iq ON iq.id=ta.question_id
    WHERE ta.submission_id=? ORDER BY ta.id", 'i', $submission['id']);

$mcqAnswers = array_filter($answers, fn($a)=>$a['question_type']==='mcq');
$subjAnswers = array_filter($answers, fn($a)=>$a['question_type']!=='mcq');
$correctMCQ = array_sum(array_column(iterator_to_array($mcqAnswers), 'is_correct'));
$totalTimeSpent = array_sum(array_column($answers,'time_spent_secs'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>My Result — SmartHire</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="assets/css/main.css">
  <style>
    body{background:#0f172a}
    .cp-header{background:linear-gradient(135deg,#7c3aed,#4338ca);padding:18px 28px;display:flex;align-items:center;justify-content:space-between}
    .cp-header h1{font-size:17px;font-weight:700;color:#fff;margin:0}
    .content{max-width:880px;margin:0 auto;padding:28px 20px}
    .card{background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:14px;padding:20px;margin-bottom:16px}
    .a-card{background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);border-radius:12px;padding:16px;margin-bottom:12px}
    .a-card.correct{border-color:rgba(16,185,129,0.3);background:rgba(16,185,129,0.05)}
    .a-card.wrong{border-color:rgba(239,68,68,0.2)}
    .a-card.pending-hr{border-color:rgba(245,158,11,0.3);background:rgba(245,158,11,0.04)}
    .btn-back{background:rgba(255,255,255,0.1);color:#fff;border:1px solid rgba(255,255,255,0.2);padding:8px 16px;border-radius:8px;font-size:13px;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
    .stat-mini{background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.07);border-radius:12px;padding:14px;text-align:center}
    .stat-mini .val{font-size:22px;font-weight:800}
    .stat-mini .lbl{font-size:11px;color:#64748b;margin-top:2px}
    .section-title{color:#94a3b8;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px}
    /* Time bar */
    .t-bar-row{display:flex;align-items:center;gap:10px;margin-bottom:8px}
    .t-bar-bg{flex:1;height:18px;background:rgba(255,255,255,0.06);border-radius:6px;overflow:hidden}
    .t-bar-fill{height:100%;border-radius:6px;display:flex;align-items:center;padding-left:8px}
  </style>
</head>
<body>
<div class="cp-header">
  <h1><i class="fa-solid fa-chart-bar"></i> My Test Result</h1>
  <a href="candidate_portal.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Portal</a>
</div>
<div class="content">

  <?php $pct = round($submission['percentage']); $pass = $pct >= ($submission['passing_marks']??40); ?>
  <!-- Score Card -->
  <div class="card" style="text-align:center;border-color:<?= $pass?'rgba(16,185,129,0.3)':'rgba(239,68,68,0.3)' ?>;margin-bottom:20px">
    <div style="font-size:56px;font-weight:900;color:<?= $pass?'#10b981':'#ef4444' ?>"><?= $pct ?>%</div>
    <div style="font-size:18px;font-weight:600;color:#f1f5f9;margin:4px 0"><?= htmlspecialchars($submission['test_title']) ?></div>
    <div style="color:#64748b;font-size:13px"><?= $submission['total_score'] ?>/<?= $submission['max_score'] ?> marks &nbsp;•&nbsp; <?= $submission['time_taken_mins'] ?> mins</div>
    <div style="margin-top:10px">
      <span style="background:<?= $pass?'rgba(16,185,129,0.2)':'rgba(239,68,68,0.2)' ?>;color:<?= $pass?'#10b981':'#ef4444' ?>;padding:5px 18px;border-radius:20px;font-size:14px;font-weight:700">
        <?= $pass?'✓ PASSED':'✗ FAILED' ?>
      </span>
    </div>
  </div>

  <!-- Mini Stats -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:20px">
    <div class="stat-mini"><div class="val" style="color:#a78bfa"><?= count($mcqAnswers) ?></div><div class="lbl">MCQ Questions</div></div>
    <div class="stat-mini"><div class="val" style="color:#10b981"><?= $correctMCQ ?></div><div class="lbl">MCQ Correct</div></div>
    <div class="stat-mini"><div class="val" style="color:#f59e0b"><?= count($subjAnswers) ?></div><div class="lbl">Subjective Qs</div></div>
    <div class="stat-mini"><div class="val" style="color:#6366f1"><?= $submission['time_taken_mins'] ?>m</div><div class="lbl">Total Time</div></div>
    <?php if ($totalTimeSpent > 0): ?>
    <div class="stat-mini"><div class="val" style="color:#94a3b8"><?= round($totalTimeSpent/max(1,count($answers))) ?>s</div><div class="lbl">Avg / Question</div></div>
    <?php endif; ?>
  </div>

  <!-- Time Spent per Question (Analytics) -->
  <?php if ($totalTimeSpent > 0): ?>
  <div class="card" style="margin-bottom:20px">
    <div class="section-title"><i class="fa-solid fa-chart-line"></i> &nbsp;Time Spent per Question</div>
    <?php
    $maxTs = max(1, max(array_column($answers,'time_spent_secs')));
    foreach ($answers as $i => $ans):
      $ts = (int)($ans['time_spent_secs']??0);
      $w = round($ts/$maxTs*100);
      $col = $ans['question_type']==='mcq'
        ? ($ans['is_correct']?'#10b981':'#ef4444')
        : ($ans['hr_marks']!==null?'#7c3aed':'#f59e0b');
    ?>
    <div class="t-bar-row">
      <span style="width:26px;font-size:11px;font-weight:700;color:#7c3aed">Q<?= $i+1 ?></span>
      <div class="t-bar-bg">
        <div class="t-bar-fill" style="width:<?= $w ?>%;background:<?= $col ?>">
          <span style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.9)"><?= $ts ?>s</span>
        </div>
      </div>
      <span style="font-size:11px;color:#64748b;width:70px;text-align:right">
        <?= $ans['question_type']==='mcq'
          ? ($ans['is_correct']?'✓ Correct':'✗ Wrong')
          : ($ans['hr_marks']!==null?'HR: '.$ans['hr_marks'].'/'.$ans['max_score']:'Pending review') ?>
      </span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Answers -->
  <div class="section-title"><i class="fa-solid fa-list-check"></i> &nbsp;Your Answers</div>
  <?php foreach ($answers as $i => $ans):
    $isSubj = $ans['question_type'] !== 'mcq';
    $cardClass = $isSubj
      ? ($ans['hr_marks']!==null ? ($ans['hr_marks']>0?'correct':'wrong') : 'pending-hr')
      : ($ans['is_correct']?'correct':'wrong');
  ?>
  <div class="a-card <?= $cardClass ?>">
    <div style="display:flex;justify-content:space-between;margin-bottom:8px;align-items:flex-start">
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <span style="font-size:11px;font-weight:700;color:#7c3aed">Q<?= $i+1 ?></span>
        <span class="badge badge-<?= $isSubj?'amber':'violet' ?>" style="font-size:10px"><?= $isSubj?'Subjective':'MCQ' ?></span>
        <?php if (($ans['time_spent_secs']??0)>0): ?>
        <span class="badge badge-blue" style="font-size:10px"><i class="fa-solid fa-clock"></i> <?= $ans['time_spent_secs'] ?>s</span>
        <?php endif; ?>
      </div>
      <div style="text-align:right">
        <?php if ($isSubj): ?>
          <?php if ($ans['hr_marks']!==null): ?>
            <span style="font-size:11px;font-weight:600;color:#a78bfa"><?= $ans['hr_marks'] ?>/<?= $ans['max_score'] ?> marks (HR graded)</span>
          <?php else: ?>
            <span class="badge badge-amber" style="font-size:10px"><i class="fa-solid fa-hourglass"></i> Pending HR review</span>
          <?php endif; ?>
        <?php else: ?>
          <span style="font-size:11px;font-weight:600;color:<?= $ans['is_correct']?'#10b981':'#ef4444' ?>">
            <?= $ans['marks_awarded'] ?>/<?= $ans['max_score'] ?> marks — <?= $ans['is_correct']?'Correct':'Wrong' ?>
          </span>
        <?php endif; ?>
      </div>
    </div>
    <p style="color:#e2e8f0;font-size:14px;margin-bottom:10px"><?= htmlspecialchars($ans['question']) ?></p>

    <?php if (!$isSubj): ?>
      <div style="font-size:13px;color:#94a3b8">
        Your answer: <strong style="color:<?= $ans['is_correct']?'#10b981':'#ef4444' ?>"><?= strtoupper($ans['selected_option']??'—') ?></strong>
        <?php if (!$ans['is_correct']): ?> &nbsp;• Correct: <strong style="color:#10b981"><?= strtoupper($ans['correct_option']) ?></strong><?php endif; ?>
      </div>
    <?php else: ?>
      <div style="background:rgba(255,255,255,0.04);border-radius:8px;padding:10px;font-size:13px;color:#94a3b8;margin-bottom:8px">
        <?= nl2br(htmlspecialchars($ans['answer_text']?:'(no answer provided)')) ?>
      </div>
      <?php if ($ans['hr_feedback']): ?>
      <div style="background:rgba(124,58,237,0.08);border:1px solid rgba(124,58,237,0.2);border-radius:8px;padding:10px">
        <div style="font-size:10px;font-weight:700;color:#7c3aed;margin-bottom:4px"><i class="fa-solid fa-comment"></i> HR FEEDBACK</div>
        <p style="font-size:13px;color:#c4b5fd"><?= nl2br(htmlspecialchars($ans['hr_feedback'])) ?></p>
      </div>
      <?php elseif ($ans['hr_marks']===null): ?>
      <div style="background:rgba(245,158,11,0.07);border:1px solid rgba(245,158,11,0.2);border-radius:8px;padding:10px;font-size:12px;color:#f59e0b">
        <i class="fa-solid fa-hourglass-half"></i> This subjective answer is awaiting HR review and grading.
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
</body>
</html>
