<?php
// ═════════════════════════════════════════════════════════════════════════════
//  AssessmentConfig layer (Module 8A) — no hard-coded rules in engines.
//  Merge chain:  platform defaults  ←  template.config  ←  online_tests.config
//  The instance snapshot (online_tests.config, frozen at generation time) wins,
//  so scoring never shifts under a candidate because a template was edited.
//  CRITICAL: defaults() reproduces legacy behaviour EXACTLY — with defaults,
//  the ScoringEngine is byte-equivalent to the pre-8A inline take_test logic.
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Assessment\Shared;

final class AssessmentConfig
{
    private function __construct(private readonly array $c) {}

    public static function defaults(): array
    {
        return [
            // scoring — legacy-equivalent defaults
            'negative_marking'   => 0.0,    // fraction of weight deducted per wrong auto-scored answer
            'partial_credit'     => false,  // multi-select proportional credit
            'blank_scores_zero'  => true,   // unanswered = 0 (legacy)
            'min_question_marks' => null,   // optional per-answer floor (used with negative marking)
            // structure
            'randomize'          => false,
            'shuffle_options'    => false,
            'section_time_rules' => [],     // section_id => minutes
            // outcome
            'recommendation_bands' => [     // pct >= threshold → band (checked in order)
                ['min' => 85, 'value' => 'strong_yes'],
                ['min' => 65, 'value' => 'yes'],
                ['min' => 45, 'value' => 'maybe'],
                ['min' => 0,  'value' => 'no'],
            ],
            'passing_pct'        => 40,
            // generation
            'difficulty_mix'     => [],     // {"easy":n,"medium":n,"hard":n} — empty = any
            'allow_underfill'    => true,   // pool smaller than requested → take what exists
        ];
    }

    /** @param array ...$layers later layers override earlier ones (deep for maps) */
    public static function make(array ...$layers): self
    {
        $merged = self::defaults();
        foreach ($layers as $layer) {
            foreach ($layer as $k => $v) {
                $merged[$k] = (is_array($v) && isset($merged[$k]) && is_array($merged[$k]) && !array_is_list($v))
                    ? array_replace($merged[$k], $v) : $v;
            }
        }
        return new self($merged);
    }

    public static function fromInstance(array $templateConfig = [], array $instanceConfig = []): self
    {
        return self::make($templateConfig, $instanceConfig);
    }

    public function get(string $key, mixed $default = null): mixed { return $this->c[$key] ?? $default; }
    public function all(): array { return $this->c; }

    /** Frozen snapshot to persist into online_tests.config at generation time. */
    public function snapshot(): string
    {
        return json_encode($this->c, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function recommendationFor(float $pct): string
    {
        foreach ($this->get('recommendation_bands', []) as $band) {
            if ($pct >= (float)($band['min'] ?? 0)) return (string)($band['value'] ?? 'maybe');
        }
        return 'no';
    }
}
