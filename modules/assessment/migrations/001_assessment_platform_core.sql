-- ═════════════════════════════════════════════════════════════════════════════
--  SmartHire Assessment Platform Core — Migration 001  (Module 8A)
--  Strategy: EXTEND, DON'T DUPLICATE. The existing pipeline
--  (interview_questions → question_presets → online_tests → test_questions →
--   test_submissions → test_answers) is promoted to the platform core.
--  Every statement here is additive and idempotent; no data is destroyed.
--  Apply:  psql "$DATABASE_URL" -f modules/assessment/migrations/001_assessment_platform_core.sql
--  Rollback notes at the bottom.
-- ═════════════════════════════════════════════════════════════════════════════

BEGIN;

-- ── 1. Question Engine: widen the bank for registry-driven types ─────────────
-- The type/category whitelists move from CHECK constraints into the code-level
-- QTypeRegistry (modules/assessment/engine/QTypeRegistry.php), which is the
-- single authority on valid types. Existing values remain valid.
DO $$
DECLARE c RECORD;
BEGIN
  FOR c IN
    SELECT conname FROM pg_constraint
    WHERE conrelid = 'interview_questions'::regclass
      AND contype = 'c'
      AND (pg_get_constraintdef(oid) ILIKE '%question_type%'
        OR pg_get_constraintdef(oid) ILIKE '%category%')
  LOOP
    EXECUTE format('ALTER TABLE interview_questions DROP CONSTRAINT %I', c.conname);
  END LOOP;
END $$;

ALTER TABLE interview_questions
  ADD COLUMN IF NOT EXISTS metadata   JSONB       NOT NULL DEFAULT '{}'::jsonb,
  ADD COLUMN IF NOT EXISTS answer_key JSONB       NULL,
  ADD COLUMN IF NOT EXISTS skills     VARCHAR(500) NULL,
  ADD COLUMN IF NOT EXISTS status     VARCHAR(12) NOT NULL DEFAULT 'active',
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP   NULL;

COMMENT ON COLUMN interview_questions.metadata   IS 'Type-specific config (options for multi-select, language for coding, bonus flag, rubric, plugin refs). Registry-validated.';
COMMENT ON COLUMN interview_questions.answer_key IS 'Structured key: {"correct":["a","c"]} | {"value":true} | {"accepted":["..."]} | {"expected_output":"..."}. Legacy MCQ keeps correct_option.';
COMMENT ON COLUMN interview_questions.skills     IS 'Comma-separated skill tags feeding the Result Engine skill analysis.';

CREATE INDEX IF NOT EXISTS idx_iq_type     ON interview_questions(question_type);
CREATE INDEX IF NOT EXISTS idx_iq_status   ON interview_questions(status);
CREATE INDEX IF NOT EXISTS idx_iq_metadata ON interview_questions USING GIN (metadata);

-- ── 2. Question Pools: promote question_presets to first-class pools ─────────
ALTER TABLE question_presets
  ADD COLUMN IF NOT EXISTS status VARCHAR(12)  NOT NULL DEFAULT 'active',
  ADD COLUMN IF NOT EXISTS tags   VARCHAR(300) NULL;

-- ── 3. Template Engine (new): reusable assessment blueprints ─────────────────
CREATE TABLE IF NOT EXISTS assessment_templates (
    id               SERIAL PRIMARY KEY,
    name             VARCHAR(200) NOT NULL,
    category         VARCHAR(80)  NULL,
    department       VARCHAR(80)  NULL,
    role             VARCHAR(120) NULL,
    experience_level VARCHAR(30)  NULL,          -- junior|mid|senior|lead|any
    duration_minutes INTEGER      NOT NULL DEFAULT 60,
    passing_score    INTEGER      NOT NULL DEFAULT 40,   -- percentage
    max_attempts     INTEGER      NOT NULL DEFAULT 1,
    instructions     TEXT         NULL,
    certification    SMALLINT     NOT NULL DEFAULT 0,
    expiry_days      INTEGER      NULL,          -- link validity after issue
    config           JSONB        NOT NULL DEFAULT '{}'::jsonb,
    -- config carries AssessmentConfig overrides: randomize, negative_marking,
    -- partial_credit, difficulty_mix, section time rules, recommendation bands…
    status           VARCHAR(12)  NOT NULL DEFAULT 'draft',  -- draft|active|archived
    created_by       INTEGER      NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NULL
);
CREATE INDEX IF NOT EXISTS idx_at_status ON assessment_templates(status);
CREATE INDEX IF NOT EXISTS idx_at_role   ON assessment_templates(role);

CREATE TABLE IF NOT EXISTS assessment_template_sections (
    id             SERIAL PRIMARY KEY,
    template_id    INTEGER NOT NULL REFERENCES assessment_templates(id) ON DELETE CASCADE,
    name           VARCHAR(120) NOT NULL,
    pool_id        INTEGER NULL REFERENCES question_presets(id) ON DELETE SET NULL,
    question_count INTEGER NOT NULL DEFAULT 5,
    time_minutes   INTEGER NULL,                 -- optional per-section time
    weight         NUMERIC(5,2) NOT NULL DEFAULT 1.0,
    difficulty_mix JSONB   NOT NULL DEFAULT '{}'::jsonb,  -- {"easy":2,"medium":2,"hard":1}
    config         JSONB   NOT NULL DEFAULT '{}'::jsonb,
    sort_order     INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_ats_template ON assessment_template_sections(template_id);

-- ── 4. Instances: link generated tests back to their template ────────────────
ALTER TABLE online_tests
  ADD COLUMN IF NOT EXISTS template_id INTEGER NULL REFERENCES assessment_templates(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS config      JSONB   NOT NULL DEFAULT '{}'::jsonb;
COMMENT ON COLUMN online_tests.config IS 'Frozen AssessmentConfig snapshot at generation time — scoring stays stable even if the template changes later.';
CREATE INDEX IF NOT EXISTS idx_ot_template ON online_tests(template_id);

ALTER TABLE test_questions
  ADD COLUMN IF NOT EXISTS section_id INTEGER NULL REFERENCES assessment_template_sections(id) ON DELETE SET NULL;

-- ── 5. Answers: structured responses for new types ───────────────────────────
ALTER TABLE test_answers
  ADD COLUMN IF NOT EXISTS response JSONB NULL;
COMMENT ON COLUMN test_answers.response IS 'Structured payload for non-scalar types (multi-select arrays, code+language, rating…). Legacy answer_text/selected_option remain authoritative for mcq/subjective.';

-- ── 6. Plugin registry (generic; question_source|delivery|ai_scorer|exporter|webhook)
CREATE TABLE IF NOT EXISTS assessment_plugins (
    id         SERIAL PRIMARY KEY,
    code       VARCHAR(60)  NOT NULL UNIQUE,     -- e.g. hackerrank, codility, bedrock, openai, claude, github
    name       VARCHAR(120) NOT NULL,
    kind       VARCHAR(30)  NOT NULL,
    config     JSONB        NOT NULL DEFAULT '{}'::jsonb,
    enabled    SMALLINT     NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ── 7. Event outbox (event-driven core; consumers arrive in later modules) ───
CREATE TABLE IF NOT EXISTS assessment_events (
    id           BIGSERIAL PRIMARY KEY,
    event_name   VARCHAR(80) NOT NULL,
    entity       VARCHAR(40) NULL,
    entity_id    INTEGER     NULL,
    payload      JSONB       NOT NULL DEFAULT '{}'::jsonb,
    created_at   TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP   NULL
);
CREATE INDEX IF NOT EXISTS idx_ae_unprocessed ON assessment_events(event_name) WHERE processed_at IS NULL;

COMMIT;

-- ── Rollback notes ───────────────────────────────────────────────────────────
-- New tables can be dropped safely (no existing code reads them before 8B):
--   DROP TABLE assessment_events, assessment_plugins,
--              assessment_template_sections, assessment_templates;
-- Added columns are nullable/defaulted; dropping them restores the prior shape:
--   ALTER TABLE interview_questions DROP COLUMN metadata, DROP COLUMN answer_key,
--     DROP COLUMN skills, DROP COLUMN status, DROP COLUMN updated_at;
--   ALTER TABLE online_tests DROP COLUMN template_id, DROP COLUMN config;
--   ALTER TABLE test_questions DROP COLUMN section_id;
--   ALTER TABLE test_answers DROP COLUMN response;
--   ALTER TABLE question_presets DROP COLUMN status, DROP COLUMN tags;
-- The dropped CHECK constraints are intentionally not recreated: the
-- QTypeRegistry is now the type authority (recreating them would break any
-- rows inserted with new registry types).
