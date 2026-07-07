<?php
require_once 'includes/layout.php';
requireLogin();

$cid          = (int)($_GET['candidate_id'] ?? 0);
$interview_id = (int)($_GET['interview_id'] ?? 0);
if (!$cid) { header('Location: candidates.php'); exit; }

$candidate = dbFetchOne("SELECT * FROM candidates WHERE id=?", 'i', $cid);
if (!$candidate) { header('Location: candidates.php'); exit; }

// ── Fetch all interviews ──────────────────────────────────
$interviews = dbFetchAll("SELECT * FROM interviews WHERE candidate_id=? ORDER BY scheduled_date DESC", 'i', $cid);

// ── Main result ───────────────────────────────────────────
$result = dbFetchOne("
    SELECT r.*, i.type AS iv_type, i.scheduled_date, i.mode, i.interviewer
    FROM results r JOIN interviews i ON i.id=r.interview_id
    WHERE r.candidate_id=?
    ORDER BY r.created_at DESC LIMIT 1", 'i', $cid);

// ── Question responses ─────────────────────────────────────
$responses = dbFetchAll("
    SELECT cr.*, iq.question, iq.category, iq.max_score, iq.difficulty
    FROM candidate_responses cr
    JOIN interview_questions iq ON iq.id=cr.question_id
    WHERE cr.candidate_id=?
    ORDER BY iq.category, iq.difficulty", 'i', $cid);

// ── Latest ATS scan ────────────────────────────────────────
$atsScan = dbFetchOne("SELECT * FROM resume_scans WHERE candidate_id=? ORDER BY scanned_at DESC LIMIT 1", 'i', $cid);

// ── Overall computed score ─────────────────────────────────
$questionAvg = 0;
if (!empty($responses)) {
    $totalScored = array_sum(array_column($responses,'score_given'));
    $totalMax    = array_sum(array_column($responses,'max_score'));
    $questionAvg = $totalMax > 0 ? round($totalScored / $totalMax * 100) : 0;
}
$aiScore  = (int)$candidate['ai_score'];
$atsScore = $atsScan ? (int)$atsScan['ats_score'] : 0;
$ivScore  = $result  ? (int)$result['overall_score'] : 0;

// Final composite score
$weights = ['ai'=>0.2,'ats'=>0.25,'interview'=>0.35,'questions'=>0.2];
$finalScore = 0;
if ($ivScore)     $finalScore += $ivScore * $weights['interview'];
if ($aiScore)     $finalScore += $aiScore * $weights['ai'];
if ($atsScore)    $finalScore += $atsScore * $weights['ats'];
if ($questionAvg) $finalScore += $questionAvg * $weights['questions'];
$finalScore = (int)round($finalScore);
if (!$ivScore && !$atsScore && !$questionAvg) $finalScore = $aiScore;

$recMap = ['strong_yes'=>['✅ Strong Hire','green'],'yes'=>['👍 Recommend Hire','blue'],
           'maybe'=>['🤔 Borderline','amber'],'no'=>['❌ Do Not Hire','rose']];
[$recLabel,$recColor] = $recMap[$result['recommendation'] ?? ''] ?? ['—',''];

$catColors = ['technical'=>'blue','hr'=>'violet','behavioral'=>'amber','system_design'=>'rose','coding'=>'green'];

renderHead('Candidate Detail — '.$candidate['name']);
renderSidebar('results');
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<div class="page-header">
  <div class="page-header-left">
    <div class="breadcrumb"><a href="dashboard.php">Home</a> <i class="fa-solid fa-chevron-right"></i> <a href="results.php">Results</a> <i class="fa-solid fa-chevron-right"></i> <?=htmlspecialchars($candidate['name'])?></div>
    <h1 class="page-title">Candidate Detail Report</h1>
    <p class="page-subtitle">Complete analysis for <?=htmlspecialchars($candidate['name'])?></p>
  </div>
  <div class="d-flex gap-2">
    <a href="results.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
    <a href="print_result.php?candidate_id=<?= $cid ?>" target="_blank" class="btn btn-secondary" id="printBtn">
      <i class="fa-solid fa-print"></i> Print / PDF
    </a>
    <button onclick="downloadPDF()" class="btn btn-primary" id="pdfBtn">
      <i class="fa-solid fa-file-pdf"></i> Download PDF
    </button>
  </div>
</div>

<div id="reportContent">

<!-- ── Hero: Candidate Profile + Composite Score ─────────── -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:20px">

  <div class="card">
    <div class="card-body" style="display:flex;gap:18px;align-items:flex-start">
      <div class="avatar lg"><?=strtoupper(substr($candidate['name'],0,1))?></div>
      <div style="flex:1">
        <h2 style="font-size:20px;font-weight:800;margin-bottom:2px"><?=htmlspecialchars($candidate['name'])?></h2>
        <div style="color:var(--accent-light);font-weight:600;margin-bottom:8px"><?=htmlspecialchars($candidate['position'])?></div>
        <div style="display:flex;flex-wrap:wrap;gap:10px;font-size:13px;color:var(--text-secondary)">
          <span><i class="fa-solid fa-envelope" style="color:var(--text-muted)"></i> <?=htmlspecialchars($candidate['email'])?></span>
          <?php if($candidate['phone']): ?><span><i class="fa-solid fa-phone" style="color:var(--text-muted)"></i> <?=htmlspecialchars($candidate['phone'])?></span><?php endif; ?>
        </div>
        <?php if($candidate['skills']): ?>
        <div style="margin-top:12px;display:flex;flex-wrap:wrap;gap:5px">
          <?php foreach(explode(',',$candidate['skills']) as $sk): ?>
          <span class="skill-tag matched"><?=htmlspecialchars(trim($sk))?></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <span class="badge-status badge-<?=$candidate['status']?>" style="flex-shrink:0"><?=$candidate['status']?></span>
    </div>
  </div>

  <div class="card" style="text-align:center;background:linear-gradient(135deg,rgba(59,130,246,.1),rgba(139,92,246,.1));border-color:rgba(59,130,246,.25)">
    <div class="card-body">
      <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);margin-bottom:10px">Composite Score</div>
      <div style="font-size:52px;font-weight:900;line-height:1;color:var(--<?=$finalScore>=75?'emerald':($finalScore>=55?'accent-light':($finalScore>=40?'amber':'rose'))?>);margin-bottom:6px"><?=$finalScore?></div>
      <div style="font-size:13px;color:var(--text-muted);margin-bottom:12px">out of 100</div>
      <?php if ($result && $result['recommendation']): ?>
      <div style="font-size:14px;font-weight:700;color:var(--<?=$recColor?>)"><?=$recLabel?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── Score Cards Row ────────────────────────────────────── -->
<div class="stats-grid" style="margin-bottom:20px">
  <?php
  $scorecards=[
    ['AI Profile Score',$aiScore,'fa-robot','blue','Profile keyword analysis'],
    ['ATS Resume Score',$atsScore,'fa-file-magnifying-glass','violet','Resume ATS compatibility'],
    ['Interview Score',$ivScore,'fa-comments','amber','Panel evaluation'],
    ['Question Score',$questionAvg,'fa-circle-question','green','Per-question performance'],
  ];
  foreach($scorecards as [$label,$val,$icon,$color,$sub]):
    $cls=$val>=75?'score-high':($val>=50?'score-medium':'score-low');
  ?>
  <div class="stat-card <?=$color?>">
    <div class="stat-icon <?=$color?>"><i class="fa-solid <?=$icon?>"></i></div>
    <div class="stat-info" style="width:100%">
      <div style="display:flex;justify-content:space-between;align-items:flex-start">
        <div>
          <div class="stat-value <?=$cls?>"><?=$val?>%</div>
          <div class="stat-label"><?=$label?></div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:2px"><?=$sub?></div>
        </div>
      </div>
      <div class="score-bar-track" style="margin-top:8px;height:5px">
        <div class="score-bar-fill <?=$cls?>" data-pct="<?=$val?>" style="height:5px;border-radius:3px"></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Charts Row ─────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">

  <!-- Radar Chart -->
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-chart-area"></i> Skills Radar</div></div>
    <div class="card-body" style="display:flex;align-items:center;justify-content:center;min-height:240px">
      <canvas id="radarChart" style="max-width:280px;max-height:280px"></canvas>
    </div>
  </div>

  <!-- Score Bar Chart -->
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-chart-bar"></i> Score Comparison</div></div>
    <div class="card-body">
      <canvas id="barChart" height="220"></canvas>
    </div>
  </div>
</div>

<!-- ── Question-by-Question Scores ────────────────────────── -->
<?php if (!empty($responses)): ?>
<div class="card" style="margin-bottom:20px">
  <div class="card-header">
    <div>
      <div class="card-title"><i class="fa-solid fa-circle-question"></i> Interview Question Scores</div>
      <div class="card-subtitle">Scored: <?=count($responses)?> questions · Total: <?=array_sum(array_column($responses,'score_given'))?>/<?=array_sum(array_column($responses,'max_score'))?> pts</div>
    </div>
    <?php $qColor = $questionAvg>=75 ? "emerald" : ($questionAvg>=50 ? "amber" : "rose"); ?>
    <div style="font-size:22px;font-weight:800;color:var(--<?= $qColor ?>)"><?= $questionAvg ?>%</div>
  </div>
  <div class="table-container">
    <table>
      <thead>
        <tr><th>#</th><th style="min-width:300px">Question</th><th>Category</th><th>Difficulty</th><th>Score</th><th>Note</th></tr>
      </thead>
      <tbody>
        <?php foreach($responses as $i=>$r):
          $pct = $r['max_score']>0?round($r['score_given']/$r['max_score']*100):0;
          $cls=$pct>=75?'score-high':($pct>=50?'score-medium':'score-low');
          $dc=['easy'=>'emerald','medium'=>'amber','hard'=>'rose'];
        ?>
        <tr>
          <td class="td-muted"><?=$i+1?></td>
          <td style="font-size:13px"><?=htmlspecialchars(substr($r['question'],0,80))?>…</td>
          <td><span style="font-size:11px;font-weight:600;text-transform:capitalize;color:var(--accent-light)"><?=str_replace('_',' ',$r['category'])?></span></td>
          <td><span style="font-size:11px;color:var(--<?=$dc[$r['difficulty']]??'amber'?>)"><?=$r['difficulty']?></span></td>
          <td>
            <div class="score-bar <?=$cls?>" style="min-width:90px">
              <div class="score-bar-track"><div class="score-bar-fill" data-pct="<?=$pct?>"></div></div>
              <span class="score-text"><?=$r['score_given']?>/<?=$r['max_score']?></span>
            </div>
          </td>
          <td class="td-muted" style="font-size:12px"><?=htmlspecialchars($r['interviewer_note']??'—')?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Online Test Results ─────────────────────────────────── -->
<?php
// Fetch online test submissions for this candidate
$testSubs = dbFetchAll("
    SELECT ts.*, ot.title AS test_title, ot.duration_minutes, ot.passing_marks, ot.total_marks AS test_total
    FROM test_submissions ts JOIN online_tests ot ON ot.id=ts.test_id
    WHERE ts.candidate_id=? AND ts.status IN ('submitted','auto_submitted')
    ORDER BY ts.submitted_at DESC", 'i', $cid);
$testAvg = !empty($testSubs) ? round(array_sum(array_column($testSubs,'percentage'))/count($testSubs)) : 0;
?>
<?php if (!empty($testSubs)): ?>
<div class="card" style="margin-bottom:20px">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
    <div>
      <div class="card-title"><i class="fa-solid fa-laptop-code" style="color:var(--violet)"></i> Online Test Results</div>
      <div class="card-subtitle"><?= count($testSubs) ?> test(s) completed · Avg: <?= $testAvg ?>%</div>
    </div>
    <div style="font-size:24px;font-weight:900;color:var(--<?= $testAvg>=75?'emerald':($testAvg>=50?'accent-light':'rose') ?>)"><?= $testAvg ?>%</div>
  </div>
  <?php foreach ($testSubs as $sub):
    $pct = round($sub['percentage'] ?? 0);
    $pass = $pct >= ($sub['passing_marks'] ?? 40);
    $sc = $pct>=75?'score-high':($pct>=50?'score-medium':'score-low');
    $tTime = (int)($sub['time_taken_mins'] ?? 0);
    $efficiency = $sub['duration_minutes'] > 0 ? round($tTime/$sub['duration_minutes']*100) : 0;
    // Fetch per-question time analytics
    $qAnalytics = dbFetchAll("SELECT ta.time_spent_secs, ta.is_correct, iq.question_type
        FROM test_answers ta JOIN interview_questions iq ON iq.id=ta.question_id
        WHERE ta.submission_id=?", 'i', $sub['id']);
    $totalTimeSecs = array_sum(array_column($qAnalytics,'time_spent_secs'));
    $correctMCQ = count(array_filter($qAnalytics, fn($a)=>$a['is_correct']==1));
    $totalMCQ   = count(array_filter($qAnalytics, fn($a)=>$a['question_type']==='mcq'));
    $avgPerQ    = count($qAnalytics)>0 ? round($totalTimeSecs/count($qAnalytics)) : 0;
  ?>
  <div style="padding:16px 20px;border-bottom:1px solid var(--border)">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:12px;flex-wrap:wrap">
      <div>
        <div style="font-size:14px;font-weight:700;color:var(--text-primary);margin-bottom:4px"><?= htmlspecialchars($sub['test_title']) ?></div>
        <div style="font-size:12px;color:var(--text-muted)">
          Submitted: <?= $sub['submitted_at']?date('d M Y, g:i A',strtotime($sub['submitted_at'])):'—' ?>
        </div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <span class="badge-status badge-<?= $pass?'hired':'rejected' ?>"><?= $pass?'PASSED':'FAILED' ?></span>
        <span class="<?= $sc ?>" style="font-size:22px;font-weight:900"><?= $pct ?>%</span>
      </div>
    </div>
    <!-- Score bar -->
    <div class="score-bar <?= $sc ?>" style="margin-bottom:14px">
      <div class="score-bar-track" style="flex:1;height:8px"><div class="score-bar-fill" data-pct="<?= $pct ?>" style="height:8px;border-radius:4px"></div></div>
      <span class="score-text"><?= $sub['total_score'] ?>/<?= $sub['max_score'] ?> marks</span>
    </div>
    <!-- Time analytics row -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px">
      <?php foreach ([
        ['fa-clock','Time Taken', $tTime.' min', $tTime<=$sub['duration_minutes']*0.8?'emerald':'amber'],
        ['fa-gauge','Efficiency', $efficiency.'%', $efficiency<=80?'green':'amber'],
        ['fa-circle-check','MCQ Correct', $correctMCQ.'/'.$totalMCQ,'blue'],
        ['fa-stopwatch','Avg/Question', $avgPerQ.'s','violet'],
      ] as [$icon,$lbl,$val,$col]):
      ?>
      <div style="background:var(--bg-elevated);border-radius:10px;padding:10px;text-align:center">
        <div style="font-size:16px;font-weight:800;color:var(--<?= $col ?>)"><?= $val ?></div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:2px"><i class="fa-solid <?= $icon ?>" style="font-size:10px"></i> <?= $lbl ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:10px;text-align:right">
      <a href="view_test_result.php?id=<?= $sub['test_id'] ?>" class="btn btn-secondary btn-sm">
        <i class="fa-solid fa-eye"></i> Full Result & HR Grading
      </a>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── ATS Resume Score ────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">
  <div class="card">
    <div class="card-header">
      <div><div class="card-title"><i class="fa-solid fa-file-magnifying-glass"></i> ATS Resume Analysis</div></div>
      <?php if($atsScan): ?>
      <a href="resume_scanner.php" class="btn btn-secondary btn-sm">Re-Scan</a>
      <?php else: ?>
      <a href="resume_scanner.php?candidate_id=<?=$cid?>" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> Scan Resume</a>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <?php if ($atsScan):
        $atsMetrics=[
          ['Contact Info',$atsScan['contact_score'],15],
          ['Doc Format',$atsScan['format_score'],15],
          ['Keywords',$atsScan['keyword_score'],25],
          ['Experience',$atsScan['experience_score'],20],
          ['Education',$atsScan['education_score'],10],
          ['Achievements',$atsScan['action_verb_score'],10],
        ];
        foreach($atsMetrics as [$l,$v,$m]):
          $p=$m>0?round($v/$m*100):0;
          $c=$p>=75?'score-high':($p>=40?'score-medium':'score-low');
        ?>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
          <span style="font-size:12px;min-width:90px;color:var(--text-secondary)"><?=$l?></span>
          <div class="score-bar-track" style="flex:1;height:7px">
            <div class="score-bar-fill <?=$c?>" data-pct="<?=$p?>" style="height:7px;border-radius:4px"></div>
          </div>
          <span style="font-size:12px;font-weight:700;min-width:40px;text-align:right"><?=$v?>/<?=$m?></span>
        </div>
        <?php endforeach; ?>
        <canvas id="atsRadar" style="margin-top:14px;max-height:180px"></canvas>
      <?php else: ?>
      <div class="empty-state" style="padding:30px 20px">
        <i class="fa-solid fa-file-circle-question"></i>
        <p>No resume scan yet.<br><a href="resume_scanner.php" style="color:var(--accent-light)">Run ATS Scan →</a></p>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Interview Result Details -->
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-comments"></i> Panel Evaluation</div></div>
    <div class="card-body">
      <?php if($result):
        $evalMetrics=[
          ['Technical Skill',$result['technical_score'],'fa-code'],
          ['Communication',$result['communication'],'fa-comments'],
          ['Problem Solving',$result['problem_solving'],'fa-brain'],
          ['Cultural Fit',$result['cultural_fit'],'fa-heart'],
        ];
        foreach($evalMetrics as [$l,$v,$icon]):
          $cls=$v>=75?'score-high':($v>=50?'score-medium':'score-low');
        ?>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
          <i class="fa-solid <?=$icon?>" style="color:var(--text-muted);width:16px"></i>
          <span style="font-size:12.5px;flex:1"><?=$l?></span>
          <div class="score-bar-track" style="width:80px;height:6px">
            <div class="score-bar-fill <?=$cls?>" data-pct="<?=$v?>" style="height:6px;border-radius:3px"></div>
          </div>
          <span class="<?=$cls?>" style="font-weight:700;font-size:12px;min-width:30px;text-align:right"><?=$v?>%</span>
        </div>
        <?php endforeach; ?>
        <?php if($result['feedback']): ?>
        <div style="margin-top:14px;padding:12px;background:var(--bg-elevated);border-radius:8px;font-size:13px;color:var(--text-secondary);border-left:3px solid var(--accent)">
          <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px">Feedback</div>
          <?=htmlspecialchars($result['feedback'])?>
        </div>
        <?php endif; ?>
      <?php else: ?>
      <div class="empty-state" style="padding:30px 20px">
        <i class="fa-solid fa-clipboard-question"></i>
        <p>No panel evaluation recorded yet</p>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── Interview History ──────────────────────────────────── -->
<?php if (!empty($interviews)): ?>
<div class="card" style="margin-bottom:20px">
  <div class="card-header">
    <div class="card-title"><i class="fa-solid fa-calendar-check"></i> Interview History</div>
    <a href="score_interview.php?interview_id=<?=($interviews[0]['id'])?>" class="btn btn-secondary btn-sm">
      <i class="fa-solid fa-star"></i> Score Latest
    </a>
  </div>
  <div class="table-container">
    <table>
      <thead>
        <tr><th>Date</th><th>Type</th><th>Mode</th><th>Interviewer</th><th>Status</th><th>Notes</th><th>Action</th></tr>
      </thead>
      <tbody>
        <?php foreach($interviews as $iv): ?>
        <tr>
          <td><div class="fw-600"><?=date('d M Y',strtotime($iv['scheduled_date']))?></div>
              <div class="td-muted"><?=date('g:i A',strtotime($iv['scheduled_time']))?></div></td>
          <td><span class="badge-status badge-scheduled" style="text-transform:capitalize"><?=$iv['type']?></span></td>
          <td class="td-muted" style="text-transform:capitalize"><?=$iv['mode']?></td>
          <td><?=htmlspecialchars($iv['interviewer']??'—')?></td>
          <td><span class="badge-status badge-<?=$iv['status']?>"><?=$iv['status']?></span></td>
          <td class="td-muted" style="font-size:12px"><?=htmlspecialchars(substr($iv['notes']??'—',0,50))?></td>
          <td>
            <a href="score_interview.php?interview_id=<?=$iv['id']?>" class="btn btn-secondary btn-sm">
              <i class="fa-solid fa-star"></i> Score
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

</div><!-- end #reportContent -->

<!-- PDF Download print trigger -->
<a href="print_result.php?candidate_id=<?=$cid?>" target="_blank" class="btn btn-secondary" style="margin-top:8px">
  <i class="fa-solid fa-print"></i> Print / Save as PDF
</a>

<script>
// ── Radar Chart ───────────────────────────────────────────
new Chart(document.getElementById('radarChart'), {
  type: 'radar',
  data: {
    labels: ['AI Score','ATS Score','Technical','Communication','Problem Solving','Questions'],
    datasets: [{
      label: '<?=htmlspecialchars(addslashes($candidate['name']))?>',
      data: [<?=$aiScore?>,<?=$atsScore?>,<?=$result['technical_score']??0?>,<?=$result['communication']??0?>,<?=$result['problem_solving']??0?>,<?=$questionAvg?>],
      backgroundColor: 'rgba(59,130,246,.15)',
      borderColor: '#3b82f6',
      pointBackgroundColor: '#3b82f6',
      borderWidth: 2,
      pointRadius: 4
    }]
  },
  options: {
    scales: { r: { beginAtZero:true, max:100, grid:{color:'rgba(148,163,184,.15)'}, pointLabels:{color:'#94a3b8',font:{size:11}}, ticks:{display:false} }},
    plugins: { legend:{display:false}}
  }
});

// ── Comparison Bar Chart ──────────────────────────────────
new Chart(document.getElementById('barChart'), {
  type: 'bar',
  data: {
    labels: ['AI Profile','ATS Resume','Interview','Questions','Composite'],
    datasets: [{
      data: [<?=$aiScore?>,<?=$atsScore?>,<?=$ivScore?>,<?=$questionAvg?>,<?=$finalScore?>],
      backgroundColor: ['rgba(59,130,246,.7)','rgba(139,92,246,.7)','rgba(245,158,11,.7)','rgba(16,185,129,.7)','rgba(99,102,241,.9)'],
      borderRadius: 8, borderWidth: 0
    }]
  },
  options: {
    plugins: { legend:{display:false}},
    scales: {
      y:{ beginAtZero:true, max:100, grid:{color:'rgba(148,163,184,.1)'}, ticks:{color:'#94a3b8',font:{size:11}} },
      x:{ grid:{display:false}, ticks:{color:'#94a3b8',font:{size:11}} }
    }
  }
});

<?php if ($atsScan): ?>
// ATS Radar
new Chart(document.getElementById('atsRadar'), {
  type:'radar',
  data:{
    labels:['Contact','Format','Keywords','Experience','Education','Achievements'],
    datasets:[{
      data:[<?=$atsScan['contact_score']?>,<?=$atsScan['format_score']?>,<?=$atsScan['keyword_score']?>,<?=$atsScan['experience_score']?>,<?=$atsScan['education_score']?>,<?=$atsScan['action_verb_score']?>],
      backgroundColor:'rgba(139,92,246,.15)',
      borderColor:'#8b5cf6',
      pointBackgroundColor:'#8b5cf6',
      borderWidth:2, pointRadius:3
    }]
  },
  options:{
    scales:{r:{beginAtZero:true,max:25,grid:{color:'rgba(148,163,184,.1)'},pointLabels:{color:'#94a3b8',font:{size:10}},ticks:{display:false}}},
    plugins:{legend:{display:false}}
  }
});
<?php endif; ?>

// ── PDF Download via html2canvas + jsPDF ──────────────────
async function downloadPDF() {
  const btn = document.getElementById('pdfBtn');
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating…';
  btn.disabled = true;
  try {
    const { jsPDF } = window.jspdf;
    const content = document.getElementById('reportContent');
    const canvas  = await html2canvas(content, { scale:1.5, backgroundColor:'#0d1829', useCORS:true, logging:false });
    const imgData = canvas.toDataURL('image/png');
    const pdf     = new jsPDF({ orientation:'portrait', unit:'mm', format:'a4' });
    const pdfW    = pdf.internal.pageSize.getWidth();
    const pdfH    = pdf.internal.pageSize.getHeight();
    const imgH    = (canvas.height * pdfW) / canvas.width;
    let pos = 0;
    pdf.addImage(imgData,'PNG',0,pos,pdfW,imgH);
    let remaining = imgH - pdfH;
    while (remaining > 0) { pdf.addPage(); pos -= pdfH; pdf.addImage(imgData,'PNG',0,pos,pdfW,imgH); remaining -= pdfH; }
    pdf.save('SmartHire_<?=preg_replace('/[^a-z0-9]/i','_',$candidate['name'])?>_Report.pdf');
  } catch(e) { alert('PDF generation failed. Try Print/Save instead.'); }
  btn.innerHTML = '<i class="fa-solid fa-file-pdf"></i> Download PDF Report';
  btn.disabled  = false;
}
</script>

<?php renderFooter(); ?>
