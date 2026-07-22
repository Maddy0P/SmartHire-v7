<?php /* Assessment Center — question bank workspace.
   Data: $D['bank'] (rows,total,usage,pools), $D['filters'], $D['page'], $D['pools'], $D['types'], $D['edit'] */
$F = $D['filters']; $per = 15;
$pages = max(1, (int)ceil($D['bank']['total'] / $per));
$bankUrl = fn(array $over = []) => 'assessment_center.php?' . http_build_query(array_filter(
    array_merge(['view' => 'bank'], array_filter($F, fn($v) => $v !== '' && $v !== 0), ['page' => $D['page'] > 1 ? $D['page'] : ''], $over),
    fn($v) => $v !== '' && $v !== null && $v !== 0));
$editing = $D['edit'] ?: (isset($_GET['new']) ? [] : null);
$statusTone = ['active' => 'success', 'draft' => 'warning', 'archived' => 'neutral'];
?>
<form method="GET" action="assessment_center.php" class="sh-flex sh-items-center sh-gap-3 sh-wrap sh-mb-4" role="search" aria-label="Search the question bank">
  <input type="hidden" name="view" value="bank">
  <div class="sh-topbar-search sh-search-inline">
    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
    <label class="sh-sr-only" for="bankQ">Search questions</label>
    <input type="search" id="bankQ" name="q" value="<?= e($F['q']) ?>" placeholder="Search question, skills, role tag…" autocomplete="off" data-debounce-submit>
  </div>
  <label class="sh-sr-only" for="fType">Type</label>
  <select id="fType" name="type" class="sh-input sh-input-auto">
    <option value="">All types</option>
    <?php foreach ($D['types'] as $code => $t): ?><option value="<?= e($code) ?>" <?= $F['type'] === $code ? 'selected' : '' ?>><?= e($t['label']) ?></option><?php endforeach; ?>
  </select>
  <label class="sh-sr-only" for="fDiff">Difficulty</label>
  <select id="fDiff" name="difficulty" class="sh-input sh-input-auto">
    <option value="">Any difficulty</option>
    <?php foreach (['easy','medium','hard'] as $d): ?><option value="<?= $d ?>" <?= $F['difficulty'] === $d ? 'selected' : '' ?>><?= ucfirst($d) ?></option><?php endforeach; ?>
  </select>
  <label class="sh-sr-only" for="fStatus">Status</label>
  <select id="fStatus" name="status" class="sh-input sh-input-auto">
    <option value="">Any status</option>
    <?php foreach (['active','draft','archived'] as $st): ?><option value="<?= $st ?>" <?= $F['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option><?php endforeach; ?>
  </select>
  <label class="sh-sr-only" for="fPool">Pool</label>
  <select id="fPool" name="pool_id" class="sh-input sh-input-auto">
    <option value="">Any pool</option>
    <?php foreach ($D['pools'] as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $F['pool_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
  </select>
  <label class="sh-sr-only" for="fSort">Sort</label>
  <select id="fSort" name="sort" class="sh-input sh-input-auto">
    <?php foreach (['newest' => 'Newest', 'oldest' => 'Oldest', 'difficulty' => 'Difficulty', 'type' => 'Type'] as $k => $v): ?>
    <option value="<?= $k ?>" <?= $F['sort'] === $k ? 'selected' : '' ?>><?= $v ?></option>
    <?php endforeach; ?>
  </select>
  <button class="sh-btn sh-btn-secondary sh-btn-sm">Apply</button>
  <a class="sh-btn sh-btn-primary sh-btn-sm" href="<?= $bankUrl(['new' => 1, 'page' => '']) ?>"><i class="fa-solid fa-circle-plus" aria-hidden="true"></i> New question</a>
</form>

<!-- Bulk actions: add selection to a pool -->
<form method="POST" id="bulkForm">
  <?= csrf_field() ?>
  <input type="hidden" name="form_action" value="pool_add_questions">
  <input type="hidden" name="back_view" value="bank">
  <input type="hidden" name="back_q" value="<?= e($F['q']) ?>">
  <div class="sh-flex sh-items-center sh-gap-3 sh-wrap sh-mb-3 sh-bulkbar" id="bulkBar" hidden>
    <span class="sh-cell-sub"><strong class="sh-tnum" id="bulkCount">0</strong> selected</span>
    <label class="sh-sr-only" for="bulkPool">Target pool</label>
    <select id="bulkPool" name="pool_id" class="sh-input sh-input-auto" required>
      <option value="">Add to pool…</option>
      <?php foreach ($D['pools'] as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
    </select>
    <button class="sh-btn sh-btn-secondary sh-btn-sm">Add to pool</button>
  </div>

<section class="sh-card sh-card-flush" aria-label="Question bank">
  <?php if (!$D['bank']['rows']): ?>
  <div class="sh-empty">
    <div class="sh-empty-icon"><i class="fa-solid fa-circle-question" aria-hidden="true"></i></div>
    <h2>No questions match</h2>
    <p><?= array_filter($F) ? 'Try clearing filters.' : 'Create your first bank question to get started.' ?></p>
    <a class="sh-btn sh-btn-primary sh-mt-2" href="<?= $bankUrl(['new' => 1]) ?>">Create a question</a>
  </div>
  <?php else: ?>
  <div class="sh-table-wrap">
    <table class="sh-table">
      <thead><tr>
        <th scope="col"><span class="sh-sr-only">Select</span></th>
        <th scope="col">Question</th><th scope="col">Type</th><th scope="col">Difficulty</th>
        <th scope="col">Skills / pools</th><th scope="col">Usage</th><th scope="col">Status</th>
        <th scope="col"><span class="sh-sr-only">Actions</span></th>
      </tr></thead>
      <tbody>
        <?php foreach ($D['bank']['rows'] as $q): $qid = (int)$q['id'];
              $use = $D['bank']['usage'][$qid] ?? ['tests' => 0, 'interviews' => 0];
              $inPools = $D['bank']['pools'][$qid] ?? []; ?>
        <tr>
          <td><input type="checkbox" name="question_ids[]" value="<?= $qid ?>" class="bulk-check" aria-label="Select question #<?= $qid ?>"></td>
          <td data-th="Question">
            <a class="sh-cellbtn" href="<?= $bankUrl(['edit' => $qid, 'new' => '']) ?>">
              <span><span class="sh-block"><?= e(mb_strimwidth($q['question'], 0, 90, '…')) ?></span>
              <span class="sh-cell-sub">#<?= $qid ?> · <?= e($q['position_tag'] ?? 'General') ?> · <?= (int)$q['max_score'] ?> pts<?= !empty(\SmartHire\Assessment\Domain\Question::jsonb($q['metadata'] ?? null)['bonus']) ? ' · bonus' : '' ?></span></span>
            </a>
          </td>
          <td data-th="Type"><?= e($D['types'][$q['question_type']]['label'] ?? $q['question_type']) ?></td>
          <td data-th="Difficulty"><span class="sh-badge sh-badge-<?= sh_center_diff_tone($q['difficulty']) ?>"><?= e($q['difficulty']) ?></span></td>
          <td data-th="Skills / pools">
            <span class="sh-cell-sub"><?= e($q['skills'] ?: '—') ?></span>
            <?php if ($inPools): ?><span class="sh-cell-sub sh-block"><i class="fa-solid fa-layer-group" aria-hidden="true"></i> <?= e(implode(', ', array_slice($inPools, 0, 2))) ?><?= count($inPools) > 2 ? ' +' . (count($inPools) - 2) : '' ?></span><?php endif; ?>
          </td>
          <td data-th="Usage" class="sh-tnum"><?= $use['tests'] ?> tests<span class="sh-cell-sub sh-block"><?= $use['interviews'] ?> interviews</span></td>
          <td data-th="Status"><span class="sh-badge sh-badge-<?= $statusTone[$q['status'] ?? 'active'] ?? 'neutral' ?>"><?= e($q['status'] ?? 'active') ?></span></td>
          <td>
            <div class="sh-row-actions">
              <a class="sh-iconbtn" href="<?= $bankUrl(['edit' => $qid, 'new' => '']) ?>" aria-label="Edit question #<?= $qid ?>"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i></a>
              <form method="POST" class="sh-inline-form">
                <?= csrf_field() ?><input type="hidden" name="form_action" value="question_duplicate">
                <input type="hidden" name="back_view" value="bank"><input type="hidden" name="question_id" value="<?= $qid ?>">
                <button class="sh-iconbtn" aria-label="Duplicate question #<?= $qid ?>"><i class="fa-solid fa-copy" aria-hidden="true"></i></button>
              </form>
              <form method="POST" class="sh-inline-form">
                <?= csrf_field() ?><input type="hidden" name="form_action" value="question_status">
                <input type="hidden" name="back_view" value="bank"><input type="hidden" name="question_id" value="<?= $qid ?>">
                <input type="hidden" name="status" value="<?= ($q['status'] ?? 'active') === 'archived' ? 'active' : 'archived' ?>">
                <button class="sh-iconbtn <?= ($q['status'] ?? 'active') === 'archived' ? '' : 'sh-danger-text' ?>"
                        aria-label="<?= ($q['status'] ?? 'active') === 'archived' ? 'Restore' : 'Archive' ?> question #<?= $qid ?>"
                        <?= ($q['status'] ?? 'active') !== 'archived' ? 'data-confirm="Archive this question? It stays in past tests but leaves generation pools."' : '' ?>>
                  <i class="fa-solid <?= ($q['status'] ?? 'active') === 'archived' ? 'fa-rotate-left' : 'fa-box-archive' ?>" aria-hidden="true"></i>
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
</form>

<p class="sh-cell-sub sh-mt-2"><span class="sh-tnum"><?= (int)$D['bank']['total'] ?></span> question(s) · page <?= $D['page'] ?> of <?= $pages ?></p>
<?= sh_pagination($D['page'], $pages, fn($p) => $bankUrl(['page' => $p > 1 ? $p : '', 'edit' => '', 'new' => ''])) ?>

<?php if ($editing !== null): $E = $editing; $isNew = !$E;
      $eMeta = \SmartHire\Assessment\Domain\Question::jsonb($E['metadata'] ?? null);
      $eKey  = \SmartHire\Assessment\Domain\Question::jsonb($E['answer_key'] ?? null); ?>
<!-- Slide-over editor (reused v8 pattern; server-rendered open state) -->
<aside class="sh-slideover open" id="qEditor" role="dialog" aria-modal="false" aria-labelledby="qEdTitle">
  <div class="sh-slideover-head">
    <div class="sh-flex-1">
      <h2 class="sh-card-title sh-panel-title" id="qEdTitle"><?= $isNew ? 'New question' : 'Edit question #' . (int)$E['id'] ?></h2>
      <p class="sh-card-sub">Saved through AssessmentService — type rules validated by the registry.</p>
    </div>
    <a class="sh-iconbtn" href="<?= $bankUrl(['edit' => '', 'new' => '']) ?>" aria-label="Close editor"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
  </div>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="question_save">
    <input type="hidden" name="back_view" value="bank">
    <input type="hidden" name="back_q" value="<?= e($F['q']) ?>">
    <?php if (!$isNew): ?><input type="hidden" name="question_id" value="<?= (int)$E['id'] ?>"><?php endif; ?>
    <div class="sh-slideover-body">
      <div class="sh-form-grid">
        <div class="sh-field sh-colspan">
          <label class="sh-label" for="qe_text">Question <span class="req" aria-hidden="true">*</span></label>
          <textarea id="qe_text" name="question" class="sh-input" required rows="3"><?= e($E['question'] ?? '') ?></textarea>
        </div>
        <div class="sh-field">
          <label class="sh-label" for="qe_type">Type <span class="req" aria-hidden="true">*</span></label>
          <select id="qe_type" name="question_type" class="sh-input" data-qtype-switch>
            <?php foreach ($D['types'] as $code => $t): ?>
            <option value="<?= e($code) ?>" data-strategy="<?= e($t['scoring']) ?>" <?= ($E['question_type'] ?? 'mcq') === $code ? 'selected' : '' ?>>
              <?= e($t['label']) ?><?= $t['deliverable'] ? '' : ' (authoring only)' ?>
            </option>
            <?php endforeach; ?>
          </select>
          <p class="sh-help">Auto-scored types need an answer key below; other types go to the manual review lane.</p>
        </div>
        <div class="sh-field">
          <label class="sh-label" for="qe_diff">Difficulty</label>
          <select id="qe_diff" name="difficulty" class="sh-input">
            <?php foreach (['easy','medium','hard'] as $d): ?><option value="<?= $d ?>" <?= ($E['difficulty'] ?? 'medium') === $d ? 'selected' : '' ?>><?= ucfirst($d) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="sh-field">
          <label class="sh-label" for="qe_cat">Category</label>
          <input id="qe_cat" name="category" class="sh-input" value="<?= e($E['category'] ?? 'technical') ?>">
        </div>
        <div class="sh-field">
          <label class="sh-label" for="qe_tag">Role tag</label>
          <input id="qe_tag" name="position_tag" class="sh-input" value="<?= e($E['position_tag'] ?? 'General') ?>">
        </div>
        <div class="sh-field">
          <label class="sh-label" for="qe_max">Max score</label>
          <input id="qe_max" name="max_score" type="number" min="1" class="sh-input" value="<?= (int)($E['max_score'] ?? 10) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-label" for="qe_skills">Skills (comma-separated)</label>
          <input id="qe_skills" name="skills" class="sh-input" value="<?= e($E['skills'] ?? '') ?>" placeholder="SQL, Indexing">
        </div>
        <div class="sh-field">
          <label class="sh-label" for="qe_status">Status</label>
          <select id="qe_status" name="status" class="sh-input">
            <?php foreach (['active','draft','archived'] as $st): ?><option value="<?= $st ?>" <?= ($E['status'] ?? 'active') === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="sh-field">
          <label class="sh-label" for="qe_bonus"><input type="checkbox" id="qe_bonus" name="bonus" value="1" <?= !empty($eMeta['bonus']) ? 'checked' : '' ?>> Bonus question (adds marks, not counted in max)</label>
        </div>

        <!-- MCQ options (also used by multi-select) -->
        <fieldset class="sh-field sh-colspan sh-qtype-block" data-for="mcq multi_select">
          <legend class="sh-label">Options</legend>
          <div class="sh-form-grid">
            <?php foreach (['a','b','c','d'] as $opt): ?>
            <div class="sh-field">
              <label class="sh-label" for="qe_opt_<?= $opt ?>">Option <?= strtoupper($opt) ?></label>
              <input id="qe_opt_<?= $opt ?>" name="option_<?= $opt ?>" class="sh-input" value="<?= e($E['option_' . $opt] ?? '') ?>">
            </div>
            <?php endforeach; ?>
          </div>
        </fieldset>
        <div class="sh-field sh-qtype-block" data-for="mcq">
          <label class="sh-label" for="qe_correct">Correct option (MCQ) <span class="req" aria-hidden="true">*</span></label>
          <select id="qe_correct" name="correct_option" class="sh-input">
            <option value="">—</option>
            <?php foreach (['a','b','c','d'] as $opt): ?><option value="<?= $opt ?>" <?= ($E['correct_option'] ?? '') === $opt ? 'selected' : '' ?>><?= strtoupper($opt) ?></option><?php endforeach; ?>
          </select>
        </div>
        <fieldset class="sh-field sh-qtype-block" data-for="multi_select">
          <legend class="sh-label">Correct options (multi-select) <span class="req" aria-hidden="true">*</span></legend>
          <?php $set = (array)($eKey['correct'] ?? []); foreach (['a','b','c','d'] as $opt): ?>
          <label class="sh-label sh-inline-label"><input type="checkbox" name="correct_set[]" value="<?= $opt ?>" <?= in_array($opt, $set, true) ? 'checked' : '' ?>> <?= strtoupper($opt) ?></label>
          <?php endforeach; ?>
        </fieldset>
        <div class="sh-field sh-qtype-block" data-for="true_false">
          <label class="sh-label" for="qe_bool">Correct value</label>
          <select id="qe_bool" name="bool_value" class="sh-input">
            <option value="true" <?= ($eKey['value'] ?? true) ? 'selected' : '' ?>>True</option>
            <option value="false" <?= isset($eKey['value']) && !$eKey['value'] ? 'selected' : '' ?>>False</option>
          </select>
        </div>
        <div class="sh-field sh-colspan sh-qtype-block" data-for="fill_blank">
          <label class="sh-label" for="qe_accepted">Accepted answers (one per line) <span class="req" aria-hidden="true">*</span></label>
          <textarea id="qe_accepted" name="accepted" class="sh-input" rows="3"><?= e(implode("\n", (array)($eKey['accepted'] ?? []))) ?></textarea>
        </div>
        <div class="sh-field sh-colspan sh-qtype-block" data-for="output_prediction">
          <label class="sh-label" for="qe_output">Expected output <span class="req" aria-hidden="true">*</span></label>
          <textarea id="qe_output" name="expected_output" class="sh-input" rows="2"><?= e($eKey['expected_output'] ?? '') ?></textarea>
        </div>
        <div class="sh-field sh-colspan">
          <label class="sh-label" for="qe_model">Model answer / rubric (shown to reviewers)</label>
          <textarea id="qe_model" name="expected_answer" class="sh-input" rows="3"><?= e($E['expected_answer'] ?? '') ?></textarea>
        </div>
        <?php if ($isNew): ?>
        <div class="sh-field sh-colspan">
          <label class="sh-label" for="qe_pools">Add to pools</label>
          <select id="qe_pools" name="pool_ids[]" class="sh-input" multiple size="3">
            <?php foreach ($D['pools'] as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
          </select>
          <p class="sh-help">Hold Ctrl/Cmd to pick several.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="sh-slideover-foot">
      <button type="submit" class="sh-btn sh-btn-primary"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save question</button>
      <a class="sh-btn sh-btn-secondary" href="<?= $bankUrl(['edit' => '', 'new' => '']) ?>">Cancel</a>
    </div>
  </form>
</aside>
<?php endif; ?>
