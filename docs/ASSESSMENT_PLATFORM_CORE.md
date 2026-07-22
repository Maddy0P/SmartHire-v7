# SmartHire Assessment Platform Core — Architecture (Module 8A)

**Status:** Core shipped (8A). UI arrives in 8B (Assessment Center), delivery of new
question types + result surfaces in 8C.

## Principle: extend, don't duplicate

The pre-existing pipeline **is** the platform:

| Domain concept | Table (existing) | Notes |
|---|---|---|
| Question (bank, single source of truth) | `interview_questions` | + `metadata`, `answer_key`, `skills`, `status` (migration 001) |
| QuestionPool | `question_presets` / `question_preset_items` | M:N — a question can live in many pools |
| Assessment (issued instance) | `online_tests` | + `template_id`, frozen `config` snapshot |
| Assessment questions | `test_questions` | + `section_id` |
| Submission (attempt) | `test_submissions` | unchanged |
| Answer + review lane | `test_answers` | + structured `response` JSONB; `hr_marks` = manual lane |
| Template (new) | `assessment_templates` + `assessment_template_sections` | reusable blueprints |
| Plugins (new) | `assessment_plugins` | generic registry |
| Events (new) | `assessment_events` | persistent outbox |

There is **no parallel question bank and no duplicate submission system.**

## Layers

```
entry points (root PHP files — URLs frozen)
        │  require modules/assessment/bootstrap.php
        ▼
AssessmentService  ←— the ONLY public surface (facade)
        │
 ┌──────┼───────────┬─────────────┬──────────────┐
 ▼      ▼           ▼             ▼              ▼
Config  Generator   ScoringEngine ResultEngine   EventBus ──► outbox + listeners
 (rules) (pure)     (one path)    (pure)         (event-driven core)
        │
        ▼
Repositories (all SQL lives here) ──► DbAdapter ──► existing dbFetch*/dbExecute
        ▼
Domain entities (Question, QuestionPool, Assessment, Submission, Score, Result)
```

* **QTypeRegistry** (`engine/QTypeRegistry.php`) — single authority on question
  types (32 registered: 2 legacy + core + technical + scenario + future).
  Adding a type = one registry entry. DB CHECK constraints were retired in its
  favour; `isValid()` is the write-path gate. Future types (video, whiteboard,
  labs…) are authorable but `deliverable=false` until their widget/plugin lands.
* **AssessmentConfig** — no hard-coded rules. Merge chain:
  `defaults ← template.config ← online_tests.config` (the instance snapshot is
  frozen at generation, so editing a template never re-scores live candidates).
  Defaults reproduce legacy behaviour exactly.
* **ScoringEngine** — one scoring path for everything. The legacy
  `take_test.php` mcq/subjective logic was extracted **byte-equivalently**
  (proven by a unit-test parity matrix against a copied reference AND a full
  old-vs-new write replay). Extended strategies (multi-select partial credit,
  true/false, text match, exact output, negative marking, floors, bonus) are
  registry/config-gated and OFF by default.
* **ResultEngine** — overall/section/skill/difficulty/time/question analysis,
  attempt trend, recommendation bands, strengths/weaknesses/suggestions.
  Manual-lane answers use `hr_marks` when present, otherwise count as pending.
* **EventBus + outbox** — engine dispatches domain events
  (`assessment.generated`, `submission.scored`, …) to in-process listeners and
  the `assessment_events` outbox. Listener failures are isolated. Future
  notifications/analytics/workflows subscribe; the engine never changes.
* **PluginRegistry** — rows declare integrations
  (`question_source | delivery | ai_scorer | exporter | webhook`), runtime
  `bind()` attaches adapters. HackerRank / Codility / TestGorilla / Bedrock /
  OpenAI / Claude / GitHub become a row + an adapter class.
* **AI interfaces** (`shared/AiInterfaces.php`) — `AiQuestionAuthor`,
  `AiAnswerEvaluator`, `AiInsightWriter`. Contracts only; AI output is always a
  suggestion routed into the human review lane, never a silent final score.

## What changed in product code (8A)

Exactly one page: `take_test.php` (13-line diff). Bootstrap require, service +
frozen-config init, the two inline scoring blocks now call
`AssessmentService::scoreAnswer()`, and one failure-isolated
`submission.scored` event after scoring persists. Every URL, POST contract,
redirect, and DB write is unchanged (replay-verified). Pre-migration databases
keep working: absent columns simply yield legacy-default config.

## Applying the migration

```
psql "$DATABASE_URL" -f modules/assessment/migrations/001_assessment_platform_core.sql
```
Idempotent, additive, transactional; rollback notes at the bottom of the file.

## Adding things later (the point of all this)

* **New question type** → one `QTypeRegistry` entry (+ widget in 8C's delivery layer).
* **New assessment kind** (SQL test, cloud test, aptitude…) → a template + pools. No code.
* **New scoring rule** → config key + strategy branch; defaults stay legacy.
* **New integration** → plugin row + adapter implementing the relevant interface.
* **New consumer** (Slack alert, analytics) → subscribe to events or drain the outbox.

---

# Module 8B — Enterprise Assessment Center (consumes the core)

**Entry point:** `assessment_center.php?view=dashboard|bank|pools|templates|generator|reviews|results`
(root file for URL stability; all rendering in `modules/assessment/admin/`).
**Rule enforced:** UI → AssessmentService → repositories → core → DB. Zero SQL in
pages, zero business logic in views; the entry point only orchestrates facade
calls and PRG redirects.

| Surface | What it does | Facade methods |
|---|---|---|
| Dashboard | 10 KPI cards, 7 server-computed CSS bar charts, quick actions | `dashboard()` |
| Question Bank | search + type/difficulty/status/pool filters, server-side pagination, sorting, bulk add-to-pool, duplicate, archive/restore, inline status, usage counts, pool chips, slide-over editor with per-type answer-key fields (registry-driven) | `bankSearch()`, `saveQuestion()`, `setQuestionStatus()`, `duplicateQuestion()` |
| Pools | create, clone, merge(+archive source), archive, question/difficulty/skills stats, used-by-templates dependency view | `poolsOverview()`, `createPool()`, `clonePool()`, `mergePools()`, `addQuestionsToPool()`, `poolDetail()` |
| Templates | list w/ structure+rules+issued counts, builder (basics → sections w/ pool + count + difficulty mix + optional time → rules incl. negative marking, partial credit, randomization, attempts, expiry, certification), clone-as-draft, publish/archive | `templateList()`, `saveTemplate()`, `cloneTemplate()`, `setTemplateStatus()` |
| Generator | 4-step wizard: template → pools/difficulty (from template) → configuration → dry-run preview (question count, marks, est. time, difficulty split, skill coverage, per-section shortfall warnings) → issue to candidate | `previewFromTemplate()` (dry run), `generateFromTemplate()` (persist) |
| Review Queue | pending manual-lane submissions (oldest first), per-answer workspace with candidate answer, clamped score entry, reviewer note, instant total recompute, AI-suggestion slot consuming `AiAnswerEvaluator` via the plugin registry (placeholder text when none bound) | `reviewQueue()`, `reviewWorkspace()`, `recordManualScore()`, `aiSuggestionFor()` |
| Results | submitted list + full ResultEngine breakdown (overall/sections/skills/difficulty/time/question analysis/trend/recommendation/insights), CSV export (opens in Excel), print stylesheet for PDF | `resultsList()`, `resultFor()`, `resultCsv()` |
| Global search | Ctrl+K modal across questions/pools/templates/assessments/candidates/results (server-rendered, grouped) | `globalSearch()` |

**Events added (8B):** `question.created/updated`, `pool.created/changed` —
every center write dispatches through the 8A bus + outbox.
**Scope notes:** template *version history/compare* and a graph-rendered pool
dependency view need versioning schema that doesn't exist yet — deferred with
the used-by list standing in for dependencies; XLSX export is served as CSV
(Excel-compatible) to keep the zero-dependency rule; "Excel" button says CSV.

---

# Module 8C — Candidate Assessment Player (consumes the core)

**Entry points (web-root, URL-stable):**
`take_test.php?token=…` (the player — pre-assessment gate, delivery, JSON API, submit)
and `test_complete.php?sid=…` (submission confirmation + config-gated result).
Both are thin orchestrators; every operation flows UI → AssessmentService (Player
API) → repositories → core → DB. Zero business logic and (for the assessment
surface) zero SQL in the pages.

**Delivery pipeline (all through `AssessmentService`):**

| Step | Facade method | Guarantee |
|---|---|---|
| Open / resume | `openAttempt(token, candId)` | Validates token+ownership; blocks completed/expired; lazily starts one in-progress attempt; returns the full render bundle (questions, saved answers, nav state, remaining seconds, effective proctoring policy). Error codes: `invalid`, `expired`, `completed`, `no_questions`, `start_failed`. |
| Autosave | `autosave(...)` | Scores auto-scorable types immediately through the **same ScoringEngine**; defers manual types (marks 0, pending HR); last-write-wins on `updated_at`; dispatches `answer.saved`; returns fresh server `remaining`. |
| Navigation | `saveNav(sid, current, flags)` | Persists cursor + review flags to `nav_state` for cross-refresh resume. |
| Proctoring | `recordProctoring(sid, signals, policy)` | Normalises via `AntiCheat`, bumps violation/reconnect counters, dispatches `proctoring.signal` per event. **Log-only — never blocks.** |
| Time check | `secondsRemaining(sid)` | Reads the **server** deadline (`EXTRACT(EPOCH FROM deadline_at - NOW())`); the client clock is display-only. |
| Submit | `submitAttempt(sid, test, candId, auto)` | **Re-scores every question server-side** from saved answers (never trusts client totals), finalises status (`submitted`/`auto_submitted`), and emits the scored event. |
| Result | `candidateResult(sid)` | Returns the ResultEngine breakdown for the candidate view. |

**Server-authoritative timing.** `deadline_at` is stamped **once** at attempt
start (`startAttempt`) from the template duration and never trusts the client
again. The player's JS counts down locally for responsiveness but re-syncs to the
server `remaining` on every autosave / nav / proctor / 25 s ping response, so
refresh, reconnect, crash, and clock-tampering all reconcile to the server. At
zero the client submits with `auto_submit`; the server also independently
auto-submits any attempt whose `secondsRemaining` ≤ 0.

**Registry-driven rendering.** `QuestionRenderer::render($q, $saved)` dispatches
on the QTypeRegistry `input` field (choice / multichoice / boolean / text /
rating / code / textarea), not on hardcoded per-type branches. Adding a question
type = a registry entry (+ one renderer method only for a brand-new input kind).
Non-deliverable input kinds (media/canvas/file/external) degrade safely to a
textarea. Scalar answers post as `ans_<qid>`; structured answers (multi-select)
post as JSON `resp_<qid>` — both consumed unchanged by the existing scoring path.

**Anti-cheat (`shared/AntiCheat.php`).** Fixed signal vocabulary
(tab_switch, window_blur, fullscreen_exit, reconnect, refresh, copy/paste_attempt,
rapid_submit). The proctoring **policy is config-driven** (merged under
`AssessmentConfig` from `AntiCheat::defaultPolicy()` + the instance's `proctoring`
key): `log_signals` (what's captured), `violation_signals` (what counts),
`fullscreen_required`, `auto_submit_after`. Signals are logged and surfaced to HR;
they never hard-block a candidate.

**Config-gated result visibility.** `test_complete.php` reads the instance config
key `candidate_result` (`none` | `score` | `full`; default `score`) through the
existing config passthrough — **no core change**. `none` hides the score,
`score` shows the ring + marks + outcome, `full` adds section/skills bars,
strengths, recommendation and print-to-PDF. Ownership is enforced
(`submission.candidateId === currentCandidate`).

**Persistence (migration `002_candidate_delivery.sql`, additive + idempotent).**
`test_submissions` gains `deadline_at`, `current_q`, `nav_state` (JSONB),
`reconnects`, `last_seen_at`; `test_answers` gains `updated_at`, `review_flag`;
partial index `idx_ts_deadline`. The player degrades gracefully if the migration
is applied late (duration-from-start timing, no cross-refresh flag recovery).

**Assets.** Standalone `assets/css/assessment-player.css` (own `--apc-*` tokens,
light+dark via `prefers-color-scheme`, reduced-motion, focus-visible, responsive)
and `assets/js/assessment-player.js` (one behavior file: timer sync, offline-safe
autosave queue, nav/flags/per-question timers, fullscreen, anti-cheat, keyboard
nav). The player is a full-screen page **outside** the v8 app shell by design.

**Events added (8C):** `proctoring.signal` (+ reuses `submission.started`,
`answer.saved`, and the scored event) — all through the 8A bus + outbox.

**Deliberate scope decisions.** `candidate_portal.php` is **left as working
legacy**: it already renders the full candidate dashboard (assigned/active/
in-progress/completed/expired with status badges, score pills, deadlines,
resume/start via the token URL the new player handles natively, and result links).
Its direct-SQL reads are noted as backlog rather than rewritten through the
service, to avoid regression risk for zero functional gain — matching the 8B
precedent of not disturbing working legacy pages. The legacy monolithic
`take_test.php` is preserved as `take_test.php.bak-8b`.

**Core-modification rule compliance.** Every core touch in 8C is **additive**:
`bootstrap.php` (two requires), `AssessmentService.php` (Player API block),
`Events.php` (one constant), `Repositories.php` (SubmissionRepository player
methods). Tree-diff vs the 8B ZIP shows **zero removed/changed lines** in all
core files — no existing 8A/8B logic was modified. The rewritten `take_test.php`
and `test_complete.php` are web-root entry points, not core, and are backed up.

**Handbook v9 compliance (6B).** Module 8C was reviewed against Volume 6B
(Assessment Execution & Candidate Workflow) and the Ch16 Definition of Done.
`questionsForTest` was made explicit-column (Ch9 "No SELECT *"); submission now
writes an audit-log entry, with auto-submit logged distinctly (Ch8 / 6B-010 /
6B-018); and a security regression asserts the renderer never leaks
`correct_option`/`answer_key` to the candidate (6B-012). All changes additive;
suite 368/368.
