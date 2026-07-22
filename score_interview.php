<?php
require_once 'includes/layout.php';
require_once 'includes/recruitment.php';
require_once 'modules/interview/bootstrap.php';   // Module 9 — Interview Management
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

// ── Save scores / scorecard / decision / feedback (Module 9 Phase 4) ─────────
// The service owns every write path; this controller only maps the result to a
// flash message + redirect (handbook Ch6/Ch12: no business logic in pages).
// $fa distinguishes the Phase-4 UI panels from the legacy per-question form,
// which posts no form_action at all — so that path is untouched and stays
// byte-identical to the pre-Phase-4 behavior.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fa     = $_POST['form_action'] ?? '';
    $ivSvc  = \SmartHire\Interview\InterviewService::production();
    $actor  = currentUser()['name'] ?? null;
    $back   = "score_interview.php?interview_id=$interview_id";

    if ($fa === 'scorecard') {
        $r = $ivSvc->saveScorecard($interview_id, $_POST, $actor);
        if ($r['ok'])                 setFlash('success', 'Scorecard saved.');
        elseif ($r['error'] === 'finalized') setFlash('error', 'This interview\'s decision is finalized — the scorecard is locked.');
        elseif (!empty($r['errors'])) setFlash('error', reset($r['errors']));
        else                          setFlash('error', 'Could not save the scorecard.');
        header("Location: $back#scorecard"); exit;

    } elseif ($fa === 'decision') {
        $r = $ivSvc->recordDecision($interview_id, (string)($_POST['decision'] ?? ''), !empty($_POST['finalize']), $actor);
        if ($r['ok'])                          setFlash('success', $r['finalized'] ? 'Decision recorded and finalized.' : 'Decision recorded.');
        elseif ($r['error'] === 'finalized')   setFlash('error', 'This decision is already finalized and cannot be changed.');
        elseif ($r['error'] === 'bad_decision') setFlash('error', 'Select a valid decision.');
        else                                     setFlash('error', 'Could not record the decision.');
        header("Location: $back#decision"); exit;

    } elseif ($fa === 'feedback') {
        $r = $ivSvc->submitFeedback($interview_id, $_POST, $actor);
        if ($r['ok'])                 setFlash('success', 'Feedback submitted.');
        elseif (!empty($r['errors'])) setFlash('error', reset($r['errors']));
        else                          setFlash('error', 'Could not submit feedback.');
        header("Location: $back#feedback"); exit;

    } else {
        // Legacy per-question scoring — unchanged, plus the finalization lock
        // (spec Part 5): once a decision is finalized, scores are frozen.
        $sc = $ivSvc->scorecardFor($interview_id);
        if ($sc && !empty($sc['decision_finalized'])) {
            setFlash('error', 'This interview\'s decision is finalized — scores are locked.');
            header("Location: $back"); exit;
        }
        $ivSvc->saveQuestionScores(
            $interview_id,
            (int)$interview['cid'],
            $_POST['scores'] ?? [],
            $_POST['notes']  ?? [],
            $actor
        );
        setFlash('success', 'Interview scored successfully! Candidate moved to "completed".');
        header("Location: candidate_detail.php?candidate_id={$interview['cid']}&interview_id=$interview_id");
        exit;
    }
}

// ── Phase 4: load scorecard / decision / feedback / timeline for display ─────
$ivSvc     = \SmartHire\Interview\InterviewService::production();
$scorecard = $ivSvc->scorecardFor($interview_id);
$feedback  = $ivSvc->feedbackFor($interview_id);
$ivTimeline = $ivSvc->timelineFor($interview_id);
$finalized = (bool)($scorecard['decision_finalized'] ?? false);

$catLabels = [
    'technical_knowledge' => 'Technical knowledge', 'communication' => 'Communication',
    'problem_solving' => 'Problem solving', 'behaviour' => 'Behaviour',
    'cultural_fit' => 'Cultural fit', 'confidence' => 'Confidence',
    'experience_relevance' => 'Experience relevance',
];
$recLabels = ['strong_hire' => 'Strong hire', 'hire' => 'Hire', 'hold' => 'Hold', 'reject' => 'Reject', 'second_round' => 'Second round'];
$decLabels = ['pending' => 'Pending', 'passed' => 'Passed', 'rejected' => 'Rejected', 'hold' => 'Hold',
              'second_round' => 'Second round', 'final_round' => 'Final round', 'recommended_for_offer' => 'Recommended for offer'];
$tlLabels  = ['scheduled' => 'Scheduled', 'candidate_confirmed' => 'Candidate confirmed', 'reminder_sent' => 'Reminder sent',
              'started' => 'Interview started', 'completed' => 'Interview completed', 'score_updated' => 'Scorecard updated',
              'feedback_submitted' => 'Feedback submitted', 'decision_recorded' => 'Decision recorded',
              'decision_changed' => 'Decision changed', 'moved_to_offer' => 'Moved to offer'];
$decTone   = ['recommended_for_offer' => 'success', 'passed' => 'success', 'rejected' => 'danger',
              'hold' => 'warning', 'second_round' => 'info', 'final_round' => 'info', 'pending' => 'info'];

// ── Load questions & existing responses ──────────────────
$questions = dbFetchAll("SELECT * FROM interview_questions ORDER BY category, difficulty");
$existing  = dbFetchAll("SELECT * FROM candidate_responses WHERE interview_id=?", 'i', $interview_id);
$existMap  = array_column($existing, null, 'question_id');

// ── v8 presentation (read-only; Module 7) ────────────────────────────────────
$totalMax = array_sum(array_column($questions, 'max_score'));
$catIcons = ['technical' => 'fa-code', 'hr' => 'fa-comments', 'behavioral' => 'fa-brain',
             'system_design' => 'fa-sitemap', 'coding' => 'fa-terminal', 'mcq' => 'fa-list-check'];
$diffTone = ['easy' => 'success', 'medium' => 'warning', 'hard' => 'danger'];
$byCat = [];
foreach ($questions as $q) $byCat[$q['category']][] = $q;

renderHead('Score Interview', true);
renderSidebar('interviews');
?>

<div class="sh-page-header">
  <div>
    <h1 class="sh-page-title">Score interview</h1>
    <p class="sh-page-sub">Rate each question for <?= e($interview['cname']) ?> — saving marks the interview completed.</p>
  </div>
  <a href="interviews.php" class="sh-btn sh-btn-secondary"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to interviews</a>
</div>

<!-- Candidate summary — the page's one hero card -->
<section class="sh-card sh-card-hero sh-mb-6" aria-label="Candidate summary">
  <div class="sh-flex sh-items-center sh-gap-4 sh-wrap">
    <span class="sh-avatar sh-avatar-lg" aria-hidden="true"><?= strtoupper(substr($interview['cname'], 0, 1)) ?></span>
    <div class="sh-flex-1">
      <h2 class="sh-card-title"><?= e($interview['cname']) ?></h2>
      <p class="sh-card-sub"><?= e($interview['position']) ?> · <?= e($interview['cemail']) ?></p>
    </div>
    <div class="sh-text-right">
      <span class="sh-badge sh-badge-info"><?= e(ucfirst($interview['type'])) ?> round</span>
      <p class="sh-cell-sub sh-mt-2 sh-tnum"><?= date('d M Y', strtotime($interview['scheduled_date'])) ?> · <?= date('g:i A', strtotime($interview['scheduled_time'])) ?> · <?= $interview['mode'] === 'online' ? 'Online' : 'In-person' ?></p>
      <p class="sh-cell-sub">Interviewer: <?= e($interview['interviewer'] ?: '—') ?></p>
    </div>
  </div>
</section>

<!-- ── Recommendation & decision status (Module 9 Phase 4) ── -->
<section class="sh-card sh-mb-4" aria-label="Recommendation and decision">
  <div class="sh-flex sh-items-center sh-gap-3 sh-wrap">
    <div class="sh-flex-1">
      <h2 class="sh-card-title">Recommendation &amp; decision</h2>
      <p class="sh-card-sub">
        Interviewer recommendation:
        <strong><?= $scorecard && $scorecard['recommendation'] ? e($recLabels[$scorecard['recommendation']] ?? $scorecard['recommendation']) : 'Not yet scored' ?></strong>
        <?php if ($scorecard && $scorecard['overall_score'] !== null): ?> · Overall <span class="sh-tnum"><?= e((string)$scorecard['overall_score']) ?></span>/10<?php endif; ?>
      </p>
    </div>
    <span class="sh-badge sh-badge-<?= $decTone[$scorecard['decision'] ?? 'pending'] ?? 'info' ?>">
      <?= e($decLabels[$scorecard['decision'] ?? 'pending'] ?? 'Pending') ?>
    </span>
    <?php if ($finalized): ?><span class="sh-badge sh-badge-danger"><i class="fa-solid fa-lock" aria-hidden="true"></i> Finalized</span><?php endif; ?>
  </div>
</section>

<!-- ── Interview scorecard (Module 9 Phase 4) ── -->
<section class="sh-card sh-mb-4" id="scorecard" aria-label="Interview scorecard">
  <div class="sh-card-header">
    <div><h2 class="sh-card-title">Interview scorecard</h2><p class="sh-card-sub">Category scores (0–10), overall recommendation, and summary notes.</p></div>
  </div>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="scorecard">
    <fieldset <?= $finalized ? 'disabled' : '' ?> style="border:0;padding:0;margin:0;">
    <div class="sh-form-grid">
      <?php foreach ($catLabels as $cat => $label): ?>
      <div class="sh-field">
        <label class="sh-label" for="sc_<?= $cat ?>"><?= e($label) ?> (0–10)</label>
        <input type="number" id="sc_<?= $cat ?>" name="<?= $cat ?>" class="sh-input" min="0" max="10" step="1"
               value="<?= e((string)($scorecard[$cat] ?? '')) ?>" placeholder="0–10">
      </div>
      <?php endforeach; ?>
      <div class="sh-field">
        <label class="sh-label" for="sc_overall">Overall score (optional override)</label>
        <input type="number" id="sc_overall" name="overall_score" class="sh-input" min="0" max="10" step="0.1"
               value="<?= e((string)($scorecard['overall_score'] ?? '')) ?>" placeholder="Auto-averaged if left blank">
      </div>
      <div class="sh-field">
        <label class="sh-label" for="sc_rec">Recommendation <span class="req" aria-hidden="true">*</span></label>
        <select id="sc_rec" name="recommendation" class="sh-input">
          <option value="">— Select —</option>
          <?php foreach ($recLabels as $k => $lbl): ?>
          <option value="<?= $k ?>" <?= ($scorecard['recommendation'] ?? '') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="sh-field sh-colspan">
        <label class="sh-label" for="sc_summary">Summary</label>
        <textarea id="sc_summary" name="summary" class="sh-input"><?= e($scorecard['summary'] ?? '') ?></textarea>
      </div>
      <div class="sh-field sh-colspan">
        <label class="sh-label" for="sc_comments">Comments</label>
        <textarea id="sc_comments" name="comments" class="sh-input"><?= e($scorecard['comments'] ?? '') ?></textarea>
      </div>
    </div>
    <div class="sh-mt-4">
      <button type="submit" class="sh-btn sh-btn-primary"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save scorecard</button>
      <?php if ($finalized): ?><span class="sh-help">Locked — the decision on this interview has been finalized.</span><?php endif; ?>
    </div>
    </fieldset>
  </form>
</section>

<!-- ── Decision controls (Module 9 Phase 4) ── -->
<section class="sh-card sh-mb-4" id="decision" aria-label="Decision controls">
  <div class="sh-card-header">
    <div><h2 class="sh-card-title">Decision</h2><p class="sh-card-sub">Record the hiring decision. Finalizing locks the scorecard and decision.</p></div>
  </div>
  <form method="POST" class="sh-flex sh-items-center sh-gap-3 sh-wrap">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="decision">
    <div class="sh-field">
      <label class="sh-label" for="dec_decision">Decision</label>
      <select id="dec_decision" name="decision" class="sh-input" <?= $finalized ? 'disabled' : '' ?>>
        <?php foreach ($decLabels as $k => $lbl): ?>
        <option value="<?= $k ?>" <?= ($scorecard['decision'] ?? 'pending') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <label class="sh-flex sh-items-center sh-gap-2">
      <input type="checkbox" name="finalize" value="1" <?= $finalized ? 'disabled checked' : '' ?>> Finalize (locks scoring)
    </label>
    <button type="submit" class="sh-btn sh-btn-secondary" <?= $finalized ? 'disabled' : '' ?>><i class="fa-solid fa-gavel" aria-hidden="true"></i> Record decision</button>
  </form>
</section>

<!-- ── Feedback panel (Module 9 Phase 4) ── -->
<section class="sh-card sh-mb-4" id="feedback" aria-label="Structured feedback">
  <div class="sh-card-header">
    <div><h2 class="sh-card-title">Feedback</h2><p class="sh-card-sub">Independent from scoring — strengths, gaps, and notes for the record.</p></div>
  </div>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="feedback">
    <div class="sh-form-grid">
      <div class="sh-field sh-colspan">
        <label class="sh-label" for="fb_summary">Summary <span class="req" aria-hidden="true">*</span></label>
        <textarea id="fb_summary" name="summary" class="sh-input" required><?= e($feedback['summary'] ?? '') ?></textarea>
      </div>
      <div class="sh-field">
        <label class="sh-label" for="fb_strengths">Strengths</label>
        <textarea id="fb_strengths" name="strengths" class="sh-input"><?= e($feedback['strengths'] ?? '') ?></textarea>
      </div>
      <div class="sh-field">
        <label class="sh-label" for="fb_weaknesses">Weaknesses</label>
        <textarea id="fb_weaknesses" name="weaknesses" class="sh-input"><?= e($feedback['weaknesses'] ?? '') ?></textarea>
      </div>
      <div class="sh-field">
        <label class="sh-label" for="fb_improvement">Improvement areas</label>
        <textarea id="fb_improvement" name="improvement_areas" class="sh-input"><?= e($feedback['improvement_areas'] ?? '') ?></textarea>
      </div>
      <div class="sh-field">
        <label class="sh-label" for="fb_tech">Technical notes</label>
        <textarea id="fb_tech" name="technical_notes" class="sh-input"><?= e($feedback['technical_notes'] ?? '') ?></textarea>
      </div>
      <div class="sh-field">
        <label class="sh-label" for="fb_behaviour">Behaviour notes</label>
        <textarea id="fb_behaviour" name="behaviour_notes" class="sh-input"><?= e($feedback['behaviour_notes'] ?? '') ?></textarea>
      </div>
      <div class="sh-field">
        <label class="sh-label" for="fb_rec">Final recommendation</label>
        <select id="fb_rec" name="final_recommendation" class="sh-input">
          <option value="">— None —</option>
          <?php foreach ($recLabels as $k => $lbl): ?>
          <option value="<?= $k ?>" <?= ($feedback['final_recommendation'] ?? '') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="sh-mt-4">
      <button type="submit" class="sh-btn sh-btn-primary"><i class="fa-solid fa-comment-dots" aria-hidden="true"></i> Submit feedback</button>
    </div>
  </form>
</section>

<!-- ── Timeline history viewer (Module 9 Phase 4) ── -->
<section class="sh-card sh-mb-4" aria-label="Timeline history">
  <div class="sh-card-header"><div><h2 class="sh-card-title">Timeline</h2><p class="sh-card-sub">Immutable, append-only history for this interview.</p></div></div>
  <?php if (!$ivTimeline): ?>
  <p class="sh-cell-sub">No timeline events recorded yet.</p>
  <?php else: ?>
  <ul class="sh-timeline">
    <?php foreach ($ivTimeline as $ev): ?>
    <li>
      <strong><?= e(date('d M Y, g:i A', strtotime($ev['created_at']))) ?> — </strong>
      <?= e($tlLabels[$ev['action']] ?? ucfirst(str_replace('_', ' ', $ev['action']))) ?>
      <?php if ($ev['actor']): ?><span class="sh-cell-sub"> · <?= e($ev['actor']) ?></span><?php endif; ?>
      <?php if ($ev['notes']): ?><span class="sh-cell-sub sh-block"><?= e($ev['notes']) ?></span><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<?php if (!$questions): ?>
<div class="sh-card"><div class="sh-empty">
  <div class="sh-empty-icon"><i class="fa-solid fa-circle-question" aria-hidden="true"></i></div>
  <h2>No questions in the bank</h2>
  <p>Add interview questions first, then return here to score this session.</p>
  <a class="sh-btn sh-btn-primary sh-mt-2" href="questions.php">Open question bank</a>
</div></div>
<?php else: ?>

<form method="POST" id="scoreForm">
  <?= csrf_field() ?>

  <?php foreach ($byCat as $cat => $qs): ?>
  <section class="sh-card sh-card-flush sh-mb-4" aria-labelledby="cat-<?= e($cat) ?>">
    <div class="sh-card-header">
      <div class="sh-flex sh-items-center sh-gap-3">
        <span class="sh-cat-icon" aria-hidden="true"><i class="fa-solid <?= $catIcons[$cat] ?? 'fa-circle-question' ?>"></i></span>
        <div>
          <h2 class="sh-card-title" id="cat-<?= e($cat) ?>"><?= e(ucwords(str_replace('_', ' ', $cat))) ?> questions</h2>
          <p class="sh-card-sub"><?= count($qs) ?> question<?= count($qs) > 1 ? 's' : '' ?> · <?= array_sum(array_column($qs, 'max_score')) ?> points</p>
        </div>
      </div>
    </div>

    <?php foreach ($qs as $q):
      $qid   = (int)$q['id'];
      $exSc  = $existMap[$qid]['score_given'] ?? '';
      $exNo  = $existMap[$qid]['interviewer_note'] ?? '';
      $maxSc = (int)$q['max_score'];
      $pct   = $exSc !== '' && $maxSc > 0 ? (int)floor($exSc / $maxSc * 100) : 0;
    ?>
    <div class="sh-qitem">
      <div class="sh-flex sh-items-center sh-gap-2 sh-wrap sh-mb-2">
        <span class="sh-badge sh-badge-<?= $diffTone[$q['difficulty']] ?? 'warning' ?>"><?= e($q['difficulty']) ?></span>
        <span class="sh-cell-sub"><?= e($q['position_tag']) ?></span>
        <span class="sh-cell-sub sh-tnum">Max <?= $maxSc ?> pts</span>
      </div>
      <p class="sh-qtext"><?= e($q['question']) ?></p>

      <?php if ($q['expected_answer']): ?>
      <details class="sh-qanswer">
        <summary><i class="fa-solid fa-key" aria-hidden="true"></i> Show model answer</summary>
        <div class="sh-qanswer-body"><?= e($q['expected_answer']) ?></div>
      </details>
      <?php endif; ?>

      <div class="sh-qgrid">
        <div class="sh-field">
          <label class="sh-label" for="sq_<?= $qid ?>">Score (0–<?= $maxSc ?>)</label>
          <input type="number" id="sq_<?= $qid ?>" name="scores[<?= $qid ?>]" min="0" max="<?= $maxSc ?>"
                 class="sh-input score-input" value="<?= e((string)$exSc) ?>"
                 data-max="<?= $maxSc ?>" data-id="<?= $qid ?>" inputmode="numeric"
                 placeholder="0–<?= $maxSc ?>" oninput="updateBar(this)">
          <div class="sh-score sh-mt-2">
            <div class="sh-score-track"><div class="sh-score-fill <?= $pct >= 75 ? 'hi' : ($pct >= 40 ? 'mid' : 'lo') ?>" id="bar_<?= $qid ?>" style="width:<?= $pct ?>%"></div></div>
          </div>
        </div>
        <div class="sh-field">
          <label class="sh-label" for="sn_<?= $qid ?>">Interviewer note</label>
          <input type="text" id="sn_<?= $qid ?>" name="notes[<?= $qid ?>]" class="sh-input"
                 placeholder="Brief observation about the answer…" value="<?= e($exNo) ?>">
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </section>
  <?php endforeach; ?>

  <div class="sh-card sh-scorefoot" role="group" aria-label="Submit scores">
    <p class="sh-text-2" aria-live="polite">Total: <strong class="sh-tnum" id="scoreSum">0</strong> / <strong class="sh-tnum"><?= $totalMax ?></strong> points</p>
    <div class="sh-flex sh-gap-3">
      <a href="interviews.php" class="sh-btn sh-btn-secondary">Cancel</a>
      <button type="submit" class="sh-btn sh-btn-primary">
        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Submit scores &amp; complete interview
      </button>
    </div>
  </div>
</form>

<script>
function updateBar(input) {
  var id  = input.dataset.id;
  var max = parseInt(input.dataset.max, 10);
  var val = Math.min(Math.max(parseInt(input.value, 10) || 0, 0), max);
  var pct = max > 0 ? (val / max * 100) : 0;
  var bar = document.getElementById('bar_' + id);
  if (bar) {
    bar.style.width = pct + '%';
    bar.className = 'sh-score-fill ' + (pct >= 75 ? 'hi' : pct >= 40 ? 'mid' : 'lo');
  }
  updateTotal();
}
function updateTotal() {
  var sum = 0;
  document.querySelectorAll('.score-input').forEach(function (inp) { sum += parseInt(inp.value, 10) || 0; });
  document.getElementById('scoreSum').textContent = sum;
}
document.querySelectorAll('.score-input').forEach(function (inp) { updateBar(inp); });
</script>
<?php endif; ?>

<?php renderFooter(); ?>
