# SmartHire ATS V2 — Build A Report (Intelligence Layer)

> Phase 1 (audit + architecture) delivered in `docs/ATS_V2_AUDIT_AND_ARCHITECTURE.md`, then Build A implemented per its roadmap. **52 PHP files lint-clean · 177/177 unit tests (53 new) · zero regressions on live PostgreSQL (integration 20/21 known, email 10/10).**

## Base adoption & repo fixes
- The uploaded `SmartHire-v7-main` repo (with out-of-session "Phase 2" improvements: CSP hardening, PDO timeout, server-side SQL aggregation, `phase2_indexes.sql`) was **adopted as the new source of truth** and verified end-to-end.
- **Critical repo bug found & fixed during audit:** the setup SQL seeded `must_change_pw` into `users`, but the column existed only on `candidates` → fresh installs aborted with an **empty database**. Fixed by adding the column to `users` (matching the seed's documented intent); clean install re-verified.

## Files Added
| File | Purpose |
|---|---|
| `includes/ats_ontology.php` | **Skill ontology** (pure data): ~90 canonical skills with aliases + categories (ReactJS→react, K8s→kubernetes, postgres→postgresql…), 16-family certification catalog, 8 job-title equivalence families, action-verb / weak-word / buzzword lexicons. |
| `includes/ats_engine.php` | **V2 engines** (pure, tested): skill normalization + free-text detection (word-boundary aware); **JD parser** (required vs preferred, years, education, certs); **semantic skill match** (exact/alias = full credit, same-category related = 50% transferable credit, per-skill tier reported); **certification engine**; **quality v2** (action verbs, weak phrases, buzzwords, passive voice, quantified achievements, repeated words — each with reasons/deductions/suggestions); **experience v2** (years × title-family relevance); **configurable weighted scoring** (`SH_ATS_WEIGHTS_JSON` override, normalized to 100) where every component returns `{score, weight, points, reasons[], deductions[], suggestions[]}`; **recruiter insights + High/Medium/Low priority banding**; spec's 5-band grade colors. |
| `docs/ATS_V2_AUDIT_AND_ARCHITECTURE.md` | Phase 1 deliverable: honest V1 audit (11 findings incl. the installer bug), strengths, commercial gap map, open-source takeaways, justified V2 architecture, roadmap. |

## Files Modified
| File | Change |
|---|---|
| `includes/ats.php` | `sh_full_ats_report()` now attaches a `v2` section — **all V1 keys preserved** (backward compatible; `ats_report.php`, rankings, and stored DB scores unaffected). |
| `database/SmartHire_v7_PostgreSQL_Setup.sql` | Installer bug fix (`users.must_change_pw`). |
| `tests/run_tests.php` | +53 assertions across ontology, JD parser, semantic match, certs, quality, experience, weights/scoring, integration. |

## What V2 fixes from the audit
| Audit finding | Resolution |
|---|---|
| W1 No ontology/synonyms | ✅ Ontology + alias resolution + related-category credit |
| W2 No JD parser | ✅ `sh2_parse_jd` (required vs preferred, exp, edu, certs) |
| W3 Hardcoded weights | ✅ `sh2_weights()` config-driven, normalized |
| W4 No per-score explanations | ✅ every component returns reasons/deductions/suggestions |
| W5 No certification engine | ✅ 16-family catalog, matched/missing vs JD |
| W6 Shallow quality engine | ✅ verbs/weak-words/buzzwords/passive/quantified/repeated |
| W8 No title equivalence | ✅ 8 title families; Backend Engineer ≈ Software Developer |
| W11 Installer bug | ✅ fixed + verified |

## Design guarantees (verified by tests)
- **Explainability:** component points sum to the overall score (±1 rounding); each point traceable to a stated reason.
- **Determinism:** pure functions, no external APIs, no randomness.
- **Backward compatibility:** V1 report keys, DB columns, and `sh_final_score` contract untouched; existing pages keep working unchanged.
- **Configurability:** weight override tested (`{"skills":50}` → renormalized, sum stays 100).

## Testing (actually run)
```
Lint:        52/52 PHP clean · JS valid
Unit:        177/177  (124 prior + 53 V2)
Regression:  live-PG integration 20/21 (known test-script assertion) · email 10/10
Live smoke:  real application row → v2 overall/grade/priority/9 components,
             semantic matched/related/missing, explanation strings present
Config:      SH_ATS_WEIGHTS_JSON override verified
```

## Remaining (per roadmap — honest)
- **Build B:** render the V2 sections in `ats_report.php`/candidate view (explanations, transferable-skill panel, certification panel, quality detail, recruiter insights + priority, animated score counter), candidate improvement roadmap page, Excel/CSV ranking export, PDF report v2, parsed-text caching.
- **Build C:** structured field extraction surfaced in UI, keyword placement analysis, accessibility + performance passes, final self-review (the spec's 16 questions) + V3 roadmap.

The intelligence layer — the hard, correctness-critical part — is done, tested, and integrated without breaking anything.
