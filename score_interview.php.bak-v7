<?php
require_once 'includes/layout.php';
require_once 'includes/recruitment.php';
requireLogin();
requireRole('interviewer');
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

$interview_id = (int)($_GET['interview_id'] ?? 0);
if (!$interview_id) { header('Location: interviews.php'); exit; }

$interview = dbFetchOne("
    SELECT i.*, c.name AS cname, c.position, c.email AS cemail, c.id AS cid
    FROM interviews i JOIN candidates c ON c.id=i.candidate_id
    WHERE i.id=?", 'i', $interview_id);
if (!$interview) { header('Location: interviews.php'); exit; }

// ── Save scores ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Delete old responses for this interview
    dbExecute("DELETE FROM candidate_responses WHERE interview_id=?", 'i', $interview_id);

    $scores = $_POST['scores'] ?? [];
    $notes  = $_POST['notes']  ?? [];
    foreach ($scores as $qid => $score) {
        dbExecute(
            "INSERT INTO candidate_responses (interview_id,candidate_id,question_id,score_given,interviewer_note)
             VALUES (?,?,?,?,?)",
            'iiiss',
            $interview_id, $interview['cid'], (int)$qid,
            max(0, min((int)$score, 100)),
            trim($notes[$qid] ?? '')
        );
    }
    // Mark interview completed
    dbExecute("UPDATE interviews SET status='completed' WHERE id=?", 'i', $interview_id);
    $__iv = dbFetchOne("SELECT candidate_id FROM interviews WHERE id=?", 'i', $interview_id);
    if ($__iv) { sh_advance_candidate_applications((int)$__iv['candidate_id'], 'interview_completed', 'Interview scored'); }
    setFlash('success', 'Interview scored successfully! Candidate moved to "completed".');
    header("Location: candidate_detail.php?candidate_id={$interview['cid']}&interview_id=$interview_id");
    exit;
}

// ── Load questions & existing responses ──────────────────
$questions = dbFetchAll("SELECT * FROM interview_questions ORDER BY category, difficulty");
$existing  = dbFetchAll("SELECT * FROM candidate_responses WHERE interview_id=?", 'i', $interview_id);
$existMap  = array_column($existing, null, 'question_id');

renderHead('Score Interview');
renderSidebar('interviews');
?>

<div class="page-header">
  <div class="page-header-left">
    <div class="breadcrumb">
      <a href="dashboard.php">Home</a> <i class="fa-solid fa-chevron-right"></i>
      <a href="interviews.php">Interviews</a> <i class="fa-solid fa-chevron-right"></i>
      Score
    </div>
    <h1 class="page-title">Score Interview</h1>
    <p class="page-subtitle">Rate each question for <?= htmlspecialchars($interview['cname']) ?></p>
  </div>
  <a href="interviews.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
</div>

<!-- Candidate Info Banner -->
<div class="card" style="margin-bottom:20px;background:linear-gradient(135deg,rgba(59,130,246,.08),rgba(139,92,246,.08));border-color:rgba(59,130,246,.2)">
  <div class="card-body" style="display:flex;gap:16px;align-items:center">
    <div class="avatar lg"><?= strtoupper(substr($interview['cname'],0,1)) ?></div>
    <div style="flex:1">
      <div style="font-size:18px;font-weight:800"><?= htmlspecialchars($interview['cname']) ?></div>
      <div class="td-muted"><?= htmlspecialchars($interview['position']) ?> · <?= htmlspecialchars($interview['cemail']) ?></div>
    </div>
    <div style="text-align:right">
      <span class="badge-status badge-scheduled" style="text-transform:capitalize"><?= $interview['type'] ?></span>
      <div class="td-muted" style="margin-top:6px"><?= date('d M Y', strtotime($interview['scheduled_date'])) ?> · <?= date('g:i A', strtotime($interview['scheduled_time'])) ?></div>
    </div>
  </div>
</div>

<form method="POST" id="scoreForm">
      <?= csrf_field() ?>
  <?php
  $currentCat = '';
  $catColors  = ['technical'=>'blue','hr'=>'violet','behavioral'=>'amber','system_design'=>'rose','coding'=>'green'];
  $catIcons   = ['technical'=>'fa-code','hr'=>'fa-comments','behavioral'=>'fa-brain','system_design'=>'fa-sitemap','coding'=>'fa-terminal'];
  ?>

  <?php foreach ($questions as $q):
    $cat = $q['category'];
    $existing_score = $existMap[$q['id']]['score_given'] ?? '';
    $existing_note  = $existMap[$q['id']]['interviewer_note'] ?? '';
    $maxScore = (int)$q['max_score'];
    $cc = $catColors[$cat] ?? 'blue';
    $ci = $catIcons[$cat] ?? 'fa-circle-question';
    $diffColors = ['easy'=>'emerald','medium'=>'amber','hard'=>'rose'];
    $dc = $diffColors[$q['difficulty']] ?? 'amber';

    if ($cat !== $currentCat):
      if ($currentCat) echo '</div>'; // close previous section
      $currentCat = $cat;
  ?>
  <div style="margin-bottom:20px">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;padding:10px 16px;background:var(--bg-card);border-radius:10px;border:1px solid var(--border)">
      <div style="width:32px;height:32px;background:var(--<?=$cc?>-bg,rgba(59,130,246,.1));border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--<?=$cc==='blue'?'accent':'rose'?>)">
        <i class="fa-solid <?=$ci?>"></i>
      </div>
      <span style="font-weight:700;font-size:14px;text-transform:capitalize"><?= str_replace('_',' ',$cat) ?> Questions</span>
    </div>
  <?php endif; ?>

    <div class="card" style="margin-bottom:12px" id="qcard_<?=$q['id']?>">
      <div class="card-body">
        <div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:14px">
          <div style="flex:1">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap">
              <span style="font-size:11px;font-weight:700;color:var(--<?=$dc?>);text-transform:uppercase;letter-spacing:.5px"><?=$q['difficulty']?></span>
              <span style="font-size:11px;color:var(--text-muted)"><?=htmlspecialchars($q['position_tag'])?></span>
              <span style="font-size:11px;color:var(--text-muted)">Max: <strong style="color:var(--accent-light)"><?=$maxScore?> pts</strong></span>
            </div>
            <p style="font-size:14px;font-weight:600;line-height:1.6;color:var(--text-primary);margin:0"><?=htmlspecialchars($q['question'])?></p>
          </div>
        </div>

        <?php if ($q['expected_answer']): ?>
        <details style="margin-bottom:14px">
          <summary style="cursor:pointer;font-size:12px;color:var(--emerald);font-weight:600">
            <i class="fa-solid fa-key"></i> Show Model Answer
          </summary>
          <div style="margin-top:8px;padding:10px 14px;background:rgba(16,185,129,.08);border-radius:8px;border-left:3px solid var(--emerald);font-size:12.5px;color:var(--text-secondary);line-height:1.6">
            <?=htmlspecialchars($q['expected_answer'])?>
          </div>
        </details>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 2fr;gap:14px;align-items:center">
          <div>
            <label class="form-label" style="margin-bottom:6px">Score (0–<?=$maxScore?>)</label>
            <div style="position:relative">
              <input type="number" name="scores[<?=$q['id']?>]" min="0" max="<?=$maxScore?>"
                     class="form-control score-input" value="<?=$existing_score?>"
                     data-max="<?=$maxScore?>" data-id="<?=$q['id']?>"
                     placeholder="0–<?=$maxScore?>" oninput="updateBar(this)">
            </div>
            <div class="score-bar-track" style="margin-top:8px;height:6px">
              <div class="score-bar-fill score-medium" id="bar_<?=$q['id']?>"
                   style="width:<?=$existing_score?floor($existing_score/$maxScore*100):0?>%;height:6px;border-radius:3px;transition:width .3s"></div>
            </div>
          </div>
          <div>
            <label class="form-label">Interviewer Note</label>
            <input type="text" name="notes[<?=$q['id']?>]" class="form-control"
                   placeholder="Brief observation about the answer…"
                   value="<?=htmlspecialchars($existing_note)?>">
          </div>
        </div>
      </div>
    </div>

  <?php endforeach; ?>
  <?php if ($currentCat) echo '</div>'; ?>

  <!-- Submit -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px">
    <div id="liveScore" style="font-size:14px;color:var(--text-secondary)">
      Total: <strong id="scoreSum" style="color:var(--accent-light)">0</strong> /
      <strong><?= array_sum(array_column($questions,'max_score')) ?></strong> points
    </div>
    <div style="display:flex;gap:10px">
      <a href="interviews.php" class="btn btn-secondary">Cancel</a>
      <button type="submit" class="btn btn-primary btn-lg">
        <i class="fa-solid fa-floppy-disk"></i> Submit Scores & Complete Interview
      </button>
    </div>
  </div>
</form>

<script>
const totalMax = <?= array_sum(array_column($questions,'max_score')) ?>;
function updateBar(input) {
  const id  = input.dataset.id;
  const max = parseInt(input.dataset.max);
  const val = Math.min(Math.max(parseInt(input.value)||0, 0), max);
  const pct = max > 0 ? (val/max*100) : 0;
  const bar = document.getElementById('bar_'+id);
  if (bar) {
    bar.style.width = pct+'%';
    bar.className = 'score-bar-fill ' + (pct>=75?'score-high':pct>=40?'score-medium':'score-low');
  }
  updateTotal();
}
function updateTotal() {
  let sum = 0;
  document.querySelectorAll('.score-input').forEach(inp => { sum += parseInt(inp.value)||0; });
  document.getElementById('scoreSum').textContent = sum;
}
// init bars on load
document.querySelectorAll('.score-input').forEach(inp => updateBar(inp));
</script>
<?php renderFooter(); ?>
