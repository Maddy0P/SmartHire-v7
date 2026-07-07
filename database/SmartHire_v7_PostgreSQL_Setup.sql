-- ═════════════════════════════════════════════════════════════════════════════
--  SmartHire v7 — MASTER PostgreSQL Setup   (database/SmartHire_v7_PostgreSQL_Setup.sql)
--
--  The SINGLE, canonical, production-ready setup file for SmartHire v7 on
--  PostgreSQL 12+ / Neon. It creates the COMPLETE database from an EMPTY database:
--  all 22 tables, constraints, foreign keys, indexes, the updated_at trigger +
--  function, and the REQUIRED seed data (default login users + job-category lookup).
--
--  A new user only needs to:
--    1. Create an empty PostgreSQL database:   CREATE DATABASE smarthire;
--    2. Run this one file:                      psql "$DATABASE_URL" -f database/SmartHire_v7_PostgreSQL_Setup.sql
--    3. Start SmartHire.
--
--  This file supersedes and REPLACES the legacy MySQL files that were removed
--  during the PostgreSQL migration:
--    • smarthire_setup_COMPLETE.sql   (MySQL base schema + demo data)   — obsolete
--    • database/migration_v7.sql      (MySQL v7 migration)              — obsolete
--    • fix_broken_tests.sql           (one-time MySQL data repair)      — obsolete
--    • database/schema_pg.sql         (interim PG schema)               — folded into this file
--
--  Demo/sample rows from the old MySQL base (sample candidates, interviews,
--  results, question bank, sample tests) were intentionally EXCLUDED as test-only;
--  a fresh install creates these through the application UI.
--
--  Safe to re-run: CREATE ... IF NOT EXISTS and ON CONFLICT DO NOTHING throughout.
--  Wrapped in a single transaction so the install is atomic (all-or-nothing).
-- ═════════════════════════════════════════════════════════════════════════════

BEGIN;

CREATE TABLE IF NOT EXISTS users (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    email       VARCHAR(150) UNIQUE NOT NULL,
    password    VARCHAR(255) NOT NULL,
    role        VARCHAR(20) DEFAULT 'hr'
                CHECK (role IN ('super_admin','admin','hr','recruiter','interviewer')),
    is_active   SMALLINT DEFAULT 1,
    must_change_pw SMALLINT DEFAULT 0,
    last_login  TIMESTAMP NULL,
    created_by  INTEGER DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ── Candidates (+ portal login + extended profile + v7 columns) ──────────────
CREATE TABLE IF NOT EXISTS candidates (
    id               SERIAL PRIMARY KEY,
    name             VARCHAR(100) NOT NULL,
    email            VARCHAR(150) UNIQUE NOT NULL,
    phone            VARCHAR(20),
    position         VARCHAR(100),
    skills           TEXT,
    resume_note      TEXT,
    status           VARCHAR(20) DEFAULT 'pending'
                     CHECK (status IN ('pending','scheduled','interviewed','hired','rejected')),
    ai_score         INTEGER DEFAULT 0,
    password         VARCHAR(255) DEFAULT NULL,
    profile_photo    VARCHAR(255) DEFAULT NULL,
    address          TEXT,
    linkedin_url     VARCHAR(255),
    github_url       VARCHAR(255),
    education        TEXT,
    experience_years INTEGER DEFAULT 0,
    composite_score  INTEGER DEFAULT 0,
    test_avg_score   INTEGER DEFAULT 0,
    resume_path      VARCHAR(255) DEFAULT NULL,
    resume_scanned   SMALLINT DEFAULT 0,
    must_change_pw   SMALLINT DEFAULT 0,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_cand_status  ON candidates(status);
CREATE INDEX IF NOT EXISTS idx_cand_created ON candidates(created_at);

-- ── Interviews ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS interviews (
    id             SERIAL PRIMARY KEY,
    candidate_id   INTEGER NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
    interviewer    VARCHAR(100),
    scheduled_date DATE,
    scheduled_time TIME,
    type           VARCHAR(20) DEFAULT 'technical' CHECK (type IN ('technical','hr','final')),
    mode           VARCHAR(20) DEFAULT 'online'    CHECK (mode IN ('online','in-person')),
    status         VARCHAR(20) DEFAULT 'scheduled' CHECK (status IN ('scheduled','completed','cancelled','no-show')),
    notes          TEXT,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_iv_status ON interviews(status);
CREATE INDEX IF NOT EXISTS idx_iv_date   ON interviews(scheduled_date);

-- ── Interview results ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS results (
    id              SERIAL PRIMARY KEY,
    interview_id    INTEGER NOT NULL REFERENCES interviews(id) ON DELETE CASCADE,
    candidate_id    INTEGER NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
    technical_score INTEGER DEFAULT 0,
    communication   INTEGER DEFAULT 0,
    problem_solving INTEGER DEFAULT 0,
    cultural_fit    INTEGER DEFAULT 0,
    overall_score   INTEGER DEFAULT 0,
    recommendation  VARCHAR(20) DEFAULT 'maybe' CHECK (recommendation IN ('strong_yes','yes','maybe','no')),
    feedback        TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_res_candidate ON results(candidate_id);

-- ── Interview question bank (subjective + MCQ) ───────────────────────────────
CREATE TABLE IF NOT EXISTS interview_questions (
    id              SERIAL PRIMARY KEY,
    category        VARCHAR(20) DEFAULT 'technical'
                    CHECK (category IN ('technical','hr','behavioral','system_design','coding','mcq')),
    difficulty      VARCHAR(10) DEFAULT 'medium' CHECK (difficulty IN ('easy','medium','hard')),
    position_tag    VARCHAR(100) DEFAULT 'General',
    question        TEXT NOT NULL,
    expected_answer TEXT,
    max_score       INTEGER DEFAULT 10,
    question_type   VARCHAR(12) DEFAULT 'subjective' CHECK (question_type IN ('subjective','mcq')),
    option_a        VARCHAR(600) DEFAULT NULL,
    option_b        VARCHAR(600) DEFAULT NULL,
    option_c        VARCHAR(600) DEFAULT NULL,
    option_d        VARCHAR(600) DEFAULT NULL,
    correct_option  VARCHAR(1) DEFAULT NULL CHECK (correct_option IN ('a','b','c','d')),
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS candidate_responses (
    id               SERIAL PRIMARY KEY,
    interview_id     INTEGER NOT NULL REFERENCES interviews(id) ON DELETE CASCADE,
    candidate_id     INTEGER NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
    question_id      INTEGER NOT NULL REFERENCES interview_questions(id) ON DELETE CASCADE,
    score_given      INTEGER DEFAULT 0,
    interviewer_note TEXT,
    answered_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ── Resume scans (ATS scanner history) ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS resume_scans (
    id                  SERIAL PRIMARY KEY,
    candidate_id        INTEGER DEFAULT NULL REFERENCES candidates(id) ON DELETE SET NULL,
    candidate_name_free VARCHAR(100),
    position_applied    VARCHAR(100),
    raw_text            TEXT,
    ats_score           INTEGER DEFAULT 0,
    contact_score       INTEGER DEFAULT 0,
    keyword_score       INTEGER DEFAULT 0,
    format_score        INTEGER DEFAULT 0,
    experience_score    INTEGER DEFAULT 0,
    education_score     INTEGER DEFAULT 0,
    action_verb_score   INTEGER DEFAULT 0,
    matched_keywords    TEXT,
    missing_keywords    TEXT,
    recommendations     TEXT,
    word_count          INTEGER DEFAULT 0,
    role_keyword_score  INTEGER DEFAULT 0,
    application_id      INTEGER DEFAULT NULL,
    scanned_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rs_score   ON resume_scans(ats_score);
CREATE INDEX IF NOT EXISTS idx_rs_scanned ON resume_scans(scanned_at);

-- ── Notifications ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notifications (
    id           SERIAL PRIMARY KEY,
    user_id      INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
    candidate_id INTEGER DEFAULT NULL,
    type         VARCHAR(40) DEFAULT 'general',
    message      TEXT,
    is_read      SMALLINT DEFAULT 0,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_notif_candidate ON notifications(candidate_id);

-- ── Online tests ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS online_tests (
    id               SERIAL PRIMARY KEY,
    title            VARCHAR(200) NOT NULL,
    description      TEXT,
    candidate_id     INTEGER NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
    created_by       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    duration_minutes INTEGER DEFAULT 60,
    total_marks      INTEGER DEFAULT 100,
    passing_marks    INTEGER DEFAULT 40,
    status           VARCHAR(12) DEFAULT 'pending' CHECK (status IN ('pending','active','completed','expired')),
    test_link_token  VARCHAR(64) UNIQUE NOT NULL,
    scheduled_date   DATE,
    expiry_date      DATE,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_ot_status ON online_tests(status);

CREATE TABLE IF NOT EXISTS test_questions (
    id              SERIAL PRIMARY KEY,
    test_id         INTEGER NOT NULL REFERENCES online_tests(id) ON DELETE CASCADE,
    question_id     INTEGER NOT NULL REFERENCES interview_questions(id) ON DELETE CASCADE,
    marks           INTEGER DEFAULT 10,
    order_no        INTEGER DEFAULT 0,
    time_limit_secs INTEGER DEFAULT 0
);

CREATE TABLE IF NOT EXISTS test_submissions (
    id               SERIAL PRIMARY KEY,
    test_id          INTEGER NOT NULL REFERENCES online_tests(id) ON DELETE CASCADE,
    candidate_id     INTEGER NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
    started_at       TIMESTAMP NULL,
    submitted_at     TIMESTAMP NULL,
    total_score      INTEGER DEFAULT 0,
    max_score        INTEGER DEFAULT 100,
    percentage       DECIMAL(5,2) DEFAULT 0.00,
    status           VARCHAR(16) DEFAULT 'in_progress' CHECK (status IN ('in_progress','submitted','auto_submitted')),
    time_taken_mins  INTEGER DEFAULT 0,
    violations       INTEGER DEFAULT 0,
    fullscreen_exits INTEGER DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_ts_status ON test_submissions(status);

CREATE TABLE IF NOT EXISTS test_answers (
    id              SERIAL PRIMARY KEY,
    submission_id   INTEGER NOT NULL REFERENCES test_submissions(id) ON DELETE CASCADE,
    question_id     INTEGER NOT NULL REFERENCES interview_questions(id) ON DELETE CASCADE,
    answer_text     TEXT,
    selected_option VARCHAR(5) DEFAULT NULL,
    marks_awarded   INTEGER DEFAULT 0,
    is_correct      SMALLINT DEFAULT 0,
    time_spent_secs INTEGER DEFAULT 0,
    hr_marks        INTEGER DEFAULT NULL,
    hr_feedback     TEXT,
    hr_marked_at    TIMESTAMP NULL,
    hr_marked_by    INTEGER DEFAULT NULL,
    answered_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_submission_question UNIQUE (submission_id, question_id)
);

CREATE TABLE IF NOT EXISTS question_presets (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255),
    created_by  INTEGER DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS question_preset_items (
    preset_id   INTEGER NOT NULL REFERENCES question_presets(id) ON DELETE CASCADE,
    question_id INTEGER NOT NULL REFERENCES interview_questions(id) ON DELETE CASCADE,
    PRIMARY KEY (preset_id, question_id)
);

-- ═════════════════════════════════════════════════════════════════════════════
--  v7 recruitment + security tables
-- ═════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS job_categories (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(100) UNIQUE NOT NULL,
    slug        VARCHAR(120) UNIQUE NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS jobs (
    id               SERIAL PRIMARY KEY,
    title            VARCHAR(150) NOT NULL,
    category_id      INTEGER DEFAULT NULL REFERENCES job_categories(id) ON DELETE SET NULL,
    department       VARCHAR(100) DEFAULT NULL,
    location         VARCHAR(120) DEFAULT NULL,
    employment_type  VARCHAR(16) DEFAULT 'full_time'
                     CHECK (employment_type IN ('full_time','part_time','contract','internship','remote')),
    experience_min   INTEGER DEFAULT 0,
    experience_max   INTEGER DEFAULT 0,
    salary_min       INTEGER DEFAULT NULL,
    salary_max       INTEGER DEFAULT NULL,
    currency         VARCHAR(8) DEFAULT 'INR',
    openings         INTEGER DEFAULT 1,
    description      TEXT,
    requirements     TEXT,
    skills_required  TEXT,
    status           VARCHAR(10) DEFAULT 'open' CHECK (status IN ('draft','open','paused','closed')),
    posted_by        INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
    closes_on        DATE DEFAULT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_jobs_status   ON jobs(status);
CREATE INDEX IF NOT EXISTS idx_jobs_category ON jobs(category_id);
CREATE INDEX IF NOT EXISTS idx_jobs_created  ON jobs(created_at);

CREATE TABLE IF NOT EXISTS job_applications (
    id                SERIAL PRIMARY KEY,
    job_id            INTEGER NOT NULL REFERENCES jobs(id) ON DELETE CASCADE,
    candidate_id      INTEGER NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
    cover_note        TEXT,
    resume_path       VARCHAR(255) DEFAULT NULL,
    stage             VARCHAR(24) DEFAULT 'applied'
                      CHECK (stage IN ('applied','resume_screening','ats_analysis','shortlisted',
                             'online_test','interview_scheduled','interview_completed',
                             'selected','offer_released','joined','rejected')),
    ats_score         INTEGER DEFAULT NULL,
    skill_match       INTEGER DEFAULT NULL,
    experience_match  INTEGER DEFAULT NULL,
    education_match   INTEGER DEFAULT NULL,
    resume_quality    INTEGER DEFAULT NULL,
    interview_score   INTEGER DEFAULT NULL,
    final_score       INTEGER DEFAULT NULL,
    rejection_reason  VARCHAR(255) DEFAULT NULL,
    applied_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_job_candidate UNIQUE (job_id, candidate_id)
);
CREATE INDEX IF NOT EXISTS idx_app_stage ON job_applications(stage);
CREATE INDEX IF NOT EXISTS idx_app_job   ON job_applications(job_id);
CREATE INDEX IF NOT EXISTS idx_app_score ON job_applications(final_score);
CREATE INDEX IF NOT EXISTS idx_app_job_stage_score ON job_applications(job_id, stage, final_score DESC);

CREATE TABLE IF NOT EXISTS application_events (
    id             SERIAL PRIMARY KEY,
    application_id INTEGER NOT NULL REFERENCES job_applications(id) ON DELETE CASCADE,
    from_stage     VARCHAR(40) DEFAULT NULL,
    to_stage       VARCHAR(40) NOT NULL,
    note           VARCHAR(255) DEFAULT NULL,
    actor_id       INTEGER DEFAULT NULL,
    actor_role     VARCHAR(20) DEFAULT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_evt_app ON application_events(application_id);

CREATE TABLE IF NOT EXISTS offers (
    id             SERIAL PRIMARY KEY,
    application_id INTEGER NOT NULL REFERENCES job_applications(id) ON DELETE CASCADE,
    candidate_id   INTEGER NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
    job_id         INTEGER NOT NULL REFERENCES jobs(id) ON DELETE CASCADE,
    designation    VARCHAR(150) NOT NULL,
    ctc            INTEGER DEFAULT NULL,
    currency       VARCHAR(8) DEFAULT 'INR',
    joining_date   DATE DEFAULT NULL,
    letter_body    TEXT,
    status         VARCHAR(12) DEFAULT 'released' CHECK (status IN ('released','accepted','declined','withdrawn','joined')),
    released_by    INTEGER DEFAULT NULL,
    released_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    responded_at   TIMESTAMP NULL
);
CREATE INDEX IF NOT EXISTS idx_offer_status ON offers(status);

CREATE TABLE IF NOT EXISTS audit_logs (
    id          SERIAL PRIMARY KEY,
    actor_type  VARCHAR(12) DEFAULT 'system' CHECK (actor_type IN ('user','candidate','system','anon')),
    actor_id    INTEGER DEFAULT NULL,
    actor_email VARCHAR(150) DEFAULT NULL,
    action      VARCHAR(80) NOT NULL,
    entity      VARCHAR(60) DEFAULT NULL,
    entity_id   INTEGER DEFAULT NULL,
    detail      VARCHAR(500) DEFAULT NULL,
    ip          VARCHAR(45) DEFAULT NULL,
    user_agent  VARCHAR(255) DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_audit_action  ON audit_logs(action);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_audit_actor   ON audit_logs(actor_type, actor_id);

CREATE TABLE IF NOT EXISTS login_attempts (
    id           SERIAL PRIMARY KEY,
    identifier   VARCHAR(150) NOT NULL,
    ip           VARCHAR(45)  NOT NULL,
    realm        VARCHAR(12) DEFAULT 'hr' CHECK (realm IN ('hr','candidate')),
    success      SMALLINT DEFAULT 0,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_la_lookup ON login_attempts(identifier, ip, attempted_at);
CREATE INDEX IF NOT EXISTS idx_la_ip     ON login_attempts(ip, attempted_at);

CREATE TABLE IF NOT EXISTS password_resets (
    id          SERIAL PRIMARY KEY,
    realm       VARCHAR(12) NOT NULL CHECK (realm IN ('hr','candidate')),
    account_id  INTEGER NOT NULL,
    email       VARCHAR(150) NOT NULL,
    token_hash  CHAR(64) NOT NULL,
    expires_at  TIMESTAMP NOT NULL,
    used        SMALLINT DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_pr_token  ON password_resets(token_hash);
CREATE INDEX IF NOT EXISTS idx_pr_expiry ON password_resets(expires_at);

-- ═════════════════════════════════════════════════════════════════════════════
--  Performance indexes added in Phase 2 optimisation
-- ═════════════════════════════════════════════════════════════════════════════

-- notifications: every page load runs "WHERE is_read=0 AND user_id=?" — must be fast
CREATE INDEX IF NOT EXISTS idx_notif_user_read
    ON notifications(user_id, is_read);

-- notifications: candidate_id lookups (candidate portal queries)
CREATE INDEX IF NOT EXISTS idx_notif_candidate_read
    ON notifications(candidate_id, is_read);

-- online_tests: candidate portal fetches "WHERE candidate_id=?"
CREATE INDEX IF NOT EXISTS idx_ot_candidate
    ON online_tests(candidate_id);

-- job_applications: compound index covering candidate + stage filters used in analytics
CREATE INDEX IF NOT EXISTS idx_app_candidate_stage
    ON job_applications(candidate_id, stage);

-- test_answers: partial index for fast "correct answers" aggregation in analytics
CREATE INDEX IF NOT EXISTS idx_ta_correct
    ON test_answers(submission_id) WHERE is_correct = 1;

-- login_attempts: range cleanup and brute-force lookups both filter on attempted_at
CREATE INDEX IF NOT EXISTS idx_la_attempted
    ON login_attempts(attempted_at);

-- resume_scans: analytics orders by scanned_at — already indexed but add candidate lookup
CREATE INDEX IF NOT EXISTS idx_rs_candidate
    ON resume_scans(candidate_id);

-- ── updated_at trigger (emulates MySQL ON UPDATE CURRENT_TIMESTAMP) ──────────
CREATE OR REPLACE FUNCTION sh_touch_updated_at() RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = CURRENT_TIMESTAMP; RETURN NEW; END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_jobs_updated ON jobs;
CREATE TRIGGER trg_jobs_updated BEFORE UPDATE ON jobs
    FOR EACH ROW EXECUTE FUNCTION sh_touch_updated_at();

DROP TRIGGER IF EXISTS trg_app_updated ON job_applications;
CREATE TRIGGER trg_app_updated BEFORE UPDATE ON job_applications
    FOR EACH ROW EXECUTE FUNCTION sh_touch_updated_at();

-- ═════════════════════════════════════════════════════════════════════════════
--  Seed data — SECURITY NOTE
--  Default password for all seed accounts is "password" (bcrypt hash below).
--  must_change_pw = 1 forces an immediate password change on first login.
--  NEVER deploy to production without changing these passwords or removing
--  these accounts and creating fresh ones via the UI.
-- ═════════════════════════════════════════════════════════════════════════════
INSERT INTO users (name, email, password, role, must_change_pw) VALUES
 ('Admin User',   'admin@smarthire.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin', 1),
 ('HR Manager',   'hr@smarthire.com',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'hr',          1),
 ('Rahul Sharma', 'rahul@smarthire.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'interviewer', 1)
ON CONFLICT (email) DO NOTHING;

INSERT INTO job_categories (name, slug, description) VALUES
 ('Engineering',      'engineering',    'Software, data, and infrastructure roles'),
 ('Data & Analytics', 'data-analytics', 'Data science, analytics, ML roles'),
 ('Design',           'design',         'Product, UX and visual design roles'),
 ('DevOps & Cloud',   'devops-cloud',   'Platform, SRE and cloud roles'),
 ('Product',          'product',        'Product management and strategy roles')
ON CONFLICT (name) DO NOTHING;

COMMIT;
