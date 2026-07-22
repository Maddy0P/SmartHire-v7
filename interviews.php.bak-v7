<?php
require_once 'includes/layout.php';
require_once 'includes/recruitment.php';
require_once 'includes/mailer.php';
requireLogin();
requireRole('recruiter');           // recruiter-or-higher may schedule
if ($_SERVER['REQUEST_METHOD']==='POST') require_csrf();

// ── Handle form submissions ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fa = $_POST['form_action'] ?? '';

    if ($fa === 'create') {
        $ok = dbExecute(
            "INSERT INTO interviews (candidate_id,interviewer,scheduled_date,scheduled_time,type,mode,status,notes)
             VALUES (?,?,?,?,?,?,?,?)",
            'isssssss',
            (int)$_POST['candidate_id'],
            trim($_POST['interviewer']),
            $_POST['scheduled_date'],
            $_POST['scheduled_time'],
            $_POST['type'],
            $_POST['mode'],
            $_POST['status'] ?? 'scheduled',
            trim($_POST['notes'] ?? '')
        );
        if ($ok) {
            addNotification('interview_scheduled',
                'Interview scheduled with ' . (dbFetchOne("SELECT name FROM candidates WHERE id=?", 'i', (int)$_POST['candidate_id'])['name'] ?? 'candidate'),
                (int)$_POST['candidate_id']);
            audit_log('interview_create', 'interview', is_int($ok) ? $ok : null);
            sh_advance_candidate_applications((int)$_POST['candidate_id'], 'interview_scheduled', 'Interview scheduled');
            // Invite the candidate (existing template + transport).
            // Fail-safe: sh_email_candidate never throws; a failed send is logged, workflow continues.
            $__ivType = ucfirst(trim($_POST['type'] ?? 'interview'));
            $__ivWhen = trim(($_POST['scheduled_date'] ?? '') . ' at ' . ($_POST['scheduled_time'] ?? ''));
            $__ivMode = trim($_POST['mode'] ?? '');
            sh_email_candidate((int)$_POST['candidate_id'], 'interview_invite', [
                'job'   => 'a ' . $__ivType . ' round',
                'extra' => 'Scheduled for ' . $__ivWhen . ($__ivMode ? ' (' . $__ivMode . ')' : '') . '.',
            ]);
        }
        setFlash($ok ? 'success' : 'error', $ok ? 'Interview scheduled!' : 'Failed to schedule.');
        header('Location: interviews.php'); exit;

    } elseif ($fa === 'update') {
        $__cand_for_sync = (int)($_POST['candidate_id'] ?? 0);
        $ok = dbExecute(
            "UPDATE interviews SET candidate_id=?,interviewer=?,scheduled_date=?,scheduled_time=?,type=?,mode=?,status=?,notes=? WHERE id=?",
            'isssssssi',
            (int)$_POST['candidate_id'],
            trim($_POST['interviewer']),
            $_POST['scheduled_date'],
            $_POST['scheduled_time'],
            $_POST['type'],
            $_POST['mode'],
            $_POST['status'],
            trim($_POST['notes'] ?? ''),
            (int)$_POST['interview_id']
        );
        if ($ok && ($_POST['status'] ?? '') === 'completed' && $__cand_for_sync) {
            sh_advance_candidate_applications($__cand_for_sync, 'interview_completed', 'Interview completed');
        }
        setFlash($ok ? 'success' : 'error', $ok ? 'Interview updated!' : 'Update failed.');
        header('Location: interviews.php'); exit;

    } elseif ($fa === 'delete') {
        dbExecute("DELETE FROM interviews WHERE id=?", 'i', (int)$_POST['interview_id']);
        setFlash('success', 'Interview removed.');
        header('Location: interviews.php'); exit;
    }
}

// ── Fetch data ────────────────────────────────────────────
$filter = $_GET['status'] ?? '';
$sql = "SELECT i.*, c.name AS candidate_name, c.position, c.email AS candidate_email
        FROM interviews i
        JOIN candidates c ON c.id = i.candidate_id"
     . ($filter ? " WHERE i.status=?" : "")
     . " ORDER BY i.scheduled_date DESC, i.scheduled_time DESC";
$interviews = $filter ? dbFetchAll($sql, 's', $filter) : dbFetchAll($sql);
$candidates = dbFetchAll("SELECT id, name, position FROM candidates ORDER BY name");

$counts = [
    'all'       => dbFetchOne("SELECT COUNT(*) n FROM interviews")['n'],
    'scheduled' => dbFetchOne("SELECT COUNT(*) n FROM interviews WHERE status='scheduled'")['n'],
    'completed' => dbFetchOne("SELECT COUNT(*) n FROM interviews WHERE status='completed'")['n'],
    'cancelled' => dbFetchOne("SELECT COUNT(*) n FROM interviews WHERE status='cancelled'")['n'],
];

renderHead('Interviews');
renderSidebar('interviews');
?>

<div class="page-header">
  <div class="page-header-left">
    <div class="breadcrumb"><a href="dashboard.php">Home</a> <i class="fa-solid fa-chevron-right"></i> Interviews</div>
    <h1 class="page-title">Interviews</h1>
    <p class="page-subtitle"><?= $counts['all'] ?> total sessions tracked</p>
  </div>
  <button class="btn btn-primary" onclick="openModal('ivModal')">
    <i class="fa-solid fa-calendar-plus"></i> Schedule Interview
  </button>
</div>

<!-- Filter tabs -->
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:20px;">
  <?php
  $tabs=[''=>'All','scheduled'=>'Scheduled','completed'=>'Completed','cancelled'=>'Cancelled'];
  foreach($tabs as $val=>$label):
    $act = $filter===$val?'btn-primary':'btn-secondary';
    $cnt = $val?$counts[$val]:$counts['all'];
  ?>
  <a href="interviews.php<?=$val?"?status=$val":''?>" class="btn <?=$act?> btn-sm">
    <?=$label?> <span style="opacity:.7">(<?=$cnt?>)</span>
  </a>
  <?php endforeach; ?>
</div>

<div class="card" style="margin-bottom:16px;">
  <div class="card-body" style="padding:12px 16px;">
    <div style="display:flex;gap:10px;align-items:center;">
      <i class="fa-solid fa-search" style="color:var(--text-muted)"></i>
      <input type="text" id="tableSearch" class="form-control" aria-label="Search interviews" placeholder="Search candidate, interviewer…" style="border:none;background:transparent;padding:0;flex:1;">
    </div>
  </div>
</div>

<div class="card">
  <div class="table-container">
    <table>
      <thead>
        <tr>
          <th>#</th><th>Candidate</th><th>Interviewer</th>
          <th>Date & Time</th><th>Type</th><th>Mode</th>
          <th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if(empty($interviews)): ?>
        <tr><td colspan="8" class="text-center" style="padding:40px;color:var(--text-muted)">
          <i class="fa-solid fa-calendar-xmark" style="font-size:24px;display:block;margin-bottom:8px"></i>
          No interviews found
        </td></tr>
        <?php endif; ?>
        <?php foreach($interviews as $i=>$iv): ?>
        <tr>
          <td class="td-muted"><?=$i+1?></td>
          <td>
            <div class="d-flex align-center gap-2">
              <div class="avatar sm"><?=strtoupper(substr($iv['candidate_name'],0,1))?></div>
              <div>
                <div class="fw-600"><?=htmlspecialchars($iv['candidate_name'])?></div>
                <div class="td-muted"><?=htmlspecialchars($iv['position'])?></div>
              </div>
            </div>
          </td>
          <td><?=htmlspecialchars($iv['interviewer'])?></td>
          <td>
            <div class="fw-600"><?=date('d M Y',strtotime($iv['scheduled_date']))?></div>
            <div class="td-muted"><?=date('g:i A',strtotime($iv['scheduled_time']))?></div>
          </td>
          <td><span class="badge-status badge-scheduled" style="text-transform:capitalize"><?=$iv['type']?></span></td>
          <td>
            <?php if($iv['mode']==='online'): ?>
            <span style="color:var(--accent-light);font-size:12px"><i class="fa-solid fa-video"></i> Online</span>
            <?php else: ?>
            <span style="color:var(--emerald);font-size:12px"><i class="fa-solid fa-building"></i> In-Person</span>
            <?php endif; ?>
          </td>
          <td><span class="badge-status badge-<?=str_replace('-','_',$iv['status'])?>"><?=$iv['status']?></span></td>
          <td>
            <div class="d-flex gap-2">
              <button onclick='openEditIv(<?=htmlspecialchars(json_encode($iv))?>)'
                      class="btn btn-secondary btn-sm btn-icon" title="Edit">
                <i class="fa-solid fa-pen-to-square"></i>
              </button>
              <form method="POST" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="delete">
                <input type="hidden" name="interview_id" value="<?=$iv['id']?>">
                <button type="submit" class="btn btn-danger btn-sm btn-icon"
                        data-confirm="Delete this interview?" title="Delete">
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

<!-- ── Schedule Modal ──── -->
<div class="modal-overlay" id="ivModal">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fa-solid fa-calendar-plus"></i> Schedule Interview</h3>
      <button class="modal-close" onclick="closeModal('ivModal')">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="form_action" value="create">
      <?= csrf_field() ?>
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group form-full">
            <label class="form-label">Candidate *</label>
            <select name="candidate_id" class="form-control" required>
              <option value="">— Select Candidate —</option>
              <?php foreach($candidates as $c): ?>
              <option value="<?=$c['id']?>"><?=htmlspecialchars($c['name'])?> (<?=htmlspecialchars($c['position'])?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Interviewer *</label>
            <input name="interviewer" type="text" class="form-control" placeholder="e.g. Rahul Sharma" required>
          </div>
          <div class="form-group">
            <label class="form-label">Type</label>
            <select name="type" class="form-control">
              <option value="technical">Technical</option>
              <option value="hr">HR</option>
              <option value="final">Final Round</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Date *</label>
            <input name="scheduled_date" type="date" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label">Time *</label>
            <input name="scheduled_time" type="time" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label">Mode</label>
            <select name="mode" class="form-control">
              <option value="online">Online</option>
              <option value="in-person">In-Person</option>
            </select>
          </div>
          <div class="form-group form-full">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" placeholder="Preparation notes…"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('ivModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Schedule</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Edit Modal ──── -->
<div class="modal-overlay" id="editIvModal">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fa-solid fa-pen-to-square"></i> Edit Interview</h3>
      <button class="modal-close" onclick="closeModal('editIvModal')">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="form_action" value="update">
      <?= csrf_field() ?>
      <input type="hidden" name="interview_id" id="eiv_id">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group form-full">
            <label class="form-label">Candidate</label>
            <select id="eiv_cid" name="candidate_id" class="form-control">
              <?php foreach($candidates as $c): ?>
              <option value="<?=$c['id']?>"><?=htmlspecialchars($c['name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Interviewer</label>
            <input id="eiv_interviewer" name="interviewer" type="text" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Type</label>
            <select id="eiv_type" name="type" class="form-control">
              <option value="technical">Technical</option>
              <option value="hr">HR</option>
              <option value="final">Final Round</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Date</label>
            <input id="eiv_date" name="scheduled_date" type="date" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Time</label>
            <input id="eiv_time" name="scheduled_time" type="time" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Mode</label>
            <select id="eiv_mode" name="mode" class="form-control">
              <option value="online">Online</option>
              <option value="in-person">In-Person</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Status</label>
            <select id="eiv_status" name="status" class="form-control">
              <option value="scheduled">Scheduled</option>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
              <option value="no-show">No Show</option>
            </select>
          </div>
          <div class="form-group form-full">
            <label class="form-label">Notes</label>
            <textarea id="eiv_notes" name="notes" class="form-control"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editIvModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Save</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditIv(iv) {
  document.getElementById('eiv_id').value          = iv.id;
  document.getElementById('eiv_cid').value          = iv.candidate_id;
  document.getElementById('eiv_interviewer').value  = iv.interviewer || '';
  document.getElementById('eiv_date').value          = iv.scheduled_date || '';
  document.getElementById('eiv_time').value          = iv.scheduled_time || '';
  document.getElementById('eiv_type').value          = iv.type;
  document.getElementById('eiv_mode').value          = iv.mode;
  document.getElementById('eiv_status').value        = iv.status;
  document.getElementById('eiv_notes').value         = iv.notes || '';
  openModal('editIvModal');
}
</script>

<?php renderFooter(); ?>
