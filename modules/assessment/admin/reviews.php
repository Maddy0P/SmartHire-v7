<?php /* Assessment Center — review queue. Data: $D['queue'], $D['sid'], $D['workspace'], $D['submission'], $D['ai'] */ ?>
<div class="sh-center-cols <?= $D['workspace'] ? 'sh-center-cols-wide' : '' ?>">
  <section class="sh-card sh-card-flush" aria-label="Pending manual reviews">
    <div class="sh-card-header">
      <div><h2 class="sh-card-title">Pending manual reviews</h2>
      <p class="sh-card-sub">Submissions with unscored essay / code / scenario answers. Oldest first.</p></div>
    </div>
    <?php if (!$D['queue']): ?>
    <div class="sh-empty">
      <div class="sh-empty-icon"><i class="fa-solid fa-user-check" aria-hidden="true"></i></div>
      <h2>Review queue is clear</h2><p>Every submitted answer has been scored. New manual-lane answers appear here automatically.</p>
    </div>
    <?php else: ?>
    <div class="sh-table-wrap">
      <table class="sh-table">
        <thead><tr>
          <th scope="col">Candidate</th><th scope="col">Assessment</th><th scope="col">Submitted</th>
          <th scope="col">Pending</th><th scope="col">Current %</th><th scope="col"><span class="sh-sr-only">Actions</span></th>
        </tr></thead>
        <tbody>
          <?php foreach ($D['queue'] as $qrow): $sid = (int)$qrow['id']; ?>
          <tr <?= $sid === $D['sid'] ? 'class="sh-row-active"' : '' ?>>
            <td data-th="Candidate">
              <a class="sh-cellbtn" href="assessment_center.php?view=reviews&sid=<?= $sid ?>">
                <span class="sh-avatar" aria-hidden="true"><?= strtoupper(substr($qrow['candidate_name'], 0, 1)) ?></span>
                <span><?= e($qrow['candidate_name']) ?></span>
              </a>
            </td>
            <td data-th="Assessment"><?= e($qrow['title']) ?></td>
            <td data-th="Submitted" class="sh-tnum"><?= $qrow['submitted_at'] ? date('d M Y', strtotime($qrow['submitted_at'])) : '—' ?></td>
            <td data-th="Pending"><span class="sh-badge sh-badge-warning sh-tnum"><?= (int)$qrow['pending_count'] ?> answer(s)</span></td>
            <td data-th="Current %" class="sh-tnum"><?= e((string)$qrow['percentage']) ?>%</td>
            <td><a class="sh-btn sh-btn-secondary sh-btn-sm" href="assessment_center.php?view=reviews&sid=<?= $sid ?>">Review</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>

  <?php if ($D['workspace'] !== null): $sub = $D['submission']; ?>
  <section class="sh-card" aria-label="Review workspace">
    <div class="sh-card-header">
      <div><h2 class="sh-card-title">Review workspace — submission #<?= (int)$D['sid'] ?></h2>
      <p class="sh-card-sub"><?= $sub ? 'Current total ' . $sub->totalScore . '/' . $sub->maxScore . ' (' . $sub->percentage . '%) · saving a score recomputes the totals instantly.' : '' ?></p></div>
      <a class="sh-iconbtn" href="assessment_center.php?view=reviews" aria-label="Close workspace"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
    </div>
    <?php if (!$D['workspace']): ?>
    <p class="sh-cell-sub">No manual-lane answers on this submission.</p>
    <?php endif; ?>
    <?php foreach ($D['workspace'] as $i => $a):
      $reviewed = ($a['hr_marks'] ?? null) !== null;
      $max = (int)($a['weight'] ?? $a['max_score'] ?? 10);
      $ai = $D['ai'][(int)$a['id']] ?? null; ?>
    <div class="sh-qitem">
      <div class="sh-flex sh-items-center sh-gap-2 sh-wrap sh-mb-2">
        <span class="sh-badge sh-badge-<?= sh_center_diff_tone((string)($a['difficulty'] ?? 'medium')) ?>"><?= e($a['difficulty'] ?? 'medium') ?></span>
        <span class="sh-cell-sub"><?= e($a['question_type']) ?> · <?= $max ?> pts</span>
        <?php if ($reviewed): ?><span class="sh-badge sh-badge-success">reviewed — <?= (int)$a['hr_marks'] ?>/<?= $max ?></span>
        <?php else: ?><span class="sh-badge sh-badge-warning">awaiting review</span><?php endif; ?>
      </div>
      <p class="sh-qtext">Q<?= $i + 1 ?>. Candidate answer:</p>
      <blockquote class="sh-review-answer"><?= nl2br(e($a['answer_text'] ?: '— no answer given —')) ?></blockquote>
      <?php if ($ai !== null): ?>
      <p class="sh-cell-sub"><i class="fa-solid fa-robot" aria-hidden="true"></i> AI suggestion: <strong class="sh-tnum"><?= e((string)$ai->marks) ?>/<?= $max ?></strong> — advisory only, review lane stays human.</p>
      <?php else: ?>
      <p class="sh-cell-sub"><i class="fa-solid fa-robot" aria-hidden="true"></i> AI suggestion: no AI evaluator plugin connected.</p>
      <?php endif; ?>
      <form method="POST" class="sh-flex sh-items-end sh-gap-3 sh-wrap sh-mt-2">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="review_save">
        <input type="hidden" name="back_view" value="reviews">
        <input type="hidden" name="back_sid" value="<?= (int)$D['sid'] ?>">
        <input type="hidden" name="submission_id" value="<?= (int)$D['sid'] ?>">
        <input type="hidden" name="answer_id" value="<?= (int)$a['id'] ?>">
        <div class="sh-field">
          <label class="sh-label" for="rv_marks_<?= (int)$a['id'] ?>">Score (0–<?= $max ?>)</label>
          <input id="rv_marks_<?= (int)$a['id'] ?>" name="marks" type="number" min="0" max="<?= $max ?>" class="sh-input" required
                 value="<?= $reviewed ? (int)$a['hr_marks'] : '' ?>" inputmode="numeric">
        </div>
        <div class="sh-field sh-flex-1">
          <label class="sh-label" for="rv_fb_<?= (int)$a['id'] ?>">Reviewer note</label>
          <input id="rv_fb_<?= (int)$a['id'] ?>" name="feedback" class="sh-input" value="<?= e($a['hr_feedback'] ?? '') ?>" placeholder="Short justification…">
        </div>
        <button class="sh-btn sh-btn-primary sh-btn-sm"><i class="fa-solid fa-check" aria-hidden="true"></i> <?= $reviewed ? 'Update' : 'Approve score' ?></button>
      </form>
    </div>
    <?php endforeach; ?>
    <?php if ($D['workspace']): ?>
    <div class="sh-mt-4 sh-flex sh-gap-3">
      <a class="sh-btn sh-btn-secondary sh-btn-sm" href="assessment_center.php?view=results&sid=<?= (int)$D['sid'] ?>"><i class="fa-solid fa-chart-column" aria-hidden="true"></i> Open full result</a>
      <a class="sh-btn sh-btn-ghost sh-btn-sm" href="view_test_result.php?submission_id=<?= (int)$D['sid'] ?>">Legacy result page</a>
    </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>
