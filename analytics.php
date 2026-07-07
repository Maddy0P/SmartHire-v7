<?php
require_once 'includes/layout.php';
requireLogin();

// ── QUERY 1: All candidate aggregates in one pass ─────────────────────────────
$cAgg = dbFetchOne("
    SELECT
        COUNT(*)                                              AS total_candidates,
        COUNT(*) FILTER (WHERE status = 'hired')              AS hired,
        COUNT(*) FILTER (WHERE status = 'rejected')           AS rejected,
        COUNT(*) FILTER (WHERE status = 'pending')            AS pending,
        COUNT(*) FILTER (WHERE status = 'interviewed')        AS interviewed,
        COUNT(*) FILTER (WHERE status = 'scheduled')          AS scheduled,
        ROUND(AVG(ai_score) FILTER (WHERE ai_score > 0), 1)  AS avg_score,
        SUM((ai_score BETWEEN 80 AND 100)::int)               AS excellent,
        SUM((ai_score BETWEEN 60 AND 79)::int)                AS good,
        SUM((ai_score BETWEEN 40 AND 59)::int)                AS average,
        SUM((ai_score > 0 AND ai_score < 40)::int)            AS below
    FROM candidates
") ?? [];

$total_candidates = (int)($cAgg['total_candidates'] ?? 0);
$hired            = (int)($cAgg['hired']            ?? 0);
$rejected         = (int)($cAgg['rejected']         ?? 0);
$pending          = (int)($cAgg['pending']          ?? 0);
$interviewed      = (int)($cAgg['interviewed']      ?? 0);
$scheduled        = (int)($cAgg['scheduled']        ?? 0);
$avg_score        = $cAgg['avg_score'] ?? 0;
$hire_rate        = $total_candidates > 0 ? round($hired / $total_candidates * 100) : 0;
$sd = [
    'excellent' => $cAgg['excellent'] ?? 0,
    'good'      => $cAgg['good']      ?? 0,
    'average'   => $cAgg['average']   ?? 0,
    'below'     => $cAgg['below']     ?? 0,
];

// ── QUERY 2: All test submission aggregates in one pass ───────────────────────
$tsAgg = dbFetchOne("
    SELECT
        COUNT(*) FILTER (WHERE status IN ('submitted','auto_submitted'))                        AS tests_completed,
        ROUND(AVG(percentage) FILTER (WHERE status IN ('submitted','auto_submitted')), 1)       AS avg_test_score,
        SUM((status IN ('submitted','auto_submitted') AND percentage >= 80)::int)               AS tsd_excellent,
        SUM((status IN ('submitted','auto_submitted') AND percentage >= 60 AND percentage < 80)::int) AS tsd_good,
        SUM((status IN ('submitted','auto_submitted') AND percentage >= 40 AND percentage < 60)::int) AS tsd_average,
        SUM((status IN ('submitted','auto_submitted') AND percentage < 40)::int)                AS tsd_poor
    FROM test_submissions
") ?? [];

$tests_completed = (int)($tsAgg['tests_completed'] ?? 0);
$avg_test_score  = $tsAgg['avg_test_score'] ?? 0;
$tsd = [
    'excellent' => $tsAgg['tsd_excellent'] ?? 0,
    'good'      => $tsAgg['tsd_good']      ?? 0,
    'average'   => $tsAgg['tsd_average']   ?? 0,
    'poor'      => $tsAgg['tsd_poor']      ?? 0,
];

// ── QUERY 3: Resume scan aggregates ──────────────────────────────────────────
$rsAgg = dbFetchOne("
    SELECT COUNT(*) AS scans_done, ROUND(AVG(ats_score), 1) AS avg_ats
    FROM resume_scans
") ?? [];
$scans_done = (int)($rsAgg['scans_done'] ?? 0);
$avg_ats    = $rsAgg['avg_ats'] ?? 0;

// ── QUERY 4: Candidates by position ──────────────────────────────────────────
$byPosition = dbFetchAll("
    SELECT position, COUNT(*) AS cnt, ROUND(AVG(ai_score), 1) AS avg_score
    FROM candidates
    GROUP BY position
    ORDER BY cnt DESC
    LIMIT 8
");

// ── QUERY 5: Recent test submissions ─────────────────────────────────────────
$testPerf = dbFetchAll("
    SELECT ot.title, c.name AS cname, ts.percentage, ts.total_score, ts.max_score, ts.submitted_at
    FROM test_submissions ts
    JOIN online_tests ot ON ot.id = ts.test_id
    JOIN candidates c ON c.id = ts.candidate_id
    WHERE ts.status IN ('submitted','auto_submitted')
    ORDER BY ts.submitted_at DESC
    LIMIT 10
");

// ── QUERY 6: Top ATS scores ───────────────────────────────────────────────────
$atsTop = dbFetchAll("
    SELECT rs.candidate_name_free, c.name AS cname, rs.position_applied, rs.ats_score, rs.scanned_at
    FROM resume_scans rs
    LEFT JOIN candidates c ON c.id = rs.candidate_id
    ORDER BY rs.ats_score DESC
    LIMIT 8
");

// ── QUERY 7: Test time analytics — correlated subqueries replaced with JOIN ──
$timeAnalytics = dbFetchAll("
    SELECT c.name, c.position,
           ts.id AS sub_id, ts.percentage, ts.time_taken_mins,
           ts.total_score, ts.max_score,
           ot.title AS test_title, ot.duration_minutes, ot.id AS test_id,
           ot.passing_marks,
           ta_agg.avg_secs_per_q,
           ta_agg.correct_count
    FROM test_submissions ts
    JOIN candidates c ON c.id = ts.candidate_id
    JOIN online_tests ot ON ot.id = ts.test_id
    LEFT JOIN (
        SELECT submission_id,
               ROUND(AVG(time_spent_secs)) AS avg_secs_per_q,
               COUNT(*) FILTER (WHERE is_correct = 1) AS correct_count
        FROM test_answers
        GROUP BY submission_id
    ) ta_agg ON ta_agg.submission_id = ts.id
    WHERE ts.status IN ('submitted','auto_submitted')
    ORDER BY ts.submitted_at DESC
    LIMIT 20
");

renderHead('Analytics');
renderSidebar('analytics');
?>

<style>
/* ── Analytics page specific styles ───────────────────── */
.an-section-title {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1.2px;
  color: var(--text-muted);
  margin: 0 0 14px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.an-section-title::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--border);
}

/* Equal-height chart grid */
.chart-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 18px;
  margin-bottom: 20px;
  align-items: stretch;
}
.chart-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 14px;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  transition: border-color .2s, box-shadow .2s;
  min-height: 320px;
}
.chart-card:hover {
  border-color: var(--border-bright);
  box-shadow: var(--shadow-md);
}
.chart-card-header {
  padding: 16px 20px 12px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}
.chart-card-icon {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  flex-shrink: 0;
}
.chart-card-icon.blue   { background: rgba(59,130,246,.15);  color: var(--accent-light); }
.chart-card-icon.green  { background: rgba(16,185,129,.15);  color: var(--emerald); }
.chart-card-icon.amber  { background: rgba(245,158,11,.15);  color: var(--amber); }
.chart-card-icon.violet { background: rgba(139,92,246,.15);  color: var(--violet); }
.chart-card-title {
  font-size: 14px;
  font-weight: 700;
  color: var(--text-primary);
}
.chart-card-body {
  padding: 20px;
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}

/* Bar rows inside chart cards */
.bar-row {
  margin-bottom: 14px;
  flex: 1;
}
.bar-row:last-child { margin-bottom: 0; }
.bar-label-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 6px;
}
.bar-label {
  font-size: 13px;
  color: var(--text-primary);
  font-weight: 500;
}
.bar-value {
  font-size: 13px;
  font-weight: 700;
  white-space: nowrap;
}
.bar-muted { color: var(--text-muted); font-weight: 400; font-size: 12px; }
.bar-track {
  height: 8px;
  background: rgba(255,255,255,.07);
  border-radius: 99px;
  overflow: hidden;
}
.bar-fill {
  height: 100%;
  border-radius: 99px;
  transition: width .8s cubic-bezier(.4,0,.2,1);
}

/* Role bar — slightly thinner */
.bar-track.thin { height: 6px; }

/* Tables */
.an-table-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 14px;
  overflow: hidden;
  margin-bottom: 20px;
}
.an-table-card:last-child { margin-bottom: 0; }
.an-table-header {
  padding: 16px 20px 12px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: 10px;
}
.an-table-header .chart-card-icon { flex-shrink: 0; }

@media (max-width: 768px) {
  .chart-grid { grid-template-columns: 1fr; }
}
</style>

<!-- ── Page Header ─────────────────────────────────── -->
<div class="page-header">
  <div class="page-header-left">
    <div class="breadcrumb">
      <a href="dashboard.php">Home</a>
      <i class="fa-solid fa-chevron-right"></i> Analytics
    </div>
    <h1 class="page-title">Hiring Analytics</h1>
    <p class="page-subtitle">Pipeline performance, test scores &amp; ATS metrics</p>
  </div>
</div>

<!-- ── Top Stat Cards ─────────────────────────────── -->
<div class="stats-grid" style="margin-bottom:24px">
  <div class="stat-card blue">
    <div class="stat-icon blue"><i class="fa-solid fa-users"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $total_candidates ?></div>
      <div class="stat-label">Total Candidates</div>
      <div class="stat-delta up">Full pipeline</div>
    </div>
  </div>
  <div class="stat-card green">
    <div class="stat-icon green"><i class="fa-solid fa-handshake"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $hire_rate ?>%</div>
      <div class="stat-label">Hire Rate</div>
      <div class="stat-delta up"><?= $hired ?> hired of <?= $total_candidates ?></div>
    </div>
  </div>
  <div class="stat-card violet">
    <div class="stat-icon violet"><i class="fa-solid fa-robot"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $avg_score ?>%</div>
      <div class="stat-label">Avg AI Score</div>
      <div class="stat-delta up">All candidates</div>
    </div>
  </div>
  <div class="stat-card amber">
    <div class="stat-icon amber"><i class="fa-solid fa-laptop-code"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $avg_test_score ?>%</div>
      <div class="stat-label">Avg Test Score</div>
      <div class="stat-delta up"><?= $tests_completed ?> tests taken</div>
    </div>
  </div>
  <div class="stat-card rose">
    <div class="stat-icon rose"><i class="fa-solid fa-file-magnifying-glass"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $avg_ats ?>%</div>
      <div class="stat-label">Avg ATS Score</div>
      <div class="stat-delta up"><?= $scans_done ?> resumes scanned</div>
    </div>
  </div>
</div>

<!-- ── Row 1: Hiring Pipeline + AI Score Distribution ── -->
<p class="an-section-title"><i class="fa-solid fa-chart-pie"></i> Candidate Overview</p>
<div class="chart-grid">

  <!-- Hiring Pipeline -->
  <div class="chart-card">
    <div class="chart-card-header">
      <div class="chart-card-icon blue"><i class="fa-solid fa-filter"></i></div>
      <span class="chart-card-title">Hiring Pipeline</span>
    </div>
    <div class="chart-card-body">
      <?php
      $pipeline = [
        'Pending'     => [$pending,     '#f59e0b'],
        'Scheduled'   => [$scheduled,   '#6366f1'],
        'Interviewed' => [$interviewed, '#3b82f6'],
        'Hired'       => [$hired,       '#10b981'],
        'Rejected'    => [$rejected,    '#ef4444'],
      ];
      foreach ($pipeline as $label => [$count, $color]):
        $w = $total_candidates > 0 ? round($count / $total_candidates * 100) : 0;
      ?>
      <div class="bar-row">
        <div class="bar-label-row">
          <span class="bar-label"><?= $label ?></span>
          <span class="bar-value" style="color:<?= $color ?>"><?= $count ?>
            <span class="bar-muted">(<?= $w ?>%)</span>
          </span>
        </div>
        <div class="bar-track">
          <div class="bar-fill" style="width:<?= $w ?>%;background:<?= $color ?>"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- AI Score Distribution -->
  <div class="chart-card">
    <div class="chart-card-header">
      <div class="chart-card-icon violet"><i class="fa-solid fa-chart-bar"></i></div>
      <span class="chart-card-title">AI Score Distribution</span>
    </div>
    <div class="chart-card-body">
      <?php
      $distData = [
        ['Excellent (80–100)', $sd['excellent'] ?? 0, '#10b981'],
        ['Good (60–79)',       $sd['good']      ?? 0, '#6366f1'],
        ['Average (40–59)',    $sd['average']   ?? 0, '#f59e0b'],
        ['Below Avg (<40)',    $sd['below']     ?? 0, '#ef4444'],
      ];
      $total_scored = max(1, array_sum(array_column($distData, 1)));
      foreach ($distData as [$label, $count, $color]):
        $w = round($count / $total_scored * 100);
      ?>
      <div class="bar-row">
        <div class="bar-label-row">
          <span class="bar-label"><?= $label ?></span>
          <span class="bar-value" style="color:<?= $color ?>"><?= $count ?>
            <span class="bar-muted">(<?= $w ?>%)</span>
          </span>
        </div>
        <div class="bar-track">
          <div class="bar-fill" style="width:<?= $w ?>%;background:<?= $color ?>"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<!-- ── Row 2: Test Score Distribution + Candidates by Role ── -->
<p class="an-section-title"><i class="fa-solid fa-laptop-code"></i> Test & Role Breakdown</p>
<div class="chart-grid">

  <!-- Test Score Distribution -->
  <div class="chart-card">
    <div class="chart-card-header">
      <div class="chart-card-icon amber"><i class="fa-solid fa-clipboard-list"></i></div>
      <span class="chart-card-title">Test Score Distribution</span>
    </div>
    <div class="chart-card-body">
      <?php
      $tDistData = [
        ['Excellent ≥80%',  $tsd['excellent'] ?? 0, '#10b981'],
        ['Good 60–79%',     $tsd['good']      ?? 0, '#6366f1'],
        ['Average 40–59%',  $tsd['average']   ?? 0, '#f59e0b'],
        ['Poor <40%',       $tsd['poor']      ?? 0, '#ef4444'],
      ];
      $total_test = max(1, array_sum(array_column($tDistData, 1)));
      foreach ($tDistData as [$label, $count, $color]):
        $w = round($count / $total_test * 100);
      ?>
      <div class="bar-row">
        <div class="bar-label-row">
          <span class="bar-label"><?= $label ?></span>
          <span class="bar-value" style="color:<?= $color ?>"><?= $count ?>
            <span class="bar-muted">(<?= $w ?>%)</span>
          </span>
        </div>
        <div class="bar-track">
          <div class="bar-fill" style="width:<?= $w ?>%;background:<?= $color ?>"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Candidates by Role -->
  <div class="chart-card">
    <div class="chart-card-header">
      <div class="chart-card-icon green"><i class="fa-solid fa-briefcase"></i></div>
      <span class="chart-card-title">Candidates by Role</span>
    </div>
    <div class="chart-card-body">
      <?php
      $maxCnt = max(1, !empty($byPosition) ? max(array_column($byPosition, 'cnt')) : 1);
      if (empty($byPosition)): ?>
        <div style="text-align:center;padding:40px 0;color:var(--text-muted);font-size:13px">
          <i class="fa-solid fa-briefcase" style="font-size:28px;display:block;margin-bottom:10px;opacity:.3"></i>
          No candidate data yet
        </div>
      <?php else:
        foreach ($byPosition as $pos):
          $w = round($pos['cnt'] / $maxCnt * 100);
      ?>
      <div class="bar-row">
        <div class="bar-label-row">
          <span class="bar-label" style="max-width:60%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= htmlspecialchars($pos['position'] ?: 'N/A') ?>
          </span>
          <span class="bar-value" style="color:var(--accent-light)"><?= $pos['cnt'] ?>
            <span class="bar-muted">• avg <?= $pos['avg_score'] ?>%</span>
          </span>
        </div>
        <div class="bar-track thin">
          <div class="bar-fill" style="width:<?= $w ?>%;background:var(--accent)"></div>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

</div>

<!-- ── Recent Test Submissions ────────────────────── -->
<?php if (!empty($testPerf)): ?>
<p class="an-section-title"><i class="fa-solid fa-list-check"></i> Recent Test Submissions</p>
<div class="an-table-card">
  <div class="an-table-header">
    <div class="chart-card-icon blue"><i class="fa-solid fa-list-check"></i></div>
    <div>
      <div class="chart-card-title">Recent Test Submissions</div>
      <div class="card-subtitle"><?= count($testPerf) ?> most recent submissions</div>
    </div>
  </div>
  <div class="table-container">
    <table>
      <thead>
        <tr>
          <th>Candidate</th>
          <th>Test</th>
          <th>Score</th>
          <th>Marks</th>
          <th>Submitted</th>
          <th>Result</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($testPerf as $tp):
          $tpct = round($tp['percentage']);
          $tc   = getScoreColor($tpct);
        ?>
        <tr>
          <td><strong><?= htmlspecialchars($tp['cname']) ?></strong></td>
          <td style="font-size:13px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= htmlspecialchars($tp['title']) ?>
          </td>
          <td><span class="badge badge-<?= $tc ?>"><?= $tpct ?>%</span></td>
          <td style="font-size:13px"><?= $tp['total_score'] ?>/<?= $tp['max_score'] ?></td>
          <td style="font-size:12px;color:var(--text-muted)">
            <?= $tp['submitted_at'] ? date('d M Y', strtotime($tp['submitted_at'])) : '—' ?>
          </td>
          <td>
            <span class="badge badge-<?= $tpct >= 40 ? 'green' : 'rose' ?>">
              <?= $tpct >= 40 ? 'Passed' : 'Failed' ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── ATS Top Scores ─────────────────────────────── -->
<?php if (!empty($atsTop)): ?>
<p class="an-section-title"><i class="fa-solid fa-file-magnifying-glass"></i> ATS Resume Scores</p>
<div class="an-table-card">
  <div class="an-table-header">
    <div class="chart-card-icon violet"><i class="fa-solid fa-file-magnifying-glass"></i></div>
    <div>
      <div class="chart-card-title">Top ATS Resume Scores</div>
      <div class="card-subtitle">Highest scoring resume scans</div>
    </div>
  </div>
  <div class="table-container">
    <table>
      <thead>
        <tr>
          <th>Candidate</th>
          <th>Position Applied</th>
          <th>ATS Score</th>
          <th>Scanned</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($atsTop as $at):
          $ac = getScoreColor($at['ats_score']);
        ?>
        <tr>
          <td><strong><?= htmlspecialchars($at['cname'] ?: $at['candidate_name_free']) ?></strong></td>
          <td style="font-size:13px"><?= htmlspecialchars($at['position_applied'] ?? '—') ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:10px">
              <span class="badge badge-<?= $ac ?>"><?= $at['ats_score'] ?>%</span>
              <div style="flex:1;height:6px;background:rgba(255,255,255,.07);border-radius:99px;overflow:hidden;min-width:60px;max-width:90px">
                <div style="height:100%;width:<?= $at['ats_score'] ?>%;background:var(--<?= $ac ?>);border-radius:99px"></div>
              </div>
            </div>
          </td>
          <td style="font-size:12px;color:var(--text-muted)">
            <?= date('d M Y', strtotime($at['scanned_at'])) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Candidate Test Time Analytics ─────────────── -->
<?php if (!empty($timeAnalytics)): ?>
<p class="an-section-title"><i class="fa-solid fa-gauge"></i> Test Time Analytics</p>
<div class="an-table-card">
  <div class="an-table-header">
    <div class="chart-card-icon amber"><i class="fa-solid fa-gauge"></i></div>
    <div>
      <div class="chart-card-title">Candidate Test Time Analytics</div>
      <div class="card-subtitle">Time taken vs. allowed per candidate</div>
    </div>
  </div>
  <div class="table-container">
    <table>
      <thead>
        <tr>
          <th>Candidate</th>
          <th>Test</th>
          <th>Score</th>
          <th>Time Taken</th>
          <th>Allowed</th>
          <th>Usage</th>
          <th>Marks</th>
          <th>Detail</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($timeAnalytics as $ta):
          $pct    = round($ta['percentage']);
          $sc     = getScoreColor($pct);
          $tUsage = $ta['duration_minutes'] > 0
                    ? round($ta['time_taken_mins'] / $ta['duration_minutes'] * 100)
                    : 0;
          $tColor = $tUsage <= 70 ? 'green' : ($tUsage <= 90 ? 'amber' : 'rose');
        ?>
        <tr>
          <td>
            <div class="fw-600"><?= htmlspecialchars($ta['name']) ?></div>
            <div class="td-muted"><?= htmlspecialchars($ta['position'] ?? '—') ?></div>
          </td>
          <td style="font-size:13px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= htmlspecialchars($ta['test_title']) ?>
          </td>
          <td><span class="badge badge-<?= $sc ?>"><?= $pct ?>%</span></td>
          <td>
            <span style="font-weight:700;color:var(--<?= $tColor ?>)"><?= $ta['time_taken_mins'] ?> min</span>
          </td>
          <td class="td-muted"><?= $ta['duration_minutes'] ?> min</td>
          <td>
            <div style="display:flex;align-items:center;gap:8px;min-width:100px">
              <div style="flex:1;height:6px;background:rgba(255,255,255,.07);border-radius:99px;overflow:hidden">
                <div style="height:100%;width:<?= min(100,$tUsage) ?>%;background:var(--<?= $tColor ?>);border-radius:99px"></div>
              </div>
              <span style="font-size:12px;font-weight:600;color:var(--<?= $tColor ?>);min-width:32px"><?= $tUsage ?>%</span>
            </div>
          </td>
          <td><?= $ta['total_score'] ?>/<?= $ta['max_score'] ?></td>
          <td>
            <a href="view_test_result.php?id=<?= $ta['test_id'] ?>" class="btn btn-xs btn-secondary">
              <i class="fa-solid fa-eye"></i> View
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php renderFooter(); ?>
