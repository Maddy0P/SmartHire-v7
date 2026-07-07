<?php
require_once 'includes/config.php';
requireCandidateLogin();
$cand = currentCandidate();
$candidate = dbFetchOne("SELECT * FROM candidates WHERE id=?", 'i', $cand['id']);

// ── Fetch tests with submission status ────────────────────────────
// Auto-expire tests whose expiry date has passed
dbExecute("UPDATE online_tests SET status='expired' WHERE status='active' AND expiry_date IS NOT NULL AND expiry_date < CURRENT_DATE AND candidate_id=?", 'i', $cand['id']);

// FIX: include in_progress submissions so we can show "Resume / Continue" button
$tests = dbFetchAll("
    SELECT ot.*,
           ts.status    AS sub_status,
           ts.total_score,
           ts.max_score,
           ts.percentage,
           ts.submitted_at,
           ts.id        AS sub_db_id
    FROM online_tests ot
    LEFT JOIN test_submissions ts
           ON ts.test_id = ot.id
          AND ts.candidate_id = ?
    WHERE ot.candidate_id = ?
    ORDER BY ot.created_at DESC", 'ii', $cand['id'], $cand['id']);

$submissions = dbFetchAll("
    SELECT ts.*, ot.title AS test_title, ot.id AS test_ot_id,
           ot.passing_marks
    FROM test_submissions ts
    JOIN online_tests ot ON ot.id = ts.test_id
    WHERE ts.candidate_id = ?
      AND ts.status IN ('submitted','auto_submitted')
    ORDER BY ts.submitted_at DESC", 'i', $cand['id']);

// Pending = active test AND not yet submitted (NULL or in_progress both count as pending)
$pendingTests = array_filter($tests, function($t) {
    $notDone = !in_array($t['sub_status'] ?? '', ['submitted', 'auto_submitted']);
    return $notDone && $t['status'] === 'active';
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>My Portal — SmartHire</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="assets/css/main.css">
  <link rel="stylesheet" href="assets/css/v7.css">
  <style>
    body{background:#0f172a}
    .cportal{min-height:100vh;background:linear-gradient(135deg,#0f172a,#1e1b4b)}
    .cp-header{background:linear-gradient(135deg,#7c3aed,#4338ca);padding:20px 32px;display:flex;align-items:center;justify-content:space-between}
    .cp-header .brand{display:flex;align-items:center;gap:12px;color:#fff}
    .cp-header .brand i{font-size:22px}
    .cp-header .brand h1{font-size:18px;font-weight:700;margin:0}
    .cp-content{max-width:920px;margin:0 auto;padding:28px 20px}
    .cp-welcome{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:16px;padding:22px;margin-bottom:22px;display:flex;align-items:center;gap:16px}
    .cp-avatar{width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#7c3aed,#4338ca);display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:700;color:#fff;flex-shrink:0}
    .cp-section{margin-bottom:26px}
    .cp-section-title{color:#94a3b8;font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;margin:0 0 12px;padding-bottom:8px;border-bottom:1px solid rgba(255,255,255,.08)}
    .test-card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:20px;margin-bottom:12px;transition:all .2s}
    .test-card:hover{background:rgba(255,255,255,.07);border-color:rgba(124,58,237,.35)}

    /* ── Status badges ── */
    .bs{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600;display:inline-flex;align-items:center;gap:5px}
    .bs-active{background:rgba(16,185,129,.15);color:#10b981;border:1px solid rgba(16,185,129,.3)}
    .bs-done{background:rgba(99,102,241,.15);color:#818cf8;border:1px solid rgba(99,102,241,.3)}
    .bs-pending{background:rgba(245,158,11,.15);color:#f59e0b;border:1px solid rgba(245,158,11,.3)}
    .bs-expired{background:rgba(239,68,68,.15);color:#ef4444;border:1px solid rgba(239,68,68,.3)}
    .bs-progress{background:rgba(124,58,237,.15);color:#a78bfa;border:1px solid rgba(124,58,237,.3)}

    /* ── Score pill ── */
    .score-pill{background:rgba(124,58,237,.2);border:1px solid rgba(124,58,237,.4);border-radius:8px;padding:8px 14px;text-align:center}
    .score-pill .sv{font-size:22px;font-weight:800;color:#a78bfa}
    .score-pill .sl{font-size:10px;color:#7c3aed;text-transform:uppercase;letter-spacing:1px}

    /* ── Buttons ── */
    .btn-start{background:linear-gradient(135deg,#7c3aed,#4338ca);color:#fff;border:none;padding:11px 22px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px;transition:all .2s;box-shadow:0 4px 14px rgba(124,58,237,.35)}
    .btn-start:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(124,58,237,.5)}
    .btn-resume{background:rgba(245,158,11,.15);color:#f59e0b;border:1px solid rgba(245,158,11,.4);padding:10px 20px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px;transition:all .2s}
    .btn-resume:hover{background:rgba(245,158,11,.25)}
    .btn-view{background:rgba(255,255,255,.06);color:#94a3b8;border:1px solid rgba(255,255,255,.1);padding:8px 16px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:5px;transition:all .2s}
    .btn-view:hover{background:rgba(255,255,255,.12);color:#f1f5f9}
    .btn-logout{background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.3);padding:8px 16px;border-radius:8px;font-size:13px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:all .2s}
    .btn-logout:hover{background:rgba(239,68,68,.25)}

    /* ── Notification banner ── */
    .notif-banner{background:linear-gradient(135deg,rgba(124,58,237,.15),rgba(67,56,202,.15));border:1.5px solid rgba(124,58,237,.5);border-radius:16px;padding:20px 22px;margin-bottom:14px;display:flex;align-items:center;gap:14px;animation:fadeUp .35s ease}
    @keyframes fadeUp{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
    .pulse{width:10px;height:10px;border-radius:50%;background:#a78bfa;flex-shrink:0;animation:blink 1.4s infinite}
    @keyframes blink{0%,100%{opacity:1;box-shadow:0 0 0 0 rgba(167,139,250,.7)}50%{opacity:.6;box-shadow:0 0 0 6px rgba(167,139,250,0)}}
    .notif-icon{width:46px;height:46px;border-radius:12px;background:linear-gradient(135deg,#7c3aed,#4338ca);display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff;flex-shrink:0}
    .notif-body h3{color:#c4b5fd;font-size:14px;font-weight:700;margin:0 0 4px}
    .notif-body p{color:#94a3b8;font-size:12.5px;margin:0;line-height:1.5}

    /* ── Progress bar (mini) ── */
    .mini-bar-wrap{width:80px;height:6px;background:rgba(255,255,255,.08);border-radius:99px;overflow:hidden;margin-top:4px}
    .mini-bar-fill{height:100%;border-radius:99px;transition:width .6s ease}

    /* ── Result row ── */
    .result-row{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:10px;padding:14px 16px;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:12px}

    /* ── Profile grid ── */
    .info-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:8px}
    .info-item label{display:block;color:#64748b;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px}
    .info-item span{color:#e2e8f0;font-size:13.5px}

    /* ── In-progress indicator ── */
    .inprog-note{font-size:12px;color:#a78bfa;display:flex;align-items:center;gap:5px;margin-top:6px}

    @media(max-width:600px){.info-grid{grid-template-columns:1fr}.cp-header{padding:14px 18px}.notif-banner{flex-direction:column;align-items:flex-start}}
  </style>
</head>
<body>
<div class="cportal">
  <div class="cp-header">
    <div class="brand">
      <i class="fa-solid fa-bolt"></i>
      <h1>SmartHire — Candidate Portal</h1>
    </div>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <nav class="cp-nav" style="display:flex;gap:6px;flex-wrap:wrap">
        <a href="candidate_portal.php" class="active" style="color:#fff;text-decoration:none;font-size:13px;font-weight:600;padding:8px 14px;border-radius:9px;background:rgba(255,255,255,.16)"><i class="fa-solid fa-house"></i> Portal</a>
        <a href="careers.php" style="color:rgba(255,255,255,.82);text-decoration:none;font-size:13px;font-weight:600;padding:8px 14px;border-radius:9px"><i class="fa-solid fa-briefcase"></i> Careers</a>
        <a href="my_applications.php" style="color:rgba(255,255,255,.82);text-decoration:none;font-size:13px;font-weight:600;padding:8px 14px;border-radius:9px"><i class="fa-solid fa-list-check"></i> My Applications</a>
      </nav>
      <span style="color:rgba(255,255,255,.7);font-size:13px">
        <i class="fa-solid fa-user"></i> <?= htmlspecialchars($cand['name']) ?>
      </span>
      <a href="candidate_logout.php" class="btn-logout">
        <i class="fa-solid fa-right-from-bracket"></i> Logout
      </a>
    </div>
  </div>

  <div class="cp-content">

    <!-- Welcome Banner -->
    <div class="cp-welcome">
      <div class="cp-avatar"><?= strtoupper(substr($candidate['name'],0,1)) ?></div>
      <div style="flex:1">
        <h2 style="color:#f1f5f9;margin:0 0 4px;font-size:19px">
          Welcome, <?= htmlspecialchars(explode(' ',$candidate['name'])[0]) ?>! 👋
        </h2>
        <p style="color:#94a3b8;margin:0;font-size:13px">
          <?= htmlspecialchars($candidate['position'] ?? 'Candidate') ?>
          &nbsp;•&nbsp; <?= htmlspecialchars($candidate['email']) ?>
        </p>
        <div style="margin-top:6px">
          <?php
          $statusClass = match($candidate['status']) {
            'hired'    => 'bs-active',
            'rejected' => 'bs-expired',
            default    => 'bs-pending',
          };
          ?>
          <span class="bs <?= $statusClass ?>"><?= ucfirst($candidate['status'] ?? 'pending') ?></span>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════
         PENDING TEST NOTIFICATION BANNERS
         Shows for: active tests that are NOT yet submitted
         (covers both: never-started AND in-progress/resumed)
         ═══════════════════════════════════════════════════════════ -->
    <?php foreach ($pendingTests as $pt):
      $isInProgress = $pt['sub_status'] === 'in_progress';
    ?>
    <div class="notif-banner">
      <div class="pulse"></div>
      <div class="notif-icon">
        <i class="fa-solid fa-<?= $isInProgress ? 'clock-rotate-left' : 'file-circle-check' ?>"></i>
      </div>
      <div class="notif-body" style="flex:1">
        <?php if ($isInProgress): ?>
          <h3>⏳ Test in progress — resume where you left off!</h3>
        <?php else: ?>
          <h3>🎯 You have been assigned a test!</h3>
        <?php endif; ?>
        <p>
          <strong style="color:#e2e8f0"><?= htmlspecialchars($pt['title']) ?></strong>
          &nbsp;•&nbsp;
          <i class="fa-solid fa-clock"></i> <?= $pt['duration_minutes'] ?> mins
          &nbsp;•&nbsp;
          <i class="fa-solid fa-star"></i> <?= $pt['total_marks'] ?> marks
          &nbsp;•&nbsp;
          Expires: <?= htmlspecialchars($pt['expiry_date'] ?? 'N/A') ?>
        </p>
        <?php if ($pt['description']): ?>
          <p style="margin-top:4px;color:#a78bfa;font-size:12px"><?= htmlspecialchars($pt['description']) ?></p>
        <?php endif; ?>
      </div>
      <a href="take_test.php?token=<?= urlencode($pt['test_link_token']) ?>"
         class="btn-start" style="white-space:nowrap">
        <?php if ($isInProgress): ?>
          <i class="fa-solid fa-clock-rotate-left"></i> Resume Test
        <?php else: ?>
          <i class="fa-solid fa-play"></i> Start Test Now
        <?php endif; ?>
      </a>
    </div>
    <?php endforeach; ?>

    <?php if (empty($pendingTests) && empty($tests)): ?>
    <div style="background:rgba(255,255,255,.03);border:1px dashed rgba(255,255,255,.1);border-radius:12px;padding:24px;text-align:center;color:#64748b;margin-bottom:20px">
      <i class="fa-solid fa-bell-slash" style="font-size:28px;display:block;margin-bottom:8px;opacity:.4"></i>
      No tests assigned yet. HR will notify you when a test is ready.
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════
         ALL MY TESTS (full list with status)
         ═══════════════════════════════════════════════════════════ -->
    <?php if (!empty($tests)): ?>
    <div class="cp-section">
      <div class="cp-section-title">
        <i class="fa-solid fa-laptop-code"></i> &nbsp;My Assigned Tests
      </div>
      <?php foreach ($tests as $t):
        $isCompleted  = in_array($t['sub_status'] ?? '', ['submitted','auto_submitted']);
        $isInProgress = $t['sub_status'] === 'in_progress';
        $isActive     = $t['status'] === 'active';
        $isExpired    = $t['status'] === 'expired';
        $pct          = round($t['percentage'] ?? 0);
        $barColor     = $pct>=80?'#10b981':($pct>=60?'#f59e0b':($pct>=40?'#6366f1':'#ef4444'));
      ?>
      <div class="test-card">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px">
          <div style="flex:1">
            <div style="color:#f1f5f9;font-size:15px;font-weight:600;margin-bottom:5px">
              <?= htmlspecialchars($t['title']) ?>
            </div>
            <div style="color:#64748b;font-size:12px;display:flex;gap:14px;flex-wrap:wrap">
              <span><i class="fa-solid fa-clock"></i> <?= $t['duration_minutes'] ?> mins</span>
              <span><i class="fa-solid fa-star"></i> <?= $t['total_marks'] ?> marks</span>
              <span><i class="fa-solid fa-check-circle"></i> Pass: <?= $t['passing_marks'] ?>%</span>
              <span><i class="fa-solid fa-calendar-xmark"></i> Expires: <?= htmlspecialchars($t['expiry_date'] ?? 'N/A') ?></span>
            </div>
          </div>
          <!-- Badge + score -->
          <div style="display:flex;align-items:center;gap:10px;flex-shrink:0">
            <?php if ($isCompleted): ?>
              <div class="score-pill">
                <div class="sv"><?= $pct ?>%</div>
                <div class="sl">Score</div>
              </div>
              <span class="bs bs-done">Completed</span>
            <?php elseif ($isInProgress): ?>
              <span class="bs bs-progress"><i class="fa-solid fa-spinner fa-spin" style="font-size:10px"></i> In Progress</span>
            <?php elseif ($isActive): ?>
              <span class="bs bs-active">Active</span>
            <?php elseif ($isExpired): ?>
              <span class="bs bs-expired">Expired</span>
            <?php else: ?>
              <span class="bs bs-pending">Pending</span>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($t['description']): ?>
          <p style="color:#64748b;font-size:13px;margin:0 0 12px"><?= htmlspecialchars($t['description']) ?></p>
        <?php endif; ?>

        <!-- ── ACTION BUTTONS ───────────────────────────────────
             FIX: show Start for active+not-started
                  show Resume for in_progress
                  show View Result for completed
        ─────────────────────────────────────────────────────── -->
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">

          <?php if ($isCompleted): ?>
            <!-- Completed: show result + score bar -->
            <a href="my_results.php?sub_id=<?= (int)$t['id'] ?>&cid=<?= $cand['id'] ?>"
               class="btn-view">
              <i class="fa-solid fa-chart-bar"></i> View My Result
            </a>
            <span style="color:#10b981;font-size:13px">
              <i class="fa-solid fa-check-circle"></i>
              Submitted <?= $t['submitted_at'] ? date('d M Y', strtotime($t['submitted_at'])) : '' ?>
            </span>
            <div style="flex-basis:100%">
              <div class="mini-bar-wrap">
                <div class="mini-bar-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div>
              </div>
            </div>

          <?php elseif ($isInProgress && $isActive): ?>
            <!-- In-progress: resume button -->
            <a href="take_test.php?token=<?= urlencode($t['test_link_token']) ?>"
               class="btn-resume">
              <i class="fa-solid fa-clock-rotate-left"></i> Resume Test
            </a>
            <div class="inprog-note">
              <i class="fa-solid fa-circle-info"></i>
              Test was started but not yet submitted — click Resume to continue.
            </div>

          <?php elseif ($isActive): ?>
            <!-- Active, not started: big start button -->
            <a href="take_test.php?token=<?= urlencode($t['test_link_token']) ?>"
               class="btn-start">
              <i class="fa-solid fa-play"></i> Start Test
            </a>

          <?php elseif ($isExpired): ?>
            <span style="color:#ef4444;font-size:13px">
              <i class="fa-solid fa-circle-xmark"></i> Test has expired
            </span>

          <?php else: ?>
            <span style="color:#f59e0b;font-size:13px">
              <i class="fa-solid fa-hourglass-half"></i> Pending activation by HR
            </span>
          <?php endif; ?>

        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════
         TEST ANALYTICS HISTORY
         ═══════════════════════════════════════════════════════════ -->
    <?php if (!empty($submissions)): ?>
    <div class="cp-section">
      <div class="cp-section-title">
        <i class="fa-solid fa-chart-bar"></i> &nbsp;My Test Analytics
      </div>
      <?php foreach ($submissions as $sub):
        $pct      = round($sub['percentage'] ?? 0);
        $pass     = $pct >= ($sub['passing_marks'] ?? 40);
        $barColor = $pct>=80?'#10b981':($pct>=60?'#f59e0b':($pct>=40?'#6366f1':'#ef4444'));
        // Check for pending subjective grading
        try {
          $hasPending = dbFetchOne(
            "SELECT COUNT(*) AS n FROM test_answers ta
             JOIN interview_questions iq ON iq.id=ta.question_id
             WHERE ta.submission_id=? AND iq.question_type='subjective' AND ta.hr_marks IS NULL",
            'i', $sub['id'])['n'] ?? 0;
        } catch (Throwable $e) { $hasPending = 0; }
      ?>
      <div class="result-row">
        <div style="flex:1;min-width:0">
          <h4 style="color:#e2e8f0;margin:0 0 4px;font-size:14px;font-weight:600">
            <?= htmlspecialchars($sub['test_title']) ?>
          </h4>
          <p style="color:#64748b;margin:0;font-size:12px">
            Submitted: <?= $sub['submitted_at'] ? date('d M Y, h:i A', strtotime($sub['submitted_at'])) : 'N/A' ?>
            &nbsp;•&nbsp; Time taken:
            <strong style="color:#a78bfa"><?= $sub['time_taken_mins'] ?> mins</strong>
            &nbsp;•&nbsp;
            <span style="color:<?= $pass?'#10b981':'#ef4444' ?>;font-weight:600">
              <?= $pass ? '✓ Passed' : '✗ Failed' ?>
            </span>
          </p>
          <div class="mini-bar-wrap" style="width:140px;margin-top:6px">
            <div class="mini-bar-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div>
          </div>
          <?php if ($hasPending): ?>
            <span style="display:inline-flex;align-items:center;gap:5px;margin-top:6px;background:rgba(245,158,11,.12);color:#f59e0b;border:1px solid rgba(245,158,11,.3);padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600">
              <i class="fa-solid fa-pen-to-square"></i> Subjective answers pending HR review
            </span>
          <?php endif; ?>
        </div>
        <div style="text-align:right;flex-shrink:0">
          <div style="font-size:26px;font-weight:800;color:<?= $barColor ?>;line-height:1"><?= $pct ?>%</div>
          <div style="color:#64748b;font-size:11px"><?= $sub['total_score'] ?>/<?= $sub['max_score'] ?> marks</div>
          <a href="my_results.php?sub_id=<?= $sub['test_ot_id'] ?>&cid=<?= $cand['id'] ?>"
             class="btn-view" style="margin-top:8px">
            <i class="fa-solid fa-eye"></i> Details
          </a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════
         MY PROFILE
         ═══════════════════════════════════════════════════════════ -->
    <div class="cp-section">
      <div class="cp-section-title"><i class="fa-solid fa-id-card"></i> &nbsp;My Profile</div>
      <div class="test-card">
        <div class="info-grid">
          <div class="info-item"><label>Full Name</label><span><?= htmlspecialchars($candidate['name']) ?></span></div>
          <div class="info-item"><label>Email</label><span><?= htmlspecialchars($candidate['email']) ?></span></div>
          <div class="info-item"><label>Phone</label><span><?= htmlspecialchars($candidate['phone'] ?? '—') ?></span></div>
          <div class="info-item"><label>Position Applied</label><span><?= htmlspecialchars($candidate['position'] ?? '—') ?></span></div>
          <div class="info-item"><label>Skills</label><span><?= htmlspecialchars($candidate['skills'] ?? '—') ?></span></div>
          <div class="info-item"><label>AI Match Score</label><span style="color:#a78bfa;font-weight:700"><?= $candidate['ai_score'] ?>%</span></div>
        </div>
      </div>
    </div>

  </div><!-- .cp-content -->
</div><!-- .cportal -->
</body>
</html>
