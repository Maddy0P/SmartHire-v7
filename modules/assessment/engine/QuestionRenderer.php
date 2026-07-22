<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Question renderers (Module 8C, candidate side) — reusable per-type widgets.
//  Driven by QTypeRegistry's `input` field: one renderer per input kind, chosen
//  by the registry, never by hardcoded per-question branching. The player asks
//  QuestionRenderer::render($question, $savedValue); adding a question type =
//  a registry entry + (if a brand-new input kind) one method here.
//
//  Every widget writes into a single hidden field `ans_<qid>` (scalar) or the
//  JSON field `resp_<qid>` (structured), so the existing submit path and the
//  ScoringEngine both keep working unchanged.
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Assessment\Engine;

final class QuestionRenderer
{
    /**
     * @param array $q     joined test_questions+interview_questions row
     * @param mixed $saved previously-saved answer_text (scalar) or decoded response (array)
     */
    public static function render(array $q, mixed $saved = null): string
    {
        $type  = (string)($q['question_type'] ?? 'subjective');
        $input = QTypeRegistry::get($type)['input'] ?? 'textarea';
        $qid   = (int)$q['question_id'];
        return match ($input) {
            'choice'      => self::choice($q, $qid, is_array($saved) ? '' : (string)($saved ?? '')),
            'multichoice' => self::multichoice($q, $qid, is_array($saved) ? ($saved['selected'] ?? $saved) : []),
            'boolean'     => self::boolean($qid, is_array($saved) ? ($saved['value'] ?? null) : $saved),
            'text'        => self::text($qid, is_array($saved) ? ($saved['text'] ?? '') : (string)($saved ?? '')),
            'rating'      => self::rating($q, $qid, is_array($saved) ? ($saved['value'] ?? '') : (string)($saved ?? '')),
            'code'        => self::code($q, $qid, is_array($saved) ? ($saved['text'] ?? '') : (string)($saved ?? '')),
            default       => self::textarea($qid, is_array($saved) ? ($saved['text'] ?? '') : (string)($saved ?? '')),
        };
    }

    private static function opts(array $q): array
    {
        return array_filter(['a' => $q['option_a'] ?? '', 'b' => $q['option_b'] ?? '',
                             'c' => $q['option_c'] ?? '', 'd' => $q['option_d'] ?? ''], fn($v) => $v !== '' && $v !== null);
    }

    private static function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

    private static function choice(array $q, int $qid, string $saved): string
    {
        $h = '<div class="ap-choices" role="radiogroup" aria-label="Answer options">';
        foreach (self::opts($q) as $opt => $txt) {
            $sel = $saved === $opt ? ' is-sel' : '';
            $h .= '<label class="ap-choice' . $sel . '">'
                . '<input type="radio" name="ans_' . $qid . '" value="' . $opt . '"' . ($saved === $opt ? ' checked' : '')
                . ' data-answer="' . $qid . '">'
                . '<span class="ap-choice-key" aria-hidden="true">' . strtoupper($opt) . '</span>'
                . '<span class="ap-choice-txt">' . self::e((string)$txt) . '</span></label>';
        }
        return $h . '</div>';
    }

    private static function multichoice(array $q, int $qid, array $saved): string
    {
        $saved = array_map('strval', $saved);
        $h = '<div class="ap-choices" role="group" aria-label="Select all that apply">'
           . '<input type="hidden" name="resp_' . $qid . '" id="resp_' . $qid . '" data-answer-json="' . $qid . '" value="">';
        foreach (self::opts($q) as $opt => $txt) {
            $sel = in_array($opt, $saved, true);
            $h .= '<label class="ap-choice' . ($sel ? ' is-sel' : '') . '">'
                . '<input type="checkbox" value="' . $opt . '" data-multi="' . $qid . '"' . ($sel ? ' checked' : '') . '>'
                . '<span class="ap-choice-key ap-check" aria-hidden="true">' . strtoupper($opt) . '</span>'
                . '<span class="ap-choice-txt">' . self::e((string)$txt) . '</span></label>';
        }
        return $h . '</div>';
    }

    private static function boolean(int $qid, mixed $saved): string
    {
        $s = $saved === null ? '' : (filter_var($saved, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false');
        $h = '<div class="ap-choices ap-choices-row" role="radiogroup" aria-label="True or false">';
        foreach (['true' => 'True', 'false' => 'False'] as $val => $lbl) {
            $h .= '<label class="ap-choice' . ($s === $val ? ' is-sel' : '') . '">'
                . '<input type="radio" name="ans_' . $qid . '" value="' . $val . '"' . ($s === $val ? ' checked' : '') . ' data-answer="' . $qid . '">'
                . '<span class="ap-choice-txt">' . $lbl . '</span></label>';
        }
        return $h . '</div>';
    }

    private static function text(int $qid, string $saved): string
    {
        return '<input type="text" class="ap-input" name="ans_' . $qid . '" id="ans_' . $qid . '" value="' . self::e($saved)
             . '" data-answer="' . $qid . '" autocomplete="off" aria-label="Your answer" placeholder="Type your answer…">';
    }

    private static function rating(array $q, int $qid, string $saved): string
    {
        $meta = QTypeRegistry::get('rating_scale');
        $max  = (int)($q['rating_max'] ?? 5) ?: 5;
        $h = '<div class="ap-rating" role="radiogroup" aria-label="Rating from 1 to ' . $max . '">';
        for ($n = 1; $n <= $max; $n++) {
            $h .= '<label class="ap-rating-pip' . ((string)$n === $saved ? ' is-sel' : '') . '">'
                . '<input type="radio" name="ans_' . $qid . '" value="' . $n . '"' . ((string)$n === $saved ? ' checked' : '') . ' data-answer="' . $qid . '">'
                . '<span>' . $n . '</span></label>';
        }
        return $h . '</div>';
    }

    private static function code(array $q, int $qid, string $saved): string
    {
        $lang = self::e((string)(($q['metadata_lang'] ?? '') ?: 'code'));
        return '<div class="ap-code-wrap"><div class="ap-code-lang" aria-hidden="true">' . $lang . '</div>'
             . '<textarea class="ap-code" name="ans_' . $qid . '" id="ans_' . $qid . '" data-answer="' . $qid . '" spellcheck="false"'
             . ' aria-label="Code answer" placeholder="Write your solution…">' . self::e($saved) . '</textarea></div>';
    }

    private static function textarea(int $qid, string $saved): string
    {
        return '<textarea class="ap-textarea" name="ans_' . $qid . '" id="ans_' . $qid . '" data-answer="' . $qid . '"'
             . ' aria-label="Your answer" placeholder="Type your detailed answer…">' . self::e($saved) . '</textarea>'
             . '<div class="ap-wc" id="wc_' . $qid . '" aria-live="polite">0 words</div>';
    }
}
