<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Anti-cheat foundation (Module 8C) — CONFIGURABLE EVENT LOGGING ONLY.
//  Never blocks a candidate. Normalises the raw client signals into a fixed
//  vocabulary, filters by the AssessmentConfig proctoring policy, and hands them
//  to the caller to publish through the platform EventBus + persist as the
//  submission's violation/reconnect counters. All decisions (what counts, what
//  auto-submits) live in config, not in code.
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Assessment\Shared;

final class AntiCheat
{
    /** Canonical proctoring signal vocabulary (client sends these codes). */
    public const SIGNALS = [
        'tab_switch', 'window_blur', 'fullscreen_exit', 'reconnect', 'refresh',
        'copy_attempt', 'paste_attempt', 'rapid_submit',
    ];

    /** Signals that increment the violation counter by default (config can override). */
    private const DEFAULT_VIOLATIONS = ['tab_switch', 'window_blur', 'fullscreen_exit'];

    /** Default proctoring policy — merged under AssessmentConfig. */
    public static function defaultPolicy(): array
    {
        return [
            'fullscreen_required'   => true,
            'log_signals'           => self::SIGNALS,           // everything captured
            'violation_signals'     => self::DEFAULT_VIOLATIONS, // subset that "counts"
            'auto_submit_after'     => 3,                        // 0 = never auto-submit on violations
        ];
    }

    /** Is this a recognised signal code? */
    public static function isSignal(string $code): bool { return in_array($code, self::SIGNALS, true); }

    /**
     * Normalise a batch of raw client signals into loggable events.
     * @param array $raw list of ['type'=>code, 'at'=>iso?, 'meta'=>[]]
     * @param array $policy effective proctoring policy (from AssessmentConfig)
     * @return array{events:array,violation_delta:int,reconnect_delta:int}
     */
    public static function normalise(array $raw, array $policy): array
    {
        $logset  = $policy['log_signals']       ?? self::SIGNALS;
        $violset = $policy['violation_signals'] ?? self::DEFAULT_VIOLATIONS;
        $events = []; $violDelta = 0; $reconnDelta = 0;
        foreach ($raw as $r) {
            $code = is_array($r) ? (string)($r['type'] ?? '') : (string)$r;
            if (!self::isSignal($code) || !in_array($code, $logset, true)) continue;
            $events[] = [
                'type' => $code,
                'at'   => is_array($r) ? (string)($r['at'] ?? '') : '',
                'meta' => is_array($r) && isset($r['meta']) && is_array($r['meta']) ? $r['meta'] : [],
                'counts' => in_array($code, $violset, true),
            ];
            if (in_array($code, $violset, true)) $violDelta++;
            if ($code === 'reconnect' || $code === 'refresh') $reconnDelta++;
        }
        return ['events' => $events, 'violation_delta' => $violDelta, 'reconnect_delta' => $reconnDelta];
    }
}
