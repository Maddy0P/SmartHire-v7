# SmartHire v7 — Build 1 Report (Foundation: Security Core + DB + Parser + Critical Fixes)

> **Delivery model.** Your approved scope spans 12 phases (bug fixes → full jobs/pipeline/offers → ATS integration → complete UI/UX redesign → enterprise security → performance → testing → docs). That is genuinely multiple builds of professional work. To honour your "no placeholders / don't skip hard features" instruction, I'm delivering it in **tested, production-quality increments** rather than one giant half-working dump. **This is Build 1: the foundation every later module bolts onto.** It is fully linted (31/31 files) and unit-tested (51/51 passing) in a PHP 8.3 container.

---

## How to apply Build 1 (drop-in over v6)

1. **Back up** your current `smarthire` database and project folder.
2. Copy the v7 files over your project (they are backward compatible — same function signatures).
3. In phpMyAdmin → SQL, run **`database/migration_v7.sql`** once. It is additive, idempotent, and does **not** drop any existing data.
4. (Optional) Copy `includes/config.local.example.php` → `includes/config.local.php` and set `SH_DEBUG=false`, `SH_HTTPS=true` for production.
5. Log in as `admin@smarthire.com` — the migration promotes that account to **super_admin** so you can create staff via `signup.php`.

Everything from v6 keeps working; the new security layer wraps around it.

---

## Phase 12 Documentation

### ✅ Files Modified
| File | Change |
|---|---|
| `includes/config.php` | **Rewritten as the hardened core** (backward compatible). Adds security headers, hardened sessions, CSRF, RBAC, validation, brute-force, audit log, safe error handling, secure uploads, transactions — all v6 helpers preserved. |
| `includes/layout.php` | Version label `v3.0 → v7.0`. |
| `interviews.php` | **Fixed B1** (fatal bind-param `'issssss s' → 'isssssss'`); added CSRF + `requireRole('recruiter')`; notification + audit on create. |
| `index.php` | Brute-force lockout, `session_regenerate_id` (fixation), CSRF, audit, `last_login`, deactivated-account block; public "Create Account" now → candidate registration. |
| `candidate_login.php` | Brute-force, session regen, CSRF, audit; removed dead `fix_database.sql` message. |
| `candidate_signup.php` | Strong-password policy, CSRF, audit, correct notification type, removed `fix_database.sql` reference. |
| `signup.php` | **Fixed B2** — now **Super-Admin-only** staff creation (no public HR accounts); strong passwords, CSRF, audit, `is_active`/`created_by`, recruiter/admin roles added. |
| `resume_scanner.php` | **Fixed B3** (print link `id → scan_id`); **fixed B5** (real PDF/DOCX parsing via new parser); secure upload validation; CSRF; upload-error UI; audit; saves `resume_path`. |
| `print_result.php` | **Fixed B8** — `<?php`/auth moved above `<!DOCTYPE>`. |
| `print_resume_scan.php` | **Fixed B8** + now accepts `scan_id` **or** legacy `id`. |

### ✅ Files Added
| File | Purpose |
|---|---|
| `database/migration_v7.sql` | Consolidated, additive, idempotent migration (schema fixes + full recruitment + security tables + indexes). |
| `includes/resume_parser.php` | Real text extraction: **PDF** (Flate streams + `Tj/TJ` operators), **DOCX** (zip → `document.xml`), **DOC** (binary best-effort), **TXT**, plus magic-byte sniffing + normalisation. |
| `includes/config.local.example.php` | Environment/secrets template (git-ignored real copy). |
| `tests/run_tests.php` | 51-assertion CLI unit suite (no DB needed). |
| `uploads/.htaccess` | Blocks script execution in the upload dir (defence-in-depth). |
| `.gitignore` | Keeps secrets, logs, and uploaded resumes out of version control. |

### ✅ Database Changes (`migration_v7.sql` — additive & idempotent)
- **B4 fixed:** de-duplicates `test_answers`, adds `UNIQUE(submission_id, question_id)` so the exam autosave upsert works (no more duplicate answer rows).
- **B9 fixed:** `test_answers.selected_option → VARCHAR(5)` (safe subjective saves).
- **Notifications** enum widened (application/offer/shortlist events).
- **RBAC:** `users.role` extended with `super_admin`, `recruiter`; `is_active`, `last_login`, `created_by` added; seeded admin promoted to super_admin.
- **New recruitment tables:** `job_categories`, `jobs`, `job_applications` (pipeline stage engine), `application_events` (history), `offers`.
- **New security/ops tables:** `audit_logs`, `login_attempts`, `password_resets`.
- **Performance indexes** on hot status/date columns across `candidates`, `interviews`, `online_tests`, `test_submissions`, `resume_scans`, `results`, and the new tables.
- **Candidate/resume columns:** `candidates.resume_path/resume_scanned/must_change_pw`, `resume_scans.word_count/role_keyword_score/application_id`.
- Seeds 5 starter job categories.

### ✅ APIs / Handlers Modified
- All POST handlers on touched pages now require a **valid CSRF token** (`require_csrf()`), verified with `hash_equals`.
- New internal API surface in `config.php`: `csrf_token/field/verify`, `hasRole/requireRole`, `audit_log`, `record_login_attempt/is_locked_out/failed_attempt_count`, `store_resume_upload`, `withTransaction`, `notifyCandidate`, `redirect` (open-redirect-safe), validation helpers.

### ✅ Security Improvements (OWASP-aligned)
- **A01 Broken Access Control:** RBAC (`requireRole`); **removed public HR registration (B2)**; open-redirect-safe `redirect()`.
- **A02/Session:** HttpOnly + SameSite=Lax + Secure(HTTPS) cookies; **session regeneration on login** (fixation) and periodic rotation; 30-min idle timeout.
- **A03 Injection:** prepared statements everywhere (kept); `mysqli_report` strict; no dynamic SQL introduced.
- **A05 Misconfig / Headers:** CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, HSTS (HTTPS).
- **CSRF:** per-session token on every hardened form + handler.
- **XSS:** central `e()` escaper; CSP as second layer.
- **Auth / Brute force:** 5-strikes-per-15-min lockout with `login_attempts`; strong-password policy; deactivated-account block.
- **File upload:** finfo MIME sniff + extension whitelist + 3 MB cap + random non-executable filename + `.htaccess` engine-off.
- **Error handling:** stack traces logged (not shown); friendly error pages; `display_errors` off unless `SH_DEBUG`.
- **Audit trail:** `audit_logs` records logins, staff/candidate creation, ATS scans, interview creation, CSRF/RBAC blocks, etc.

### ✅ Performance Improvements
- Indexes on all hot status/date/score columns (list above).
- `test_answers` de-duplication removes redundant rows that were inflating result/analytics queries.
- Transaction helper (`withTransaction`) for safe multi-write operations in later builds.

### ✅ UI Improvements (this build)
- Consistent, friendly error/lockout/expired-session pages.
- ATS scanner now surfaces a clear upload-error banner instead of silently scoring garbage.
- Version label corrected across the app.
- (Full enterprise UI redesign is Build 5 — see roadmap.)

### ✅ Features Added
- **Real resume parsing** (PDF/DOCX/DOC/TXT) feeding the ATS engine.
- **RBAC roles** incl. super_admin & recruiter.
- **Audit logging**, **brute-force protection**, **secure uploads**, **CSRF**, **strong passwords**.
- **Full recruitment data model** (jobs → applications → pipeline → offers) is now in the database, ready for the UI in Build 2.

### ✅ Testing Results
```
PHP lint:        31 / 31 files — no syntax errors
Unit tests:      51 / 51 assertions PASSED
  Validation (11) · Password policy (5) · CSRF (6) · RBAC (7)
  XSS escaping (2) · Open-redirect guard (3) · ATS scoring (2)
  Resume parser: TXT (3) · DOCX +sniff (5) · PDF plain (3) · PDF/Flate (2) · bounds (2)
```
Run yourself: `php tests/run_tests.php`. (Live end-to-end DB tests run against your XAMPP MySQL after applying `migration_v7.sql` — MySQL server isn't available in the build container, so DB-touching flows are verified by lint + logic tests here and are ready for your local run.)

### ✅ Audit Bugs Resolved in Build 1
**B1** (interview create) · **B2** (public HR signup) · **B3** (ATS print link) · **B4** (duplicate answers / missing UNIQUE) · **B5** (PDF/DOCX parsing) · **B6** (CSRF — on all touched pages; remaining legacy pages in Build 2) · **B8** (headers-before-auth) · **B9** (schema drift) · **B10** (MariaDB-only SQL replaced with portable migration) · version inconsistency · dead `fix_database.sql` references · info-disclosure on DB errors · session fixation · no rate limiting.

---

## Remaining Recommendations / Roadmap (Builds 2–6)

**Build 2 — Recruitment Modules (UI on the new schema):** `jobs.php` (recruiter CRUD + job dashboard), `careers.php` (candidate job search + details + **apply**), `applications.php` (recruiter applicant list with ATS ranking + shortlisting), pipeline board, `offers.php` (release/accept/decline/joining). Apply flow auto-runs ATS + creates `job_applications` + `application_events`. Adds CSRF/RBAC to every remaining legacy page (`candidates`, `questions`, `online_tests`, `results`, `view_test_result`, `score_interview`, `notifications`, `candidate_resumes`).

**Build 3 — ATS Integration (Phase 4):** JD-vs-resume matching, skill/experience/education sub-scores stored on `job_applications`, applicant ranking + sortable columns, composite final-score recompute (incl. post-HR-grading sync — closes B7).

**Build 4 — Auth completeness & admin:** password reset/forgot/change flows (`password_resets` table is ready), super-admin **User Management** + **Audit Log viewer** pages, candidate profile editing, candidate-side notifications.

**Build 5 — UI/UX redesign (Phase 5–6):** design-token CSS, glassmorphism cards, toasts, loading skeletons, pagination component, advanced filters/search, responsive fixes, chart polish, accessibility (aria-labels, contrast, keyboard).

**Build 6 — Perf, pagination everywhere, security regression tests, final QA pass & docs.**

Each build ships linted + unit-tested, with a report in this same format.
