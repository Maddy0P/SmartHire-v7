<?php
// Copy to includes/config.local.php and edit for local development.
// In production (Render/Neon), prefer real ENVIRONMENT VARIABLES instead of this file.
// This file is git-ignored and overrides defaults in config.php.

// ── Database: PostgreSQL / Neon ───────────────────────────────────────────
// Option A — single connection string (matches Neon's dashboard copy button):
// putenv('DATABASE_URL=postgresql://user:pass@ep-xxx-pooler.region.aws.neon.tech/smarthire?sslmode=require');
//
// Option B — discrete settings:
defined('DB_HOST') || define('DB_HOST', 'localhost');       // or your Neon pooled host
defined('DB_PORT') || define('DB_PORT', 5432);
defined('DB_USER') || define('DB_USER', 'postgres');
defined('DB_PASS') || define('DB_PASS', '');
defined('DB_NAME') || define('DB_NAME', 'smarthire');
defined('DB_SSLMODE') || define('DB_SSLMODE', 'prefer');       // use 'require' for Neon, 'disable' for local dev

// ── Environment ───────────────────────────────────────────────────────────
defined('SH_DEBUG') || define('SH_DEBUG', false);   // MUST be false in production
defined('SH_HTTPS') || define('SH_HTTPS', true);    // true when served over HTTPS

// ── Email / Notifications ─────────────────────────────────────────────────
defined('SH_MAIL_TRANSPORT') || define('SH_MAIL_TRANSPORT', 'log');   // 'log' | 'php' | 'smtp'
defined('SH_MAIL_FROM') || define('SH_MAIL_FROM', 'no-reply@yourdomain.com');
defined('SH_MAIL_FROM_NAME') || define('SH_MAIL_FROM_NAME', 'SmartHire');
// SMTP (only when SH_MAIL_TRANSPORT='smtp'):
// define('SH_SMTP_HOST','smtp.yourhost.com'); define('SH_SMTP_PORT',587);
// define('SH_SMTP_USER','...'); define('SH_SMTP_PASS','...'); define('SH_SMTP_SECURE','tls');
