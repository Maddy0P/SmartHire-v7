<?php
// ─────────────────────────────────────────────────────────────────────────────
//  signup.php — STAFF account creation.  SECURITY: Super-Admin only (fixes B2).
//  Public visitors can NOT create HR/recruiter accounts; they register as
//  candidates via candidate_signup.php.
// ─────────────────────────────────────────────────────────────────────────────
require_once 'includes/config.php';
requireLogin();
requireRole('super_admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

$error = $success = '';
$allowedRoles = ['recruiter','hr','interviewer','admin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    $role     = in_array($_POST['role'] ?? '', $allowedRoles, true) ? $_POST['role'] : 'recruiter';

    if (!$name || !$email || !$password) {
        $error = 'All fields are required.';
    } elseif (!v_email($email)) {
        $error = 'Please enter a valid email address.';
    } elseif (($pwErr = password_policy_error($password)) !== '') {
        $error = $pwErr;
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $existing = dbFetchOne("SELECT id FROM users WHERE email=?", 's', $email);
        if ($existing) {
            $error = 'This email address is already registered.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ok = dbExecute(
                "INSERT INTO users (name,email,password,role,is_active,created_by) VALUES (?,?,?,?,1,?)",
                'ssssi', $name, $email, $hash, $role, currentUser()['id']);
            if ($ok) {
                audit_log('staff_create', 'user', is_int($ok) ? $ok : null, 'role=' . $role . ' email=' . $email);
                $success = true;
            } else {
                $error = 'Registration failed. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign Up — SmartHire</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="assets/css/main.css">
</head>
<body>
<div class="login-page">

  <!-- Left Panel -->
  <div class="login-left">
    <div class="login-brand">
      <div class="big-icon"><i class="fa-solid fa-bolt"></i></div>
      <h1>SmartHire</h1>
      <p>Create a staff account (Super Admin only)</p>
    </div>
    <div class="login-features">
      <div class="feature-item">
        <i class="fa-solid fa-users-gear"></i>
        <span>Manage candidates end-to-end</span>
      </div>
      <div class="feature-item">
        <i class="fa-solid fa-robot"></i>
        <span>AI-powered resume ATS scanner</span>
      </div>
      <div class="feature-item">
        <i class="fa-solid fa-chart-pie"></i>
        <span>Visual interview analytics & reports</span>
      </div>
      <div class="feature-item">
        <i class="fa-solid fa-file-pdf"></i>
        <span>One-click PDF result downloads</span>
      </div>
    </div>
  </div>

  <!-- Right Panel -->
  <div class="login-right">
    <div class="login-form-box">
      <?php if ($success): ?>
      <div style="text-align:center;padding:30px 0">
        <div style="width:72px;height:72px;background:var(--emerald-bg);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:30px;color:var(--emerald)">
          <i class="fa-solid fa-check"></i>
        </div>
        <h2 style="margin-bottom:8px">Account Created!</h2>
        <p style="color:var(--text-secondary);margin-bottom:24px">Your SmartHire account is ready. Sign in to get started.</p>
        <a href="signup.php" class="btn btn-primary btn-lg w-100"><i class="fa-solid fa-user-plus"></i> Create Another</a>
      </div>
      <?php else: ?>
      <h2>Create Staff Account 🔐</h2>
      <p>Only a Super Admin can provision HR / recruiter / interviewer accounts.</p>

      <?php if ($error): ?>
      <div class="alert alert-error" style="margin:0 0 18px">
        <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <form method="POST">
        <?= csrf_field() ?>
        <div class="form-group">
          <label class="form-label">Full Name</label>
          <div class="input-icon-wrap">
            <i class="fa-solid fa-user"></i>
            <input type="text" name="name" class="form-control" placeholder="Your full name"
                   value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Email Address</label>
          <div class="input-icon-wrap">
            <i class="fa-solid fa-envelope"></i>
            <input type="email" name="email" class="form-control" placeholder="you@company.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Role</label>
          <div class="input-icon-wrap">
            <i class="fa-solid fa-id-badge"></i>
            <select name="role" class="form-control" style="padding-left:38px">
              <option value="recruiter">Recruiter</option>
              <option value="hr">HR Manager</option>
              <option value="interviewer">Interviewer</option>
              <option value="admin">Admin</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Password</label>
          <div class="input-icon-wrap">
            <i class="fa-solid fa-lock"></i>
            <input type="password" name="password" class="form-control" placeholder="Min. 6 characters" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Confirm Password</label>
          <div class="input-icon-wrap">
            <i class="fa-solid fa-lock"></i>
            <input type="password" name="confirm_password" class="form-control" placeholder="Repeat password" required>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100" style="margin-top:6px">
          <i class="fa-solid fa-user-plus"></i> Create Account
        </button>
      </form>
      <p style="text-align:center;margin-top:20px;font-size:13px;color:var(--text-muted)">
        <a href="dashboard.php" style="color:var(--accent-light);font-weight:600">← Back to dashboard</a>
      </p>
      <?php endif; ?>
    </div>
  </div>

</div>
</body>
</html>
