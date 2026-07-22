<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Domain layer — Assessment Platform Core (Module 8A)
//  Lightweight entities over the EXISTING tables (extend, don't duplicate):
//    Question       ⇐ interview_questions        QuestionPool ⇐ question_presets
//    Assessment     ⇐ online_tests (+ template)  Submission   ⇐ test_submissions
//    Score          ⇐ one test_answers outcome   Result       ⇐ computed analysis
//  Entities are immutable value objects: hydrate from a DB row, never talk to
//  the DB themselves (that's the repository layer's job).
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Assessment\Domain;

final class Question
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $type,          // QTypeRegistry code
        public readonly string  $category,
        public readonly string  $difficulty,    // easy|medium|hard
        public readonly string  $text,
        public readonly int     $maxScore,
        public readonly array   $metadata   = [],   // options, language, bonus, rubric…
        public readonly ?array  $answerKey  = null, // structured key (new types)
        public readonly ?string $legacyCorrectOption = null,  // mcq a|b|c|d
        public readonly array   $legacyOptions = [],          // ['a'=>…, 'b'=>…]
        public readonly array   $skills     = [],
        public readonly string  $status     = 'active',
    ) {}

    public static function fromRow(array $r): self
    {
        $meta = self::jsonb($r['metadata'] ?? null);
        return new self(
            id:         (int)$r['id'],
            type:       (string)($r['question_type'] ?? 'subjective'),
            category:   (string)($r['category'] ?? 'technical'),
            difficulty: (string)($r['difficulty'] ?? 'medium'),
            text:       (string)($r['question'] ?? ''),
            maxScore:   (int)($r['max_score'] ?? 10),
            metadata:   $meta,
            answerKey:  self::jsonb($r['answer_key'] ?? null) ?: null,
            legacyCorrectOption: isset($r['correct_option']) && $r['correct_option'] !== null && $r['correct_option'] !== ''
                                 ? (string)$r['correct_option'] : null,
            legacyOptions: array_filter([
                'a' => $r['option_a'] ?? null, 'b' => $r['option_b'] ?? null,
                'c' => $r['option_c'] ?? null, 'd' => $r['option_d'] ?? null,
            ], fn($v) => $v !== null && $v !== ''),
            skills:     array_values(array_filter(array_map('trim', explode(',', (string)($r['skills'] ?? ''))))),
            status:     (string)($r['status'] ?? 'active'),
        );
    }

    public function isBonus(): bool { return (bool)($this->metadata['bonus'] ?? false); }

    /** @return array|null decoded jsonb (accepts already-decoded arrays) */
    public static function jsonb(mixed $v): array
    {
        if (is_array($v)) return $v;
        if (is_string($v) && $v !== '') { $d = json_decode($v, true); return is_array($d) ? $d : []; }
        return [];
    }
}

final class QuestionPool
{
    /** @param int[] $questionIds */
    public function __construct(
        public readonly int    $id,
        public readonly string $name,
        public readonly string $status = 'active',
        public readonly array  $tags = [],
        public readonly array  $questionIds = [],
    ) {}

    public static function fromRow(array $r, array $questionIds = []): self
    {
        return new self(
            id:     (int)$r['id'],
            name:   (string)$r['name'],
            status: (string)($r['status'] ?? 'active'),
            tags:   array_values(array_filter(array_map('trim', explode(',', (string)($r['tags'] ?? ''))))),
            questionIds: array_map('intval', $questionIds),
        );
    }
}

/** An issued assessment instance (one online_tests row). */
final class Assessment
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $title,
        public readonly int     $candidateId,
        public readonly int     $durationMinutes,
        public readonly int     $totalMarks,
        public readonly int     $passingMarks,
        public readonly string  $status,        // pending|active|completed|expired
        public readonly string  $linkToken,
        public readonly ?int    $templateId = null,
        public readonly array   $config     = [],   // frozen AssessmentConfig snapshot
    ) {}

    public static function fromRow(array $r): self
    {
        return new self(
            id:              (int)$r['id'],
            title:           (string)$r['title'],
            candidateId:     (int)$r['candidate_id'],
            durationMinutes: (int)($r['duration_minutes'] ?? 60),
            totalMarks:      (int)($r['total_marks'] ?? 100),
            passingMarks:    (int)($r['passing_marks'] ?? 40),
            status:          (string)($r['status'] ?? 'pending'),
            linkToken:       (string)($r['test_link_token'] ?? ''),
            templateId:      isset($r['template_id']) && $r['template_id'] !== null ? (int)$r['template_id'] : null,
            config:          Question::jsonb($r['config'] ?? null),
        );
    }
}

/** One attempt (test_submissions row). */
final class Submission
{
    public function __construct(
        public readonly int     $id,
        public readonly int     $assessmentId,
        public readonly int     $candidateId,
        public readonly string  $status,        // in_progress|submitted|auto_submitted
        public readonly ?string $startedAt,
        public readonly ?string $submittedAt,
        public readonly int     $totalScore,
        public readonly int     $maxScore,
        public readonly float   $percentage,
        public readonly int     $timeTakenMins,
        public readonly int     $violations,
        public readonly int     $fullscreenExits,
    ) {}

    public static function fromRow(array $r): self
    {
        return new self(
            id:            (int)$r['id'],
            assessmentId:  (int)$r['test_id'],
            candidateId:   (int)$r['candidate_id'],
            status:        (string)($r['status'] ?? 'in_progress'),
            startedAt:     $r['started_at'] ?? null,
            submittedAt:   $r['submitted_at'] ?? null,
            totalScore:    (int)($r['total_score'] ?? 0),
            maxScore:      (int)($r['max_score'] ?? 100),
            percentage:    (float)($r['percentage'] ?? 0),
            timeTakenMins: (int)($r['time_taken_mins'] ?? 0),
            violations:    (int)($r['violations'] ?? 0),
            fullscreenExits: (int)($r['fullscreen_exits'] ?? 0),
        );
    }
}

/** Outcome of scoring one answer — what gets written to a test_answers row. */
final class Score
{
    public function __construct(
        public readonly float   $marks,
        public readonly int     $isCorrect,       // 1|0 (legacy contract)
        public readonly ?string $selectedOption,  // legacy mcq column
        public readonly bool    $needsReview,     // manual-review lane
        public readonly array   $detail = [],     // partial-credit breakdown, matched key…
    ) {}
}

/** Computed analysis for an attempt — produced by the ResultEngine. */
final class Result
{
    public function __construct(
        public readonly float $overallPct,
        public readonly float $totalMarks,
        public readonly float $maxMarks,
        public readonly bool  $passed,
        public readonly array $sections,         // name => [score,max,pct,weight]
        public readonly array $skills,           // skill => [score,max,pct]
        public readonly array $difficulty,       // easy|medium|hard => [score,max,pct]
        public readonly array $timeAnalysis,     // total, avg, slowest, fastest
        public readonly array $questionAnalysis, // per-question outcome rows
        public readonly array $trend,            // prior attempt percentages
        public readonly string $recommendation,  // strong_yes|yes|maybe|no
        public readonly array $strengths,
        public readonly array $weaknesses,
        public readonly array $suggestions,
        public readonly int   $pendingReview = 0,
    ) {}
}
