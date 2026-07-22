<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Module 9 — Interview Management tests (service-facade level). In-memory FakeDb
//  routes the InterviewRepository SQL; side-effects are captured via fake
//  callables. Verifies validation, double-booking conflict detection, the create/
//  update/delete flows, side-effect firing + order, and the composite score.
// ═════════════════════════════════════════════════════════════════════════════

require_once dirname(__DIR__) . '/modules/interview/bootstrap.php';

use SmartHire\Interview\DbAdapter;
use SmartHire\Interview\InterviewRepository;
use SmartHire\Interview\InterviewValidator;
use SmartHire\Interview\InterviewService;

section('Module 9 — Interview Management');

$makeFake = function () {
    return new class implements DbAdapter {
        public array $rows = [];          // id => interview row
        public array $conflictRows = [];  // rows returned by conflict query
        public array $candidates = [9 => 'Asha Verma'];
        public array $writes = [];        // captured verbs
        public int $nextId = 100;
        public bool $failCreate = false;
        public bool $failUpdate = false;
        // Phase 3 stores
        public array $scorecards = [];    // interview_id => row
        public array $timeline   = [];    // append-only list
        public array $feedback   = [];    // interview_id => row
        public array $responses  = [];    // per-question rows
        public int $nextTlId = 1;

        public function fetchOne(string $sql, string $t = '', mixed ...$p): ?array {
            if (str_contains($sql, 'FROM candidates WHERE id=?')) {
                $id = (int)$p[0]; return isset($this->candidates[$id]) ? ['name' => $this->candidates[$id]] : null;
            }
            if (str_contains($sql, 'FROM interviews WHERE id=?')) return $this->rows[(int)$p[0]] ?? null;
            if (str_contains($sql, 'FROM interview_scorecards WHERE interview_id=?')) return $this->scorecards[(int)$p[0]] ?? null;
            if (str_contains($sql, 'FROM interview_feedback WHERE interview_id=?')) return $this->feedback[(int)$p[0]] ?? null;
            return null;
        }
        public function fetchAll(string $sql, string $t = '', mixed ...$p): array {
            if (str_contains($sql, 'LOWER(interviewer)')) return $this->conflictRows;
            if (str_contains($sql, 'GROUP BY status')) {
                $g = [];
                foreach ($this->rows as $r) { $s = $r['status'] ?? ''; $g[$s] = ($g[$s] ?? 0) + 1; }
                return array_map(fn($k, $v) => ['status' => $k, 'n' => $v], array_keys($g), array_values($g));
            }
            if (str_contains($sql, 'JOIN candidates c ON c.id = i.candidate_id')) {
                $rows = array_values($this->rows);
                if ($t === 's') $rows = array_values(array_filter($rows, fn($r) => ($r['status'] ?? null) === $p[0]));
                return $rows;
            }
            if (str_contains($sql, 'FROM interview_timeline WHERE interview_id=?')) {
                return array_values(array_filter($this->timeline, fn($e) => $e['interview_id'] === (int)$p[0]));
            }
            return [];
        }
        public function execute(string $sql, string $t = '', mixed ...$p): bool|int {
            if (str_contains($sql, 'INSERT INTO interviews')) {
                if ($this->failCreate) return false;
                $id = $this->nextId++;
                $this->rows[$id] = ['id' => $id, 'candidate_id' => $p[0], 'interviewer' => $p[1],
                    'scheduled_date' => $p[2], 'scheduled_time' => $p[3], 'type' => $p[4],
                    'mode' => $p[5], 'status' => $p[6], 'notes' => $p[7]];
                $this->writes[] = 'insert'; return $id;
            }
            if (str_contains($sql, 'UPDATE interviews SET')) {
                if ($this->failUpdate) return false;
                $this->writes[] = 'update'; return 1;
            }
            if (str_contains($sql, 'DELETE FROM interviews')) { $this->writes[] = 'delete'; return 1; }

            // ── Phase 3 ──
            if (str_contains($sql, 'INSERT INTO interview_scorecards') && str_contains($sql, 'DO NOTHING')) {
                $iid = (int)$p[0];
                $this->scorecards[$iid] ??= ['interview_id'=>$iid,'decision'=>'pending','decision_finalized'=>false];
                $this->writes[] = 'ensure_scorecard'; return 1;
            }
            if (str_contains($sql, 'INSERT INTO interview_scorecards')) { // upsert scores
                $iid = (int)$p[0];
                $prev = $this->scorecards[$iid] ?? ['decision'=>'pending','decision_finalized'=>false];
                $this->scorecards[$iid] = array_merge($prev, [
                    'interview_id'=>$iid,'technical_knowledge'=>$p[1],'communication'=>$p[2],'problem_solving'=>$p[3],
                    'behaviour'=>$p[4],'cultural_fit'=>$p[5],'confidence'=>$p[6],'experience_relevance'=>$p[7],
                    'overall_score'=>$p[8],'recommendation'=>$p[9],'summary'=>$p[10],'comments'=>$p[11],'scored_by'=>$p[12],
                ]);
                $this->writes[] = 'save_scorecard'; return 1;
            }
            if (str_contains($sql, 'UPDATE interview_scorecards SET decision=')) {
                $iid = (int)$p[2];
                $this->scorecards[$iid] ??= ['interview_id'=>$iid];
                $this->scorecards[$iid]['decision'] = $p[0];
                $this->scorecards[$iid]['decision_finalized'] = (bool)$p[1];
                $this->writes[] = 'update_decision'; return 1;
            }
            if (str_contains($sql, 'INSERT INTO interview_timeline')) {
                $this->timeline[] = ['id'=>$this->nextTlId,'interview_id'=>(int)$p[0],'actor'=>$p[1],'action'=>$p[2],'notes'=>$p[3]];
                $this->writes[] = 'timeline'; return $this->nextTlId++;
            }
            if (str_contains($sql, 'INSERT INTO interview_feedback')) {
                $iid = (int)$p[0];
                $this->feedback[$iid] = ['interview_id'=>$iid,'summary'=>$p[1],'strengths'=>$p[2],'weaknesses'=>$p[3],
                    'improvement_areas'=>$p[4],'technical_notes'=>$p[5],'behaviour_notes'=>$p[6],
                    'final_recommendation'=>$p[7],'created_by'=>$p[8]];
                $this->writes[] = 'feedback'; return 1;
            }
            if (str_contains($sql, 'DELETE FROM candidate_responses')) { $this->responses = []; $this->writes[] = 'del_responses'; return 1; }
            if (str_contains($sql, 'INSERT INTO candidate_responses')) {
                $this->responses[] = ['interview_id'=>$p[0],'candidate_id'=>$p[1],'question_id'=>$p[2],'score_given'=>$p[3],'interviewer_note'=>$p[4]];
                $this->writes[] = 'response'; return 1;
            }
            return 1;
        }
    };
};

// Side-effect capture harness ------------------------------------------------
$makeSvc = function ($fake) use (&$fx) {
    $fx = ['notify' => [], 'advance' => [], 'email' => [], 'audit' => []];
    return new InterviewService(
        new InterviewRepository($fake),
        new InterviewValidator(),
        notify:         function ($t, $m, $c) use (&$fx) { $fx['notify'][] = [$t, $m, $c]; },
        advance:        function ($c, $s, $n) use (&$fx) { $fx['advance'][] = [$c, $s, $n]; },
        emailCandidate: function ($c, $e, $v) use (&$fx) { $fx['email'][] = [$c, $e, $v]; },
        audit:          function ($a, $e, $i, $d = null) use (&$fx) { $fx['audit'][] = [$a, $e, $i, $d]; },
    );
};

$validInput = [
    'candidate_id' => 9, 'interviewer' => 'Dr. Rao', 'scheduled_date' => '2099-01-15',
    'scheduled_time' => '10:30', 'type' => 'technical', 'mode' => 'online', 'status' => 'scheduled', 'notes' => 'Round 1',
];

// ── Validator ─────────────────────────────────────────────────────────────────
$val = new InterviewValidator();
$r = $val->validateSchedule($validInput, true, '2026-07-21');
ok($r['success'] === true, 'Validator: valid input passes');
ok($r['data']['candidate_id'] === 9, 'Validator: sanitized data carries candidate_id');

$r = $val->validateSchedule([], true, '2026-07-21');
ok($r['success'] === false, 'Validator: empty input fails');
ok(isset($r['errors']['candidate_id'], $r['errors']['interviewer'], $r['errors']['scheduled_date'], $r['errors']['scheduled_time']), 'Validator: reports each missing required field');

ok($val->validateSchedule(['candidate_id'=>9,'interviewer'=>'X','scheduled_date'=>'2020-01-01','scheduled_time'=>'10:00'], true, '2026-07-21')['errors']['scheduled_date'] ?? false, 'Validator: create rejects past date');
ok(($val->validateSchedule(['candidate_id'=>9,'interviewer'=>'X','scheduled_date'=>'2020-01-01','scheduled_time'=>'10:00'], false, '2026-07-21')['errors']['scheduled_date'] ?? null) === null, 'Validator: update allows past date');
ok(isset($val->validateSchedule(['candidate_id'=>9,'interviewer'=>'X','scheduled_date'=>'2099-01-01','scheduled_time'=>'99:99'], true)['errors']['scheduled_time']), 'Validator: rejects bad time');
ok(isset($val->validateSchedule($validInput + ['type'=>'bogus'], true, '2026-07-21')['errors']) && ($val->validateSchedule(array_merge($validInput, ['type'=>'bogus']), true, '2026-07-21')['errors']['type'] ?? false), 'Validator: rejects invalid type enum');
ok(InterviewValidator::isValidTime('10:30') && InterviewValidator::isValidTime('09:05:00') && !InterviewValidator::isValidTime('24:00'), 'Validator: time format matcher');

// ── Repository conflict query wiring ──────────────────────────────────────────
$fake = $makeFake();
$repo = new InterviewRepository($fake);
$fake->conflictRows = [['id'=>5,'interviewer'=>'Dr. Rao','scheduled_date'=>'2099-01-15','scheduled_time'=>'10:30']];
ok(count($repo->conflicts('Dr. Rao','2099-01-15','10:30')) === 1, 'Repository: conflict query returns clashes');
ok($repo->conflicts('','','') === [], 'Repository: conflict skips when fields blank');
ok($repo->candidateName(9) === 'Asha Verma', 'Repository: candidateName lookup');

// ── Service.schedule happy path ───────────────────────────────────────────────
$fake = $makeFake();
$svc = $makeSvc($fake);
$res = $svc->schedule($validInput, '2026-07-21');
ok($res['ok'] === true && $res['id'] >= 100, 'schedule: creates + returns id');
ok(in_array('insert', $fake->writes, true), 'schedule: repository insert ran');
ok(count($fx['notify']) === 1 && $fx['notify'][0][0] === 'interview_scheduled', 'schedule: fires interview_scheduled notification');
ok($fx['notify'][0][1] === 'Interview scheduled with Asha Verma', 'schedule: notification uses candidate name');
ok(count($fx['advance']) === 1 && $fx['advance'][0][1] === 'interview_scheduled', 'schedule: advances application stage');
ok(count($fx['email']) === 1 && $fx['email'][0][1] === 'interview_invite', 'schedule: sends invite email');
ok($fx['email'][0][2]['job'] === 'a Technical round', 'schedule: invite email vars preserved');
ok(count($fx['audit']) === 1 && $fx['audit'][0][0] === 'interview_create', 'schedule: audit logged');

// ── Service.schedule validation rejection (no write, no side-effects) ─────────
$fake = $makeFake();
$svc = $makeSvc($fake);
$res = $svc->schedule(['candidate_id' => 0], '2026-07-21');
ok($res['ok'] === false && !empty($res['errors']), 'schedule: invalid input rejected with errors');
ok($fake->writes === [] && $fx['notify'] === [], 'schedule: no write / no side-effects on invalid input');

// ── Service.schedule conflict rejection ───────────────────────────────────────
$fake = $makeFake();
$fake->conflictRows = [['id'=>7,'interviewer'=>'Dr. Rao','scheduled_date'=>'2099-01-15','scheduled_time'=>'10:30']];
$svc = $makeSvc($fake);
$res = $svc->schedule($validInput, '2026-07-21');
ok($res['ok'] === false && !empty($res['conflict']), 'schedule: double-booking blocked');
ok($fake->writes === [], 'schedule: no insert on conflict');

// ── Service.reschedule ────────────────────────────────────────────────────────
$fake = $makeFake();
$svc = $makeSvc($fake);
$res = $svc->reschedule(50, array_merge($validInput, ['status' => 'scheduled']), '2026-07-21');
ok($res['ok'] === true && in_array('update', $fake->writes, true), 'reschedule: updates row');
ok(count($fx['audit']) === 1 && $fx['audit'][0][0] === 'interview_update', 'reschedule: audit logged');
ok($fx['advance'] === [], 'reschedule: no stage advance when not completed');

$fake = $makeFake();
$svc = $makeSvc($fake);
$svc->reschedule(50, array_merge($validInput, ['status' => 'completed', 'scheduled_date' => '2020-01-01']), '2026-07-21');
ok(count($fx['advance']) === 1 && $fx['advance'][0][1] === 'interview_completed', 'reschedule: completed advances stage (past date allowed)');

// conflict excludes self (no clash rows for update)
$fake = $makeFake();
$fake->conflictRows = [];
$svc = $makeSvc($fake);
ok($svc->reschedule(50, $validInput, '2026-07-21')['ok'] === true, 'reschedule: own row not a self-conflict');

// ── Service.remove ────────────────────────────────────────────────────────────
$fake = $makeFake();
$svc = $makeSvc($fake);
$res = $svc->remove(50);
ok($res['ok'] === true && in_array('delete', $fake->writes, true), 'remove: deletes row');
ok(count($fx['audit']) === 1 && $fx['audit'][0][0] === 'interview_delete', 'remove: audit logged');
ok($svc->remove(0)['ok'] === false, 'remove: rejects bad id');

// ── Composite score (preserves sh_final_score) ────────────────────────────────
$fake = $makeFake();
$svc = $makeSvc($fake);
ok($svc->finalScore(80, 60) === 72, 'finalScore: 0.6*ats + 0.4*iv');
ok($svc->finalScore(80, null) === 80, 'finalScore: null interview → ats only');

// ── DB-failure path ───────────────────────────────────────────────────────────
$fake = $makeFake();
$fake->failCreate = true;
$svc = $makeSvc($fake);
ok($svc->schedule($validInput, '2026-07-21')['ok'] === false, 'schedule: db failure returns not-ok');

// ── Phase 2: read-path (list + consolidated counts) ───────────────────────────
$fake = $makeFake();
$fake->rows = [
    1 => ['id'=>1,'candidate_id'=>9,'status'=>'scheduled','scheduled_date'=>'2099-01-10','scheduled_time'=>'09:00'],
    2 => ['id'=>2,'candidate_id'=>9,'status'=>'scheduled','scheduled_date'=>'2099-01-11','scheduled_time'=>'10:00'],
    3 => ['id'=>3,'candidate_id'=>9,'status'=>'completed','scheduled_date'=>'2099-01-12','scheduled_time'=>'11:00'],
    4 => ['id'=>4,'candidate_id'=>9,'status'=>'cancelled','scheduled_date'=>'2099-01-13','scheduled_time'=>'12:00'],
    5 => ['id'=>5,'candidate_id'=>9,'status'=>'no-show','scheduled_date'=>'2099-01-14','scheduled_time'=>'13:00'],
];
$repo = new InterviewRepository($fake);

$sc = $repo->statusCounts();
ok($sc['all'] === 5, 'statusCounts: all = total across every status');
ok($sc['scheduled'] === 2, 'statusCounts: scheduled tallied');
ok($sc['completed'] === 1 && $sc['cancelled'] === 1 && $sc['no-show'] === 1, 'statusCounts: each status tallied');
ok(array_keys($sc) === ['all','scheduled','completed','cancelled','no-show'], 'statusCounts: stable key shape');

// unknown statuses still count toward 'all' but never create stray keys
$fake->rows[6] = ['id'=>6,'candidate_id'=>9,'status'=>'rescheduled','scheduled_date'=>'2099-01-15','scheduled_time'=>'14:00'];
$sc2 = $repo->statusCounts();
ok($sc2['all'] === 6, 'statusCounts: unknown status counted in all');
ok(array_keys($sc2) === ['all','scheduled','completed','cancelled','no-show'], 'statusCounts: unknown status adds no key');

ok(count($repo->listWithCandidate()) === 6, 'listWithCandidate: returns all rows unfiltered');
ok(count($repo->listWithCandidate('scheduled')) === 2, 'listWithCandidate: status filter applied');
ok($repo->listWithCandidate('nonexistent') === [], 'listWithCandidate: empty for unmatched status');

// service facade passthroughs
$svc = $makeSvc($fake);
ok($svc->statusCounts()['all'] === 6, 'service.statusCounts: passthrough');
ok(count($svc->listing()) === 6 && count($svc->listing('completed')) === 1, 'service.listing: passthrough + filter');

// ═════════════════════════════════════════════════════════════════════════════
//  Phase 3 — scoring · timeline · decision workflow · feedback
// ═════════════════════════════════════════════════════════════════════════════
use SmartHire\Interview\InterviewWorkflow;

// ── Workflow rulebook ─────────────────────────────────────────────────────────
ok(InterviewWorkflow::isCategory('problem_solving') && !InterviewWorkflow::isCategory('nope'), 'Workflow: category enum');
ok(InterviewWorkflow::isRecommendation('strong_hire') && !InterviewWorkflow::isRecommendation('maybe'), 'Workflow: recommendation enum');
ok(InterviewWorkflow::isDecision('recommended_for_offer') && !InterviewWorkflow::isDecision('bogus'), 'Workflow: decision enum');
ok(InterviewWorkflow::isTimelineAction('feedback_submitted') && !InterviewWorkflow::isTimelineAction('xxx'), 'Workflow: timeline action enum');
ok(InterviewWorkflow::canChangeStatus('completed','scheduled') === false, 'Workflow: completed cannot revert to scheduled');
ok(InterviewWorkflow::canChangeStatus('scheduled','completed') === true, 'Workflow: scheduled → completed allowed');
ok(InterviewWorkflow::canChangeStatus('completed','cancelled') === true, 'Workflow: completed → cancelled allowed');
ok(InterviewWorkflow::clampScore(15) === 10 && InterviewWorkflow::clampScore(-3) === 0 && InterviewWorkflow::clampScore(7) === 7, 'Workflow: clampScore 0..10');

// ── Validator.validateScore ───────────────────────────────────────────────────
$val = new InterviewValidator();
$vs = $val->validateScore(['technical_knowledge'=>8,'communication'=>6,'recommendation'=>'hire']);
ok($vs['success'] === true, 'validateScore: valid passes');
ok($vs['data']['overall_score'] === 7.0, 'validateScore: overall averaged from scored categories');
ok($vs['data']['problem_solving'] === null, 'validateScore: unscored category stays null');
$vs = $val->validateScore(['technical_knowledge'=>99,'recommendation'=>'hire']);
ok($vs['data']['technical_knowledge'] === 10, 'validateScore: clamps out-of-range score');
ok($val->validateScore(['technical_knowledge'=>5])['errors']['recommendation'] ?? false, 'validateScore: missing recommendation fails');
ok($val->validateScore(['recommendation'=>'bogus','technical_knowledge'=>5])['errors']['recommendation'] ?? false, 'validateScore: invalid recommendation fails');
ok($val->validateScore(['recommendation'=>'hire'])['errors']['scores'] ?? false, 'validateScore: no scores fails');
ok($val->validateScore(['overall_score'=>9,'recommendation'=>'hire'])['data']['overall_score'] === 9.0, 'validateScore: explicit overall respected');

// ── Validator.validateFeedback ────────────────────────────────────────────────
ok($val->validateFeedback(['summary'=>'Solid round'])['success'] === true, 'validateFeedback: summary passes');
ok($val->validateFeedback([])['errors']['summary'] ?? false, 'validateFeedback: missing summary fails');
ok($val->validateFeedback(['summary'=>'x','final_recommendation'=>'nope'])['errors']['final_recommendation'] ?? false, 'validateFeedback: invalid recommendation fails');

// ── Service.saveQuestionScores (refactored legacy path) ───────────────────────
$fake = $makeFake();
$fake->rows[50] = ['id'=>50,'candidate_id'=>9,'status'=>'scheduled'];
$svc = $makeSvc($fake);
$r = $svc->saveQuestionScores(50, 9, [1=>80, 2=>50], [1=>'good'], 'Rao');
ok($r['ok'] === true, 'saveQuestionScores: ok');
ok(count($fake->responses) === 2, 'saveQuestionScores: per-question rows written');
ok(in_array('update', $fake->writes, true), 'saveQuestionScores: interview marked completed');
ok(count($fx['advance']) === 1 && $fx['advance'][0][1] === 'interview_completed', 'saveQuestionScores: candidate advanced');
ok(count($fx['audit']) === 1 && $fx['audit'][0][0] === 'interview_scored', 'saveQuestionScores: audited');
ok(count($fake->timeline) === 1 && $fake->timeline[0]['action'] === 'completed', 'saveQuestionScores: timeline recorded');
ok($svc->saveQuestionScores(0, 9, [], [])['ok'] === false, 'saveQuestionScores: rejects bad id');

// ── Service.saveScorecard ─────────────────────────────────────────────────────
$fake = $makeFake();
$svc = $makeSvc($fake);
$r = $svc->saveScorecard(50, ['technical_knowledge'=>8,'communication'=>7,'recommendation'=>'hire','summary'=>'good'], 'Rao');
ok($r['ok'] === true && $r['overall'] === 7.5, 'saveScorecard: saved with averaged overall');
ok($fake->scorecards[50]['recommendation'] === 'hire', 'saveScorecard: persisted');
ok(count($fx['audit']) === 1 && $fx['audit'][0][0] === 'interview_score_updated', 'saveScorecard: audited');
ok(end($fake->timeline)['action'] === 'score_updated', 'saveScorecard: timeline score_updated');
ok(!empty($svc->saveScorecard(50, ['recommendation'=>'bogus'], 'Rao')['errors']), 'saveScorecard: invalid input returns errors');

// ── Service.recordDecision + finalization lock ────────────────────────────────
$fake = $makeFake();
$svc = $makeSvc($fake);
ok($svc->recordDecision(50, 'nonsense')['ok'] === false, 'recordDecision: rejects invalid decision');
$r = $svc->recordDecision(50, 'passed', false, 'HR');
ok($r['ok'] === true, 'recordDecision: valid decision recorded');
ok($fake->scorecards[50]['decision'] === 'passed', 'recordDecision: persisted');
ok(end($fake->timeline)['action'] === 'decision_recorded', 'recordDecision: first decision → recorded');
$svc->recordDecision(50, 'hold', false, 'HR');
ok(end($fake->timeline)['action'] === 'decision_changed', 'recordDecision: change → decision_changed');
$rf = $svc->recordDecision(50, 'recommended_for_offer', true, 'HR');
ok($rf['ok'] === true && $rf['finalized'] === true, 'recordDecision: finalize succeeds');
ok($fake->scorecards[50]['decision_finalized'] === true, 'recordDecision: finalized flag set');
ok(in_array('moved_to_offer', array_column($fake->timeline,'action'), true), 'recordDecision: recommended_for_offer adds moved_to_offer');
// after finalization everything locks
ok($svc->recordDecision(50, 'rejected')['ok'] === false, 'recordDecision: locked after finalize');
ok($svc->saveScorecard(50, ['technical_knowledge'=>5,'recommendation'=>'hire'], 'Rao')['ok'] === false, 'saveScorecard: locked after finalize');

// ── Service.submitFeedback ────────────────────────────────────────────────────
$fake = $makeFake();
$svc = $makeSvc($fake);
$r = $svc->submitFeedback(50, ['summary'=>'Strong','strengths'=>'SQL','weaknesses'=>'nerves'], 'Rao');
ok($r['ok'] === true, 'submitFeedback: saved');
ok($fake->feedback[50]['strengths'] === 'SQL', 'submitFeedback: persisted independently from scoring');
ok(end($fake->timeline)['action'] === 'feedback_submitted', 'submitFeedback: timeline recorded');
ok($fx['audit'][0][0] === 'interview_feedback', 'submitFeedback: audited');
ok(!empty($svc->submitFeedback(50, [])['errors']), 'submitFeedback: missing summary → errors');

// ── Service.addTimelineEvent + immutability/ordering ──────────────────────────
$fake = $makeFake();
$svc = $makeSvc($fake);
$svc->addTimelineEvent(50, 'scheduled', 'Rao');
$svc->addTimelineEvent(50, 'candidate_confirmed', 'Asha');
$svc->addTimelineEvent(50, 'reminder_sent', 'system');
ok($svc->addTimelineEvent(50, 'bogus_action')['ok'] === false, 'addTimelineEvent: rejects unknown action');
$tl = $svc->timelineFor(50);
ok(count($tl) === 3, 'timeline: all events retained (immutable, append-only)');
ok(array_column($tl,'action') === ['scheduled','candidate_confirmed','reminder_sent'], 'timeline: preserves chronological order');

// ── reschedule transition guard ───────────────────────────────────────────────
$fake = $makeFake();
$fake->rows[50] = ['id'=>50,'candidate_id'=>9,'status'=>'completed'];
$svc = $makeSvc($fake);
$bad = $svc->reschedule(50, array_merge($validInput, ['status'=>'scheduled']), '2026-07-21');
ok($bad['ok'] === false && ($bad['error'] ?? '') === 'bad_transition', 'reschedule: completed cannot revert to scheduled');
$okr = $svc->reschedule(50, array_merge($validInput, ['status'=>'completed']), '2026-07-21');
ok($okr['ok'] === true, 'reschedule: completed → completed still allowed');
