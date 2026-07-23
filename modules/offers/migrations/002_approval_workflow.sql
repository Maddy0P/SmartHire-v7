-- ═════════════════════════════════════════════════════════════════════════════
--  Offers module — Migration 002 (Module 10 Phase 3: Offer Approval Workflow).
--  ADDITIVE ONLY: adds columns to two module-owned tables so approval actions can
--  record the actor's role and IP address, and so each approval-chain stage can
--  be tracked. No table is created or dropped, no column is altered or removed,
--  and NO interview / assessment / candidate / job table is touched.
--  Apply:  psql "$DATABASE_URL" -f modules/offers/migrations/002_approval_workflow.sql
-- ═════════════════════════════════════════════════════════════════════════════

BEGIN;

-- ── offers: the current review cycle (bumped on every (re)submission) ───────
ALTER TABLE offers ADD COLUMN IF NOT EXISTS approval_cycle INTEGER NOT NULL DEFAULT 1;

-- ── offer_history: who acted, in what role, from where ──────────────────────
-- (offer_id / from_status / to_status / actor_id / actor_name / notes / created_at
--  already exist from migration 001 — only the two new columns are added.)
ALTER TABLE offer_history ADD COLUMN IF NOT EXISTS actor_role VARCHAR(20);
ALTER TABLE offer_history ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45);

-- ── offer_approvals: one row per approval-chain stage decision ──────────────
ALTER TABLE offer_approvals ADD COLUMN IF NOT EXISTS stage         VARCHAR(30);
ALTER TABLE offer_approvals ADD COLUMN IF NOT EXISTS approver_name VARCHAR(120);
ALTER TABLE offer_approvals ADD COLUMN IF NOT EXISTS approver_role VARCHAR(20);
ALTER TABLE offer_approvals ADD COLUMN IF NOT EXISTS ip_address    VARCHAR(45);
ALTER TABLE offer_approvals ADD COLUMN IF NOT EXISTS cycle         INTEGER NOT NULL DEFAULT 1;

-- Stage decisions are read per offer and per cycle on every approval screen.
CREATE INDEX IF NOT EXISTS idx_offer_approvals_stage ON offer_approvals(offer_id, cycle, stage);

COMMIT;

-- ── Rollback ─────────────────────────────────────────────────────────────────
--   DROP INDEX IF EXISTS idx_offer_approvals_stage;
--   ALTER TABLE offer_approvals DROP COLUMN IF EXISTS cycle;
--   ALTER TABLE offer_approvals DROP COLUMN IF EXISTS ip_address;
--   ALTER TABLE offer_approvals DROP COLUMN IF EXISTS approver_role;
--   ALTER TABLE offer_approvals DROP COLUMN IF EXISTS approver_name;
--   ALTER TABLE offer_approvals DROP COLUMN IF EXISTS stage;
--   ALTER TABLE offer_history   DROP COLUMN IF EXISTS ip_address;
--   ALTER TABLE offer_history   DROP COLUMN IF EXISTS actor_role;
--   ALTER TABLE offers          DROP COLUMN IF EXISTS approval_cycle;
-- Phases 1–2 do not read these columns, so a code-only rollback stays safe.
