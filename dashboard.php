<?php
require_once 'includes/layout.php';
requireLogin();

// ── All candidate aggregates in ONE query (was 5 separate queries) ───────────
$cStats = dbFetchOne("
    SELECT
        COUNT(*)                                              AS total_candidates,
        COUNT(*) FILTER (WHERE status = 'hired')              AS total_hired,
        COUNT(*) FILTER (WHERE status = 'pending')            AS total_pending,
        ROUND(AVG(ai_score) FILTER (WHERE ai_score > 0), 1)  AS avg_score
    FROM candidates
") ?? [];

$total_candidates = (int)($cStats['total_candidates'] ?? 0);
$total_hired      = (int)($cStats['total_hired']      ?? 0);
$total_pending    = (int)($cStats['total_pending']    ?? 0);
$avg_score        = $cStats['avg_score'] ?? 0;

// ── All interview aggregates in ONE query (was 2 separate queries) ────────────
$iStats = dbFetchOne("
    SELECT
        COUNT(*)                                          AS total_interviews,
        COUNT(*) FILTER (WHERE status = 'scheduled')     AS total_scheduled
    FROM interviews
") ?? [];

$total_interviews = (int)($iStats['total_interviews'] ?? 0);
$total_scheduled  = (int)($iStats['total_scheduled']  ?? 0);

// ── Recent candidates ────────────────────────────────────────────────────────
$recent = dbFetchAll("SELECT id, name, email, position, ai_score, status FROM candidates ORDER BY created_at DESC LIMIT 5");

// ── Upcoming interviews ──────────────────────────────────────────────────────
$upcoming = dbFetchAll("
    SELECT i.id, i.scheduled_date, i.scheduled_time, i.type,
           c.name AS candidate_name, c.position
    FROM interviews i
    JOIN candidates c ON c.id = i.candidate_id
    WHERE i.status = 'scheduled' AND i.scheduled_date >= CURRENT_DATE
    ORDER BY i.scheduled_date ASC, i.scheduled_time ASC
    LIMIT 5
");

// ── Pending tests (NOT IN → NOT EXISTS for index use) ────────────────────────
$pendingTests = dbFetchAll("
    SELECT ot.title, c.name AS cname
    FROM online_tests ot
    JOIN candidates c ON c.id = ot.candidate_id
    WHERE ot.status = 'active'
      AND NOT EXISTS (
          SELECT 1 FROM test_submissions ts
          WHERE ts.test_id = ot.id
            AND ts.status IN ('submitted', 'auto_submitted')
      )
    ORDER BY ot.created_at DESC
    LIMIT 3
");

// ── v8 presentation aggregates (read-only; Bible P12/P15 — funnel, deltas, sparkline) ──
$byStatus = [];
foreach (dbFetchAll("SELECT status, COUNT(*) AS n FROM candidates GROUP BY status") as $r) {
    $byStatus[$r['status']] = (int)$r['n'];
}
$delta = dbFetchOne("
    SELECT
        COUNT(*) FILTER (WHERE created_at >= CURRENT_DATE - INTERVAL '30 days') AS cur,
        COUNT(*) FILTER (WHERE created_at >= CURRENT_DATE - INTERVAL '60 days'
                           AND created_at <  CURRENT_DATE - INTERVAL '30 days') AS prev
    FROM candidates
") ?? ['cur' => 0, 'prev' => 0];
$spark = array_fill(0, 8, 0);
foreach (dbFetchAll("
    SELECT (CURRENT_DATE - created_at::date) / 7 AS wk, COUNT(*) AS n
    FROM candidates
    WHERE created_at >= CURRENT_DATE - INTERVAL '56 days'
    GROUP BY 1
") as $r) {
    $i = 7 - (int)$r['wk'];
    if ($i >= 0 && $i < 8) $spark[$i] = (int)$r['n'];
}


renderHead('Dashboard', true);
renderSidebar('dashboard');
$funnel = [
    ['Applied',     $total_candidates],
    ['Scheduled',   ($byStatus['scheduled'] ?? 0) + ($byStatus['interviewed'] ?? 0) + ($byStatus['hired'] ?? 0)],
    ['Interviewed', ($byStatus['interviewed'] ?? 0) + ($byStatus['hired'] ?? 0)],
    ['Hired',       $byStatus['hired'] ?? 0],
];
$fMax = max(1, $funnel[0][1]);
?>

<div class="sh-page-header">
  <div>
    <h1 class="sh-page-title">Dashboard</h1>
    <p class="sh-page-sub">Welcome back, <?= htmlspecialchars(currentUser()['name']) ?>. Here's what needs you today.</p>
  </div>
  <a href="candidates.php?action=new" class="sh-btn sh-btn-primary">
    <i class="fa-solid fa-plus" aria-hidden="true"></i> Add candidate
  </a>
</div>

<!-- KPI band — Bible P12 anatomy -->
<div class="sh-kpi-grid">
  <div class="sh-kpi">
    <div class="sh-kpi-top"><i class="fa-solid fa-users" aria-hidden="true"></i>Total candidates</div>
    <div class="sh-kpi-value"><?= $total_candidates ?> <?= sh_delta_chip((int)$delta['cur'], (int)$delta['prev']) ?></div>
    <div class="sh-kpi-foot">
      <span>vs. previous 30 days</span>
      <?= sh_sparkline($spark) ?>
    </div>
    <div class="sh-kpi-foot"><a href="candidates.php">View all →</a></div>
  </div>
  <div class="sh-kpi">
    <div class="sh-kpi-top"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i>Upcoming interviews</div>
    <div class="sh-kpi-value"><?= $total_scheduled ?></div>
    <div class="sh-kpi-foot"><span>scheduled of <?= $total_interviews ?> total</span><a href="interviews.php">Schedule →</a></div>
  </div>
  <div class="sh-kpi">
    <div class="sh-kpi-top"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>Awaiting review</div>
    <div class="sh-kpi-value"><?= $total_pending ?></div>
    <div class="sh-kpi-foot"><span>pending candidates</span><a href="candidates.php?status=pending">Review →</a></div>
  </div>
  <div class="sh-kpi">
    <div class="sh-kpi-top"><i class="fa-solid fa-gauge-high" aria-hidden="true"></i>Avg resume score <span class="sh-ai-chip" title="Computed by the SmartHire scoring engine"><i class="fa-solid fa-sparkles" aria-hidden="true"></i>AI</span></div>
    <div class="sh-kpi-value"><?= $avg_score ?: '—' ?><?= $avg_score ? '<span class="sh-kpi-unit">%</span>' : '' ?></div>
    <div class="sh-kpi-foot"><span>across scored candidates</span><a href="candidate_resumes.php">Scores →</a></div>
  </div>
</div>

<?php if (!empty($pendingTests)): ?>
<div class="sh-card sh-mb-6 sh-alert-warning">
  <div class="sh-flex sh-items-center sh-gap-3">
    <i class="fa-solid fa-laptop-code sh-warning-text" aria-hidden="true"></i>
    <div class="sh-flex-1">
      <strong class="sh-warning-text"><?= count($pendingTests) ?> active test<?= count($pendingTests) > 1 ? 's' : '' ?> awaiting submission</strong>
      <div class="sh-fs-xs sh-text-2"><?= implode(' · ', array_map(fn($t) => htmlspecialchars($t['cname']), $pendingTests)) ?></div>
    </div>
    <a href="online_tests.php" class="sh-btn sh-btn-secondary sh-btn-sm sh-shrink-0">View tests</a>
  </div>
</div>
<?php endif; ?>

<!-- Tier 2: funnel (page hero) + today's queue -->
<div class="sh-tier2">
  <section class="sh-card sh-card-hero" aria-labelledby="funnel-title">
    <div class="sh-card-header">
      <div>
        <h2 class="sh-card-title" id="funnel-title">Where do candidates stand?</h2>
        <p class="sh-card-sub">Recruitment funnel across all positions</p>
      </div>
    </div>
    <div class="sh-funnel">
      <?php foreach ($funnel as [$label, $n]): ?>
      <div class="sh-funnel-row">
        <span><?= $label ?></span>
        <div class="sh-funnel-track"><div class="sh-funnel-fill" style="width:<?= round($n / $fMax * 100, 1) ?>%"></div></div>
        <span class="sh-funnel-n"><?= $n ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if ($total_candidates === 0): ?>
    <div class="sh-empty">
      <div class="sh-empty-icon"><i class="fa-solid fa-users" aria-hidden="true"></i></div>
      <h3>No candidates yet</h3>
      <p>Your funnel fills as candidates apply or are added to the pipeline.</p>
      <a href="candidates.php?action=new" class="sh-btn sh-btn-primary sh-mt-2">Add your first candidate</a>
    </div>
    <?php endif; ?>
  </section>

  <section class="sh-card" aria-labelledby="today-title">
    <div class="sh-card-header">
      <div>
        <h2 class="sh-card-title" id="today-title">Upcoming interviews</h2>
        <p class="sh-card-sub">Next scheduled sessions</p>
      </div>
      <a href="interviews.php" class="sh-btn sh-btn-ghost sh-btn-sm">All</a>
    </div>
    <?php if (empty($upcoming)): ?>
    <div class="sh-empty">
      <div class="sh-empty-icon"><i class="fa-regular fa-calendar" aria-hidden="true"></i></div>
      <h3>Nothing scheduled</h3>
      <p>Interviews you schedule will appear here with their date and time.</p>
      <a href="interviews.php" class="sh-btn sh-btn-secondary sh-btn-sm sh-mt-2">Schedule an interview</a>
    </div>
    <?php else: ?>
    <?php foreach ($upcoming as $iv): ?>
    <div class="sh-listrow">
      <div class="sh-datebox">
        <div class="d"><?= date('d', strtotime($iv['scheduled_date'])) ?></div>
        <div class="m"><?= date('M', strtotime($iv['scheduled_date'])) ?></div>
      </div>
      <div class="sh-flex-1">
        <div class="sh-cell-main sh-truncate"><?= htmlspecialchars($iv['candidate_name']) ?></div>
        <div class="sh-cell-sub sh-truncate"><?= htmlspecialchars($iv['position']) ?></div>
      </div>
      <div class="sh-right">
        <span class="sh-badge sh-badge-info"><?= htmlspecialchars($iv['type']) ?></span>
        <div class="sh-cell-sub sh-tnum"><?= date('g:i A', strtotime($iv['scheduled_time'])) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </section>
</div>

<!-- Tier 3: recent candidates -->
<section class="sh-card sh-card-flush" aria-labelledby="recent-title">
  <div class="sh-card-header">
    <div>
      <h2 class="sh-card-title" id="recent-title">Recent candidates</h2>
      <p class="sh-card-sub">Latest entries in the pipeline</p>
    </div>
    <a href="candidates.php" class="sh-btn sh-btn-ghost sh-btn-sm">View all</a>
  </div>
  <?php if (empty($recent)): ?>
  <div class="sh-empty">
    <div class="sh-empty-icon"><i class="fa-solid fa-user-plus" aria-hidden="true"></i></div>
    <h3>No candidates yet</h3>
    <p>Candidates appear here as soon as they're added or apply.</p>
  </div>
  <?php else: ?>
  <div class="sh-table-wrap">
    <table class="sh-table">
      <thead>
        <tr><th scope="col">Candidate</th><th scope="col">Position</th><th scope="col">Score</th><th scope="col">Status</th></tr>
      </thead>
      <tbody>
        <?php foreach ($recent as $c): $score = (int)$c['ai_score'];
              $cls = $score >= 75 ? 'hi' : ($score >= 50 ? 'mid' : 'lo'); ?>
        <tr>
          <td data-th="Candidate">
            <div class="sh-flex sh-items-center sh-gap-3">
              <span class="sh-avatar" aria-hidden="true"><?= strtoupper(substr($c['name'], 0, 1)) ?></span>
              <div class="sh-flex-1">
                <div class="sh-cell-main sh-truncate"><?= htmlspecialchars($c['name']) ?></div>
                <div class="sh-cell-sub sh-truncate sh-mono"><?= htmlspecialchars($c['email']) ?></div>
              </div>
            </div>
          </td>
          <td data-th="Position"><?= htmlspecialchars($c['position']) ?></td>
          <td data-th="Score">
            <div class="sh-score">
              <div class="sh-score-track"><div class="sh-score-fill <?= $cls ?>" style="width:<?= $score ?>%"></div></div>
              <span class="sh-score-n"><?= $score ?>%</span>
            </div>
          </td>
          <td data-th="Status"><?= sh_status_badge($c['status']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<?php renderFooter(); ?>
