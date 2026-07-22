<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Assessment Player — Module 8C candidate-delivery tests (service-facade level).
//  Required by tests/run_tests.php after the 8B tests. No real DB: an in-memory
//  FakeDb routes the Player-API SQL surface. Verifies server-authoritative timing,
//  autosave (auto-score + defer), attempt lifecycle, anti-cheat normalisation,
//  submission re-scoring, and the registry-driven QuestionRenderer.
// ═════════════════════════════════════════════════════════════════════════════

use SmartHire\Assessment\Engine\AssessmentService;
use SmartHire\Assessment\Engine\QuestionRenderer;
use SmartHire\Assessment\Shared\DbAdapter;
use SmartHire\Assessment\Shared\Events;
use SmartHire\Assessment\Shared\AntiCheat;

section('Module 8C — Candidate Player');

$fake8c = new class implements DbAdapter {
    public array $test = ['id' => 50, 'title' => 'Backend Screen', 'candidate_id' => 9, 'duration_minutes' => 30,
        'total_marks' => 25, 'passing_marks' => 40, 'status' => 'active', 'test_link_token' => 'tok123',
        'description' => 'Do well', 'config' => null, 'expiry_date' => null];
    public array $questions = [
        101 => ['id' => 101, 'question_id' => 101, 'marks' => 10, 'order_no' => 1, 'time_limit_secs' => 0,
                'question_type' => 'mcq', 'correct_option' => 'b', 'answer_key' => null, 'max_score' => 10,
                'question' => 'Q1', 'option_a' => 'A', 'option_b' => 'B', 'option_c' => 'C', 'option_d' => 'D',
                'category' => 'technical', 'difficulty' => 'easy', 'skills' => 'SQL', 'metadata' => '{}', 'section_id' => null],
        102 => ['id' => 102, 'question_id' => 102, 'marks' => 10, 'order_no' => 2, 'time_limit_secs' => 60,
                'question_type' => 'multi_select', 'correct_option' => '', 'answer_key' => '{"correct":["a","c"]}', 'max_score' => 10,
                'question' => 'Q2', 'option_a' => 'A', 'option_b' => 'B', 'option_c' => 'C', 'option_d' => 'D',
                'category' => 'technical', 'difficulty' => 'hard', 'skills' => 'SQL', 'metadata' => '{}', 'section_id' => null],
        103 => ['id' => 103, 'question_id' => 103, 'marks' => 5, 'order_no' => 3, 'time_limit_secs' => 0,
                'question_type' => 'subjective', 'correct_option' => null, 'answer_key' => null, 'max_score' => 5,
                'question' => 'Q3', 'option_a' => '', 'option_b' => '', 'option_c' => '', 'option_d' => '',
                'category' => 'hr', 'difficulty' => 'medium', 'skills' => 'Communication', 'metadata' => '{}', 'section_id' => null],
    ];
    public ?array $active = null;                 // in-progress attempt (null = none)
    public ?array $completed = null;              // completed attempt (null = none)
    public array $answers = [];                   // question_id => saved answer row
    public array $writes = [];                    // captured SQL verbs
    public int $remaining = 1500;
    private int $nextSid = 500;

    public function fetchOne(string $sql, string $t = '', mixed ...$p): ?array {
        if (str_contains($sql, 'FROM online_tests WHERE test_link_token')) return $this->test['test_link_token'] === $p[0] && (int)$this->test['candidate_id'] === (int)$p[1] ? $this->test : null;
        if (str_contains($sql, "status IN ('submitted','auto_submitted')")) return $this->completed;
        if (str_contains($sql, "status='in_progress'")) return $this->active;
        if (str_contains($sql, 'EXTRACT(EPOCH')) return ['s' => $this->remaining];
        if (str_contains($sql, 'FROM test_submissions WHERE id=?')) return $this->active ?? $this->completed;
        if (str_contains($sql, 'SELECT config FROM online_tests')) return ['config' => $this->test['config']];
        if (str_contains($sql, 'tq.question_id=?')) return $this->questions[(int)$p[1]] ?? null;
        if (str_contains($sql, 'COUNT(*) n FROM test_submissions')) return ['n' => $this->completed ? 1 : 0];
        return null;
    }
    public function fetchAll(string $sql, string $t = '', mixed ...$p): array {
        if (str_contains($sql, 'FROM test_questions tq JOIN interview_questions iq')) return array_values($this->questions);
        if (str_contains($sql, 'FROM test_answers WHERE submission_id')) return array_values($this->answers);
        if (str_contains($sql, 'FROM test_answers ta')) {  // resultFor answersWithQuestions
            $out = [];
            foreach ($this->answers as $qid => $a) {
                $q = $this->questions[$qid];
                $out[] = $a + ['question_type' => $q['question_type'], 'difficulty' => $q['difficulty'],
                    'skills' => $q['skills'], 'weight' => $q['marks'], 'section_id' => $q['section_id'],
                    'metadata' => '{}', 'category' => $q['category'], 'max_score' => $q['max_score'], 'hr_marks' => null];
            }
            return $out;
        }
        if (str_contains($sql, 'ORDER BY submitted_at')) return [];
        return [];
    }
    public function execute(string $sql, string $t = '', mixed ...$p): bool|int {
        if (str_contains($sql, 'INSERT INTO test_submissions')) {
            $sid = $this->nextSid;
            $this->active = ['id' => $sid, 'assessment_id' => 50, 'test_id' => 50, 'candidate_id' => 9,
                'status' => 'in_progress', 'started_at' => date('Y-m-d H:i:s'), 'deadline_at' => date('Y-m-d H:i:s', time() + $this->remaining),
                'max_score' => 25, 'nav_state' => '{}', 'current_q' => 0, 'violations' => 0, 'reconnects' => 0,
                'total_score' => 0, 'percentage' => 0.0, 'time_taken_mins' => 0, 'fullscreen_exits' => 0, 'submitted_at' => null];
            $this->writes[] = 'start';
            return $sid;
        }
        if (str_contains($sql, 'INSERT INTO test_answers')) {
            // params: sid, qid, answerText, selected, responseJson, marks, correct, time, flag
            $this->answers[(int)$p[1]] = ['question_id' => (int)$p[1], 'answer_text' => $p[2], 'selected_option' => $p[3],
                'response' => $p[4], 'marks_awarded' => $p[5], 'is_correct' => $p[6], 'time_spent_secs' => $p[7], 'review_flag' => $p[8]];
            $this->writes[] = 'answer';
            return 1;
        }
        if (str_contains($sql, 'SET current_q')) { $this->writes[] = 'nav'; if ($this->active) $this->active['nav_state'] = $p[1]; return 1; }
        if (str_contains($sql, 'violations=violations+')) { $this->writes[] = 'proctor'; if ($this->active) { $this->active['violations'] += (int)$p[0]; $this->active['reconnects'] += (int)$p[1]; } return 1; }
        if (str_contains($sql, 'SET status=?, submitted_at=NOW()')) {
            $this->writes[] = 'finalize';
            if ($this->active) { $this->completed = $this->active; $this->completed['status'] = $p[0]; $this->completed['total_score'] = $p[1]; $this->completed['percentage'] = $p[2]; $this->active = null; }
            return 1;
        }
        $this->writes[] = 'other';
        return 1;
    }
};

$svc8c = new AssessmentService($fake8c);

// ── openAttempt: fresh start ──────────────────────────────────────────────────
$b = $svc8c->openAttempt('tok123', 9);
ok($b['error'] === null, 'openAttempt: fresh start has no error');
ok($b['resumed'] === false, 'openAttempt: not resumed on fresh start');
ok(in_array('start', $fake8c->writes, true), 'openAttempt: creates in-progress attempt');
ok(count($b['questions']) === 3, 'openAttempt: loads all questions');
ok($b['remaining'] === 1500, 'openAttempt: server-authoritative remaining');
ok(($b['policy']['fullscreen_required'] ?? null) === true, 'openAttempt: policy carries fullscreen default');
ok(count($b['policy']['log_signals'] ?? []) === 8, 'openAttempt: policy logs all signals');
$sid = (int)$b['submission']['id'];

// ── openAttempt: resume existing attempt ──────────────────────────────────────
$b2 = $svc8c->openAttempt('tok123', 9);
ok($b2['resumed'] === true, 'openAttempt: resumes existing attempt');
ok((int)$b2['submission']['id'] === $sid, 'openAttempt: resume reuses same submission');
// ── openAttempt: error cases ──────────────────────────────────────────────────
ok($svc8c->openAttempt('wrong', 9)['error'] === 'invalid', 'openAttempt: invalid token → error');
ok($svc8c->openAttempt('tok123', 999)['error'] === 'invalid', 'openAttempt: wrong candidate → invalid');
// ── autosave: auto-scorable (mcq correct) ─────────────────────────────────────
$q101 = $fake8c->questions[101];
$r = $svc8c->autosave($sid, $fake8c->test, $q101, 'b', null, 20, 0, null);
ok($r['ok'] === true, 'autosave: returns ok');
ok($r['remaining'] === 1500, 'autosave: returns server remaining');
ok(!empty($r['saved_at']), 'autosave: returns saved_at timestamp');
ok((int)($fake8c->answers[101]['marks_awarded'] ?? -1) === 10, 'autosave: mcq correct scored 10');
ok((int)($fake8c->answers[101]['is_correct'] ?? 0) === 1, 'autosave: mcq correct flagged is_correct');
// ── autosave: multi_select partial (wrong subset scores 0 by default) ─────────
$q102 = $fake8c->questions[102];
$svc8c->autosave($sid, $fake8c->test, $q102, ['a'], ['selected' => ['a']], 30, 1, null);
ok((int)($fake8c->answers[102]['marks_awarded'] ?? -1) === 0, 'autosave: multi_select incomplete scores 0');
ok(str_contains((string)($fake8c->answers[102]['response'] ?? ''), 'selected'), 'autosave: multi_select stores response json');
ok((int)($fake8c->answers[102]['review_flag'] ?? -1) === 1, 'autosave: review flag persisted');
// full correct multi_select
$svc8c->autosave($sid, $fake8c->test, $q102, ['a', 'c'], ['selected' => ['a', 'c']], 35, 0, null);
ok((int)($fake8c->answers[102]['marks_awarded'] ?? -1) === 10, 'autosave: multi_select complete scores full');
ok((int)($fake8c->answers[102]['review_flag'] ?? -1) === 0, 'autosave: last-write-wins overwrites prior');
// ── autosave: manual (subjective defers to 0, pending review) ─────────────────
$q103 = $fake8c->questions[103];
$svc8c->autosave($sid, $fake8c->test, $q103, 'Normalization avoids redundancy', null, 60, 0, null);
ok((int)($fake8c->answers[103]['marks_awarded'] ?? -1) === 0, 'autosave: subjective deferred (0 until HR)');
ok(str_contains((string)($fake8c->answers[103]['answer_text'] ?? ''), 'redundancy'), 'autosave: subjective text stored');
// ── saveNav ───────────────────────────────────────────────────────────────────
$svc8c->saveNav($sid, 2, [102]);
ok(str_contains((string)($fake8c->active['nav_state'] ?? ''), '102'), 'saveNav: persists flags + position');
// ── secondsRemaining is server-sourced ────────────────────────────────────────
$fake8c->remaining = 900;
ok($svc8c->secondsRemaining($sid) === 900, 'secondsRemaining: reads server deadline');
// ── recordProctoring: normalisation + counters + events ───────────────────────
$captured = [];
$svc8c->events->subscribe(Events::PROCTORING_SIGNAL, function ($e) use (&$captured) { $captured[] = $e['signal'] ?? '?'; });
$res = $svc8c->recordProctoring($sid, [
    ['type' => 'tab_switch'], ['type' => 'copy_attempt'], ['type' => 'not_a_signal'], ['type' => 'reconnect'],
], $b['policy']);
ok($res['logged'] === 3, 'recordProctoring: logs valid signals only');
ok($res['violation_delta'] === 1, 'recordProctoring: tab_switch counts as violation');
ok($res['reconnect_delta'] === 1, 'recordProctoring: reconnect counts separately');
ok(count($captured) === 3, 'recordProctoring: dispatches events for each');
ok((int)($fake8c->active['violations'] ?? 0) === 1, 'recordProctoring: persists violation counter');
// ── submitAttempt: server re-scores, updates pipeline ─────────────────────────
$fake8c->remaining = 600;
$out = $svc8c->submitAttempt($sid, $fake8c->test, 9, false);
ok($out['marks'] === 20, 'submitAttempt: total re-scored server-side (10+10+0)');
ok($out['pct'] === 80.0, 'submitAttempt: pct computed from total');
ok($out['status'] === 'submitted', 'submitAttempt: status submitted (manual)');
ok($out['max'] === 25, 'submitAttempt: max carried through');
ok(in_array('finalize', $fake8c->writes, true), 'submitAttempt: finalize written');
ok($fake8c->completed !== null && $fake8c->active === null, 'submitAttempt: attempt now completed');
// ── openAttempt after completion → completed error ────────────────────────────
ok($svc8c->openAttempt('tok123', 9)['error'] === 'completed', 'openAttempt: completed attempt blocks re-entry');
// ── auto-submit path ──────────────────────────────────────────────────────────
$fake8c->completed = null; $fake8c->active = null; $fake8c->answers = []; $fake8c->writes = [];
$b3 = $svc8c->openAttempt('tok123', 9);
$sid3 = (int)$b3['submission']['id'];
$svc8c->autosave($sid3, $fake8c->test, $q101, 'b', null, 5, 0, null);
$out3 = $svc8c->submitAttempt($sid3, $fake8c->test, 9, true);
ok($out3['status'] === 'auto_submitted', 'submitAttempt: auto-submit sets auto_submitted status');
// ── AntiCheat unit tests ──────────────────────────────────────────────────────
ok(AntiCheat::isSignal('tab_switch') === true, 'AntiCheat: recognises valid signal');
ok(AntiCheat::isSignal('bogus') === false, 'AntiCheat: rejects unknown signal');
ok(AntiCheat::defaultPolicy()['fullscreen_required'] === true, 'AntiCheat: default policy fullscreen required');
$norm = AntiCheat::normalise(
    [['type' => 'tab_switch'], ['type' => 'window_blur'], ['type' => 'copy_attempt'], ['type' => 'refresh'], ['type' => 'junk']],
    AntiCheat::defaultPolicy());
ok(count($norm['events']) === 4, 'AntiCheat: filters junk, keeps 4 valid');
ok($norm['violation_delta'] === 2, 'AntiCheat: violation subset counted (tab+blur)');
ok($norm['reconnect_delta'] === 1, 'AntiCheat: refresh counts as reconnect');
// policy narrowing: only log tab_switch, count nothing
$narrow = AntiCheat::normalise(
    [['type' => 'tab_switch'], ['type' => 'window_blur']],
    ['log_signals' => ['tab_switch'], 'violation_signals' => []]);
ok(count($narrow['events']) === 1, 'AntiCheat: log_signals filters to allowed');
ok($narrow['violation_delta'] === 0, 'AntiCheat: empty violation_signals counts none');
// ── QuestionRenderer unit tests ───────────────────────────────────────────────
$mcqHtml = QuestionRenderer::render($fake8c->questions[101], 'b');
ok(str_contains($mcqHtml, 'type="radio"'), 'Renderer: mcq emits radio group');
ok(str_contains($mcqHtml, 'value="b"') && str_contains($mcqHtml, 'checked'), 'Renderer: mcq hydrates saved answer');
ok(str_contains($mcqHtml, 'name="ans_101"'), 'Renderer: mcq writes ans_ field');
$msHtml = QuestionRenderer::render($fake8c->questions[102], ['selected' => ['a', 'c']]);
ok(str_contains($msHtml, 'data-multi="102"'), 'Renderer: multi_select emits checkboxes');
ok(substr_count($msHtml, 'checked') === 2, 'Renderer: multi_select hydrates saved picks');
ok(str_contains($msHtml, 'name="resp_102"'), 'Renderer: multi_select writes resp_ json field');
$subjHtml = QuestionRenderer::render($fake8c->questions[103], 'my essay');
ok(str_contains($subjHtml, 'ap-textarea'), 'Renderer: subjective emits textarea');
ok(str_contains($subjHtml, 'my essay'), 'Renderer: subjective hydrates saved text');
$boolHtml = QuestionRenderer::render(['question_id' => 200, 'question_type' => 'true_false'] + $fake8c->questions[101], 'true');
ok(str_contains($boolHtml, '>True<') && str_contains($boolHtml, '>False<'), 'Renderer: true_false emits True/False');
$codeHtml = QuestionRenderer::render(['question_id' => 201, 'question_type' => 'coding'] + $fake8c->questions[103], 'print(1)');
ok(str_contains($codeHtml, 'ap-code') && str_contains($codeHtml, 'print(1)'), 'Renderer: coding emits code textarea');
$ratingHtml = QuestionRenderer::render(['question_id' => 202, 'question_type' => 'rating_scale', 'rating_max' => 5] + $fake8c->questions[101], '3');
ok(substr_count($ratingHtml, 'ap-rating-pip') === 5, 'Renderer: rating emits pips');
ok(str_contains($ratingHtml, 'value="3"') && str_contains($ratingHtml, 'checked'), 'Renderer: rating hydrates selection');
// unknown/non-deliverable input falls back to textarea safely
$fallbackHtml = QuestionRenderer::render(['question_id' => 203, 'question_type' => 'file_upload'] + $fake8c->questions[103], '');
ok(str_contains($fallbackHtml, 'ap-textarea'), 'Renderer: non-deliverable input falls back to textarea');
// XSS safety
$xssHtml = QuestionRenderer::render($fake8c->questions[103], '<script>alert(1)</script>');
ok(!str_contains($xssHtml, '<script>alert'), 'Renderer: escapes saved answer (no raw script)');

// 6B-012 — the renderer must NEVER expose correct answers / answer keys / metadata,
// even though it receives the full question row (which carries them for scoring).
$mcqLeak = QuestionRenderer::render($fake8c->questions[101], null);   // correct_option='b'
ok(!str_contains($mcqLeak, 'correct_option') && !str_contains($mcqLeak, 'data-correct'), '6B-012: mcq render hides correct_option');
$msLeak = QuestionRenderer::render($fake8c->questions[102], null);    // answer_key '{"correct":["a","c"]}'
ok(!str_contains($msLeak, 'answer_key') && !str_contains($msLeak, '"correct"'), '6B-012: multi_select render hides answer_key');
ok(!str_contains($msLeak, 'metadata'), '6B-012: render hides raw metadata');