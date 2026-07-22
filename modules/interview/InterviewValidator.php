<?php
// ═════════════════════════════════════════════════════════════════════════════
//  InterviewValidator (Module 9). Centralized, pure validation for scheduling
//  input (handbook Ch6). No DB, no side-effects. Returns a structured result:
//    ['success' => bool, 'errors' => [field => message], 'data' => [clean fields]]
//  The `data` array is the sanitized, DB-ready payload the repository expects.
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Interview;

final class InterviewValidator
{
    public const TYPES    = ['technical', 'hr', 'final'];
    public const MODES    = ['online', 'in-person'];
    public const STATUSES = ['scheduled', 'completed', 'cancelled', 'no-show'];

    /**
     * @param array $in    raw input (e.g. $_POST)
     * @param bool  $isNew true for create (enforces "not in the past"); false for update
     * @param string $today injectable for testing (defaults to server today)
     */
    public function validateSchedule(array $in, bool $isNew = true, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $errors = [];

        $candidateId = (int)($in['candidate_id'] ?? 0);
        if ($candidateId <= 0) $errors['candidate_id'] = 'Select a candidate.';

        $interviewer = trim((string)($in['interviewer'] ?? ''));
        if ($interviewer === '') $errors['interviewer'] = 'Interviewer name is required.';
        elseif (mb_strlen($interviewer) > 100) $errors['interviewer'] = 'Interviewer name is too long.';

        $date = trim((string)($in['scheduled_date'] ?? ''));
        if ($date === '' || !self::isValidDate($date)) {
            $errors['scheduled_date'] = 'A valid date is required.';
        } elseif ($isNew && $date < $today) {
            $errors['scheduled_date'] = 'Interview date cannot be in the past.';
        }

        $time = trim((string)($in['scheduled_time'] ?? ''));
        if ($time === '' || !self::isValidTime($time)) {
            $errors['scheduled_time'] = 'A valid time is required.';
        }

        $type = (string)($in['type'] ?? 'technical');
        if (!in_array($type, self::TYPES, true)) $errors['type'] = 'Invalid interview type.';

        $mode = (string)($in['mode'] ?? 'online');
        if (!in_array($mode, self::MODES, true)) $errors['mode'] = 'Invalid interview mode.';

        $status = (string)($in['status'] ?? 'scheduled');
        if (!in_array($status, self::STATUSES, true)) $errors['status'] = 'Invalid status.';

        $notes = trim((string)($in['notes'] ?? ''));

        return [
            'success' => $errors === [],
            'errors'  => $errors,
            'data'    => [
                'candidate_id'   => $candidateId,
                'interviewer'    => $interviewer,
                'scheduled_date' => $date,
                'scheduled_time' => $time,
                'type'           => $type,
                'mode'           => $mode,
                'status'         => $status,
                'notes'          => $notes,
            ],
        ];
    }

    /**
     * Validate a category scorecard (Part 1). Every category is optional (0–10);
     * `overall_score` is taken as given or averaged from the scored categories.
     * A valid recommendation and at least one score are required.
     */
    public function validateScore(array $in): array
    {
        $errors = [];
        $data   = [];
        foreach (InterviewWorkflow::SCORE_CATEGORIES as $cat) {
            $raw = $in[$cat] ?? null;
            if ($raw === null || $raw === '') { $data[$cat] = null; continue; }
            if (!is_numeric($raw)) { $errors[$cat] = 'Score must be a number.'; $data[$cat] = null; continue; }
            $data[$cat] = InterviewWorkflow::clampScore((int)round((float)$raw));
        }
        $scored = array_filter($data, fn($v) => $v !== null);

        if (isset($in['overall_score']) && $in['overall_score'] !== '' && is_numeric($in['overall_score'])) {
            $data['overall_score'] = round(min(10, max(0, (float)$in['overall_score'])), 1);
        } else {
            $data['overall_score'] = $scored ? round(array_sum($scored) / count($scored), 1) : null;
        }
        if (!$scored && $data['overall_score'] === null) {
            $errors['scores'] = 'Enter at least one category score.';
        }

        $rec = (string)($in['recommendation'] ?? '');
        if ($rec === '' || !InterviewWorkflow::isRecommendation($rec)) {
            $errors['recommendation'] = 'A valid recommendation is required.';
        }
        $data['recommendation'] = $rec;
        $data['summary']  = trim((string)($in['summary'] ?? ''));
        $data['comments'] = trim((string)($in['comments'] ?? ''));

        return ['success' => $errors === [], 'errors' => $errors, 'data' => $data];
    }

    /** Validate structured feedback (Part 4). Summary required; rest optional. */
    public function validateFeedback(array $in): array
    {
        $errors  = [];
        $summary = trim((string)($in['summary'] ?? ''));
        if ($summary === '') $errors['summary'] = 'A summary is required.';

        $rec = (string)($in['final_recommendation'] ?? '');
        if ($rec !== '' && !InterviewWorkflow::isRecommendation($rec)) {
            $errors['final_recommendation'] = 'Invalid recommendation.';
        }

        return [
            'success' => $errors === [],
            'errors'  => $errors,
            'data'    => [
                'summary'              => $summary,
                'strengths'            => trim((string)($in['strengths'] ?? '')),
                'weaknesses'           => trim((string)($in['weaknesses'] ?? '')),
                'improvement_areas'    => trim((string)($in['improvement_areas'] ?? '')),
                'technical_notes'      => trim((string)($in['technical_notes'] ?? '')),
                'behaviour_notes'      => trim((string)($in['behaviour_notes'] ?? '')),
                'final_recommendation' => $rec !== '' ? $rec : null,
            ],
        ];
    }

    public static function isValidDate(string $d): bool
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $d);
        return $dt !== false && $dt->format('Y-m-d') === $d;
    }

    public static function isValidTime(string $t): bool
    {
        // Accept HH:MM or HH:MM:SS (the browser <input type=time> sends HH:MM).
        return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $t);
    }
}
