-- ═════════════════════════════════════════════════════════════════════════════
--  SmartHire Assessment Platform — Migration 002  (Module 8C, candidate delivery)
--  Additive + idempotent. Adds server-authoritative timing + autosave-recovery
--  state to the EXISTING submission/answer tables. No new pipeline, no data loss.
--  Apply:  psql "$DATABASE_URL" -f modules/assessment/migrations/002_candidate_delivery.sql
-- ═════════════════════════════════════════════════════════════════════════════

BEGIN;

-- ── Server-authoritative timer + navigation recovery on the attempt ──────────
-- deadline_at is computed once from duration at first load and never trusts the
-- client again; nav_state persists the last question + review flags for resume.
ALTER TABLE test_submissions
  ADD COLUMN IF NOT EXISTS deadline_at   TIMESTAMP NULL,
  ADD COLUMN IF NOT EXISTS current_q     INTEGER   NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS nav_state     JSONB     NOT NULL DEFAULT '{}'::jsonb,
  ADD COLUMN IF NOT EXISTS reconnects    INTEGER   NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS last_seen_at  TIMESTAMP NULL;

COMMENT ON COLUMN test_submissions.deadline_at  IS 'Server-authoritative submit deadline, set once at start. Client timer is display-only.';
COMMENT ON COLUMN test_submissions.nav_state    IS 'Autosave recovery: {flags:[qid,…], current:idx}. Answers themselves live in test_answers.';
COMMENT ON COLUMN test_submissions.reconnects   IS 'Count of reload/reconnect recoveries (anti-cheat signal).';

-- ── Per-answer autosave bookkeeping (last-write-wins conflict resolution) ────
ALTER TABLE test_answers
  ADD COLUMN IF NOT EXISTS updated_at    TIMESTAMP NULL,
  ADD COLUMN IF NOT EXISTS review_flag   SMALLINT  NOT NULL DEFAULT 0;

COMMENT ON COLUMN test_answers.updated_at  IS 'Autosave timestamp; server compares to resolve out-of-order queued saves (last-write-wins).';

CREATE INDEX IF NOT EXISTS idx_ts_deadline ON test_submissions(deadline_at) WHERE status = 'in_progress';

COMMIT;

-- ── Rollback ─────────────────────────────────────────────────────────────────
--   ALTER TABLE test_submissions DROP COLUMN deadline_at, DROP COLUMN current_q,
--     DROP COLUMN nav_state, DROP COLUMN reconnects, DROP COLUMN last_seen_at;
--   ALTER TABLE test_answers DROP COLUMN updated_at, DROP COLUMN review_flag;
-- The candidate player degrades gracefully without these columns (falls back to
-- duration-from-started_at timing and no cross-refresh flag recovery), so 8C
-- ships safe even if the migration is applied late.
