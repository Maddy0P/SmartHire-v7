<?php /* Assessment Center — generator. Data: $D['templates'], $D['candidates'], $D['template_id'], $D['preview'] */
$P = $D['preview']; $tid = $D['template_id'];
$selTpl = null; foreach ($D['templates'] as $t) if ((int)$t['id'] === $tid) { $selTpl = $t; break; }
?>
<div class="sh-center-cols">
  <section class="sh-card sh-card-hero" aria-label="Assessment generator">
    <div class="sh-card-header">
      <div><h2 class="sh-card-title">Generate an assessment</h2>
      <p class="sh-card-sub">Pick a published template, preview the selection the engine would make, then issue it to a candidate. Everything runs through AssessmentService.</p></div>
    </div>

    <h3 class="sh-card-title sh-panel-title sh-wstep">1 · Template (role, department, rules)</h3>
    <form method="GET" action="assessment_center.php" class="sh-form-grid">
      <input type="hidden" name="view" value="generator">
      <div class="sh-field sh-colspan">
        <label class="sh-label" for="gn_tpl">Assessment template <span class="req" aria-hidden="true">*</span></label>
        <select id="gn_tpl" name="template_id" class="sh-input" required>
          <option value="">— pick a published template —</option>
          <?php foreach ($D['templates'] as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= $tid === (int)$t['id'] ? 'selected' : '' ?>>
            <?= e($t['name']) ?> — <?= e($t['role'] ?: 'any role') ?> · <?= (int)$t['question_count'] ?> Qs · <?= (int)$t['duration_minutes'] ?> min
          </option>
          <?php endforeach; ?>
        </select>
        <?php if (!$D['templates']): ?><p class="sh-help">No published templates yet — publish one in the Templates tab first.</p><?php endif; ?>
      </div>
      <div class="sh-field">
        <label class="sh-label" for="gn_seed">Selection seed (optional)</label>
        <input id="gn_seed" name="seed" type="number" class="sh-input" value="<?= e($_GET['seed'] ?? '') ?>" placeholder="reproducible pick">
        <p class="sh-help">Same seed → same question selection. Leave empty for a fresh draw.</p>
      </div>
      <div class="sh-field sh-flex sh-items-end">
        <button class="sh-btn sh-btn-secondary"><i class="fa-solid fa-eye" aria-hidden="true"></i> Preview selection</button>
      </div>
    </form>

    <?php if ($selTpl): $cfg = $selTpl['config']; ?>
    <h3 class="sh-card-title sh-panel-title sh-wstep">2 · Pools &amp; difficulty (from the template)</h3>
    <p class="sh-cell-sub"><?= (int)$selTpl['section_count'] ?> section(s) · <?= (int)$selTpl['question_count'] ?> questions ·
      <?= !empty($cfg['randomize']) ? 'randomized' : 'fixed order' ?> · negative marking <?= e((string)($cfg['negative_marking'] ?? 0)) ?> ·
      partial credit <?= !empty($cfg['partial_credit']) ? 'on' : 'off' ?></p>

    <h3 class="sh-card-title sh-panel-title sh-wstep">3 · Configuration</h3>
    <p class="sh-cell-sub"><?= (int)$selTpl['duration_minutes'] ?> minutes · pass ≥ <?= (int)$selTpl['passing_score'] ?>% ·
      <?= (int)$selTpl['max_attempts'] ?> attempt(s)<?= $selTpl['expiry_days'] !== null ? ' · link valid ' . (int)$selTpl['expiry_days'] . ' day(s)' : '' ?>
      <a class="sh-btn sh-btn-ghost sh-btn-sm" href="assessment_center.php?view=templates&edit=<?= $tid ?>">Edit rules</a></p>
    <?php endif; ?>

    <?php if ($P): ?>
    <h3 class="sh-card-title sh-panel-title sh-wstep">4 · Preview &amp; generate</h3>
    <?php if (!$P['ok']): ?>
    <div class="sh-flash sh-flash-error" role="alert"><?= e($P['error']) ?></div>
    <?php else: ?>
    <div class="sh-form-grid">
      <div class="sh-field">
        <p class="sh-cell-sub">Questions</p><p class="sh-kpi-value sh-tnum"><?= (int)$P['total_questions'] ?></p>
      </div>
      <div class="sh-field">
        <p class="sh-cell-sub">Total marks</p><p class="sh-kpi-value sh-tnum"><?= (int)$P['total_marks'] ?></p>
      </div>
      <div class="sh-field">
        <p class="sh-cell-sub">Est. completion</p><p class="sh-kpi-value sh-tnum"><?= (int)$P['est_minutes'] ?><span class="sh-cell-sub"> min</span></p>
      </div>
      <div class="sh-field">
        <p class="sh-cell-sub">Estimated difficulty</p>
        <p><span class="sh-badge sh-badge-success sh-tnum"><?= (int)$P['difficulty']['easy'] ?>E</span>
           <span class="sh-badge sh-badge-warning sh-tnum"><?= (int)$P['difficulty']['medium'] ?>M</span>
           <span class="sh-badge sh-badge-danger sh-tnum"><?= (int)$P['difficulty']['hard'] ?>H</span></p>
      </div>
    </div>
    <?php foreach ($P['sections'] as $sec): ?>
    <p class="sh-cell-sub"><i class="fa-solid fa-list" aria-hidden="true"></i> <?= e($sec['name']) ?>: <?= count($sec['question_ids']) ?> question(s)
      <?= $sec['shortfall'] > 0 ? '<span class="sh-badge sh-badge-warning">short by ' . (int)$sec['shortfall'] . ' — pool too small</span>' : '' ?></p>
    <?php endforeach; ?>
    <?php if ($P['skills']): sh_center_bars('Skill coverage of this selection', array_slice($P['skills'], 0, 8)); ?>
    <?php else: ?><p class="sh-cell-sub">No skill tags on the selected questions — tag them in the bank to unlock skill analytics.</p><?php endif; ?>

    <form method="POST" class="sh-flex sh-items-center sh-gap-3 sh-wrap sh-mt-4">
      <?= csrf_field() ?>
      <input type="hidden" name="form_action" value="generate">
      <input type="hidden" name="back_view" value="generator">
      <input type="hidden" name="back_template" value="<?= $tid ?>">
      <input type="hidden" name="template_id" value="<?= $tid ?>">
      <input type="hidden" name="seed" value="<?= e($_GET['seed'] ?? '') ?>">
      <label class="sh-sr-only" for="gn_cand">Candidate</label>
      <select id="gn_cand" name="candidate_id" class="sh-input sh-flex-1" required>
        <option value="">— issue to candidate —</option>
        <?php foreach ($D['candidates'] as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?> (<?= e($c['position']) ?>)</option><?php endforeach; ?>
      </select>
      <button class="sh-btn sh-btn-primary"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Generate assessment</button>
    </form>
    <p class="sh-help sh-mt-2">Note: without a seed, the generated selection is a fresh draw and may differ from this preview when randomization is on.</p>
    <?php endif; ?>
    <?php elseif ($tid): ?>
    <div class="sh-flash sh-flash-error" role="alert">Template not found or not published.</div>
    <?php endif; ?>
  </section>

  <aside class="sh-card" aria-label="How generation works">
    <div class="sh-card-header"><h2 class="sh-card-title">How it works</h2></div>
    <ul class="sh-timeline">
      <li><strong>Template</strong> defines sections, pools, difficulty mix and rules.</li>
      <li><strong>Preview</strong> is a dry run of the same engine — nothing is saved.</li>
      <li><strong>Generate</strong> writes a regular online test (existing pipeline) with the config frozen at issue time.</li>
      <li><strong>Candidate</strong> receives it in their portal exactly like any online test.</li>
      <li><strong>Review &amp; results</strong> flow through the queue and workspace tabs.</li>
    </ul>
  </aside>
</div>
