<?php
// Assessment Center — shared shell: header, flash, tab nav, tiny render helpers.
// Pure presentation; receives $view, $centerTabs, $centerUrl, $searchQ from the entry point.

/** Reusable CSS bar chart (tokens only; widths are sanctioned data-bound styles). */
function sh_center_bars(string $title, array $data, string $unit = ''): void
{
    $max = max(1, ...array_values($data ?: [0]));
    echo '<div class="sh-card sh-cchart"><h3 class="sh-card-title sh-panel-title">' . e($title) . '</h3>';
    if (!$data || array_sum($data) == 0) { echo '<p class="sh-cell-sub sh-mt-2">No data yet.</p></div>'; return; }
    echo '<ul class="sh-cbars">';
    foreach ($data as $label => $val) {
        $pct = (int)round($val / $max * 100);
        echo '<li><span class="sh-cbar-label">' . e((string)$label) . '</span>'
           . '<span class="sh-cbar-track"><span class="sh-cbar-fill" style="width:' . $pct . '%"></span></span>'
           . '<span class="sh-cbar-val sh-tnum">' . e((string)$val) . $unit . '</span></li>';
    }
    echo '</ul></div>';
}

/** Difficulty badge tone map shared across center views. */
function sh_center_diff_tone(string $d): string
{ return ['easy' => 'success', 'medium' => 'warning', 'hard' => 'danger'][$d] ?? 'neutral'; }

?>
<div class="sh-page-header">
  <div>
    <h1 class="sh-page-title">Assessment Center</h1>
    <p class="sh-page-sub">One command center for questions, pools, templates, generation, review and results — powered by the Assessment Platform Core.</p>
  </div>
  <div class="sh-flex sh-gap-2 sh-items-center">
    <button class="sh-btn sh-btn-secondary sh-btn-sm" onclick="shOpenSearch()" aria-haspopup="dialog">
      <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Search
      <kbd class="sh-kbd" aria-hidden="true">Ctrl K</kbd>
    </button>
    <a class="sh-btn sh-btn-primary" href="assessment_center.php?view=generator">
      <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Generate assessment
    </a>
  </div>
</div>

<nav class="sh-flex sh-gap-2 sh-mb-4 sh-wrap" aria-label="Assessment Center sections">
  <?php foreach ($centerTabs as $key => [$icon, $label]): ?>
  <a href="assessment_center.php?view=<?= $key ?>" class="sh-chip <?= $view === $key ? 'active' : '' ?>" <?= $view === $key ? 'aria-current="page"' : '' ?>>
    <i class="fa-solid <?= $icon ?>" aria-hidden="true"></i> <?= $label ?>
  </a>
  <?php endforeach; ?>
</nav>
