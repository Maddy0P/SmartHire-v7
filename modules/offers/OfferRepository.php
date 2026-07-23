<?php
// ═════════════════════════════════════════════════════════════════════════════
//  OfferRepository (Module 10 Phase 1). PostgreSQL access for the offers tables
//  ONLY — no validation, no business logic, no HTML. Every query is parameterized
//  and column-explicit (handbook Ch9: prepared statements, no SELECT *).
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Offer;

final class OfferRepository
{
    public function __construct(private DbAdapter $db) {}

    private const COLS =
        'id, candidate_id, job_id, recruiter_id, interview_id, job_title, department, location,
         employment_type, salary, currency, joining_date, expiry_date, benefits, notes, status,
         approval_cycle, hired_at, created_by, created_at, updated_at';

    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT ' . self::COLS . ' FROM offers WHERE id=?', 'i', $id);
    }

    public function listByCandidate(int $candidateId): array
    {
        return $this->db->fetchAll(
            'SELECT ' . self::COLS . ' FROM offers WHERE candidate_id=? ORDER BY created_at DESC, id DESC',
            'i', $candidateId);
    }

    /** Create an offer; returns the new id (int) or false on failure. */
    public function create(array $d, ?int $createdBy = null): int|false
    {
        $res = $this->db->execute(
            'INSERT INTO offers
                (candidate_id, job_id, recruiter_id, interview_id, job_title, department, location,
                 employment_type, salary, currency, joining_date, expiry_date, benefits, notes, status, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            'iiiissssdssssssi',
            $d['candidate_id'], $d['job_id'], $d['recruiter_id'], $d['interview_id'],
            $d['job_title'], $d['department'], $d['location'], $d['employment_type'],
            $d['salary'], $d['currency'], $d['joining_date'], $d['expiry_date'],
            $d['benefits'], $d['notes'], $d['status'] ?? 'draft', $createdBy);
        return is_int($res) ? $res : ($res ? 0 : false);
    }

    /** Update editable offer fields (never candidate_id or status). */
    public function update(int $id, array $d): bool
    {
        return (bool)$this->db->execute(
            'UPDATE offers SET
                job_id=?, recruiter_id=?, interview_id=?, job_title=?, department=?, location=?,
                employment_type=?, salary=?, currency=?, joining_date=?, expiry_date=?, benefits=?, notes=?,
                updated_at=NOW()
             WHERE id=?',
            'iiisssssdssssi',
            $d['job_id'], $d['recruiter_id'], $d['interview_id'], $d['job_title'], $d['department'],
            $d['location'], $d['employment_type'], $d['salary'], $d['currency'], $d['joining_date'],
            $d['expiry_date'], $d['benefits'], $d['notes'], $id);
    }

    /** Bump the offer's review cycle (a new round of approvals). */
    public function updateApprovalCycle(int $id, int $cycle): bool
    {
        return (bool)$this->db->execute(
            'UPDATE offers SET approval_cycle=?, updated_at=NOW() WHERE id=?', 'ii', $cycle, $id);
    }

    public function updateStatus(int $id, string $status): bool
    {
        return (bool)$this->db->execute(
            'UPDATE offers SET status=?, updated_at=NOW() WHERE id=?', 'si', $status, $id);
    }

    // ── Immutable history (append-only) ──────────────────────────────────────

    public function addHistory(int $offerId, ?string $from, string $to, ?int $actorId, ?string $actorName, ?string $notes, ?string $actorRole = null, ?string $ip = null): int|false
    {
        $res = $this->db->execute(
            'INSERT INTO offer_history (offer_id, from_status, to_status, actor_id, actor_name, notes, actor_role, ip_address)
             VALUES (?,?,?,?,?,?,?,?)',
            'ississss', $offerId, $from, $to, $actorId, $actorName, $notes, $actorRole, $ip);
        return is_int($res) ? $res : ($res ? 0 : false);
    }

    public function history(int $offerId): array
    {
        return $this->db->fetchAll(
            'SELECT id, offer_id, from_status, to_status, actor_id, actor_name, notes, actor_role, ip_address, created_at
             FROM offer_history WHERE offer_id=? ORDER BY created_at, id', 'i', $offerId);
    }

    // ── Phase 3: approval-chain decisions ────────────────────────────────────

    private const APPROVAL_COLS =
        'id, offer_id, cycle, stage, approver_id, approver_name, approver_role, decision, comments, ip_address, created_at';

    /** Record one stage decision. Append-only — decisions are never updated. */
    public function addApproval(int $offerId, int $cycle, string $stage, string $decision, ?int $approverId, ?string $approverName, ?string $approverRole, ?string $comments, ?string $ip): int|false
    {
        $res = $this->db->execute(
            'INSERT INTO offer_approvals (offer_id, cycle, stage, decision, approver_id, approver_name, approver_role, comments, ip_address)
             VALUES (?,?,?,?,?,?,?,?,?)',
            'iississss', $offerId, $cycle, $stage, $decision, $approverId, $approverName, $approverRole, $comments, $ip);
        return is_int($res) ? $res : ($res ? 0 : false);
    }

    /** All decisions for one offer, oldest first (immutable audit order). */
    public function approvals(int $offerId): array
    {
        return $this->db->fetchAll(
            'SELECT ' . self::APPROVAL_COLS . ' FROM offer_approvals WHERE offer_id=? ORDER BY created_at, id',
            'i', $offerId);
    }

    /**
     * Decisions for many offers in ONE query, grouped by offer id — keeps the
     * hub's approval column off the N+1 path (handbook Performance section).
     * @return array<int, array<int, array>>
     */
    public function approvalsForMany(array $offerIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $offerIds)));
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->fetchAll(
            'SELECT ' . self::APPROVAL_COLS . ' FROM offer_approvals WHERE offer_id IN (' . $ph . ') ORDER BY created_at, id',
            str_repeat('i', count($ids)), ...$ids);
        $out = [];
        foreach ($rows as $r) $out[(int)$r['offer_id']][] = $r;
        return $out;
    }

    /**
     * History for many offers in ONE query, grouped by offer id. Used by the hub
     * preview drawer so rendering N rows never costs N queries (handbook Part 7).
     * @return array<int, array<int, array>>
     */
    public function historyForMany(array $offerIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $offerIds)));
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->fetchAll(
            'SELECT id, offer_id, from_status, to_status, actor_id, actor_name, notes, actor_role, ip_address, created_at
             FROM offer_history WHERE offer_id IN (' . $ph . ') ORDER BY created_at, id',
            str_repeat('i', count($ids)), ...$ids);
        $out = [];
        foreach ($rows as $r) $out[(int)$r['offer_id']][] = $r;
        return $out;
    }

    // ── Phase 2: Offer Management Hub reads ──────────────────────────────────

    /** Hub list projection — offer columns plus candidate/job display fields. */
    private const LIST_COLS =
        'o.id, o.candidate_id, o.job_id, o.recruiter_id, o.interview_id, o.job_title, o.department,
         o.location, o.employment_type, o.salary, o.currency, o.joining_date, o.expiry_date,
         o.benefits, o.notes, o.status, o.approval_cycle, o.created_by, o.created_at, o.updated_at,
         c.name AS candidate_name, c.email AS candidate_email, j.title AS job_name';

    /** ORDER BY whitelist — user input never reaches the SQL string. */
    private const SORTS = [
        'newest'      => 'o.created_at DESC, o.id DESC',
        'oldest'      => 'o.created_at ASC, o.id ASC',
        'candidate'   => 'c.name ASC, o.id DESC',
        'salary_desc' => 'o.salary DESC NULLS LAST, o.id DESC',
        'salary_asc'  => 'o.salary ASC NULLS LAST, o.id DESC',
        'expiry'      => 'o.expiry_date ASC NULLS LAST, o.id DESC',
    ];

    /**
     * Offer list for the hub with optional status filter, free-text search and
     * sort. $scopeUserId restricts the result to offers a plain recruiter owns
     * (created_by or recruiter_id); pass null for full-visibility roles.
     */
    public function listAll(?string $status = null, string $q = '', string $sort = 'newest', ?int $scopeUserId = null): array
    {
        [$where, $types, $params] = $this->scopeClause($status, $q, $scopeUserId);
        $order = self::SORTS[$sort] ?? self::SORTS['newest'];
        $sql = 'SELECT ' . self::LIST_COLS . '
                FROM offers o
                LEFT JOIN candidates c ON c.id = o.candidate_id
                LEFT JOIN jobs j       ON j.id = o.job_id'
             . $where . ' ORDER BY ' . $order;
        return $this->db->fetchAll($sql, $types, ...$params);
    }

    /**
     * All status tallies in ONE grouped query (handbook 6A-005/6A-022 — never
     * one COUNT per chip). Unknown statuses still count toward 'all'.
     */
    public function statusCounts(?int $scopeUserId = null): array
    {
        $out = array_fill_keys(array_merge(['all'], OfferWorkflow::STATUSES), 0);
        [$where, $types, $params] = $this->scopeClause(null, '', $scopeUserId);
        $sql = 'SELECT o.status, COUNT(*) n FROM offers o' . $where . ' GROUP BY o.status';
        foreach ($this->db->fetchAll($sql, $types, ...$params) as $r) {
            $n = (int)$r['n'];
            $out['all'] += $n;
            if (array_key_exists((string)$r['status'], $out)) $out[(string)$r['status']] = $n;
        }
        return $out;
    }

    /** Count of live offers whose expiry falls inside the next $days days. */
    public function expiringSoon(int $days = 7, ?int $scopeUserId = null): int
    {
        $types = 'i'; $params = [$days];
        $sql = "SELECT COUNT(*) n FROM offers o
                WHERE o.expiry_date IS NOT NULL
                  AND o.expiry_date >= CURRENT_DATE
                  AND o.expiry_date <= CURRENT_DATE + (CAST(? AS INTEGER) * INTERVAL '1 day')
                  AND o.status NOT IN ('accepted','declined','cancelled','expired')";
        if ($scopeUserId !== null) {
            $sql .= ' AND (o.created_by=? OR o.recruiter_id=?)';
            $types .= 'ii'; $params[] = $scopeUserId; $params[] = $scopeUserId;
        }
        $r = $this->db->fetchOne($sql, $types, ...$params);
        return (int)($r['n'] ?? 0);
    }

    /** Hard-delete an offer (service restricts this to drafts). */
    public function delete(int $id): bool
    {
        return (bool)$this->db->execute('DELETE FROM offers WHERE id=?', 'i', $id);
    }

    /** Shared WHERE builder — every fragment parameterized. */
    private function scopeClause(?string $status, string $q, ?int $scopeUserId): array
    {
        $where = []; $types = ''; $params = [];

        if ($status !== null && $status !== '') {
            $where[] = 'o.status=?'; $types .= 's'; $params[] = $status;
        }
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(c.name ILIKE ? OR o.job_title ILIKE ? OR o.department ILIKE ? OR o.location ILIKE ?)';
            $types .= 'ssss';
            array_push($params, $like, $like, $like, $like);
        }
        if ($scopeUserId !== null) {
            $where[] = '(o.created_by=? OR o.recruiter_id=?)';
            $types .= 'ii'; $params[] = $scopeUserId; $params[] = $scopeUserId;
        }
        return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $types, $params];
    }
}
