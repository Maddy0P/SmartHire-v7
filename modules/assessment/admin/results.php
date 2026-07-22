<?php /* Assessment Center — results workspace. Data: $D['list'], $D['filters'], $D['sid'], $D['result'], $D['detailRow'] */
$R = $D['result']; $row = $D['detailRow'];
$recTone = ['strong_yes' => 'success', 'yes' => 'success', 'maybe' => 'warning', 'no' => 'danger'];
?>
<form method="GET" action="assessment_center.php" class="sh-flex sh-items-center sh-gap-3 sh-wrap sh-mb-4 sh-noprint" role="search" aria-label="Search results">
  <input type="hidden" name="view" value="results">
  <div class="sh-topbar-search sh-search-inline">
    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
    <label class="sh-sr-only" for="resQ">Search results</label>
    <input type="search" id="resQ" name="q" value="<?= e($D['filters']['q']) ?>" placeholder="Search candidate or assessment…" autocomplete="off" data-debounce-submit>
  </div>
  <button class="sh-btn sh-btn-secondary sh-btn-sm">Apply</button>
</form>

<div class="sh-center-cols <?= $R ? 'sh-center-cols-wide' : '' ?>">
  <section class="sh-card sh-card-flush sh-noprint" aria-label="Assessment results">
    <?php if (!$D['list']): ?>
    <div class="sh-empty">
      <div class="sh-empty-icon"><i class="fa-solid fa-chart-column" aria-hidden="true"></i></div>
      <h2>No submitted assessments yet</h2><p>Results appear here as soon as candidates submit.</p>
    </div>
    <?php else: ?>
    <div class="sh-table-wrap">
      <table class="sh-table">
        <thead><tr>
          <th scope="col">Candidate</th><th scope="col">Assessment</th><th scope="col">Submitted</th>
          <th scope="col">Score</th><th scope="col">Outcome</th><th scope="col"><span class="sh-sr-only">Actions</span></th>
        </tr></thead>
        <tbody>
          <?php foreach ($D['list'] as $r): $sid = (int)$r['id']; $passed = (int)$r['total_score'] >= (int)$r['passing_marks']; ?>
          <tr <?= $sid === $D['sid'] ? 'class="sh-row-active"' : '' ?>>
            <td data-th="Candidate">
              <a class="sh-cellbtn" href="assessment_center.php?view=results&sid=<?= $sid ?>">
                <span class="sh-avatar" aria-hidden="true"><?= strtoupper(substr($r['candidate_name'], 0, 1)) ?></span>
                <span><?= e($r['candidate_name']) ?></span>
              </a>
            </td>
            <td data-th="Assessment"><?= e($r['title']) ?><?php if ($r['template_id']): ?><span class="sh-cell-sub sh-block">from template</span><?php endif; ?></td>
            <td data-th="Submitted" class="sh-tnum"><?= $r['submitted_at'] ? date('d M Y', strtotime($r['submitted_at'])) : '—' ?></td>
            <td data-th="Score" class="sh-tnum"><?= (int)$r['total_score'] ?>/<?= (int)$r['test_total'] ?><span class="sh-cell-sub sh-block"><?= e((string)$r['percentage']) ?>%</span></td>
            <td data-th="Outcome"><span class="sh-badge sh-badge-<?= $passed ? 'success' : 'danger' ?>"><?= $passed ? 'Pass' : 'Fail' ?></span></td>
            <td><a class="sh-btn sh-btn-secondary sh-btn-sm" href="assessment_center.php?view=results&sid=<?= $sid ?>">Breakdown</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>

  <?php if ($R && $row): ?>
  <section class="sh-card sh-result-sheet" aria-label="Result breakdown">
    <div class="sh-card-header">
      <div>
        <h2 class="sh-card-title"><?= e($row['candidate_name']) ?> — <?= e($row['title']) ?></h2>
        <p class="sh-card-sub">Submission #<?= (int)$D['sid'] ?> · <?= $row['submitted_at'] ? date('d M Y, g:i A', strtotime($row['submitted_at'])) : '' ?>
          · <?= (int)$row['time_taken_mins'] ?> min · <?= (int)$row['violations'] ?> violation(s)</p>
      </div>
      <div class="sh-flex sh-gap-2 sh-noprint">
        <a class="sh-btn sh-btn-secondary sh-btn-sm" href="assessment_center.php?view=results&sid=<?= (int)$D['sid'] ?>&export=csv"><i class="fa-solid fa-file-csv" aria-hidden="true"></i> CSV / Excel</a>
        <button class="sh-btn sh-btn-secondary sh-btn-sm" onclick="window.print()"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i> PDF</button>
        <a class="sh-iconbtn" href="assessment_center.php?view=results" aria-label="Close breakdown"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
      </div>
    </div>

    <div class="sh-form-grid">
      <div class="sh-field"><p class="sh-cell-sub">Overall</p><p class="sh-kpi-value sh-tnum"><?= e((string)$R->overallPct) ?>%</p>
        <p class="sh-cell-sub sh-tnum"><?= e((string)$R->totalMarks) ?> / <?= e((string)$R->maxMarks) ?> marks</p></div>
      <div class="sh-field"><p class="sh-cell-sub">Outcome</p>
        <p><span class="sh-badge sh-badge-<?= $R->passed ? 'success' : 'danger' ?>"><?= $R->passed ? 'Passed' : 'Not passed' ?></span></p></div>
      <div class="sh-field"><p class="sh-cell-sub">Recommendation</p>
        <p><span class="sh-badge sh-badge-<?= $recTone[$R->recommendation] ?? 'neutral' ?>"><?= e(str_replace('_', ' ', $R->recommendation)) ?></span></p></div>
      <div class="sh-field"><p class="sh-cell-sub">Pending review</p>
        <p class="sh-kpi-value sh-tnum"><?= (int)$R->pendingReview ?></p>
        <?php if ($R->pendingReview > 0): ?><a class="sh-cell-sub sh-noprint" href="assessment_center.php?view=reviews&sid=<?= (int)$D['sid'] ?>">Open review queue →</a><?php endif; ?></div>
    </div>

    <?php if ($R->trend): ?>
    <h3 class="sh-card-title sh-panel-title sh-mt-4">Performance trend (prior attempts)</h3>
    <?php $t = []; foreach ($R->trend as $i => $p) $t['Attempt ' . ($i + 1)] = $p; $t['This attempt'] = $R->overallPct;
          sh_center_bars('', $t, '%'); ?>
    <?php endif; ?>

    <h3 class="sh-card-title sh-panel-title sh-mt-4">Section analysis</h3>
    <?php sh_center_bars('', array_map(fn($v) => $v['pct'], $R->sections), '%'); ?>

    <?php if ($R->skills): ?>
    <h3 class="sh-card-title sh-panel-title sh-mt-4">Skill analysis</h3>
    <?php sh_center_bars('', array_map(fn($v) => $v['pct'], array_slice($R->skills, 0, 8, true)), '%'); ?>
    <?php endif; ?>

    <h3 class="sh-card-title sh-panel-title sh-mt-4">Difficulty analysis</h3>
    <?php sh_center_bars('', array_map(fn($v) => $v['pct'], $R->difficulty), '%'); ?>

    <h3 class="sh-card-title sh-panel-title sh-mt-4">Question analysis</h3>
    <div class="sh-table-wrap">
      <table class="sh-table">
        <thead><tr><th scope="col">#</th><th scope="col">Type</th><th scope="col">Difficulty</th><th scope="col">Marks</th><th scope="col">Time</th><th scope="col">Status</th></tr></thead>
        <tbody>
          <?php foreach ($R->questionAnalysis as $qa): ?>
          <tr>
            <td data-th="#" class="sh-tnum">Q<?= (int)$qa['question_id'] ?><?= $qa['bonus'] ? ' <span class="sh-badge sh-badge-info">bonus</span>' : '' ?></td>
            <td data-th="Type"><?= e($qa['type']) ?></td>
            <td data-th="Difficulty"><span class="sh-badge sh-badge-<?= sh_center_diff_tone($qa['difficulty']) ?>"><?= e($qa['difficulty']) ?></span></td>
            <td data-th="Marks" class="sh-tnum"><?= e((string)$qa['earned']) ?><?= $qa['max'] > 0 ? '/' . $qa['max'] : '' ?></td>
            <td data-th="Time" class="sh-tnum"><?= (int)$qa['time_secs'] ?>s</td>
            <td data-th="Status">
              <?php if ($qa['pending']): ?><span class="sh-badge sh-badge-warning">pending review</span>
              <?php elseif ($qa['correct']): ?><span class="sh-badge sh-badge-success">correct</span>
              <?php elseif ($qa['reviewed']): ?><span class="sh-badge sh-badge-info">reviewed</span>
              <?php else: ?><span class="sh-badge sh-badge-danger">incorrect</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <h3 class="sh-card-title sh-panel-title sh-mt-4">Timeline</h3>
    <ul class="sh-timeline">
      <li><strong><?= (int)$R->timeAnalysis['total_secs'] ?>s</strong> total answering time · avg <?= (int)$R->timeAnalysis['avg_secs'] ?>s/question</li>
      <?php if ($R->timeAnalysis['slowest']): ?><li>Slowest: Q<?= (int)$R->timeAnalysis['slowest']['question_id'] ?> (<?= (int)$R->timeAnalysis['slowest']['secs'] ?>s) · fastest: Q<?= (int)$R->timeAnalysis['fastest']['question_id'] ?> (<?= (int)$R->timeAnalysis['fastest']['secs'] ?>s)</li><?php endif; ?>
      <li>Submitted <?= $row['submitted_at'] ? date('d M Y, g:i A', strtotime($row['submitted_at'])) : '—' ?> (<?= e($row['status']) ?>)</li>
    </ul>

    <?php if ($R->strengths || $R->weaknesses || $R->suggestions): ?>
    <h3 class="sh-card-title sh-panel-title sh-mt-4">Insights</h3>
    <?php if ($R->strengths): ?><p><strong>Strengths:</strong> <?= e(implode(' · ', $R->strengths)) ?></p><?php endif; ?>
    <?php if ($R->weaknesses): ?><p><strong>Weaknesses:</strong> <?= e(implode(' · ', $R->weaknesses)) ?></p><?php endif; ?>
    <?php foreach ($R->suggestions as $sg): ?><p class="sh-cell-sub">→ <?= e($sg) ?></p><?php endforeach; ?>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>
