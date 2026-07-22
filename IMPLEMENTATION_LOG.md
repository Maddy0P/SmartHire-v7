# SmartHire Enterprise Implementation Log

Chronological record of every completed module. Required by the Phase 4 charter.
Format per entry: date · module · files · improvements · a11y · perf · responsive · known issues · QA · commit message.

---

## 2026-07-17 — Modules 1–3: Application Shell, Dashboard, Candidates

**Architecture decision (governs all three):** Dual shell. The live product is dark-themed (v7); the Design Bible v1.2 is light-first; the shell is shared by all 42 pages. To honor the Blueprint rule "a page is fully old or fully new — never hybrid," `renderHead($title, true)` opts a page into the new v8 shell while every unmigrated page renders the legacy v7 shell via byte-preserved copies of the original functions (`sh_legacy_head/sidebar/footer`). Each future module flips its pages to v8.

### Files changed
- `includes/layout.php` — dual shell (v8 branch new; legacy branch byte-preserved). Backup: `includes/layout.php.bak-v7`
- `dashboard.php` — v8 rebuild; all original queries verbatim + 3 read-only presentation aggregates (status funnel, 30-day delta, 8-week sparkline). Backup: `dashboard.php.bak-v7`
- `candidates.php` — v8 rebuild; entire POST/logic block byte-preserved. Backup: `candidates.php.bak-v7`

### Files added
- `assets/css/` — `tokens.css`, `utilities.css`, `layout.css`, `components.css`, `cards.css`, `tables.css`, `forms.css`, `modals.css`, `animations.css` (~34 KB unminified; `--sh-*` namespace; scoped to `body.sh-v8`)
- `assets/js/shell.js` — drawer, dropdown menus, slide-over API (`shOpenSlideover`/`shCloseSlideover`), Esc handling, `/` search focus, bulk selection, client-side CSV export, bulk delete via sequential POSTs to the existing endpoint

### Files untouched
All 40 non-migrated root pages (verified byte-identical against the Phase 0 baseline), all backend includes except `layout.php`, all APIs, SQL, auth, ATS/AI engines, `main.js`/`v7.js` and their minified builds, legacy CSS.

### Features improved
- Shell: grouped sidebar IA (Recruit / Assess / Intelligence), breadcrumbs, labeled global search with `/` shortcut, notification bell as a real `<button>`, user dropdown menu, flash restyle.
- Dashboard: KPI band with full Bible P12 anatomy (icon, title, metric, trend, comparison period, sparkline, status link); recruitment funnel as the page's single hero card; upcoming-interviews queue; pending-tests warning; recent-candidates table; genuine empty states.
- Candidates: filter chips with counts + URL state; bulk select with CSV export and bulk delete; row-click candidate detail slide-over; rebuilt add/edit modals; hover row actions with `focus-within` support.

### Accessibility fixes (Phase 0 items in scope)
Skip link added (0→1); all form controls in migrated pages label-paired (candidates unlabeled inputs 14→1); icon-only controls given `aria-label`s; `aria-current` on nav; `aria-expanded`/`aria-controls` on disclosure triggers; `role="status"` flash; focus-visible rules 7→8; reduced-motion rules 1→2; decorative icons `aria-hidden`.

### Performance improvements
Inter trimmed 6 weights → 3 on v8 pages; no chart library (CSS funnel + inline SVG sparkline); new CSS loads only on v8 pages; zero new dependencies; dashboard adds 3 aggregate queries (indexed, read-only).

### Responsive improvements
Sidebar: drawer <768 px, icon rail <1280 px; tables transform to labeled cards on mobile (`data-th`), not squeezed; 44 px touch targets on mobile; forms single-column on mobile.

### Known issues / risks
- Legacy pages keep the old dark look until migrated (by design under dual shell).
- Bulk delete is N sequential requests to the existing per-item endpoint; a true bulk endpoint requires an approved backend change.
- Two shells coexist in `layout.php` until the final page migrates, then the legacy branch is deleted.
- Inline `style=` remaining in migrated files: data-bound bar widths + one JS-state hook only (sanctioned by amended Appendix A).

### QA result — PASS
`php -l` clean on all changed files · unit tests 178/178 · 40/40 unmigrated pages byte-identical · scanner deltas: dashboard inline styles 9→2, candidates 11→1 · legacy JS contracts verified (`toggleNotifDropdown` `open`-class, `updateBadge` display hook, `openModal`/`closeModal`, `tableSearch`, `openEditModal`, `?action=new/edit` auto-open).

### Suggested commit message
```
feat(ui): v8 enterprise shell + dashboard + candidates (Modules 1-3)

Dual-shell architecture: Design Bible v1.2 light shell for migrated
pages, legacy v7 shell byte-preserved for the rest. New token-based
CSS architecture (9 files, --sh-* namespace) and shell.js. Dashboard
and candidates rebuilt on v8 with all backend logic and JS contracts
preserved. A11y: skip link, labels, aria, reduced-motion. 178/178
tests passing; 40 unmigrated pages byte-identical.
```

---

## 2026-07-17 — Module 4: Jobs

### Audit summary
`jobs.php` (308 lines): solid frozen backend (save create/update, `set_status` across draft/open/paused/closed, add_category — all CSRF'd, prepared, audit-logged) under a legacy dark card grid. Debts found: 18 unlabeled inputs, 2 unlabeled icon buttons, 4 inline styles, no sorting, no bulk actions, pagination printed every page number, `shortlisted_count` fetched but never rendered, filter selects auto-submitted without visible affordance.

### Files changed
- `jobs.php` — v8 rebuild; entire logic block byte-preserved (verified 1:1 against backup). Backup: `jobs.php.bak-v7`
- `includes/layout.php` — one-line addition: requires `ui_helpers.php`
- `dashboard.php`, `candidates.php` — duplicated local helpers removed (now sourced from the shared include)
- `assets/js/shell.js` — `shBulkExport` generalized (headers/filename params, back-compat defaults); new generic `shBulkPost`; `shBulkDelete` now a thin wrapper
- `assets/css/utilities.css` (+`.sh-input-auto`), `assets/css/cards.css` (+`.sh-timeline`)

### Files added
- `includes/ui_helpers.php` — `sh_status_badge`, `sh_job_status_badge`, `sh_delta_chip`, `sh_sparkline`, `sh_pagination` (charter de-duplication refactor)

### Files untouched
All backend includes except the one-line layout require; all APIs, SQL, auth; all 39 non-migrated pages (verified byte-identical).

### Features improved
Header stats (now surfaces shortlisted total); status filter chips with per-status counts; whitelisted sorting (newest/oldest/title/applicants/closing-soon); bulk pause/close/reopen via the existing `set_status` endpoint + CSV export; per-row status menu (all four statuses, confirm on close); **Duplicate job** (prefills the create form from an existing role — uses the existing create path, zero backend change); row-click detail slide-over with a real timeline (posted → audit-log events → closes date); windowed pagination; rebuilt create/edit form as the page's hero card with grouped fields and helper text; categories card restyled with labeled inputs.

### Accessibility improvements
Unlabeled inputs 18→1, unlabeled icon buttons 2→0, inline styles 4→0; `aria-current` on filter chips; `aria-haspopup`/`aria-expanded`/`aria-controls` on menus; sr-only labels on filter controls; dialog semantics on the slide-over.

### Responsive improvements
Table transforms to labeled cards on mobile (`data-th`); filter row wraps; form single-column <768px; 44px touch targets inherited from the shell.

### Performance improvements
Zero new dependencies; one shared helper file replaces three page-local copies; three read-only presentation queries added (status counts, shortlisted total, one `IN`-batched audit fetch for the page's jobs); default sort path runs the exact original query.

### Known risks
- "Archive" is mapped to the existing `closed` status — a true archive state requires a schema change (out of frozen scope; recommend for a future approved backend change).
- Sorted views re-run the list query with a whitelisted ORDER BY (the unsorted default executes the original query verbatim).
- Bulk status = N sequential POSTs to the existing endpoint (same pattern as Module 3 bulk delete).

### QA result — PASS
`php -l` clean (jobs, layout, dashboard, candidates, ui_helpers) · shell.js parses · unit tests 178/178 · logic block verified byte-identical · 39/39 non-migrated pages byte-identical · `data-confirm` legacy handler verified present · scanner: jobs inline styles 4→0, unlabeled inputs 18→1, icon buttons 2→0.

### Suggested commit message
```
feat(ui): v8 jobs module — table, sorting, bulk status, duplicate, timeline (Module 4)

Jobs rebuilt on the v8 shell with backend logic byte-preserved. Adds
whitelisted sorting, status-chip filters with counts, bulk pause/close/
reopen over the existing set_status endpoint, duplicate-job prefill,
detail slide-over with audit-log timeline, windowed pagination.
Refactor: shared includes/ui_helpers.php replaces page-local helper
copies; shell.js bulk functions generalized (back-compat). A11y: 18
unlabeled inputs fixed. 178/178 tests; 39 legacy pages byte-identical.
```

---

## 2026-07-17 — Module 5: Applications

### Audit summary
`applications.php` (218 lines): the richest backend so far — AJAX stage-move endpoint (`?ajax`, JSON, header-or-field CSRF), stage machine (11 stages via `SH_STAGES`/`sh_move_stage` with `application_events` audit trail), shortlist-with-email, reject-with-reason, server-side ranked CSV export, 8-way sorting. Debts: 12-column table (violates the 7-column cap), `ats_ring()` helper with 4 raw hex colors, drag-and-drop as the *only* board interaction (inaccessible), board JS living in legacy `v7.js`, 7 inline styles, 2 unlabeled icon buttons, no pagination.

### Files changed
- `applications.php` — v8 rebuild; lines 1–96 (AJAX endpoint, stage actions, filters, ranked query, CSV export, counts) byte-preserved and verified 1:1. `ats_ring()` (raw-hex presentation helper) replaced by Bible score bars. Backup: `applications.php.bak-v7`
- `assets/js/shell.js` — new board module: accessible per-card "Move to…" select (primary path) + drag-and-drop (enhancement), both posting to the existing `?ajax` endpoint; optimistic move, live-region feedback, count refresh
- `assets/css/cards.css` — pipeline board styles under `.sh-v8` scope

### Files added
None (charter reuse rule satisfied: table, chips, slide-over, bulk bar, pagination, timeline, score bars all reused from existing components).

### Files untouched
`includes/recruitment.php` (entire stage machine), `mailer.php`, `v7.js` (legacy pages still consume it), all 38 remaining legacy pages (byte-verified), all SQL/APIs/auth.

### Features improved
Table condensed 12→7 data columns — the four ATS sub-scores (skill/experience/education/quality) moved to the detail slide-over as labeled bars, satisfying the Phase 2 explainable-scoring decision instead of cramming; rank preserved via numbering. Detail slide-over: candidate + job summary, applied date, current stage, full score breakdown (AI-labeled), stage history timeline from `application_events` (with actor roles — this also covers "who acted" since there is no assignment column, see risks), reject note, advance-to-next quick action, link to full detail page. Board: v8 restyle, per-column counts, empty-column states, accessible move select, drag enhancement, `aria-live` status feedback. Bulk shortlist/reject over existing endpoints. List pagination (20/page, presentation-layer slice — board and CSV still operate on the full set). Export CSV reuses the existing server-side ranked export.

### Accessibility improvements
Board no longer drag-only — every card has a labeled select fallback (mobile shows it permanently); icon buttons 2→0 unlabeled; inline styles 7→2 (both sanctioned data-bound widths); `aria-live` move feedback; dialog semantics; sr-only labels on all filter controls. Residual scanner flag on the row checkbox is a false positive (PHP-echo inside the aria-label value).

### Responsive improvements
Board: horizontal scroll columns, permanent move-select on touch; table→cards on mobile; filter row wraps.

### Performance improvements
One batched `IN` query for stage events (capped 400 rows/6 per app) is the only added query; pagination trims list DOM from unbounded to 20 rows; no dependencies; board module ~70 lines replacing the v7.js dependency on migrated pages.

### Known risks
- **Recruiter assignment is not implementable without schema change** — `job_applications` has no assignee column. The timeline's actor display covers accountability; a real assignment feature needs an approved migration (recommended for the backlog alongside "archive job").
- v7.js and shell.js both contain board logic during the transition; v7's copy retires with the legacy pages (transition state, tracked).
- Optimistic board moves revert only via the status message on failure (matches previous behavior).

### QA result — PASS
Logic block verified byte-identical (incl. AJAX + CSV contracts) · `php -l` clean · shell.js parses · 178/178 tests · 38/38 legacy pages byte-identical · scanner: inline 7→2 (sanctioned), icon buttons 2→0, raw hex 0→0.

### Suggested commit message
```
feat(ui): v8 applications module — accessible pipeline board, 7-col ranked
list, detail slide-over with score breakdown + stage timeline (Module 5)

Backend byte-preserved (AJAX stage endpoint, CSV export, stage machine).
Board rebuilt in shell.js with an accessible per-card move control plus
drag-and-drop, posting to the existing ?ajax endpoint. ATS sub-scores
relocated to an explainable breakdown panel. Bulk shortlist/reject,
list pagination, aria-live feedback. 178/178 tests; 38 legacy pages
byte-identical.
```

---

## 2026-07-17 — Module 6: Candidate Profile

### Audit summary
`candidate_detail.php` (519 lines) — Phase 0's second-worst page: **74 inline styles**, queries interleaved into markup, Chart.js radar for category scores, PDF export hardcoding the dark background, no tabs (one endless scroll). Backend surface: read-only (no POST endpoints) — candidate row, interviews, latest scored result + per-question responses, latest `resume_scans` row (7 evidence dimensions + matched/missing keywords + recommendations), test submissions with per-question analytics, and a 20/25/35/20-weighted composite score.

### Files changed
- `candidate_detail.php` — v8 rebuild as a tabbed workspace. Top logic byte-preserved; the two mid-markup queries hoisted verbatim to the top. Backup: `candidate_detail.php.bak-v7`
- `assets/js/shell.js` — new **generic accessible tab component** (WAI-ARIA APG arrow-key pattern, hash deep-links) — reusable by later modules
- `assets/css/components.css` — tab styles, chip-danger variant, large avatar, resume-text block

### Files added / untouched
Added: none. Untouched: all engines, all 37 remaining legacy pages (byte-verified), all SQL/APIs/auth.

### Features improved
Six-tab workspace: **Overview** (score-composition panel showing exactly how the composite is weighted — every input inspectable; details; applications table), **ATS report** (all 7 evidence dimensions as labeled bars + matched/missing keyword chips + engine recommendations — the Phase 2 evidence-linked display), **Interviews** (history table + latest scored interview with per-category bars and interviewer notes), **Tests** (per-submission cards with pass/fail, marks, per-question correctness and timing), **Timeline** (unified chronological merge of pipeline add, applications, stage events with actors, interviews, ATS scans, test submissions, scoring), **Resume & notes** (notes, skill chips, ATS-parsed resume text). Hero header with avatar, contact, stage, composite chip, quick actions. PDF export contract preserved — now captures light background and temporarily reveals all tabs for a complete report.

### Honest scope mappings
- **AI Summary/Strengths/Weaknesses/Recommended Questions**: no AI-summary table exists; strengths/weaknesses shown are the *interviewer's* notes, explicitly labeled human-entered; the "recommendation" badge is labeled interviewer-entered — per the Phase 2 decision, no fabricated AI hiring recommendation is displayed.
- **Documents / Communications**: no storage tables exist — schema-frozen; joins the assignment/archive backlog.
- **Resume embedded preview**: no file storage exists (`resume_scans.raw_text` is the parsed text); shown as the scanned-text block instead.

### Accessibility & performance
Inline styles **74→1** (sanctioned data-bound width); zero raw hex (radar chart's config colors gone); proper tabs with keyboard nav; all tables headed and mobile-transforming. **Chart.js CDN dropped entirely** (radar → accessible labeled bars); jsPDF/html2canvas retained for the preserved download contract, now `defer`red; two read-only queries added (application events, applications list).

### QA result — PASS
`php -l` clean · shell.js parses · 178/178 tests · top logic + both hoisted SQL strings verified verbatim · 37/37 legacy pages byte-identical.

### Suggested commit message
```
feat(ui): v8 candidate profile — tabbed workspace with explainable
score composition, evidence-linked ATS report, unified timeline (Module 6)

74 inline styles eliminated; Chart.js dependency dropped (radar ->
accessible bars); queries hoisted verbatim; PDF export contract kept
(light bg, all-tabs capture). New reusable ARIA tab component in
shell.js. 178/178 tests; 37 legacy pages byte-identical.
```

---

## 2026-07-18 — Module 7: Interviews

### Audit summary
`interviews.php` (344 lines): solid frozen backend (create with notification + audit log + pipeline sync + candidate email invite, update with completed-sync, delete — all CSRF'd) under a legacy dark table. `score_interview.php` (199 lines): per-question scoring backend (delete-and-reinsert `candidate_responses`, clamp 0–100, auto-complete + pipeline sync). Debts found: 13 + 32 inline styles, 7 unlabeled controls, icon-only edit/delete buttons title-only (no aria-label), no `no-show` filter chip despite the status existing in the schema, no calendar, no sorting, no pagination (every interview rendered), no server-side search, no detail view, scorecard results (`results` table) never surfaced on the interviews page, category header markup emitted a broken nested-ternary CSS var (`--blue'?'accent':'rose'`).
**Doc note:** the Design Bible and Phase 0 documents are not inside this ZIP; the implemented v8 system (tokens.css, prior-module components, charter rules in this log) served as the operative spec, consistent with Modules 4–6.

### Files changed
- `interviews.php` — v8 rebuild; lines 1–89 (POST create/update/delete, list query, candidates fetch, counts) byte-preserved and verified 1:1. Backup: `interviews.php.bak-v7`
- `score_interview.php` — v8 rebuild; lines 1–46 (save-scores endpoint, question/response loads) byte-preserved and verified 1:1. Backup: `score_interview.php.bak-v7`
- `assets/css/cards.css` — Module 7 block appended: calendar (month/week/day/agenda), upcoming strip, segmented view toggle, question-scoring items, sticky score footer (all `--sh-*` tokens, `.sh-v8`-scoped)

### Files added
None (charter reuse rule satisfied: shell, KPI band, chips, toolbar/search, sh-table + data-th mobile cards, slide-over, sh-timeline, sh-badge, sh-score bars, sh_pagination, modals, openModal/closeModal + data-confirm contracts all reused).

### Files untouched
All backend includes, all APIs/SQL/auth/ATS/AI, `results.php` (Results module — separate nav entry, later module), all 35 remaining legacy pages (tree-diff verified byte-identical against the Module 6 ZIP), shell.js, all other CSS files.

### Features improved
- **Interview dashboard**: KPI band (today, next 7 days, completed, no-shows) computed in PHP from the already-fetched set — zero chart deps.
- **List view**: 6-data-column table (candidate w/ role, interviewer, date & time, type, mode, status + latest score inline), row-click detail slide-over, hover row actions (score / edit / delete), "Next up" strip of the 5 soonest scheduled interviews, server-side search (candidate/interviewer/role/type), whitelisted sorting, 15/page windowed pagination, genuine empty states with CTAs.
- **Calendar** (new, server-rendered, zero dependencies): Month grid (Mon-first, today highlight, 3-event cap + "+n more"), Week columns, Day view, 30-day Agenda; prev/next/today navigation; every event is a real `<button>` opening the detail slide-over; the calendar card is the page's single hero surface.
- **Detail slide-over**: status/type/mode/interviewer/when/email/notes, latest scorecard (overall %, four category bars, interviewer recommendation, feedback — surfaced from the `results` table via one batched IN query), timeline (created → scheduled slot → scored → current status), quick actions (score / edit / open candidate).
- **No-show** now a first-class filter chip with count (status existed in schema but had no UI).
- **Schedule/Edit modals**: rebuilt on v8 form components, every field label-paired; email-invite behavior noted in helper text; `openEditIv` contract and all field names preserved.
- **Score interview**: candidate-summary hero, category-grouped question cards (icon, count, points subtotal), difficulty badges, model-answer disclosures, labeled score + note inputs, live score bars, sticky total bar with `aria-live`, empty state linking to the question bank. Broken category-color ternary eliminated with the legacy markup.

### Accessibility improvements
Unlabeled controls 7→0 across both pages (all inputs `for`/`id`-paired or `aria-label`ed); icon buttons all `aria-label`ed with candidate names; inline styles 45→2 (both sanctioned data-bound bar widths); `aria-current` on chips and view toggles; dialog semantics on slide-over and modals; calendar day links carry full date + count labels; decorative icons `aria-hidden`; `aria-live` score total; focus-visible on model-answer summaries; `sh-sr-only` labels on search/sort.

### Responsive improvements
Month grid collapses to an event-day list <768px (empty days hidden — no squeezed 7-column grid on phones); week view stacks <1024px; table→labeled cards on mobile (`data-th`); score grid single-column and score footer unsticks on mobile; toolbar wraps; 44px touch targets inherited.

### Performance improvements
Zero new dependencies; three added read-only queries worst-case (full set when a status filter is active, no-show count, batched results IN query) — KPIs/upcoming/calendar reuse the already-fetched unfiltered set when no filter is applied; pagination trims list DOM from unbounded to 15 rows; search/sort/pagination are presentation-layer over the verbatim default query.

### Known risks
- Interviews aren't linked to jobs in the schema (candidate-level only) — the panel shows the candidate's applied role; a job link needs an approved schema change (joins the assignment/archive backlog).
- Search/sort/pagination operate on the fetched set in PHP (dataset is small; the default DB query is untouched). If interviews grow large, move to SQL LIMIT/OFFSET like Module 4.
- "Interview panel" (multiple interviewers) not implementable — single `interviewer` varchar column; schema-frozen; backlog.
- Loading states: pages are fully server-rendered; no async fetches exist to skeleton. Not applicable rather than omitted.
- The scorecard shown is the latest `results` row per interview; multiple historical results per interview remain visible on `results.php` (unmigrated).

### QA result — PASS
`php -l` clean (interviews, score_interview, layout) · inline JS parsed via Node · unit tests 178/178 · both logic blocks verified byte-identical against backups · full-tree diff vs Module 6 ZIP: only the 3 intended files differ (35/35 legacy pages + all includes byte-identical) · runtime smoke-render with fixtures: list, month, week, day, agenda, search, sort, filter, out-of-range page, and score page all render without PHP warnings; HTML tag-balance check clean · scanner: inline styles 45→2 (sanctioned), unlabeled controls 7→0.

### Suggested commit message
```
feat(ui): v8 interviews module — calendar (month/week/day/agenda), KPI
dashboard, detail slide-over with scorecard + timeline, v8 scoring page
(Module 7)

Backend byte-preserved in both files (schedule/update/delete + email
invite; per-question scoring endpoint). Server-rendered calendar with
zero dependencies; no-show filter surfaced; search/sort/pagination as
presentation layer over the verbatim query; results table surfaced in
the interview panel via one batched query. A11y: 7 unlabeled controls
fixed, 45 inline styles -> 2 sanctioned. 178/178 tests; 35 legacy pages
byte-identical.
```

---

## 2026-07-18 — Module 8A: Assessment Platform Core (architecture, no UI)

### Scope executed (as approved)
"Extend, don't duplicate": the existing interview_questions → question_presets → online_tests → test_questions → test_submissions → test_answers pipeline is now the Assessment Platform Core. No parallel banks, no duplicate submission system. Full design reference: docs/ASSESSMENT_PLATFORM_CORE.md.

### Files added
- `modules/assessment/bootstrap.php` — single require point; engine exposed ONLY via AssessmentService
- `modules/assessment/domain/Entities.php` — Question, QuestionPool, Assessment, Submission, Score, Result (immutable, hydrate from existing rows)
- `modules/assessment/shared/Config.php` — AssessmentConfig layer; defaults ← template.config ← frozen instance snapshot; defaults are legacy-equivalent
- `modules/assessment/shared/Events.php` — EventBus + canonical event catalog; failure-isolated listeners; outbox sink
- `modules/assessment/shared/AiInterfaces.php` — AiQuestionAuthor / AiAnswerEvaluator / AiInsightWriter (contracts only, no impl, AI = suggestions into the human review lane)
- `modules/assessment/shared/Repositories.php` — DbAdapter + GlobalDb + Question/Pool/Template/Assessment/Submission/Outbox repositories + generic PluginRegistry (question_source|delivery|ai_scorer|exporter|webhook)
- `modules/assessment/engine/QTypeRegistry.php` — single type authority; 32 types (2 legacy frozen + 8 core + 9 technical + 6 scenario + 7 future authorable-not-deliverable); new type = one entry
- `modules/assessment/engine/Generator.php` — pure, seedable pool selection with difficulty mix, per-section rules, cross-section dedupe, underfill policy
- `modules/assessment/engine/AssessmentService.php` — the facade: generateFromTemplate (writes online_tests + test_questions only), scoreAnswer, resultFor, events, plugins
- `modules/assessment/scoring/ScoringEngine.php` — ONE scoring path; legacy mcq/subjective branch extracted byte-equivalently; extended strategies (multi-select ± partial credit, true/false, fill-blank, output-prediction, negative marking, floors, bonus) config/registry-gated, OFF by default
- `modules/assessment/results/ResultEngine.php` — overall/section/skill/difficulty/time/question analysis, trend, recommendation bands, strengths/weaknesses/suggestions, pending-review awareness
- `modules/assessment/migrations/001_assessment_platform_core.sql` — additive + idempotent: JSONB metadata/answer_key/skills/status on the bank; pool status/tags; assessment_templates + sections; online_tests.template_id + frozen config; test_questions.section_id; test_answers.response; assessment_plugins; assessment_events outbox; CHECK constraints on question types retired in favour of the registry; rollback notes included
- `tests/assessment_core_tests.php` — 84 tests
- `docs/ASSESSMENT_PLATFORM_CORE.md` — architecture reference

### Files changed
- `take_test.php` — 13-line surgical diff (backup: take_test.php.bak-m7): bootstrap require, service + frozen-config init, both inline scoring blocks now call the engine, one failure-isolated submission.scored event. Every URL, POST contract, redirect and DB write unchanged.
- `tests/run_tests.php` — one-line require of the 8A test file (backup kept)

### Files untouched
Every other page and include, all SQL in database/, questions.php, online_tests.php, view_test_result.php, test_complete.php, candidate portal — byte-identical (tree-diff verified against the Module 7 ZIP).

### Regression proof (the heart of 8A)
1. **Unit parity matrix**: ScoringEngine vs a byte-copied reference of the pre-8A inline logic across mcq correct/wrong/blank/'0'-truthy-edge/blank-key and subjective cases — identical (marks, is_correct, selected) write triples.
2. **Full write replay**: an identical simulated submission (correct MCQ + wrong MCQ + essay) executed through the OLD and NEW take_test.php against a recording DB stub — all 8 legacy writes byte-identical (answer rows, totals/percentage update, pipeline sync, notification); the new path adds exactly one additive assessment_events outbox write.
3. **AJAX path replay**: correct/wrong/blank autosaves — byte-identical writes.
4. **Suite**: 262/262 (178 existing + 84 new), zero existing tests modified.
5. Pre-migration DBs keep working: absent config column ⇒ legacy defaults (verified in replay — stub had no config column value).

### Known risks / notes
- The migration must be applied before 8B features are used; until then the engine runs in pure legacy-parity mode (safe by construction).
- New auto-scorable types need answer_key columns in take_test's fetch queries — deliberately deferred to 8C (delivery module); until then such questions fall to the manual review lane (safe default).
- view_test_result.php still recomputes HR totals with its own inline loop; routing it through ResultEngine is 8C scope.
- mt_srand seeding is process-global; acceptable for generation determinism, revisit if parallel generation ever matters.

### QA result — PASS
php -l on all 13 touched/added PHP files · bootstrap standalone-load verified · 262/262 tests · old-vs-new write replays byte-identical on both modified code paths · tree diff vs Module 7 ZIP shows only intended files.

### Suggested commit message
```
feat(assessment): Assessment Platform Core — domain/config/events/repos/
service layers, type registry (32 types), generator, unified scoring +
result engines, plugin registry, AI interfaces, additive migration
(Module 8A)

Extend-don't-duplicate: existing test pipeline promoted to platform core.
take_test.php scoring extracted byte-equivalently (13-line diff) — parity
proven by unit matrix + full old-vs-new DB write replay. 262/262 tests.
No UI changes; all URLs and workflows preserved.
```

---

## 2026-07-18 — Module 8B: Enterprise Assessment Center

### Files added
- `assessment_center.php` — thin root entry point (URL-stable): auth, CSRF, PRG action dispatch, per-view data orchestration — every operation is an AssessmentService call, zero SQL, zero business logic
- `modules/assessment/admin/` — `_shell.php` (header/tabs/shared bar-chart + tone helpers), `dashboard.php`, `bank.php`, `pools.php`, `templates.php`, `generator.php`, `reviews.php`, `results.php`, `_search_modal.php` (Ctrl+K modal + the center's single behavior script: debounced search, bulk bar, type-driven editor fields, section-row cloning)
- `tests/assessment_center_tests.php` — 43 workflow tests over an in-memory DbAdapter

### Files modified (all additive; backups `.bak-8a` included)
- `modules/assessment/shared/Repositories.php` (+413/−1) — 8B workspace methods on the existing repositories (bank search w/ server-side pagination, usage counts, save/duplicate/status; pool stats/clone/merge/dependencies; template list/save/clone; review queue + totals; results list) + new `AdminStatsRepository` (dashboard aggregates, global search, candidate options). The −1 is `find()` delegating to the new `findRow()` — behavior-identical, test-proven.
- `modules/assessment/engine/AssessmentService.php` (+301/−0) — the Assessment Center facade: dashboard, bank, pools, templates, generator preview (dry-run of the same engine), review recompute (auto+hr rule), AI suggestion via `AiAnswerEvaluator` plugins, results + CSV builder, global search
- `modules/assessment/shared/Events.php` (+4) — `question.created/updated`, `pool.created/changed`
- `assets/css/cards.css` — 8B block: KPI-5 grid, CSS bar charts, builder/wizard, review answers, Ctrl+K list, print stylesheet (tokens only)
- `includes/layout.php` (+2) — "Assessment Center" nav entry in both v8 and legacy sidebars
- `tests/run_tests.php` (+1 require)

### Architecture decisions
- Strict layering enforced: UI → AssessmentService → repositories → core → DB. The one initial exception (candidate picker) was routed through the service before shipping.
- Existing repositories were EXTENDED, not shadowed by parallel "admin repositories" — honoring both "core is complete" and "no duplicated repositories".
- Generator preview is a dry run of the SAME Generator the issue path uses — preview and generation can't drift.
- Manual review recompute uses the platform's established auto-marks + hr_marks rule and dispatches `review.completed`; the legacy `view_test_result.php` reviewer keeps working unchanged in parallel until 8C retires it.
- Legacy `questions.php` / `online_tests.php` remain untouched and linked (import path); the center is the forward surface.

### UI decisions
Server-rendered everything (wizard steps, calendar-style states, Ctrl+K results) — no client state to lose, JS is progressive enhancement only. One behavior script for the whole center. Slide-over editor reuses the v8 pattern with registry-driven per-type answer-key fields. Charts are token-styled CSS bars (zero chart dependencies).

### Accessibility
All 40+ form controls label-paired; sr-only labels on filters; `aria-current` on tabs; dialog semantics on the search modal + slide-over; `aria-live` on search results; keyboard shortcut with visible affordance (button + kbd hint) and Esc close; row-action buttons carry entity-named aria-labels; decorative icons hidden; reduced-motion + focus-visible inherited from the v8 shell.

### Performance
Server-side pagination on the bank (COUNT + LIMIT/OFFSET); batched IN queries for usage/pool enrichment; one dashboard payload call; debounced (450 ms) search auto-submit; blank global search short-circuits before any query; no duplicate queries (filters reuse one WHERE builder).

### QA
305/305 tests (178 legacy + 84 8A + 43 8B, zero prior tests modified) · php -l on all 21 touched/added files · 16 view states smoke-rendered against a stubbed DB with zero PHP warnings · HTML tag-balance clean on the five richest renders · additive-only diffs verified per core file.

### Regression report (Module 8A behavior)
- Old-vs-new `take_test.php` write replay RE-RUN after the service extensions: all 8 legacy writes byte-identical; only the additive outbox write differs. take_test.php itself untouched in 8B (tree-diff verified).
- All 84 Module 8A tests pass unmodified; scoring parity matrix intact.
- Tree diff vs the 8A ZIP: only the files listed above differ.

### Known issues
- Template version history / compare needs a versioning table — deferred (clone-as-draft covers the workflow meanwhile).
- Pool dependency shown as a used-by list, not a rendered graph.
- "Excel" export is CSV (Excel-opens-it); true .xlsx would need a library — deliberately avoided.
- Reviewer assignment (assign/bulk-assign) needs a reviewer column on test_answers — queue is shared FIFO for now; schema addition proposed for 8C.
- Bank pagination is server-side; pools/templates/results lists render fully (small datasets) — flip to paginated queries if they grow.

### Rollback notes
Remove `assessment_center.php` + `modules/assessment/admin/` + the two nav lines and the product is exactly Module 8A (core extensions are additive and unused without the center). `.bak-8a` files restore the 8A core verbatim if ever needed.

### Suggested commit message
```
feat(assessment): Enterprise Assessment Center — dashboard, question bank,
pools, template builder, generator wizard w/ dry-run preview, review queue,
results workspace, Ctrl+K global search (Module 8B)

Strict UI→AssessmentService layering (zero SQL in pages). Core extended
additively (+718 lines across repos/service/events). 305/305 tests; 8A
write-parity replay re-verified; take_test.php untouched.
```

## 2026-07-20 — Module 8C: Candidate Assessment Player

The candidate-facing delivery experience for the Assessment Platform: a
server-authoritative, resumable, registry-driven test player plus a config-gated
result page. Consumes the 8A core through a new Player API on `AssessmentService`;
touches the core **additively only** (no existing 8A/8B logic modified).

### Files added
- `modules/assessment/migrations/002_candidate_delivery.sql` — additive/idempotent player-state columns (`deadline_at`, `current_q`, `nav_state`, `reconnects`, `last_seen_at` on submissions; `updated_at`, `review_flag` on answers; partial index `idx_ts_deadline`).
- `modules/assessment/shared/AntiCheat.php` — config-driven proctoring signal vocabulary + `normalise()`; log-only.
- `modules/assessment/engine/QuestionRenderer.php` — registry-driven per-input-kind answer widgets.
- `modules/assessment/candidate/player.php` — pre-assessment acknowledgment gate + full-screen player view.
- `modules/assessment/candidate/_error.php` — shared accessible error/notice page.
- `assets/js/assessment-player.js` — single behavior file (timer sync, offline-safe autosave queue, nav/flags/per-Q timers, fullscreen, anti-cheat, keyboard nav).
- `assets/css/assessment-player.css` — standalone player + result stylesheet (own `--apc-*` tokens, light/dark, reduced-motion, focus-visible, responsive).
- `tests/assessment_player_tests.php` — 60 Player-API / AntiCheat / QuestionRenderer tests.

### Files modified (all additive; backups included)
- `modules/assessment/bootstrap.php` — two `require_once` for AntiCheat + QuestionRenderer.
- `modules/assessment/engine/AssessmentService.php` (`.bak-8b`) — Player API block: `openAttempt`, `autosave`, `saveNav`, `recordProctoring`, `secondsRemaining`, `submitAttempt`, `candidateResult`.
- `modules/assessment/shared/Repositories.php` (`.bak-8b`) — SubmissionRepository player methods (findByToken, activeAttempt/completedAttempt, startAttempt, questionsForTest, questionForAnswer, savedAnswers, autosaveAnswer, saveNavState, bumpProctoring, secondsRemaining, finalizeSubmission).
- `modules/assessment/shared/Events.php` — `PROCTORING_SIGNAL` constant.
- `take_test.php` (`.bak-8b`) — **rewritten** from the 477-line monolith to a 134-line thin entry point (URL/token contract preserved; JSON API endpoints `autosave|nav|proctor|ping`; submit re-scores via `submitAttempt`; legacy `ajax_save` shim kept; exact legacy pipeline side-effects preserved).
- `test_complete.php` (`.bak-8b`) — **rewritten** to consume `candidateResult($sid)` with config-gated visibility + PDF print.
- `tests/run_tests.php` — wired the 8C test file after the 8B require.

Tree-diff vs the 8B ZIP confirms **zero removed/changed lines** in every core
file (`bootstrap`, `AssessmentService`, `Events`, `Repositories`) — purely
additive. `take_test.php`/`test_complete.php` are web-root entry points (not
core) and are backed up.

### Architecture decisions
- **Server-authoritative timing.** `deadline_at` stamped once at start; client clock is display-only and re-syncs to server `remaining` on every autosave/nav/proctor/ping. Server independently auto-submits expired attempts.
- **Player API as the only surface.** The candidate pages never bypass `AssessmentService`; timing, attempt validation, autosave scoring, and submission re-scoring are all server-side.
- **Submission re-scores server-side.** `submitAttempt` recomputes every question from saved answers through the same ScoringEngine — client-reported totals are never trusted.
- **Registry-driven renderers.** `QuestionRenderer` dispatches on the QTypeRegistry `input` field; new types need no player changes. Non-deliverable inputs degrade to textarea.
- **Anti-cheat is log-only + config-driven.** Policy (log_signals / violation_signals / fullscreen_required / auto_submit_after) merges under `AssessmentConfig`; signals never hard-block.
- **Config-gated result visibility** via `candidate_result` (none|score|full; default score) read through the existing config passthrough — no core change.
- **`candidate_portal.php` left as working legacy** (deliberate): already renders the full dashboard (assigned/active/in-progress/completed/expired, badges, score pills, deadlines, resume/start via token URL, result links). Its direct-SQL reads are backlog, not rewritten — avoids regression risk for zero functional gain (8B precedent).

### Accessibility
ARIA roles on radiogroups/checkbox groups/timer/dialog/question map; `aria-pressed` flags; `aria-live` for save/net/toast status; keyboard nav (←/→ move, `f` flags, focus moves to question content on nav); visible `:focus-visible` rings; `prefers-reduced-motion` disables all transitions/animations; labelled inputs; acknowledgment gate is keyboard-operable.

### Security
CSRF on every POST (`_csrf` field or `X-CSRF-Token` header via `require_csrf()`); all queries parameterised; attempt/token ownership checked in `openAttempt`; result ownership checked in `test_complete.php`; server-authoritative timer + attempt state (no client-trusted timing or totals); anti-cheat and re-scoring performed server-side; answers escaped on render (XSS test included).

### Performance
Debounced autosave (900 ms) with per-question de-dupe; offline queue flushes on reconnect (last-write-wins server-side); minimal JSON payloads; 25 s reconciliation ping; single external JS/CSS (cacheable); question map + per-Q timers are O(n) client-side only.

### QA
60 new 8C tests; **full suite 365/365** (305 baseline + 60). Smoke-tested: intro gate, full player (all input types via registry, saved-answer hydration, flag/nav restore, 0 HTML mismatches, valid boot JSON with server `remaining`), all 4 error states, all 4 JSON API endpoints, and `test_complete.php` in score/full/none modes + ownership guard (0 HTML mismatches). PHP lint + JS `new Function()` validation clean.

### Regression report
- All 305 prior tests pass unmodified (178 legacy + 84 8A + 43 8B).
- Core files verified additive by tree-diff (zero removed/changed lines).
- Scoring parity preserved: `submitAttempt` and `autosave` route through the same ScoringEngine as 8A/8B; multi_select/mcq/subjective scoring matrix intact.
- Legacy pipeline side-effects preserved on submit (candidate application advance, `online_tests.status=completed`, `candidates.ai_score` GREATEST, HR notification).

### Known issues / limitations
- `candidate_portal.php` still reads assessment tables with direct SQL (backlog — not rewritten this module).
- Anti-cheat is signal-logging only; no webcam/screen proctoring (out of scope).
- `media/canvas/file/external` input kinds render as textarea placeholders until dedicated widgets ship.
- `updated_at` last-write-wins uses server `NOW()`; true client-timestamp conflict resolution deferred (queue de-dupe + server GREATEST on time_spent already prevent the common races).

### Rollback notes
Restore `take_test.php.bak-8b` + `test_complete.php.bak-8b`, revert the four core files to `.bak-8b` / remove the additive blocks, drop the new files, and (optionally) roll back migration 002 with the documented `ALTER TABLE … DROP COLUMN` statements. The player degrades gracefully even with migration 002 applied, so a code-only rollback is safe.

### Suggested commit message
`feat(assessment): Module 8C — server-authoritative candidate player (resume, autosave, anti-cheat, registry-driven delivery) + config-gated results; core touched additively only; 365/365 tests`

### Handbook v9 compliance pass (Module 8C)

Reviewed Module 8C against the SmartHire V9 Handbook — Volume 6B (Assessment
Execution & Candidate Workflow, 6B-009→6B-018), the Chapter 7–9/13 standards, and
the Chapter 16 Definition of Done. Three findings in 8C-owned code were closed;
all additive.

**Findings fixed**
- **Ch9 "No SELECT *" + 6B-012** — `SubmissionRepository::questionsForTest` used `iq.*`. Replaced with an explicit column list (question/options/type/marks/difficulty/skills/limits for rendering; correct_option/answer_key/metadata/max_score kept server-side for re-scoring only). Tightens the query and the security surface.
- **Ch8 "Log: Assessment" + 6B-018 "Audit Log"** — submission wrote a notification but no audit entry. Added `audit_log('assessment_submitted'|'assessment_auto_submitted', 'test_submission', $sid, …)` on submit. No secrets recorded (Ch8).
- **6B-010 "Improve timeout logging"** — auto-submit now logs distinctly (`assessment_auto_submitted`) for timer observability.

**Verified compliant (no change needed)**
- 6B-009 Session lifecycle — Player API is the single source of truth (open/start/active/completed/finalize), ownership-validated, expired-blocked, one active attempt, metadata loaded once per request.
- 6B-010 Timer — server-authoritative `deadline_at`; client display-only; re-syncs every response (drift + reconnect recovery); reliable auto-submit.
- 6B-011 Autosave — debounced/de-duped/offline-queued/retried; ownership checked per save; answer format preserved.
- 6B-013 Submission pipeline — validate → session-verify → persist → confirm; question+attempt ownership; duplicates blocked; server re-scores.
- 6B-015 Progress — answered/remaining/time/percentage computed server-side once, tracked incrementally client-side.
- 6B-017 Export — result print-to-PDF is ownership- and config-gated, no sensitive fields.
- Ch7 Error handling — structured JSON / error page, never echo+die, no SQL/stack/secret exposure.
- Ch13 Security — CSRF on every POST, ownership checks, server-authoritative state, parameterized SQL.

**6B-012 security regression locked in** — 3 new tests assert `QuestionRenderer` never emits `correct_option`/`answer_key`/`metadata` even though it receives the full row. Rendered player HTML + boot JSON scanned: zero answer-key exposure (the `answered` boot field carries question **ids** only).

**Definition of Done (Ch16) — all met:** compiles, lint-clean, existing behavior intact, new behavior tested, layout responsive, security strengthened (not weakened), DB compatible (additive migration + explicit columns), Neon/Postgres SQL verified (INTERVAL / EXTRACT EPOCH / JSONB / ON CONFLICT), uploads + `includes/mailer.php` untouched (unchanged vs 8B), docs updated.

**Regression:** full suite **368/368** (365 + 3 new 6B-012 tests). Core files remain additive vs 8B.

## 2026-07-21 — Module 9: Interview Management (Phase 1 of 4)

New development workstream (post-8C). Builds a domain-driven Interview module
under `modules/interview/` following the handbook's Controller → Service →
Repository → Validator standard (Vol1 Ch4/5/6), mirroring the assessment
platform's proven pattern. **Backward compatible**: `interviews.php` keeps its
URL, POST contract, side-effects, flash messages, and redirects; the SQL is
byte-identical to the legacy INSERT/UPDATE/DELETE.

**Phase plan:** (1) domain foundation + write-path *(this phase)*; (2) read-path +
calendar-query consolidation; (3) scoring + timeline (`score_interview.php`);
(4) hardening (pagination, DoD, ZIP).

### Files added
- `modules/interview/Db.php` — local `DbAdapter` + `GlobalDb` (injectable seam over the global db helpers; kept module-local to keep interview/assessment decoupled).
- `modules/interview/domain/Interview.php` — immutable entity + `fromRow()`.
- `modules/interview/InterviewRepository.php` — all `interviews` SQL (explicit columns, parameterized; Ch9). Includes `conflicts()` for double-booking detection.
- `modules/interview/InterviewValidator.php` — centralized scheduling validation → `{success, errors, data}` (Ch6).
- `modules/interview/InterviewService.php` — the facade: validation → conflict check → persistence → side-effects; `::production()` wires the real globals.
- `modules/interview/bootstrap.php` — single require point.
- `tests/interview_tests.php` — 35 tests (validator, repository, service flows, conflict/validation logic, side-effect firing + order, composite score, DB-failure path).

### Files modified
- `interviews.php` — the three write handlers (create/update/delete) now delegate to `InterviewService`; the page only maps the result to a flash + redirect. 682 → 650 lines. Read/render section untouched.
- `tests/run_tests.php` — wired in the Module 9 test file.

### Improvements delivered (from 6A-005 / handbook)
- **Scheduling validation** — required fields, valid date/time, enum checks; create rejects past dates (update allows them, for editing history).
- **Double-booking conflict detection** — same interviewer + date + time (excluding self on update, ignoring cancelled) is blocked with a clear message instead of being written.
- **Consistent audit logging** (6A-015) — create/update/delete all now `audit_log`; previously only create did.

### Behavior change (intentional, documented)
Valid, non-conflicting input behaves exactly as before (same INSERT, same notification/stage-advance/invite-email/audit, same flash + redirect). Invalid input or a double-booking is now rejected with a flash message rather than written — the intended 6A-005 improvement. No URL, schema, or data-format change.

### QA
Full suite **403/403** (368 prior + 35 new), no warnings. All interview files lint-clean; module loads; `::production()` wiring verified. SQL parity with the legacy write path confirmed by inspection (identical columns + type strings). `interviews.php` read/render path unchanged.

### Backward compatibility
URL, POST contract, side-effects, flash text, and redirects preserved; `interviews` schema untouched; no change to `score_interview.php` (Phase 3). Legacy behavior for valid input is identical.

### Next (Phase 2)
Move the list/KPI/upcoming/calendar reads into the repository and collapse the 5 separate COUNT queries into one grouped query (6A-022); `interviews.php` read section delegates. Awaiting approval to proceed.

## 2026-07-21 — Module 9: Interview Management (Phase 2 of 4)

**Summary.** Extracted the interview **read-path** out of `interviews.php` into the
Interview module and consolidated the status tallies. The board list and the
KPI/calendar full-set now come from `InterviewService`/`InterviewRepository`, and
the five separate `COUNT(*)` round-trips are replaced by a single grouped query
(handbook 6A-005 "reduce duplicate calendar queries" / 6A-022 performance). Purely
a data-access refactor — the rendered board, filters, search, sort, pagination,
and calendar are byte-for-byte unchanged.

**Files added.** None.

**Files modified.**
- `modules/interview/InterviewRepository.php` — added `listWithCandidate(?status)` (explicit joined columns, reproduces the legacy `i.* + candidate fields` exactly) and `statusCounts()` (one `GROUP BY status` query returning all/scheduled/completed/cancelled/no-show; unknown statuses still counted in `all`).
- `modules/interview/InterviewService.php` — added thin facade passthroughs `listing(?status)` and `statusCounts()`.
- `interviews.php` — read-path now calls the service: `$interviews`, `$allIvs`, `$counts`, `$noShowCount` are populated via the service instead of six inline queries. Candidate-dropdown read left inline (candidate domain, future `CandidateRepository`). Search/sort/pagination and the scorecard read (Phase 3) untouched. 650 → 646 lines.
- `tests/interview_tests.php` — FakeDb routes the two new reads; 11 Phase 2 tests added.

**Database changes.** None (no schema, no migration). Same tables, same columns; one fewer query pattern.

**Backward compatibility.** Fully preserved. `$interviews` returns the same rows in the same order; `$counts` holds the same four keys with the same values (now typed int — renders identically); `$allIvs`/`$noShowCount` unchanged; the no-filter "reuse the list, zero extra query" optimization is retained. No route, API, template, or workflow change.

**Tests executed.** 11 new (statusCounts totals + stable key shape + unknown-status handling; listWithCandidate unfiltered/filtered/empty; service passthroughs). Equivalence spot-checked against a fake dataset.

**Security review.** No change to the security surface. New reads are parameterized and column-explicit (Ch9 "no SELECT *"); the extracted list drops the legacy `i.*`. No new inputs, no new outputs, GET-only read-path.

**Performance review.** Read-path DB round-trips cut from ~7 (1–2 list + 5 counts) to ~2–3 (1–2 list + 1 grouped count) per board load — a fixed −4 queries every page view (6A-005/6A-022).

**Regression results.** Full suite **414 / 414** (403 + 11). Lint clean. Core (assessment) files untouched.

## 2026-07-21 — Module 9: Interview Management (Phase 3 of 4)

**Summary.** Added Interview **Scoring, Timeline, Decision Workflow, and Feedback**
as backend capabilities, and extracted `score_interview.php`'s write-path into the
service (no scoring logic left in the controller, per spec Part 1). All business
logic lives in `InterviewService`; every mutation appends an immutable timeline
entry and an audit-log record. No UI redesign, no route/API changes.

**Files Added.**
- `modules/interview/InterviewWorkflow.php` — pure rulebook: score categories, recommendation/decision enums, timeline-action vocabulary, `canChangeStatus()`, `clampScore()`.
- `modules/interview/migrations/003_scoring_timeline.sql` — additive: `interview_scorecards`, `interview_timeline` (append-only + index), `interview_feedback`. Zero changes to existing tables.

**Files Modified.**
- `InterviewRepository.php` — `replaceQuestionScores`, `markCompleted`, scorecard `scorecard`/`saveScorecard`/`ensureScorecard`/`updateDecision`, timeline `addTimelineEvent`/`timeline`, feedback `feedback`/`saveFeedback`. Explicit columns, parameterized.
- `InterviewValidator.php` — `validateScore` (categories 0–10, averaged overall, recommendation required) and `validateFeedback` (summary required).
- `InterviewService.php` — `saveQuestionScores` (refactored legacy path), `saveScorecard`, `recordDecision` (recorded-vs-changed, finalization lock, auto `moved_to_offer`), `submitFeedback`, `addTimelineEvent`, read facades, and a completed↛scheduled transition guard in `reschedule`.
- `modules/interview/bootstrap.php` — loads `InterviewWorkflow`.
- `score_interview.php` — POST write-path delegates to `InterviewService::saveQuestionScores` (byte-identical behavior + audit + timeline); render untouched.
- `tests/interview_tests.php` — FakeDb routes the new tables; 51 Phase 3 tests.

**Database Changes.** One additive migration (3 new module-owned tables). No existing table altered; `interviews`, `results`, `candidate_responses` untouched. The legacy per-question flow and scheduling/calendar keep working with or without migration 003 (timeline writes on the legacy path are best-effort).

**Backward Compatibility Verification.** `score_interview.php` produces the same DB writes (replace responses → mark completed → advance), same flash, same redirect. No URL/route/API/UI change. Scheduling, calendar, search, sort, pagination, dashboard, and the assessment modules are untouched. Full prior suite (414) passes unchanged.

**Security Review.** CSRF preserved on `score_interview.php`; RBAC (`requireRole('interviewer')`) unchanged. All new SQL is parameterized and column-explicit (Ch9 "no SELECT *"). Validators gate every write; enums reject invalid recommendation/decision/action; finalization locks further edits. Bad ids rejected. Actor captured via `currentUser()`.

**Performance Review.** No N+1: scorecard/timeline/feedback are single-row upserts or one indexed append; `timeline()` is one indexed read (`idx_iv_timeline_interview`). The refactor removed a redundant `candidate_id` re-fetch from the legacy score path (−1 query).

**Tests Added.** 51 — workflow enums + transitions + clamp; validateScore/validateFeedback; saveQuestionScores (writes/advance/audit/timeline/bad-id); saveScorecard (save/averaged overall/audit/invalid/finalization-lock); recordDecision (invalid/recorded/changed/finalize/post-finalize lock/moved_to_offer); submitFeedback; addTimelineEvent + immutable ordering; reschedule transition guard.

**Full Test Results.** 465 / 465 passed. Lint clean across all changed files.

**Regression Results.** All 414 prior tests pass unchanged; diff vs the Phase 2 ZIP shows only the intended files; assessment core untouched; ZIP re-verified 465/465 from a clean extract.

**Remaining Work for Phase 4.** Wire the new capabilities into the UI (scorecard form + timeline view + decision controls + feedback panel on the interview detail / score page); apply the shared pagination helper to interview listings if needed; run the full Definition-of-Done checklist; produce the final Module 9 full-project ZIP.
