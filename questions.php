<?php
require_once 'includes/layout.php';
requireLogin();
requireRole('recruiter');
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

// ── Handle CRUD ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fa = $_POST['form_action'] ?? '';

    if ($fa === 'create') {
        $ok = dbExecute(
            "INSERT INTO interview_questions (category,difficulty,position_tag,question,expected_answer,max_score) VALUES (?,?,?,?,?,?)",
            'sssssi',
            $_POST['category'], $_POST['difficulty'], trim($_POST['position_tag']),
            trim($_POST['question']), trim($_POST['expected_answer']), (int)$_POST['max_score']
        );
        setFlash($ok ? 'success' : 'error', $ok ? 'Question added to bank!' : 'Failed to add question.');
        header('Location: questions.php'); exit;

    } elseif ($fa === 'update') {
        $ok = dbExecute(
            "UPDATE interview_questions SET category=?,difficulty=?,position_tag=?,question=?,expected_answer=?,max_score=? WHERE id=?",
            'sssssii',
            $_POST['category'], $_POST['difficulty'], trim($_POST['position_tag']),
            trim($_POST['question']), trim($_POST['expected_answer']),
            (int)$_POST['max_score'], (int)$_POST['question_id']
        );
        setFlash($ok ? 'success' : 'error', $ok ? 'Question updated!' : 'Update failed.');
        header('Location: questions.php'); exit;

    } elseif ($fa === 'delete') {
        dbExecute("DELETE FROM interview_questions WHERE id=?", 'i', (int)$_POST['question_id']);
        setFlash('success', 'Question deleted.');
        header('Location: questions.php'); exit;
    }
}

// ── Fetch ─────────────────────────────────────────────────
$filterCat = $_GET['cat'] ?? '';
$questions = $filterCat
    ? dbFetchAll("SELECT * FROM interview_questions WHERE category=? ORDER BY difficulty, category", 's', $filterCat)
    : dbFetchAll("SELECT * FROM interview_questions ORDER BY category, difficulty");

$catCounts = dbFetchAll("SELECT category, COUNT(*) as n FROM interview_questions GROUP BY category");
$catMap = array_column($catCounts, 'n', 'category');
$total = array_sum($catMap);

$diffColor = ['easy'=>'green','medium'=>'amber','hard'=>'rose'];
$catColor  = ['technical'=>'blue','hr'=>'violet','behavioral'=>'amber','system_design'=>'rose','coding'=>'green'];

renderHead('Question Bank');
renderSidebar('questions');
?>

<div class="page-header">
  <div class="page-header-left">
    <div class="breadcrumb"><a href="dashboard.php">Home</a> <i class="fa-solid fa-chevron-right"></i> Question Bank</div>
    <h1 class="page-title">Interview Question Bank</h1>
    <p class="page-subtitle"><?= $total ?> questions across all categories</p>
  </div>
  <button class="btn btn-primary" onclick="openModal('qModal')">
    <i class="fa-solid fa-plus"></i> Add Question
  </button>
</div>

<!-- Category filter cards -->
<div class="stats-grid" style="margin-bottom:20px">
  <?php
  $cats = [
    ['','fa-list','All Questions',$total,'blue'],
    ['technical','fa-code','Technical',$catMap['technical']??0,'blue'],
    ['hr','fa-comments','HR',$catMap['hr']??0,'violet'],
    ['behavioral','fa-brain','Behavioral',$catMap['behavioral']??0,'amber'],
    ['system_design','fa-sitemap','System Design',$catMap['system_design']??0,'rose'],
    ['coding','fa-terminal','Coding',$catMap['coding']??0,'green'],
  ];
  foreach ($cats as [$val,$icon,$label,$cnt,$color]):
  $isActive = $filterCat === $val;
  ?>
  <a href="questions.php<?= $val?"?cat=$val":'' ?>" style="text-decoration:none">
    <div class="stat-card <?= $color ?>" style="<?= $isActive?'border-color:var(--accent);box-shadow:var(--shadow-glow)':'' ?>">
      <div class="stat-icon <?= $color ?>"><i class="fa-solid <?= $icon ?>"></i></div>
      <div class="stat-info">
        <div class="stat-value"><?= $cnt ?></div>
        <div class="stat-label"><?= $label ?></div>
      </div>
    </div>
  </a>
  <?php endforeach; ?>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-body" style="padding:12px 16px">
    <div style="display:flex;gap:10px;align-items:center">
      <i class="fa-solid fa-search" style="color:var(--text-muted)"></i>
      <input type="text" id="tableSearch" class="form-control" placeholder="Search questions…" style="border:none;background:transparent;padding:0;flex:1">
    </div>
  </div>
</div>

<div class="card">
  <div class="table-container">
    <table>
      <thead>
        <tr>
          <th>#</th><th style="min-width:380px">Question</th><th>Category</th>
          <th>Difficulty</th><th>Position Tag</th><th>Max Score</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($questions)): ?>
        <tr><td colspan="7" class="text-center" style="padding:40px;color:var(--text-muted)">
          <i class="fa-solid fa-circle-question" style="font-size:28px;display:block;margin-bottom:8px"></i>No questions yet
        </td></tr>
        <?php endif; ?>
        <?php foreach ($questions as $i => $q): ?>
        <tr>
          <td class="td-muted"><?= $i+1 ?></td>
          <td>
            <div style="font-size:13.5px;font-weight:500;line-height:1.5"><?= htmlspecialchars(substr($q['question'],0,120)) ?>…</div>
            <?php if($q['expected_answer']): ?>
            <div class="td-muted" style="font-size:12px;margin-top:3px">
              <i class="fa-solid fa-key" style="color:var(--emerald)"></i>
              <?= htmlspecialchars(substr($q['expected_answer'],0,80)) ?>…
            </div>
            <?php endif; ?>
          </td>
          <td>
            <?php $cc = $catColor[$q['category']] ?? 'blue'; ?>
            <span class="badge-status badge-<?= $cc==='blue'?'scheduled':($cc==='violet'?'interviewed':($cc==='amber'?'pending':($cc==='green'?'hired':'rejected'))) ?>"
                  style="text-transform:capitalize"><?= str_replace('_',' ',$q['category']) ?></span>
          </td>
          <td>
            <?php $dc = $diffColor[$q['difficulty']] ?? 'amber'; ?>
            <span style="color:var(--<?= $dc ?>);font-weight:600;font-size:12px;text-transform:capitalize"><?= $q['difficulty'] ?></span>
          </td>
          <td class="td-muted"><?= htmlspecialchars($q['position_tag']) ?></td>
          <td><span style="font-weight:700;color:var(--accent-light)"><?= $q['max_score'] ?></span></td>
          <td>
            <div class="d-flex gap-2">
              <button onclick='openEditQ(<?= htmlspecialchars(json_encode($q)) ?>)'
                      class="btn btn-secondary btn-sm btn-icon" title="Edit">
                <i class="fa-solid fa-pen-to-square"></i>
              </button>
              <form method="POST" style="display:inline">
      <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="delete">
                <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm btn-icon"
                        data-confirm="Delete this question?" title="Delete">
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

<!-- ── Add Question Modal ─────────────────────────────────── -->
<?php foreach (['qModal'=>['create','Add Question to Bank'],'editQModal'=>['update','Edit Question']] as $modalId=>[$action,$title]): ?>
<div class="modal-overlay" id="<?= $modalId ?>">
  <div class="modal" style="max-width:620px">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fa-solid fa-circle-question"></i> <?= $title ?></h3>
      <button class="modal-close" onclick="closeModal('<?= $modalId ?>')">×</button>
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="form_action" value="<?= $action ?>">
      <?php if($action==='update'): ?><input type="hidden" name="question_id" id="<?=$modalId?>_id"><?php endif; ?>
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Category *</label>
            <select name="category" id="<?=$modalId?>_cat" class="form-control" required>
              <option value="technical">Technical</option>
              <option value="hr">HR</option>
              <option value="behavioral">Behavioral</option>
              <option value="system_design">System Design</option>
              <option value="coding">Coding</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Difficulty</label>
            <select name="difficulty" id="<?=$modalId?>_diff" class="form-control">
              <option value="easy">Easy</option>
              <option value="medium" selected>Medium</option>
              <option value="hard">Hard</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Position Tag</label>
            <input name="position_tag" id="<?=$modalId?>_pos" type="text" class="form-control" placeholder="e.g. Full Stack Developer">
          </div>
          <div class="form-group">
            <label class="form-label">Max Score (points)</label>
            <input name="max_score" id="<?=$modalId?>_max" type="number" min="1" max="100" class="form-control" value="10">
          </div>
          <div class="form-group form-full">
            <label class="form-label">Question *</label>
            <textarea name="question" id="<?=$modalId?>_q" class="form-control" rows="3" placeholder="Enter the interview question…" required></textarea>
          </div>
          <div class="form-group form-full">
            <label class="form-label">Expected / Model Answer</label>
            <textarea name="expected_answer" id="<?=$modalId?>_ans" class="form-control" rows="3" placeholder="Key points the candidate should mention…"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('<?= $modalId ?>')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> <?= $action==='create'?'Add Question':'Save Changes' ?></button>
      </div>
    </form>
  </div>
</div>
<?php endforeach; ?>

<script>
function openEditQ(q) {
  document.getElementById('editQModal_id').value   = q.id;
  document.getElementById('editQModal_cat').value  = q.category;
  document.getElementById('editQModal_diff').value = q.difficulty;
  document.getElementById('editQModal_pos').value  = q.position_tag || '';
  document.getElementById('editQModal_q').value    = q.question;
  document.getElementById('editQModal_ans').value  = q.expected_answer || '';
  document.getElementById('editQModal_max').value  = q.max_score;
  openModal('editQModal');
}
</script>

<?php renderFooter(); ?>
