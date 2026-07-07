<?php
require_once 'includes/layout.php';
requireLogin();
requireRole('recruiter');
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

$action = $_GET['action'] ?? '';
$editId = (int)($_GET['id'] ?? 0);
$editCandidate = null;

// ── Handle form submissions ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $skills   = trim($_POST['skills'] ?? '');
    $resume   = trim($_POST['resume_note'] ?? '');
    $status   = $_POST['status'] ?? 'pending';
    $aiScore  = calculateAIScore($skills, $position, $resume);

    if ($_POST['form_action'] === 'create') {
        $ok = dbExecute(
            "INSERT INTO candidates (name,email,phone,position,skills,resume_note,status,ai_score) VALUES (?,?,?,?,?,?,?,?)",
            'sssssssi', $name, $email, $phone, $position, $skills, $resume, $status, $aiScore
        );
        setFlash($ok ? 'success' : 'error', $ok ? "Candidate '$name' added successfully!" : 'Failed to add candidate.');
        header('Location: candidates.php'); exit;

    } elseif ($_POST['form_action'] === 'update') {
        $id = (int)$_POST['candidate_id'];
        $ok = dbExecute(
            "UPDATE candidates SET name=?,email=?,phone=?,position=?,skills=?,resume_note=?,status=?,ai_score=? WHERE id=?",
            'sssssssii', $name, $email, $phone, $position, $skills, $resume, $status, $aiScore, $id
        );
        setFlash($ok ? 'success' : 'error', $ok ? "Candidate updated successfully!" : 'Update failed.');
        header('Location: candidates.php'); exit;

    } elseif ($_POST['form_action'] === 'delete') {
        $id = (int)$_POST['candidate_id'];
        dbExecute("DELETE FROM candidates WHERE id=?", 'i', $id);
        setFlash('success', 'Candidate removed.');
        header('Location: candidates.php'); exit;
    }
}

// ── Load edit target ──────────────────────────────────────
if ($action === 'edit' && $editId) {
    $editCandidate = dbFetchOne("SELECT * FROM candidates WHERE id=?", 'i', $editId);
}

// ── Fetch all candidates ──────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
$sql = "SELECT * FROM candidates" . ($filterStatus ? " WHERE status=?" : "") . " ORDER BY created_at DESC";
$candidates = $filterStatus
    ? dbFetchAll($sql, 's', $filterStatus)
    : dbFetchAll($sql);

$counts = [
    'all'         => dbFetchOne("SELECT COUNT(*) n FROM candidates")['n'],
    'pending'     => dbFetchOne("SELECT COUNT(*) n FROM candidates WHERE status='pending'")['n'],
    'scheduled'   => dbFetchOne("SELECT COUNT(*) n FROM candidates WHERE status='scheduled'")['n'],
    'interviewed' => dbFetchOne("SELECT COUNT(*) n FROM candidates WHERE status='interviewed'")['n'],
    'hired'       => dbFetchOne("SELECT COUNT(*) n FROM candidates WHERE status='hired'")['n'],
    'rejected'    => dbFetchOne("SELECT COUNT(*) n FROM candidates WHERE status='rejected'")['n'],
];

renderHead('Candidates');
renderSidebar('candidates');
?>

<!-- Page Header -->
<div class="page-header">
  <div class="page-header-left">
    <div class="breadcrumb"><a href="dashboard.php">Home</a> <i class="fa-solid fa-chevron-right"></i> Candidates</div>
    <h1 class="page-title">Candidates</h1>
    <p class="page-subtitle"><?= $counts['all'] ?> candidates in pipeline</p>
  </div>
  <button class="btn btn-primary" onclick="openModal('candidateModal')">
    <i class="fa-solid fa-plus"></i> Add Candidate
  </button>
</div>

<!-- Status filter tabs -->
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:20px;">
  <?php
  $tabs = [''=>'All','pending'=>'Pending','scheduled'=>'Scheduled','interviewed'=>'Interviewed','hired'=>'Hired','rejected'=>'Rejected'];
  foreach ($tabs as $val => $label):
    $active = $filterStatus === $val ? 'btn-primary' : 'btn-secondary';
    $cnt = $val ? $counts[$val] : $counts['all'];
  ?>
  <a href="candidates.php<?= $val ? "?status=$val" : '' ?>" class="btn <?= $active ?> btn-sm">
    <?= $label ?> <span style="opacity:.7">(<?= $cnt ?>)</span>
  </a>
  <?php endforeach; ?>
</div>

<!-- Search -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-body" style="padding:12px 16px;">
    <div style="display:flex;gap:10px;align-items:center;">
      <i class="fa-solid fa-search" style="color:var(--text-muted)"></i>
      <input type="text" id="tableSearch" class="form-control" placeholder="Search by name, email, position…" style="border:none;background:transparent;padding:0;flex:1;">
    </div>
  </div>
</div>

<!-- Candidates Table -->
<div class="card">
  <div class="table-container">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Candidate</th>
          <th>Position</th>
          <th>Phone</th>
          <th>AI Score</th>
          <th>Status</th>
          <th>Added</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($candidates)): ?>
        <tr><td colspan="8" class="text-center" style="padding:40px;color:var(--text-muted)">
          <i class="fa-solid fa-user-slash" style="font-size:24px;display:block;margin-bottom:8px"></i>
          No candidates found
        </td></tr>
        <?php endif; ?>
        <?php foreach ($candidates as $i => $c): ?>
        <?php
          $score = (int)$c['ai_score'];
          $scoreClass = $score >= 75 ? 'score-high' : ($score >= 50 ? 'score-medium' : 'score-low');
        ?>
        <tr>
          <td class="td-muted"><?= $i + 1 ?></td>
          <td>
            <div class="d-flex align-center gap-2">
              <div class="avatar sm"><?= strtoupper(substr($c['name'],0,1)) ?></div>
              <div>
                <div class="fw-600"><?= htmlspecialchars($c['name']) ?></div>
                <div class="td-muted"><?= htmlspecialchars($c['email']) ?></div>
              </div>
            </div>
          </td>
          <td><?= htmlspecialchars($c['position']) ?></td>
          <td class="td-muted"><?= htmlspecialchars($c['phone'] ?: '—') ?></td>
          <td>
            <div class="score-bar <?= $scoreClass ?>">
              <div class="score-bar-track">
                <div class="score-bar-fill" data-pct="<?= $score ?>"></div>
              </div>
              <span class="score-text"><?= $score ?>%</span>
            </div>
          </td>
          <td><span class="badge-status badge-<?= $c['status'] ?>"><?= $c['status'] ?></span></td>
          <td class="td-muted"><?= date('d M Y', strtotime($c['created_at'])) ?></td>
          <td>
            <div class="d-flex gap-2">
              <a href="candidate_final_result.php?id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm btn-icon" title="Final Result" style="background:rgba(124,58,237,0.15);color:#a78bfa;border-color:rgba(124,58,237,0.3)"><i class="fa-solid fa-chart-bar"></i></a>
              <a href="?action=edit&id=<?= $c['id'] ?>"
                 onclick="openEditModal(<?= htmlspecialchars(json_encode($c)) ?>); return false;"
                 class="btn btn-secondary btn-sm btn-icon" title="Edit">
                <i class="fa-solid fa-pen-to-square"></i>
              </a>
              <form method="POST" style="display:inline">
      <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="delete">
                <input type="hidden" name="candidate_id" value="<?= $c['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm btn-icon"
                        data-confirm="Remove '<?= htmlspecialchars($c['name']) ?>'?" title="Delete">
                  <i class="fa-solid fa-trash"></i>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Add Candidate Modal ──────────────────────────────── -->
<div class="modal-overlay" id="candidateModal">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fa-solid fa-user-plus"></i> Add New Candidate</h3>
      <button class="modal-close" onclick="closeModal('candidateModal')">×</button>
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="form_action" value="create">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Full Name *</label>
            <input name="name" type="text" class="form-control" placeholder="John Doe" required>
          </div>
          <div class="form-group">
            <label class="form-label">Email *</label>
            <input name="email" type="email" class="form-control" placeholder="john@email.com" required>
          </div>
          <div class="form-group">
            <label class="form-label">Phone</label>
            <input name="phone" type="text" class="form-control" placeholder="+91-9876543210">
          </div>
          <div class="form-group">
            <label class="form-label">Applied Position *</label>
            <input name="position" type="text" class="form-control" placeholder="Full Stack Developer" required>
          </div>
          <div class="form-group form-full">
            <label class="form-label">Skills (comma-separated)</label>
            <input name="skills" type="text" class="form-control" placeholder="React, Node.js, Python, MySQL">
          </div>
          <div class="form-group form-full">
            <label class="form-label">Resume Notes</label>
            <textarea name="resume_note" class="form-control" placeholder="Brief summary of experience…"></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Status</label>
            <select name="status" class="form-control">
              <option value="pending">Pending</option>
              <option value="scheduled">Scheduled</option>
              <option value="interviewed">Interviewed</option>
              <option value="hired">Hired</option>
              <option value="rejected">Rejected</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('candidateModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Add Candidate</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Edit Candidate Modal ─────────────────────────────── -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fa-solid fa-pen-to-square"></i> Edit Candidate</h3>
      <button class="modal-close" onclick="closeModal('editModal')">×</button>
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="form_action" value="update">
      <input type="hidden" name="candidate_id" id="edit_id">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Full Name *</label>
            <input id="edit_name" name="name" type="text" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label">Email *</label>
            <input id="edit_email" name="email" type="email" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label">Phone</label>
            <input id="edit_phone" name="phone" type="text" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Applied Position</label>
            <input id="edit_position" name="position" type="text" class="form-control">
          </div>
          <div class="form-group form-full">
            <label class="form-label">Skills</label>
            <input id="edit_skills" name="skills" type="text" class="form-control">
          </div>
          <div class="form-group form-full">
            <label class="form-label">Resume Notes</label>
            <textarea id="edit_resume" name="resume_note" class="form-control"></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Status</label>
            <select id="edit_status" name="status" class="form-control">
              <option value="pending">Pending</option>
              <option value="scheduled">Scheduled</option>
              <option value="interviewed">Interviewed</option>
              <option value="hired">Hired</option>
              <option value="rejected">Rejected</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditModal(c) {
  document.getElementById('edit_id').value       = c.id;
  document.getElementById('edit_name').value     = c.name;
  document.getElementById('edit_email').value    = c.email;
  document.getElementById('edit_phone').value    = c.phone || '';
  document.getElementById('edit_position').value = c.position || '';
  document.getElementById('edit_skills').value   = c.skills || '';
  document.getElementById('edit_resume').value   = c.resume_note || '';
  document.getElementById('edit_status').value   = c.status;
  openModal('editModal');
}
<?php if ($action === 'new'): ?>
openModal('candidateModal');
<?php endif; ?>
</script>

<?php renderFooter(); ?>
