<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Assessment Player view (Module 8C). Rendered by take_test.php with the Player
//  bundle. Renders: (1) pre-assessment acknowledgment gate, (2) the full-screen
//  player. Per-question widgets come from QuestionRenderer (registry-driven).
//  Behavior lives in assets/js/assessment-player.js; styling in the CSS file.
// ═════════════════════════════════════════════════════════════════════════════
use SmartHire\Assessment\Engine\QuestionRenderer;
use SmartHire\Assessment\Engine\QTypeRegistry;

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$fsRequired = !empty($policy['fullscreen_required']);
$autoSubmitAfter = (int)($policy['auto_submit_after'] ?? 3);

// Client boot payload — everything the JS needs, no secrets.
$boot = [
    'token'        => $token,
    'sid'          => (int)$submission['id'],
    'totalQ'       => $totalQ,
    'remaining'    => $remaining,           // server-authoritative seconds
    'startAt'      => min(max(0, $startAt), max(0, $totalQ - 1)),
    'flagged'      => $flaggedIds,
    'qids'         => array_map(fn($q) => (int)$q['question_id'], $questions),
    'perQLimits'   => array_map(fn($q) => (int)($q['time_limit_secs'] ?? 0), $questions),
    'answered'     => array_values(array_map('intval', array_keys(array_filter($saved,
                        fn($a) => trim((string)($a['answer_text'] ?? '')) !== '' || !empty($a['response']) || (string)($a['selected_option'] ?? '') !== '')))),
    'fsRequired'   => $fsRequired,
    'autoSubmitAfter' => $autoSubmitAfter,
    'logSignals'   => $policy['log_signals'] ?? [],
    'csrf'         => csrf_token(),
    'resumed'      => $resumed,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $e($test['title']) ?> — SmartHire Assessment</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="assets/css/assessment-player.css">
</head>
<body class="ap-body">

<?php if ($showIntro): ?>
<!-- ── Pre-assessment acknowledgment gate ── -->
<main class="ap-intro" role="main">
  <div class="ap-intro-card">
    <header class="ap-intro-head">
      <div class="ap-intro-icon" aria-hidden="true"><i class="fa-solid fa-laptop-code"></i></div>
      <div>
        <h1><?= $e($test['title']) ?></h1>
        <p class="ap-muted">SmartHire Online Assessment — please read before starting</p>
      </div>
    </header>

    <dl class="ap-intro-meta">
      <div><dt>Duration</dt><dd><?= (int)$test['duration_minutes'] ?> min</dd></div>
      <div><dt>Questions</dt><dd><?= $totalQ ?></dd></div>
      <div><dt>Total marks</dt><dd><?= (int)$test['total_marks'] ?></dd></div>
      <div><dt>Pass mark</dt><dd><?= (int)$test['passing_marks'] ?>%</dd></div>
    </dl>

    <?php if (!empty($test['description'])): ?>
    <section class="ap-intro-instructions" aria-label="Instructions">
      <h2>Instructions</h2>
      <p><?= nl2br($e($test['description'])) ?></p>
    </section>
    <?php endif; ?>

    <ul class="ap-rules">
      <li><i class="fa-solid fa-desktop" aria-hidden="true"></i> <strong>Device</strong> — use a laptop or desktop with a stable connection. Mobile is supported but not recommended.</li>
      <li><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> <strong>Timer</strong> — the countdown is authoritative on the server and keeps running if you refresh, reconnect, or close the tab. At zero the assessment auto-submits.</li>
      <li><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> <strong>Auto-save</strong> — every answer, flag and your position are saved automatically. If anything interrupts you, reopen the link and you resume exactly where you left off.</li>
      <li><i class="fa-solid fa-arrows-left-right" aria-hidden="true"></i> <strong>Navigation</strong> — move with Previous / Next or jump from the question map. Flag anything you want to revisit.</li>
      <?php if ($fsRequired): ?><li><i class="fa-solid fa-expand" aria-hidden="true"></i> <strong>Fullscreen</strong> — this assessment runs in fullscreen. Leaving fullscreen is logged<?= $autoSubmitAfter > 0 ? ' and, after ' . $autoSubmitAfter . ' exits, auto-submits' : '' ?>.</li><?php endif; ?>
      <li><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> <strong>Integrity</strong> — tab switches and focus loss are logged. Answers are final once submitted.</li>
    </ul>

    <form method="GET" action="take_test.php" id="ap-ack-form">
      <input type="hidden" name="token" value="<?= $e($token) ?>">
      <input type="hidden" name="begin" value="1">
      <label class="ap-ack">
        <input type="checkbox" id="ap-ack-box" required>
        <span>I have read the instructions and I'm ready to begin under these conditions.</span>
      </label>
      <button type="submit" class="ap-btn ap-btn-primary ap-btn-lg" id="ap-begin-btn" disabled>
        <i class="fa-solid fa-play" aria-hidden="true"></i> Start assessment
      </button>
    </form>
    <p class="ap-muted ap-center-txt"><a href="candidate_portal.php" class="ap-link">← Back to portal</a></p>
  </div>
</main>
<script>
  (function () {
    var box = document.getElementById('ap-ack-box'), btn = document.getElementById('ap-begin-btn');
    box.addEventListener('change', function () { btn.disabled = !box.checked; });
  })();
</script>

<?php else: ?>
<!-- ── Full-screen player ── -->
<div class="ap-player" id="ap-player">
  <header class="ap-topbar">
    <h1 class="ap-title"><i class="fa-solid fa-bolt" aria-hidden="true"></i> <?= $e($test['title']) ?></h1>
    <div class="ap-topbar-right">
      <span class="ap-netstatus" id="ap-net" title="Network status" aria-live="polite"><i class="fa-solid fa-wifi" aria-hidden="true"></i> <span class="ap-net-txt">Online</span></span>
      <span class="ap-savestatus" id="ap-save" aria-live="polite"><i class="fa-solid fa-check" aria-hidden="true"></i> Saved</span>
      <span class="ap-counter" id="ap-counter">Q 1 / <?= $totalQ ?></span>
      <div class="ap-timer" id="ap-timer" role="timer" aria-label="Time remaining">
        <span class="ap-timer-lbl">Time left</span>
        <span class="ap-timer-val" id="ap-timer-val">--:--</span>
      </div>
      <?php if ($fsRequired): ?><button class="ap-fsbtn" id="ap-fsbtn" type="button"><i class="fa-solid fa-expand" aria-hidden="true"></i> Fullscreen</button><?php endif; ?>
    </div>
  </header>

  <div class="ap-main">
    <nav class="ap-sidebar" aria-label="Question navigator">
      <div class="ap-sb-head">Questions <span id="ap-sb-prog"><?= $answeredCount ?>/<?= $totalQ ?></span></div>
      <div class="ap-qmap" id="ap-qmap" role="list">
        <?php foreach ($questions as $i => $q): $ans = in_array((int)$q['question_id'], $boot['answered'], true); $flg = in_array((int)$q['question_id'], $flaggedIds, true); ?>
        <button type="button" role="listitem" class="ap-qdot<?= $ans ? ' is-ans' : '' ?><?= $flg ? ' is-flg' : '' ?>" id="ap-dot-<?= $i ?>"
                data-idx="<?= $i ?>" aria-label="Question <?= $i + 1 ?><?= $ans ? ', answered' : '' ?><?= $flg ? ', flagged' : '' ?>"><?= $i + 1 ?></button>
        <?php endforeach; ?>
      </div>
      <div class="ap-legend">
        <span><i class="ap-leg is-ans"></i> Answered</span>
        <span><i class="ap-leg is-cur"></i> Current</span>
        <span><i class="ap-leg is-flg"></i> Flagged</span>
      </div>
    </nav>

    <main class="ap-content" id="ap-content" tabindex="-1">
      <form method="POST" action="take_test.php?token=<?= $e($token) ?>" id="ap-form">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="submit_test" value="1">
        <input type="hidden" name="auto_submit" id="ap-auto" value="">
        <?php foreach ($questions as $i => $q):
          $qid = (int)$q['question_id'];
          $savedRow = $saved[$qid] ?? null;
          $savedVal = $savedRow ? ($savedRow['response'] ? json_decode($savedRow['response'], true) : ($savedRow['answer_text'] ?? $savedRow['selected_option'] ?? '')) : null;
          $typeMeta = QTypeRegistry::get($q['question_type']) ?? ['label' => ucfirst($q['question_type'])];
          $diffCol  = ['easy' => 'ap-tag-easy', 'medium' => 'ap-tag-med', 'hard' => 'ap-tag-hard'][$q['difficulty'] ?? 'medium'] ?? 'ap-tag-med';
        ?>
        <section class="ap-question<?= $i === $boot['startAt'] ? ' is-active' : '' ?>" id="ap-q-<?= $i ?>" data-idx="<?= $i ?>" data-qid="<?= $qid ?>"
                 data-limit="<?= (int)($q['time_limit_secs'] ?? 0) ?>" aria-label="Question <?= $i + 1 ?>"<?= $i === $boot['startAt'] ? '' : ' hidden' ?>>
          <div class="ap-q-head">
            <div>
              <span class="ap-q-num">Question <?= $i + 1 ?> <span class="ap-muted">of <?= $totalQ ?></span></span>
              <div class="ap-q-tags">
                <span class="ap-tag ap-tag-type"><?= $e($typeMeta['label']) ?></span>
                <span class="ap-tag ap-tag-marks"><i class="fa-solid fa-star" aria-hidden="true"></i> <?= (int)$q['marks'] ?> marks</span>
                <?php if (!empty($q['difficulty'])): ?><span class="ap-tag <?= $diffCol ?>"><?= $e(ucfirst($q['difficulty'])) ?></span><?php endif; ?>
                <?php if ((int)($q['time_limit_secs'] ?? 0) > 0): ?><span class="ap-tag ap-tag-timer" id="ap-qt-<?= $i ?>"><i class="fa-solid fa-stopwatch" aria-hidden="true"></i> <span><?= (int)$q['time_limit_secs'] ?>s</span></span><?php endif; ?>
              </div>
            </div>
            <button type="button" class="ap-flag" id="ap-flag-<?= $i ?>" data-idx="<?= $i ?>" aria-pressed="<?= in_array($qid, $flaggedIds, true) ? 'true' : 'false' ?>">
              <i class="fa-solid fa-flag" aria-hidden="true"></i> <span><?= in_array($qid, $flaggedIds, true) ? 'Flagged' : 'Flag' ?></span>
            </button>
          </div>
          <div class="ap-q-text"><?= nl2br($e($q['question'])) ?></div>
          <div class="ap-q-answer">
            <?= QuestionRenderer::render($q, $savedVal) ?>
          </div>
          <input type="hidden" name="time_<?= $qid ?>" id="ap-time-<?= $qid ?>" value="<?= (int)($savedRow['time_spent_secs'] ?? 0) ?>">
          <div class="ap-q-nav">
            <?php if ($i > 0): ?><button type="button" class="ap-btn ap-btn-ghost" data-goto="<?= $i - 1 ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Previous</button><?php else: ?><span></span><?php endif; ?>
            <?php if ($i < $totalQ - 1): ?><button type="button" class="ap-btn ap-btn-primary" data-goto="<?= $i + 1 ?>">Next <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
            <?php else: ?><button type="button" class="ap-btn ap-btn-submit" id="ap-submit-inline"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Review &amp; submit</button><?php endif; ?>
          </div>
        </section>
        <?php endforeach; ?>
      </form>
    </main>
  </div>

  <footer class="ap-bottombar">
    <div class="ap-progress">
      <div class="ap-progress-lbl"><span id="ap-ans-count"><?= $answeredCount ?></span>/<?= $totalQ ?> answered</div>
      <div class="ap-progress-track"><div class="ap-progress-fill" id="ap-progress-fill" style="width:<?= $totalQ ? round($answeredCount / $totalQ * 100) : 0 ?>%"></div></div>
    </div>
    <button type="button" class="ap-btn ap-btn-submit" id="ap-submit-btn"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Submit</button>
  </footer>
</div>

<!-- Timer/network warning toast -->
<div class="ap-toast" id="ap-toast" role="status" aria-live="assertive" hidden></div>

<!-- Submit confirmation -->
<div class="ap-modal" id="ap-confirm" role="dialog" aria-modal="true" aria-labelledby="ap-confirm-title" hidden>
  <div class="ap-modal-box">
    <h2 id="ap-confirm-title">Submit assessment?</h2>
    <p>You have answered <strong id="ap-confirm-ans"><?= $answeredCount ?></strong> of <?= $totalQ ?> questions. <span id="ap-confirm-unans" class="ap-warn-txt"></span></p>
    <p class="ap-muted">Once submitted, your answers are final.</p>
    <div class="ap-modal-actions">
      <button type="button" class="ap-btn ap-btn-ghost" id="ap-confirm-cancel">Keep reviewing</button>
      <button type="button" class="ap-btn ap-btn-submit" id="ap-confirm-yes"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Yes, submit</button>
    </div>
  </div>
</div>

<script id="ap-boot" type="application/json"><?= json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="assets/js/assessment-player.js" defer></script>
<?php endif; ?>
</body>
</html>
