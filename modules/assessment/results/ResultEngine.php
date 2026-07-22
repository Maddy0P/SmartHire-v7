<?php
// ═════════════════════════════════════════════════════════════════════════════
//  ResultEngine (Module 8A) — one reusable analysis layer.
//  PURE: consumes answer rows (test_answers ⋈ test_questions ⋈ interview_questions,
//  the SubmissionRepository::answersWithQuestions shape) + config, and computes
//  every dimension the directive names. Manual-lane answers use hr_marks when
//  present (mirrors the existing view_test_result recompute), otherwise count
//  as pending review and score 0 until reviewed.
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Assessment\Results;

use SmartHire\Assessment\Domain\Question;
use SmartHire\Assessment\Domain\Result;
use SmartHire\Assessment\Engine\QTypeRegistry;
use SmartHire\Assessment\Shared\AssessmentConfig;

final class ResultEngine
{
    /**
     * @param array $answerRows rows from SubmissionRepository::answersWithQuestions()
     * @param array $sectionNames section_id => name (optional)
     * @param array $history prior attempts [['percentage'=>..], …] oldest first
     */
    public function analyze(array $answerRows, AssessmentConfig $cfg,
                            array $sectionNames = [], array $history = []): Result
    {
        $total = 0.0; $max = 0.0; $pending = 0;
        $sections = []; $skills = []; $difficulty = []; $qa = [];
        $times = [];

        foreach ($answerRows as $r) {
            $type   = (string)($r['question_type'] ?? 'subjective');
            $meta   = Question::jsonb($r['metadata'] ?? null);
            $bonus  = (bool)($meta['bonus'] ?? false);
            $weight = (int)($r['weight'] ?? $r['marks'] ?? 0) ?: (int)($r['max_score'] ?? 10);
            $auto   = QTypeRegistry::isAutoScorable($type);

            if ($auto) {
                $earned = (float)($r['marks_awarded'] ?? 0);
            } elseif (($r['hr_marks'] ?? null) !== null) {
                $earned = (float)$r['hr_marks'];
            } else {
                $earned = 0.0; $pending++;
            }

            // Bonus questions add to the numerator, never the denominator.
            $total += $earned;
            if (!$bonus) $max += $weight;

            $this->bucket($sections, $sectionNames[(int)($r['section_id'] ?? 0)] ?? 'General', $earned, $bonus ? 0 : $weight);
            foreach (array_filter(array_map('trim', explode(',', (string)($r['skills'] ?? '')))) as $skill) {
                $this->bucket($skills, $skill, $earned, $bonus ? 0 : $weight);
            }
            $this->bucket($difficulty, (string)($r['difficulty'] ?? 'medium'), $earned, $bonus ? 0 : $weight);

            $t = (int)($r['time_spent_secs'] ?? 0);
            if ($t > 0) $times[] = ['question_id' => (int)$r['question_id'], 'secs' => $t];

            $qa[] = [
                'question_id' => (int)$r['question_id'],
                'type'        => $type,
                'difficulty'  => (string)($r['difficulty'] ?? 'medium'),
                'earned'      => $earned,
                'max'         => $bonus ? 0 : $weight,
                'bonus'       => $bonus,
                'correct'     => (int)($r['is_correct'] ?? 0) === 1,
                'reviewed'    => !$auto && ($r['hr_marks'] ?? null) !== null,
                'pending'     => !$auto && ($r['hr_marks'] ?? null) === null,
                'time_secs'   => $t,
            ];
        }

        $pct = $max > 0 ? round($total / $max * 100, 2) : 0.0;
        $this->pctify($sections); $this->pctify($skills); $this->pctify($difficulty);

        $secs = array_column($times, 'secs');
        $timeAnalysis = [
            'total_secs'  => array_sum($secs),
            'avg_secs'    => $secs ? (int)round(array_sum($secs) / count($secs)) : 0,
            'slowest'     => $times ? $times[array_search(max($secs), $secs, true)] : null,
            'fastest'     => $times ? $times[array_search(min($secs), $secs, true)] : null,
        ];

        $trend = array_map(fn($h) => (float)($h['percentage'] ?? 0), $history);

        [$strengths, $weaknesses, $suggestions] = $this->insights($skills, $difficulty, $pending);

        return new Result(
            overallPct: $pct, totalMarks: round($total, 2), maxMarks: round($max, 2),
            passed: $pct >= (float)$cfg->get('passing_pct', 40),
            sections: $sections, skills: $skills, difficulty: $difficulty,
            timeAnalysis: $timeAnalysis, questionAnalysis: $qa, trend: $trend,
            recommendation: $cfg->recommendationFor($pct),
            strengths: $strengths, weaknesses: $weaknesses, suggestions: $suggestions,
            pendingReview: $pending,
        );
    }

    private function bucket(array &$b, string $key, float $earned, int $max): void
    {
        $b[$key] ??= ['score' => 0.0, 'max' => 0, 'pct' => 0.0];
        $b[$key]['score'] += $earned;
        $b[$key]['max']   += $max;
    }

    private function pctify(array &$b): void
    {
        foreach ($b as &$v) {
            $v['score'] = round($v['score'], 2);
            $v['pct'] = $v['max'] > 0 ? round($v['score'] / $v['max'] * 100, 1) : 0.0;
        }
    }

    /** Deterministic, data-grounded insight lists (an AiInsightWriter plugin may enrich these later). */
    private function insights(array $skills, array $difficulty, int $pending): array
    {
        $strengths = []; $weaknesses = []; $suggestions = [];
        foreach ($skills as $name => $v) {
            if ($v['max'] === 0) continue;
            if ($v['pct'] >= 75)      $strengths[]  = "$name ({$v['pct']}%)";
            elseif ($v['pct'] < 45)   $weaknesses[] = "$name ({$v['pct']}%)";
        }
        foreach ($weaknesses as $w) {
            $suggestions[] = 'Revisit ' . preg_replace('/ \(.+\)$/', '', $w) . ' fundamentals before the next round.';
        }
        if (($difficulty['hard']['max'] ?? 0) > 0 && ($difficulty['hard']['pct'] ?? 0) < 40
            && ($difficulty['easy']['pct'] ?? 0) >= 70) {
            $suggestions[] = 'Solid on fundamentals; practice harder, multi-step problems.';
        }
        if ($pending > 0) {
            $suggestions[] = "$pending answer(s) await manual review — final score may rise.";
        }
        return [$strengths, $weaknesses, $suggestions];
    }
}
