-- ═════════════════════════════════════════════════════════════════════════════
--  Offers module — Migration 001 (Module 10 Phase 1: Offer Architecture).
--  ADDITIVE ONLY: four new module-owned tables. No existing table is altered —
--  candidates / jobs / users / interviews are referenced by foreign key but never
--  modified, so every prior module keeps working unchanged.
--  Apply:  psql "$DATABASE_URL" -f modules/offers/migrations/001_offers_core.sql
-- ═════════════════════════════════════════════════════════════════════════════

BEGIN;

-- ── Offers ───────────────────────────────────────────────────────────────────
-- FKs: candidate (required), job / recruiter / interview (optional, SET NULL so an
-- offer survives if a source record is removed). candidate cascade-deletes.
CREATE TABLE IF NOT EXISTS offers (
    id              SERIAL PRIMARY KEY,
    candidate_id    INTEGER NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
    job_id          INTEGER REFERENCES jobs(id)        ON DELETE SET NULL,
    recruiter_id    INTEGER REFERENCES users(id)       ON DELETE SET NULL,
    interview_id    INTEGER REFERENCES interviews(id)  ON DELETE SET NULL,
    job_title       VARCHAR(150),
    department      VARCHAR(100),
    location        VARCHAR(120),
    employment_type VARCHAR(30),
    salary          NUMERIC(12,2),
    currency        VARCHAR(3)  NOT NULL DEFAULT 'INR',
    joining_date    DATE,
    expiry_date     DATE,
    benefits        TEXT,
    notes           TEXT,
    status          VARCHAR(20) NOT NULL DEFAULT 'draft',
    hired_at        TIMESTAMP,
    created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMP   NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP   NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_offers_candidate ON offers(candidate_id);
CREATE INDEX IF NOT EXISTS idx_offers_status    ON offers(status);

-- ── Offer history — immutable state-transition log (append-only) ─────────────
CREATE TABLE IF NOT EXISTS offer_history (
    id          SERIAL PRIMARY KEY,
    offer_id    INTEGER     NOT NULL REFERENCES offers(id) ON DELETE CASCADE,
    from_status VARCHAR(20),
    to_status   VARCHAR(20) NOT NULL,
    actor_id    INTEGER,
    actor_name  VARCHAR(120),
    notes       TEXT,
    created_at  TIMESTAMP   NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_offer_history_offer ON offer_history(offer_id, created_at, id);

-- ── Offer documents — generated offer-letter PDFs, versioned ─────────────────
CREATE TABLE IF NOT EXISTS offer_documents (
    id           SERIAL PRIMARY KEY,
    offer_id     INTEGER     NOT NULL REFERENCES offers(id) ON DELETE CASCADE,
    version      INTEGER     NOT NULL DEFAULT 1,
    file_path    VARCHAR(255),
    file_name    VARCHAR(160),
    generated_by INTEGER,
    created_at   TIMESTAMP   NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_offer_docs_offer ON offer_documents(offer_id, version);

-- ── Offer approvals — approval-workflow decisions ───────────────────────────
CREATE TABLE IF NOT EXISTS offer_approvals (
    id          SERIAL PRIMARY KEY,
    offer_id    INTEGER     NOT NULL REFERENCES offers(id) ON DELETE CASCADE,
    approver_id INTEGER,
    decision    VARCHAR(20) NOT NULL,   -- approved | rejected | returned
    comments    TEXT,
    created_at  TIMESTAMP   NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_offer_approvals_offer ON offer_approvals(offer_id, created_at);

COMMIT;

-- ── Rollback ─────────────────────────────────────────────────────────────────
--   DROP TABLE IF EXISTS offer_approvals;
--   DROP TABLE IF EXISTS offer_documents;
--   DROP TABLE IF EXISTS offer_history;
--   DROP TABLE IF EXISTS offers;
-- Nothing outside the offers module depends on these tables, so a code-only
-- rollback (remove modules/offers/) is safe.
