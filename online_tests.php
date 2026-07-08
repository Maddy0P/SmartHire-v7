<?php
require_once 'includes/layout.php';
require_once 'includes/mailer.php';
requireLogin();
requireRole('recruiter');
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

// ── Handle CRUD ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fa = $_POST['form_action'] ?? '';

    if ($fa === 'create') {
        $token = generateToken(24);
        $tid = dbExecute("INSERT INTO online_tests (title,description,candidate_id,created_by,duration_minutes,total_marks,passing_marks,status,test_link_token,scheduled_date,expiry_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            'ssiiiiissss',
            trim($_POST['title']), trim($_POST['description']), (int)$_POST['candidate_id'],
            currentUser()['id'], (int)$_POST['duration_minutes'], (int)$_POST['total_marks'],
            (int)$_POST['passing_marks'], 'active', $token,
            $_POST['scheduled_date'], $_POST['expiry_date']);

        if ($tid) {
            // Determine questions: preset OR manual selection
            $questionIds = [];
            if (!empty($_POST['preset_id']) && (int)$_POST['preset_id'] > 0) {
                $presetQs = dbFetchAll("SELECT question_id FROM question_preset_items WHERE preset_id=?", 'i', (int)$_POST['preset_id']);
                $questionIds = array_column($presetQs, 'question_id');
            }
            // Merge manual selections too
            if (!empty($_POST['question_ids'])) {
                foreach ($_POST['question_ids'] as $qid) { $questionIds[] = (int)$qid; }
                $questionIds = array_unique($questionIds);
            }

            // Perf: fetch max_score for ALL selected questions in one query
            // (was one query per question in the loop below).
            $maxScoreById = [];
            if (!empty($questionIds)) {
                $ph = implode(',', array_fill(0, count($questionIds), '?'));
                $rows = dbFetchAll("SELECT id, max_score FROM interview_questions WHERE id IN ($ph)",
                    str_repeat('i', count($questionIds)), ...array_map('intval', $questionIds));
                foreach ($rows as $row) { $maxScoreById[(int)$row['id']] = $row['max_score']; }
            }
            $order = 1;
            $totalCalc = 0;
            foreach ($questionIds as $qid) {
                $qid = (int)$qid;
                $maxScore = $maxScoreById[$qid] ?? 10;
                $perQTime = (int)($_POST['per_q_time'] ?? 0); // global per-q time if set
                dbExecute("INSERT INTO test_questions (test_id,question_id,marks,order_no,time_limit_secs) VALUES (?,?,?,?,?)",
                    'iiiii', $tid, $qid, $maxScore, $order++, $perQTime);
                $totalCalc += $maxScore;
            }
            // Update total_marks if auto-calc
            if (!empty($_POST['auto_calc_marks'])) {
                dbExecute("UPDATE online_tests SET total_marks=? WHERE id=?", 'ii', $totalCalc, $tid);
            }
            // Notify the candidate their assessment is ready (existing template + transport).
            // Fail-safe: sh_email_candidate never throws; a failed send is logged, workflow continues.
            sh_email_candidate((int)$_POST['candidate_id'], 'test_assigned', ['job' => trim($_POST['title'])]);
        }
        setFlash('success', 'Test created successfully!');
        header('Location: online_tests.php'); exit;

    } elseif ($fa === 'delete') {
        dbExecute("DELETE FROM online_tests WHERE id=?", 'i', (int)$_POST['test_id']);
        setFlash('success', 'Test deleted.'); header('Location: online_tests.php'); exit;

    } elseif ($fa === 'activate') {
        dbExecute("UPDATE online_tests SET status='active' WHERE id=?", 'i', (int)$_POST['test_id']);
        setFlash('success', 'Test activated.'); header('Location: online_tests.php'); exit;
    }
}

// Auto-expire tests whose expiry date has passed
dbExecute("UPDATE online_tests SET status='expired' WHERE status='active' AND expiry_date IS NOT NULL AND expiry_date < CURRENT_DATE");

$tests = dbFetchAll("SELECT ot.*, c.name AS cname, c.position AS cposition,
    (SELECT COUNT(*) FROM test_questions WHERE test_id=ot.id) AS q_count,
    (SELECT ts.percentage FROM test_submissions ts WHERE ts.test_id=ot.id AND ts.candidate_id=ot.candidate_id AND ts.status IN ('submitted','auto_submitted') LIMIT 1) AS test_score
    FROM online_tests ot JOIN candidates c ON c.id=ot.candidate_id
    ORDER BY ot.created_at DESC");
$candidates = dbFetchAll("SELECT id,name,position,email FROM candidates WHERE status NOT IN ('rejected') ORDER BY name");
$questions  = dbFetchAll("SELECT * FROM interview_questions ORDER BY category, difficulty");
$presets    = dbFetchAll("SELECT * FROM question_presets ORDER BY name");

// Group questions by category for preset preview
$qByCategory = [];
foreach ($questions as $q) { $qByCategory[$q['category']][] = $q; }

renderHead('Online Tests');
renderSidebar('online_tests');
?>

<div class="page-header">
  <div class="page-header-left">
    <div class="breadcrumb"><a href="dashboard.php">Home</a> <i class="fa-solid fa-chevron-right"></i> Online Tests</div>
    <h1 class="page-title">Online Assessments</h1>
    <p class="page-subtitle"><?= count($tests) ?> test(s) — Assign tests to candidates for evaluation</p>
  </div>
  <button class="btn btn-primary" onclick="openModal('createTestModal')">
    <i class="fa-solid fa-plus"></i> Create Test
  </button>
</div>

<div class="card">
  <div class="card-header"><div class="card-title">All Tests</div></div>
  <?php if (empty($tests)): ?>
    <div style="text-align:center;padding:60px;color:var(--text-muted)">
      <i class="fa-solid fa-laptop-code" style="font-size:48px;margin-bottom:16px;display:block;opacity:.3"></i>
      <p>No tests created yet. Click "Create Test" to get started.</p>
    </div>
  <?php else: ?>
  <div class="table-container">
    <table><thead><tr>
      <th>Test Title</th><th>Candidate</th><th>Questions</th><th>Duration</th>
      <th>Status</th><th>Score</th><th>Expiry</th><th>Actions</th>
    </tr></thead><tbody>
      <?php foreach ($tests as $t):
        $sc = $t['test_score'] !== null ? round($t['test_score']) : null;
        $scColor = $sc !== null ? getScoreColor($sc) : 'blue';
      ?>
      <tr>
        <td>
          <strong style="color:var(--text)"><?= htmlspecialchars($t['title']) ?></strong>
          <?php if ($t['description']): ?>
            <div style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars(substr($t['description'],0,60)) ?>…</div>
          <?php endif; ?>
        </td>
        <td>
          <div style="font-weight:600"><?= htmlspecialchars($t['cname']) ?></div>
          <div style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($t['cposition']) ?></div>
        </td>
        <td><span class="badge badge-blue"><?= $t['q_count'] ?> Q</span></td>
        <td><?= $t['duration_minutes'] ?> mins</td>
        <td>
          <span class="badge badge-<?= ['active'=>'green','completed'=>'blue','pending'=>'amber','expired'=>'rose'][$t['status']] ?? 'blue' ?>">
            <?= ucfirst($t['status']) ?>
          </span>
        </td>
        <td>
          <?php if ($sc !== null): ?>
            <span class="badge badge-<?= $scColor ?>"><?= $sc ?>%</span>
          <?php else: ?><span style="color:var(--text-muted)">—</span>
          <?php endif; ?>
        </td>
        <td style="font-size:13px"><?= htmlspecialchars($t['expiry_date'] ?? '—') ?></td>
        <td>
          <div style="display:flex;gap:6px;align-items:center">
            <a href="view_test_result.php?id=<?= $t['id'] ?>" class="btn btn-xs btn-secondary" title="View Result">
              <i class="fa-solid fa-eye"></i>
            </a>
            <button class="btn btn-xs btn-secondary" onclick="copyLink('<?= $t['test_link_token'] ?>')" title="Copy Test Link">
              <i class="fa-solid fa-link"></i>
            </button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this test?')">
      <?= csrf_field() ?>
              <input type="hidden" name="form_action" value="delete">
              <input type="hidden" name="test_id" value="<?= $t['id'] ?>">
              <button type="submit" class="btn btn-xs btn-danger"><i class="fa-solid fa-trash"></i></button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody></table>
  </div>
  <?php endif; ?>
</div>

<!-- ── Create Test Modal ── -->
<div class="modal-overlay" id="createTestModal">
  <div class="modal" style="max-width:780px;max-height:92vh;overflow-y:auto">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fa-solid fa-laptop-code"></i> Create Online Test</h3>
      <button class="modal-close" onclick="closeModal('createTestModal')">×</button>
    </div>
    <form method="POST" id="createTestForm">
      <?= csrf_field() ?>
      <input type="hidden" name="form_action" value="create">
      <div class="modal-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div class="form-group" style="grid-column:1/-1">
            <label class="form-label">Test Title *</label>
            <input type="text" name="title" class="form-control" placeholder="e.g. Frontend Developer Assessment" required>
          </div>
          <div class="form-group" style="grid-column:1/-1">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="2" placeholder="Brief description of what the test covers…"></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Candidate *</label>
            <select name="candidate_id" class="form-control" required>
              <option value="">— Select Candidate —</option>
              <?php foreach ($candidates as $c): ?>
              <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> — <?= htmlspecialchars($c['position']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Duration (minutes) *</label>
            <input type="number" name="duration_minutes" class="form-control" value="60" min="5" max="300" required>
          </div>
          <div class="form-group">
            <label class="form-label">Total Marks <small style="color:var(--text-muted)">(or auto-calc)</small></label>
            <input type="number" name="total_marks" class="form-control" value="100" min="10" id="totalMarksInput">
            <label style="display:flex;align-items:center;gap:6px;margin-top:6px;font-size:12px;color:var(--text-muted);cursor:pointer">
              <input type="checkbox" name="auto_calc_marks" value="1" onchange="document.getElementById('totalMarksInput').disabled=this.checked">
              Auto-calculate from selected questions
            </label>
          </div>
          <div class="form-group">
            <label class="form-label">Passing Marks</label>
            <input type="number" name="passing_marks" class="form-control" value="40" min="1">
          </div>
          <div class="form-group">
            <label class="form-label">Scheduled Date</label>
            <input type="date" name="scheduled_date" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Expiry Date</label>
            <input type="date" name="expiry_date" class="form-control" value="<?= date('Y-m-d', strtotime('+14 days')) ?>">
          </div>

          <!-- Per-question time limit -->
          <div class="form-group" style="grid-column:1/-1">
            <label class="form-label"><i class="fa-solid fa-stopwatch" style="color:var(--accent)"></i> Per-Question Time Limit</label>
            <div style="display:flex;align-items:center;gap:12px">
              <select name="per_q_time" class="form-control" style="max-width:200px">
                <option value="0">No per-question limit</option>
                <option value="30">30 seconds</option>
                <option value="45">45 seconds</option>
                <option value="60">1 minute</option>
                <option value="90">1.5 minutes</option>
                <option value="120">2 minutes</option>
                <option value="180">3 minutes</option>
                <option value="300">5 minutes</option>
              </select>
              <span style="font-size:12px;color:var(--text-muted)">If set, unanswered questions auto-advance and mark wrong</span>
            </div>
          </div>
        </div>

        <!-- ── Question Selection — Preset OR Manual ── -->
        <div style="margin-top:16px">
          <div style="display:flex;gap:0;border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:14px">
            <button type="button" id="tabPreset" class="tab-btn active" onclick="switchTab('preset')"
              style="flex:1;padding:10px;background:var(--accent);color:#fff;border:none;cursor:pointer;font-size:13px;font-weight:600">
              <i class="fa-solid fa-layer-group"></i> Category Preset
            </button>
            <button type="button" id="tabManual" class="tab-btn" onclick="switchTab('manual')"
              style="flex:1;padding:10px;background:transparent;color:var(--text-muted);border:none;cursor:pointer;font-size:13px;font-weight:600">
              <i class="fa-solid fa-list-check"></i> Manual Selection
            </button>
          </div>

          <!-- Preset Panel -->
          <div id="panelPreset">
            <label class="form-label">Select Question Category Preset</label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">
              <?php foreach ($presets as $p): ?>
              <label style="display:flex;align-items:flex-start;gap:10px;padding:12px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer;transition:all .15s" id="presetLabel_<?= $p['id'] ?>"
                     onmouseover="this.style.borderColor='var(--accent)'" onmouseout="if(!document.getElementById('preset_<?= $p['id'] ?>').checked)this.style.borderColor='var(--border)'">
                <input type="radio" name="preset_id" value="<?= $p['id'] ?>" id="preset_<?= $p['id'] ?>"
                  style="margin-top:2px;accent-color:var(--accent)"
                  onchange="onPresetChange(<?= $p['id'] ?>)">
                <div>
                  <div style="font-size:13px;font-weight:600;color:var(--text)"><?= htmlspecialchars($p['name']) ?></div>
                  <div style="font-size:11px;color:var(--text-muted);margin-top:2px"><?= htmlspecialchars($p['description']) ?></div>
                </div>
              </label>
              <?php endforeach; ?>
              <label style="display:flex;align-items:flex-start;gap:10px;padding:12px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer">
                <input type="radio" name="preset_id" value="0" checked style="margin-top:2px;accent-color:var(--accent)">
                <div>
                  <div style="font-size:13px;font-weight:600;color:var(--text)">No Preset</div>
                  <div style="font-size:11px;color:var(--text-muted);margin-top:2px">Pick questions manually</div>
                </div>
              </label>
            </div>
            <div id="presetPreview" style="background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:12px;font-size:13px;color:var(--text-muted);display:none">
              <div style="font-weight:600;color:var(--text);margin-bottom:8px"><i class="fa-solid fa-eye"></i> Preset Questions Preview</div>
              <div id="presetPreviewList"></div>
            </div>
          </div>

          <!-- Manual Panel -->
          <div id="panelManual" style="display:none">
            <label class="form-label">Select Questions Manually</label>
            <!-- Category filter tabs -->
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
              <button type="button" class="btn btn-xs btn-secondary cat-filter-btn active" onclick="filterCat('')" data-cat="">All</button>
              <?php foreach (array_unique(array_column($questions,'category')) as $cat): ?>
              <button type="button" class="btn btn-xs btn-secondary cat-filter-btn" onclick="filterCat('<?= $cat ?>')" data-cat="<?= $cat ?>"><?= ucfirst(str_replace('_',' ',$cat)) ?></button>
              <?php endforeach; ?>
            </div>
            <div style="max-height:280px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;padding:12px;background:var(--surface)" id="manualQList">
              <?php foreach ($questions as $q): ?>
              <label class="q-item" data-cat="<?= $q['category'] ?>" style="display:flex;align-items:flex-start;gap:10px;padding:8px;border-radius:6px;cursor:pointer;margin-bottom:6px;transition:background .15s"
                     onmouseover="this.style.background='rgba(255,255,255,0.04)'" onmouseout="this.style.background='transparent'">
                <input type="checkbox" name="question_ids[]" value="<?= $q['id'] ?>" style="margin-top:2px;accent-color:var(--accent)">
                <div>
                  <span style="font-size:13px;color:var(--text)"><?= htmlspecialchars(substr($q['question'],0,90)) ?>…</span>
                  <div style="margin-top:3px">
                    <span class="badge badge-<?= ['technical'=>'blue','hr'=>'violet','behavioral'=>'amber','system_design'=>'rose','coding'=>'green','mcq'=>'violet'][$q['category']] ?? 'blue' ?>" style="font-size:10px"><?= $q['category'] ?></span>
                    <span class="badge badge-<?= ['easy'=>'green','medium'=>'amber','hard'=>'rose'][$q['difficulty']] ?? 'blue' ?>" style="font-size:10px"><?= $q['difficulty'] ?></span>
                    <span style="font-size:11px;color:var(--text-muted)"><?= $q['question_type']==='mcq'?'MCQ':'Subjective' ?> • <?= $q['max_score'] ?> marks</span>
                  </div>
                </div>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('createTestModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Create & Activate Test</button>
      </div>
    </form>
  </div>
</div>

<?php
// Build preset->question map for JS
$presetQMap = [];
$allPresetItems = dbFetchAll("SELECT pqi.preset_id, pqi.question_id, iq.question, iq.question_type, iq.max_score, iq.category
    FROM question_preset_items pqi JOIN interview_questions iq ON iq.id=pqi.question_id");
foreach ($allPresetItems as $item) {
    $presetQMap[$item['preset_id']][] = $item;
}
?>
<script>
const presetQMap = <?= json_encode($presetQMap) ?>;

function switchTab(tab){
    document.getElementById('panelPreset').style.display = tab==='preset'?'block':'none';
    document.getElementById('panelManual').style.display  = tab==='manual'?'block':'none';
    document.getElementById('tabPreset').style.background = tab==='preset'?'var(--accent)':'transparent';
    document.getElementById('tabPreset').style.color      = tab==='preset'?'#fff':'var(--text-muted)';
    document.getElementById('tabManual').style.background = tab==='manual'?'var(--accent)':'transparent';
    document.getElementById('tabManual').style.color      = tab==='manual'?'#fff':'var(--text-muted)';
}

function onPresetChange(pid){
    const qs = presetQMap[pid] || [];
    const prev = document.getElementById('presetPreview');
    const list = document.getElementById('presetPreviewList');
    if(!qs.length){ prev.style.display='none'; return; }
    prev.style.display='block';
    list.innerHTML = qs.map(q=>`<div style="padding:6px 0;border-bottom:1px solid rgba(255,255,255,0.06);display:flex;gap:8px;align-items:flex-start">
        <span style="font-size:11px;background:rgba(124,58,237,0.15);color:#a78bfa;padding:2px 7px;border-radius:4px;white-space:nowrap">${q.question_type.toUpperCase()}</span>
        <span style="font-size:13px;color:var(--text)">${q.question.substring(0,80)}…</span>
        <span style="font-size:11px;color:var(--text-muted);white-space:nowrap">${q.max_score} marks</span>
    </div>`).join('');
}

function filterCat(cat){
    document.querySelectorAll('.q-item').forEach(el=>{
        el.style.display = (!cat || el.dataset.cat===cat)?'flex':'none';
    });
    document.querySelectorAll('.cat-filter-btn').forEach(btn=>{
        btn.classList.toggle('active', btn.dataset.cat===cat);
        btn.style.background = btn.dataset.cat===cat?'var(--accent)':'';
        btn.style.color = btn.dataset.cat===cat?'#fff':'';
    });
}

function copyLink(token) {
    const url = window.location.origin + window.location.pathname.replace('online_tests.php','') + 'take_test.php?token=' + token;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(()=>alert('Test link copied!\n'+url));
    } else { prompt('Copy test link:', url); }
}
</script>
<?php renderFooter(); ?>
