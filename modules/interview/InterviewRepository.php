<?php
// ═════════════════════════════════════════════════════════════════════════════
//  InterviewRepository (Module 9). PostgreSQL access for the `interviews` table
//  ONLY — no validation, no business logic, no HTML. Every query is parameterized
//  and column-explicit (handbook Ch9: prepared statements, no SELECT *).
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Interview;

final class InterviewRepository
{
    public function __construct(private DbAdapter $db) {}

    private const COLS = 'id, candidate_id, interviewer, scheduled_date, scheduled_time, type, mode, status, notes, created_at';

    // Joined list projection (handbook Ch9: explicit columns, no SELECT *).
    // Reproduces the legacy `i.*, c.name AS candidate_name, c.position, c.email AS candidate_email`
    // exactly — the interviews table has precisely the ten i.* columns below.
    private const LIST_COLS =
        'i.id, i.candidate_id, i.interviewer, i.scheduled_date, i.scheduled_time, i.type, i.mode, i.status, i.notes, i.created_at,
         c.name AS candidate_name, c.position, c.email AS candidate_email';

    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT ' . self::COLS . ' FROM interviews WHERE id=?', 'i', $id);
    }

    /** Create an interview; returns the new id (int) or false on failure. */
    public function create(array $d): int|false
    {
        $res = $this->db->execute(
            'INSERT INTO interviews (candidate_id,interviewer,scheduled_date,scheduled_time,type,mode,status,notes)
             VALUES (?,?,?,?,?,?,?,?)',
            'isssssss',
            $d['candidate_id'], $d['interviewer'], $d['scheduled_date'], $d['scheduled_time'],
            $d['type'], $d['mode'], $d['status'], $d['notes']);
        return is_int($res) ? $res : ($res ? 0 : false);
    }

    public function update(int $id, array $d): bool
    {
        return (bool)$this->db->execute(
            'UPDATE interviews SET candidate_id=?,interviewer=?,scheduled_date=?,scheduled_time=?,type=?,mode=?,status=?,notes=? WHERE id=?',
            'isssssssi',
            $d['candidate_id'], $d['interviewer'], $d['scheduled_date'], $d['scheduled_time'],
            $d['type'], $d['mode'], $d['status'], $d['notes'], $id);
    }

    public function delete(int $id): bool
    {
        return (bool)$this->db->execute('DELETE FROM interviews WHERE id=?', 'i', $id);
    }

    /**
     * Double-booking check (6A-005): same interviewer, same date + time.
     * $excludeId lets an update ignore its own row. Returns matching rows.
     */
    public function conflicts(string $interviewer, string $date, string $time, ?int $excludeId = null): array
    {
        $interviewer = trim($interviewer);
        if ($interviewer === '' || $date === '' || $time === '') return [];
        if ($excludeId !== null) {
            return $this->db->fetchAll(
                "SELECT id, candidate_id, interviewer, scheduled_date, scheduled_time
                 FROM interviews
                 WHERE LOWER(interviewer)=LOWER(?) AND scheduled_date=? AND scheduled_time=?
                   AND status <> 'cancelled' AND id <> ?",
                'sssi', $interviewer, $date, $time, $excludeId);
        }
        return $this->db->fetchAll(
            "SELECT id, candidate_id, interviewer, scheduled_date, scheduled_time
             FROM interviews
             WHERE LOWER(interviewer)=LOWER(?) AND scheduled_date=? AND scheduled_time=?
               AND status <> 'cancelled'",
            'sss', $interviewer, $date, $time);
    }

    public function candidateName(int $candidateId): ?string
    {
        $r = $this->db->fetchOne('SELECT name FROM candidates WHERE id=?', 'i', $candidateId);
        return $r['name'] ?? null;
    }

    // ── Read-path (Module 9 Phase 2) ─────────────────────────────────────────

    /**
     * Interview list with candidate name/position/email, newest first.
     * Optional status filter. Reproduces the legacy list query column-for-column.
     */
    public function listWithCandidate(?string $status = null): array
    {
        $where = ($status !== null && $status !== '') ? ' WHERE i.status=?' : '';
        $sql = 'SELECT ' . self::LIST_COLS . '
                FROM interviews i JOIN candidates c ON c.id = i.candidate_id'
             . $where
             . ' ORDER BY i.scheduled_date DESC, i.scheduled_time DESC';
        return $where !== ''
            ? $this->db->fetchAll($sql, 's', $status)
            : $this->db->fetchAll($sql);
    }

    /**
     * All status tallies in ONE grouped query (handbook 6A-005 / 6A-022 —
     * replaces five separate COUNT(*) round-trips). 'all' is the total across
     * every status; unknown statuses still count toward 'all'.
     * @return array{all:int,scheduled:int,completed:int,cancelled:int,'no-show':int}
     */
    public function statusCounts(): array
    {
        $out = ['all' => 0, 'scheduled' => 0, 'completed' => 0, 'cancelled' => 0, 'no-show' => 0];
        foreach ($this->db->fetchAll('SELECT status, COUNT(*) n FROM interviews GROUP BY status') as $r) {
            $n = (int)$r['n'];
            $out['all'] += $n;
            if (array_key_exists((string)$r['status'], $out)) $out[(string)$r['status']] = $n;
        }
        return $out;
    }

    // ── Phase 3: per-question scores (legacy candidate_responses flow) ────────

    /** Replace this interview's per-question scores (delete-then-insert). */
    public function replaceQuestionScores(int $interviewId, int $candidateId, array $scores, array $notes): void
    {
        $this->db->execute('DELETE FROM candidate_responses WHERE interview_id=?', 'i', $interviewId);
        foreach ($scores as $qid => $score) {
            $this->db->execute(
                'INSERT INTO candidate_responses (interview_id,candidate_id,question_id,score_given,interviewer_note)
                 VALUES (?,?,?,?,?)',
                'iiiss',
                $interviewId, $candidateId, (int)$qid,
                max(0, min((int)$score, 100)),
                trim((string)($notes[$qid] ?? '')));
        }
    }

    public function markCompleted(int $interviewId): bool
    {
        return (bool)$this->db->execute("UPDATE interviews SET status='completed' WHERE id=?", 'i', $interviewId);
    }

    // ── Phase 3: category scorecard ──────────────────────────────────────────

    private const SCORECARD_COLS =
        'id, interview_id, technical_knowledge, communication, problem_solving, behaviour, cultural_fit,
         confidence, experience_relevance, overall_score, recommendation, summary, comments,
         decision, decision_finalized, scored_by, created_at, updated_at';

    public function scorecard(int $interviewId): ?array
    {
        return $this->db->fetchOne(
            'SELECT ' . self::SCORECARD_COLS . ' FROM interview_scorecards WHERE interview_id=?', 'i', $interviewId);
    }

    /**
     * Upsert the category scorecard (scores/recommendation/summary/comments only).
     * Never touches decision/decision_finalized — those move through updateDecision().
     * @param array $d keys: technical_knowledge, communication, problem_solving, behaviour,
     *                 cultural_fit, confidence, experience_relevance, overall_score,
     *                 recommendation, summary, comments, scored_by
     */
    public function saveScorecard(int $interviewId, array $d): bool
    {
        return (bool)$this->db->execute(
            'INSERT INTO interview_scorecards
                (interview_id, technical_knowledge, communication, problem_solving, behaviour, cultural_fit,
                 confidence, experience_relevance, overall_score, recommendation, summary, comments, scored_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON CONFLICT (interview_id) DO UPDATE SET
                technical_knowledge=EXCLUDED.technical_knowledge, communication=EXCLUDED.communication,
                problem_solving=EXCLUDED.problem_solving, behaviour=EXCLUDED.behaviour,
                cultural_fit=EXCLUDED.cultural_fit, confidence=EXCLUDED.confidence,
                experience_relevance=EXCLUDED.experience_relevance, overall_score=EXCLUDED.overall_score,
                recommendation=EXCLUDED.recommendation, summary=EXCLUDED.summary, comments=EXCLUDED.comments,
                scored_by=EXCLUDED.scored_by, updated_at=NOW()',
            'iiiiiiiidssss',
            $interviewId, $d['technical_knowledge'], $d['communication'], $d['problem_solving'],
            $d['behaviour'], $d['cultural_fit'], $d['confidence'], $d['experience_relevance'],
            $d['overall_score'], $d['recommendation'], $d['summary'], $d['comments'], $d['scored_by']);
    }

    /** Update the decision + finalization flag (Part 3). */
    public function updateDecision(int $interviewId, string $decision, bool $finalized): bool
    {
        return (bool)$this->db->execute(
            'UPDATE interview_scorecards SET decision=?, decision_finalized=?, updated_at=NOW() WHERE interview_id=?',
            'sii', $decision, $finalized ? 1 : 0, $interviewId);
    }

    /** Ensure a scorecard row exists (so a decision can be recorded before scores). */
    public function ensureScorecard(int $interviewId): bool
    {
        return (bool)$this->db->execute(
            'INSERT INTO interview_scorecards (interview_id) VALUES (?) ON CONFLICT (interview_id) DO NOTHING',
            'i', $interviewId);
    }

    // ── Phase 3: immutable timeline (append-only) ────────────────────────────

    public function addTimelineEvent(int $interviewId, ?string $actor, string $action, ?string $notes): int|false
    {
        $res = $this->db->execute(
            'INSERT INTO interview_timeline (interview_id, actor, action, notes) VALUES (?,?,?,?)',
            'isss', $interviewId, $actor, $action, $notes);
        return is_int($res) ? $res : ($res ? 0 : false);
    }

    /** Full timeline for an interview, oldest first (history is never reordered). */
    public function timeline(int $interviewId): array
    {
        return $this->db->fetchAll(
            'SELECT id, interview_id, actor, action, notes, created_at
             FROM interview_timeline WHERE interview_id=? ORDER BY created_at, id', 'i', $interviewId);
    }

    // ── Phase 3: structured feedback (independent from scoring) ───────────────

    private const FEEDBACK_COLS =
        'id, interview_id, summary, strengths, weaknesses, improvement_areas, technical_notes,
         behaviour_notes, final_recommendation, created_by, created_at, updated_at';

    public function feedback(int $interviewId): ?array
    {
        return $this->db->fetchOne(
            'SELECT ' . self::FEEDBACK_COLS . ' FROM interview_feedback WHERE interview_id=?', 'i', $interviewId);
    }

    public function saveFeedback(int $interviewId, array $d): bool
    {
        return (bool)$this->db->execute(
            'INSERT INTO interview_feedback
                (interview_id, summary, strengths, weaknesses, improvement_areas, technical_notes,
                 behaviour_notes, final_recommendation, created_by)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON CONFLICT (interview_id) DO UPDATE SET
                summary=EXCLUDED.summary, strengths=EXCLUDED.strengths, weaknesses=EXCLUDED.weaknesses,
                improvement_areas=EXCLUDED.improvement_areas, technical_notes=EXCLUDED.technical_notes,
                behaviour_notes=EXCLUDED.behaviour_notes, final_recommendation=EXCLUDED.final_recommendation,
                updated_at=NOW()',
            'issssssss',
            $interviewId, $d['summary'], $d['strengths'], $d['weaknesses'], $d['improvement_areas'],
            $d['technical_notes'], $d['behaviour_notes'], $d['final_recommendation'], $d['created_by']);
    }
}
