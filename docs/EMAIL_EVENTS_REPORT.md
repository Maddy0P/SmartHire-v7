# SmartHire v7 — Email Events: Final Wiring Report

Both previously-unwired workflow emails are now connected to their triggers, using the **existing** mailer (`includes/mailer.php`), the **existing** templates, and the **existing** transport (`log` by default, `smtp`/`php` when configured). No mailer, template, or transport code was created or changed. The only files modified were the two trigger handlers (plus two unit-test assertions).

## What was changed (scope-limited)
| File | Change |
|---|---|
| `online_tests.php` | Added `require_once 'includes/mailer.php'` and, inside the `create` handler after the test + questions are saved, one call: `sh_email_candidate($candidateId, 'test_assigned', ['job' => <test title>])`. |
| `interviews.php` | Added `require_once 'includes/mailer.php'` and, inside the `create` handler after scheduling, one call: `sh_email_candidate($candidateId, 'interview_invite', ['job' => 'a <Type> round', 'extra' => 'Scheduled for <date> at <time> (<mode>).'])`. |
| `tests/run_tests.php` | +2 assertions covering the `test_assigned` and `interview_invite` templates. |

**Fail-safe behaviour (verified):** `sh_email_candidate()` → `sh_email_event()` → `sh_mail()` never throws — `sh_mail()` wraps the transport in try/catch, logs failures via `sh_log_error()`, and returns `false`; `sh_email_event()` audit-logs the attempt (with a `(FAILED)` marker on failure). An unknown/mis-addressed candidate returns `false` with no exception, so the create workflow always completes and the user is never interrupted.

---

## Complete Email Event Map

Every event defined in `sh_mail_template()` and where it is triggered in the codebase:

| # | Email Event | Template key | Recipient | Trigger location (PHP file · handler) | Status |
|---|---|---|---|---|---|
| 1 | Application Confirmation | `application_confirmation` | Candidate | `careers.php` · apply handler (after `sh_create_application`) | Wired (Build 4) |
| 2 | Shortlisted | `shortlisted` | Candidate | `applications.php` · `shortlist` action | Wired (Build 4) |
| 3 | **Test Assigned** | `test_assigned` | Candidate | **`online_tests.php` · `create` handler (after test + questions saved)** | **Wired (this build)** |
| 4 | **Interview Invitation** | `interview_invite` | Candidate | **`interviews.php` · `create` handler (after schedule + pipeline sync)** | **Wired (this build)** |
| 5 | Offer Released | `offer_released` | Candidate | `application_detail.php` · `release_offer` action | Wired (Build 4) |

### Templates that exist for future/optional use
These templates are defined in `sh_mail_template()` and are available to any caller; they are not tied to a single mandatory trigger in the current workflow (in-app notifications already cover them). Listed for completeness:

| Template key | Intended recipient | Notes |
|---|---|---|
| `selected` | Candidate | Optional — send on move to `selected` if desired. |
| `rejected` | Candidate | Optional — neutral rejection copy; some teams prefer manual sending. |
| `new_application` | Recruiter | Optional recruiter alert; in-app notification already fires on apply. |
| `ats_completed` | Recruiter | Optional recruiter alert. |
| `offer_accepted` | Recruiter | Optional — in-app notification fires when a candidate accepts. |
| `interview_completed` | Recruiter | Optional recruiter alert. |
| `security_alert` / `audit_alert` / `system_alert` | Admin | Available for admin/ops notifications. |

The five **candidate-facing lifecycle emails (rows 1–5)** — the ones the workflow depends on — are now **fully connected end to end**.

---

## Verification (run against a live PostgreSQL 16 instance)
```
PHP lint:        all files — no syntax errors
Unit tests:      124 / 124 passed  (includes new test_assigned + interview_invite template checks)
Email wiring integration (live DB):  10 / 10 passed
  ✔ test_assigned template renders (existing template, unchanged)
  ✔ interview_invite template renders (existing template, unchanged)
  ✔ sh_email_candidate(test_assigned)     → true, delivered to log transport
  ✔ sh_email_candidate(interview_invite)  → true, delivered to log transport
  ✔ both emails written to logs/mail.log for the real candidate address
  ✔ audit_logs contains email_test_assigned and email_interview_invite
  ✔ unknown candidate → returns false, NO exception (workflow uninterrupted)
```

**Transport used:** whatever `SH_MAIL_TRANSPORT` is set to — `log` (default; writes to `logs/mail.log`), `php` (`mail()`), or `smtp` (PHPMailer). The two new triggers go through the exact same `sh_mail()` path as the existing three, so switching to SMTP in production enables real delivery for all five with no further code changes.

## Confirmation
Every candidate-facing workflow email event is now fully connected. Nothing outside these two integrations (and their test coverage) was modified.
