# SmartHire v7 — RC3 Bug Fix Pass

**Scope of this pass:** I read through the app (PHP pages, `includes/`, `assets/css`, `assets/js`, DB schema/seed) and fixed the concrete, reproducible bugs from your list. I did **not** attempt the full "redesign every page like Notion/Linear/Stripe" enterprise UI pass, the analytics chart-type-switcher, or a page-by-page UX audit in this same session — that's genuinely a much bigger job than a bug-fix pass, and bolting it on top risked exactly the kind of regressions you told me to avoid. Below is exactly what changed and why, so you can decide what to tackle next.

Also, important caveat: I don't have a browser or your live database in this environment, so this is a **static code read + targeted fix**, not click-through QA. I traced each bug to a concrete root cause in the code before changing anything — I did not touch code I couldn't explain.

---

## 1. Notifications — "Mark All Read" → blank screen

**Root cause:** The button was a plain `<a href="?mark_all=1">` link (a GET request). `require_csrf()` only ever validates **POST** requests — for GET it silently returns and does nothing, despite the code comment claiming it protected the action. So the mutation itself ran with no real CSRF protection, and the subsequent `header('Location: notifications.php'); exit;` had no fallback: if output had already started for any reason (slow DB round-trip, a warning, anything writing to the response before that line), the redirect silently fails and `exit` leaves the browser staring at nothing — display_errors is off in production, so any fatal error anywhere in that path would look identical: a blank white screen with nothing logged to the UI (only to `logs/`).

**Fix:**
- `notifications.php` — "Mark All Read" is now a real `POST` form with a CSRF token, exactly like the existing "delete notification" action on the same page. It also uses the app's `redirect()` helper (which detects `headers_sent()` and falls back to a JS redirect) instead of a raw `header()+exit`.
- `includes/config.php` — added a `register_shutdown_function` that catches true fatal errors (the kind that bypass `set_exception_handler`, e.g. execution-time-limit or out-of-memory) and renders the existing friendly error page instead of leaving the response empty. This is a systemic fix: it protects **every** page, not just this one, against ever silently going blank in production.

**Files:** `notifications.php`, `includes/config.php`

---

## 2/3/6. Add Candidate / Schedule Interview / Add Question modals opening low, not centered

**Root cause:** The `.modal-overlay` used `position:fixed; inset:0;` with flexbox centering — correct in principle, but with no explicit `top/right/bottom/left` fallback and no `margin:0` reset. On top of that, `.modal { max-height:90vh }` had no matching `margin:auto`, so anything that defeated the flex alignment (older WebViews without full `inset` support, a modal taller than the viewport, etc.) left the modal pinned to the top of its box instead of centered.

**Fix (one shared component, so it's a single change that fixes all three modals — and every other modal in the app, since they all reuse `.modal-overlay`/`.modal`):**
- Added explicit `top:0; right:0; bottom:0; left:0;` alongside `inset:0`, plus `width/height/margin:0`.
- Added `overflow-y:auto` on the overlay so an oversized modal scrolls the overlay instead of overflowing the viewport.
- Added `margin:auto` on `.modal` itself as a belt-and-suspenders centering guarantee.

**File:** `assets/css/main.css`

---

## 4. Online Tests — "Select Question Category Preset" incomplete / not loading

**Root cause:** This one wasn't a code bug — `question_presets` / `question_preset_items` are real tables in `SmartHire_v7_PostgreSQL_Setup.sql`, and the PHP/JS that reads and renders them (`online_tests.php`) is correct. But **`database/demo_seed.sql` never inserted any rows into those two tables.** So on any freshly-seeded database, the picker only ever has the "No Preset" fallback option — which looks exactly like "presets aren't loading."

**Fix:** Added a guarded seed block to `demo_seed.sql` that creates one preset per existing question category (Technical, HR, Behavioral, System Design, Coding, MCQ/Aptitude) and populates `question_preset_items` from the questions already seeded in the bank. Guarded with an `IF EXISTS` check so re-running the seed file is safe.

**File:** `database/demo_seed.sql` *(re-run this against your DB — a code fix alone won't backfill existing deployments)*

---

## 5. Question Bank — "Add Question" broken under "All Questions" / "Technical" filter

**Root cause:** Two real issues here:
- The category `<select>` in the Add/Edit Question modal was missing the `mcq` option, even though the DB `CHECK` constraint allows it and the filter cards on the same page show an MCQ-adjacent category — so adding a question in some contexts had no valid category to submit.
- Create/Update/Delete redirected unconditionally to `questions.php` (no filter), discarding whatever category card you had open. After adding/editing/deleting a question while filtered to "Technical," you'd land back on the unfiltered list — which reads as "the question I just added/edited isn't there" i.e. "doesn't work."

**Fix:**
- Added the missing `mcq` option to both the Add and Edit question forms.
- Added a `return_cat` hidden field to the create, update, and delete forms; the backend now redirects back to `questions.php?cat=<same filter>` instead of always resetting to "All Questions." Also switched these to the safe `redirect()` helper.

**File:** `questions.php`

---

## 7/8. Results & ATS Scanner — score number squeezed inside the progress bar

**Root cause:** The shared `.score-bar` component is a flex row: `[track][number]`. The number (`.score-text`) only had `min-width:28px` — not enough for values like `100%` or fraction labels like `20/25` used on the Resume Scores page. Worse, several call sites set an **inline** `style="min-width:65px"` / `70px"` on the whole container, which (inline always wins over the stylesheet) squeezed track+gap+number into ~65-70px total — too tight for the number to render without wrapping onto/over the bar.

**Fix:**
- `.score-text`: raised `min-width` to `44px`, added `white-space:nowrap` and `flex-shrink:0` so it can never wrap or get compressed.
- `.score-bar-track`: given its own `min-width:36px` and bumped height from 6px→7px to match the ATS report's bar style.
- Widened the too-tight inline `min-width` overrides: `results.php` (65px→100px, 70px→110px) and `candidate_resumes.php` / Resume Scores table (70px→120px, three columns).

**Files:** `assets/css/main.css`, `results.php`, `candidate_resumes.php`

---

## 9. Mobile responsiveness

The existing responsive CSS only had three breakpoints (1024/768/480) covering the sidebar drawer and a couple of grids. I extended it rather than replacing it, so nothing on desktop changed:

- **Page header** now stacks (title/subtitle above the action button) instead of squeezing onto one row.
- **Buttons in page headers/modal footers** go full-width on mobile so they're easy to tap and don't force horizontal scroll.
- **Cards/stat cards** get tighter padding on phones instead of desktop-sized padding.
- **Filter bars / search inputs** wrap and go full-width instead of overflowing.
- **Modals** use nearly the full viewport width/height on mobile with reduced inner padding, and their footer buttons stack on very small screens (≤390px).
- **Tables** get smaller header/cell padding and font size under 480px, while keeping the existing horizontal-scroll container (I did not rebuild tables into card-lists — that's a bigger structural change than a CSS pass, flagged below).
- Added an explicit ≤390px breakpoint for small phones (iPhone SE-class) on top of the 480px one.

**File:** `assets/css/main.css` (`.min.css` regenerated to match — see note below)

**Not done / worth a follow-up:** true per-page mobile treatment for Analytics (chart canvases), the Question Bank manual-selection list, and Online Tests' two-column preset grid — these have inline layout logic (`style="display:grid;grid-template-columns:1fr 1fr"` etc.) baked directly into the PHP templates rather than CSS classes, so a proper mobile pass on them means editing each page's markup, not just the stylesheet. I'd rather scope that as its own follow-up than rush it.

---

## Build note

`renderHead()` loads `assets/css/main.min.css` / `v7.min.css`, not the unminified sources. I regenerated both `.min.css` files from the edited sources after every CSS change (spot-checked the trickier selectors — the `select` background-image data-URI and the new modal/score rules — to confirm the regen didn't corrupt anything). I did not touch `main.js`/`v7.js` or their `.min.js` builds; none of the fixes above required a JS change.

---

## What I'd flag before you call this "production-polished"

1. **Live QA needed** — I can't run a browser or your Postgres/Neon instance here, so please click through the notifications page, the three modals, Question Bank filtering, and a couple of mobile widths (360/375/768) before treating this as verified.
2. **Re-run `demo_seed.sql`** (or just the new preset block) against your actual database — the code fix for bug #4 does nothing until the seed data exists there.
3. **The bigger asks in your brief** — enterprise-level visual redesign inspired by Notion/Linear/Stripe, the analytics chart-type switcher (Line/Bar/Pie/Doughnut/Area/Radar/Scatter/Heatmap), and a full page-by-page UX audit — are real, multi-session projects on their own. Happy to scope and start on any one of them next; I'd suggest picking the one that matters most for what you're using this for (portfolio demo vs. actual production use), since they pull in different directions.

## Files changed
```
notifications.php
questions.php
results.php
candidate_resumes.php
includes/config.php
assets/css/main.css
assets/css/main.min.css   (regenerated)
database/demo_seed.sql
```
