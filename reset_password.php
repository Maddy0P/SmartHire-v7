<?php
// ═════════════════════════════════════════════════════════════════════════════
//  reset_password.php — consume a reset token, set a new password.
// ═════════════════════════════════════════════════════════════════════════════
require_once 'includes/config.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$realm = (($_GET['realm'] ?? $_POST['realm'] ?? 'hr') === 'candidate') ? 'candidate' : 'hr';
$hash  = $token ? hash('sha256', $token) : '';
$error = ''; $done = false;

$row = $hash ? dbFetchOne(
    "SELECT * FROM password_resets WHERE token_hash=? AND realm=? AND used=0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1",
    'ss', $hash, $realm) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $row) {
    $pw = $_POST['password'] ?? ''; $cf = $_POST['confirm'] ?? '';
    if (($e = password_policy_error($pw)) !== '') $error = $e;
    elseif ($pw !== $cf) $error = 'Passwords do not match.';
    else {
        $table = $realm === 'candidate' ? 'candidates' : 'users';
        $newHash = password_hash($pw, PASSWORD_DEFAULT);
        withTransaction(function () use ($table, $newHash, $row) {
            dbExecute("UPDATE $table SET password=? WHERE id=?", 'si', $newHash, (int)$row['account_id']);
            dbExecute("UPDATE password_resets SET used=1 WHERE id=?", 'i', (int)$row['id']);
        });
        audit_log('password_reset_complete', 'auth', (int)$row['account_id'], $realm);
        $done = true;
    }
}
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Reset Password — SmartHire</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="assets/css/main.css"><link rel="stylesheet" href="assets/css/v7.css">
<style>.auth-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;background:radial-gradient(circle at 30% 20%,#1e1b4b,#060d1a)}
.auth-card{background:var(--bg-card);border:1px solid var(--border);border-radius:18px;padding:34px;max-width:420px;width:100%;box-shadow:var(--shadow-lg)}
.auth-card h2{margin:0 0 6px;color:var(--text-primary);font-size:20px}.auth-card p{color:var(--text-secondary);font-size:13.5px;margin:0 0 20px}
.auth-icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#7c3aed,#4338ca);display:flex;align-items:center;justify-content:center;color:#fff;font-size:22px;margin-bottom:16px}</style>
</head><body>
<div class="auth-wrap"><div class="auth-card">
  <div class="auth-icon"><i class="fa-solid fa-lock"></i></div>
  <?php if ($done): ?>
    <h2>Password updated ✅</h2><p>Your password has been reset. You can now sign in.</p>
    <a href="<?= $realm==='candidate'?'candidate_login.php':'index.php' ?>" class="btn btn-primary w-100"><i class="fa-solid fa-right-to-bracket"></i> Sign in</a>
  <?php elseif (!$row): ?>
    <h2>Link expired or invalid</h2><p>This reset link is invalid, already used, or has expired. Please request a new one.</p>
    <a href="forgot_password.php" class="btn btn-primary w-100"><i class="fa-solid fa-rotate-right"></i> Request new link</a>
  <?php else: ?>
    <h2>Set a new password</h2><p>Choose a strong password (8+ chars, upper, lower & number).</p>
    <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:16px"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($error) ?></div><?php endif; ?>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>"><input type="hidden" name="realm" value="<?= e($realm) ?>">
      <div class="form-group"><label>New password</label><input class="form-control" type="password" name="password" required autofocus></div>
      <div class="form-group"><label>Confirm password</label><input class="form-control" type="password" name="confirm" required></div>
      <button class="btn btn-primary w-100"><i class="fa-solid fa-check"></i> Reset password</button>
    </form>
  <?php endif; ?>
</div></div>
</body></html>
