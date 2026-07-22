<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Generator (Module 8A) — assembles an assessment from pools + section rules.
//  PURE: operates on arrays of question ids grouped by difficulty; all DB I/O
//  happens in repositories, all persistence in AssessmentService. Determinism
//  is testable via an explicit seed.
//
//  Section spec (from assessment_template_sections or an ad-hoc request):
//    ['name','pool' => ['easy'=>[ids],'medium'=>[ids],'hard'=>[ids]],
//     'question_count', 'difficulty_mix' => ['easy'=>n,...] (optional),
//     'weight_per_question' (optional; default: bank max_score at persist time),
//     'time_minutes' (optional), 'section_id' (optional)]
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Assessment\Engine;

use SmartHire\Assessment\Shared\AssessmentConfig;

final class Generator
{
    /**
     * Select question ids for every section.
     * @return array{sections: array<int,array{name:string,section_id:?int,time_minutes:?int,question_ids:int[],shortfall:int}>, question_ids:int[]}
     * @throws \RuntimeException when a section can't be filled and underfill is disallowed
     */
    public function select(array $sections, AssessmentConfig $cfg, ?int $seed = null): array
    {
        if ($seed !== null) mt_srand($seed);
        $randomize = (bool)$cfg->get('randomize', false);
        $allowUnderfill = (bool)$cfg->get('allow_underfill', true);

        $out = ['sections' => [], 'question_ids' => []];
        $used = [];   // no question repeats across sections of one assessment

        foreach ($sections as $i => $s) {
            $want = max(0, (int)($s['question_count'] ?? 0));
            $pool = $s['pool'] ?? ['easy' => [], 'medium' => [], 'hard' => []];
            foreach (['easy', 'medium', 'hard'] as $d) {
                $pool[$d] = array_values(array_diff(array_map('intval', $pool[$d] ?? []), $used));
            }
            $mix = $s['difficulty_mix'] ?? $cfg->get('difficulty_mix', []);
            $picked = [];

            if (is_array($mix) && $mix !== []) {
                foreach (['easy', 'medium', 'hard'] as $d) {
                    $n = min((int)($mix[$d] ?? 0), $want - count($picked));
                    $picked = array_merge($picked, $this->draw($pool[$d], $n, $randomize));
                }
            }
            // top up (or the whole draw when no mix) from every difficulty
            if (count($picked) < $want) {
                $rest = array_values(array_diff(
                    array_merge($pool['easy'], $pool['medium'], $pool['hard']), $picked));
                $picked = array_merge($picked, $this->draw($rest, $want - count($picked), $randomize));
            }

            $shortfall = $want - count($picked);
            if ($shortfall > 0 && !$allowUnderfill) {
                throw new \RuntimeException(
                    "Section '" . ($s['name'] ?? "#$i") . "' needs $want questions; pool provides only " . count($picked));
            }
            if ($randomize) $picked = $this->shuffle($picked);

            $used = array_merge($used, $picked);
            $out['sections'][] = [
                'name'         => (string)($s['name'] ?? 'Section ' . ($i + 1)),
                'section_id'   => isset($s['section_id']) ? (int)$s['section_id'] : null,
                'time_minutes' => isset($s['time_minutes']) ? (int)$s['time_minutes'] : null,
                'question_ids' => $picked,
                'shortfall'    => max(0, $shortfall),
            ];
            $out['question_ids'] = array_merge($out['question_ids'], $picked);
        }
        return $out;
    }

    /** @param int[] $ids */
    private function draw(array $ids, int $n, bool $randomize): array
    {
        if ($n <= 0 || !$ids) return [];
        $ids = array_values($ids);
        if ($randomize) $ids = $this->shuffle($ids);
        return array_slice($ids, 0, $n);
    }

    /** Fisher–Yates on mt_rand so an explicit seed makes selection reproducible. */
    private function shuffle(array $a): array
    {
        for ($i = count($a) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
        }
        return $a;
    }
}
