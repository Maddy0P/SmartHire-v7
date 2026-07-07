# SmartHire v7 — Build 2 Report (Recruitment Workflow + Responsive Design System)

> **Builds on Build 1 — nothing from Build 1 was rebuilt or removed.** This build delivers the complete **recruitment workflow end-to-end** (jobs → careers → apply → ATS ranking → pipeline → offers → joining) plus a **shared responsive design-system layer** (`v7.css` + `v7.js`) that the new pages use and that legacy pages can progressively adopt. Everything reuses Build 1's security core (RBAC, CSRF, audit, prepared statements, secure uploads).
>
> **Honest scope note:** Phases 3–4 asked for a full visual redesign of *every existing page*. That is a large, separate effort. Build 2 ships the design system and applies it fully to all **new** recruitment screens + the candidate portal nav + the mobile sidebar drawer (which affects every admin page). **Re-skinning the remaining legacy admin pages (dashboard, candidates, results, analytics, tests, interviews, notifications) onto the new tokens is Build 3.** I'd rather ship these screens correct and tested than half-restyle twenty pages.

---

## Files Added
| File | Purpose |
|---|---|
| `includes/recruitment.php` | Recruitment engine — pipeline stage model, legal-transition rules, stage moves with event logging + candidate notifications, application creation, and **JD-aware ATS scoring** (skill/experience/education/quality → weighted composite + final score). Pure-logic parts are unit-tested. |
| `assets/css/v7.css` | Additive design-system + responsive layer (loads **after** `main.css`, overrides nothing): job cards, stage badges, ATS score rings, pipeline/kanban board, timeline, filter bar, pagination, toasts, skeletons, empty states, responsive stacked tables, mobile off-canvas sidebar, focus-visible a11y, reduced-motion support, breakpoints for mobile/tablet/laptop/desktop. |
| `assets/js/v7.js` | Progressive enhancement: mobile sidebar drawer + overlay, toast notifications, **AJAX pipeline stage moves**, **drag-and-drop** between pipeline columns, confirm-guards, live list filtering. Every action degrades to a plain form/link if JS is off. |
| `jobs.php` | **Recruiter** job dashboard — create/edit jobs, open/pause/close, category quick-add, search + status/category filters, pagination, per-job applicant counts. |
| `careers.php` | **Candidate** careers — job search (keyword/category/type), job detail, and **Apply** (resume upload → auto-ATS → application created). |
| `applications.php` | **Recruiter** applicant dashboard — **ATS-ranked sortable table** (skill/exp/edu/quality/interview/final) + **Kanban pipeline board** with drag-drop; shortlist / advance / reject actions; JSON AJAX endpoint (`?ajax=1`). |
| `application_detail.php` | Single application — ATS sub-score bars, full **timeline**, stage controls, interview-score entry (recomputes final score), and **offer release**. |
| `my_applications.php` | **Candidate** application tracking — per-application stage progress track, ATS score, and **offer accept/decline** (IDOR-safe). |

## Files Modified
| File | Change |
|---|---|
| `includes/layout.php` | Loads `v7.css` + `v7.js`; adds `<meta name="csrf-token">` for AJAX; adds **Jobs** and **Applicants** to the recruiter sidebar nav. |
| `candidate_portal.php` | Loads `v7.css`; adds candidate top-nav (**Portal / Careers / My Applications**). |
| `tests/run_tests.php` | +32 assertions covering the recruitment engine (now **83 total**). |

## Database Changes
**None** — Build 2 runs entirely on the schema created by Build 1's `migration_v7.sql` (`jobs`, `job_categories`, `job_applications`, `application_events`, `offers`, plus the ATS sub-score columns). No new migration is required. Offer *acceptance* is tracked on `offers.status` rather than as a separate pipeline enum value, so no enum change was needed.

## Screens Added
Recruiter: **Jobs**, **Applicants (list + pipeline board)**, **Application Detail**. Candidate: **Careers (search + detail + apply)**, **My Applications**. All wired into navigation for both realms.

## Security Changes (all Build 1 protections preserved)
- **RBAC:** `jobs.php`, `applications.php`, `application_detail.php` require recruiter-or-higher; candidate pages require candidate login.
- **CSRF:** every POST (including the AJAX stage-move endpoint, which verifies the `X-CSRF-Token` header) is token-checked.
- **XSS:** all output escaped via `e()`.
- **SQL injection:** 100% prepared statements; dynamic pieces limited to whitelisted filter/sort tokens.
- **IDOR:** candidate offer actions and application queries are scoped to `candidate_id`; offer ownership is re-verified before any state change.
- **Secure uploads:** applying reuses Build 1's `store_resume_upload()` (finfo MIME + whitelist + size cap + random filename).
- **Audit:** job create/update/status, category add, stage moves, interview scoring, offer release/accept/decline, application create — all written to `audit_logs` + `application_events`.

## Performance Improvements
- Applicant ranking and job lists use the Build 1 indexes (`idx_app_stage`, `idx_app_job`, `idx_app_score`, `idx_jobs_status`, `idx_jobs_created`).
- Per-job applicant counts via correlated subqueries (indexed) instead of N+1 queries.
- Jobs list is paginated (9/page); CSS/JS are static cacheable files; drag-drop updates a single row over AJAX rather than reloading the board.
- `prefers-reduced-motion` disables animations for users who request it.

## Responsive Improvements
- **Mobile off-canvas sidebar** with overlay + Esc-to-close (affects all recruiter pages).
- Breakpoints at **1200 / 900 / 768 / 480px**; grids reflow 4→2→1; pipeline columns become swipeable; the topbar search hides on mobile.
- **Responsive tables**: `.sh-rtable` collapses rows into labelled stacked cards under 760px (used on the applicant table).
- Candidate header nav, filter bars, and forms all wrap/stack cleanly.

## UI Improvements
Modern job cards with hover lift, stage badges, conic-gradient ATS score rings, sub-score bars, a Kanban pipeline board, a vertical application timeline, filter bars with search, pagination, toast notifications, skeleton/empty states, and consistent focus-visible outlines — all on one shared token set (`--sh-*`).

## Testing Results
```
PHP lint:   39 / 39 files — no syntax errors
JS check:   assets/js/v7.js — valid (node --check)
Unit tests: 83 / 83 assertions PASSED   (51 from Build 1 + 32 new)
  New: pipeline model (11) · skill parsing/matching (4) · experience (6)
       education & quality (5) · ATS composite (6)
```
Run: `php tests/run_tests.php`. DB-touching page flows (apply, stage moves, offers) are lint-verified and built on tested pure-logic; run them live against your XAMPP MySQL after the Build 1 migration (MySQL server isn't available in the build container).

**Manual test checklist for your XAMPP:** post a job (recruiter) → browse & apply (candidate, upload a PDF/DOCX) → see ATS score on the applicant table → drag the card across the pipeline board → open the application, set an interview score, release an offer → accept the offer as the candidate → watch stage + notifications update.

## Remaining Work
- **Build 3:** re-skin the remaining legacy admin pages (dashboard, candidates, results, analytics, online_tests, interviews, notifications, resume pages) onto the v7 design tokens; add CSRF/RBAC to their POST handlers; wire the recruitment pipeline into the existing interviews/online-test modules (auto-advance stage when a test/interview is completed); password-reset UI (table ready from Build 1); super-admin user-management + audit-log viewer.
- **Build 4:** analytics for the recruitment funnel, saved searches, bulk actions, email notifications, and a final accessibility + performance audit pass.
