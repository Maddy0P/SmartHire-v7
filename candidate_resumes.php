<?php
// ─────────────────────────────────────────────────────────────────────────────
//  SmartHire — candidate_resumes.php
//  Full page: all ATS resume scans with stats, charts, and download buttons
// ─────────────────────────────────────────────────────────────────────────────
require_once 'includes/layout.php';
requireLogin();
requireRole('recruiter');
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

// ── Handle delete ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action']??'') === 'delete') {
    dbExecute("DELETE FROM resume_scans WHERE id=?", 'i', (int)$_POST['scan_id']);
    setFlash('success', 'Resume scan deleted.');
    header('Location: candidate_resumes.php'); exit;
}

// ── Fetch all scans ──────────────────────────────────────────
$filterTier = $_GET['tier'] ?? '';
$tierWhere  = '';
if ($filterTier === 'excellent')   $tierWhere = " WHERE rs.ats_score >= 80";
elseif ($filterTier === 'good')    $tierWhere = " WHERE rs.ats_score >= 65 AND rs.ats_score < 80";
elseif ($filterTier === 'average') $tierWhere = " WHERE rs.ats_score >= 50 AND rs.ats_score < 65";
elseif ($filterTier === 'poor')    $tierWhere = " WHERE rs.ats_score < 50";

$scans = dbFetchAll("
    SELECT rs.*, c.name AS cname, c.status AS cstatus
    FROM resume_scans rs
    LEFT JOIN candidates c ON c.id = rs.candidate_id
    $tierWhere
    ORDER BY rs.scanned_at DESC
");

// ── Stats ─────────────────────────────────────────────────────
$statsAll = dbFetchOne("
    SELECT COUNT(*) AS total,
           ROUND(AVG(ats_score),1) AS avg_score,
           MAX(ats_score) AS max_score,
           MIN(ats_score) AS min_score
    FROM resume_scans
");
$tierCounts = [
    'excellent' => dbFetchOne("SELECT COUNT(*) n FROM resume_scans WHERE ats_score>=80")['n'],
    'good'      => dbFetchOne("SELECT COUNT(*) n FROM resume_scans WHERE ats_score>=65 AND ats_score<80")['n'],
    'average'   => dbFetchOne("SELECT COUNT(*) n FROM resume_scans WHERE ats_score>=50 AND ats_score<65")['n'],
    'poor'      => dbFetchOne("SELECT COUNT(*) n FROM resume_scans WHERE ats_score<50")['n'],
];

// ── Chart data: score distribution in 10-point buckets ───────
$distData = dbFetchAll("
    SELECT FLOOR(ats_score/10)*10 AS bucket, COUNT(*) AS n
    FROM resume_scans
    GROUP BY bucket ORDER BY bucket
");
$distLabels = array_map(fn($r) => $r['bucket'].'-'.($r['bucket']+9), $distData);
$distValues = array_column($distData, 'n');

renderHead('Candidate Resume Scores');
renderSidebar('resume_scanner');
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<div class="page-header">
  <div class="page-header-left">
    <div class="breadcrumb">
      <a href="dashboard.php">Home</a>
      <i class="fa-solid fa-chevron-right"></i>
      <a href="resume_scanner.php">ATS Scanner</a>
      <i class="fa-solid fa-chevron-right"></i>
      All Resume Scores
    </div>
    <h1 class="page-title">Candidate Resume Scores</h1>
    <p class="page-subtitle"><?= $statsAll['total'] ?? 0 ?> resumes scanned · Avg ATS score: <?= $statsAll['avg_score'] ?? 0 ?>%</p>
  </div>
  <a href="resume_scanner.php" class="btn btn-primary">
    <i class="fa-solid fa-plus"></i> New ATS Scan
  </a>
</div>

<!-- ── Top Stats ──────────────────────────────────────────── -->
<div class="stats-grid" style="margin-bottom:20px">
  <div class="stat-card green">
    <div class="stat-icon green"><i class="fa-solid fa-circle-check"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $tierCounts['excellent'] ?></div>
      <div class="stat-label">Excellent (80–100%)</div>
    </div>
  </div>
  <div class="stat-card blue">
    <div class="stat-icon blue"><i class="fa-solid fa-thumbs-up"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $tierCounts['good'] ?></div>
      <div class="stat-label">Good (65–79%)</div>
    </div>
  </div>
  <div class="stat-card amber">
    <div class="stat-icon amber"><i class="fa-solid fa-triangle-exclamation"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $tierCounts['average'] ?></div>
      <div class="stat-label">Needs Work (50–64%)</div>
    </div>
  </div>
  <div class="stat-card rose">
    <div class="stat-icon rose"><i class="fa-solid fa-circle-xmark"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $tierCounts['poor'] ?></div>
      <div class="stat-label">Poor (&lt;50%)</div>
    </div>
  </div>
</div>

<!-- ── Charts Row ─────────────────────────────────────────── -->
<?php if (!empty($scans)): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:20px">

  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-chart-bar"></i> Score Distribution</div></div>
    <div class="card-body"><canvas id="distChart" height="180"></canvas></div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-chart-pie"></i> ATS Tier Breakdown</div></div>
    <div class="card-body" style="display:flex;align-items:center;gap:20px">
      <canvas id="tierDonut" style="max-width:150px;max-height:150px;flex-shrink:0"></canvas>
      <div>
        <?php
        $tiers = [
          ['Excellent', $tierCounts['excellent'], '#10b981'],
          ['Good',      $tierCounts['good'],      '#3b82f6'],
          ['Avg',       $tierCounts['average'],   '#f59e0b'],
          ['Poor',      $tierCounts['poor'],       '#f43f5e'],
        ];
        foreach ($tiers as [$lbl,$cnt,$clr]):
        ?>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:9px">
          <span style="width:10px;height:10px;border-radius:2px;background:<?=$clr?>;flex-shrink:0;display:block"></span>
          <span style="font-size:12.5px;color:var(--text-secondary)"><?=$lbl?></span>
          <span style="font-size:12px;font-weight:700;color:var(--text-primary);margin-left:auto"><?=$cnt?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</div>
<?php endif; ?>

<!-- ── Filter Tabs ────────────────────────────────────────── -->
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px">
  <?php
  $tabs = [
    [''          , 'All Scans',       $statsAll['total']??0,    'btn-secondary'],
    ['excellent' , 'Excellent',       $tierCounts['excellent'], 'btn-success'],
    ['good'      , 'Good',            $tierCounts['good'],      'btn-secondary'],
    ['average'   , 'Needs Work',      $tierCounts['average'],   'btn-secondary'],
    ['poor'      , 'Poor',            $tierCounts['poor'],      'btn-danger'],
  ];
  foreach ($tabs as [$val,$label,$cnt,$baseClass]):
    $active = $filterTier === $val ? 'btn-primary' : $baseClass;
  ?>
  <a href="candidate_resumes.php<?= $val ? "?tier=$val" : '' ?>" class="btn <?= $active ?> btn-sm">
    <?= $label ?> <span style="opacity:.75">(<?= $cnt ?>)</span>
  </a>
  <?php endforeach; ?>
</div>

<!-- ── Search ─────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:16px">
  <div class="card-body" style="padding:12px 16px">
    <div style="display:flex;gap:10px;align-items:center">
      <i class="fa-solid fa-search" style="color:var(--text-muted)"></i>
      <input type="text" id="tableSearch" class="form-control"
             placeholder="Search by name, position…"
             style="border:none;background:transparent;padding:0;flex:1">
    </div>
  </div>
</div>

<!-- ── Main Table ─────────────────────────────────────────── -->
<div class="card">
  <div class="table-container">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Candidate</th>
          <th>Position Applied</th>
          <th>ATS Score</th>
          <th>Contact</th>
          <th>Keywords</th>
          <th>Experience</th>
          <th>Scanned</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($scans)): ?>
        <tr>
          <td colspan="9" style="text-align:center;padding:50px;color:var(--text-muted)">
            <i class="fa-solid fa-file-circle-question" style="font-size:28px;display:block;margin-bottom:10px"></i>
            No resume scans found. <a href="resume_scanner.php" style="color:var(--accent-light)">Start scanning →</a>
          </td>
        </tr>
        <?php endif; ?>
        <?php foreach ($scans as $i => $scan):
          $sc  = (int)$scan['ats_score'];
          $cls = $sc>=75?'score-high':($sc>=50?'score-medium':'score-low');
          $name = $scan['cname'] ?? $scan['candidate_name_free'] ?? 'Anonymous';
          // Sub-score bar widths (out of their max)
          $cPct = $scan['contact_score']  > 0 ? round($scan['contact_score'] /15*100) : 0;
          $kPct = $scan['keyword_score']  > 0 ? round($scan['keyword_score'] /25*100) : 0;
          $ePct = $scan['experience_score']> 0 ? round($scan['experience_score']/20*100) : 0;
          $cCls = $cPct>=75?'score-high':($cPct>=40?'score-medium':'score-low');
          $kCls = $kPct>=75?'score-high':($kPct>=40?'score-medium':'score-low');
          $eCls = $ePct>=75?'score-high':($ePct>=40?'score-medium':'score-low');
        ?>
        <tr>
          <td class="td-muted"><?= $i + 1 ?></td>
          <td>
            <div class="d-flex align-center gap-2">
              <div class="avatar sm"><?= strtoupper(substr($name,0,1)) ?></div>
              <div>
                <div class="fw-600"><?= htmlspecialchars($name) ?></div>
                <?php if ($scan['cname'] && $scan['candidate_id']): ?>
                <div class="td-muted" style="font-size:11px">
                  <span class="badge-status badge-<?= $scan['cstatus']??'pending' ?>" style="font-size:10px;padding:2px 7px"><?= $scan['cstatus']??'' ?></span>
                </div>
                <?php else: ?>
                <div class="td-muted" style="font-size:11px">Standalone scan</div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td><?= htmlspecialchars($scan['position_applied'] ?: '—') ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:10px">
              <div style="position:relative;width:48px;height:48px;flex-shrink:0">
                <svg viewBox="0 0 36 36" width="48" height="48">
                  <circle cx="18" cy="18" r="15.9" fill="none" stroke="var(--bg-elevated)" stroke-width="3.5"/>
                  <circle cx="18" cy="18" r="15.9" fill="none"
                    stroke="<?= $sc>=75?'#10b981':($sc>=50?'#f59e0b':'#f43f5e') ?>"
                    stroke-width="3.5"
                    stroke-dasharray="<?= round($sc * 100 / 100) ?> 100"
                    stroke-linecap="round"
                    transform="rotate(-90 18 18)"/>
                </svg>
                <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:11px;font-weight:800;color:var(--text-primary)"><?= $sc ?></div>
              </div>
              <div>
                <?php
                $tier = $sc>=80?['Excellent','green']:($sc>=65?['Good','blue']:($sc>=50?['Avg','amber']:['Poor','rose']));
                ?>
                <div style="font-size:12px;font-weight:700;color:var(--<?=$tier[1]?>)"><?=$tier[0]?></div>
                <div class="td-muted" style="font-size:11px">ATS Score</div>
              </div>
            </div>
          </td>
          <td>
            <div class="score-bar <?=$cCls?>" style="min-width:70px">
              <div class="score-bar-track"><div class="score-bar-fill" data-pct="<?=$cPct?>"></div></div>
              <span class="score-text"><?=$scan['contact_score']?>/15</span>
            </div>
          </td>
          <td>
            <div class="score-bar <?=$kCls?>" style="min-width:70px">
              <div class="score-bar-track"><div class="score-bar-fill" data-pct="<?=$kPct?>"></div></div>
              <span class="score-text"><?=$scan['keyword_score']?>/25</span>
            </div>
          </td>
          <td>
            <div class="score-bar <?=$eCls?>" style="min-width:70px">
              <div class="score-bar-track"><div class="score-bar-fill" data-pct="<?=$ePct?>"></div></div>
              <span class="score-text"><?=$scan['experience_score']?>/20</span>
            </div>
          </td>
          <td>
            <div class="fw-600" style="font-size:12.5px"><?= date('d M Y', strtotime($scan['scanned_at'])) ?></div>
            <div class="td-muted"><?= date('g:i A', strtotime($scan['scanned_at'])) ?></div>
          </td>
          <td>
            <div class="d-flex gap-2">
              <!-- Download ATS PDF -->
              <a href="print_resume_scan.php?scan_id=<?= $scan['id'] ?>" target="_blank"
                 class="btn btn-secondary btn-sm btn-icon" title="Download ATS PDF Report"
                 style="color:var(--rose)">
                <i class="fa-solid fa-file-pdf"></i>
              </a>
              <!-- View Candidate Profile -->
              <?php if ($scan['candidate_id']): ?>
              <a href="candidate_detail.php?candidate_id=<?= $scan['candidate_id'] ?>"
                 class="btn btn-secondary btn-sm btn-icon" title="Full Candidate Report">
                <i class="fa-solid fa-eye"></i>
              </a>
              <?php endif; ?>
              <!-- Delete -->
              <form method="POST" style="display:inline">
      <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="delete">
                <input type="hidden" name="scan_id" value="<?= $scan['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm btn-icon"
                        data-confirm="Delete this resume scan permanently?" title="Delete">
                  <i class="fa-solid fa-trash"></i>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (!empty($scans)): ?>
<script>
// Score distribution bar chart
new Chart(document.getElementById('distChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($distLabels) ?>,
    datasets: [{
      label: 'Resumes',
      data: <?= json_encode($distValues) ?>,
      backgroundColor: 'rgba(59,130,246,.7)',
      borderRadius: 6,
      borderWidth: 0
    }]
  },
  options: {
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, grid: { color: 'rgba(148,163,184,.1)' }, ticks: { color: '#94a3b8', font: { size: 11 } } },
      x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 11 } } }
    }
  }
});

// Tier donut chart
new Chart(document.getElementById('tierDonut'), {
  type: 'doughnut',
  data: {
    labels: ['Excellent','Good','Average','Poor'],
    datasets: [{
      data: [<?=$tierCounts['excellent']?>,<?=$tierCounts['good']?>,<?=$tierCounts['average']?>,<?=$tierCounts['poor']?>],
      backgroundColor: ['#10b981','#3b82f6','#f59e0b','#f43f5e'],
      borderWidth: 0,
      cutout: '65%'
    }]
  },
  options: {
    plugins: { legend: { display: false }, tooltip: { callbacks: {
      label: ctx => ' ' + ctx.label + ': ' + ctx.parsed + ' scans'
    }}}
  }
});
</script>
<?php endif; ?>

<?php renderFooter(); ?>
