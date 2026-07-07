<?php
require_once 'includes/layout.php';
requireLogin();
requireRole('interviewer');
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

$tid = (int)($_GET['id'] ?? 0);
$test = dbFetchOne("SELECT ot.*, c.name AS cname, c.email AS cemail, c.phone AS cphone, c.position AS cposition
    FROM online_tests ot JOIN candidates c ON c.id=ot.candidate_id WHERE ot.id=?", 'i', $tid);
if (!$test) { header('Location: online_tests.php'); exit; }

$submission = dbFetchOne("SELECT * FROM test_submissions WHERE test_id=? ORDER BY submitted_at DESC LIMIT 1", 'i', $tid);
$answers = $submission ? dbFetchAll("SELECT ta.*, iq.question, iq.question_type, iq.expected_answer, iq.correct_option,
    iq.option_a, iq.option_b, iq.option_c, iq.option_d, iq.category, iq.max_score
    FROM test_answers ta JOIN interview_questions iq ON iq.id=ta.question_id
    WHERE ta.submission_id=? ORDER BY ta.id", 'i', $submission['id']) : [];

// Handle HR manual marking POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hr_mark_action'])) {
    $hrUser = currentUser();
    foreach ($_POST['hr_marks'] ?? [] as $ansId => $mark) {
        $mark = max(0, (int)$mark);
        $fb   = trim($_POST['hr_feedback'][$ansId] ?? '');
        dbExecute("UPDATE test_answers SET hr_marks=?, hr_feedback=?, hr_marked_at=NOW(), hr_marked_by=? WHERE id=? AND submission_id=?",
            'isiii', $mark, $fb, $hrUser['id'], (int)$ansId, $submission['id']);
    }
    // Recalculate total score: MCQ marks + HR marks for subjective
    $allAnswers = dbFetchAll("SELECT ta.*, iq.question_type FROM test_answers ta JOIN interview_questions iq ON iq.id=ta.question_id WHERE ta.submission_id=?", 'i', $submission['id']);
    $newTotal = 0;
    foreach ($allAnswers as $a) {
        if ($a['question_type'] === 'mcq') { $newTotal += (int)$a['marks_awarded']; }
        else { $newTotal += ($a['hr_marks'] !== null ? (int)$a['hr_marks'] : 0); }
    }
    $newPct = $submission['max_score'] > 0 ? round($newTotal / $submission['max_score'] * 100, 2) : 0;
    dbExecute("UPDATE test_submissions SET total_score=?, percentage=? WHERE id=?", 'idi', $newTotal, $newPct, $submission['id']);
    setFlash('success', 'Marks saved! New score: ' . round($newPct) . '%');
    header('Location: view_test_result.php?id=' . $tid); exit;
}

// Reload answers after any update
$answers = $submission ? dbFetchAll("SELECT ta.*, iq.question, iq.question_type, iq.expected_answer, iq.correct_option,
    iq.option_a, iq.option_b, iq.option_c, iq.option_d, iq.category, iq.max_score
    FROM test_answers ta JOIN interview_questions iq ON iq.id=ta.question_id
    WHERE ta.submission_id=? ORDER BY ta.id", 'i', $submission['id']) : [];

renderHead('Test Result — ' . $test['title']);
renderSidebar('online_tests');

// Flash
$flash = getFlash();
if ($flash): ?>
<div style="background:<?= $flash['type']==='success'?'rgba(16,185,129,0.15)':'rgba(239,68,68,0.15)' ?>;border:1px solid <?= $flash['type']==='success'?'rgba(16,185,129,0.3)':'rgba(239,68,68,0.3)' ?>;border-radius:10px;padding:12px 18px;margin-bottom:16px;color:<?= $flash['type']==='success'?'#10b981':'#f87171' ?>;font-size:13px">
  <i class="fa-solid <?= $flash['type']==='success'?'fa-check-circle':'fa-circle-xmark' ?>"></i> <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<div class="page-header">
  <div class="page-header-left">
    <div class="breadcrumb"><a href="dashboard.php">Home</a> <i class="fa-solid fa-chevron-right"></i> <a href="online_tests.php">Tests</a> <i class="fa-solid fa-chevron-right"></i> Result</div>
    <h1 class="page-title"><?= htmlspecialchars($test['title']) ?></h1>
    <p class="page-subtitle"><?= htmlspecialchars($test['cname']) ?> &nbsp;•&nbsp; <?= htmlspecialchars($test['cposition']) ?></p>
  </div>
  <div class="d-flex gap-2">
    <a href="javascript:window.print()" class="btn btn-secondary"><i class="fa-solid fa-print"></i> Print / PDF</a>
    <a href="print_result.php?candidate_id=<?= $test['candidate_id'] ?>" target="_blank"
       class="btn btn-secondary" style="color:var(--rose);border-color:rgba(244,63,94,.3)">
      <i class="fa-solid fa-file-pdf"></i> Full PDF Report
    </a>
  </div>
</div>

<?php if (!$submission): ?>
<div class="card">
  <div style="text-align:center;padding:60px;color:var(--text-muted)">
    <i class="fa-solid fa-hourglass-half" style="font-size:48px;display:block;margin-bottom:16px;opacity:.3"></i>
    <h3>Test Not Yet Attempted</h3>
    <p>The candidate hasn't submitted this test yet.</p>
  </div>
</div>
<?php else:
$pct = round($submission['percentage']);
$sc  = getScoreColor($pct);
$correctCount = array_sum(array_column($answers,'is_correct'));
$mcqTotal = count(array_filter($answers, fn($a)=>$a['question_type']==='mcq'));
$subjTotal = count(array_filter($answers, fn($a)=>$a['question_type']!=='mcq'));
$subjPending = count(array_filter($answers, fn($a)=>$a['question_type']!=='mcq' && $a['hr_marks']===null));
$totalTimeSpent = array_sum(array_column($answers,'time_spent_secs'));
?>

<!-- Score Summary -->
<div class="stats-grid" style="margin-bottom:24px">
  <div class="stat-card <?= $sc ?>">
    <div class="stat-icon <?= $sc ?>"><i class="fa-solid fa-trophy"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $pct ?>%</div>
      <div class="stat-label">Final Score</div>
      <div class="stat-delta <?= $pct >= $test['passing_marks'] ? 'up' : 'down' ?>">
        <?= $pct >= $test['passing_marks'] ? '<i class="fa-solid fa-check"></i> Passed' : '<i class="fa-solid fa-xmark"></i> Failed' ?>
      </div>
    </div>
  </div>
  <div class="stat-card blue">
    <div class="stat-icon blue"><i class="fa-solid fa-star"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $submission['total_score'] ?>/<?= $submission['max_score'] ?></div>
      <div class="stat-label">Marks Obtained</div>
    </div>
  </div>
  <div class="stat-card amber">
    <div class="stat-icon amber"><i class="fa-solid fa-clock"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $submission['time_taken_mins'] ?> min</div>
      <div class="stat-label">Time Taken</div>
      <div class="stat-delta up">Limit: <?= $test['duration_minutes'] ?> min</div>
    </div>
  </div>
  <div class="stat-card violet">
    <div class="stat-icon violet"><i class="fa-solid fa-circle-check"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $correctCount ?>/<?= $mcqTotal ?></div>
      <div class="stat-label">MCQ Correct</div>
      <div class="stat-delta up">Auto-scored</div>
    </div>
  </div>
  <?php if ($subjTotal > 0): ?>
  <div class="stat-card <?= $subjPending>0?'amber':'green' ?>">
    <div class="stat-icon <?= $subjPending>0?'amber':'green' ?>"><i class="fa-solid fa-pen-to-square"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $subjTotal - $subjPending ?>/<?= $subjTotal ?></div>
      <div class="stat-label">Subjective Graded</div>
      <div class="stat-delta <?= $subjPending>0?'down':'up' ?>"><?= $subjPending>0?$subjPending.' pending':'All graded' ?></div>
    </div>
  </div>
  <?php endif; ?>
  <?php $viol = (int)($submission['violations'] ?? 0); ?>
  <div class="stat-card <?= $viol > 0 ? 'rose' : 'green' ?>">
    <div class="stat-icon <?= $viol > 0 ? 'rose' : 'green' ?>"><i class="fa-solid fa-shield-halved"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $viol ?></div>
      <div class="stat-label">Proctoring Violations</div>
      <div class="stat-delta <?= $viol > 0 ? 'down' : 'up' ?>"><?= $viol === 0 ? 'Clean attempt' : ($viol >= 3 ? 'Auto-submitted' : 'Flagged') ?></div>
    </div>
  </div>
  <?php if ($totalTimeSpent > 0): ?>
  <div class="stat-card blue">
    <div class="stat-icon blue"><i class="fa-solid fa-gauge"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= round($totalTimeSpent/max(1,count($answers))) ?>s</div>
      <div class="stat-label">Avg Time / Question</div>
      <div class="stat-delta up">Total: <?= round($totalTimeSpent/60,1) ?> min</div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Candidate Info -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><div class="card-title">Candidate Information</div></div>
  <div style="padding:20px;display:grid;grid-template-columns:repeat(3,1fr);gap:16px">
    <div><div class="form-label">Name</div><strong><?= htmlspecialchars($test['cname']) ?></strong></div>
    <div><div class="form-label">Email</div><span><?= htmlspecialchars($test['cemail']) ?></span></div>
    <div><div class="form-label">Phone</div><span><?= htmlspecialchars($test['cphone'] ?? '—') ?></span></div>
    <div><div class="form-label">Position</div><span><?= htmlspecialchars($test['cposition']) ?></span></div>
    <div><div class="form-label">Submitted At</div><span><?= $submission['submitted_at'] ? date('d M Y, h:i A', strtotime($submission['submitted_at'])) : '—' ?></span></div>
    <div><div class="form-label">Result</div>
      <span class="badge badge-<?= $pct >= $test['passing_marks'] ? 'green' : 'rose' ?>">
        <?= $pct >= $test['passing_marks'] ? 'PASSED' : 'FAILED' ?> (Pass: <?= $test['passing_marks'] ?>%)
      </span>
    </div>
  </div>
</div>

<!-- Per-Question Time Analysis -->
<?php if ($totalTimeSpent > 0): ?>
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><div class="card-title"><i class="fa-solid fa-chart-bar"></i> Time Analysis per Question</div></div>
  <div style="padding:16px 20px">
    <?php foreach ($answers as $i => $ans):
      $ts = (int)($ans['time_spent_secs'] ?? 0);
      $maxTs = max(1, max(array_column($answers, 'time_spent_secs')));
      $barW = round($ts/$maxTs*100);
    ?>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px">
      <span style="width:24px;font-size:11px;font-weight:700;color:var(--accent)">Q<?= $i+1 ?></span>
      <div style="flex:1;height:20px;background:rgba(255,255,255,0.06);border-radius:6px;overflow:hidden">
        <div style="height:100%;width:<?= $barW ?>%;background:<?= $ans['is_correct']?'linear-gradient(90deg,#10b981,#059669)':($ans['question_type']==='mcq'?'linear-gradient(90deg,#ef4444,#dc2626)':'linear-gradient(90deg,#7c3aed,#4338ca)') ?>;border-radius:6px;transition:width .6s ease;display:flex;align-items:center;padding-left:8px">
          <span style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.9);white-space:nowrap"><?= $ts ?>s</span>
        </div>
      </div>
      <span style="width:80px;text-align:right;font-size:12px;color:var(--text-muted)"><?= htmlspecialchars(substr($ans['question'],0,25)) ?>…</span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Detailed Answers + HR Grading Form -->
<form method="POST" id="hrMarkForm">
      <?= csrf_field() ?>
<input type="hidden" name="hr_mark_action" value="1">
<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
    <div class="card-title">Detailed Answers (<?= count($answers) ?> Questions)</div>
    <?php if ($subjPending > 0): ?>
    <span class="badge badge-amber"><i class="fa-solid fa-pen-to-square"></i> <?= $subjPending ?> subjective answer(s) need grading</span>
    <?php endif; ?>
  </div>
  <div style="padding:20px">
    <?php foreach ($answers as $i => $ans):
      $isSubj = $ans['question_type'] !== 'mcq';
      $ts = (int)($ans['time_spent_secs'] ?? 0);
    ?>
    <div style="border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:14px;background:<?= !$isSubj&&$ans['is_correct'] ? 'rgba(16,185,129,0.05)' : (!$isSubj&&!$ans['is_correct']?'rgba(239,68,68,0.04)':'rgba(255,255,255,0.02)') ?>;border-color:<?= !$isSubj&&$ans['is_correct']?'rgba(16,185,129,0.3)':(!$isSubj&&!$ans['is_correct']?'rgba(239,68,68,0.2)':'var(--border)') ?>">
      <!-- Q header -->
      <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <span style="font-size:12px;font-weight:700;color:var(--accent)">Q<?= $i+1 ?></span>
          <span class="badge badge-blue" style="font-size:10px"><?= $ans['category'] ?></span>
          <span class="badge badge-<?= $isSubj?'amber':'violet' ?>" style="font-size:10px"><?= $isSubj?'Subjective':'MCQ' ?></span>
          <?php if ($ts > 0): ?>
          <span class="badge badge-blue" style="font-size:10px"><i class="fa-solid fa-clock"></i> <?= $ts ?>s</span>
          <?php endif; ?>
        </div>
        <div style="text-align:right">
          <?php if ($isSubj): ?>
            <?php if ($ans['hr_marks'] !== null): ?>
              <span class="badge badge-green"><?= $ans['hr_marks'] ?>/<?= $ans['max_score'] ?> marks (HR)</span>
            <?php else: ?>
              <span class="badge badge-amber">Pending HR grading</span>
            <?php endif; ?>
          <?php else: ?>
          <span class="badge badge-<?= $ans['marks_awarded'] > 0 ? 'green':'rose' ?>">
            <?= $ans['marks_awarded'] ?>/<?= $ans['max_score'] ?> marks
          </span>
          <span class="badge badge-<?= $ans['is_correct']?'green':'rose' ?>" style="margin-left:4px">
            <?= $ans['is_correct']?'✓ Correct':'✗ Wrong' ?>
          </span>
          <?php endif; ?>
        </div>
      </div>

      <p style="font-weight:600;color:var(--text);margin-bottom:10px;font-size:14px"><?= htmlspecialchars($ans['question']) ?></p>

      <?php if (!$isSubj): ?>
        <!-- MCQ options -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:13px;margin-bottom:8px">
          <?php foreach (['a','b','c','d'] as $opt): ?>
            <?php $optText = $ans['option_'.$opt]; if (!$optText) continue; ?>
            <div style="padding:8px 12px;border-radius:8px;border:1px solid <?=
              $opt===$ans['correct_option'] ? '#10b981' : ($opt===$ans['selected_option']&&!$ans['is_correct'] ? '#ef4444' : 'var(--border)')
            ?>;background:<?=
              $opt===$ans['correct_option'] ? 'rgba(16,185,129,0.1)' : ($opt===$ans['selected_option']&&!$ans['is_correct'] ? 'rgba(239,68,68,0.08)' : 'transparent')
            ?>">
              <strong style="color:var(--accent)"><?= strtoupper($opt) ?>.</strong> <?= htmlspecialchars($optText) ?>
              <?php if ($opt===$ans['selected_option']): ?> <span style="font-size:10px;color:<?= $ans['is_correct']?'#10b981':'#ef4444' ?>"> ← Candidate</span><?php endif; ?>
              <?php if ($opt===$ans['correct_option']): ?> <span style="font-size:10px;color:#10b981"> ✓ Correct</span><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <!-- Subjective answer -->
        <div style="background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:8px;padding:12px;margin-bottom:12px">
          <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">Candidate's Answer:</div>
          <p style="font-size:13px;color:var(--text)"><?= nl2br(htmlspecialchars($ans['answer_text'] ?: '(No answer provided)')) ?></p>
        </div>
        <?php if ($ans['expected_answer']): ?>
        <div style="background:rgba(16,185,129,0.05);border:1px solid rgba(16,185,129,0.2);border-radius:8px;padding:10px;margin-bottom:12px">
          <div style="font-size:11px;color:#10b981;margin-bottom:4px">Expected Answer (Guide):</div>
          <p style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($ans['expected_answer']) ?></p>
        </div>
        <?php endif; ?>

        <!-- ── HR Manual Grading Section ── -->
        <div style="background:rgba(124,58,237,0.07);border:1.5px solid rgba(124,58,237,0.3);border-radius:10px;padding:14px">
          <div style="font-size:12px;font-weight:700;color:#a78bfa;margin-bottom:10px">
            <i class="fa-solid fa-pen-to-square"></i> HR Manual Grading
            <?php if ($ans['hr_marks'] !== null): ?>
              <span style="color:#10b981;margin-left:8px"><i class="fa-solid fa-check-circle"></i> Graded by HR</span>
              <?php if ($ans['hr_marked_at']): ?>
                <span style="color:var(--text-muted);font-size:10px;margin-left:6px"><?= date('d M Y, h:i', strtotime($ans['hr_marked_at'])) ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span style="color:#f59e0b;margin-left:8px"><i class="fa-solid fa-clock"></i> Awaiting grading</span>
            <?php endif; ?>
          </div>
          <div style="display:grid;grid-template-columns:auto 1fr;gap:10px;align-items:start">
            <div>
              <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px">Marks (0–<?= $ans['max_score'] ?>)</label>
              <input type="number" name="hr_marks[<?= $ans['id'] ?>]" min="0" max="<?= $ans['max_score'] ?>"
                value="<?= $ans['hr_marks'] ?? '' ?>"
                class="form-control" style="width:80px"
                placeholder="0">
            </div>
            <div>
              <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px">HR Feedback / Comments</label>
              <textarea name="hr_feedback[<?= $ans['id'] ?>]" class="form-control" rows="2"
                placeholder="Optional feedback for this answer…"><?= htmlspecialchars($ans['hr_feedback'] ?? '') ?></textarea>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if ($subjTotal > 0): ?>
    <div style="margin-top:16px;display:flex;justify-content:flex-end">
      <button type="submit" class="btn btn-primary" style="padding:12px 28px">
        <i class="fa-solid fa-floppy-disk"></i> Save HR Marks & Recalculate Score
      </button>
    </div>
    <?php endif; ?>
  </div>
</div>
</form>
<?php endif; ?>
<?php renderFooter(); ?>
