<?php
// ═════════════════════════════════════════════════════════════════════════════
//  QTypeRegistry (Module 8A) — single authority on question types.
//  Adding a type = ONE entry here. Each entry declares:
//    label     — human name
//    group     — core | technical | scenario | future
//    input     — which capture widget the delivery layer renders
//    scoring   — which ScoringEngine strategy applies:
//                  'mcq'          legacy single-choice (byte-equal behaviour)
//                  'multi_select' partial-credit set matching
//                  'boolean'      answer_key {"value": true|false}
//                  'text_match'   answer_key {"accepted":[...]} (trim/ci match)
//                  'exact_output' answer_key {"expected_output": "..."}
//                  'manual'       human (or AI-suggested) review lane
//    deliverable — false ⇒ authorable in the bank but not yet issuable
//                  (delivery widget arrives in a later module / via plugin)
//  DB CHECK constraints on question_type were dropped by migration 001;
//  isValid() below is now the write-path gate.
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Assessment\Engine;

final class QTypeRegistry
{
    /** @return array<string,array{label:string,group:string,input:string,scoring:string,deliverable:bool}> */
    public static function all(): array
    {
        static $types = null;
        return $types ??= [
            // ── legacy (behaviour frozen) ────────────────────────────────────
            'mcq'        => ['label' => 'Multiple choice',   'group' => 'core', 'input' => 'choice',    'scoring' => 'mcq',    'deliverable' => true],
            'subjective' => ['label' => 'Subjective answer', 'group' => 'core', 'input' => 'textarea',  'scoring' => 'manual', 'deliverable' => true],
            // ── core ─────────────────────────────────────────────────────────
            'multi_select'   => ['label' => 'Multiple select',   'group' => 'core', 'input' => 'multichoice', 'scoring' => 'multi_select', 'deliverable' => true],
            'true_false'     => ['label' => 'True / False',      'group' => 'core', 'input' => 'boolean',     'scoring' => 'boolean',      'deliverable' => true],
            'fill_blank'     => ['label' => 'Fill in the blank', 'group' => 'core', 'input' => 'text',        'scoring' => 'text_match',   'deliverable' => true],
            'short_answer'   => ['label' => 'Short answer',      'group' => 'core', 'input' => 'text',        'scoring' => 'manual',       'deliverable' => true],
            'long_answer'    => ['label' => 'Long answer',       'group' => 'core', 'input' => 'textarea',    'scoring' => 'manual',       'deliverable' => true],
            'essay'          => ['label' => 'Essay',             'group' => 'core', 'input' => 'textarea',    'scoring' => 'manual',       'deliverable' => true],
            'paragraph'      => ['label' => 'Paragraph',         'group' => 'core', 'input' => 'textarea',    'scoring' => 'manual',       'deliverable' => true],
            'rating_scale'   => ['label' => 'Rating scale',      'group' => 'core', 'input' => 'rating',      'scoring' => 'manual',       'deliverable' => true],
            // ── technical ────────────────────────────────────────────────────
            'coding'            => ['label' => 'Coding challenge',    'group' => 'technical', 'input' => 'code',     'scoring' => 'manual',       'deliverable' => true],
            'sql_query'         => ['label' => 'SQL query',           'group' => 'technical', 'input' => 'code',     'scoring' => 'manual',       'deliverable' => true],
            'debug_code'        => ['label' => 'Debug code',          'group' => 'technical', 'input' => 'code',     'scoring' => 'manual',       'deliverable' => true],
            'output_prediction' => ['label' => 'Output prediction',   'group' => 'technical', 'input' => 'text',     'scoring' => 'exact_output', 'deliverable' => true],
            'algorithm'         => ['label' => 'Algorithm',           'group' => 'technical', 'input' => 'code',     'scoring' => 'manual',       'deliverable' => true],
            'cloud_scenario'    => ['label' => 'Cloud architecture scenario', 'group' => 'technical', 'input' => 'textarea', 'scoring' => 'manual', 'deliverable' => true],
            'system_design'     => ['label' => 'System design',       'group' => 'technical', 'input' => 'textarea', 'scoring' => 'manual',       'deliverable' => true],
            'api_design'        => ['label' => 'API design',          'group' => 'technical', 'input' => 'textarea', 'scoring' => 'manual',       'deliverable' => true],
            'database_design'   => ['label' => 'Database design',     'group' => 'technical', 'input' => 'textarea', 'scoring' => 'manual',       'deliverable' => true],
            // ── scenario ─────────────────────────────────────────────────────
            'case_study'         => ['label' => 'Case study',         'group' => 'scenario', 'input' => 'textarea', 'scoring' => 'manual', 'deliverable' => true],
            'business_scenario'  => ['label' => 'Business scenario',  'group' => 'scenario', 'input' => 'textarea', 'scoring' => 'manual', 'deliverable' => true],
            'incident_response'  => ['label' => 'Incident response',  'group' => 'scenario', 'input' => 'textarea', 'scoring' => 'manual', 'deliverable' => true],
            'customer_support'   => ['label' => 'Customer support',   'group' => 'scenario', 'input' => 'textarea', 'scoring' => 'manual', 'deliverable' => true],
            'team_management'    => ['label' => 'Team management',    'group' => 'scenario', 'input' => 'textarea', 'scoring' => 'manual', 'deliverable' => true],
            'project_management' => ['label' => 'Project management', 'group' => 'scenario', 'input' => 'textarea', 'scoring' => 'manual', 'deliverable' => true],
            // ── future (architecture-ready; widgets/plugins pending) ─────────
            'video_response'   => ['label' => 'Video response',   'group' => 'future', 'input' => 'media',    'scoring' => 'manual', 'deliverable' => false],
            'audio_response'   => ['label' => 'Audio response',   'group' => 'future', 'input' => 'media',    'scoring' => 'manual', 'deliverable' => false],
            'screen_recording' => ['label' => 'Screen recording', 'group' => 'future', 'input' => 'media',    'scoring' => 'manual', 'deliverable' => false],
            'whiteboard'       => ['label' => 'Whiteboard',       'group' => 'future', 'input' => 'canvas',   'scoring' => 'manual', 'deliverable' => false],
            'file_upload'      => ['label' => 'File upload',      'group' => 'future', 'input' => 'file',     'scoring' => 'manual', 'deliverable' => false],
            'diagram_builder'  => ['label' => 'Diagram builder',  'group' => 'future', 'input' => 'canvas',   'scoring' => 'manual', 'deliverable' => false],
            'interactive_lab'  => ['label' => 'Interactive lab',  'group' => 'future', 'input' => 'external', 'scoring' => 'manual', 'deliverable' => false],
        ];
    }

    public static function isValid(string $type): bool      { return isset(self::all()[$type]); }
    public static function get(string $type): ?array        { return self::all()[$type] ?? null; }
    public static function scoringStrategy(string $type): string
    { return self::all()[$type]['scoring'] ?? 'manual'; }
    public static function isAutoScorable(string $type): bool
    { return self::scoringStrategy($type) !== 'manual'; }
    public static function isDeliverable(string $type): bool
    { return (bool)(self::all()[$type]['deliverable'] ?? false); }
    /** @return string[] type codes in a group */
    public static function byGroup(string $group): array
    {
        return array_keys(array_filter(self::all(), fn($t) => $t['group'] === $group));
    }
}
