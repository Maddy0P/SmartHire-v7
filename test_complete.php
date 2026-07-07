<?php
require_once 'includes/config.php';
requireCandidateLogin();
$score     = round((float)($_GET['score'] ?? 0));
$marks     = (int)($_GET['marks'] ?? 0);
$max       = (int)($_GET['max'] ?? 100);
$timeTaken = (int)($_GET['time'] ?? 0);
$cand      = currentCandidate();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Test Submitted — SmartHire</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{background:#0f172a;font-family:'Inter',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
    .card{background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:24px;padding:48px;max-width:500px;width:100%;text-align:center}
    .icon-circle{width:80px;height:80px;border-radius:50%;margin:0 auto 24px;display:flex;align-items:center;justify-content:center;font-size:36px}
    .score-ring{width:160px;height:160px;margin:0 auto 24px;position:relative}
    .score-ring svg{transform:rotate(-90deg)}
    .score-ring .ring-bg{fill:none;stroke:rgba(255,255,255,0.1);stroke-width:12}
    .score-ring .ring-fill{fill:none;stroke-width:12;stroke-linecap:round;transition:stroke-dashoffset 1s ease}
    .score-center{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center}
    .score-pct{font-size:36px;font-weight:800;line-height:1}
    .score-lbl{font-size:12px;color:#64748b;margin-top:4px}
    h2{color:#f1f5f9;font-size:24px;margin-bottom:8px}
    p{color:#94a3b8;font-size:14px;line-height:1.6;margin-bottom:6px}
    .marks-row{background:rgba(255,255,255,0.04);border-radius:12px;padding:16px;margin:20px 0;display:flex;justify-content:space-between}
    .marks-row div{text-align:center}
    .marks-row .val{font-size:22px;font-weight:700;color:#a78bfa}
    .marks-row .lbl{font-size:11px;color:#64748b;margin-top:2px}
    .btn{display:inline-block;padding:12px 28px;border-radius:10px;font-size:14px;font-weight:600;text-decoration:none;cursor:pointer;border:none;transition:all .2s;margin:6px}
    .btn-primary{background:linear-gradient(135deg,#7c3aed,#4338ca);color:#fff}
    .btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(124,58,237,0.4)}
  </style>
</head>
<body>
<div class="card">
  <?php
  $color = $score >= 80 ? '#10b981' : ($score >= 60 ? '#f59e0b' : ($score >= 40 ? '#6366f1' : '#ef4444'));
  $circ = 2 * pi() * 64;
  $filled = $circ * ($score / 100);
  ?>
  <div class="score-ring">
    <svg width="160" height="160" viewBox="0 0 160 160">
      <circle class="ring-bg" cx="80" cy="80" r="64"/>
      <circle class="ring-fill" cx="80" cy="80" r="64"
              stroke="<?= $color ?>"
              stroke-dasharray="<?= $circ ?>"
              stroke-dashoffset="<?= $circ - $filled ?>" id="ringFill"/>
    </svg>
    <div class="score-center">
      <div class="score-pct" style="color:<?= $color ?>"><?= $score ?>%</div>
      <div class="score-lbl">Score</div>
    </div>
  </div>

  <h2>Test Submitted! 🎉</h2>
  <p>Great job, <?= htmlspecialchars(explode(' ',$cand['name'])[0]) ?>! Your responses have been recorded.</p>

  <div class="marks-row">
    <div><div class="val"><?= $marks ?></div><div class="lbl">Marks Earned</div></div>
    <div><div class="val"><?= $max ?></div><div class="lbl">Total Marks</div></div>
    <div><div class="val" style="color:<?= $color ?>"><?= $score >= 40 ? 'Passed' : 'Failed' ?></div><div class="lbl">Result</div></div>
    <?php if ($timeTaken > 0): ?>
    <div><div class="val" style="color:#94a3b8"><?= $timeTaken ?> min</div><div class="lbl">Time Taken</div></div>
    <?php endif; ?>
  </div>

  <p style="color:#64748b;font-size:13px">Your detailed result has been saved to your profile. HR will review your answers shortly.</p>

  <div style="margin-top:24px">
    <a href="candidate_portal.php" class="btn btn-primary"><i class="fa-solid fa-house"></i> Back to Portal</a>
  </div>
</div>
<script>
// Animate ring
setTimeout(() => {
  const r = document.getElementById('ringFill');
  const circ = <?= $circ ?>;
  const filled = <?= $filled ?>;
  r.style.strokeDashoffset = circ - filled;
}, 100);
</script>
</body>
</html>
