<?php
// ═════════════════════════════════════════════════════════════════════════════
//  OfferWorkflow (Module 10 Phase 1). Pure rulebook for the offer lifecycle —
//  statuses, allowed transitions, employment types. No DB, no side-effects: the
//  single source of truth the validator + service consult so no lifecycle rule
//  ever leaks into a page controller (handbook Ch6/Ch12).
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Offer;

final class OfferWorkflow
{
    /** Configurable offer states (Phase 3). */
    public const STATUSES = [
        'draft', 'pending_approval', 'approved', 'rejected', 'changes_requested',
        'sent', 'accepted', 'declined', 'expired', 'cancelled',
    ];

    /**
     * Allowed state transitions. Terminal states map to an empty set.
     * Phase 3 rules: pending_approval may be withdrawn back to draft by the
     * owning recruiter, returned as changes_requested by an approver, or closed
     * as approved/rejected. changes_requested behaves like a draft that can be
     * revised and resubmitted. rejected is read-only (only cancellation remains).
     */
    private const TRANSITIONS = [
        'draft'             => ['pending_approval', 'cancelled'],
        'pending_approval'  => ['approved', 'rejected', 'changes_requested', 'draft', 'cancelled'],
        'changes_requested' => ['pending_approval', 'cancelled'],
        'approved'          => ['sent', 'cancelled'],
        'rejected'          => ['cancelled'],
        'sent'              => ['accepted', 'declined', 'expired', 'cancelled'],
        'accepted'          => [],
        'declined'          => [],
        'expired'           => ['cancelled'],
        'cancelled'         => [],
    ];

    /**
     * States in which recruiters may still edit offer details.
     * Phase 3 spec: Draft is editable, Changes Requested is editable (revise and
     * resubmit), Pending Approval is locked, Approved and Rejected are read-only.
     */
    private const EDITABLE = ['draft', 'changes_requested'];

    /** States that permanently lock the offer (no further transitions / edits). */
    private const TERMINAL = ['accepted', 'declined', 'cancelled'];

    /**
     * Ordered approval chain (Phase 3). The offer stays in pending_approval until
     * every stage has approved; the final approval flips it to `approved`. Each
     * stage names the roles permitted to act on it — the platform has no dedicated
     * "hiring manager" role, so the existing role set is mapped onto the chain.
     */
    public const APPROVAL_CHAIN = [
        ['key' => 'hiring_manager', 'label' => 'Hiring Manager', 'roles' => ['hr', 'admin', 'super_admin']],
        ['key' => 'hr_manager',     'label' => 'HR Manager',     'roles' => ['hr', 'admin', 'super_admin']],
        ['key' => 'final_approval', 'label' => 'Final Approval', 'roles' => ['admin', 'super_admin']],
    ];

    /** Roles allowed to bypass the "never approve your own offer" rule. */
    public const SELF_APPROVAL_ROLES = ['super_admin'];

    public const EMPLOYMENT_TYPES = ['full_time', 'part_time', 'contract', 'internship', 'temporary'];

    public static function isStatus(string $s): bool         { return in_array($s, self::STATUSES, true); }
    public static function isEmploymentType(string $t): bool  { return in_array($t, self::EMPLOYMENT_TYPES, true); }
    public static function isEditable(string $status): bool   { return in_array($status, self::EDITABLE, true); }
    public static function isTerminal(string $status): bool   { return in_array($status, self::TERMINAL, true); }

    /** May the offer move from $from to $to? */
    public static function canTransition(string $from, string $to): bool
    {
        return self::isStatus($to) && in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** The set of states reachable from $from (for UI / validation). */
    public static function nextStates(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    // ── Approval chain helpers (Phase 3) ─────────────────────────────────────

    /** Ordered stage keys, e.g. ['hiring_manager','hr_manager','final_approval']. */
    public static function stageKeys(): array
    {
        return array_column(self::APPROVAL_CHAIN, 'key');
    }

    public static function isStage(string $key): bool
    {
        return in_array($key, self::stageKeys(), true);
    }

    public static function stage(string $key): ?array
    {
        foreach (self::APPROVAL_CHAIN as $s) if ($s['key'] === $key) return $s;
        return null;
    }

    /** May this role act on this stage? */
    public static function roleCanActOnStage(string $role, string $stageKey): bool
    {
        $s = self::stage($stageKey);
        return $s !== null && in_array($role, $s['roles'], true);
    }

    /**
     * The next stage awaiting a decision, given the stage keys already approved.
     * Returns null when every stage has approved (offer is fully approved).
     */
    public static function nextStage(array $approvedStageKeys): ?string
    {
        foreach (self::stageKeys() as $k) {
            if (!in_array($k, $approvedStageKeys, true)) return $k;
        }
        return null;
    }

    /** True when the given approved stages complete the whole chain. */
    public static function chainComplete(array $approvedStageKeys): bool
    {
        return self::nextStage($approvedStageKeys) === null;
    }
}
