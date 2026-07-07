<?php
// ═════════════════════════════════════════════════════════════════════════════
//  forgot_password.php — request a reset link (staff or candidate).
//  No user enumeration; token hashed + 1h expiry; email via mailer (log by default).
// ═════════════════════════════════════════════════════════════════════════════
require_once 'includes/config.php';
require_once 'includes/mailer.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

$done = false; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $realm = ($_POST['realm'] ?? 'hr') === 'candidate' ? 'candidate' : 'hr';
    if (!v_email($email)) {
        $error = 'Please enter a valid email address.';
    } else {
        $table = $realm === 'candidate' ? 'candidates' : 'users';
        $acct  = dbFetchOne("SELECT id, name FROM $table WHERE email=?", 's', $email);
        if ($acct) {
            $token = bin2hex(random_bytes(32));
            $hash  = hash('sha256', $token);
            dbExecute("INSERT INTO password_resets (realm,account_id,email,token_hash,expires_at)
                       VALUES (?,?,?,?, NOW() + INTERVAL '1 hour')",
                'siss', $realm, (int)$acct['id'], $email, $hash);
            $base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                  . rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
            $link = $base . '/reset_password.php?token=' . $token . '&realm=' . $realm;
            sh_mail($email, 'Reset your SmartHire password',
                sh_mail_wrap('Reset your password',
                    'Hi ' . e($acct['name']) . ', we received a request to reset your password. '
                    . 'Click the link below (valid for 1 hour):<br><br>'
                    . '<a href="' . e($link) . '" style="background:#7c3aed;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">Reset Password</a>'
                    . '<br><br>If you didn\'t request this, you can safely ignore this email.'));
            audit_log('password_reset_request', 'auth', (int)$acct['id'], $realm);
        }
        // Always show the same message (prevents user enumeration)
        $done = true;
    }
}
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Forgot Password — SmartHire</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="assets/css/main.css"><link rel="stylesheet" href="assets/css/v7.css">
<style>.auth-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;background:radial-gradient(circle at 30% 20%,#1e1b4b,#060d1a)}
.auth-card{background:var(--bg-card);border:1px solid var(--border);border-radius:18px;padding:34px;max-width:420px;width:100%;box-shadow:var(--shadow-lg)}
.auth-card h2{margin:0 0 6px;color:var(--text-primary);font-size:20px}.auth-card p{color:var(--text-secondary);font-size:13.5px;margin:0 0 20px}
.auth-icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#7c3aed,#4338ca);display:flex;align-items:center;justify-content:center;color:#fff;font-size:22px;margin-bottom:16px}</style>
</head><body>
<div class="auth-wrap"><div class="auth-card">
  <div class="auth-icon"><i class="fa-solid fa-key"></i></div>
  <?php if ($done): ?>
    <h2>Check your inbox</h2>
    <p>If an account exists for that email, we've sent a password reset link. It expires in 1 hour.</p>
    <p class="sh-muted" style="font-size:12px">Demo note: with the default mail transport, the link is written to <code>logs/mail.log</code>.</p>
    <a href="index.php" class="btn btn-primary w-100"><i class="fa-solid fa-arrow-left"></i> Back to sign in</a>
  <?php else: ?>
    <h2>Forgot password?</h2>
    <p>Enter your email and we'll send a reset link.</p>
    <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:16px"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($error) ?></div><?php endif; ?>
    <form method="POST">
      <?= csrf_field() ?>
      <div class="form-group"><label>Email</label><input class="form-control" type="email" name="email" required autofocus></div>
      <div class="form-group"><label>Account type</label>
        <select class="form-control" name="realm"><option value="hr">Staff (HR / Recruiter)</option><option value="candidate">Candidate</option></select></div>
      <button class="btn btn-primary w-100"><i class="fa-solid fa-paper-plane"></i> Send reset link</button>
    </form>
    <p style="text-align:center;margin:16px 0 0"><a href="index.php" style="color:var(--accent-light);font-size:13px;font-weight:600">← Back to sign in</a></p>
  <?php endif; ?>
</div></div>
</body></html>
