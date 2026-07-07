# SmartHire v7 — Deployment & Production Guide

A step-by-step guide to deploying SmartHire on a production server, plus a hardening checklist. SmartHire runs on **PHP 8+ and PostgreSQL** (Neon cloud database recommended; any Postgres 12+ works).

---

## 1. Requirements
- PHP **8.0+** with extensions: `pdo_pgsql`, `pgsql`, `mbstring`, `zip`, `fileinfo`, `openssl`
- PostgreSQL **12+** (or a Neon serverless Postgres database)
- Apache (with `mod_rewrite`, `.htaccess` enabled) or Nginx

## 2. Install
1. Copy the project into your web root (e.g. `htdocs/smarthire` or `/var/www/smarthire`).
2. Create the database and load the schema (single file — all 22 tables + seeds):
   ```bash
   psql "$DATABASE_URL" -f database/SmartHire_v7_PostgreSQL_Setup.sql
   # or locally:  createdb smarthire && psql smarthire -f database/SmartHire_v7_PostgreSQL_Setup.sql
   ```
3. Create your environment file:
   ```bash
   cp includes/config.local.example.php includes/config.local.php
   ```
   Edit it: set DB credentials, `SH_DEBUG=false`, `SH_HTTPS=true` (if using TLS), and mail settings.
4. Ensure writable dirs: `logs/` and `uploads/resumes/` (e.g. `chmod 775`, owned by the web user).
5. Visit the site and sign in as the seeded owner `admin@smarthire.com` (promoted to **super_admin** by the migration). **Change this password immediately** via *Profile → Change Password*, and create real staff accounts via *Super Admin → Add Staff User*.

## 3. Enabling real email (optional)
Notifications work out-of-the-box with the **log** transport (emails written to `logs/mail.log`). To send real email:
- **PHP mail():** set `SH_MAIL_TRANSPORT = 'php'` (needs a working MTA/sendmail).
- **SMTP (recommended):**
  ```bash
  composer require phpmailer/phpmailer
  ```
  Then in `config.local.php` set `SH_MAIL_TRANSPORT = 'smtp'` and the `SH_SMTP_*` constants. The mailer auto-detects PHPMailer; if absent it safely falls back to the log transport.

## 4. Web-server hardening
**Apache** — the project ships `uploads/.htaccess` (blocks script execution in uploads). Add a root `.htaccess` to protect internals:
```apache
# Deny direct access to includes, logs, database, tests
RedirectMatch 403 ^/.*/(includes|logs|database|tests)/.*$
<FilesMatch "\.(sql|log|md)$">
  Require all denied
</FilesMatch>
```
**Nginx** — equivalent `location` blocks:
```nginx
location ~* /(includes|logs|database|tests)/ { deny all; }
location ~* \.(sql|log|md)$ { deny all; }
```

## 5. Security posture (built-in)
Already enforced by the application (Builds 1–4):
- Security headers (CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy; **HSTS when `SH_HTTPS=true`**).
- Hardened sessions (HttpOnly, SameSite=Lax, Secure on HTTPS, idle timeout, regeneration on login).
- CSRF on every state-changing request; RBAC on every staff page; prepared statements everywhere; output escaping.
- Brute-force lockout, audit logging, secure validated uploads (finfo MIME + whitelist + random names).

## 6. Performance
- Enable OPcache in `php.ini`: `opcache.enable=1`, `opcache.validate_timestamps=0` (prod).
- Serve `assets/` with far-future cache headers (they're static & versioned by build).
- Enable gzip/brotli for CSS/JS/HTML.
- The v7 migration already adds DB indexes on all hot status/date/score columns.
- Charts are pure inline SVG/CSS (no chart library download).

---

## Production Checklist
- [ ] `config.local.php` created; **`SH_DEBUG=false`**
- [ ] `SH_HTTPS=true` and TLS certificate installed
- [ ] Empty database created + `database/SmartHire_v7_PostgreSQL_Setup.sql` applied
- [ ] `logs/` and `uploads/resumes/` writable by web user
- [ ] Default `admin@smarthire.com` password changed
- [ ] Real staff accounts created; unused demo accounts removed
- [ ] Root `.htaccess` (or Nginx rules) blocking `includes/ logs/ database/ tests/` + `.sql/.log/.md`
- [ ] Mail transport configured (`log` for demo, `smtp` for production)
- [ ] OPcache enabled; gzip on; asset caching on
- [ ] Backups scheduled for the `smarthire` database + `uploads/`
- [ ] `php tests/run_tests.php` passes on the server (122/122)
- [ ] Reviewed `audit_logs` access is restricted to admins

---

## Rollback
All v7 migrations are **additive** — no v6 tables/columns were dropped. To roll back the app, restore the previous PHP files; the v7 tables can remain harmlessly. Always back up the database before any migration.
