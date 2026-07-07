<?php
require_once 'includes/config.php';

if (isCandidateLoggedIn()) { redirect('candidate_portal.php'); }
if ($_SERVER['REQUEST_METHOD']==='POST') require_csrf();

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $skills   = trim($_POST['skills'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!$name || !$email || !$password) {
        $error = 'Please fill in all required fields (Name, Email, Password).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (($pwErr = password_policy_error($password)) !== '') {
        $error = $pwErr;
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        // Check duplicate email
        $existing = dbFetchOne("SELECT id FROM candidates WHERE email = ?", 's', $email);
        if ($existing) {
            $error = 'An account with this email already exists. <a href="candidate_login.php" style="color:inherit;text-decoration:underline">Sign in instead →</a>';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            // Use a try/catch so a schema mismatch shows a clear message
            try {
                $id = dbExecute(
                    "INSERT INTO candidates (name, email, phone, position, skills, status, password) VALUES (?,?,?,?,?,'pending',?)",
                    'ssssss',
                    $name, $email, $phone, $position, $skills, $hash
                );
                if ($id) {
                    audit_log('candidate_register', 'candidate', is_int($id) ? $id : null, 'email=' . $email);
                    $success = 'Account created successfully! You can now sign in.';
                    // HR notification (silently skip if table missing)
                    try {
                        addNotification('general',
                            $name . ' registered as a new candidate' . ($position ? ' for: ' . $position : ''),
                            (int)$id
                        );
                    } catch (Throwable $e) {}
                } else {
                    $error = 'Registration failed — please try again.';
                }
            } catch (Throwable $e) {
                // Surface a helpful message if column still missing after auto-heal
                $error = 'Database schema error: ' . htmlspecialchars($e->getMessage()) .
                         '<br><br>Please contact the administrator if this persists.';
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
  <title>Candidate Registration — SmartHire</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="assets/css/main.css">
</head>
<body>
<div class="login-page" style="background:linear-gradient(135deg,#0f172a 0%,#1e1b4b 100%)">

  <!-- Left Panel -->
  <div class="login-left" style="background:linear-gradient(160deg,#7c3aed,#4338ca)">
    <div class="login-brand">
      <div class="big-icon"><i class="fa-solid fa-user-plus"></i></div>
      <h1>Join SmartHire</h1>
      <p>Create your candidate account</p>
    </div>
    <div class="login-features">
      <div class="feature-item"><i class="fa-solid fa-clipboard-list"></i><span>Take online assessments &amp; tests</span></div>
      <div class="feature-item"><i class="fa-solid fa-chart-line"></i><span>Track your scores &amp; progress</span></div>
      <div class="feature-item"><i class="fa-solid fa-bell"></i><span>Get notified of interview updates</span></div>
      <div class="feature-item"><i class="fa-solid fa-shield-halved"></i><span>Your data is secure &amp; private</span></div>
    </div>
  </div>

  <!-- Right Panel -->
  <div class="login-right">
    <div class="login-form-box">
      <div style="text-align:center;margin-bottom:20px">
        <div style="width:56px;height:56px;background:linear-gradient(135deg,#7c3aed,#4338ca);border-radius:14px;display:inline-flex;align-items:center;justify-content:center;font-size:22px;color:#fff">
          <i class="fa-solid fa-user-plus"></i>
        </div>
      </div>
      <h2>Create Account</h2>
      <p>Register as a candidate to take assessments</p>

      <?php if ($error): ?>
      <div class="alert alert-error" style="margin:0 0 16px">
        <i class="fa-solid fa-triangle-exclamation"></i> <?= $error ?>
      </div>
      <?php endif; ?>

      <?php if ($success): ?>
      <div class="alert alert-success" style="margin:0 0 16px">
        <i class="fa-solid fa-check-circle"></i> <?= htmlspecialchars($success) ?>
        <br><a href="candidate_login.php" style="color:inherit;font-weight:700;margin-top:6px;display:inline-block">→ Sign In Now</a>
      </div>
      <?php endif; ?>

      <?php if (!$success): ?>
      <form method="POST">
        <?= csrf_field() ?>
        <div class="form-group">
          <label class="form-label">Full Name *</label>
          <div class="input-icon-wrap">
            <i class="fa-solid fa-user"></i>
            <input type="text" name="name" class="form-control" placeholder="Your full name"
                   value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required autofocus>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Email Address *</label>
          <div class="input-icon-wrap">
            <i class="fa-solid fa-envelope"></i>
            <input type="email" name="email" class="form-control" placeholder="your@email.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Phone Number</label>
          <div class="input-icon-wrap">
            <i class="fa-solid fa-phone"></i>
            <input type="text" name="phone" class="form-control" placeholder="+91-XXXXXXXXXX"
                   value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Position Applying For</label>
          <div class="input-icon-wrap">
            <i class="fa-solid fa-briefcase"></i>
            <input type="text" name="position" class="form-control" placeholder="e.g. Full Stack Developer"
                   value="<?= htmlspecialchars($_POST['position'] ?? '') ?>">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Key Skills</label>
          <div class="input-icon-wrap">
            <i class="fa-solid fa-tags"></i>
            <input type="text" name="skills" class="form-control" placeholder="e.g. PHP, MySQL, React"
                   value="<?= htmlspecialchars($_POST['skills'] ?? '') ?>">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Password * <small style="color:var(--text-muted);font-weight:400">(min. 8 chars, upper, lower & number)</small></label>
          <div class="input-icon-wrap">
            <i class="fa-solid fa-lock"></i>
            <input type="password" name="password" class="form-control" placeholder="Choose a strong password" required>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Confirm Password *</label>
          <div class="input-icon-wrap">
            <i class="fa-solid fa-lock"></i>
            <input type="password" name="confirm_password" class="form-control" placeholder="Repeat your password" required>
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100"
                style="margin-top:6px;background:linear-gradient(135deg,#7c3aed,#4338ca)">
          <i class="fa-solid fa-user-plus"></i> Create Account
        </button>
      </form>
      <?php endif; ?>

      <div style="text-align:center;margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
        <p style="font-size:13px;color:var(--text-muted);margin:0">
          Already have an account?
          <a href="candidate_login.php" style="color:#a78bfa;font-weight:600">Sign In →</a>
        </p>
        <p style="font-size:12px;color:var(--text-muted);margin:8px 0 0">
          Are you HR staff?
          <a href="index.php" style="color:var(--text-muted)">HR Login →</a>
        </p>
      </div>
    </div>
  </div>
</div>
</body>
</html>
