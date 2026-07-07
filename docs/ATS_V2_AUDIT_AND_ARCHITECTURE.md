# SmartHire ATS Version 2 — Audit, Architecture & Roadmap
*(Phase 1 deliverable — produced before implementation, as required)*

---

# PART A — ATS V1 AUDIT (honest)

## A1. Current architecture (as-is)
```
Resume upload (careers.php / candidates.php / resume_scanner.php)
  → store_resume_upload()            [config.php — finfo MIME + whitelist + 3MB + random name]
  → extract_resume_text()            [resume_parser.php — PDF Flate/Tj, DOCX zip→XML, DOC binary, TXT]
  → sh_create_application()          [recruitment.php — auto-runs ATS on apply]
      → sh_ats_breakdown()           [skill/experience/education/quality → weighted composite]
  → sh_full_ats_report()             [ats.php — keywords, formatting, readability, compat,
                                      strengths/weaknesses, recommendation, probabilities]
  → ats_report.php / print_ats_report.php   [recruiter dashboard + PDF view]
  → applications.php ranking · recruitment_analytics.php aggregates
```
Pure functions throughout (124 unit tests), no external APIs, PostgreSQL-backed.

## A2. Strengths (keep, build on)
1. **Clean separation** — parsing / matching / scoring / presentation are already distinct pure modules; V2 can extend rather than rewrite.
2. **Transparent sub-scores** — skill/exp/edu/quality/keyword/formatting/readability each surfaced with bars; nothing is a black box.
3. **Deterministic + tested** — same input → same score; every scoring function has unit coverage; integration-tested on live PostgreSQL.
4. **Fail-safe integration** — auto-ATS on apply, event log, recruiter ranking, analytics, emails; a scoring failure never breaks the apply flow.
5. **Security posture** — extracted text length-capped and escaped on output; uploads validated by content (finfo), not extension alone.

## A3. Weaknesses / hardcoded / placeholder logic (the honest list)
| # | Finding | Where | Severity |
|---|---|---|---|
| W1 | **No skill ontology** — `ReactJS`≠`React`, `JS`≠`JavaScript`, `PostgreSQL` earns no credit for a "SQL" requirement. Pure substring matching. | `sh_skill_match`, `sh_missing_skills` | **High** — the #1 accuracy gap |
| W2 | **No JD parser** — jobs rely on the recruiter's `skills_required` CSV; description/requirements text is only used for generic keyword frequency. Required vs preferred is not distinguished. | recruitment.php / ats.php | **High** |
| W3 | **Hardcoded weights** — 0.4/0.25/0.15/0.2 inside `sh_ats_breakdown()`, 0.6/0.4 in `sh_final_score()`. Spec requires configurable. | recruitment.php | **High** (explicit spec violation) |
| W4 | **Scores don't explain deductions** — the report shows *what* scored low but not *why points were lost* per component. | ats.php | Medium |
| W5 | **No certification engine** — certifications are ignored entirely. | — | Medium |
| W6 | **Quality engine is shallow** — binary "has numbers / has sections" checks; no action-verb, weak-word, passive-voice, buzzword, or repeated-word analysis (Resume-Worded's core value). | `sh_formatting_score` | Medium |
| W7 | **No structured resume extraction** — parser produces raw text only; name/email/links/education/titles are not extracted as fields. | resume_parser.php | Medium |
| W8 | **Experience detection is regex-only** (`N years`); job-title equivalence (Software Engineer ≈ Backend Developer) absent. | `sh_years_experience` | Medium |
| W9 | **Probabilities are uncalibrated logistic curves** — illustrative, not validated (documented, but still a limitation). | ats.php | Low (disclosed) |
| W10 | **Stopword list is hardcoded** (`SH_STOPWORDS`), fine but not configurable. | ats.php | Low |
| W11 | **Repo installer bug (found during this audit):** seed inserted `must_change_pw` into `users` but the column existed only on `candidates` → fresh install aborted with an empty DB. | Setup SQL | **Critical** — **FIXED in this build** (column added to `users`, matching the seed's documented intent; verified by clean install + full suite) |

## A4. Performance & security (ATS-specific)
- Parsing/scoring is O(text) pure PHP — fast at resume scale; re-parse on each report view (W12: cache parsed text per application — acceptable now, worth caching in V2 integration).
- No N+1 in ATS paths; ranking uses indexed queries; Phase-2 indexes (adopted from repo) cover notification/analytics hot paths.
- Extracted text: capped 20k chars, always escaped via `e()` on display — no stored-XSS path found. Upload validation solid.

## A5. Commercial feature comparison (gap map)
| Capability | Jobscan | Resume Worded | SkillSyncer | SmartHire V1 | V2 target |
|---|---|---|---|---|---|
| Keyword match + missing | ✔ | ✔ | ✔ | ✔ | ✔ keep |
| Skill normalization/synonyms | ✔ | ✔ | ✔ | ✖ | **✔ ontology** |
| Related/transferable skills | ~ | ~ | ✔ | ✖ | **✔ category credit** |
| Required vs preferred | ✔ | — | ✔ | ✖ | **✔ JD parser** |
| Per-score explanations | ✔ | ✔ | ✔ | partial | **✔ every component** |
| Certifications | ✔ | ~ | ✔ | ✖ | **✔ engine** |
| Action verbs / weak words / passive voice | — | ✔ | — | ✖ | **✔ quality v2** |
| Configurable weights | (internal) | (internal) | (internal) | ✖ | **✔ config array** |
| Recruiter priority banding | ✔ | — | — | partial | **✔ insights** |

## A6. Open-source engineering takeaways (studied, not copied)
- **Resume-Matcher:** treat matching as an *ensemble* of independent signals combined by weights — adopted as V2's core design.
- **pyresparser / Resume-Parser:** dictionary+pattern extraction (skills lists, section headers, contact regexes) is robust without ML — adopted for structured extraction.
- **OpenResume:** section-completeness as a first-class score and ATS-safe formatting checks — adopted in quality engine.

---

# PART B — ATS V2 ARCHITECTURE

**Design rule: extend, never duplicate.** V1's tested functions remain the primitives; V2 adds layers around them. One ATS, one report object, fully backward-compatible keys.

```
                       ┌──────────────────────────────────────────┐
resume text ──────────▶│ ats_ontology.php   SKILL ONTOLOGY        │  data layer (pure arrays)
job row ──────────────▶│  canonical skills · aliases · categories │  aliases → canonical
                       │  related tech · title equivalence        │  canonical → category
                       │  certifications · action/weak verbs      │
                       └──────────────┬───────────────────────────┘
                                      ▼
                       ┌──────────────────────────────────────────┐
                       │ ats_engine.php     V2 ENGINES (pure)     │
                       │  sh2_normalize_skills   (ontology map)   │
                       │  sh2_parse_jd           (req vs pref,    │
                       │                          exp/edu/certs)  │
                       │  sh2_skill_match        (exact→alias→    │
                       │                          related credit) │
                       │  sh2_certifications     (match/missing)  │
                       │  sh2_quality            (verbs, weak     │
                       │                          words, passive, │
                       │                          buzzwords, nums)│
                       │  sh2_experience         (titles+years)   │
                       │  sh2_score              (CONFIGURABLE    │
                       │                          weights + per-  │
                       │                          component WHY)  │
                       └──────────────┬───────────────────────────┘
                                      ▼
                sh_full_ats_report()  — single report object (v1 keys preserved,
                                        v2 sections added: explanations, certs,
                                        transferable, quality detail, insights)
                                      ▼
        ats_report.php · print_ats_report.php · applications.php · analytics
```

**Key decisions & justifications**
1. **Ontology as data, not code** — a PHP constant array (`SH_SKILLS`) mapping alias→canonical and canonical→category. Justification: deterministic, zero-dependency, unit-testable, editable by a non-expert; exactly how SkillSyncer-class tools start.
2. **Weights in configuration** (`SH_ATS_WEIGHTS`, overridable via `config.local.php`/env) with normalization to 100. Justification: spec mandate; enables future per-company profiles (V3) with no schema change.
3. **Three-tier skill credit** — exact/alias = 100%, same-category related = 50% (transferable), absent = 0, with each skill's tier reported. Justification: mirrors how human recruiters credit adjacent experience, and keeps every point explainable.
4. **JD parser is additive** — parses `description`+`requirements` for skills/experience/education/certs and *merges* with the recruiter's `skills_required` (recruiter input stays authoritative for "required"). Justification: no behavior change for existing jobs; richer signal where JD text exists.
5. **Every component returns `{score, weight, points, reasons[], deductions[], suggestions[]}`** — explanation is a return value, not an afterthought. Justification: spec's explainability mandate; the UI just renders it.
6. **Backward compatibility contract:** `sh_ats_breakdown()` keys and DB columns (`ats_score`, `skill_match`, …) unchanged; V2 recomputes them via the new engine so stored scores stay comparable. No schema migration required.
7. **V3-ready seams:** weights profile parameter, ontology as swappable dataset, per-component engine functions — AI feedback/multi-language/company profiles plug in without restructuring.

## Implementation roadmap
- **Build A (this build):** ontology + JD parser + semantic skill/cert/quality/experience engines + configurable explainable scoring + integration into the report object + unit/integration tests. *(The intelligence layer.)*
- **Build B (next):** dashboard upgrade to render V2 sections (explanations, cert panel, transferable skills, quality detail, recruiter insights, priority bands), candidate improvement roadmap view, Excel/CSV export of rankings, PDF report v2, animated score counter, parsed-text caching.
- **Build C (final):** structured field extraction surfaced in UI (auto-fill candidate profile), keyword placement analysis, accessibility pass, performance pass, full regression + release report.
