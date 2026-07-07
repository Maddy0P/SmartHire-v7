# SmartHire ATS V2 — Build B Report (Enterprise Experience Layer)

> Builds on Build A's intelligence layer. **53 PHP files lint-clean · 177/177 unit tests · zero regressions (live-PG integration 20/21 known, email 10/10).**

## Files Added
| File | Purpose |
|---|---|
| `candidate_ats_report.php` | **Candidate-facing ATS report** — animated V2 score + grade, per-component breakdown (with the weight each area carries), an **Improvement Roadmap ordered by score impact** (highest-weight suggestions first), missing required skills, transferable-skill reassurance ("your MySQL counts toward PostgreSQL"), missing job keywords, and JD-requested certifications. **IDOR-safe**: the application is queried `WHERE a.id=? AND a.candidate_id=?`; read-only; all output escaped. |

## Files Modified
| File | Change |
|---|---|
| `ats_report.php` | Recruiter dashboard now renders the full **V2 intelligence**: engine score card with grade color + **High/Medium/Low priority band** and recommended action; a **"Score Breakdown — every point explained"** table (component score × configurable weight = points, with + reasons and − deductions per row); **Semantic Skill Analysis** panel (matched incl. aliases, transferable 50%-credit pairs with category, missing, preferred hits/misses, bonus skills); **Certifications** panel (found / JD-requested-missing); **Writing Quality (V2)** panel (action verbs, quantified achievements, weak phrases, buzzwords, passive voice + suggestions); **Recruiter Insights** (tagged judgments, each with its justification). All V1 sections retained above — nothing removed. |
| `my_applications.php` | Each application now links to **View ATS Report** (the candidate report). |
| `applications.php` | **CSV export** of the current ranked view (respects active job/stage/search/sort filters; audit-logged; streams before any HTML). Export button added beside the List/Pipeline toggle. |
| `assets/js/v7.js` | **Animated score counter** for `[data-count]` elements (ease-out cubic, ~0.9 s), automatically disabled under `prefers-reduced-motion`. |

## Spec coverage delivered in Build B
- Overall ATS score with **animated counter, grade band, and the spec's 5-color system** (green/blue/yellow/orange/red).
- **Detailed score breakdown** — every component with progress, percentage, weight, points, explanation, and improvement suggestions.
- **Job description comparison** — matched / missing / extra / transferable skills, preferred hits, keyword gaps, certification comparison.
- **Recruiter dashboard** — priority banding (High/Medium/Low) with recommended action, tagged insights with justification, CSV export of rankings.
- **Candidate dashboard** — score, quality, missing keywords, skills analysis, and an impact-ordered improvement roadmap.
- **Accessibility/performance** — counters and bars honor reduced-motion; all new panels use the existing responsive component classes (stacked tables, wrap grids); zero new dependencies (pure CSS/JS/SVG).

## Verification (actually run)
```
Lint:        53/53 PHP · JS valid (node --check)
Unit:        177/177
Regression:  live-PG integration 20/21 (known assertion) · email 10/10
Security:    candidate report verified IDOR-scoped; CSV export recruiter-only + audit-logged
```

## Remaining (Build C — honest)
PDF report v2 (extend `print_ats_report.php` with the V2 sections), parsed-text caching per application, structured field extraction surfaced in the UI, keyword placement analysis, final accessibility/performance audit, the spec's 16-question self-review, and the V3 roadmap document.
