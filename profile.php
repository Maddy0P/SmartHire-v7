<?php
// ═════════════════════════════════════════════════════════════════════════════
//  profile.php — staff user profile (view/edit name, account info, security).
// ═════════════════════════════════════════════════════════════════════════════
require_once 'includes/config.php';
require_once 'includes/layout.php';
requireLogin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

$id = currentUser()['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'update') {
    $name = trim($_POST['name'] ?? '');
    if (v_len($name, 2, 100)) {
        dbExecute("UPDATE users SET name=? WHERE id=?", 'si', $name, $id);
        $_SESSION['user_name'] = $name;
        audit_log('profile_update', 'user', (int)$id);
        setFlash('success', 'Profile updated.');
    } else setFlash('error', 'Name must be 2–100 characters.');
    redirect('profile.php');
}
$u = dbFetchOne("SELECT * FROM users WHERE id=?", 'i', $id);

renderHead('Profile');
renderSidebar('');
?>
<div class="page-header"><div class="page-header-left"><h1><i class="fa-solid fa-user-gear"></i> My Profile</h1>
  <p class="sh-muted">Manage your account details and security</p></div></div>

<div class="sh-grid" style="grid-template-columns:1fr 1fr">
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-id-card"></i> Account Details</h3></div>
    <div class="card-body">
      <div style="display:flex;align-items:center;gap:14px;margin-bottom:18px">
        <div class="avatar" style="width:60px;height:60px;font-size:24px"><?= strtoupper(substr($u['name'],0,1)) ?></div>
        <div><div style="font-weight:700;font-size:16px;color:var(--text-primary)"><?= e($u['name']) ?></div>
          <span class="stage-badge stage-violet"><?= e(ucfirst(str_replace('_',' ',$u['role']))) ?></span></div>
      </div>
      <form method="POST">
        <?= csrf_field() ?><input type="hidden" name="form_action" value="update">
        <div class="form-group"><label>Full Name</label><input class="form-control" name="name" value="<?= e($u['name']) ?>" required></div>
        <div class="form-group"><label>Email</label><input class="form-control" value="<?= e($u['email']) ?>" disabled></div>
        <button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save Changes</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-shield-halved"></i> Security</h3></div>
    <div class="card-body">
      <p class="sh-muted" style="font-size:13.5px">Last login: <?= $u['last_login'] ? date('M j, Y g:i A', strtotime($u['last_login'])) : '—' ?></p>
      <p class="sh-muted" style="font-size:13.5px">Account status: <span class="stage-badge stage-<?= ($u['is_active']??1)?'green':'rose' ?>"><?= ($u['is_active']??1)?'Active':'Disabled' ?></span></p>
      <a href="change_password.php" class="btn btn-secondary sh-mt"><i class="fa-solid fa-key"></i> Change Password</a>
      <?php if (hasRole('super_admin')): ?>
      <a href="signup.php" class="btn btn-secondary sh-mt" style="margin-left:8px"><i class="fa-solid fa-user-plus"></i> Add Staff User</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php renderFooter(); ?>
