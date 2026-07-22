-- ═════════════════════════════════════════════════════════════════════════════
--  Interview module — Migration 003 (Module 9 Phase 3: scoring, timeline,
--  decision workflow, feedback). ADDITIVE ONLY: three new module-owned tables,
--  zero changes to existing tables (interviews / results / candidate_responses
--  are untouched), so scheduling, calendar, search, and the legacy per-question
--  scoring flow keep working unchanged.
--  Apply:  psql "$DATABASE_URL" -f modules/interview/migrations/003_scoring_timeline.sql
-- ═════════════════════════════════════════════════════════════════════════════

BEGIN;

-- ── Category scorecard (Part 1) + decision workflow (Part 3) ─────────────────
-- One editable scorecard per interview. Category scores are 0–10; overall is the
-- 0–10 average. Decision + finalization live here; once finalized, scores /
-- recommendation / decision are locked by the service.
CREATE TABLE IF NOT EXISTS interview_scorecards (
    id                    SERIAL PRIMARY KEY,
    interview_id          INTEGER NOT NULL,
    technical_knowledge   SMALLINT,
    communication         SMALLINT,
    problem_solving       SMALLINT,
    behaviour             SMALLINT,
    cultural_fit          SMALLINT,
    confidence            SMALLINT,
    experience_relevance  SMALLINT,
    overall_score         NUMERIC(4,1),
    recommendation        VARCHAR(20),          -- strong_hire|hire|hold|reject|second_round
    summary               TEXT,
    comments              TEXT,
    decision              VARCHAR(30) NOT NULL DEFAULT 'pending',
    decision_finalized    BOOLEAN     NOT NULL DEFAULT FALSE,
    scored_by             VARCHAR(120),
    created_at            TIMESTAMP   NOT NULL DEFAULT NOW(),
    updated_at            TIMESTAMP   NOT NULL DEFAULT NOW(),
    UNIQUE (interview_id)
);

-- ── Immutable timeline (Part 2) — append-only, never updated or deleted ──────
CREATE TABLE IF NOT EXISTS interview_timeline (
    id            SERIAL PRIMARY KEY,
    interview_id  INTEGER      NOT NULL,
    actor         VARCHAR(120),
    action        VARCHAR(50)  NOT NULL,
    notes         TEXT,
    created_at    TIMESTAMP    NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_iv_timeline_interview ON interview_timeline(interview_id, created_at, id);

-- ── Structured feedback (Part 4) — independent from scoring, one per interview ─
CREATE TABLE IF NOT EXISTS interview_feedback (
    id                   SERIAL PRIMARY KEY,
    interview_id         INTEGER NOT NULL,
    summary              TEXT,
    strengths            TEXT,
    weaknesses           TEXT,
    improvement_areas    TEXT,
    technical_notes      TEXT,
    behaviour_notes      TEXT,
    final_recommendation VARCHAR(20),
    created_by           VARCHAR(120),
    created_at           TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at           TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (interview_id)
);

COMMIT;

-- ── Rollback ─────────────────────────────────────────────────────────────────
--   DROP TABLE IF EXISTS interview_feedback;
--   DROP TABLE IF EXISTS interview_timeline;
--   DROP TABLE IF EXISTS interview_scorecards;
-- The legacy per-question scoring flow (candidate_responses) and the scheduling
-- write/read paths do not depend on these tables, so a code-only rollback is safe.
