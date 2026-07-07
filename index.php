<?php
require_once 'includes/config.php';

// Redirect if already logged in
if (isLoggedIn()) { redirect('dashboard.php'); }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (is_locked_out($email, 'hr')) {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
        audit_log('login_locked', 'auth', null, 'email=' . $email);
    } elseif ($email && $password) {
        $user = dbFetchOne("SELECT * FROM users WHERE email = ?", 's', $email);
        $ok = $user && password_verify($password, $user['password']);
        // block disabled accounts
        if ($ok && isset($user['is_active']) && (int)$user['is_active'] === 0) {
            $ok = false; $error = 'This account has been deactivated.';
        }
        record_login_attempt($email, (bool)$ok, 'hr');
        if ($ok) {
            session_regenerate_id(true);               // prevent session fixation
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['user_name']  = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role']  = $user['role'];
            $_SESSION['_born']      = time();
            dbExecute("UPDATE users SET last_login=NOW() WHERE id=?", 'i', $user['id']);
            audit_log('login', 'auth', (int)$user['id'], 'role=' . $user['role']);
            setFlash('success', 'Welcome back, ' . $user['name'] . '!');
            redirect('dashboard.php');
        } elseif (!$error) {
            $remain = max(0, 5 - failed_attempt_count($email, 'hr'));
            $error = 'Invalid email or password.' . ($remain <= 2 && $remain > 0 ? " ($remain attempt(s) left)" : '');
        }
    } else {
        $error = 'Please enter both email and password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — SmartHire</title>
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
      <p>Next-Generation Interview Management Platform</p>
    </div>
    <div class="login-features">
      <div class="feature-item">
        <i class="fa-solid fa-robot"></i>
        <span>AI-powered candidate scoring & analysis</span>
      </div>
      <div class="feature-item">
        <i class="fa-solid fa-calendar-check"></i>
        <span>Smart interview scheduling & tracking</span>
      </div>
      <div class="feature-item">
        <i class="fa-solid fa-chart-line"></i>
        <span>Real-time hiring pipeline analytics</span>
      </div>
      <div class="feature-item">
        <i class="fa-solid fa-shield-halved"></i>
        <span>Secure, role-based access control</span>
      </div>
    </div>
  </div>

  <!-- Right Panel — Login Form -->
  <div class="login-right">
    <div class="login-form-box">
      <h2>Welcome back 👋</h2>
      <p>Sign in to your SmartHire account to continue</p>

      <?php if ($error): ?>
      <div class="alert alert-error" style="margin:0 0 18px;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <form method="POST" autocomplete="on">
        <?= csrf_field() ?>
        <div class="form-group">
          <label class="form-label">Email Address</label>
          <div class="input-icon-wrap">
            <i class="fa-solid fa-envelope"></i>
            <input type="email" name="email" class="form-control"
                   placeholder="you@company.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                   required autofocus>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Password</label>
          <div class="input-icon-wrap">
            <i class="fa-solid fa-lock"></i>
            <input type="password" name="password" class="form-control"
                   placeholder="Enter your password" required>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100" style="margin-top:6px;">
          <i class="fa-solid fa-right-to-bracket"></i>
          Sign In
        </button>
      </form>

      <p style="text-align:center;margin-top:16px;font-size:13px;color:var(--text-muted)">
        <a href="forgot_password.php" style="color:var(--accent-light);font-size:12.5px;font-weight:600">Forgot password?</a><br>New candidate? <a href="candidate_signup.php" style="color:var(--accent-light);font-weight:600">Register here</a>
      </p>
      <?php if (SH_DEBUG): ?>
      <div class="login-hint">
        <strong>Dev credentials (debug mode):</strong><br>
        Admin: <code>admin@smarthire.com</code> / <code>password</code><br>
        HR:&nbsp;&nbsp;&nbsp; <code>hr@smarthire.com</code> / <code>password</code>
      </div>
      <?php endif; ?>
      <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border);text-align:center">
        <a href="candidate_login.php" style="color:#818cf8;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px">
          <i class="fa-solid fa-user-graduate"></i> Candidate Portal Login
        </a>
        <span style="color:var(--text-muted);font-size:12px;margin:0 8px">·</span>
        <a href="candidate_signup.php" style="color:#a78bfa;font-size:13px;font-weight:600">
          Register as Candidate
        </a>
      </div>
    </div>
  </div>

</div>
</body>
</html>
