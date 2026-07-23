<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Module 10 — Offer Management tests (Phase 1: architecture). In-memory FakeDb
//  routes the OfferRepository SQL; audit side-effects are captured via a fake.
//  Covers the workflow rulebook, validator, entity hydration, repository wiring,
//  and the service facade (create / update / transition / history).
// ═════════════════════════════════════════════════════════════════════════════

require_once dirname(__DIR__) . '/modules/offers/bootstrap.php';

use SmartHire\Offer\DbAdapter;
use SmartHire\Offer\OfferRepository;
use SmartHire\Offer\OfferValidator;
use SmartHire\Offer\OfferService;
use SmartHire\Offer\OfferWorkflow;
use SmartHire\Offer\Domain\Offer;

section('Module 10 — Offer Management (Phase 1)');

$makeOfferFake = function () {
    return new class implements DbAdapter {
        public array $offers = [];     // id => row
        public array $history = [];    // append-only list
        public array $writes = [];
        public int $nextId = 500;
        public int $nextHid = 1;
        public bool $failCreate = false;
        public array $calls = [];      // every SQL executed, for contract assertions
        public array $approvals = [];  // append-only stage decisions
        public int $expiring = 0;

        public function fetchOne(string $sql, string $t = '', mixed ...$p): ?array {
            $this->calls[] = ['sql' => $sql, 'types' => $t, 'params' => $p];
            if (str_contains($sql, 'COUNT(*) n FROM offers o')) return ['n' => $this->expiring];
            if (str_contains($sql, 'FROM offers WHERE id=?')) return $this->offers[(int)$p[0]] ?? null;
            return null;
        }
        public function fetchAll(string $sql, string $t = '', mixed ...$p): array {
            $this->calls[] = ['sql' => $sql, 'types' => $t, 'params' => $p];
            if (str_contains($sql, 'GROUP BY o.status')) {
                $g = [];
                foreach ($this->offers as $o) { $s = (string)$o['status']; $g[$s] = ($g[$s] ?? 0) + 1; }
                return array_map(fn($k, $v) => ['status' => $k, 'n' => $v], array_keys($g), array_values($g));
            }
            if (str_contains($sql, 'FROM offer_approvals WHERE offer_id IN')) {
                $ids = array_map('intval', $p);
                return array_values(array_filter($this->approvals, fn($a) => in_array((int)$a['offer_id'], $ids, true)));
            }
            if (str_contains($sql, 'FROM offer_approvals WHERE offer_id=?')) {
                return array_values(array_filter($this->approvals, fn($a) => (int)$a['offer_id'] === (int)$p[0]));
            }
            if (str_contains($sql, 'FROM offer_history WHERE offer_id IN')) {
                $ids = array_map('intval', $p);
                return array_values(array_filter($this->history, fn($h) => in_array((int)$h['offer_id'], $ids, true)));
            }
            if (str_contains($sql, 'FROM offers o')) {           // hub listing
                $rows = array_values($this->offers);
                if (str_contains($sql, 'o.status=?')) {
                    $rows = array_values(array_filter($rows, fn($o) => (string)$o['status'] === (string)$p[0]));
                }
                return $rows;
            }
            if (str_contains($sql, 'FROM offers WHERE candidate_id=?')) {
                return array_values(array_filter($this->offers, fn($o) => $o['candidate_id'] === (int)$p[0]));
            }
            if (str_contains($sql, 'FROM offer_history WHERE offer_id=?')) {
                return array_values(array_filter($this->history, fn($h) => $h['offer_id'] === (int)$p[0]));
            }
            return [];
        }
        /** Last SQL executed, for query-contract assertions. */
        public function lastSql(): string { return end($this->calls)['sql'] ?? ''; }
        public function execute(string $sql, string $t = '', mixed ...$p): bool|int {
            if (str_contains($sql, 'INSERT INTO offers')) {
                if ($this->failCreate) return false;
                $id = $this->nextId++;
                $this->offers[$id] = ['id'=>$id,'candidate_id'=>$p[0],'job_id'=>$p[1],'recruiter_id'=>$p[2],
                    'interview_id'=>$p[3],'job_title'=>$p[4],'department'=>$p[5],'location'=>$p[6],
                    'employment_type'=>$p[7],'salary'=>$p[8],'currency'=>$p[9],'joining_date'=>$p[10],
                    'expiry_date'=>$p[11],'benefits'=>$p[12],'notes'=>$p[13],'status'=>$p[14],'created_by'=>$p[15]];
                $this->writes[] = 'create'; return $id;
            }
            if (str_contains($sql, 'UPDATE offers SET approval_cycle=')) {
                $id = (int)$p[1]; if (isset($this->offers[$id])) $this->offers[$id]['approval_cycle'] = (int)$p[0];
                $this->writes[] = 'cycle'; return 1;
            }
            if (str_contains($sql, 'UPDATE offers SET status=')) {
                $id = (int)$p[1]; if (isset($this->offers[$id])) $this->offers[$id]['status'] = $p[0];
                $this->writes[] = 'status'; return 1;
            }
            if (str_contains($sql, 'UPDATE offers SET')) { // field update
                $id = (int)$p[13];
                if (isset($this->offers[$id])) $this->offers[$id] = array_merge($this->offers[$id], [
                    'job_id'=>$p[0],'recruiter_id'=>$p[1],'interview_id'=>$p[2],'job_title'=>$p[3],
                    'department'=>$p[4],'location'=>$p[5],'employment_type'=>$p[6],'salary'=>$p[7],
                    'currency'=>$p[8],'joining_date'=>$p[9],'expiry_date'=>$p[10],'benefits'=>$p[11],'notes'=>$p[12]]);
                $this->writes[] = 'update'; return 1;
            }
            if (str_contains($sql, 'DELETE FROM offers')) {
                unset($this->offers[(int)$p[0]]);
                $this->writes[] = 'delete'; return 1;
            }
            if (str_contains($sql, 'INSERT INTO offer_approvals')) {
                $this->approvals[] = ['id'=>count($this->approvals)+1,'offer_id'=>(int)$p[0],'cycle'=>(int)$p[1],
                    'stage'=>$p[2],'decision'=>$p[3],'approver_id'=>$p[4],'approver_name'=>$p[5],
                    'approver_role'=>$p[6],'comments'=>$p[7],'ip_address'=>$p[8],'created_at'=>'2026-07-23 10:00'];
                $this->writes[] = 'approval'; return count($this->approvals);
            }
            if (str_contains($sql, 'INSERT INTO offer_history')) {
                $this->history[] = ['id'=>$this->nextHid,'offer_id'=>(int)$p[0],'from_status'=>$p[1],
                    'to_status'=>$p[2],'actor_id'=>$p[3],'actor_name'=>$p[4],'notes'=>$p[5],
                    'actor_role'=>$p[6] ?? null,'ip_address'=>$p[7] ?? null,'created_at'=>'2026-07-23 10:00'];
                $this->writes[] = 'history'; return $this->nextHid++;
            }
            return 1;
        }
    };
};

$makeOfferSvc = function ($fake) use (&$ofx) {
    $ofx = ['audit' => []];
    return new OfferService(
        new OfferRepository($fake),
        new OfferValidator(),
        audit: function ($a, $e, $i, $d = null) use (&$ofx) { $ofx['audit'][] = [$a, $e, $i, $d]; },
    );
};

$validOffer = [
    'candidate_id' => 9, 'job_id' => 3, 'recruiter_id' => 2, 'interview_id' => 50,
    'job_title' => 'Backend Engineer', 'department' => 'Engineering', 'location' => 'Pune',
    'employment_type' => 'full_time', 'salary' => '1800000', 'currency' => 'INR',
    'joining_date' => '2099-02-01', 'expiry_date' => '2099-01-20', 'benefits' => 'Health', 'notes' => 'Round cleared',
];

// ── Workflow rulebook ─────────────────────────────────────────────────────────
ok(OfferWorkflow::isStatus('sent') && !OfferWorkflow::isStatus('bogus'), 'Workflow: status enum');
ok(OfferWorkflow::isEmploymentType('contract') && !OfferWorkflow::isEmploymentType('slave'), 'Workflow: employment type enum');
ok(OfferWorkflow::canTransition('draft', 'pending_approval'), 'Workflow: draft → pending_approval allowed');
ok(!OfferWorkflow::canTransition('draft', 'accepted'), 'Workflow: draft → accepted disallowed');
ok(OfferWorkflow::canTransition('sent', 'accepted') && OfferWorkflow::canTransition('sent', 'declined'), 'Workflow: sent → accepted/declined allowed');
ok(!OfferWorkflow::canTransition('accepted', 'draft'), 'Workflow: accepted is terminal');
ok(OfferWorkflow::isEditable('draft') && OfferWorkflow::isEditable('changes_requested') && !OfferWorkflow::isEditable('sent'), 'Workflow: editable states');
ok(OfferWorkflow::isTerminal('accepted') && OfferWorkflow::isTerminal('cancelled') && !OfferWorkflow::isTerminal('draft'), 'Workflow: terminal states');
ok(OfferWorkflow::nextStates('approved') === ['sent', 'cancelled'], 'Workflow: nextStates graph');

// ── Validator ─────────────────────────────────────────────────────────────────
$val = new OfferValidator();
$r = $val->validateOffer($validOffer, '2026-07-21');
ok($r['success'] === true, 'Validator: valid offer passes');
ok($r['data']['salary'] === 1800000.0, 'Validator: salary parsed to float');
ok($r['data']['currency'] === 'INR', 'Validator: currency normalized');
ok($r['data']['job_id'] === 3 && $r['data']['interview_id'] === 50, 'Validator: optional FKs carried');

ok(($val->validateOffer([], '2026-07-21')['errors']['candidate_id'] ?? false), 'Validator: missing candidate fails');
ok(($val->validateOffer(['candidate_id'=>9], '2026-07-21')['errors']['job_title'] ?? false), 'Validator: missing job_title fails');
ok(($val->validateOffer(['candidate_id'=>9,'job_title'=>'X'], '2026-07-21')['errors']['salary'] ?? false), 'Validator: missing salary fails');
ok(($val->validateOffer(array_merge($validOffer, ['salary'=>'-5']), '2026-07-21')['errors']['salary'] ?? false), 'Validator: negative salary fails');
ok(($val->validateOffer(array_merge($validOffer, ['employment_type'=>'bogus']), '2026-07-21')['errors']['employment_type'] ?? false), 'Validator: invalid employment type fails');
ok(($val->validateOffer(array_merge($validOffer, ['currency'=>'rupee']), '2026-07-21')['errors']['currency'] ?? false), 'Validator: invalid currency fails');
ok(($val->validateOffer(array_merge($validOffer, ['expiry_date'=>'2000-01-01']), '2026-07-21')['errors']['expiry_date'] ?? false), 'Validator: past expiry fails');
ok(($val->validateOffer(array_merge($validOffer, ['joining_date'=>'not-a-date']), '2026-07-21')['errors']['joining_date'] ?? false), 'Validator: invalid joining date fails');
$rn = $val->validateOffer(array_merge($validOffer, ['job_id'=>'', 'interview_id'=>'']), '2026-07-21');
ok($rn['data']['job_id'] === null && $rn['data']['interview_id'] === null, 'Validator: blank optional FKs → null');

// ── Entity ─────────────────────────────────────────────────────────────────────
$o = Offer::fromRow(['id'=>1,'candidate_id'=>9,'job_id'=>3,'interview_id'=>null,'job_title'=>'Dev',
    'salary'=>'1800000.50','currency'=>'INR','status'=>'draft']);
ok($o->id === 1 && $o->candidateId === 9 && $o->jobId === 3, 'Entity: ids hydrated');
ok($o->interviewId === null, 'Entity: null FK preserved');
ok($o->salary === 1800000.5, 'Entity: salary cast to float');

// ── Repository ──────────────────────────────────────────────────────────────────
$fake = $makeOfferFake();
$repo = new OfferRepository($fake);
$data = $val->validateOffer($validOffer, '2026-07-21')['data'] + ['status'=>'draft'];
$id = $repo->create($data, 2);
ok(is_int($id) && $id > 0, 'Repository: create returns id');
ok($repo->findById($id)['job_title'] === 'Backend Engineer', 'Repository: findById round-trips');
ok($repo->updateStatus($id, 'sent') && $repo->findById($id)['status'] === 'sent', 'Repository: updateStatus');
$repo->addHistory($id, 'draft', 'sent', 2, 'Rao', 'Sent to candidate');
ok(count($repo->history($id)) === 1, 'Repository: history append + read');
$fake->failCreate = true;
ok($repo->create($data, 2) === false, 'Repository: create failure returns false');

// ── Service.createOffer ─────────────────────────────────────────────────────────
$fake = $makeOfferFake();
$svc = $makeOfferSvc($fake);
$r = $svc->createOffer($validOffer, 2, 'Rao', '2026-07-21');
ok($r['ok'] === true && $r['id'] > 0, 'createOffer: ok with id');
ok($fake->offers[$r['id']]['status'] === 'draft', 'createOffer: starts in draft');
ok(count($fake->history) === 1 && $fake->history[0]['to_status'] === 'draft', 'createOffer: history seeded');
ok($ofx['audit'][0][0] === 'offer_created', 'createOffer: audited');
ok(!empty($svc->createOffer(['candidate_id'=>9], 2, 'Rao', '2026-07-21')['errors']), 'createOffer: invalid input → errors');

// ── Service.updateOffer + edit lock ──────────────────────────────────────────────
$fake = $makeOfferFake();
$svc = $makeOfferSvc($fake);
$id = $svc->createOffer($validOffer, 2, 'Rao', '2026-07-21')['id'];
$u = $svc->updateOffer($id, array_merge($validOffer, ['salary'=>'2000000']), 2, 'Rao', '2026-07-21');
ok($u['ok'] === true, 'updateOffer: editable draft updates');
ok((float)$fake->offers[$id]['salary'] === 2000000.0, 'updateOffer: change persisted');
$fake->offers[$id]['status'] = 'sent';   // lock it
ok(($svc->updateOffer($id, $validOffer, 2, 'Rao', '2026-07-21')['error'] ?? '') === 'locked', 'updateOffer: locked once sent');
ok(($svc->updateOffer(9999, $validOffer, 2, 'Rao', '2026-07-21')['error'] ?? '') === 'not_found', 'updateOffer: not found');
ok(($svc->updateOffer(0, [])['error'] ?? '') === 'bad_id', 'updateOffer: bad id');

// ── Service.transition ───────────────────────────────────────────────────────────
$fake = $makeOfferFake();
$svc = $makeOfferSvc($fake);
$id = $svc->createOffer($validOffer, 2, 'Rao', '2026-07-21')['id'];
$t1 = $svc->transition($id, 'pending_approval', 2, 'Rao');
ok($t1['ok'] === true && $fake->offers[$id]['status'] === 'pending_approval', 'transition: draft → pending_approval');
ok(end($fake->history)['to_status'] === 'pending_approval', 'transition: history recorded');
ok(($svc->transition($id, 'accepted', 2, 'Rao')['error'] ?? '') === 'bad_transition', 'transition: illegal transition rejected');
ok(($svc->transition($id, 'nonsense', 2, 'Rao')['error'] ?? '') === 'bad_status', 'transition: unknown status rejected');
ok(($svc->transition(9999, 'approved', 2, 'Rao')['error'] ?? '') === 'not_found', 'transition: not found');
$svc->transition($id, 'approved', 2, 'Rao');
$svc->transition($id, 'sent', 2, 'Rao');
$svc->transition($id, 'accepted', 2, 'Rao');
ok($fake->offers[$id]['status'] === 'accepted', 'transition: full lifecycle to accepted');
ok(($svc->transition($id, 'cancelled', 2, 'Rao')['error'] ?? '') === 'bad_transition', 'transition: accepted is locked (terminal)');
ok(count($svc->historyFor($id)) === 5, 'transition: full history retained (immutable)');

// ── Read facades ─────────────────────────────────────────────────────────────────
ok($svc->find($id)['status'] === 'accepted', 'facade: find');
ok(count($svc->forCandidate(9)) >= 1, 'facade: forCandidate');

// ═════════════════════════════════════════════════════════════════════════════
//  Phase 2 — Offer Management Hub: RBAC scoping, listing, search/sort, delete
// ═════════════════════════════════════════════════════════════════════════════

// ── RBAC scoping (pure) ───────────────────────────────────────────────────────
ok(OfferService::scopeUserId(['id'=>7,'role'=>'hr']) === null, 'RBAC: hr has full visibility');
ok(OfferService::scopeUserId(['id'=>7,'role'=>'admin']) === null, 'RBAC: admin has full visibility');
ok(OfferService::scopeUserId(['id'=>7,'role'=>'super_admin']) === null, 'RBAC: super_admin has full visibility');
ok(OfferService::scopeUserId(['id'=>7,'role'=>'recruiter']) === 7, 'RBAC: recruiter is scoped to own id');

$fake = $makeOfferFake();
$svc  = $makeOfferSvc($fake);
$mine   = ['id'=>7,'created_by'=>7,'recruiter_id'=>null,'status'=>'draft'];
$theirs = ['id'=>8,'created_by'=>9,'recruiter_id'=>9,'status'=>'draft'];
$assigned = ['id'=>9,'created_by'=>9,'recruiter_id'=>7,'status'=>'draft'];
ok($svc->canAccess($theirs, ['id'=>7,'role'=>'hr']) === true, 'canAccess: hr reaches any offer');
ok($svc->canAccess($mine, ['id'=>7,'role'=>'recruiter']) === true, 'canAccess: creator reaches own offer');
ok($svc->canAccess($assigned, ['id'=>7,'role'=>'recruiter']) === true, 'canAccess: assigned recruiter reaches offer');
ok($svc->canAccess($theirs, ['id'=>7,'role'=>'recruiter']) === false, "canAccess: recruiter blocked from another's offer");
ok($svc->canAccess($mine, ['id'=>0,'role'=>'recruiter']) === false, 'canAccess: anonymous blocked');

// ── Hub listing: filter, search, sort whitelist, scoping ──────────────────────
$fake = $makeOfferFake();
$repo = new OfferRepository($fake);
$fake->offers = [
    1 => ['id'=>1,'candidate_id'=>9,'status'=>'draft','salary'=>100.0],
    2 => ['id'=>2,'candidate_id'=>9,'status'=>'draft','salary'=>200.0],
    3 => ['id'=>3,'candidate_id'=>8,'status'=>'sent','salary'=>300.0],
    4 => ['id'=>4,'candidate_id'=>8,'status'=>'accepted','salary'=>400.0],
];
ok(count($repo->listAll()) === 4, 'listAll: unfiltered returns every offer');
ok(count($repo->listAll('draft')) === 2, 'listAll: status filter applied');
ok(str_contains($fake->lastSql(), 'o.status=?'), 'listAll: status filter is parameterized');

$repo->listAll(null, 'sharma');
ok(str_contains($fake->lastSql(), 'ILIKE ?'), 'listAll: search uses parameterized ILIKE');
ok(count(end($fake->calls)['params']) === 4, 'listAll: search binds all four searchable columns');
ok(!str_contains($fake->lastSql(), 'sharma'), 'listAll: search term never interpolated into SQL');

$repo->listAll(null, '', 'salary_desc');
ok(str_contains($fake->lastSql(), 'ORDER BY o.salary DESC'), 'listAll: whitelisted sort applied');
$repo->listAll(null, '', 'DROP TABLE offers');
ok(str_contains($fake->lastSql(), 'ORDER BY o.created_at DESC'), 'listAll: unknown sort falls back to newest (no injection)');

$repo->listAll(null, '', 'newest', 7);
ok(str_contains($fake->lastSql(), 'o.created_by=? OR o.recruiter_id=?'), 'listAll: recruiter scope clause applied');
$repo->listAll(null, '', 'newest', null);
ok(!str_contains($fake->lastSql(), 'o.created_by=?'), 'listAll: no scope clause for full-visibility roles');

// ── Status counts (one grouped query) ────────────────────────────────────────
$sc = $repo->statusCounts();
ok(str_contains($fake->lastSql(), 'GROUP BY o.status'), 'statusCounts: single grouped query');
ok($sc['all'] === 4, 'statusCounts: all totals every status');
ok($sc['draft'] === 2 && $sc['sent'] === 1 && $sc['accepted'] === 1, 'statusCounts: per-status tallies');
ok($sc['cancelled'] === 0, 'statusCounts: unused statuses default to zero');
ok(array_key_exists('pending_approval', $sc), 'statusCounts: every workflow status present in shape');

// ── Expiring KPI ──────────────────────────────────────────────────────────────
$fake->expiring = 3;
ok($repo->expiringSoon(7) === 3, 'expiringSoon: returns count');
ok(str_contains($fake->lastSql(), "NOT IN ('accepted','declined','cancelled','expired')"), 'expiringSoon: excludes closed offers');

// ── Batched history (no N+1) ──────────────────────────────────────────────────
$fake->history = [
    ['id'=>1,'offer_id'=>1,'from_status'=>null,'to_status'=>'draft','actor_name'=>'Rao','notes'=>null,'created_at'=>'x'],
    ['id'=>2,'offer_id'=>1,'from_status'=>'draft','to_status'=>'sent','actor_name'=>'Rao','notes'=>null,'created_at'=>'y'],
    ['id'=>3,'offer_id'=>3,'from_status'=>null,'to_status'=>'draft','actor_name'=>'Rao','notes'=>null,'created_at'=>'z'],
];
$before = count($fake->calls);
$grouped = $repo->historyForMany([1, 3]);
ok(count($fake->calls) - $before === 1, 'historyForMany: ONE query for many offers (no N+1)');
ok(count($grouped[1]) === 2 && count($grouped[3]) === 1, 'historyForMany: rows grouped by offer id');
ok($repo->historyForMany([]) === [], 'historyForMany: empty input short-circuits');

// ── Delete draft offer ────────────────────────────────────────────────────────
$fake = $makeOfferFake();
$svc  = $makeOfferSvc($fake);
$hr        = ['id'=>1,'role'=>'hr'];
$recruiter = ['id'=>7,'role'=>'recruiter'];
$fake->offers = [
    10 => ['id'=>10,'candidate_id'=>9,'status'=>'draft','created_by'=>7,'recruiter_id'=>7],
    11 => ['id'=>11,'candidate_id'=>9,'status'=>'sent','created_by'=>7,'recruiter_id'=>7],
    12 => ['id'=>12,'candidate_id'=>9,'status'=>'draft','created_by'=>9,'recruiter_id'=>9],
];
ok(($svc->deleteOffer(0)['error'] ?? '') === 'bad_id', 'deleteOffer: rejects bad id');
ok(($svc->deleteOffer(999, $hr)['error'] ?? '') === 'not_found', 'deleteOffer: not found');
ok(($svc->deleteOffer(12, $recruiter)['error'] ?? '') === 'forbidden', "deleteOffer: recruiter blocked from another's draft");
ok(($svc->deleteOffer(11, $recruiter)['error'] ?? '') === 'not_draft', 'deleteOffer: non-draft cannot be deleted');
ok(isset($fake->offers[11]), 'deleteOffer: blocked delete leaves the offer intact');
$d = $svc->deleteOffer(10, $recruiter);
ok($d['ok'] === true && !isset($fake->offers[10]), 'deleteOffer: own draft deleted');
ok(in_array('offer_deleted', array_column($ofx['audit'], 0), true), 'deleteOffer: audited');

// ── updateOffer ownership guard ───────────────────────────────────────────────
$fake = $makeOfferFake();
$svc  = $makeOfferSvc($fake);
$fake->offers = [20 => ['id'=>20,'candidate_id'=>9,'status'=>'draft','created_by'=>9,'recruiter_id'=>9]];
ok(($svc->updateOffer(20, $validOffer, 7, 'Rao', '2026-07-21', ['id'=>7,'role'=>'recruiter'])['error'] ?? '') === 'forbidden',
   "updateOffer: recruiter blocked from another's offer");
ok($svc->updateOffer(20, $validOffer, 1, 'HR', '2026-07-21', ['id'=>1,'role'=>'hr'])['ok'] === true,
   'updateOffer: hr may edit any editable offer');
ok($svc->updateOffer(20, $validOffer, 1, 'HR', '2026-07-21')['ok'] === true,
   'updateOffer: omitting actor keeps Phase 1 behaviour (backward compatible)');

// ═════════════════════════════════════════════════════════════════════════════
//  Phase 3 — Offer Approval Workflow
// ═════════════════════════════════════════════════════════════════════════════

// ── Workflow rules mandated by the Phase 3 spec ──────────────────────────────
ok(OfferWorkflow::isStatus('changes_requested'), 'Phase3: changes_requested is a workflow state');
ok(!OfferWorkflow::isEditable('pending_approval'), 'Phase3: pending approval is locked for editing');
ok(!OfferWorkflow::isEditable('approved'), 'Phase3: approved offer is read-only');
ok(!OfferWorkflow::isEditable('rejected'), 'Phase3: rejected offer is read-only');
ok(OfferWorkflow::isEditable('changes_requested'), 'Phase3: changes requested is editable');
ok(OfferWorkflow::canTransition('changes_requested', 'pending_approval'), 'Phase3: revised offer can be resubmitted');
ok(OfferWorkflow::canTransition('pending_approval', 'draft'), 'Phase3: pending can be withdrawn to draft');
ok(!OfferWorkflow::canTransition('rejected', 'draft'), 'Phase3: rejected cannot return to draft');

// ── Approval chain helpers ───────────────────────────────────────────────────
ok(OfferWorkflow::stageKeys() === ['hiring_manager','hr_manager','final_approval'], 'Chain: ordered stage keys');
ok(OfferWorkflow::nextStage([]) === 'hiring_manager', 'Chain: first stage when nothing approved');
ok(OfferWorkflow::nextStage(['hiring_manager']) === 'hr_manager', 'Chain: advances in order');
ok(OfferWorkflow::nextStage(['hiring_manager','hr_manager','final_approval']) === null, 'Chain: null when complete');
ok(OfferWorkflow::chainComplete(['hiring_manager','hr_manager','final_approval']) === true, 'Chain: chainComplete');
ok(OfferWorkflow::roleCanActOnStage('hr', 'hiring_manager'), 'Chain: hr may act on hiring_manager stage');
ok(!OfferWorkflow::roleCanActOnStage('hr', 'final_approval'), 'Chain: hr may not give final approval');
ok(!OfferWorkflow::roleCanActOnStage('recruiter', 'hiring_manager'), 'Chain: recruiter can never approve');
ok(OfferWorkflow::roleCanActOnStage('admin', 'final_approval'), 'Chain: admin may give final approval');

// Fixtures -------------------------------------------------------------------
$mkOffer = function ($fake, string $status = 'draft', array $over = []) {
    $fake->offers[30] = array_merge([
        'id'=>30,'candidate_id'=>9,'status'=>$status,'created_by'=>7,'recruiter_id'=>7,'expiry_date'=>null,'approval_cycle'=>1,
    ], $over);
    return 30;
};
$rec   = ['id'=>7,'name'=>'Rao','role'=>'recruiter','ip'=>'10.0.0.1'];
$other = ['id'=>8,'name'=>'Iyer','role'=>'recruiter','ip'=>'10.0.0.2'];
$hrU   = ['id'=>2,'name'=>'Nadia','role'=>'hr','ip'=>'10.0.0.3'];
$adm   = ['id'=>3,'name'=>'Root','role'=>'admin','ip'=>'10.0.0.4'];

// ── Submit for approval ──────────────────────────────────────────────────────
$fake = $makeOfferFake(); $svc = $makeOfferSvc($fake); $id = $mkOffer($fake, 'draft');
ok(($svc->submitForApproval(0, $rec)['error'] ?? '') === 'bad_id', 'submit: rejects bad id');
ok(($svc->submitForApproval(999, $rec)['error'] ?? '') === 'not_found', 'submit: not found');
ok(($svc->submitForApproval($id, $other)['error'] ?? '') === 'forbidden', "submit: blocked on another recruiter's offer");
$r = $svc->submitForApproval($id, $rec, 'Please review');
ok($r['ok'] === true && $fake->offers[$id]['status'] === 'pending_approval', 'submit: draft → pending approval');
ok($r['cycle'] === 1, 'submit: first review cycle');
ok(end($fake->history)['actor_role'] === 'recruiter' && end($fake->history)['ip_address'] === '10.0.0.1', 'submit: history records role + IP');
ok(str_contains((string)end($fake->history)['notes'], 'Please review'), 'submit: comment stored in history');
ok(($svc->submitForApproval($id, $rec)['error'] ?? '') === 'bad_state', 'submit: cannot resubmit while pending');

// expiry guard
$fake2 = $makeOfferFake(); $svc2 = $makeOfferSvc($fake2);
$mkOffer($fake2, 'draft', ['expiry_date' => '2000-01-01']);
ok(($svc2->submitForApproval(30, $rec)['error'] ?? '') === 'expired', 'submit: expired offer blocked');

// ── Withdraw ─────────────────────────────────────────────────────────────────
$w = $svc->withdraw($id, $rec, 'Fixing salary');
ok($w['ok'] === true && $fake->offers[$id]['status'] === 'draft', 'withdraw: pending → draft');
ok(($svc->withdraw($id, $rec)['error'] ?? '') === 'bad_state', 'withdraw: only valid while pending');
$svc->submitForApproval($id, $rec);

// ── Approver permission guards ───────────────────────────────────────────────
ok(($svc->approve($id, $rec)['error'] ?? '') === 'forbidden', 'approve: recruiter cannot approve');
ok(($svc->approve($id, ['id'=>7,'name'=>'Rao','role'=>'hr'])['error'] ?? '') === 'self_approval', 'approve: cannot approve your own offer');
ok(($svc->approve($id, ['id'=>7,'name'=>'Rao','role'=>'super_admin'])['ok'] ?? false) === true, 'approve: super_admin may self-approve');

// ── Full chain walk ──────────────────────────────────────────────────────────
$fake = $makeOfferFake(); $svc = $makeOfferSvc($fake); $id = $mkOffer($fake, 'draft');
$svc->submitForApproval($id, $rec);
$a1 = $svc->approve($id, $hrU, 'Role confirmed');
ok($a1['ok'] === true && $a1['stage'] === 'hiring_manager' && $a1['complete'] === false, 'chain: stage 1 approved, not complete');
ok($fake->offers[$id]['status'] === 'pending_approval', 'chain: stays pending mid-chain');
$a2 = $svc->approve($id, $hrU);
ok($a2['stage'] === 'hr_manager', 'chain: advances to stage 2');
ok(($svc->approve($id, $hrU)['error'] ?? '') === 'forbidden', 'chain: hr cannot give final approval');
$a3 = $svc->approve($id, $adm, 'Budget ok');
ok($a3['complete'] === true && $fake->offers[$id]['status'] === 'approved', 'chain: final stage approves the offer');
ok(count($fake->approvals) === 3, 'chain: one immutable row per stage decision');
ok($fake->approvals[0]['ip_address'] === '10.0.0.3' && $fake->approvals[0]['approver_role'] === 'hr', 'chain: decision records role + IP');

// ── Prevented transitions ────────────────────────────────────────────────────
ok(($svc->approve($id, $adm)['error'] ?? '') === 'bad_state', 'prevent: double approval');
ok(($svc->reject($id, $adm)['error'] ?? '') === 'bad_state', 'prevent: rejection after approval');
ok(($svc->deleteOffer($id, $adm)['error'] ?? '') === 'not_draft', 'prevent: deleting an approved offer');
ok(($svc->updateOffer($id, $validOffer, 3, 'Root', '2026-07-23', $adm)['error'] ?? '') === 'locked', 'prevent: editing an approved offer');

$fake = $makeOfferFake(); $svc = $makeOfferSvc($fake); $id = $mkOffer($fake, 'draft');
ok(($svc->approve($id, $adm)['error'] ?? '') === 'bad_state', 'prevent: approving a draft');
$svc->submitForApproval($id, $rec);
ok(($svc->deleteOffer($id, $rec)['error'] ?? '') === 'not_draft', 'prevent: deleting a pending offer');
ok(($svc->updateOffer($id, $validOffer, 7, 'Rao', '2026-07-23', $rec)['error'] ?? '') === 'locked', 'prevent: editing a pending offer');
$fake->offers[$id]['expiry_date'] = '2000-01-01';
ok(($svc->approve($id, $hrU)['error'] ?? '') === 'expired', 'prevent: approval after expiry');

// ── Rejection is terminal ────────────────────────────────────────────────────
$fake = $makeOfferFake(); $svc = $makeOfferSvc($fake); $id = $mkOffer($fake, 'draft');
$svc->submitForApproval($id, $rec);
$rj = $svc->reject($id, $hrU, 'Band too high');
ok($rj['ok'] === true && $fake->offers[$id]['status'] === 'rejected', 'reject: offer rejected');
ok(($svc->approve($id, $adm)['error'] ?? '') === 'bad_state', 'reject: no approval after rejection');
ok(($svc->submitForApproval($id, $rec)['error'] ?? '') === 'bad_state', 'reject: cannot resubmit a rejected offer');

// ── Changes requested → revise → resubmit (new cycle) ────────────────────────
$fake = $makeOfferFake(); $svc = $makeOfferSvc($fake); $id = $mkOffer($fake, 'draft');
$svc->submitForApproval($id, $rec);
$svc->approve($id, $hrU);                       // stage 1 approved in cycle 1
$cr = $svc->requestChanges($id, $hrU, 'Add relocation detail');
ok($cr['ok'] === true && $fake->offers[$id]['status'] === 'changes_requested', 'requestChanges: returned to recruiter');
ok($svc->updateOffer($id, $validOffer, 7, 'Rao', '2026-07-23', $rec)['ok'] === true, 'requestChanges: recruiter may revise');
$re = $svc->submitForApproval($id, $rec);
ok($re['ok'] === true && $re['cycle'] === 2, 'requestChanges: resubmission opens a new review cycle');
$st = $svc->approvalState($fake->offers[$id], $svc->approvalsFor($id));
ok($st['approved_stages'] === [], 'new cycle: previous approvals do not carry over');
ok($st['next_stage'] === 'hiring_manager', 'new cycle: chain restarts at stage one');

// ── Stepper state ────────────────────────────────────────────────────────────
$fake = $makeOfferFake(); $svc = $makeOfferSvc($fake); $id = $mkOffer($fake, 'draft');
$draftState = $svc->approvalState($fake->offers[$id], []);
ok(count($draftState['nodes']) === 4, 'stepper: recruiter node + three chain stages');
ok($draftState['nodes'][0]['state'] === 'current', 'stepper: draft highlights the recruiter node');
ok($draftState['nodes'][1]['state'] === 'pending', 'stepper: unstarted stages are pending');
$svc->submitForApproval($id, $rec);
$pending = $svc->approvalState($fake->offers[$id], $svc->approvalsFor($id));
ok($pending['nodes'][0]['state'] === 'complete', 'stepper: submission completes the recruiter node');
ok($pending['nodes'][1]['state'] === 'current', 'stepper: first approval stage becomes current');
$svc->approve($id, $hrU, 'Looks good');
$mid = $svc->approvalState($fake->offers[$id], $svc->approvalsFor($id));
ok($mid['nodes'][1]['state'] === 'complete' && $mid['nodes'][1]['approver'] === 'Nadia', 'stepper: approved node shows approver');
ok($mid['nodes'][1]['comment'] === 'Looks good', 'stepper: node carries the comment');
ok($mid['nodes'][2]['state'] === 'current', 'stepper: next stage becomes current');
$svc->reject($id, $hrU, 'No');
$rej = $svc->approvalState($fake->offers[$id], $svc->approvalsFor($id));
ok($rej['nodes'][2]['state'] === 'rejected', 'stepper: rejected node rendered as rejected');

// ── canDecide gate (drives the UI action panel) ──────────────────────────────
$fake = $makeOfferFake(); $svc = $makeOfferSvc($fake); $id = $mkOffer($fake, 'draft');
ok($svc->canDecide($fake->offers[$id], [], $hrU) === false, 'canDecide: false for a draft');
$svc->submitForApproval($id, $rec);
ok($svc->canDecide($fake->offers[$id], $svc->approvalsFor($id), $hrU) === true, 'canDecide: true for eligible approver');
ok($svc->canDecide($fake->offers[$id], $svc->approvalsFor($id), $rec) === false, 'canDecide: false for recruiter');
ok($svc->canDecide($fake->offers[$id], $svc->approvalsFor($id), ['id'=>7,'role'=>'hr']) === false, 'canDecide: false for own offer');

// ── History integrity + batched approval reads ───────────────────────────────
$fake = $makeOfferFake(); $svc = $makeOfferSvc($fake); $id = $mkOffer($fake, 'draft');
$svc->submitForApproval($id, $rec);
$before = count($fake->history);
$svc->approve($id, $hrU);
ok(count($fake->history) > $before, 'history: every decision appends an entry');
ok(count(array_filter($fake->history, fn($h) => $h['to_status'] === null)) === 0, 'history: entries always record the new status');
$repo = new OfferRepository($fake);
$callsBefore = count($fake->calls);
$grouped = $repo->approvalsForMany([$id, 999]);
ok(count($fake->calls) - $callsBefore === 1, 'approvalsForMany: ONE query for many offers (no N+1)');
ok(count($grouped[$id]) === 1, 'approvalsForMany: grouped by offer id');
