<?php
// ═════════════════════════════════════════════════════════════════════════════
//  change_password.php — logged-in password change (staff or candidate realm).
// ═════════════════════════════════════════════════════════════════════════════
require_once 'includes/config.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

$isStaff = isLoggedIn();
$isCand  = isCandidateLoggedIn();
if (!$isStaff && !$isCand) { redirect('index.php'); }
$realm = $isStaff ? 'hr' : 'candidate';
$table = $isStaff ? 'users' : 'candidates';
$id    = $isStaff ? currentUser()['id'] : currentCandidate()['id'];

$error = ''; $ok = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cur = $_POST['current'] ?? ''; $pw = $_POST['password'] ?? ''; $cf = $_POST['confirm'] ?? '';
    $acct = dbFetchOne("SELECT password FROM $table WHERE id=?", 'i', $id);
    if (!$acct || !password_verify($cur, $acct['password'])) $error = 'Current password is incorrect.';
    elseif (($e = password_policy_error($pw)) !== '') $error = $e;
    elseif ($pw !== $cf) $error = 'New passwords do not match.';
    else {
        dbExecute("UPDATE $table SET password=? WHERE id=?", 'si', password_hash($pw, PASSWORD_DEFAULT), $id);
        audit_log('password_change', 'auth', (int)$id, $realm);
        $ok = true;
    }
}
$back = $isStaff ? 'profile.php' : 'candidate_portal.php';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Change Password — SmartHire</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="assets/css/main.css"><link rel="stylesheet" href="assets/css/v7.css">
<style>.auth-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;background:radial-gradient(circle at 30% 20%,#1e1b4b,#060d1a)}
.auth-card{background:var(--bg-card);border:1px solid var(--border);border-radius:18px;padding:34px;max-width:420px;width:100%;box-shadow:var(--shadow-lg)}
.auth-card h2{margin:0 0 6px;color:var(--text-primary);font-size:20px}.auth-card p{color:var(--text-secondary);font-size:13.5px;margin:0 0 20px}
.auth-icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#7c3aed,#4338ca);display:flex;align-items:center;justify-content:center;color:#fff;font-size:22px;margin-bottom:16px}</style>
</head><body>
<div class="auth-wrap"><div class="auth-card">
  <div class="auth-icon"><i class="fa-solid fa-shield-halved"></i></div>
  <h2>Change password</h2><p>Keep your account secure with a strong password.</p>
  <?php if ($ok): ?><div class="alert alert-success" style="margin-bottom:16px"><i class="fa-solid fa-check-circle"></i> Password updated successfully.</div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:16px"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($error) ?></div><?php endif; ?>
  <form method="POST">
    <?= csrf_field() ?>
    <div class="form-group"><label>Current password</label><input class="form-control" type="password" name="current" required></div>
    <div class="form-group"><label>New password</label><input class="form-control" type="password" name="password" required></div>
    <div class="form-group"><label>Confirm new password</label><input class="form-control" type="password" name="confirm" required></div>
    <button class="btn btn-primary w-100"><i class="fa-solid fa-check"></i> Update password</button>
  </form>
  <p style="text-align:center;margin:16px 0 0"><a href="<?= $back ?>" style="color:var(--accent-light);font-size:13px;font-weight:600">← Back</a></p>
</div></div>
</body></html>
