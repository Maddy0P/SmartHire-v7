<?php
require_once 'includes/config.php';

if (isCandidateLoggedIn()) { redirect('candidate_portal.php'); }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (is_locked_out($email, 'candidate')) {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } elseif ($email && $password) {
        $cand = dbFetchOne("SELECT * FROM candidates WHERE email = ?", 's', $email);
        $ok = $cand && !empty($cand['password']) && password_verify($password, $cand['password']);
        record_login_attempt($email, (bool)$ok, 'candidate');
        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['candidate_id']    = $cand['id'];
            $_SESSION['candidate_name']  = $cand['name'];
            $_SESSION['candidate_email'] = $cand['email'];
            $_SESSION['_born']           = time();
            audit_log('login', 'auth', (int)$cand['id'], 'candidate');
            redirect('candidate_portal.php');
        } else {
            $error = 'Invalid email or password. Please check and try again.';
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
  <title>Candidate Login — SmartHire</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="assets/css/main.css">
</head>
<body>
<div class="login-page" style="background:linear-gradient(135deg,#0f172a 0%,#1e1b4b 50%,#0f172a 100%)">
  <div class="login-left" style="background:linear-gradient(160deg,#7c3aed,#4338ca)">
    <div class="login-brand">
      <div class="big-icon"><i class="fa-solid fa-user-graduate"></i></div>
      <h1>Candidate Portal</h1>
      <p>SmartHire Assessment Platform</p>
    </div>
    <div class="login-features">
      <div class="feature-item"><i class="fa-solid fa-laptop-code"></i><span>Take online assessments and tests</span></div>
      <div class="feature-item"><i class="fa-solid fa-chart-bar"></i><span>View your test scores and results</span></div>
      <div class="feature-item"><i class="fa-solid fa-file-circle-check"></i><span>Access your complete profile &amp; reports</span></div>
      <div class="feature-item"><i class="fa-solid fa-shield-halved"></i><span>Secure, timed testing environment</span></div>
    </div>
  </div>
  <div class="login-right">
    <div class="login-form-box">
      <div style="text-align:center;margin-bottom:24px">
        <div style="width:60px;height:60px;background:linear-gradient(135deg,#7c3aed,#4338ca);border-radius:16px;display:inline-flex;align-items:center;justify-content:center;font-size:24px;color:#fff">
          <i class="fa-solid fa-user-graduate"></i>
        </div>
      </div>
      <h2>Candidate Sign In</h2>
      <p>Access your assessment portal</p>

      <?php if ($error): ?>
      <div class="alert alert-error" style="margin:0 0 18px">
        <i class="fa-solid fa-triangle-exclamation"></i> <?= $error ?>
      </div>
      <?php endif; ?>

      <form method="POST">
        <?= csrf_field() ?>
        <div style="text-align:right;margin-bottom:8px"><a href="forgot_password.php" style="color:#a78bfa;font-size:12px;font-weight:600">Forgot password?</a></div>
        <div class="form-group">
          <label class="form-label">Email Address</label>
          <div class="input-icon-wrap">
            <i class="fa-solid fa-envelope"></i>
            <input type="email" name="email" class="form-control" placeholder="your@email.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Password</label>
          <div class="input-icon-wrap">
            <i class="fa-solid fa-lock"></i>
            <input type="password" name="password" class="form-control" placeholder="Enter your password" required>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100"
                style="margin-top:6px;background:linear-gradient(135deg,#7c3aed,#4338ca)">
          <i class="fa-solid fa-right-to-bracket"></i> Sign In to Portal
        </button>
      </form>

      <?php if (SH_DEBUG): ?>
      <div class="login-hint" style="margin-top:20px">
        <strong>Dev credentials (debug mode):</strong><br>
        <code>alice@email.com</code> / <code>password</code><br>
        <small style="color:var(--text-muted)">Or register a new account below</small>
      </div>
      <?php endif; ?>

      <div style="text-align:center;margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
        <p style="font-size:13px;color:var(--text-muted);margin:0 0 8px">
          Don't have an account?
          <a href="candidate_signup.php" style="color:#a78bfa;font-weight:600">Register →</a>
        </p>
        <a href="index.php" style="color:var(--text-muted);font-size:12px">
          <i class="fa-solid fa-arrow-left"></i> HR Login
        </a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
