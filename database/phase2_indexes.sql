-- ═════════════════════════════════════════════════════════════════════════════
--  SmartHire v7 — Phase 2 Performance Indexes
--  Run this against an EXISTING smarthire database that was set up before the
--  Phase 2 optimisation. All statements are idempotent (IF NOT EXISTS).
--
--  Usage:
--    psql "$DATABASE_URL" -f database/phase2_indexes.sql
-- ═════════════════════════════════════════════════════════════════════════════

BEGIN;

-- Notification bell query (runs on every page load)
CREATE INDEX IF NOT EXISTS idx_notif_user_read
    ON notifications(user_id, is_read);

CREATE INDEX IF NOT EXISTS idx_notif_candidate_read
    ON notifications(candidate_id, is_read);

-- Candidate portal test lookup
CREATE INDEX IF NOT EXISTS idx_ot_candidate
    ON online_tests(candidate_id);

-- Analytics compound filter on applications
CREATE INDEX IF NOT EXISTS idx_app_candidate_stage
    ON job_applications(candidate_id, stage);

-- Fast correct-answer count in test analytics
CREATE INDEX IF NOT EXISTS idx_ta_correct
    ON test_answers(submission_id) WHERE is_correct = 1;

-- Login attempt cleanup range scans
CREATE INDEX IF NOT EXISTS idx_la_attempted
    ON login_attempts(attempted_at);

-- Resume scan candidate lookups
CREATE INDEX IF NOT EXISTS idx_rs_candidate
    ON resume_scans(candidate_id);

COMMIT;

-- RC1: composite for the applications list hot path (job + stage filter, ranked).
-- Measured at 5k rows: 2x faster execution, 87x fewer index entries scanned
-- vs BitmapAnd of the single-column indexes.
CREATE INDEX IF NOT EXISTS idx_app_job_stage_score ON job_applications(job_id, stage, final_score DESC);
