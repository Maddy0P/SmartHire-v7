<?php
// ═════════════════════════════════════════════════════════════════════════════
//  SmartHire v7 — includes/mailer.php   (Build 4 · Email & Notification System)
//
//  A clean, pluggable notification layer. By DEFAULT it uses the "log" transport
//  (writes rendered emails to logs/mail.log) so the whole notification flow works
//  out-of-the-box with zero SMTP setup — ideal for a demo/college environment.
//
//  To enable real email, set in includes/config.local.php:
//     define('SH_MAIL_TRANSPORT', 'php');    // uses PHP mail()  (needs a configured MTA)
//     // — or —
//     define('SH_MAIL_TRANSPORT', 'smtp');   // uses PHPMailer if installed (see DEPLOYMENT.md)
//     define('SH_MAIL_FROM', 'no-reply@yourdomain.com');
//     define('SH_SMTP_HOST', 'smtp.yourhost.com');
//     define('SH_SMTP_PORT', 587);
//     define('SH_SMTP_USER', '...'); define('SH_SMTP_PASS', '...');
//     define('SH_SMTP_SECURE', 'tls');
//
//  Template rendering (sh_mail_template) is PURE and unit-tested.
// ═════════════════════════════════════════════════════════════════════════════

defined('SH_MAIL_TRANSPORT') || define('SH_MAIL_TRANSPORT', 'log');
defined('SH_MAIL_FROM')      || define('SH_MAIL_FROM', 'no-reply@smarthire.local');
defined('SH_MAIL_FROM_NAME') || define('SH_MAIL_FROM_NAME', 'SmartHire');

/**
 * Event → [subject, htmlBody]. PURE (no DB / no side effects). $d = template vars.
 * Covers every candidate/recruiter/admin event in the spec.
 */
function sh_mail_template(string $event, array $d = []): array {
    $name  = $d['name']  ?? 'there';
    $job   = $d['job']   ?? 'the role';
    $extra = $d['extra'] ?? '';
    $map = [
        // ── Candidate ──
        'application_confirmation' => ['Application received — ' . $job,
            "Hi $name, thanks for applying to <b>$job</b>. Your resume was received and automatically scored by our ATS. We'll be in touch as your application progresses."],
        'shortlisted' => ['You\'ve been shortlisted — ' . $job,
            "Great news $name! You've been <b>shortlisted</b> for <b>$job</b>. Our team will reach out with next steps."],
        'interview_invite' => ['Interview invitation — ' . $job,
            "Hi $name, you're invited to interview for <b>$job</b>. $extra"],
        'test_assigned' => ['Assessment assigned — ' . $job,
            "Hi $name, an online assessment has been assigned for <b>$job</b>. Please complete it from your candidate portal."],
        'offer_released' => ['🎉 You have an offer — ' . $job,
            "Congratulations $name! We're delighted to offer you the role of <b>$job</b>. $extra Please review and respond from your portal."],
        'selected' => ['You\'ve been selected — ' . $job,
            "Congratulations $name — you've been selected for <b>$job</b>!"],
        'rejected' => ['Update on your application — ' . $job,
            "Hi $name, thank you for your interest in <b>$job</b>. After careful review we won't be moving forward at this time. We wish you the very best."],
        // ── Recruiter ──
        'new_application' => ['New application — ' . $job,
            "A new candidate ($name) applied for <b>$job</b>. $extra"],
        'ats_completed' => ['ATS scan complete — ' . $job,
            "The ATS scan for $name ($job) is complete. $extra"],
        'offer_accepted' => ['Offer accepted — ' . $job,
            "$name has <b>accepted</b> the offer for <b>$job</b>."],
        'interview_completed' => ['Interview completed — ' . $job,
            "The interview for $name ($job) has been completed and scored."],
        // ── Admin ──
        'security_alert' => ['Security alert — SmartHire',
            "A security-relevant event was recorded: $extra"],
        'audit_alert' => ['Audit alert — SmartHire',
            "An auditable admin action occurred: $extra"],
        'system_alert' => ['System alert — SmartHire',
            "System notice: $extra"],
    ];
    [$subject, $body] = $map[$event] ?? ['SmartHire notification', $extra ?: 'You have a new notification.'];
    return ['subject' => $subject, 'html' => sh_mail_wrap($subject, $body)];
}

/** Wrap body copy in a simple, email-client-safe HTML shell. Pure. */
function sh_mail_wrap(string $title, string $bodyHtml): string {
    $t = htmlspecialchars($title, ENT_QUOTES);
    return '<!doctype html><html><body style="margin:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif">'
        . '<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px">'
        . '<table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden">'
        . '<tr><td style="background:linear-gradient(135deg,#7c3aed,#4338ca);padding:22px 28px">'
        . '<span style="color:#fff;font-size:18px;font-weight:bold">⚡ SmartHire</span></td></tr>'
        . '<tr><td style="padding:28px"><h1 style="font-size:18px;color:#0f172a;margin:0 0 14px">' . $t . '</h1>'
        . '<div style="font-size:14px;color:#334155;line-height:1.7">' . $bodyHtml . '</div>'
        . '<p style="font-size:12px;color:#94a3b8;margin-top:26px">This is an automated message from SmartHire.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

/**
 * Send an email via the configured transport. Returns bool.
 * Never throws — a failed email must not break a request.
 */
function sh_mail(string $to, string $subject, string $html): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $from = SH_MAIL_FROM; $fromName = SH_MAIL_FROM_NAME;
    try {
        switch (SH_MAIL_TRANSPORT) {
            case 'php':
                $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
                         . "From: $fromName <$from>\r\n";
                return @mail($to, $subject, $html, $headers);

            case 'smtp':
                // PHPMailer path — enabled only if the library is installed (see DEPLOYMENT.md).
                if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                    $m = new \PHPMailer\PHPMailer\PHPMailer(true);
                    $m->isSMTP();
                    $m->Host = SH_SMTP_HOST; $m->Port = SH_SMTP_PORT;
                    $m->SMTPAuth = true; $m->Username = SH_SMTP_USER; $m->Password = SH_SMTP_PASS;
                    if (defined('SH_SMTP_SECURE')) $m->SMTPSecure = SH_SMTP_SECURE;
                    $m->setFrom($from, $fromName); $m->addAddress($to);
                    $m->isHTML(true); $m->Subject = $subject; $m->Body = $html;
                    return $m->send();
                }
                // fall through to log if PHPMailer missing
            case 'log':
            default:
                $line = '[' . date('Y-m-d H:i:s') . "] TO:$to | SUBJECT:$subject\n" . $html . "\n" . str_repeat('─', 60) . "\n";
                @error_log($line, 3, rtrim(SH_LOG_DIR, '/') . '/mail.log');
                return true;
        }
    } catch (Throwable $e) {
        sh_log_error('mail failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * High-level: render an event template and email a candidate by id.
 * Also usable for recruiters/admins by passing an explicit address.
 */
function sh_email_event(string $to, string $event, array $vars = []): bool {
    $tpl = sh_mail_template($event, $vars);
    $ok  = sh_mail($to, $tpl['subject'], $tpl['html']);
    audit_log('email_' . $event, 'email', null, $to . ($ok ? '' : ' (FAILED)'));
    return $ok;
}

/** Convenience: email a candidate by id for an event (looks up their address). */
function sh_email_candidate(int $candidateId, string $event, array $vars = []): bool {
    $c = dbFetchOne("SELECT name,email FROM candidates WHERE id=?", 'i', $candidateId);
    if (!$c || empty($c['email'])) return false;
    $vars['name'] = $vars['name'] ?? $c['name'];
    return sh_email_event($c['email'], $event, $vars);
}
