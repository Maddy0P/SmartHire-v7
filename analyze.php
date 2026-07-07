<?php
require_once 'includes/layout.php';
requireLogin();
requireRole('recruiter');
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

$result = null;
$analyzed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $skills   = trim($_POST['skills'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $resume   = trim($_POST['resume'] ?? '');

    if ($skills || $resume) {
        $score = calculateAIScore($skills, $position, $resume);
        $text  = strtolower($skills . ' ' . $resume);

        $techKeywords = ['python','java','javascript','react','node','php','laravel','vue',
                         'angular','docker','kubernetes','aws','azure','gcp','mysql','mongodb',
                         'postgresql','redis','terraform','ci/cd','spring','django','flask',
                         'tensorflow','pytorch','sql','typescript','graphql','rest','api'];
        $softKeywords = ['project','team','lead','leadership','experience','years','award',
                         'portfolio','certified','agile','scrum','mentor','manage','deliver'];

        $matchedTech = array_filter($techKeywords, fn($k) => str_contains($text, $k));
        $matchedSoft = array_filter($softKeywords, fn($k) => str_contains($text, $k));

        $level = $score >= 80 ? ['Excellent','green','fa-star','Strong candidate — recommend for interview']
               : ($score >= 65 ? ['Good','blue','fa-thumbs-up','Good candidate — worth scheduling']
               : ($score >= 50 ? ['Average','amber','fa-minus','Average profile — review carefully']
               : ['Weak','rose','fa-thumbs-down','Weak match — may not meet requirements']));

        $result = compact('score','matchedTech','matchedSoft','level','skills','position','resume');
        $analyzed = true;
    }
}

renderHead('AI Analyzer');
renderSidebar('analyze');
?>

<div class="page-header">
  <div class="page-header-left">
    <div class="breadcrumb"><a href="dashboard.php">Home</a> <i class="fa-solid fa-chevron-right"></i> AI Analyzer</div>
    <h1 class="page-title">AI Candidate Analyzer</h1>
    <p class="page-subtitle">Instantly score candidate profiles with smart keyword analysis</p>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start">

  <!-- Input Form -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title"><i class="fa-solid fa-robot" style="color:var(--accent)"></i> Analyze Candidate</div>
        <div class="card-subtitle">Enter skills and context for AI scoring</div>
      </div>
    </div>
    <div class="card-body">
      <form method="POST">
      <?= csrf_field() ?>
        <div class="form-group" style="margin-bottom:16px">
          <label class="form-label">Target Position</label>
          <input name="position" type="text" class="form-control"
                 placeholder="e.g. Full Stack Developer"
                 value="<?= htmlspecialchars($_POST['position'] ?? '') ?>">
        </div>
        <div class="form-group" style="margin-bottom:16px">
          <label class="form-label">Skills (comma-separated) *</label>
          <input name="skills" type="text" class="form-control"
                 placeholder="React, Node.js, Python, MySQL, Docker"
                 value="<?= htmlspecialchars($_POST['skills'] ?? '') ?>" required>
        </div>
        <div class="form-group" style="margin-bottom:20px">
          <label class="form-label">Resume Notes / Experience Summary</label>
          <textarea name="resume" class="form-control" rows="5"
                    placeholder="e.g. 3 years experience building React apps. Led a team of 5. Certified AWS Developer. Delivered 10+ projects on time."><?= htmlspecialchars($_POST['resume'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary w-100">
          <i class="fa-solid fa-bolt"></i> Run AI Analysis
        </button>
      </form>
    </div>
  </div>

  <!-- Result Panel -->
  <div>
    <?php if ($analyzed && $result): ?>
    <?php [$lvlLabel,$lvlColor,$lvlIcon,$lvlMsg] = $result['level']; ?>

    <div class="ai-result-card" style="margin-bottom:16px">
      <div class="ai-score-ring" data-pct="<?= $result['score'] ?>">
        <div class="ai-score-value"><?= $result['score'] ?></div>
      </div>
      <h3 style="font-size:20px;font-weight:800;margin-bottom:4px"><?= $lvlLabel ?> Match</h3>
      <p style="color:var(--<?= $lvlColor ?>);font-size:13px;font-weight:600;margin-bottom:4px">
        <i class="fa-solid <?= $lvlIcon ?>"></i> <?= $lvlMsg ?>
      </p>
      <p style="color:var(--text-muted);font-size:12px">Score out of 100 based on skills &amp; experience</p>
    </div>

    <!-- Score Breakdown -->
    <div class="card" style="margin-bottom:16px">
      <div class="card-header"><div class="card-title">Score Breakdown</div></div>
      <div class="card-body">
        <?php
        $breakdown = [
          ['Technical Skills', min(100, 40 + count($result['matchedTech']) * 4), 'score-high','fa-code'],
          ['Soft Skills',      min(100, 40 + count($result['matchedSoft']) * 5), 'score-medium','fa-users'],
          ['Overall Match',    $result['score'], $result['score']>=75?'score-high':($result['score']>=50?'score-medium':'score-low'),'fa-star'],
        ];
        foreach ($breakdown as [$label,$val,$cls,$icon]):
        ?>
        <div style="margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;margin-bottom:5px">
            <span style="font-size:13px;font-weight:500"><i class="fa-solid <?=$icon?>" style="margin-right:6px;color:var(--text-muted)"></i><?=$label?></span>
            <span class="<?=$cls?>" style="font-weight:700;font-size:13px"><?=$val?>%</span>
          </div>
          <div class="score-bar-track">
            <div class="score-bar-fill <?=$cls?>" data-pct="<?=$val?>" style="height:8px;border-radius:4px"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Matched Keywords -->
    <div class="card">
      <div class="card-header"><div class="card-title">Detected Keywords</div></div>
      <div class="card-body">
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
          <i class="fa-solid fa-microchip"></i> Technical Skills (<?= count($result['matchedTech']) ?> detected)
        </p>
        <div style="margin-bottom:14px">
          <?php foreach ($result['matchedTech'] as $kw): ?>
          <span class="skill-tag matched"><i class="fa-solid fa-check" style="font-size:10px"></i> <?= $kw ?></span>
          <?php endforeach; ?>
          <?php if(empty($result['matchedTech'])): ?>
          <span style="color:var(--text-muted);font-size:13px">None detected</span>
          <?php endif; ?>
        </div>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
          <i class="fa-solid fa-people-group"></i> Soft Skills / Experience (<?= count($result['matchedSoft']) ?> detected)
        </p>
        <div>
          <?php foreach ($result['matchedSoft'] as $kw): ?>
          <span class="skill-tag matched" style="background:rgba(139,92,246,.15);border-color:rgba(139,92,246,.3);color:var(--violet)"><?= $kw ?></span>
          <?php endforeach; ?>
          <?php if(empty($result['matchedSoft'])): ?>
          <span style="color:var(--text-muted);font-size:13px">None detected</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php else: ?>

    <div class="card" style="text-align:center;padding:60px 30px">
      <i class="fa-solid fa-robot" style="font-size:48px;color:var(--text-muted);display:block;margin-bottom:16px"></i>
      <h3 style="font-weight:700;margin-bottom:8px">Ready to Analyze</h3>
      <p style="color:var(--text-secondary);font-size:13.5px">
        Fill in the form on the left with the candidate's skills and experience,
        then click <strong>Run AI Analysis</strong> to get an instant score.
      </p>
    </div>

    <!-- How it works -->
    <div class="card" style="margin-top:16px">
      <div class="card-header"><div class="card-title">How It Works</div></div>
      <div class="card-body">
        <?php $steps = [
          ['fa-list-check','Input skills & resume summary','Paste the candidate\'s skills and a brief experience summary'],
          ['fa-magnifying-glass','Keyword analysis','AI scans for 30+ technical and soft-skill keywords'],
          ['fa-chart-simple','Score calculation','Weighted scoring based on keyword matches and depth'],
          ['fa-check-circle','Get recommendation','Instant hire/no-hire recommendation with reasoning'],
        ];
        foreach($steps as $i=>[$icon,$title,$desc]): ?>
        <div style="display:flex;gap:14px;margin-bottom:14px;align-items:flex-start">
          <div style="width:32px;height:32px;border-radius:8px;background:rgba(59,130,246,.15);color:var(--accent-light);display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0">
            <i class="fa-solid <?=$icon?>"></i>
          </div>
          <div>
            <div style="font-size:13px;font-weight:600"><?=$title?></div>
            <div style="font-size:12px;color:var(--text-muted)"><?=$desc?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php endif; ?>
  </div>
</div>

<?php renderFooter(); ?>
