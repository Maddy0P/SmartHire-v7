<?php
// ═════════════════════════════════════════════════════════════════════════════
//  InterviewWorkflow (Module 9 Phase 3). Pure rulebook — the enumerations and
//  transition rules for interview scoring, recommendations, decisions, and the
//  timeline vocabulary. No DB, no side-effects, no HTML: the single source of
//  truth the validator + service consult so business rules never scatter into
//  page controllers (handbook Ch6/Ch12).
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Interview;

final class InterviewWorkflow
{
    /** Configurable score categories (Part 1). Each scored 0–10. */
    public const SCORE_CATEGORIES = [
        'technical_knowledge', 'communication', 'problem_solving',
        'behaviour', 'cultural_fit', 'confidence', 'experience_relevance',
    ];

    /** Interviewer recommendations (Part 1). */
    public const RECOMMENDATIONS = ['strong_hire', 'hire', 'hold', 'reject', 'second_round'];

    /** Decision-workflow outcomes (Part 3). */
    public const DECISIONS = [
        'pending', 'passed', 'rejected', 'hold',
        'second_round', 'final_round', 'recommended_for_offer',
    ];

    /** Immutable timeline action vocabulary (Part 2 / Part 5). */
    public const TIMELINE_ACTIONS = [
        'scheduled', 'candidate_confirmed', 'reminder_sent', 'started', 'completed',
        'score_updated', 'feedback_submitted', 'decision_recorded', 'decision_changed',
        'moved_to_offer',
    ];

    /** Scheduling statuses that count as "already happened" (cannot revert to scheduled). */
    private const TERMINAL_STATUSES = ['completed'];

    public const SCORE_MIN = 0;
    public const SCORE_MAX = 10;

    public static function isCategory(string $c): bool     { return in_array($c, self::SCORE_CATEGORIES, true); }
    public static function isRecommendation(string $r): bool { return in_array($r, self::RECOMMENDATIONS, true); }
    public static function isDecision(string $d): bool     { return in_array($d, self::DECISIONS, true); }
    public static function isTimelineAction(string $a): bool { return in_array($a, self::TIMELINE_ACTIONS, true); }

    /**
     * Scheduling-status transition rule (Part 3): a completed interview may not
     * return to "scheduled". Everything else stays permitted (backward compatible).
     */
    public static function canChangeStatus(string $from, string $to): bool
    {
        if ($to === 'scheduled' && in_array($from, self::TERMINAL_STATUSES, true)) return false;
        return true;
    }

    /** Clamp a raw category score into the valid 0–10 range. */
    public static function clampScore(int $n): int
    {
        return max(self::SCORE_MIN, min(self::SCORE_MAX, $n));
    }
}
