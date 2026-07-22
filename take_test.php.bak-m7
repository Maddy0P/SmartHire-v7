<?php
require_once 'includes/config.php';
require_once 'includes/recruitment.php';
requireCandidateLogin();
$cand = currentCandidate();

$token = $_GET['token'] ?? '';
if (!$token) { header('Location: candidate_portal.php'); exit; }

$test = dbFetchOne("SELECT * FROM online_tests WHERE test_link_token=? AND candidate_id=?", 'si', $token, $cand['id']);
if (!$test) { die('<div style="font-family:sans-serif;padding:40px;background:#0f172a;color:#f87171;min-height:100vh"><h2>Invalid test link or access denied.</h2></div>'); }
if ($test['status'] === 'expired') { die('<div style="font-family:sans-serif;padding:40px;background:#0f172a;color:#f59e0b;min-height:100vh"><h2>This test has expired.</h2><p><a href="candidate_portal.php" style="color:#818cf8">Back to Portal</a></p></div>'); }

$existing = dbFetchOne("SELECT * FROM test_submissions WHERE test_id=? AND candidate_id=? AND status IN ('submitted','auto_submitted')", 'ii', $test['id'], $cand['id']);
if ($existing) { header('Location: candidate_portal.php'); exit; }

$submission = dbFetchOne("SELECT * FROM test_submissions WHERE test_id=? AND candidate_id=? AND status='in_progress'", 'ii', $test['id'], $cand['id']);
if (!$submission) {
    $sid = dbExecute("INSERT INTO test_submissions (test_id,candidate_id,started_at,max_score,status) VALUES (?,?,NOW(),?,'in_progress')", 'iii', $test['id'], $cand['id'], $test['total_marks']);
    $submission = dbFetchOne("SELECT * FROM test_submissions WHERE id=?", 'i', $sid);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    $qid = (int)($_POST['qid'] ?? 0); $ans = $_POST['answer'] ?? ''; $timeSecs = (int)($_POST['time_spent'] ?? 0);
    $q = dbFetchOne("SELECT tq.*, iq.question_type, iq.correct_option, iq.max_score FROM test_questions tq JOIN interview_questions iq ON iq.id=tq.question_id WHERE tq.test_id=? AND tq.question_id=?", 'ii', $test['id'], $qid);
    if ($q) {
        $marks = 0; $correct = 0; $selected = null;
        if ($q['question_type'] === 'mcq') { $selected = $ans; if ($selected && $selected === $q['correct_option']) { $marks = $q['marks']; $correct = 1; } }
        dbExecute("INSERT INTO test_answers (submission_id,question_id,answer_text,selected_option,marks_awarded,is_correct,time_spent_secs) VALUES (?,?,?,?,?,?,?) ON CONFLICT (submission_id, question_id) DO UPDATE SET answer_text=EXCLUDED.answer_text,selected_option=EXCLUDED.selected_option,marks_awarded=EXCLUDED.marks_awarded,is_correct=EXCLUDED.is_correct,time_spent_secs=EXCLUDED.time_spent_secs", 'iissiii', $submission['id'], $qid, $ans, $selected ?? '', $marks, $correct, $timeSecs);
    }
    header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_test'])) {
    $questions = dbFetchAll("SELECT tq.*, iq.question_type, iq.correct_option FROM test_questions tq JOIN interview_questions iq ON iq.id=tq.question_id WHERE tq.test_id=? ORDER BY tq.order_no", 'i', $test['id']);
    $totalScore = 0;
    foreach ($questions as $q) {
        $ans = $_POST['ans_' . $q['question_id']] ?? ''; $timeSecs = (int)($_POST['time_' . $q['question_id']] ?? 0);
        $selected = null; $correct = 0; $marks = 0;
        if ($q['question_type'] === 'mcq') { $selected = $ans; if ($selected && $selected === $q['correct_option']) { $marks = $q['marks']; $correct = 1; } }
        $totalScore += $marks;
        dbExecute("INSERT INTO test_answers (submission_id,question_id,answer_text,selected_option,marks_awarded,is_correct,time_spent_secs) VALUES (?,?,?,?,?,?,?) ON CONFLICT (submission_id, question_id) DO UPDATE SET answer_text=EXCLUDED.answer_text,selected_option=EXCLUDED.selected_option,marks_awarded=EXCLUDED.marks_awarded,is_correct=EXCLUDED.is_correct,time_spent_secs=EXCLUDED.time_spent_secs", 'iissiii', $submission['id'], $q['question_id'], $ans, $selected ?? '', $marks, $correct, $timeSecs);
    }
    $pct = $test['total_marks'] > 0 ? round($totalScore / $test['total_marks'] * 100, 2) : 0;
    $timeTaken = max(1, (int)((time() - strtotime($submission['started_at'])) / 60));
    $status = isset($_POST['auto_submit']) ? 'auto_submitted' : 'submitted';
    $violationsCount = max(0, (int)($_POST['violations_count'] ?? 0));
    dbExecute("UPDATE test_submissions SET status=?, submitted_at=NOW(), total_score=?, percentage=?, time_taken_mins=?, violations=? WHERE id=?", 'sidiii', $status, $totalScore, $pct, $timeTaken, $violationsCount, $submission['id']);
    try { sh_advance_candidate_applications((int)$cand['id'], 'online_test', 'Online test completed'); } catch (Throwable $e) {}
    dbExecute("UPDATE online_tests SET status='completed' WHERE id=?", 'i', $test['id']);
    dbExecute("UPDATE candidates SET ai_score=GREATEST(ai_score,?) WHERE id=?", 'ii', (int)$pct, $cand['id']);
    addNotification('test_submitted', $cand['name'] . ' submitted "' . $test['title'] . '" — Score: ' . $pct . '%', $cand['id']);
    header('Location: test_complete.php?score=' . $pct . '&marks=' . $totalScore . '&max=' . $test['total_marks'] . '&time=' . $timeTaken); exit;
}

$questions = dbFetchAll("SELECT tq.*, iq.* FROM test_questions tq JOIN interview_questions iq ON iq.id=tq.question_id WHERE tq.test_id=? ORDER BY tq.order_no", 'i', $test['id']);
$totalQ = count($questions);
if ($totalQ === 0) {
    die('<div style="font-family:sans-serif;padding:40px;background:#0f172a;color:#f59e0b;min-height:100vh"><h2>⚠ This test has no questions yet.</h2><p>Please contact HR — the test has not been configured with questions.</p><p><a href="candidate_portal.php" style="color:#818cf8">← Back to Portal</a></p></div>');
}
$remaining = max(0, $test['duration_minutes'] * 60 - (time() - strtotime($submission['started_at'])));
$hasPerQ = array_sum(array_column($questions, 'time_limit_secs')) > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= htmlspecialchars($test['title']) ?> — SmartHire Exam</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#060d1a;--surface:#0d1829;--card:#111f35;--elevated:#162840;--border:rgba(148,163,184,.12);--accent:#7c3aed;--green:#10b981;--amber:#f59e0b;--rose:#f43f5e;--text:#f0f6ff;--muted:#94a3b8;--dim:#475569}
html,body{height:100%;overflow:hidden;background:var(--bg);font-family:'Inter',sans-serif;color:var(--text)}
button{font-family:inherit;cursor:pointer}
/* Instructions */
#instrOverlay{position:fixed;inset:0;background:var(--bg);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px}
.ibox{background:var(--surface);border:1px solid rgba(124,58,237,.4);border-radius:20px;max-width:700px;width:100%;padding:40px;box-shadow:0 0 60px rgba(124,58,237,.2)}
.ihead{display:flex;align-items:center;gap:16px;margin-bottom:24px}
.iicon{width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,#7c3aed,#4338ca);display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;flex-shrink:0}
.imeta{display:flex;gap:20px;flex-wrap:wrap;background:rgba(124,58,237,.08);border:1px solid rgba(124,58,237,.2);border-radius:12px;padding:16px 20px;margin-bottom:20px}
.imeta-item{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted)}
.imeta-item strong{color:var(--text)}
.imeta-item i{color:#a78bfa}
.rules{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:24px}
.rule{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:12px;padding:14px;display:flex;gap:12px;align-items:flex-start}
.rule-i{width:30px;height:30px;border-radius:8px;background:rgba(124,58,237,.12);color:#a78bfa;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
.rule-t{font-size:12.5px;color:var(--muted);line-height:1.5}
.rule-t strong{color:var(--text);display:block;margin-bottom:2px}
.btn-begin{background:linear-gradient(135deg,#7c3aed,#4338ca);color:#fff;border:none;padding:16px;border-radius:12px;font-size:15px;font-weight:700;width:100%;display:flex;align-items:center;justify-content:center;gap:10px;transition:all .2s;box-shadow:0 4px 24px rgba(124,58,237,.4)}
.btn-begin:hover{transform:translateY(-2px);box-shadow:0 8px 32px rgba(124,58,237,.5)}
/* Exam */
#examUI{display:none;height:100vh;flex-direction:column;overflow:hidden}
#examUI.active{display:flex}
.topbar{background:linear-gradient(135deg,#7c3aed,#4338ca);padding:10px 20px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-shrink:0;box-shadow:0 4px 20px rgba(0,0,0,.5);z-index:50}
.topbar h1{font-size:14px;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:320px}
.ttimer{display:flex;align-items:center;gap:8px;background:rgba(0,0,0,.35);border:1.5px solid rgba(255,255,255,.25);border-radius:8px;padding:5px 14px}
.ttimer-val{font-size:18px;font-weight:800;color:#fff;font-variant-numeric:tabular-nums}
.ttimer.warn .ttimer-val{color:#fbbf24}
.ttimer.danger .ttimer-val{color:#f87171;animation:blink 1s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.4}}
.fsbtn{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);color:#fff;padding:6px 14px;border-radius:8px;font-size:12px;font-weight:600;display:flex;align-items:center;gap:6px}
.fsbtn:hover{background:rgba(255,255,255,.2)}
.body-wrap{display:flex;flex:1;overflow:hidden}
/* Sidebar */
.sidebar{width:190px;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;flex-shrink:0}
.sb-head{padding:12px 14px;border-bottom:1px solid var(--border);font-size:10px;font-weight:700;color:var(--dim);text-transform:uppercase;letter-spacing:.8px}
.qgrid{display:grid;grid-template-columns:repeat(4,1fr);gap:5px;padding:12px;overflow-y:auto;flex:1}
.qdot{width:32px;height:32px;border-radius:8px;border:1.5px solid var(--border);background:rgba(255,255,255,.03);cursor:pointer;font-size:11px;font-weight:700;color:var(--dim);display:flex;align-items:center;justify-content:center;transition:all .18s;position:relative}
.qdot:hover{background:rgba(124,58,237,.15)}
.qdot.cur{border-color:#7c3aed;background:rgba(124,58,237,.2);color:#c4b5fd}
.qdot.ans{background:rgba(16,185,129,.14);border-color:rgba(16,185,129,.4);color:#10b981}
.qdot.tout{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.3);color:#f87171}
.qdot.flg::after{content:'';position:absolute;top:3px;right:3px;width:6px;height:6px;border-radius:50%;background:#f59e0b}
.sb-legend{padding:10px 12px;border-top:1px solid var(--border);font-size:10px;color:var(--dim)}
.leg-row{display:flex;align-items:center;gap:6px;margin-bottom:3px}
.leg-dot{width:9px;height:9px;border-radius:3px;flex-shrink:0}
/* Main */
.main-area{flex:1;overflow-y:auto;background:var(--bg)}
.main-area::-webkit-scrollbar{width:5px}
.main-area::-webkit-scrollbar-thumb{background:var(--elevated);border-radius:3px}
.qpane{display:none;padding:28px 32px;max-width:820px;margin:0 auto}
.qpane.active{display:block;animation:slin .22s ease}
@keyframes slin{from{opacity:0;transform:translateX(14px)}to{opacity:1;transform:translateX(0)}}
/* Per-Q timer */
.qtblock{margin-bottom:18px}
.qtrow{display:flex;justify-content:space-between;align-items:center;margin-bottom:5px}
.qtlbl{font-size:11px;color:var(--dim)}
.qtval{font-size:14px;font-weight:800;color:#a78bfa;font-variant-numeric:tabular-nums}
.qtval.warn{color:#f59e0b}.qtval.danger{color:#f87171}
.qtbarwrap{height:5px;background:rgba(255,255,255,.07);border-radius:99px;overflow:hidden}
.qtbar{height:100%;border-radius:99px;background:linear-gradient(90deg,#7c3aed,#a78bfa);transition:width .9s linear}
.qtbar.warn{background:linear-gradient(90deg,#f59e0b,#fbbf24)}
.qtbar.danger{background:linear-gradient(90deg,#ef4444,#f87171)}
.tout-notice{background:rgba(239,68,68,.09);border:1px solid rgba(239,68,68,.3);border-radius:10px;padding:10px 16px;display:none;align-items:center;gap:10px;color:#f87171;font-size:13px;font-weight:600;margin-bottom:16px}
.tout-notice.show{display:flex}
/* Q card */
.qhead{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:12px}
.qnum{font-size:11px;font-weight:700;color:#a78bfa;text-transform:uppercase;letter-spacing:.8px}
.qtags{display:flex;gap:6px;flex-wrap:wrap;margin-top:5px}
.qtag{padding:3px 10px;border-radius:100px;font-size:10.5px;font-weight:600}
.tag-m{background:rgba(99,102,241,.15);color:#818cf8}
.tag-s{background:rgba(16,185,129,.15);color:#10b981}
.tag-mk{background:rgba(245,158,11,.15);color:#f59e0b}
.tag-c{background:rgba(148,163,184,.1);color:var(--muted)}
.qtext{font-size:15.5px;color:var(--text);line-height:1.7;margin-bottom:22px;font-weight:500}
/* MCQ */
.mcqlist{display:flex;flex-direction:column;gap:10px}
.mcqopt{display:flex;align-items:flex-start;gap:14px;padding:14px 18px;border:1.5px solid var(--border);border-radius:12px;cursor:pointer;transition:all .18s;background:rgba(255,255,255,.02)}
.mcqopt:hover{border-color:rgba(124,58,237,.4);background:rgba(124,58,237,.06)}
.mcqopt.sel{border-color:rgba(124,58,237,.7);background:rgba(124,58,237,.12)}
.mcqopt.dis{opacity:.55;pointer-events:none}
.optlet{width:28px;height:28px;border-radius:7px;background:rgba(255,255,255,.08);font-size:12px;font-weight:800;color:var(--muted);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .18s}
.mcqopt.sel .optlet{background:rgba(124,58,237,.5);color:#fff}
.opttext{font-size:14px;color:var(--text);line-height:1.6;flex:1}
.mcqopt input{display:none}
/* Subjective */
.subj{width:100%;min-height:120px;background:rgba(255,255,255,.03);border:1.5px solid var(--border);border-radius:12px;padding:14px;color:var(--text);font-size:14px;font-family:'Inter',sans-serif;resize:vertical;transition:border-color .2s;line-height:1.7}
.subj:focus{outline:none;border-color:#7c3aed}
.wc{font-size:11px;color:var(--dim);margin-top:5px;text-align:right}
/* Nav */
.navrow{display:flex;justify-content:space-between;align-items:center;margin-top:22px;padding-top:16px;border-top:1px solid var(--border)}
.nbtn{display:flex;align-items:center;gap:7px;padding:10px 20px;border-radius:9px;font-size:13px;font-weight:600;border:none;transition:all .2s}
.nprev{background:var(--elevated);color:var(--muted);border:1px solid var(--border)}
.nprev:hover{color:var(--text)}
.nnext{background:linear-gradient(135deg,#7c3aed,#4338ca);color:#fff}
.nnext:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(124,58,237,.4)}
.nflag{background:rgba(245,158,11,.1);color:var(--amber);border:1px solid rgba(245,158,11,.25);padding:8px 14px;border-radius:8px;font-size:12px}
.nflag.flg{background:rgba(245,158,11,.22);border-color:rgba(245,158,11,.5)}
/* Bottom bar */
.bottombar{background:var(--surface);border-top:1px solid var(--border);padding:12px 24px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-shrink:0;z-index:40}
.progwrap{flex:1;max-width:280px}
.proglbl{font-size:11px;color:var(--muted);margin-bottom:4px}
.progbg{height:7px;background:rgba(255,255,255,.07);border-radius:99px;overflow:hidden}
.progfill{height:100%;border-radius:99px;background:linear-gradient(90deg,#7c3aed,#10b981);transition:width .4s}
.bstats{display:flex;gap:18px}
.bstat{text-align:center}
.bstat-v{font-size:16px;font-weight:800;color:var(--text)}
.bstat-l{font-size:10px;color:var(--dim);text-transform:uppercase;letter-spacing:.5px}
.btn-sub{background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;border:none;padding:11px 26px;border-radius:10px;font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;transition:all .2s;box-shadow:0 4px 14px rgba(239,68,68,.3)}
.btn-sub:hover{transform:translateY(-1px);box-shadow:0 6px 22px rgba(239,68,68,.45)}
/* Overlays */
#confirmOvl{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:8000;display:none;align-items:center;justify-content:center;backdrop-filter:blur(5px)}
#confirmOvl.show{display:flex}
.cbox{background:var(--card);border:1px solid rgba(148,163,184,.2);border-radius:16px;padding:32px;max-width:400px;width:100%;text-align:center}
.cbox h3{font-size:18px;font-weight:800;margin-bottom:8px}
.cbox p{font-size:13.5px;color:var(--muted);margin-bottom:22px;line-height:1.6}
.cbtns{display:flex;gap:10px;justify-content:center}
#pWarn{position:fixed;top:0;left:0;right:0;z-index:7999;background:linear-gradient(90deg,#dc2626,#ef4444);padding:10px 24px;display:none;align-items:center;justify-content:space-between;font-size:13px;font-weight:600;color:#fff;box-shadow:0 4px 16px rgba(239,68,68,.5)}
#pWarn.show{display:flex}
@media(max-width:768px){.sidebar{display:none}.qpane{padding:16px}}
</style>
</head>
<body>

<!-- Instructions -->
<div id="instrOverlay">
  <div class="ibox">
    <div class="ihead">
      <div class="iicon"><i class="fa-solid fa-laptop-code"></i></div>
      <div>
        <div style="font-size:20px;font-weight:800"><?= htmlspecialchars($test['title']) ?></div>
        <div style="font-size:13px;color:var(--muted);margin-top:3px">SmartHire Online Assessment — Read carefully before starting</div>
      </div>
    </div>
    <div class="imeta">
      <div class="imeta-item"><i class="fa-solid fa-clock"></i><span><strong><?= $test['duration_minutes'] ?> min</strong> total</span></div>
      <div class="imeta-item"><i class="fa-solid fa-circle-question"></i><span><strong><?= $totalQ ?></strong> questions</span></div>
      <div class="imeta-item"><i class="fa-solid fa-star"></i><span><strong><?= $test['total_marks'] ?></strong> marks</span></div>
      <div class="imeta-item"><i class="fa-solid fa-check-circle"></i><span>Pass: <strong><?= $test['passing_marks'] ?>%</strong></span></div>
      <?php if ($hasPerQ): ?><div class="imeta-item"><i class="fa-solid fa-stopwatch"></i><span><strong>Per-Q timer</strong> active</span></div><?php endif; ?>
    </div>
    <div class="rules">
      <div class="rule"><div class="rule-i"><i class="fa-solid fa-expand"></i></div><div class="rule-t"><strong>Fullscreen Enforced</strong>Exam runs in fullscreen. Exiting 3x auto-submits the test.</div></div>
      <div class="rule"><div class="rule-i"><i class="fa-solid fa-eye"></i></div><div class="rule-t"><strong>No Tab Switching</strong>Switching tabs/windows is detected and counted as a violation.</div></div>
      <?php if ($hasPerQ): ?>
      <div class="rule"><div class="rule-i"><i class="fa-solid fa-stopwatch"></i></div><div class="rule-t"><strong>Per-Question Timer</strong>Each question has its own countdown. Expires = auto-locked & next.</div></div>
      <?php endif; ?>
      <div class="rule"><div class="rule-i"><i class="fa-solid fa-clock-rotate-left"></i></div><div class="rule-t"><strong>Overall Countdown</strong>Timer visible at top. Reaches zero = test auto-submitted.</div></div>
      <div class="rule"><div class="rule-i"><i class="fa-solid fa-bookmark"></i></div><div class="rule-t"><strong>Flag for Review</strong>Flag uncertain questions to revisit using sidebar navigator.</div></div>
      <div class="rule"><div class="rule-i"><i class="fa-solid fa-paper-plane"></i></div><div class="rule-t"><strong>Final Submission</strong>Once submitted you cannot change answers. Review before submitting.</div></div>
    </div>
    <button class="btn-begin" onclick="startExam()"><i class="fa-solid fa-play"></i> Begin Examination</button>
    <div style="text-align:center;margin-top:12px;font-size:12px;color:var(--dim)"><i class="fa-solid fa-expand"></i> Clicking Begin will enter fullscreen mode</div>
  </div>
</div>

<!-- Proctoring warning bar -->
<div id="pWarn">
  <div><i class="fa-solid fa-triangle-exclamation"></i> &nbsp;<span id="pMsg">Warning!</span></div>
  <span id="pCount" style="font-size:12px;opacity:.85">Violation 1/3</span>
</div>

<!-- Exam UI -->
<div id="examUI">
  <div class="topbar">
    <h1><i class="fa-solid fa-bolt" style="margin-right:8px;opacity:.8"></i><?= htmlspecialchars($test['title']) ?></h1>
    <div style="display:flex;align-items:center;gap:12px">
      <span style="font-size:12px;color:rgba(255,255,255,.65)" id="qctr">Q 1 of <?= $totalQ ?></span>
      <div class="ttimer" id="ttWrap"><div><div style="font-size:9px;color:rgba(255,255,255,.55);text-transform:uppercase;letter-spacing:.5px">Time Left</div><div class="ttimer-val" id="ttVal">--:--</div></div></div>
    </div>
    <button class="fsbtn" onclick="reqFS()" id="fsBtn"><i class="fa-solid fa-expand"></i> Fullscreen</button>
  </div>

  <div class="body-wrap">
    <div class="sidebar">
      <div class="sb-head">Questions &nbsp;<span id="sbProg" style="color:var(--muted);font-weight:400">0/<?= $totalQ ?></span></div>
      <div class="qgrid" id="qgrid">
        <?php foreach ($questions as $i => $q): ?>
        <div class="qdot <?= $i===0?'cur':'' ?>" id="qdot<?= $i ?>" onclick="goQ(<?= $i ?>)"><?= $i+1 ?></div>
        <?php endforeach; ?>
      </div>
      <div class="sb-legend">
        <div class="leg-row"><div class="leg-dot" style="background:rgba(16,185,129,.5)"></div>Answered</div>
        <div class="leg-row"><div class="leg-dot" style="background:rgba(124,58,237,.5)"></div>Current</div>
        <div class="leg-row"><div class="leg-dot" style="background:rgba(245,158,11,.5)"></div>Flagged</div>
        <div class="leg-row"><div class="leg-dot" style="background:rgba(239,68,68,.3)"></div>Expired</div>
      </div>
    </div>

    <div class="main-area" id="mainArea">
      <form method="POST" id="tf">
        <?php foreach ($questions as $i => $q):
          $qTL = (int)($q['time_limit_secs'] ?? 0);
        ?>
        <div class="qpane <?= $i===0?'active':'' ?>" id="qp<?= $i ?>">
          <?php if ($qTL > 0): ?>
          <div class="qtblock">
            <div class="qtrow">
              <span class="qtlbl"><i class="fa-solid fa-stopwatch"></i> Question timer</span>
              <span class="qtval" id="qtv<?= $i ?>"><?= $qTL ?>s</span>
            </div>
            <div class="qtbarwrap"><div class="qtbar" id="qtb<?= $i ?>" style="width:100%"></div></div>
          </div>
          <?php endif; ?>
          <div class="tout-notice" id="tout<?= $i ?>"><i class="fa-solid fa-clock-rotate-left"></i> Time limit reached — question locked.</div>
          <div class="qhead">
            <div>
              <div class="qnum">Question <?= $i+1 ?> of <?= $totalQ ?></div>
              <div class="qtags">
                <span class="qtag <?= $q['question_type']==='mcq'?'tag-m':'tag-s' ?>"><?= $q['question_type']==='mcq'?'Multiple Choice':'Written Answer' ?></span>
                <span class="qtag tag-mk"><i class="fa-solid fa-star"></i> <?= $q['marks'] ?> marks</span>
                <span class="qtag tag-c"><?= ucfirst(str_replace('_',' ',$q['category'])) ?></span>
                <?php if ($q['difficulty']): ?>
                <span class="qtag" style="background:rgba(<?= $q['difficulty']==='easy'?'16,185,129':($q['difficulty']==='hard'?'244,63,94':'245,158,11') ?>,.12);color:<?= $q['difficulty']==='easy'?'#10b981':($q['difficulty']==='hard'?'#f43f5e':'#f59e0b') ?>"><?= ucfirst($q['difficulty']) ?></span>
                <?php endif; ?>
              </div>
            </div>
            <button type="button" class="nflag" id="flgbtn<?= $i ?>" onclick="toggleFlag(<?= $i ?>)"><i class="fa-solid fa-flag"></i> Flag</button>
          </div>
          <div class="qtext"><?= nl2br(htmlspecialchars($q['question'])) ?></div>
          <?php if ($q['question_type'] === 'mcq'): ?>
          <div class="mcqlist" id="ml<?= $i ?>">
            <?php foreach (['a'=>$q['option_a'],'b'=>$q['option_b'],'c'=>$q['option_c'],'d'=>$q['option_d']] as $opt=>$txt): ?>
            <?php if (!$txt) continue; ?>
            <div class="mcqopt" id="mopt<?= $i ?>_<?= $opt ?>" onclick="selMCQ(<?= $i ?>,<?= $q['question_id'] ?>,'<?= $opt ?>')">
              <input type="radio" name="ans_<?= $q['question_id'] ?>" value="<?= $opt ?>">
              <div class="optlet"><?= strtoupper($opt) ?></div>
              <div class="opttext"><?= htmlspecialchars($txt) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <textarea class="subj" name="ans_<?= $q['question_id'] ?>" id="sa<?= $q['question_id'] ?>" placeholder="Type your detailed answer here…" oninput="onSubj(<?= $i ?>,<?= $q['question_id'] ?>,this)"></textarea>
          <div class="wc" id="wc<?= $q['question_id'] ?>">0 words</div>
          <?php endif; ?>
          <input type="hidden" name="time_<?= $q['question_id'] ?>" id="tf<?= $q['question_id'] ?>" value="0">
          <div class="navrow">
            <?php if ($i > 0): ?><button type="button" class="nbtn nprev" onclick="goQ(<?= $i-1 ?>)"><i class="fa-solid fa-arrow-left"></i> Previous</button><?php else: ?><div></div><?php endif; ?>
            <?php if ($i < $totalQ-1): ?><button type="button" class="nbtn nnext" onclick="goQ(<?= $i+1 ?>)">Next <i class="fa-solid fa-arrow-right"></i></button>
            <?php else: ?><button type="button" class="nbtn nnext" onclick="showCnf()" style="background:linear-gradient(135deg,#ef4444,#dc2626)"><i class="fa-solid fa-paper-plane"></i> Submit Test</button><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <input type="hidden" name="submit_test" value="1">
        <input type="hidden" name="violations_count" id="violationsInput" value="0">
      </form>
    </div>
  </div>

  <div class="bottombar">
    <div class="progwrap">
      <div class="proglbl"><span id="acbot">0</span>/<?= $totalQ ?> answered</div>
      <div class="progbg"><div class="progfill" id="pf" style="width:0%"></div></div>
    </div>
    <div class="bstats">
      <div class="bstat"><div class="bstat-v" id="bs_a">0</div><div class="bstat-l">Answered</div></div>
      <div class="bstat"><div class="bstat-v" id="bs_f">0</div><div class="bstat-l">Flagged</div></div>
      <div class="bstat"><div class="bstat-v" id="bs_r"><?= $totalQ ?></div><div class="bstat-l">Remaining</div></div>
    </div>
    <button type="button" class="btn-sub" onclick="showCnf()"><i class="fa-solid fa-paper-plane"></i> Submit</button>
  </div>
</div>

<!-- Confirm -->
<div id="confirmOvl">
  <div class="cbox">
    <div style="font-size:36px;margin-bottom:12px">📋</div>
    <h3>Submit Examination?</h3>
    <p>You answered <strong id="ca">0</strong>/<?= $totalQ ?> questions.<br><span id="cu" style="color:#f59e0b"></span><br>Submitted answers are final.</p>
    <div class="cbtns">
      <button class="nbtn nprev" style="padding:11px 22px" onclick="hideCnf()"><i class="fa-solid fa-arrow-left"></i> Review</button>
      <button class="nbtn nnext" style="background:linear-gradient(135deg,#ef4444,#dc2626);padding:11px 22px" onclick="doSubmit()"><i class="fa-solid fa-paper-plane"></i> Yes, Submit</button>
    </div>
  </div>
</div>

<script>
const TQ = <?= $totalQ ?>;
const qIds = <?= json_encode(array_column($questions,'question_id')) ?>;
const qTLs = <?= json_encode(array_column($questions,'time_limit_secs')) ?>;
let totalRem = <?= $remaining ?>;
let curQ = 0, violations = 0;
const answered = new Set(), flagged = new Set(), timedOut = new Set();
let qTLeft = {}, qTSpent = {}, qTInt = {}, qTStart = {};
let examGo = false;

qIds.forEach((id,i)=>{ qTLeft[i]=qTLs[i]||0; qTSpent[i]=0; });
const pad=n=>n<10?'0'+n:n;

function startExam(){
  document.getElementById('instrOverlay').style.display='none';
  document.getElementById('examUI').classList.add('active');
  examGo=true;
  tickTotal(); setInterval(tickTotal,1000);
  startQT(0); reqFS(); bindProc();
  window.onbeforeunload=()=>'Exam in progress.';
}
function tickTotal(){
  const m=Math.floor(totalRem/60),s=totalRem%60;
  const el=document.getElementById('ttVal'),w=document.getElementById('ttWrap');
  el.textContent=pad(m)+':'+pad(s);
  w.className='ttimer'+(totalRem<=300?' warn':'')+(totalRem<=60?' danger':'');
  if(totalRem<=0){autoSub();return;}
  totalRem--;
}
function startQT(i){
  const lim=qTLs[i]||0; if(!lim) return;
  qTStart[i]=Date.now();
  if(qTInt[i]) clearInterval(qTInt[i]);
  qTInt[i]=setInterval(()=>tickQT(i),500); tickQT(i);
}
function stopQT(i){
  if(qTInt[i]) clearInterval(qTInt[i]);
  if(qTStart[i]){ qTSpent[i]=(qTSpent[i]||0)+Math.round((Date.now()-qTStart[i])/1000); const tf=document.getElementById('tf'+qIds[i]); if(tf) tf.value=qTSpent[i]; }
}
function tickQT(i){
  const lim=qTLs[i]||0; if(!lim||timedOut.has(i)) return;
  const spent=Math.round((Date.now()-qTStart[i])/1000)+(qTSpent[i]||0);
  const left=Math.max(0,lim-spent); qTLeft[i]=left;
  const bar=document.getElementById('qtb'+i), cnt=document.getElementById('qtv'+i);
  if(!bar||!cnt) return;
  bar.style.width=(left/lim*100)+'%';
  cnt.textContent=left+'s';
  const dg=left<=lim*.1, wn=left<=lim*.35&&!dg;
  bar.className='qtbar'+(dg?' danger':wn?' warn':'');
  cnt.className='qtval'+(dg?' danger':wn?' warn':'');
  if(left<=0){ clearInterval(qTInt[i]); onExpire(i); }
}
function onExpire(i){
  timedOut.add(i); updDot(i);
  const n=document.getElementById('tout'+i); if(n) n.classList.add('show');
  const pane=document.getElementById('qp'+i);
  if(pane){ pane.querySelectorAll('.mcqopt').forEach(o=>o.classList.add('dis')); const ta=pane.querySelector('textarea'); if(ta) ta.disabled=true; }
  if(i<TQ-1) setTimeout(()=>goQ(i+1),1500);
}
function goQ(i){
  if(i<0||i>=TQ) return;
  stopQT(curQ);
  document.getElementById('qp'+curQ).classList.remove('active');
  document.getElementById('qdot'+curQ).classList.remove('cur');
  curQ=i;
  document.getElementById('qp'+i).classList.add('active');
  document.getElementById('qdot'+i).classList.add('cur');
  document.getElementById('qctr').textContent='Q '+(i+1)+' of '+TQ;
  document.getElementById('mainArea').scrollTo({top:0,behavior:'smooth'});
  if(!timedOut.has(i)) startQT(i);
}
function selMCQ(i,qid,opt){
  if(timedOut.has(i)) return;
  ['a','b','c','d'].forEach(o=>{ const el=document.getElementById('mopt'+i+'_'+o); if(el) el.classList.toggle('sel',o===opt); });
  const r=document.querySelector(`input[name="ans_${qid}"][value="${opt}"]`); if(r) r.checked=true;
  markAns(i);
  ajaxSave(qid,opt,qTSpent[i]||0);
}
function onSubj(i,qid,el){
  const w=el.value.trim().split(/\s+/).filter(Boolean).length;
  const wc=document.getElementById('wc'+qid); if(wc) wc.textContent=w+' word'+(w!==1?'s':'');
  if(el.value.trim().length>2) markAns(i); else { answered.delete(i); updStats(); updDot(i); }
}
function markAns(i){ answered.add(i); updStats(); updDot(i); }
function toggleFlag(i){
  const btn=document.getElementById('flgbtn'+i);
  if(flagged.has(i)){ flagged.delete(i); btn.classList.remove('flg'); btn.innerHTML='<i class="fa-solid fa-flag"></i> Flag'; }
  else { flagged.add(i); btn.classList.add('flg'); btn.innerHTML='<i class="fa-solid fa-flag"></i> Flagged'; }
  updStats(); updDot(i);
}
function updDot(i){
  const d=document.getElementById('qdot'+i); if(!d) return;
  d.className='qdot'+(i===curQ?' cur':'')+(timedOut.has(i)?' tout':answered.has(i)?' ans':'')+(flagged.has(i)?' flg':'');
}
function updStats(){
  const a=answered.size,f=flagged.size,r=TQ-a;
  ['sbProg','acbot'].forEach(id=>{const e=document.getElementById(id);if(e)e.textContent=a+'/'+TQ;});
  document.getElementById('bs_a').textContent=a;
  document.getElementById('bs_f').textContent=f;
  document.getElementById('bs_r').textContent=r;
  document.getElementById('pf').style.width=(a/TQ*100)+'%';
}
function ajaxSave(qid,ans,time){
  const fd=new FormData(); fd.append('ajax_save','1'); fd.append('qid',qid); fd.append('answer',ans); fd.append('time_spent',time);
  fetch(window.location.href,{method:'POST',body:fd}).catch(()=>{});
}
function showCnf(){ stopQT(curQ); document.getElementById('ca').textContent=answered.size; const u=TQ-answered.size; document.getElementById('cu').textContent=u>0?u+' unanswered question'+(u>1?'s':'')+'will score zero.':''; document.getElementById('confirmOvl').classList.add('show'); }
function hideCnf(){ document.getElementById('confirmOvl').classList.remove('show'); startQT(curQ); }
function syncViolations(){ document.getElementById('violationsInput').value = violations; }
function doSubmit(){ window.onbeforeunload=null; stopQT(curQ); syncViolations(); document.getElementById('tf').submit(); }
function autoSub(){ window.onbeforeunload=null; stopQT(curQ); syncViolations(); const h=document.createElement('input');h.type='hidden';h.name='auto_submit';h.value='1';document.getElementById('tf').appendChild(h); document.getElementById('tf').submit(); }
function reqFS(){ const e=document.documentElement; (e.requestFullscreen||e.webkitRequestFullscreen||e.mozRequestFullScreen||function(){}).call(e); document.getElementById('fsBtn').style.display='none'; }
function onFSC(){ const fs=!!(document.fullscreenElement||document.webkitFullscreenElement||document.mozFullScreenElement); if(!fs&&examGo){ document.getElementById('fsBtn').style.display=''; addViol('Fullscreen exited! Click Fullscreen to re-enter.'); } else if(fs) document.getElementById('fsBtn').style.display='none'; }
document.addEventListener('fullscreenchange',onFSC); document.addEventListener('webkitfullscreenchange',onFSC); document.addEventListener('mozfullscreenchange',onFSC);
function addViol(msg){ violations++; syncViolations(); document.getElementById('pMsg').textContent=msg; document.getElementById('pCount').textContent='Violation '+violations+'/3'; document.getElementById('pWarn').classList.add('show'); setTimeout(()=>document.getElementById('pWarn').classList.remove('show'),5000); if(violations>=3) setTimeout(()=>{alert('Max violations reached. Submitting.'); autoSub();},800); }
function bindProc(){
  document.addEventListener('visibilitychange',()=>{ if(document.hidden&&examGo) addViol('Tab switch detected!'); });
  window.addEventListener('blur',()=>{ if(examGo) addViol('Window focus lost!'); });
  document.addEventListener('contextmenu',e=>e.preventDefault());
  document.addEventListener('copy',e=>{if(examGo)e.preventDefault();});
  document.addEventListener('keydown',e=>{ if(examGo&&(e.ctrlKey||e.metaKey)&&['c','v','u','p','s'].includes(e.key.toLowerCase())) e.preventDefault(); if(e.key==='F12') e.preventDefault(); });
}
</script>
</body>
</html>
