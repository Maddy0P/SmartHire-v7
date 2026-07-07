<?php
// ═════════════════════════════════════════════════════════════════════════════
//  recruitment_analytics.php — Enterprise recruitment analytics (Build 4)
//  Funnel · applications per job · offer acceptance · time-to-hire · ATS dist ·
//  job & department performance. Recruiter-or-higher. Pure inline SVG/CSS charts.
//  Phase 2: replaced full-table PHP aggregation with server-side SQL GROUP BY.
// ═════════════════════════════════════════════════════════════════════════════
require_once 'includes/config.php';
require_once 'includes/layout.php';
require_once 'includes/recruitment.php';
requireRole('recruiter');

// ── QUERY 1: Top-level application aggregates ─────────────────────────────────
// Was: SELECT all rows → PHP array_filter/count. Now: pure SQL aggregation.
$appAgg = dbFetchOne("
    SELECT
        COUNT(*)                                          AS total_apps,
        COUNT(*) FILTER (WHERE stage = 'joined')          AS hired,
        COUNT(*) FILTER (WHERE stage = 'rejected')        AS rejected,
        ROUND(AVG(ats_score) FILTER (WHERE ats_score IS NOT NULL)) AS avg_ats
    FROM job_applications
") ?? [];

$totalApps = (int)($appAgg['total_apps'] ?? 0);
$hired     = (int)($appAgg['hired']      ?? 0);
$rejected  = (int)($appAgg['rejected']   ?? 0);
$avgAts    = (int)($appAgg['avg_ats']    ?? 0);
$active    = $totalApps - $hired - $rejected;

// ── QUERY 2: Funnel — stage counts aggregated in SQL (no full-table PHP load) ─
$stageCounts = dbFetchAll("
    SELECT stage, COUNT(*) AS cnt
    FROM job_applications
    GROUP BY stage
") ?? [];

// Reconstruct the funnel from the stage counts (tiny array, 11 max rows)
$stageMap = array_column($stageCounts, 'cnt', 'stage');
$flow      = sh_stage_flow();
// Cumulative funnel: count[stage] = applications that reached this stage or further
$funnel = [];
foreach ($flow as $i => $stage) {
    $cnt = 0;
    foreach ($flow as $j => $s) {
        if ($j >= $i && isset($stageMap[$s])) $cnt += (int)$stageMap[$s];
    }
    $funnel[$stage] = $cnt;
}

// ── QUERY 3: ATS score distribution — SQL bucketing ───────────────────────────
$distRow = dbFetchOne("
    SELECT
        COUNT(*) FILTER (WHERE ats_score >= 75)               AS d_strong,
        COUNT(*) FILTER (WHERE ats_score >= 60 AND ats_score < 75) AS d_good,
        COUNT(*) FILTER (WHERE ats_score >= 40 AND ats_score < 60) AS d_mid,
        COUNT(*) FILTER (WHERE ats_score < 40 AND ats_score IS NOT NULL) AS d_low
    FROM job_applications
") ?? [];
$dist = [
    '75-100' => (int)($distRow['d_strong'] ?? 0),
    '60-74'  => (int)($distRow['d_good']   ?? 0),
    '40-59'  => (int)($distRow['d_mid']    ?? 0),
    '0-39'   => (int)($distRow['d_low']    ?? 0),
];

// ── QUERY 4: Offer aggregates ─────────────────────────────────────────────────
$offAgg    = dbFetchOne("
    SELECT
        COUNT(*)                                                 AS released,
        COUNT(*) FILTER (WHERE status IN ('accepted','joined'))  AS accepted,
        COUNT(*) FILTER (WHERE status = 'declined')              AS declined
    FROM offers
") ?? [];
$released   = (int)($offAgg['released'] ?? 0);
$accepted   = (int)($offAgg['accepted'] ?? 0);
$acceptRate = sh_acceptance_rate($released, $accepted);
$convRate   = sh_conversion_rate($totalApps, $hired);

// ── QUERY 5: Time-to-hire metrics ────────────────────────────────────────────
$hireDays = array_column(dbFetchAll("
    SELECT (e.created_at::date - a.applied_at::date) AS d
    FROM application_events e
    JOIN job_applications a ON a.id = e.application_id
    WHERE e.to_stage = 'joined'
"), 'd');
$avgTimeToHire = sh_avg_days(array_map('intval', $hireDays));

$ivDays = array_column(dbFetchAll("
    SELECT (e.created_at::date - a.applied_at::date) AS d
    FROM application_events e
    JOIN job_applications a ON a.id = e.application_id
    WHERE e.to_stage = 'interview_scheduled'
"), 'd');
$avgTimeToInterview = sh_avg_days(array_map('intval', $ivDays));

// ── QUERY 6: Job performance ──────────────────────────────────────────────────
$perJob = dbFetchAll("
    SELECT j.title,
           COUNT(a.id)                                           AS apps,
           ROUND(AVG(a.ats_score) FILTER (WHERE a.ats_score IS NOT NULL)) AS avg_ats,
           COUNT(*) FILTER (WHERE a.stage = 'joined')           AS hired
    FROM jobs j
    LEFT JOIN job_applications a ON a.job_id = j.id
    GROUP BY j.id, j.title
    ORDER BY apps DESC
    LIMIT 10
");

// ── QUERY 7: Department analytics ────────────────────────────────────────────
$byDept = dbFetchAll("
    SELECT COALESCE(NULLIF(j.department,''),'Unassigned') AS dept,
           COUNT(a.id)                                    AS apps,
           COUNT(*) FILTER (WHERE a.stage='joined')       AS hired
    FROM jobs j
    LEFT JOIN job_applications a ON a.job_id = j.id
    GROUP BY dept
    ORDER BY apps DESC
    LIMIT 8
");

$maxFunnel = max(1, ...array_values($funnel ?: [1]));

// ── CSV export (must run before any output) ──────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    audit_log('analytics_export', 'analytics', null, 'recruitment.csv');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="recruitment_analytics_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Metric', 'Value']);
    fputcsv($out, ['Total Applications', $totalApps]);
    fputcsv($out, ['Hired / Joined',     $hired]);
    fputcsv($out, ['Rejected',           $rejected]);
    fputcsv($out, ['Active in Pipeline', $active]);
    fputcsv($out, ['Conversion Rate %',      $convRate]);
    fputcsv($out, ['Offer Acceptance Rate %', $acceptRate]);
    fputcsv($out, ['Avg ATS Score %',        $avgAts]);
    fputcsv($out, ['Avg Time to Hire (days)',      $avgTimeToHire]);
    fputcsv($out, ['Avg Time to Interview (days)', $avgTimeToInterview]);
    fputcsv($out, []);
    fputcsv($out, ['Funnel Stage', 'Count']);
    foreach ($funnel as $stage => $cnt) fputcsv($out, [sh_stage_label($stage), $cnt]);
    fputcsv($out, []);
    fputcsv($out, ['Job', 'Applications', 'Avg ATS', 'Hired']);
    foreach ($perJob as $r) fputcsv($out, [$r['title'], (int)$r['apps'], $r['avg_ats'] !== null ? (int)$r['avg_ats'] : '', (int)$r['hired']]);
    fclose($out);
    exit;
}

function statcard(string $icon, string $val, string $label, string $color = 'blue'): string {
    return '<div class="stat-card"><div class="chart-card-icon ' . $color . '" style="width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;margin-bottom:10px"><i class="fa-solid ' . $icon . '"></i></div>'
         . '<div style="font-size:26px;font-weight:800;color:var(--text-primary)">' . htmlspecialchars($val) . '</div>'
         . '<div class="sh-muted" style="font-size:12.5px">' . htmlspecialchars($label) . '</div></div>';
}

renderHead('Recruitment Analytics');
renderSidebar('recruitment_analytics');
?>
<div class="page-header sh-between sh-wrap">
  <div class="page-header-left">
    <h1><i class="fa-solid fa-chart-pie"></i> Recruitment Analytics</h1>
    <p class="sh-muted">Funnel, conversion, offers and ATS performance across all jobs</p>
  </div>
  <a href="recruitment_analytics.php?export=csv" class="btn btn-secondary"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
</div>

<!-- KPI row -->
<div class="sh-grid sh-grid-4 animate-in">
  <?= statcard('fa-inbox',          (string)$totalApps,    'Total Applications',        'blue') ?>
  <?= statcard('fa-user-check',     (string)$hired,        'Hired / Joined',            'green') ?>
  <?= statcard('fa-percent',        $convRate . '%',        'Conversion Rate',           'violet') ?>
  <?= statcard('fa-gauge-high',     $avgAts . '%',          'Avg ATS Score',             'amber') ?>
  <?= statcard('fa-file-signature', $acceptRate . '%',      'Offer Acceptance',          'green') ?>
  <?= statcard('fa-clock',          $avgTimeToHire . 'd',   'Avg Time to Hire',          'blue') ?>
  <?= statcard('fa-calendar-check', $avgTimeToInterview . 'd', 'Avg Time to Interview', 'violet') ?>
  <?= statcard('fa-users',          (string)$active,        'Active in Pipeline',        'amber') ?>
</div>

<div class="sh-grid" style="grid-template-columns:1.3fr 1fr;margin-top:18px">
  <!-- Funnel -->
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-filter"></i> Recruitment Funnel</h3></div>
    <div class="card-body">
      <?php foreach ($funnel as $stage => $cnt): $w = sh_pct($cnt, $maxFunnel); ?>
      <div class="subscore" style="grid-template-columns:150px 1fr 48px">
        <span><i class="fa-solid <?= sh_stage_icon($stage) ?>" style="color:#60a5fa;width:16px"></i> <?= sh_stage_label($stage) ?></span>
        <span class="score-bar" style="height:14px"><i style="width:<?= max(3, $w) ?>%"></i></span>
        <span style="text-align:right;font-weight:700"><?= $cnt ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ATS distribution -->
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-chart-column"></i> ATS Score Distribution</h3></div>
    <div class="card-body">
      <?php
      $dmax = max(1, ...array_values($dist ?: [1]));
      $dcol = ['0-39' => '#f43f5e', '40-59' => '#f59e0b', '60-74' => '#3b82f6', '75-100' => '#10b981'];
      foreach ($dist as $band => $cnt): ?>
      <div class="subscore" style="grid-template-columns:70px 1fr 40px">
        <span><?= $band ?></span>
        <span class="score-bar" style="height:14px"><i style="width:<?= max(3, sh_pct($cnt, $dmax)) ?>%;background:<?= $dcol[$band] ?>"></i></span>
        <span style="text-align:right;font-weight:700"><?= $cnt ?></span>
      </div>
      <?php endforeach; ?>
      <div class="sh-flex sh-mt" style="gap:16px;flex-wrap:wrap;font-size:12px">
        <span class="sh-muted"><span style="color:#10b981">●</span> Strong 75+</span>
        <span class="sh-muted"><span style="color:#3b82f6">●</span> Good 60-74</span>
        <span class="sh-muted"><span style="color:#f59e0b">●</span> Fair 40-59</span>
        <span class="sh-muted"><span style="color:#f43f5e">●</span> Weak &lt;40</span>
      </div>
    </div>
  </div>
</div>

<!-- Job performance -->
<div class="card sh-mt">
  <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-briefcase"></i> Job Performance (Top 10)</h3></div>
  <div class="card-body">
    <div class="table-container">
      <table class="table sh-rtable">
        <thead><tr><th>Job</th><th>Applications</th><th>Avg ATS</th><th>Hired</th><th>Conversion</th></tr></thead>
        <tbody>
          <?php foreach ($perJob as $r): $c = sh_conversion_rate((int)$r['apps'], (int)$r['hired']); ?>
          <tr>
            <td data-label="Job"><strong><?= e($r['title']) ?></strong></td>
            <td data-label="Applications"><?= (int)$r['apps'] ?></td>
            <td data-label="Avg ATS"><?= $r['avg_ats'] !== null ? (int)$r['avg_ats'] . '%' : '—' ?></td>
            <td data-label="Hired"><?= (int)$r['hired'] ?></td>
            <td data-label="Conversion"><span class="stage-badge stage-<?= $c >= 20 ? 'green' : ($c > 0 ? 'amber' : 'gray') ?>"><?= $c ?>%</span></td>
          </tr>
          <?php endforeach; if (!$perJob): ?><tr><td colspan="5" class="sh-muted">No jobs yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Department analytics -->
<div class="card sh-mt">
  <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-building"></i> Department Analytics</h3></div>
  <div class="card-body">
    <?php
    $depMax = max(1, ...array_map(fn($d) => (int)$d['apps'], $byDept ?: [['apps' => 1]]));
    foreach ($byDept as $d): ?>
    <div class="subscore" style="grid-template-columns:160px 1fr 90px">
      <span><i class="fa-solid fa-building" style="color:#60a5fa;width:16px"></i> <?= e($d['dept']) ?></span>
      <span class="score-bar" style="height:14px"><i style="width:<?= max(3, sh_pct((int)$d['apps'], $depMax)) ?>%"></i></span>
      <span style="text-align:right;font-weight:700"><?= (int)$d['apps'] ?> apps · <?= (int)$d['hired'] ?> hired</span>
    </div>
    <?php endforeach; if (!$byDept): ?><p class="sh-muted">No department data yet.</p><?php endif; ?>
  </div>
</div>

<?php renderFooter(); ?>
