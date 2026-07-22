<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Assessment submission confirmation + candidate result (Module 8C).
//  Consumes AssessmentService::candidateResult($sid) (ResultEngine) and gates the
//  depth of what the candidate sees on the frozen instance config
//  `candidate_result` (none|score|full; default: score). No SQL here.
// ═════════════════════════════════════════════════════════════════════════════
require_once 'includes/config.php';
require_once 'modules/assessment/bootstrap.php';
requireCandidateLogin();

use SmartHire\Assessment\Engine\AssessmentService;
use SmartHire\Assessment\Domain\Question;

$cand  = currentCandidate();
$svc   = AssessmentService::production();
$sid   = (int)($_GET['sid'] ?? 0);

// Fallbacks from the redirect (used only for the headline confirmation numbers).
$qScore = round((float)($_GET['score'] ?? 0));
$qMarks = (int)($_GET['marks'] ?? 0);
$qMax   = (int)($_GET['max'] ?? 100);
$qTime  = (int)($_GET['time'] ?? 0);

// Load the authoritative submission + result (ownership-checked).
$sub = $sid > 0 ? $svc->submissions->find($sid) : null;
$ownsSubmission = $sub && (int)$sub->candidateId === (int)$cand['id'];
$result = $ownsSubmission ? $svc->candidateResult($sid) : null;

// Visibility policy from the frozen instance config.
$visibility = 'score';
if ($ownsSubmission) {
    $test = dbFetchOne("SELECT config FROM online_tests WHERE id=?", 'i', $sub->assessmentId);
    $cfg = $svc->configFor(null, Question::jsonb($test['config'] ?? null));
    $visibility = (string)$cfg->get('candidate_result', 'score'); // none|score|full
}
$showScore = $visibility !== 'none' && $result !== null;
$showBreakdown = $visibility === 'full' && $result !== null;

$pct   = $result ? round($result->overallPct) : $qScore;
$marks = $result ? (int)$result->totalMarks : $qMarks;
$max   = $result ? (int)$result->maxMarks : $qMax;
$passed = $result ? $result->passed : ($pct >= 40);
$pending = $result ? $result->pendingReview : 0;
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$recLabel = ['strong_yes' => 'Strongly recommended', 'yes' => 'Recommended', 'maybe' => 'Under consideration', 'no' => 'Not advanced'];
$firstName = $e(explode(' ', $cand['name'])[0]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Assessment submitted — SmartHire</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="assets/css/assessment-player.css">
</head>
<body class="ap-body ap-center">
<main class="ap-result" role="main">
  <div class="ap-result-card">
    <div class="ap-result-confirm">
      <div class="ap-result-tick" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></div>
      <h1>Assessment submitted</h1>
      <p class="ap-muted">Thanks, <?= $firstName ?> — your responses have been recorded<?= $pending > 0 ? ' and are awaiting review' : '' ?>.</p>
    </div>

    <?php if ($showScore): ?>
    <?php $ringPct = max(0, min(100, $pct)); $col = $pct >= 80 ? 'var(--apc-success)' : ($pct >= 40 ? 'var(--apc-accent)' : 'var(--apc-danger)'); ?>
    <div class="ap-result-score">
      <div class="ap-ring" style="--ring:<?= $ringPct ?>;--ring-col:<?= $col ?>">
        <div class="ap-ring-val"><?= $pct ?><span>%</span></div>
      </div>
      <dl class="ap-result-stats">
        <div><dt>Marks</dt><dd><?= $marks ?> / <?= $max ?></dd></div>
        <div><dt>Outcome</dt><dd style="color:<?= $col ?>"><?= $passed ? 'Passed' : 'Not passed' ?></dd></div>
        <?php if ($qTime > 0): ?><div><dt>Time</dt><dd><?= $qTime ?> min</dd></div><?php endif; ?>
        <?php if ($pending > 0): ?><div><dt>Pending review</dt><dd><?= $pending ?></dd></div><?php endif; ?>
      </dl>
      <?php if ($pending > 0): ?>
      <p class="ap-result-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Some answers are graded manually — your final score may change after review.</p>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="ap-result-hidden">
      <i class="fa-solid fa-lock" aria-hidden="true"></i>
      <p>Your responses are with the hiring team. Results aren't shown here — you'll hear about next steps by email.</p>
    </div>
    <?php endif; ?>

    <?php if ($showBreakdown): ?>
    <section class="ap-result-breakdown" aria-label="Result breakdown">
      <?php if ($result->sections): ?>
      <h2>Section performance</h2>
      <?php foreach ($result->sections as $name => $s): ?>
      <div class="ap-bar"><span class="ap-bar-lbl"><?= $e($name) ?></span>
        <span class="ap-bar-track"><span class="ap-bar-fill" style="width:<?= (int)round($s['pct']) ?>%"></span></span>
        <span class="ap-bar-val"><?= (int)round($s['pct']) ?>%</span></div>
      <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($result->skills): ?>
      <h2>Skills</h2>
      <?php foreach (array_slice($result->skills, 0, 6, true) as $skill => $s): ?>
      <div class="ap-bar"><span class="ap-bar-lbl"><?= $e($skill) ?></span>
        <span class="ap-bar-track"><span class="ap-bar-fill" style="width:<?= (int)round($s['pct']) ?>%"></span></span>
        <span class="ap-bar-val"><?= (int)round($s['pct']) ?>%</span></div>
      <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($result->strengths): ?>
      <h2>Strengths</h2>
      <p class="ap-result-tags"><?php foreach ($result->strengths as $st): ?><span class="ap-pill ap-pill-ok"><?= $e($st) ?></span><?php endforeach; ?></p>
      <?php endif; ?>

      <?php if ($visibility === 'full' && !empty($result->recommendation) && isset($recLabel[$result->recommendation])): ?>
      <h2>Next hiring step</h2>
      <p class="ap-muted"><?= $e($recLabel[$result->recommendation]) ?>. The hiring team will be in touch about what comes next.</p>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <div class="ap-result-actions ap-noprint">
      <a href="candidate_portal.php" class="ap-btn ap-btn-primary"><i class="fa-solid fa-house" aria-hidden="true"></i> Back to portal</a>
      <?php if ($showBreakdown): ?><button type="button" class="ap-btn ap-btn-ghost" onclick="window.print()"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i> Save as PDF</button><?php endif; ?>
    </div>
  </div>
</main>
</body>
</html>
