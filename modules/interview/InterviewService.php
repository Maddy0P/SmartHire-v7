<?php
// ═════════════════════════════════════════════════════════════════════════════
//  InterviewService (Module 9). The single facade the pages consume. Holds the
//  business rules: validation, double-booking conflict detection, persistence,
//  and the surrounding side-effects (notification, application-stage advance,
//  candidate invite email, audit logging). No SQL (delegated to the repository)
//  and no HTML. Side-effects are injected callables so the flow is unit-testable;
//  ::production() binds them to the app's existing global helpers, preserving the
//  exact behavior of the legacy interviews.php write path.
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Interview;

final class InterviewService
{
    /**
     * @param callable $notify         fn(string $type, string $msg, int $candidateId): void
     * @param callable $advance        fn(int $candidateId, string $stage, string $note): void
     * @param callable $emailCandidate fn(int $candidateId, string $event, array $vars): void
     * @param callable $audit          fn(string $action, ?string $entity, ?int $id, ?string $detail): void
     */
    public function __construct(
        private InterviewRepository $repo,
        private InterviewValidator $validator,
        private $notify,
        private $advance,
        private $emailCandidate,
        private $audit,
    ) {}

    /** Production wiring: real DB + the app's existing global side-effect helpers. */
    public static function production(): self
    {
        $db = new GlobalDb();
        return new self(
            new InterviewRepository($db),
            new InterviewValidator(),
            notify:         fn(string $t, string $m, int $c) => addNotification($t, $m, $c),
            advance:        fn(int $c, string $s, string $n) => sh_advance_candidate_applications($c, $s, $n),
            emailCandidate: fn(int $c, string $e, array $v)  => sh_email_candidate($c, $e, $v),
            audit:          fn(string $a, ?string $e, ?int $id, ?string $d = null) => audit_log($a, $e, $id, $d),
        );
    }

    /**
     * Schedule a new interview.
     * @return array{ok:bool, id?:int, errors?:array, conflict?:array, error?:string}
     */
    public function schedule(array $input, ?string $today = null): array
    {
        $v = $this->validator->validateSchedule($input, isNew: true, today: $today);
        if (!$v['success']) return ['ok' => false, 'errors' => $v['errors']];
        $d = $v['data'];

        $clash = $this->repo->conflicts($d['interviewer'], $d['scheduled_date'], $d['scheduled_time']);
        if ($clash) return ['ok' => false, 'conflict' => $clash];

        $id = $this->repo->create($d);
        if ($id === false) return ['ok' => false, 'error' => 'db'];

        // Side-effects — same set and order as the legacy page.
        $name = $this->repo->candidateName($d['candidate_id']) ?? 'candidate';
        ($this->notify)('interview_scheduled', 'Interview scheduled with ' . $name, $d['candidate_id']);
        ($this->audit)('interview_create', 'interview', $id ?: null, null);
        ($this->advance)($d['candidate_id'], 'interview_scheduled', 'Interview scheduled');

        $type = ucfirst($d['type']);
        $when = trim($d['scheduled_date'] . ' at ' . $d['scheduled_time']);
        $mode = $d['mode'];
        ($this->emailCandidate)($d['candidate_id'], 'interview_invite', [
            'job'   => 'a ' . $type . ' round',
            'extra' => 'Scheduled for ' . $when . ($mode ? ' (' . $mode . ')' : '') . '.',
        ]);

        return ['ok' => true, 'id' => $id];
    }

    /**
     * Update / reschedule an interview.
     * @return array{ok:bool, errors?:array, conflict?:array, error?:string}
     */
    public function reschedule(int $id, array $input, ?string $today = null): array
    {
        $v = $this->validator->validateSchedule($input, isNew: false, today: $today);
        if (!$v['success']) return ['ok' => false, 'errors' => $v['errors']];
        $d = $v['data'];

        // Decision-workflow rule (Part 3): a completed interview cannot revert to
        // "scheduled". Any other transition is unchanged (backward compatible).
        $current = $this->repo->findById($id);
        if ($current && !InterviewWorkflow::canChangeStatus((string)$current['status'], $d['status'])) {
            return ['ok' => false, 'error' => 'bad_transition'];
        }

        $clash = $this->repo->conflicts($d['interviewer'], $d['scheduled_date'], $d['scheduled_time'], excludeId: $id);
        if ($clash) return ['ok' => false, 'conflict' => $clash];

        if (!$this->repo->update($id, $d)) return ['ok' => false, 'error' => 'db'];

        ($this->audit)('interview_update', 'interview', $id, 'status=' . $d['status']);
        if ($d['status'] === 'completed' && $d['candidate_id'] > 0) {
            ($this->advance)($d['candidate_id'], 'interview_completed', 'Interview completed');
        }
        return ['ok' => true];
    }

    /** Delete an interview. */
    public function remove(int $id): array
    {
        if ($id <= 0) return ['ok' => false, 'error' => 'bad_id'];
        $ok = $this->repo->delete($id);
        if ($ok) ($this->audit)('interview_delete', 'interview', $id, null);
        return ['ok' => $ok];
    }

    /** Composite score: 60% ATS + 40% interview (preserves sh_final_score). */
    public function finalScore(int $atsScore, ?int $interviewScore): int
    {
        if ($interviewScore === null) return $atsScore;
        return (int)round($atsScore * 0.6 + $interviewScore * 0.4);
    }

    // ── Read-path facade (Module 9 Phase 2) — thin passthroughs to the repo ──

    /** Interview list (+candidate) for the board, optional status filter. */
    public function listing(?string $status = null): array
    {
        return $this->repo->listWithCandidate($status);
    }

    /** All status tallies in one query (6A-005/6A-022 consolidation). */
    public function statusCounts(): array
    {
        return $this->repo->statusCounts();
    }

    // ── Phase 3: scoring · decision · feedback · timeline ────────────────────
    // All business logic lives here (spec Part 1 "no scoring logic in page
    // controllers"). Every mutation appends an immutable timeline entry and an
    // audit-log record via the existing centralized helpers.

    /**
     * Legacy per-question scoring (extracted verbatim from score_interview.php):
     * replace responses → mark completed → advance the candidate. Byte-identical
     * behavior, now with an audit entry and a (best-effort) timeline record.
     */
    public function saveQuestionScores(int $interviewId, int $candidateId, array $scores, array $notes, ?string $actor = null): array
    {
        if ($interviewId <= 0) return ['ok' => false, 'error' => 'bad_id'];
        $this->repo->replaceQuestionScores($interviewId, $candidateId, $scores, $notes);
        $this->repo->markCompleted($interviewId);
        if ($candidateId > 0) ($this->advance)($candidateId, 'interview_completed', 'Interview scored');
        ($this->audit)('interview_scored', 'interview', $interviewId, 'questions=' . count($scores));
        $this->tryTimeline($interviewId, $actor, 'completed', 'Per-question scoring submitted');
        return ['ok' => true];
    }

    /** Category scorecard (Part 1). Blocked once the decision is finalized. */
    public function saveScorecard(int $interviewId, array $input, ?string $actor = null): array
    {
        if ($interviewId <= 0) return ['ok' => false, 'error' => 'bad_id'];
        $existing = $this->repo->scorecard($interviewId);
        if ($existing && !empty($existing['decision_finalized'])) return ['ok' => false, 'error' => 'finalized'];

        $v = $this->validator->validateScore($input);
        if (!$v['success']) return ['ok' => false, 'errors' => $v['errors']];
        $d = $v['data'];
        $d['scored_by'] = $actor;

        if (!$this->repo->saveScorecard($interviewId, $d)) return ['ok' => false, 'error' => 'db'];
        $this->repo->addTimelineEvent($interviewId, $actor, 'score_updated',
            'Recommendation: ' . $d['recommendation'] . ($d['overall_score'] !== null ? ', overall ' . $d['overall_score'] . '/10' : ''));
        ($this->audit)('interview_score_updated', 'interview', $interviewId, 'rec=' . $d['recommendation']);
        return ['ok' => true, 'overall' => $d['overall_score']];
    }

    /**
     * Record / change the decision (Part 3). Rejects invalid outcomes and any
     * change after finalization; logs recorded-vs-changed distinctly.
     */
    public function recordDecision(int $interviewId, string $decision, bool $finalize = false, ?string $actor = null): array
    {
        if ($interviewId <= 0) return ['ok' => false, 'error' => 'bad_id'];
        if (!InterviewWorkflow::isDecision($decision)) return ['ok' => false, 'error' => 'bad_decision'];

        $existing = $this->repo->scorecard($interviewId);
        if ($existing && !empty($existing['decision_finalized'])) return ['ok' => false, 'error' => 'finalized'];

        $this->repo->ensureScorecard($interviewId);
        $prev = (string)($existing['decision'] ?? 'pending');
        if (!$this->repo->updateDecision($interviewId, $decision, $finalize)) return ['ok' => false, 'error' => 'db'];

        $action = ($prev === 'pending' || $prev === $decision) ? 'decision_recorded' : 'decision_changed';
        $label  = ($prev !== $decision ? ucfirst(str_replace('_', ' ', $prev)) . ' → ' : '')
                . ucfirst(str_replace('_', ' ', $decision)) . ($finalize ? ' (finalized)' : '');
        $this->repo->addTimelineEvent($interviewId, $actor, $action, $label);
        ($this->audit)('interview_decision', 'interview', $interviewId, 'decision=' . $decision . ($finalize ? ' finalized' : ''));
        if ($decision === 'recommended_for_offer') $this->tryTimeline($interviewId, $actor, 'moved_to_offer', null);
        return ['ok' => true, 'finalized' => $finalize];
    }

    /** Structured feedback (Part 4) — independent from scoring. */
    public function submitFeedback(int $interviewId, array $input, ?string $actor = null): array
    {
        if ($interviewId <= 0) return ['ok' => false, 'error' => 'bad_id'];
        $v = $this->validator->validateFeedback($input);
        if (!$v['success']) return ['ok' => false, 'errors' => $v['errors']];
        $d = $v['data'];
        $d['created_by'] = $actor;

        if (!$this->repo->saveFeedback($interviewId, $d)) return ['ok' => false, 'error' => 'db'];
        $this->repo->addTimelineEvent($interviewId, $actor, 'feedback_submitted', null);
        ($this->audit)('interview_feedback', 'interview', $interviewId, null);
        return ['ok' => true];
    }

    /** Generic immutable timeline append (Part 2) for lifecycle events. */
    public function addTimelineEvent(int $interviewId, string $action, ?string $actor = null, ?string $notes = null): array
    {
        if ($interviewId <= 0) return ['ok' => false, 'error' => 'bad_id'];
        if (!InterviewWorkflow::isTimelineAction($action)) return ['ok' => false, 'error' => 'bad_action'];
        $id = $this->repo->addTimelineEvent($interviewId, $actor, $action, $notes);
        if ($id === false) return ['ok' => false, 'error' => 'db'];
        ($this->audit)('interview_timeline', 'interview', $interviewId, 'action=' . $action);
        return ['ok' => true, 'id' => $id];
    }

    // Read facades ------------------------------------------------------------
    public function scorecardFor(int $interviewId): ?array { return $this->repo->scorecard($interviewId); }
    public function timelineFor(int $interviewId): array   { return $this->repo->timeline($interviewId); }
    public function feedbackFor(int $interviewId): ?array  { return $this->repo->feedback($interviewId); }

    /** Best-effort timeline write — never blocks the core flow if 003 is unapplied. */
    private function tryTimeline(int $interviewId, ?string $actor, string $action, ?string $notes): void
    {
        try { $this->repo->addTimelineEvent($interviewId, $actor, $action, $notes); }
        catch (\Throwable $e) { /* timeline table is additive; core flow must not depend on it */ }
    }
}
