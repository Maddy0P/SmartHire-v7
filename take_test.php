<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Candidate Assessment Player (Module 8C) — thin entry point.
//  URL, token contract and the submit/ajax POST endpoints are preserved from the
//  legacy page. All logic flows through AssessmentService (Player API); this file
//  only routes requests and delegates rendering to modules/assessment/candidate/.
//  Timing + scoring are server-authoritative; the client timer is display-only.
// ═════════════════════════════════════════════════════════════════════════════
require_once 'includes/config.php';
require_once 'includes/recruitment.php';
require_once 'modules/assessment/bootstrap.php';
requireCandidateLogin();

use SmartHire\Assessment\Engine\AssessmentService;

$cand = currentCandidate();
$svc  = AssessmentService::production();
$token = $_GET['token'] ?? '';

if ($token === '') { header('Location: candidate_portal.php'); exit; }

// ── JSON API (autosave / nav / proctoring / remaining) — must precede any HTML ──
$api = $_POST['api'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $api !== '') {
    header('Content-Type: application/json');
    require_csrf(); // reads _csrf field or X-CSRF-Token header
    $bundle = $svc->openAttempt($token, (int)$cand['id']);
    if ($bundle['error'] !== null) { echo json_encode(['ok' => false, 'error' => $bundle['error']]); exit; }
    $test = $bundle['test']; $sid = (int)$bundle['submission']['id'];

    switch ($api) {
        case 'autosave':
            $qid = (int)($_POST['qid'] ?? 0);
            $q = $svc->submissions->questionForAnswer((int)$test['id'], $qid);
            if (!$q) { echo json_encode(['ok' => false, 'error' => 'bad_question']); exit; }
            $respRaw = $_POST['response'] ?? '';
            $response = $respRaw !== '' ? json_decode($respRaw, true) : null;
            $answer = is_array($response) ? $response : ($_POST['answer'] ?? '');
            $out = $svc->autosave($sid, $test, $q, $answer, is_array($response) ? $response : null,
                (int)($_POST['time_spent'] ?? 0), (int)($_POST['flag'] ?? 0), $_POST['client_ts'] ?? null);
            echo json_encode($out); exit;

        case 'nav':
            $flags = array_map('intval', json_decode($_POST['flags'] ?? '[]', true) ?: []);
            $svc->saveNav($sid, (int)($_POST['current'] ?? 0), $flags);
            echo json_encode(['ok' => true, 'remaining' => $svc->secondsRemaining($sid)]); exit;

        case 'proctor':
            $signals = json_decode($_POST['signals'] ?? '[]', true) ?: [];
            $res = $svc->recordProctoring($sid, $signals, $bundle['policy']);
            echo json_encode(['ok' => true] + $res + ['remaining' => $svc->secondsRemaining($sid)]); exit;

        case 'ping':
            echo json_encode(['ok' => true, 'remaining' => $svc->secondsRemaining($sid)]); exit;

        default:
            echo json_encode(['ok' => false, 'error' => 'unknown_api']); exit;
    }
}

// ── Legacy autosave shim (old client posted ajax_save) — kept for compatibility ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    header('Content-Type: application/json');
    $bundle = $svc->openAttempt($token, (int)$cand['id']);
    if ($bundle['error'] !== null) { echo json_encode(['ok' => false]); exit; }
    $test = $bundle['test']; $sid = (int)$bundle['submission']['id'];
    $q = $svc->submissions->questionForAnswer((int)$test['id'], (int)($_POST['qid'] ?? 0));
    if ($q) $svc->autosave($sid, $test, $q, $_POST['answer'] ?? '', null, (int)($_POST['time_spent'] ?? 0), 0, null);
    echo json_encode(['ok' => true]); exit;
}

// ── Full submit ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_test'])) {
    require_csrf();
    $bundle = $svc->openAttempt($token, (int)$cand['id']);
    if ($bundle['error'] !== null) { header('Location: candidate_portal.php'); exit; }
    $test = $bundle['test']; $sid = (int)$bundle['submission']['id'];

    // Persist any answers posted with the final form (covers no-JS + last edits).
    foreach ($svc->submissions->questionsForTest((int)$test['id']) as $q) {
        $qid = (int)$q['question_id'];
        if (!isset($_POST['ans_' . $qid]) && !isset($_POST['resp_' . $qid])) continue;
        $respRaw = $_POST['resp_' . $qid] ?? '';
        $response = $respRaw !== '' ? json_decode($respRaw, true) : null;
        $answer = is_array($response) ? $response : ($_POST['ans_' . $qid] ?? '');
        $svc->autosave($sid, $test, $q, $answer, is_array($response) ? $response : null,
            (int)($_POST['time_' . $qid] ?? 0), 0, null);
    }

    // Server decides auto-submit if its authoritative clock already expired.
    $auto = isset($_POST['auto_submit']) || $svc->secondsRemaining($sid) <= 0;
    $r = $svc->submitAttempt($sid, $test, (int)$cand['id'], $auto);

    // Preserve the exact legacy side-effects on the surrounding pipeline.
    try { sh_advance_candidate_applications((int)$cand['id'], 'online_test', 'Online test completed'); } catch (Throwable $e) {}
    dbExecute("UPDATE online_tests SET status='completed' WHERE id=?", 'i', (int)$test['id']);
    dbExecute("UPDATE candidates SET ai_score=GREATEST(ai_score,?) WHERE id=?", 'ii', (int)$r['pct'], (int)$cand['id']);
    addNotification('test_submitted', $cand['name'] . ' submitted "' . $test['title'] . '" — Score: ' . $r['pct'] . '%', (int)$cand['id']);

    // Audit trail (handbook Ch8 "Log: Assessment" + 6B-018). Auto-submit is logged
    // distinctly for timer observability (6B-010). No secrets recorded.
    audit_log($auto ? 'assessment_auto_submitted' : 'assessment_submitted', 'test_submission', $sid,
        'Test "' . $test['title'] . '" — ' . $r['pct'] . '% (' . $r['marks'] . '/' . $r['max'] . '), status=' . $r['status']);

    header('Location: test_complete.php?sid=' . $sid . '&score=' . $r['pct'] . '&marks=' . $r['marks'] . '&max=' . $r['max'] . '&time=' . $r['time']); exit;
}

// ── GET: render pre-assessment screen + player, or an error page ─────────────
$bundle = $svc->openAttempt($token, (int)$cand['id']);
if ($bundle['error'] !== null) {
    $map = [
        'invalid'      => ['Invalid test link or access denied.', 'danger'],
        'expired'      => ['This assessment has expired.', 'warn'],
        'completed'    => ['You have already completed this assessment.', 'ok'],
        'no_questions' => ['This assessment has no questions yet. Please contact HR.', 'warn'],
        'start_failed' => ['Could not start the assessment. Please retry.', 'danger'],
    ];
    [$msg, $tone] = $map[$bundle['error']] ?? ['Unable to open the assessment.', 'danger'];
    require __DIR__ . '/modules/assessment/candidate/_error.php';
    exit;
}

$test       = $bundle['test'];
$submission = $bundle['submission'];
$questions  = $bundle['questions'];
$saved      = $bundle['saved'];
$nav        = $bundle['nav'];
$policy     = $bundle['policy'];
$remaining  = (int)$bundle['remaining'];
$resumed    = (bool)$bundle['resumed'];
$totalQ     = count($questions);
$answeredCount = count(array_filter($saved, fn($a) => trim((string)($a['answer_text'] ?? '')) !== '' || !empty($a['response']) || (string)($a['selected_option'] ?? '') !== ''));
$flaggedIds = array_map('intval', $nav['flags'] ?? []);
$startAt    = (int)($nav['current'] ?? 0);

// Pre-assessment acknowledgment gate (skipped when resuming an in-flight attempt).
$showIntro = !$resumed && !isset($_GET['begin']);

require __DIR__ . '/modules/assessment/candidate/player.php';
