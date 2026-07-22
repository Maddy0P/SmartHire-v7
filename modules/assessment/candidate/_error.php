<?php /* Candidate assessment — error/notice page. Vars: $msg, $tone (danger|warn|ok). */
$tones = ['danger' => ['#f87171', 'fa-circle-xmark'], 'warn' => ['#fbbf24', 'fa-triangle-exclamation'], 'ok' => ['#34d399', 'fa-circle-check']];
[$col, $icon] = $tones[$tone] ?? $tones['danger'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Assessment — SmartHire</title>
<link rel="stylesheet" href="assets/css/assessment-player.css">
</head>
<body class="ap-body ap-center">
  <main class="ap-notice" role="alert">
    <div class="ap-notice-icon" style="color:<?= $col ?>"><i class="fa-solid <?= $icon ?>" aria-hidden="true"></i></div>
    <h1><?= htmlspecialchars($msg) ?></h1>
    <a class="ap-btn ap-btn-primary" href="candidate_portal.php"><i class="fa-solid fa-house" aria-hidden="true"></i> Back to portal</a>
  </main>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</body>
</html>
