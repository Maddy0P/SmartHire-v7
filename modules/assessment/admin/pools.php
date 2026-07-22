<?php /* Assessment Center — pools workspace. Data: $D['pools'], $D['detail'] */ ?>
<div class="sh-center-cols">
  <div>
    <section class="sh-card sh-mb-4" aria-label="Create pool">
      <div class="sh-card-header"><h2 class="sh-card-title">Create pool</h2></div>
      <form method="POST" class="sh-flex sh-items-center sh-gap-3 sh-wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="pool_create">
        <input type="hidden" name="back_view" value="pools">
        <label class="sh-sr-only" for="np_name">Pool name</label>
        <input id="np_name" name="name" class="sh-input sh-flex-1" placeholder="e.g. Cloud Fundamentals Pool" required>
        <label class="sh-sr-only" for="np_tags">Tags</label>
        <input id="np_tags" name="tags" class="sh-input sh-input-auto" placeholder="tags, comma-separated">
        <button class="sh-btn sh-btn-primary sh-btn-sm"><i class="fa-solid fa-circle-plus" aria-hidden="true"></i> Create</button>
      </form>
    </section>

    <section class="sh-card sh-mb-4" aria-label="Merge pools">
      <div class="sh-card-header"><div><h2 class="sh-card-title">Merge pools</h2><p class="sh-card-sub">Copies every question into the target, then archives the source.</p></div></div>
      <form method="POST" class="sh-flex sh-items-center sh-gap-3 sh-wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="pool_merge">
        <input type="hidden" name="back_view" value="pools">
        <label class="sh-sr-only" for="mg_src">Source pool</label>
        <select id="mg_src" name="source_id" class="sh-input sh-input-auto" required>
          <option value="">Source…</option>
          <?php foreach ($D['pools'] as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
        <i class="fa-solid fa-arrow-right sh-text-muted" aria-hidden="true"></i>
        <label class="sh-sr-only" for="mg_tgt">Target pool</label>
        <select id="mg_tgt" name="target_id" class="sh-input sh-input-auto" required>
          <option value="">Target…</option>
          <?php foreach ($D['pools'] as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
        <button class="sh-btn sh-btn-secondary sh-btn-sm" data-confirm="Merge and archive the source pool?">Merge</button>
      </form>
    </section>

    <section class="sh-card sh-card-flush" aria-label="Question pools">
      <?php if (!$D['pools']): ?>
      <div class="sh-empty">
        <div class="sh-empty-icon"><i class="fa-solid fa-layer-group" aria-hidden="true"></i></div>
        <h2>No pools yet</h2><p>Create your first pool above, then add questions from the bank via bulk actions.</p>
      </div>
      <?php else: ?>
      <div class="sh-table-wrap">
        <table class="sh-table">
          <thead><tr>
            <th scope="col">Pool</th><th scope="col">Questions</th><th scope="col">Difficulty mix</th>
            <th scope="col">Used by</th><th scope="col"><span class="sh-sr-only">Actions</span></th>
          </tr></thead>
          <tbody>
            <?php foreach ($D['pools'] as $p): $pid = (int)$p['id']; $b = $p['breakdown']; ?>
            <tr>
              <td data-th="Pool">
                <a class="sh-cellbtn" href="assessment_center.php?view=pools&pool_id=<?= $pid ?>">
                  <span class="sh-avatar" aria-hidden="true"><i class="fa-solid fa-layer-group"></i></span>
                  <span><span class="sh-block"><?= e($p['name']) ?></span>
                  <span class="sh-cell-sub"><?= e($p['tags'] ?: ($p['description'] ?? '') ?: '—') ?></span></span>
                </a>
              </td>
              <td data-th="Questions" class="sh-tnum"><?= (int)$p['question_count'] ?></td>
              <td data-th="Difficulty mix">
                <span class="sh-badge sh-badge-success sh-tnum"><?= $b['easy'] ?>E</span>
                <span class="sh-badge sh-badge-warning sh-tnum"><?= $b['medium'] ?>M</span>
                <span class="sh-badge sh-badge-danger sh-tnum"><?= $b['hard'] ?>H</span>
              </td>
              <td data-th="Used by" class="sh-cell-sub"><?= $p['used_by'] ? count($p['used_by']) . ' template(s)' : '—' ?></td>
              <td>
                <div class="sh-row-actions">
                  <form method="POST" class="sh-inline-form">
                    <?= csrf_field() ?><input type="hidden" name="form_action" value="pool_clone">
                    <input type="hidden" name="back_view" value="pools"><input type="hidden" name="pool_id" value="<?= $pid ?>">
                    <input type="hidden" name="name" value="<?= e($p['name']) ?> (copy)">
                    <button class="sh-iconbtn" aria-label="Clone pool <?= e($p['name']) ?>"><i class="fa-solid fa-copy" aria-hidden="true"></i></button>
                  </form>
                  <form method="POST" class="sh-inline-form">
                    <?= csrf_field() ?><input type="hidden" name="form_action" value="pool_archive">
                    <input type="hidden" name="back_view" value="pools"><input type="hidden" name="pool_id" value="<?= $pid ?>">
                    <button class="sh-iconbtn sh-danger-text" data-confirm="Archive this pool? Templates using it will underfill until re-pointed." aria-label="Archive pool <?= e($p['name']) ?>"><i class="fa-solid fa-box-archive" aria-hidden="true"></i></button>
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
  </div>

  <?php if ($D['detail']): $pd = $D['detail']; $pool = $pd['pool']; ?>
  <aside class="sh-card" aria-label="Pool detail">
    <div class="sh-card-header">
      <div><h2 class="sh-card-title"><?= e($pool->name) ?></h2>
      <p class="sh-card-sub"><?= count($pool->questionIds) ?> question(s) · <?= e($pool->status) ?></p></div>
      <a class="sh-iconbtn" href="assessment_center.php?view=pools" aria-label="Close pool detail"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
    </div>
    <h3 class="sh-card-title sh-panel-title">Difficulty breakdown</h3>
    <?php $bd = array_map('count', $pd['by_difficulty']); sh_center_bars('', ['Easy' => $bd['easy'], 'Medium' => $bd['medium'], 'Hard' => $bd['hard']]); ?>
    <h3 class="sh-card-title sh-panel-title sh-mt-4">Skills coverage</h3>
    <?php if ($pd['skills']): sh_center_bars('', array_slice($pd['skills'], 0, 8)); else: ?><p class="sh-cell-sub">No skill tags yet — tag questions in the bank.</p><?php endif; ?>
    <h3 class="sh-card-title sh-panel-title sh-mt-4">Used by templates</h3>
    <?php if ($pd['used_by']): ?>
    <ul class="sh-timeline">
      <?php foreach ($pd['used_by'] as $t): ?>
      <li><a href="assessment_center.php?view=templates&edit=<?= (int)$t['id'] ?>"><?= e($t['name']) ?></a> <span class="sh-cell-sub">(<?= e($t['status']) ?>)</span></li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?><p class="sh-cell-sub">Not referenced by any template yet.</p><?php endif; ?>
    <div class="sh-mt-4">
      <a class="sh-btn sh-btn-secondary sh-btn-sm" href="assessment_center.php?view=bank&pool_id=<?= (int)$pool->id ?>"><i class="fa-solid fa-circle-question" aria-hidden="true"></i> View its questions</a>
    </div>
  </aside>
  <?php endif; ?>
</div>
