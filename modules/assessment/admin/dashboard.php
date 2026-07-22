<?php /* Assessment Center — dashboard. Data: $D['dash'] from AssessmentService::dashboard(). */
$o = $D['dash']['overview'];
$kpis = [
    ['fa-bolt',            'Active assessments',  $o['active_assessments'],  'view=results'],
    ['fa-pen-ruler',       'Draft templates',     $o['draft_templates'],     'view=templates&status=draft'],
    ['fa-file-invoice',    'Published templates', $o['published_templates'], 'view=templates&status=active'],
    ['fa-layer-group',     'Question pools',      $o['pools'],               'view=pools'],
    ['fa-circle-question', 'Questions',           $o['questions'],           'view=bank'],
    ['fa-user-clock',      'In progress',         $o['in_progress'],         'view=results'],
    ['fa-user-check',      'Pending reviews',     $o['pending_reviews'],     'view=reviews'],
    ['fa-calendar-day',    'Completed today',     $o['completed_today'],     'view=results'],
    ['fa-percent',         'Average score',       $o['avg_score'] . '%',     'view=results'],
    ['fa-flag-checkered',  'Pass rate',           $o['pass_rate'] . '%',     'view=results'],
];
?>
<div class="sh-kpi-grid sh-kpi-grid-5">
  <?php foreach ($kpis as [$icon, $label, $value, $link]): ?>
  <a class="sh-kpi sh-kpi-link" href="assessment_center.php?<?= $link ?>">
    <div class="sh-kpi-top"><i class="fa-solid <?= $icon ?>" aria-hidden="true"></i><?= $label ?></div>
    <div class="sh-kpi-value sh-tnum"><?= e((string)$value) ?></div>
  </a>
  <?php endforeach; ?>
</div>

<section class="sh-card sh-mb-4" aria-label="Quick actions">
  <div class="sh-card-header"><h2 class="sh-card-title">Quick actions</h2></div>
  <div class="sh-flex sh-gap-2 sh-wrap">
    <a class="sh-btn sh-btn-primary sh-btn-sm" href="assessment_center.php?view=generator"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Generate assessment</a>
    <a class="sh-btn sh-btn-secondary sh-btn-sm" href="assessment_center.php?view=templates&new=1"><i class="fa-solid fa-file-circle-plus" aria-hidden="true"></i> Create template</a>
    <a class="sh-btn sh-btn-secondary sh-btn-sm" href="assessment_center.php?view=bank&new=1"><i class="fa-solid fa-circle-plus" aria-hidden="true"></i> Create question</a>
    <a class="sh-btn sh-btn-secondary sh-btn-sm" href="assessment_center.php?view=pools"><i class="fa-solid fa-layer-group" aria-hidden="true"></i> Create pool</a>
    <a class="sh-btn sh-btn-secondary sh-btn-sm" href="assessment_center.php?view=reviews"><i class="fa-solid fa-user-check" aria-hidden="true"></i> Review results</a>
    <a class="sh-btn sh-btn-secondary sh-btn-sm" href="questions.php"><i class="fa-solid fa-file-import" aria-hidden="true"></i> Import questions (legacy bank)</a>
  </div>
</section>

<?php
// 7 charts — reusable bar renderer, all server-computed
$act = [];
foreach ($D['dash']['activity'] as $d => $n) $act[date('d M', strtotime($d))] = $n;
$pf  = $D['dash']['pass_fail'];
$pr  = $D['dash']['progress'];
$total = max(1, $pf['pass'] + $pf['fail']);
?>
<div class="sh-cchart-grid">
  <?php sh_center_bars('Assessment activity — last 14 days', $act); ?>
  <?php sh_center_bars('Candidate progress', ['In progress' => $pr['in_progress'], 'Completed' => $pr['completed']]); ?>
  <?php sh_center_bars('Completion rate', ['Completed %' => $pr['in_progress'] + $pr['completed'] > 0 ? (int)round($pr['completed'] / ($pr['in_progress'] + $pr['completed']) * 100) : 0], '%'); ?>
  <?php sh_center_bars('Pass vs fail', ['Pass' => $pf['pass'], 'Fail' => $pf['fail']]); ?>
  <?php sh_center_bars('Most used templates', array_column($D['dash']['top_templates'], 'n', 'name')); ?>
  <?php sh_center_bars('Most used pools', array_column($D['dash']['top_pools'], 'n', 'name')); ?>
  <?php sh_center_bars('Question difficulty distribution', ['Easy' => $D['dash']['difficulty']['easy'], 'Medium' => $D['dash']['difficulty']['medium'], 'Hard' => $D['dash']['difficulty']['hard']]); ?>
</div>
