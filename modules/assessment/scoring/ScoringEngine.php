<?php
// ═════════════════════════════════════════════════════════════════════════════
//  ScoringEngine (Module 8A) — ONE scoring path for every assessment.
//
//  REGRESSION CONTRACT (the whole point of 8A):
//  With AssessmentConfig::defaults(), scoreAnswer() reproduces the pre-8A
//  inline logic of take_test.php EXACTLY for the two legacy types:
//    mcq        →  $selected = $ans; if ($selected && $selected === correct_option)
//                     marks = weight, correct = 1;   else 0/0
//    subjective →  marks 0, correct 0, selected '' (manual HR lane)
//  The legacy truthy check (empty string / '0' never matches) is preserved
//  deliberately. tests/run_tests.php proves parity against a byte-copied
//  reference implementation across a case matrix.
//
//  Extended strategies (multi_select partial credit, boolean, text_match,
//  exact_output, negative marking, per-answer floors, bonus flags) activate
//  ONLY through config/registry — never by default.
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Assessment\Scoring;

use SmartHire\Assessment\Domain\Question;
use SmartHire\Assessment\Domain\Score;
use SmartHire\Assessment\Engine\QTypeRegistry;
use SmartHire\Assessment\Shared\AssessmentConfig;

final class ScoringEngine
{
    /**
     * Score one answer.
     * @param Question $q       the bank question
     * @param int      $weight  marks for this question in this test (test_questions.marks)
     * @param mixed    $answer  scalar POST answer (legacy) or array payload (new types)
     */
    public function scoreAnswer(Question $q, int $weight, mixed $answer, AssessmentConfig $cfg): Score
    {
        $strategy = QTypeRegistry::scoringStrategy($q->type);
        $out = match ($strategy) {
            'mcq'          => $this->scoreMcqLegacy($q, $weight, $answer),
            'boolean'      => $this->scoreBoolean($q, $weight, $answer),
            'text_match'   => $this->scoreTextMatch($q, $weight, $answer),
            'exact_output' => $this->scoreTextMatch($q, $weight, $answer, expectedOutput: true),
            'multi_select' => $this->scoreMultiSelect($q, $weight, $answer, $cfg),
            default        => new Score(0.0, 0, null, needsReview: true),   // manual lane
        };

        // Negative marking: penalises wrong, NON-BLANK, auto-scored answers that
        // earned nothing (partial credit > 0 is never penalised). Off by default
        // (legacy parity); optional min_question_marks floor caps the penalty.
        $neg = (float)$cfg->get('negative_marking', 0.0);
        if ($neg > 0 && !$out->needsReview && $out->isCorrect === 0 && $out->marks == 0.0
            && !$this->isBlank($answer)) {
            $penalty = -1 * $neg * $weight;
            $floor = $cfg->get('min_question_marks', null);
            if ($floor !== null) $penalty = max($penalty, (float)$floor);
            $out = new Score(round($penalty, 2), 0, $out->selectedOption, false,
                             $out->detail + ['negative_marking' => $neg]);
        }
        return $out;
    }

    // ── legacy strategies (behaviour frozen) ─────────────────────────────────

    /** Byte-equal extraction of the pre-8A take_test.php mcq branch. */
    private function scoreMcqLegacy(Question $q, int $weight, mixed $answer): Score
    {
        $ans = is_array($answer) ? '' : (string)$answer;
        $marks = 0; $correct = 0;
        $selected = $ans;
        if ($selected && $selected === ($q->legacyCorrectOption ?? '')) { $marks = $weight; $correct = 1; }
        return new Score((float)$marks, $correct, $selected, needsReview: false);
    }

    // ── extended strategies (config/registry-gated) ──────────────────────────

    private function scoreBoolean(Question $q, int $weight, mixed $answer): Score
    {
        $key = $q->answerKey['value'] ?? null;
        if ($key === null) return new Score(0.0, 0, null, needsReview: true, detail: ['error' => 'missing answer_key.value']);
        if ($this->isBlank($answer)) return new Score(0.0, 0, null, false, ['blank' => true]);
        $given = filter_var(is_array($answer) ? ($answer['value'] ?? null) : $answer,
                            FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $hit = $given !== null && $given === (bool)$key;
        return new Score($hit ? (float)$weight : 0.0, $hit ? 1 : 0, null, false);
    }

    private function scoreTextMatch(Question $q, int $weight, mixed $answer, bool $expectedOutput = false): Score
    {
        $accepted = $expectedOutput
            ? array_filter([(string)($q->answerKey['expected_output'] ?? '')], fn($s) => $s !== '')
            : array_map('strval', (array)($q->answerKey['accepted'] ?? []));
        if (!$accepted) return new Score(0.0, 0, null, needsReview: true, detail: ['error' => 'missing answer_key']);
        if ($this->isBlank($answer)) return new Score(0.0, 0, null, false, ['blank' => true]);
        $given = $this->norm(is_array($answer) ? (string)($answer['text'] ?? '') : (string)$answer);
        foreach ($accepted as $a) {
            if ($this->norm($a) === $given) return new Score((float)$weight, 1, null, false, ['matched' => $a]);
        }
        return new Score(0.0, 0, null, false);
    }

    /**
     * Multi-select. answer_key {"correct": ["a","c"]}. Response: array of picks.
     * partial_credit=false → all-or-nothing (exact set match).
     * partial_credit=true  → marks × max(0, correctPicks − wrongPicks) / totalCorrect.
     */
    private function scoreMultiSelect(Question $q, int $weight, mixed $answer, AssessmentConfig $cfg): Score
    {
        $key = array_values(array_unique(array_map('strval', (array)($q->answerKey['correct'] ?? []))));
        if (!$key) return new Score(0.0, 0, null, needsReview: true, detail: ['error' => 'missing answer_key.correct']);
        if ($this->isBlank($answer)) return new Score(0.0, 0, null, false, ['blank' => true]);
        $picks = array_values(array_unique(array_map('strval',
                    is_array($answer) ? ($answer['selected'] ?? $answer) : [(string)$answer])));
        $correctPicks = count(array_intersect($picks, $key));
        $wrongPicks   = count(array_diff($picks, $key));
        $exact = $correctPicks === count($key) && $wrongPicks === 0;
        if (!$cfg->get('partial_credit', false)) {
            return new Score($exact ? (float)$weight : 0.0, $exact ? 1 : 0, null, false,
                             ['correct_picks' => $correctPicks, 'wrong_picks' => $wrongPicks]);
        }
        $ratio = max(0, $correctPicks - $wrongPicks) / count($key);
        return new Score(round($weight * $ratio, 2), $exact ? 1 : 0, null, false,
                         ['correct_picks' => $correctPicks, 'wrong_picks' => $wrongPicks, 'ratio' => $ratio]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function isBlank(mixed $answer): bool
    {
        if ($answer === null) return true;
        if (is_array($answer)) return count(array_filter($answer, fn($v) => $v !== '' && $v !== null)) === 0;
        return trim((string)$answer) === '';
    }

    private function norm(string $s): string { return mb_strtolower(trim(preg_replace('/\s+/', ' ', $s) ?? $s)); }
}
