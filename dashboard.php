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

renderHead('Dashboard');
renderSidebar('dashboard');
?>

<!-- Page Header -->
<div class="page-header">
  <div class="page-header-left">
    <div class="breadcrumb"><i class="fa-solid fa-house"></i> Home</div>
    <h1 class="page-title">Dashboard</h1>
    <p class="page-subtitle">Welcome back, <?= htmlspecialchars(currentUser()['name']) ?>! Here's what's happening today.</p>
  </div>
  <a href="candidates.php?action=new" class="btn btn-primary">
    <i class="fa-solid fa-plus"></i> Add Candidate
  </a>
</div>

<!-- Stat Cards -->
<div class="stats-grid">
  <div class="stat-card blue">
    <div class="stat-icon blue"><i class="fa-solid fa-users"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $total_candidates ?></div>
      <div class="stat-label">Total Candidates</div>
      <div class="stat-delta up"><i class="fa-solid fa-arrow-up"></i> Active pipeline</div>
    </div>
  </div>
  <div class="stat-card amber">
    <div class="stat-icon amber"><i class="fa-solid fa-calendar-days"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $total_scheduled ?></div>
      <div class="stat-label">Upcoming Interviews</div>
      <div class="stat-delta up"><i class="fa-solid fa-calendar"></i> Scheduled</div>
    </div>
  </div>
  <div class="stat-card green">
    <div class="stat-icon green"><i class="fa-solid fa-handshake"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $total_hired ?></div>
      <div class="stat-label">Hired This Cycle</div>
      <div class="stat-delta up"><i class="fa-solid fa-arrow-up"></i> Offers made</div>
    </div>
  </div>
  <div class="stat-card violet">
    <div class="stat-icon violet"><i class="fa-solid fa-robot"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $avg_score ?>%</div>
      <div class="stat-label">Avg AI Score</div>
      <div class="stat-delta up"><i class="fa-solid fa-chart-line"></i> Across all</div>
    </div>
  </div>
  <div class="stat-card rose">
    <div class="stat-icon rose"><i class="fa-solid fa-hourglass-half"></i></div>
    <div class="stat-info">
      <div class="stat-value"><?= $total_pending ?></div>
      <div class="stat-label">Awaiting Review</div>
      <div class="stat-delta down"><i class="fa-solid fa-clock"></i> Needs action</div>
    </div>
  </div>
</div>

<!-- Quick Test Notifications -->
<?php if (!empty($pendingTests)): ?>
<div style="background:rgba(124,58,237,.08);border:1px solid rgba(124,58,237,.25);border-radius:12px;padding:14px 20px;margin-bottom:20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
  <div style="width:36px;height:36px;border-radius:8px;background:rgba(124,58,237,.2);display:flex;align-items:center;justify-content:center;color:#a78bfa;font-size:16px;flex-shrink:0">
    <i class="fa-solid fa-laptop-code"></i>
  </div>
  <div style="flex:1">
    <div style="font-size:13px;font-weight:700;color:#c4b5fd;margin-bottom:3px"><?= count($pendingTests) ?> active test(s) awaiting candidate submission</div>
    <div style="font-size:12px;color:var(--text-muted)"><?= implode(' · ', array_map(fn($t) => htmlspecialchars($t['cname']), $pendingTests)) ?></div>
  </div>
  <a href="online_tests.php" class="btn btn-secondary btn-sm" style="flex-shrink:0">View Tests →</a>
</div>
<?php endif; ?>

<!-- Main Grid -->
<div class="dashboard-grid">

  <!-- Recent Candidates -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Recent Candidates</div>
        <div class="card-subtitle">Latest entries in the pipeline</div>
      </div>
      <a href="candidates.php" class="btn btn-secondary btn-sm">View All</a>
    </div>
    <div class="table-container">
      <table>
        <thead>
          <tr>
            <th>Candidate</th>
            <th>Position</th>
            <th>AI Score</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $c): ?>
          <?php
            $score = (int)$c['ai_score'];
            $scoreClass = $score >= 75 ? 'score-high' : ($score >= 50 ? 'score-medium' : 'score-low');
          ?>
          <tr>
            <td>
              <div class="d-flex align-center gap-2">
                <div class="avatar sm"><?= strtoupper(substr($c['name'],0,1)) ?></div>
                <div>
                  <div class="fw-600"><?= htmlspecialchars($c['name']) ?></div>
                  <div class="td-muted"><?= htmlspecialchars($c['email']) ?></div>
                </div>
              </div>
            </td>
            <td><?= htmlspecialchars($c['position']) ?></td>
            <td>
              <div class="score-bar <?= $scoreClass ?>">
                <div class="score-bar-track">
                  <div class="score-bar-fill" data-pct="<?= $score ?>"></div>
                </div>
                <span class="score-text"><?= $score ?>%</span>
              </div>
            </td>
            <td><span class="badge-status badge-<?= $c['status'] ?>"><?= $c['status'] ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Upcoming Interviews -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Upcoming Interviews</div>
        <div class="card-subtitle">Next scheduled sessions</div>
      </div>
      <a href="interviews.php" class="btn btn-secondary btn-sm">All</a>
    </div>
    <div class="card-body">
      <?php if (empty($upcoming)): ?>
      <div class="empty-state">
        <i class="fa-regular fa-calendar-xmark"></i>
        <p>No upcoming interviews</p>
      </div>
      <?php else: ?>
      <?php foreach ($upcoming as $iv): ?>
      <div class="interview-item">
        <div class="interview-time-box">
          <div class="time-day"><?= date('d', strtotime($iv['scheduled_date'])) ?></div>
          <div class="time-month"><?= date('M', strtotime($iv['scheduled_date'])) ?></div>
        </div>
        <div style="flex:1;min-width:0">
          <div class="fw-600" style="font-size:13.5px"><?= htmlspecialchars($iv['candidate_name']) ?></div>
          <div class="td-muted"><?= htmlspecialchars($iv['position']) ?></div>
          <div style="margin-top:4px;display:flex;gap:6px;align-items:center">
            <span class="badge-status badge-scheduled"><?= $iv['type'] ?></span>
            <span class="text-muted text-sm"><?= date('g:i A', strtotime($iv['scheduled_time'])) ?></span>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php renderFooter(); ?>
