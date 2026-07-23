<?php
// ═════════════════════════════════════════════════════════════════════════════
//  OfferService (Module 10 Phase 1). The single facade pages consume. Holds the
//  business rules — validation, lifecycle transitions, edit-locking — and the
//  side-effects (immutable history + audit logging). No SQL (delegated to the
//  repository) and no HTML. Side-effects are injected callables so the flow is
//  unit-testable; ::production() binds them to the app's global helpers.
//  Notifications, PDF generation, and the candidate/approval workflows arrive in
//  later phases; this phase establishes the architecture + core create/edit.
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Offer;

final class OfferService
{
    /** @param callable $audit fn(string $action, ?string $entity, ?int $id, ?string $detail): void */
    public function __construct(
        private OfferRepository $repo,
        private OfferValidator $validator,
        private $audit,
    ) {}

    public static function production(): self
    {
        return new self(
            new OfferRepository(new GlobalDb()),
            new OfferValidator(),
            audit: fn(string $a, ?string $e, ?int $id, ?string $d = null) => audit_log($a, $e, $id, $d),
        );
    }

    /**
     * Create a draft offer (typically from a completed interview).
     * @return array{ok:bool, id?:int, errors?:array, error?:string}
     */
    public function createOffer(array $input, ?int $actorId = null, ?string $actorName = null, ?string $today = null): array
    {
        $v = $this->validator->validateOffer($input, $today);
        if (!$v['success']) return ['ok' => false, 'errors' => $v['errors']];
        $d = $v['data'];
        $d['status'] = 'draft';

        $id = $this->repo->create($d, $actorId);
        if ($id === false) return ['ok' => false, 'error' => 'db'];

        $this->repo->addHistory($id ?: 0, null, 'draft', $actorId, $actorName, 'Offer drafted');
        ($this->audit)('offer_created', 'offer', $id ?: null, 'candidate=' . $d['candidate_id']);
        return ['ok' => true, 'id' => $id];
    }

    /**
     * Edit offer details. Only permitted while the offer is editable (draft /
     * rejected); locked once approved, sent, accepted, etc. (Phase 4 lock).
     * @return array{ok:bool, errors?:array, error?:string}
     */
    public function updateOffer(int $id, array $input, ?int $actorId = null, ?string $actorName = null, ?string $today = null, ?array $actor = null): array
    {
        if ($id <= 0) return ['ok' => false, 'error' => 'bad_id'];
        $offer = $this->repo->findById($id);
        if (!$offer) return ['ok' => false, 'error' => 'not_found'];
        // Ownership guard (Phase 2). Optional so Phase 1 callers are unaffected.
        if ($actor !== null && !$this->canAccess($offer, $actor)) return ['ok' => false, 'error' => 'forbidden'];
        if (!OfferWorkflow::isEditable((string)$offer['status'])) return ['ok' => false, 'error' => 'locked'];

        $v = $this->validator->validateOffer($input + ['candidate_id' => $offer['candidate_id']], $today);
        if (!$v['success']) return ['ok' => false, 'errors' => $v['errors']];

        if (!$this->repo->update($id, $v['data'])) return ['ok' => false, 'error' => 'db'];
        ($this->audit)('offer_updated', 'offer', $id, null);
        return ['ok' => true];
    }

    /**
     * Move the offer to a new lifecycle state, enforcing the transition graph and
     * recording an immutable history row. Illegal transitions are rejected.
     * @return array{ok:bool, error?:string}
     */
    public function transition(int $id, string $to, ?int $actorId = null, ?string $actorName = null, ?string $notes = null): array
    {
        if ($id <= 0) return ['ok' => false, 'error' => 'bad_id'];
        if (!OfferWorkflow::isStatus($to)) return ['ok' => false, 'error' => 'bad_status'];
        $offer = $this->repo->findById($id);
        if (!$offer) return ['ok' => false, 'error' => 'not_found'];

        $from = (string)$offer['status'];
        if (!OfferWorkflow::canTransition($from, $to)) return ['ok' => false, 'error' => 'bad_transition'];

        if (!$this->repo->updateStatus($id, $to)) return ['ok' => false, 'error' => 'db'];
        $this->repo->addHistory($id, $from, $to, $actorId, $actorName, $notes);
        ($this->audit)('offer_status', 'offer', $id, $from . '→' . $to);
        return ['ok' => true, 'from' => $from, 'to' => $to];
    }

    /**
     * Delete a draft offer (Phase 2). Only drafts may be removed — anything that
     * has entered the approval/send lifecycle is retained for the audit trail.
     * @return array{ok:bool, error?:string}
     */
    public function deleteOffer(int $id, ?array $actor = null): array
    {
        if ($id <= 0) return ['ok' => false, 'error' => 'bad_id'];
        $offer = $this->repo->findById($id);
        if (!$offer) return ['ok' => false, 'error' => 'not_found'];
        if ($actor !== null && !$this->canAccess($offer, $actor)) return ['ok' => false, 'error' => 'forbidden'];
        if ((string)$offer['status'] !== 'draft') return ['ok' => false, 'error' => 'not_draft'];

        if (!$this->repo->delete($id)) return ['ok' => false, 'error' => 'db'];
        ($this->audit)('offer_deleted', 'offer', $id, 'candidate=' . $offer['candidate_id']);
        return ['ok' => true];
    }

    // ── Phase 2: RBAC scoping ────────────────────────────────────────────────
    // Roles at or above HR see every offer; a plain recruiter sees only offers
    // they created or are assigned to. Pure + testable: no globals, no session.

    public const FULL_VISIBILITY_ROLES = ['hr', 'admin', 'super_admin'];

    /** null = unrestricted; otherwise the user id the query must be scoped to. */
    public static function scopeUserId(array $user): ?int
    {
        return in_array((string)($user['role'] ?? ''), self::FULL_VISIBILITY_ROLES, true)
            ? null
            : (int)($user['id'] ?? 0);
    }

    /** May this user read/manage this offer? */
    public function canAccess(array $offer, array $user): bool
    {
        if (in_array((string)($user['role'] ?? ''), self::FULL_VISIBILITY_ROLES, true)) return true;
        $uid = (int)($user['id'] ?? 0);
        if ($uid <= 0) return false;
        return (int)($offer['created_by'] ?? 0) === $uid || (int)($offer['recruiter_id'] ?? 0) === $uid;
    }

    // ── Phase 3: approval workflow ───────────────────────────────────────────
    // Every transition validates permission → current state → records an
    // immutable history row (actor, role, timestamp, comment, IP) and, for
    // approver decisions, an append-only offer_approvals stage row.

    /** Recruiter submits a draft (or a revised offer) into the approval chain. */
    public function submitForApproval(int $id, array $actor, ?string $comment = null): array
    {
        [$offer, $err] = $this->loadFor($id, $actor);
        if ($err) return $err;

        $status = (string)$offer['status'];
        if (!in_array($status, ['draft', 'changes_requested'], true)) return ['ok' => false, 'error' => 'bad_state'];
        if ($this->isExpired($offer)) return ['ok' => false, 'error' => 'expired'];

        // A fresh review cycle: any decision from a previous round is superseded.
        $prior = $this->repo->approvals($id);
        $cycle = $prior ? max(array_map(fn($a) => (int)$a['cycle'], $prior)) + 1 : 1;

        $this->repo->updateApprovalCycle($id, $cycle);
        if (!$this->repo->updateStatus($id, 'pending_approval')) return ['ok' => false, 'error' => 'db'];
        $this->record($id, $status, 'pending_approval', $actor,
            $comment ?: ($status === 'changes_requested' ? 'Resubmitted for approval' : 'Submitted for approval'));
        ($this->audit)('offer_submitted', 'offer', $id, 'cycle=' . $cycle);
        return ['ok' => true, 'cycle' => $cycle];
    }

    /** Recruiter pulls a pending offer back for editing. */
    public function withdraw(int $id, array $actor, ?string $comment = null): array
    {
        [$offer, $err] = $this->loadFor($id, $actor);
        if ($err) return $err;
        if ((string)$offer['status'] !== 'pending_approval') return ['ok' => false, 'error' => 'bad_state'];

        if (!$this->repo->updateStatus($id, 'draft')) return ['ok' => false, 'error' => 'db'];
        $this->record($id, 'pending_approval', 'draft', $actor, $comment ?: 'Withdrawn by recruiter');
        ($this->audit)('offer_withdrawn', 'offer', $id, null);
        return ['ok' => true];
    }

    /** Approver signs off the next stage; the last stage approves the offer. */
    public function approve(int $id, array $actor, ?string $comment = null): array
    {
        return $this->decide($id, $actor, 'approved', $comment);
    }

    /** Approver rejects the offer outright (read-only afterwards). */
    public function reject(int $id, array $actor, ?string $comment = null): array
    {
        return $this->decide($id, $actor, 'rejected', $comment);
    }

    /** Approver returns the offer to the recruiter for revision. */
    public function requestChanges(int $id, array $actor, ?string $comment = null): array
    {
        return $this->decide($id, $actor, 'changes_requested', $comment);
    }

    /** Shared approver decision path — one place for every guard. */
    private function decide(int $id, array $actor, string $decision, ?string $comment): array
    {
        if ($id <= 0) return ['ok' => false, 'error' => 'bad_id'];
        $offer = $this->repo->findById($id);
        if (!$offer) return ['ok' => false, 'error' => 'not_found'];

        // State: only a pending offer can receive a decision. This alone blocks
        // double approval, approving a draft, and approving after rejection.
        if ((string)$offer['status'] !== 'pending_approval') return ['ok' => false, 'error' => 'bad_state'];
        if ($this->isExpired($offer)) return ['ok' => false, 'error' => 'expired'];

        $state = $this->approvalState($offer, $this->repo->approvals($id));
        $stage = $state['next_stage'];
        if ($stage === null) return ['ok' => false, 'error' => 'bad_state'];

        // Permission: role must own this stage, and nobody approves their own offer.
        $role = (string)($actor['role'] ?? '');
        if (!OfferWorkflow::roleCanActOnStage($role, $stage)) return ['ok' => false, 'error' => 'forbidden'];
        if ($this->isOwnOffer($offer, $actor) && !in_array($role, OfferWorkflow::SELF_APPROVAL_ROLES, true)) {
            return ['ok' => false, 'error' => 'self_approval'];
        }

        $cycle = $state['cycle'];
        $this->repo->addApproval($id, $cycle, $stage, $decision, (int)($actor['id'] ?? 0),
            (string)($actor['name'] ?? ''), $role, $comment, $actor['ip'] ?? null);

        // Approving the last stage completes the chain; anything else ends the round.
        $approved = $state['approved_stages'];
        if ($decision === 'approved') {
            $approved[] = $stage;
            if (!OfferWorkflow::chainComplete($approved)) {
                ($this->audit)('offer_stage_approved', 'offer', $id, 'stage=' . $stage . ' cycle=' . $cycle);
                $this->record($id, 'pending_approval', 'pending_approval', $actor,
                    $comment, OfferWorkflow::stage($stage)['label'] . ' approved');
                return ['ok' => true, 'stage' => $stage, 'complete' => false];
            }
            $to = 'approved';
        } else {
            $to = $decision;   // rejected | changes_requested
        }

        if (!$this->repo->updateStatus($id, $to)) return ['ok' => false, 'error' => 'db'];
        $this->record($id, 'pending_approval', $to, $actor, $comment);
        ($this->audit)('offer_' . $to, 'offer', $id, 'stage=' . $stage . ' cycle=' . $cycle);
        return ['ok' => true, 'stage' => $stage, 'complete' => $to === 'approved'];
    }

    /**
     * Derive the approval stepper from stored decisions — the single source the
     * UI renders. Nodes: the recruiter submission followed by each chain stage.
     */
    public function approvalState(array $offer, array $approvals): array
    {
        $status = (string)($offer['status'] ?? 'draft');
        // The offer owns its current cycle — a fresh round has no decisions yet,
        // so it can never be inferred from the stored decisions alone.
        $cycle  = (int)($offer['approval_cycle']
                  ?? ($approvals ? max(array_map(fn($a) => (int)$a['cycle'], $approvals)) : 1));
        $round  = array_values(array_filter($approvals, fn($a) => (int)$a['cycle'] === $cycle));

        $byStage = [];
        foreach ($round as $a) $byStage[(string)$a['stage']] = $a;   // latest wins

        $approvedStages = [];
        foreach ($round as $a) if ((string)$a['decision'] === 'approved') $approvedStages[] = (string)$a['stage'];
        $next = OfferWorkflow::nextStage($approvedStages);

        // Node 0 — the recruiter's own submission.
        $submitted = !in_array($status, ['draft', 'changes_requested'], true) || $cycle > 1;
        $nodes = [[
            'key' => 'recruiter', 'label' => 'Recruiter',
            'state' => $submitted ? 'complete' : 'current',
            'caption' => $submitted ? 'Submitted for approval' : 'Draft in progress',
            'approver' => (string)($offer['created_by_name'] ?? ''), 'decision' => '', 'when' => '', 'comment' => '',
        ]];

        foreach (OfferWorkflow::APPROVAL_CHAIN as $s) {
            $d = $byStage[$s['key']] ?? null;
            if ($d !== null) {
                $dec   = (string)$d['decision'];
                $state = $dec === 'approved' ? 'complete' : ($dec === 'rejected' ? 'rejected' : 'returned');
            } elseif ($status === 'pending_approval' && $s['key'] === $next) {
                $state = 'current';
            } else {
                $state = 'pending';
            }
            $nodes[] = [
                'key' => $s['key'], 'label' => $s['label'], 'state' => $state,
                'caption'  => $d !== null ? ucwords(str_replace('_', ' ', (string)$d['decision'])) : 'Awaiting decision',
                'approver' => (string)($d['approver_name'] ?? ''),
                'decision' => (string)($d['decision'] ?? ''),
                'when'     => (string)($d['created_at'] ?? ''),
                'comment'  => (string)($d['comments'] ?? ''),
            ];
        }

        return [
            'cycle' => $cycle, 'approved_stages' => $approvedStages, 'next_stage' => $next,
            'complete' => $next === null, 'decisions' => $byStage, 'nodes' => $nodes,
        ];
    }

    /** May this user act on the offer's current approval stage right now? */
    public function canDecide(array $offer, array $approvals, array $user): bool
    {
        if ((string)($offer['status'] ?? '') !== 'pending_approval') return false;
        if ($this->isExpired($offer)) return false;
        $stage = $this->approvalState($offer, $approvals)['next_stage'];
        if ($stage === null) return false;
        $role = (string)($user['role'] ?? '');
        if (!OfferWorkflow::roleCanActOnStage($role, $stage)) return false;
        if ($this->isOwnOffer($offer, $user) && !in_array($role, OfferWorkflow::SELF_APPROVAL_ROLES, true)) return false;
        return true;
    }

    public function approvalsFor(int $id): array            { return $this->repo->approvals($id); }
    public function approvalsForMany(array $ids): array     { return $this->repo->approvalsForMany($ids); }

    // Internals ---------------------------------------------------------------

    /** Load + ownership-check an offer for a recruiter-side action. */
    private function loadFor(int $id, array $actor): array
    {
        if ($id <= 0) return [null, ['ok' => false, 'error' => 'bad_id']];
        $offer = $this->repo->findById($id);
        if (!$offer) return [null, ['ok' => false, 'error' => 'not_found']];
        if (!$this->canAccess($offer, $actor)) return [null, ['ok' => false, 'error' => 'forbidden']];
        return [$offer, null];
    }

    private function isOwnOffer(array $offer, array $user): bool
    {
        $uid = (int)($user['id'] ?? 0);
        return $uid > 0 && ((int)($offer['created_by'] ?? 0) === $uid || (int)($offer['recruiter_id'] ?? 0) === $uid);
    }

    private function isExpired(array $offer): bool
    {
        $exp = $offer['expiry_date'] ?? null;
        return $exp !== null && $exp !== '' && substr((string)$exp, 0, 10) < date('Y-m-d');
    }

    /** Immutable history row + audit-safe metadata. */
    private function record(int $id, string $from, string $to, array $actor, ?string $comment, ?string $prefix = null): void
    {
        $note = trim(($prefix ? $prefix . ($comment ? ' — ' : '') : '') . (string)$comment);
        $this->repo->addHistory($id, $from, $to, (int)($actor['id'] ?? 0), (string)($actor['name'] ?? ''),
            $note !== '' ? $note : null, (string)($actor['role'] ?? ''), $actor['ip'] ?? null);
    }

    // Read facades ------------------------------------------------------------
    public function find(int $id): ?array         { return $this->repo->findById($id); }
    public function historyFor(int $id): array    { return $this->repo->history($id); }
    public function forCandidate(int $cid): array { return $this->repo->listByCandidate($cid); }

    /** Offer Management Hub list (status filter + search + sort, RBAC-scoped). */
    public function hub(?string $status = null, string $q = '', string $sort = 'newest', ?int $scopeUserId = null): array
    {
        return $this->repo->listAll($status, $q, $sort, $scopeUserId);
    }

    /** Status tallies for the hub chips (one grouped query). */
    public function counts(?int $scopeUserId = null): array
    {
        return $this->repo->statusCounts($scopeUserId);
    }

    /** History for many offers at once, grouped by offer id (drawer rendering). */
    public function historyForMany(array $offerIds): array
    {
        return $this->repo->historyForMany($offerIds);
    }

    /** Offers expiring inside $days (hub KPI / expiry warnings). */
    public function expiring(int $days = 7, ?int $scopeUserId = null): int
    {
        return $this->repo->expiringSoon($days, $scopeUserId);
    }
}
