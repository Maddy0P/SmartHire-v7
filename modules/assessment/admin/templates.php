<?php /* Assessment Center — templates workspace. Data: $D['templates'], $D['pools'], $D['edit'], $D['filters'] */
$T = $D['edit']; $isNew = isset($_GET['new']);
$stTone = ['active' => 'success', 'draft' => 'warning', 'archived' => 'neutral'];
?>
<form method="GET" action="assessment_center.php" class="sh-flex sh-items-center sh-gap-3 sh-wrap sh-mb-4" role="search" aria-label="Search templates">
  <input type="hidden" name="view" value="templates">
  <div class="sh-topbar-search sh-search-inline">
    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
    <label class="sh-sr-only" for="tplQ">Search templates</label>
    <input type="search" id="tplQ" name="q" value="<?= e($D['filters']['q']) ?>" placeholder="Search name or role…" autocomplete="off" data-debounce-submit>
  </div>
  <label class="sh-sr-only" for="tplStatus">Status</label>
  <select id="tplStatus" name="status" class="sh-input sh-input-auto">
    <option value="">Any status</option>
    <?php foreach (['draft','active','archived'] as $st): ?><option value="<?= $st ?>" <?= $D['filters']['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option><?php endforeach; ?>
  </select>
  <button class="sh-btn sh-btn-secondary sh-btn-sm">Apply</button>
  <a class="sh-btn sh-btn-primary sh-btn-sm" href="assessment_center.php?view=templates&new=1"><i class="fa-solid fa-file-circle-plus" aria-hidden="true"></i> New template</a>
</form>

<section class="sh-card sh-card-flush sh-mb-4" aria-label="Assessment templates">
  <?php if (!$D['templates']): ?>
  <div class="sh-empty">
    <div class="sh-empty-icon"><i class="fa-solid fa-file-invoice" aria-hidden="true"></i></div>
    <h2>No templates yet</h2><p>A template is a reusable blueprint: sections drawn from pools with difficulty rules. Build one and reuse it across every opening.</p>
    <a class="sh-btn sh-btn-primary sh-mt-2" href="assessment_center.php?view=templates&new=1">Build your first template</a>
  </div>
  <?php else: ?>
  <div class="sh-table-wrap">
    <table class="sh-table">
      <thead><tr>
        <th scope="col">Template</th><th scope="col">Role / level</th><th scope="col">Structure</th>
        <th scope="col">Rules</th><th scope="col">Issued</th><th scope="col">Status</th>
        <th scope="col"><span class="sh-sr-only">Actions</span></th>
      </tr></thead>
      <tbody>
        <?php foreach ($D['templates'] as $t): $tid = (int)$t['id']; $cfg = $t['config']; ?>
        <tr>
          <td data-th="Template">
            <a class="sh-cellbtn" href="assessment_center.php?view=templates&edit=<?= $tid ?>">
              <span class="sh-avatar" aria-hidden="true"><i class="fa-solid fa-file-invoice"></i></span>
              <span><span class="sh-block"><?= e($t['name']) ?></span>
              <span class="sh-cell-sub"><?= e($t['category'] ?: '—') ?> · <?= e($t['department'] ?: 'Any dept') ?></span></span>
            </a>
          </td>
          <td data-th="Role / level"><?= e($t['role'] ?: '—') ?><span class="sh-cell-sub sh-block"><?= e($t['experience_level'] ?: 'any') ?></span></td>
          <td data-th="Structure" class="sh-tnum"><?= (int)$t['section_count'] ?> sections · <?= (int)$t['question_count'] ?> Qs
            <span class="sh-cell-sub sh-block"><?= (int)$t['duration_minutes'] ?> min</span></td>
          <td data-th="Rules" class="sh-cell-sub">
            pass ≥ <?= (int)$t['passing_score'] ?>%<?= !empty($cfg['negative_marking']) ? ' · −' . $cfg['negative_marking'] : '' ?><?= !empty($cfg['randomize']) ? ' · shuffled' : '' ?><?= !empty($cfg['partial_credit']) ? ' · partial' : '' ?>
          </td>
          <td data-th="Issued" class="sh-tnum"><?= (int)$t['issued_count'] ?></td>
          <td data-th="Status"><span class="sh-badge sh-badge-<?= $stTone[$t['status']] ?? 'neutral' ?>"><?= e($t['status']) ?></span></td>
          <td>
            <div class="sh-row-actions">
              <a class="sh-iconbtn" href="assessment_center.php?view=generator&template_id=<?= $tid ?>" aria-label="Preview / generate from <?= e($t['name']) ?>"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i></a>
              <form method="POST" class="sh-inline-form">
                <?= csrf_field() ?><input type="hidden" name="form_action" value="template_clone">
                <input type="hidden" name="back_view" value="templates"><input type="hidden" name="template_id" value="<?= $tid ?>">
                <input type="hidden" name="name" value="<?= e($t['name']) ?> (copy)">
                <button class="sh-iconbtn" aria-label="Clone <?= e($t['name']) ?>"><i class="fa-solid fa-copy" aria-hidden="true"></i></button>
              </form>
              <form method="POST" class="sh-inline-form">
                <?= csrf_field() ?><input type="hidden" name="form_action" value="template_status">
                <input type="hidden" name="back_view" value="templates"><input type="hidden" name="template_id" value="<?= $tid ?>">
                <input type="hidden" name="status" value="<?= $t['status'] === 'active' ? 'archived' : 'active' ?>">
                <button class="sh-iconbtn <?= $t['status'] === 'active' ? 'sh-danger-text' : '' ?>" aria-label="<?= $t['status'] === 'active' ? 'Archive' : 'Publish' ?> <?= e($t['name']) ?>"
                        <?= $t['status'] === 'active' ? 'data-confirm="Archive this template? Existing issued tests are unaffected."' : '' ?>>
                  <i class="fa-solid <?= $t['status'] === 'active' ? 'fa-box-archive' : 'fa-circle-check' ?>" aria-hidden="true"></i>
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

<?php if ($T || $isNew):
  $cfg = $T['config'] ?? [];
  $sections = $T['sections'] ?? [];
  if (!$sections) $sections = [['name' => '', 'pool_id' => null, 'question_count' => 5, 'time_minutes' => null, 'difficulty_mix' => []]];
?>
<section class="sh-card sh-card-hero" aria-label="Template builder" id="builder">
  <div class="sh-card-header">
    <div>
      <h2 class="sh-card-title"><?= $T ? 'Edit template — ' . e($T['name']) : 'Template builder' ?></h2>
      <p class="sh-card-sub">Basics → sections (pool + count + difficulty mix) → passing rules. Generation always flows through AssessmentService.</p>
    </div>
    <a class="sh-iconbtn" href="assessment_center.php?view=templates" aria-label="Close builder"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
  </div>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="template_save">
    <input type="hidden" name="back_view" value="templates">
    <?php if ($T): ?><input type="hidden" name="template_id" value="<?= (int)$T['id'] ?>"><?php endif; ?>

    <h3 class="sh-card-title sh-panel-title sh-wstep">1 · Basics</h3>
    <div class="sh-form-grid">
      <div class="sh-field sh-colspan">
        <label class="sh-label" for="tb_name">Template name <span class="req" aria-hidden="true">*</span></label>
        <input id="tb_name" name="name" class="sh-input" required value="<?= e($T['name'] ?? '') ?>" placeholder="e.g. Cloud Engineer — Screening Round">
      </div>
      <div class="sh-field"><label class="sh-label" for="tb_role">Role</label><input id="tb_role" name="role" class="sh-input" value="<?= e($T['role'] ?? '') ?>"></div>
      <div class="sh-field"><label class="sh-label" for="tb_dept">Department</label><input id="tb_dept" name="department" class="sh-input" value="<?= e($T['department'] ?? '') ?>"></div>
      <div class="sh-field"><label class="sh-label" for="tb_cat">Category</label><input id="tb_cat" name="category" class="sh-input" value="<?= e($T['category'] ?? '') ?>"></div>
      <div class="sh-field">
        <label class="sh-label" for="tb_exp">Experience level</label>
        <select id="tb_exp" name="experience_level" class="sh-input">
          <?php foreach (['any','junior','mid','senior','lead'] as $lv): ?><option value="<?= $lv ?>" <?= ($T['experience_level'] ?? 'any') === $lv ? 'selected' : '' ?>><?= ucfirst($lv) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>

    <h3 class="sh-card-title sh-panel-title sh-wstep">2 · Sections</h3>
    <div id="tbSections">
      <?php foreach ($sections as $i => $s): $mix = $s['difficulty_mix'] ?? []; ?>
      <fieldset class="sh-tbsection">
        <legend class="sh-label">Section <?= $i + 1 ?></legend>
        <div class="sh-form-grid">
          <div class="sh-field"><label class="sh-label" for="sec<?= $i ?>_name">Name</label>
            <input id="sec<?= $i ?>_name" name="sections[<?= $i ?>][name]" class="sh-input" value="<?= e($s['name'] ?? '') ?>" placeholder="e.g. Cloud fundamentals"></div>
          <div class="sh-field"><label class="sh-label" for="sec<?= $i ?>_pool">Question pool</label>
            <select id="sec<?= $i ?>_pool" name="sections[<?= $i ?>][pool_id]" class="sh-input">
              <option value="">— pick a pool —</option>
              <?php foreach ($D['pools'] as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)($s['pool_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (<?= (int)$p['question_count'] ?>)</option><?php endforeach; ?>
            </select></div>
          <div class="sh-field"><label class="sh-label" for="sec<?= $i ?>_count">Questions</label>
            <input id="sec<?= $i ?>_count" name="sections[<?= $i ?>][question_count]" type="number" min="1" class="sh-input" value="<?= (int)($s['question_count'] ?? 5) ?>"></div>
          <div class="sh-field"><label class="sh-label" for="sec<?= $i ?>_time">Section minutes (optional)</label>
            <input id="sec<?= $i ?>_time" name="sections[<?= $i ?>][time_minutes]" type="number" min="1" class="sh-input" value="<?= $s['time_minutes'] !== null ? (int)$s['time_minutes'] : '' ?>"></div>
          <div class="sh-field"><label class="sh-label" for="sec<?= $i ?>_me">Easy</label>
            <input id="sec<?= $i ?>_me" name="sections[<?= $i ?>][mix_easy]" type="number" min="0" class="sh-input" value="<?= (int)($mix['easy'] ?? 0) ?>"></div>
          <div class="sh-field"><label class="sh-label" for="sec<?= $i ?>_mm">Medium</label>
            <input id="sec<?= $i ?>_mm" name="sections[<?= $i ?>][mix_medium]" type="number" min="0" class="sh-input" value="<?= (int)($mix['medium'] ?? 0) ?>"></div>
          <div class="sh-field"><label class="sh-label" for="sec<?= $i ?>_mh">Hard</label>
            <input id="sec<?= $i ?>_mh" name="sections[<?= $i ?>][mix_hard]" type="number" min="0" class="sh-input" value="<?= (int)($mix['hard'] ?? 0) ?>"></div>
        </div>
        <p class="sh-help">Leave the mix at 0/0/0 to draw from any difficulty. Mix totals below the question count are topped up automatically.</p>
      </fieldset>
      <?php endforeach; ?>
    </div>
    <button type="button" class="sh-btn sh-btn-secondary sh-btn-sm sh-mt-2" id="tbAddSection"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add section</button>

    <h3 class="sh-card-title sh-panel-title sh-wstep">3 · Rules &amp; configuration</h3>
    <div class="sh-form-grid">
      <div class="sh-field"><label class="sh-label" for="tb_dur">Duration (minutes)</label>
        <input id="tb_dur" name="duration_minutes" type="number" min="5" class="sh-input" value="<?= (int)($T['duration_minutes'] ?? 60) ?>"></div>
      <div class="sh-field"><label class="sh-label" for="tb_pass">Passing score (%)</label>
        <input id="tb_pass" name="passing_score" type="number" min="0" max="100" class="sh-input" value="<?= (int)($T['passing_score'] ?? 40) ?>"></div>
      <div class="sh-field"><label class="sh-label" for="tb_att">Max attempts</label>
        <input id="tb_att" name="max_attempts" type="number" min="1" class="sh-input" value="<?= (int)($T['max_attempts'] ?? 1) ?>"></div>
      <div class="sh-field"><label class="sh-label" for="tb_neg">Negative marking (0–1 × weight)</label>
        <input id="tb_neg" name="negative_marking" type="number" min="0" max="1" step="0.05" class="sh-input" value="<?= e((string)($cfg['negative_marking'] ?? '0')) ?>">
        <p class="sh-help">0 keeps legacy behaviour. 0.25 deducts a quarter of the weight per wrong answer.</p></div>
      <div class="sh-field"><label class="sh-label" for="tb_exp_d">Link expiry (days, optional)</label>
        <input id="tb_exp_d" name="expiry_days" type="number" min="1" class="sh-input" value="<?= $T && $T['expiry_days'] !== null ? (int)$T['expiry_days'] : '' ?>"></div>
      <div class="sh-field">
        <label class="sh-label" for="tb_status">Template status</label>
        <select id="tb_status" name="status" class="sh-input">
          <?php foreach (['draft','active','archived'] as $st): ?><option value="<?= $st ?>" <?= ($T['status'] ?? 'draft') === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="sh-field">
        <label class="sh-label"><input type="checkbox" name="randomize" value="1" <?= !empty($cfg['randomize']) ? 'checked' : '' ?>> Randomize question selection &amp; order</label>
        <label class="sh-label"><input type="checkbox" name="partial_credit" value="1" <?= !empty($cfg['partial_credit']) ? 'checked' : '' ?>> Partial credit on multi-select</label>
        <label class="sh-label"><input type="checkbox" name="certification" value="1" <?= !empty($T['certification']) ? 'checked' : '' ?>> Issue certificate on pass</label>
      </div>
      <div class="sh-field sh-colspan">
        <label class="sh-label" for="tb_instr">Candidate instructions</label>
        <textarea id="tb_instr" name="instructions" class="sh-input" rows="2"><?= e($T['instructions'] ?? '') ?></textarea>
      </div>
    </div>

    <div class="sh-flex sh-gap-3 sh-mt-4">
      <button type="submit" class="sh-btn sh-btn-primary"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save template</button>
      <?php if ($T): ?><a class="sh-btn sh-btn-secondary" href="assessment_center.php?view=generator&template_id=<?= (int)$T['id'] ?>"><i class="fa-solid fa-eye" aria-hidden="true"></i> Preview in generator</a><?php endif; ?>
      <a class="sh-btn sh-btn-ghost" href="assessment_center.php?view=templates">Cancel</a>
    </div>
  </form>
</section>
<?php endif; ?>
