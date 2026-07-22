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

renderHead('Candidates', true);
renderSidebar('candidates');
?>

<div class="sh-page-header">
  <div>
    <h1 class="sh-page-title">Candidates</h1>
    <p class="sh-page-sub"><span class="sh-tnum"><?= $counts['all'] ?></span> candidates in the pipeline</p>
  </div>
  <button class="sh-btn sh-btn-primary" onclick="openModal('candidateModal')">
    <i class="fa-solid fa-plus" aria-hidden="true"></i> Add candidate
  </button>
</div>

<!-- Filter chips (state in URL — Bible P11/P14) -->
<nav class="sh-flex sh-gap-2 sh-mb-4 sh-wrap" aria-label="Filter candidates by status">
  <?php
  $tabs = [''=>'All','pending'=>'Pending','scheduled'=>'Scheduled','interviewed'=>'Interviewed','hired'=>'Hired','rejected'=>'Rejected'];
  foreach ($tabs as $val => $label):
    $cnt = $val ? $counts[$val] : $counts['all'];
  ?>
  <a href="candidates.php<?= $val ? "?status=$val" : '' ?>"
     class="sh-chip <?= $filterStatus === $val ? 'active' : '' ?>"
     <?= $filterStatus === $val ? 'aria-current="page"' : '' ?>>
    <?= $label ?> <span class="sh-count"><?= $cnt ?></span>
  </a>
  <?php endforeach; ?>
</nav>

<!-- Bulk action bar -->
<div class="sh-bulkbar" id="shBulkBar" role="toolbar" aria-label="Bulk actions">
  <span><span id="shBulkCount" class="sh-tnum">0</span> selected</span>
  <button class="sh-btn sh-btn-secondary sh-btn-sm" onclick="shBulkExport()"><i class="fa-solid fa-download" aria-hidden="true"></i> Export CSV</button>
  <button class="sh-btn sh-btn-danger sh-btn-sm" onclick="shBulkDelete()"><i class="fa-solid fa-trash" aria-hidden="true"></i> Delete</button>
</div>

<section class="sh-card sh-card-flush" aria-label="Candidates table">
  <div class="sh-card-header">
    <div class="sh-topbar-search sh-search-inline">
      <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
      <label class="sh-sr-only" for="tableSearch">Search candidates</label>
      <input type="search" id="tableSearch" placeholder="Search name, email, position…" autocomplete="off">
    </div>
  </div>
  <?php if (empty($candidates)): ?>
  <div class="sh-empty">
    <div class="sh-empty-icon"><i class="fa-solid fa-user-slash" aria-hidden="true"></i></div>
    <h3><?= $filterStatus ? 'Nothing matches this filter' : 'No candidates yet' ?></h3>
    <p><?= $filterStatus ? 'Try a different status, or clear the filter to see everyone.' : 'Candidates appear here when they apply or when you add them.' ?></p>
    <?php if ($filterStatus): ?>
    <a href="candidates.php" class="sh-btn sh-btn-secondary sh-btn-sm sh-mt-2">Clear filter</a>
    <?php else: ?>
    <button class="sh-btn sh-btn-primary sh-mt-2" onclick="openModal('candidateModal')">Add your first candidate</button>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="sh-table-wrap">
    <table class="sh-table" id="candidatesTable">
      <thead>
        <tr>
          <th scope="col" class="sh-col-check"><input type="checkbox" class="sh-check" id="shCheckAll" aria-label="Select all candidates"></th>
          <th scope="col">Candidate</th>
          <th scope="col">Position</th>
          <th scope="col">Score</th>
          <th scope="col">Status</th>
          <th scope="col">Added</th>
          <th scope="col"><span class="sh-sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($candidates as $c): $score = (int)$c['ai_score'];
              $cls = $score >= 75 ? 'hi' : ($score >= 50 ? 'mid' : 'lo'); ?>
        <tr data-name="<?= htmlspecialchars($c['name']) ?>" data-email="<?= htmlspecialchars($c['email']) ?>"
            data-phone="<?= htmlspecialchars($c['phone'] ?? '') ?>" data-position="<?= htmlspecialchars($c['position']) ?>"
            data-status="<?= htmlspecialchars($c['status']) ?>" data-aiscore="<?= $score ?>">
          <td><input type="checkbox" class="sh-check sh-row-check" value="<?= $c['id'] ?>" aria-label="Select <?= htmlspecialchars($c['name']) ?>"></td>
          <td data-th="Candidate">
            <button class="sh-cellbtn"
                    onclick='shShowCandidate(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>, this)'
                    aria-haspopup="dialog">
              <span class="sh-avatar" aria-hidden="true"><?= strtoupper(substr($c['name'],0,1)) ?></span>
              <span class="sh-flex-1">
                <span class="sh-cell-main sh-truncate sh-block"><?= htmlspecialchars($c['name']) ?></span>
                <span class="sh-cell-sub sh-truncate sh-mono sh-block"><?= htmlspecialchars($c['email']) ?></span>
              </span>
            </button>
          </td>
          <td data-th="Position"><?= htmlspecialchars($c['position']) ?></td>
          <td data-th="Score">
            <div class="sh-score">
              <div class="sh-score-track"><div class="sh-score-fill <?= $cls ?>" style="width:<?= $score ?>%"></div></div>
              <span class="sh-score-n sh-tnum"><?= $score ?>%</span>
            </div>
          </td>
          <td data-th="Status"><?= sh_status_badge($c['status']) ?></td>
          <td data-th="Added" class="sh-cell-sub sh-hide-mobile"><?= date('d M Y', strtotime($c['created_at'])) ?></td>
          <td>
            <div class="sh-row-actions">
              <a href="candidate_final_result.php?id=<?= $c['id'] ?>" class="sh-iconbtn" aria-label="Final result for <?= htmlspecialchars($c['name']) ?>" title="Final result"><i class="fa-solid fa-chart-bar" aria-hidden="true"></i></a>
              <a href="?action=edit&id=<?= $c['id'] ?>"
                 onclick="openEditModal(<?= htmlspecialchars(json_encode($c)) ?>); return false;"
                 class="sh-iconbtn" aria-label="Edit <?= htmlspecialchars($c['name']) ?>" title="Edit">
                <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
              </a>
              <form method="POST" class="sh-inline-form">
      <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="delete">
                <input type="hidden" name="candidate_id" value="<?= $c['id'] ?>">
                <button type="submit" class="sh-iconbtn sh-danger-text"
                        data-confirm="Remove '<?= htmlspecialchars($c['name']) ?>'?" aria-label="Delete <?= htmlspecialchars($c['name']) ?>" title="Delete">
                  <i class="fa-solid fa-trash" aria-hidden="true"></i>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<!-- Candidate detail slide-over (Bible P13; data from row, link to full profile) -->
<aside class="sh-slideover" id="candidatePanel" role="dialog" aria-modal="false" aria-labelledby="panelName" aria-hidden="true">
  <div class="sh-slideover-head">
    <span class="sh-avatar" id="panelAvatar" aria-hidden="true"></span>
    <div class="sh-flex-1">
      <h2 class="sh-card-title sh-panel-title" id="panelName"></h2>
      <p class="sh-card-sub" id="panelPosition"></p>
    </div>
    <button class="sh-iconbtn" onclick="shCloseSlideover('candidatePanel')" aria-label="Close panel"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
  </div>
  <div class="sh-slideover-body">
    <dl class="sh-dl">
      <dt>Email</dt><dd class="sh-mono" id="panelEmail"></dd>
      <dt>Phone</dt><dd class="sh-mono" id="panelPhone"></dd>
      <dt>Status</dt><dd id="panelStatus"></dd>
      <dt>Score</dt><dd><span class="sh-tnum" id="panelScore"></span> <span class="sh-ai-chip" title="Computed by the SmartHire scoring engine">AI</span></dd>
      <dt>Skills</dt><dd id="panelSkills"></dd>
      <dt>Notes</dt><dd id="panelNotes"></dd>
    </dl>
  </div>
  <div class="sh-slideover-foot">
    <a class="sh-btn sh-btn-primary" id="panelProfileLink" href="#">Open full profile</a>
    <a class="sh-btn sh-btn-secondary" id="panelResultLink" href="#">Final result</a>
  </div>
</aside>

<!-- ── Add Candidate Modal ──────────────────────────────── -->
<div class="modal-overlay" id="candidateModal" role="dialog" aria-modal="true" aria-labelledby="addTitle">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title" id="addTitle">Add new candidate</h3>
      <button class="modal-close" onclick="closeModal('candidateModal')" aria-label="Close dialog">×</button>
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="form_action" value="create">
      <div class="modal-body">
        <div class="sh-form-grid">
          <div class="sh-field">
            <label class="sh-label" for="add_name">Full name <span class="req" aria-hidden="true">*</span></label>
            <input id="add_name" name="name" type="text" class="sh-input" placeholder="John Doe" required>
          </div>
          <div class="sh-field">
            <label class="sh-label" for="add_email">Email <span class="req" aria-hidden="true">*</span></label>
            <input id="add_email" name="email" type="email" class="sh-input" placeholder="john@email.com" required>
          </div>
          <div class="sh-field">
            <label class="sh-label" for="add_phone">Phone</label>
            <input id="add_phone" name="phone" type="text" class="sh-input" placeholder="+91-9876543210">
          </div>
          <div class="sh-field">
            <label class="sh-label" for="add_position">Applied position <span class="req" aria-hidden="true">*</span></label>
            <input id="add_position" name="position" type="text" class="sh-input" placeholder="Full Stack Developer" required>
          </div>
          <div class="sh-field sh-colspan">
            <label class="sh-label" for="add_skills">Skills</label>
            <input id="add_skills" name="skills" type="text" class="sh-input" placeholder="React, Node.js, Python, MySQL">
            <p class="sh-help">Comma-separated — used by the resume scoring engine.</p>
          </div>
          <div class="sh-field sh-colspan">
            <label class="sh-label" for="add_resume">Resume notes</label>
            <textarea id="add_resume" name="resume_note" class="sh-input" placeholder="Brief summary of experience…"></textarea>
          </div>
          <div class="sh-field">
            <label class="sh-label" for="add_status">Status</label>
            <select id="add_status" name="status" class="sh-input">
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
        <button type="button" class="sh-btn sh-btn-secondary" onclick="closeModal('candidateModal')">Cancel</button>
        <button type="submit" class="sh-btn sh-btn-primary">Add candidate</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Edit Candidate Modal ─────────────────────────────── -->
<div class="modal-overlay" id="editModal" role="dialog" aria-modal="true" aria-labelledby="editTitle">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title" id="editTitle">Edit candidate</h3>
      <button class="modal-close" onclick="closeModal('editModal')" aria-label="Close dialog">×</button>
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="form_action" value="update">
      <input type="hidden" name="candidate_id" id="edit_id">
      <div class="modal-body">
        <div class="sh-form-grid">
          <div class="sh-field">
            <label class="sh-label" for="edit_name">Full name <span class="req" aria-hidden="true">*</span></label>
            <input id="edit_name" name="name" type="text" class="sh-input" required>
          </div>
          <div class="sh-field">
            <label class="sh-label" for="edit_email">Email <span class="req" aria-hidden="true">*</span></label>
            <input id="edit_email" name="email" type="email" class="sh-input" required>
          </div>
          <div class="sh-field">
            <label class="sh-label" for="edit_phone">Phone</label>
            <input id="edit_phone" name="phone" type="text" class="sh-input">
          </div>
          <div class="sh-field">
            <label class="sh-label" for="edit_position">Applied position</label>
            <input id="edit_position" name="position" type="text" class="sh-input">
          </div>
          <div class="sh-field sh-colspan">
            <label class="sh-label" for="edit_skills">Skills</label>
            <input id="edit_skills" name="skills" type="text" class="sh-input">
          </div>
          <div class="sh-field sh-colspan">
            <label class="sh-label" for="edit_resume">Resume notes</label>
            <textarea id="edit_resume" name="resume_note" class="sh-input"></textarea>
          </div>
          <div class="sh-field">
            <label class="sh-label" for="edit_status">Status</label>
            <select id="edit_status" name="status" class="sh-input">
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
        <button type="button" class="sh-btn sh-btn-secondary" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="sh-btn sh-btn-primary">Save changes</button>
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
function shShowCandidate(c, trigger) {
  document.getElementById('panelAvatar').textContent   = (c.name || '?').charAt(0).toUpperCase();
  document.getElementById('panelName').textContent     = c.name || '';
  document.getElementById('panelPosition').textContent = c.position || '';
  document.getElementById('panelEmail').textContent    = c.email || '—';
  document.getElementById('panelPhone').textContent    = c.phone || '—';
  document.getElementById('panelStatus').textContent   = c.status || '—';
  document.getElementById('panelScore').textContent    = (c.ai_score || 0) + '%';
  document.getElementById('panelSkills').textContent   = c.skills || '—';
  document.getElementById('panelNotes').textContent    = c.resume_note || '—';
  document.getElementById('panelProfileLink').href     = 'candidate_detail.php?id=' + c.id;
  document.getElementById('panelResultLink').href      = 'candidate_final_result.php?id=' + c.id;
  shOpenSlideover('candidatePanel', trigger);
}
<?php if ($action === 'new'): ?>
openModal('candidateModal');
<?php endif; ?>
<?php if ($action === 'edit' && $editCandidate): ?>
openEditModal(<?= json_encode($editCandidate) ?>);
<?php endif; ?>
</script>

<?php renderFooter(); ?>
