<?php
require_once 'includes/config.php';
requireLogin();
$cid = (int)($_GET['candidate_id'] ?? 0);
if (!$cid) die('Invalid request.');

$candidate = dbFetchOne("SELECT * FROM candidates WHERE id=?", 'i', $cid);
if (!$candidate) die('Candidate not found.');

$result = dbFetchOne("SELECT r.*,i.type AS iv_type,i.scheduled_date,i.interviewer FROM results r JOIN interviews i ON i.id=r.interview_id WHERE r.candidate_id=? ORDER BY r.created_at DESC LIMIT 1", 'i', $cid);
$responses = dbFetchAll("SELECT cr.*,iq.question,iq.category,iq.max_score,iq.difficulty FROM candidate_responses cr JOIN interview_questions iq ON iq.id=cr.question_id WHERE cr.candidate_id=? ORDER BY iq.category", 'i', $cid);
$atsScan = dbFetchOne("SELECT * FROM resume_scans WHERE candidate_id=? ORDER BY scanned_at DESC LIMIT 1", 'i', $cid);
$interviews = dbFetchAll("SELECT * FROM interviews WHERE candidate_id=? ORDER BY scheduled_date DESC", 'i', $cid);

$testSubs = dbFetchAll("SELECT ts.*,ot.title AS test_title,ot.passing_marks FROM test_submissions ts JOIN online_tests ot ON ot.id=ts.test_id WHERE ts.candidate_id=? AND ts.status IN ('submitted','auto_submitted') ORDER BY ts.submitted_at DESC", 'i', $cid);
$testAvg  = !empty($testSubs) ? round(array_sum(array_column($testSubs,'percentage'))/count($testSubs)) : 0;
$aiScore  = (int)$candidate['ai_score'];
$atsScore = $atsScan ? (int)$atsScan['ats_score'] : 0;
$ivScore  = $result  ? (int)$result['overall_score'] : 0;
$qAvg     = !empty($responses) ? round(array_sum(array_column($responses,'score_given'))/array_sum(array_column($responses,'max_score'))*100) : 0;
// Composite: weight test avg in if available
$weights  = $testAvg > 0
    ? ['ai'=>0.15,'ats'=>0.2,'interview'=>0.3,'questions'=>0.15,'tests'=>0.2]
    : ['ai'=>0.2,'ats'=>0.25,'interview'=>0.35,'questions'=>0.2,'tests'=>0];
$finalScore = (int)round(
    $aiScore   * $weights['ai'] +
    $atsScore  * $weights['ats'] +
    $ivScore   * $weights['interview'] +
    $qAvg      * $weights['questions'] +
    $testAvg   * $weights['tests']
);
if (!$ivScore && !$atsScore && !$qAvg && !$testAvg) $finalScore = $aiScore;
$recMap=['strong_yes'=>'✅ Strong Hire','yes'=>'👍 Recommend','maybe'=>'🤔 Borderline','no'=>'❌ Do Not Hire'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>SmartHire Report — <?=htmlspecialchars($candidate['name'])?></title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Inter',sans-serif;background:#fff;color:#111;font-size:12px;line-height:1.6;padding:0}
  .page{max-width:800px;margin:0 auto;padding:32px}
  .header{background:linear-gradient(135deg,#0d1829,#1a3250);color:#fff;padding:28px 32px;border-radius:12px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center}
  .brand{font-size:22px;font-weight:800;color:#60a5fa}
  .brand span{color:#a78bfa;font-size:12px;display:block;font-weight:400}
  .candidate-name{font-size:20px;font-weight:800;color:#fff}
  .candidate-sub{color:#94a3b8;font-size:13px}
  .score-big{font-size:40px;font-weight:900;color:#10b981;text-align:right;line-height:1}
  .score-label{font-size:11px;color:#94a3b8;text-align:right}
  .section{margin-bottom:22px}
  .section-title{font-size:13px;font-weight:700;color:#1e293b;text-transform:uppercase;letter-spacing:.6px;border-bottom:2px solid #e2e8f0;padding-bottom:6px;margin-bottom:14px}
  .grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
  .score-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;text-align:center}
  .score-card .val{font-size:26px;font-weight:800;color:#1e293b}
  .score-card .lbl{font-size:10px;color:#64748b;margin-top:2px}
  .bar-row{display:flex;align-items:center;gap:10px;margin-bottom:8px}
  .bar-label{font-size:11px;color:#475569;min-width:110px}
  .bar-track{flex:1;height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden}
  .bar-fill{height:100%;border-radius:4px}
  .bar-fill.high{background:#10b981}
  .bar-fill.med{background:#f59e0b}
  .bar-fill.low{background:#f43f5e}
  .bar-val{font-size:11px;font-weight:700;min-width:32px;text-align:right}
  table{width:100%;border-collapse:collapse;font-size:11px}
  th{background:#f1f5f9;color:#475569;font-weight:700;padding:8px 10px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.4px}
  td{padding:8px 10px;border-bottom:1px solid #f1f5f9;color:#334155;vertical-align:middle}
  .badge{display:inline-block;padding:3px 9px;border-radius:100px;font-size:10px;font-weight:700}
  .badge-green{background:#dcfce7;color:#15803d}
  .badge-blue{background:#dbeafe;color:#1d4ed8}
  .badge-amber{background:#fef3c7;color:#92400e}
  .badge-red{background:#fee2e2;color:#991b1b}
  .badge-purple{background:#ede9fe;color:#5b21b6}
  .tag{display:inline-block;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:100px;padding:2px 8px;font-size:10px;color:#475569;margin:2px}
  .tag.match{background:#dbeafe;border-color:#93c5fd;color:#1d4ed8}
  .box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;font-size:11px;color:#475569}
  .rec-item{display:flex;gap:8px;padding:6px 0;border-bottom:1px solid #f1f5f9}
  .rec-num{width:18px;height:18px;background:#fef3c7;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:#92400e;flex-shrink:0}
  .footer{text-align:center;color:#94a3b8;font-size:10px;margin-top:28px;padding-top:14px;border-top:1px solid #e2e8f0}
  @media print{
    body{print-color-adjust:exact;-webkit-print-color-adjust:exact}
    @page{margin:15mm;size:A4}
  }
</style>
</head>
<body>
<div class="page">

<!-- Header -->
<div class="header">
  <div>
    <div class="brand">⚡ SmartHire <span>Interview Management System</span></div>
    <div class="candidate-name"><?=htmlspecialchars($candidate['name'])?></div>
    <div class="candidate-sub"><?=htmlspecialchars($candidate['position'])?> · <?=htmlspecialchars($candidate['email'])?></div>
    <?php if($candidate['phone']): ?>
    <div class="candidate-sub"><?=htmlspecialchars($candidate['phone'])?></div>
    <?php endif; ?>
    <div style="margin-top:10px;font-size:11px;color:#94a3b8">Report generated: <?=date('d M Y, g:i A')?></div>
  </div>
  <div>
    <div class="score-big"><?=$finalScore?></div>
    <div class="score-label">Composite Score / 100</div>
    <?php if($result && $result['recommendation']): ?>
    <div style="margin-top:8px;text-align:right;font-size:12px;font-weight:700;color:#60a5fa"><?=$recMap[$result['recommendation']]??'—'?></div>
    <?php endif; ?>
  </div>
</div>

<!-- Score Cards -->
<div class="grid4" style="grid-template-columns:repeat(5,1fr)">
  <?php foreach([['AI Score',$aiScore],['ATS Score',$atsScore],['Interview',$ivScore],['Questions',$qAvg],['Test Avg',$testAvg]] as [$l,$v]): ?>
  <div class="score-card">
    <div class="val"><?=$v?>%</div>
    <div class="lbl"><?=$l?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Interview Panel Evaluation -->
<?php if($result): ?>
<div class="section">
  <div class="section-title">Panel Evaluation</div>
  <?php foreach([['Technical Skill',$result['technical_score']],['Communication',$result['communication']],['Problem Solving',$result['problem_solving']],['Cultural Fit',$result['cultural_fit']],['Overall Score',$result['overall_score']]] as [$l,$v]):
    $cls=$v>=75?'high':($v>=50?'med':'low');
  ?>
  <div class="bar-row">
    <div class="bar-label"><?=$l?></div>
    <div class="bar-track"><div class="bar-fill <?=$cls?>" style="width:<?=$v?>%"></div></div>
    <div class="bar-val"><?=$v?>%</div>
  </div>
  <?php endforeach; ?>
  <?php if($result['feedback']): ?>
  <div class="box" style="margin-top:10px"><strong>Feedback:</strong> <?=htmlspecialchars($result['feedback'])?></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Question Scores -->
<?php if(!empty($responses)): ?>
<div class="section">
  <div class="section-title">Interview Questions — <?=$qAvg?>% Average</div>
  <table>
    <thead><tr><th>#</th><th>Question</th><th>Category</th><th>Difficulty</th><th>Score</th><th>Note</th></tr></thead>
    <tbody>
      <?php foreach($responses as $i=>$r):
        $pct=$r['max_score']>0?round($r['score_given']/$r['max_score']*100):0;
        $bc=$pct>=75?'badge-green':($pct>=50?'badge-amber':'badge-red');
      ?>
      <tr>
        <td><?=$i+1?></td>
        <td><?=htmlspecialchars(substr($r['question'],0,70))?>…</td>
        <td><span class="badge badge-blue"><?=str_replace('_',' ',$r['category'])?></span></td>
        <td><?=$r['difficulty']?></td>
        <td><span class="badge <?=$bc?>"><?=$r['score_given']?>/<?=$r['max_score']?></span></td>
        <td><?=htmlspecialchars($r['interviewer_note']??'—')?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ATS Scan -->
<?php if($atsScan): ?>
<div class="section">
  <div class="section-title">ATS Resume Analysis — <?=$atsScan['ats_score']?>% Score</div>
  <?php foreach([['Contact Info',$atsScan['contact_score'],15],['Document Format',$atsScan['format_score'],15],['Keywords',$atsScan['keyword_score'],25],['Experience',$atsScan['experience_score'],20],['Education',$atsScan['education_score'],10],['Achievements',$atsScan['action_verb_score'],10]] as [$l,$v,$m]):
    $p=$m>0?round($v/$m*100):0;$cls=$p>=75?'high':($p>=40?'med':'low');
  ?>
  <div class="bar-row">
    <div class="bar-label"><?=$l?> (<?=$m?>pts)</div>
    <div class="bar-track"><div class="bar-fill <?=$cls?>" style="width:<?=$p?>%"></div></div>
    <div class="bar-val"><?=$v?>/<?=$m?></div>
  </div>
  <?php endforeach; ?>
  <?php if($atsScan['matched_keywords']): ?>
  <div style="margin-top:10px"><strong style="font-size:11px">Matched Keywords:</strong><br>
  <?php foreach(array_filter(explode(',',$atsScan['matched_keywords'])) as $kw): ?>
  <span class="tag match"><?=trim($kw)?></span>
  <?php endforeach; ?></div>
  <?php endif; ?>
  <?php if($atsScan['recommendations']): $recs=array_filter(explode('|',$atsScan['recommendations'])); ?>
  <div style="margin-top:10px"><strong style="font-size:11px">Recommendations:</strong>
  <?php foreach($recs as $i=>$rec): ?>
  <div class="rec-item"><div class="rec-num"><?=$i+1?></div><div><?=htmlspecialchars($rec)?></div></div>
  <?php endforeach; ?></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Candidate Skills -->
<?php if($candidate['skills']): ?>
<div class="section">
  <div class="section-title">Skills Profile</div>
  <?php foreach(explode(',',$candidate['skills']) as $sk): ?><span class="tag match"><?=htmlspecialchars(trim($sk))?></span><?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Online Test Results -->
<?php if(!empty($testSubs)): ?>
<div class="section">
  <div class="section-title">Online Test Results</div>
  <table>
    <thead><tr><th>Test Title</th><th>Score</th><th>Marks</th><th>Time Taken</th><th>Result</th><th>Date</th></tr></thead>
    <tbody>
      <?php foreach($testSubs as $ts):
        $pct=round($ts['percentage']);$pass=$pct>=($ts['passing_marks']??40);
        $bc=$pass?'badge-green':'badge-red';
      ?>
      <tr>
        <td><?=htmlspecialchars($ts['test_title'])?></td>
        <td><strong style="color:<?=$pass?'#15803d':'#991b1b'?>"><?=$pct?>%</strong></td>
        <td><?=$ts['total_score']?>/<?=$ts['max_score']?></td>
        <td><?=$ts['time_taken_mins']?> min</td>
        <td><span class="badge <?=$bc?>"><?=$pass?'PASSED':'FAILED'?></span></td>
        <td><?=$ts['submitted_at']?date('d M Y',strtotime($ts['submitted_at'])):'—'?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- Interview History -->
<?php if(!empty($interviews)): ?>
<div class="section">
  <div class="section-title">Interview History</div>
  <table>
    <thead><tr><th>Date</th><th>Type</th><th>Interviewer</th><th>Mode</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach($interviews as $iv): $bc=['scheduled'=>'badge-blue','completed'=>'badge-green','cancelled'=>'badge-red','no-show'=>'badge-amber'][$iv['status']]??'badge-blue'; ?>
      <tr>
        <td><?=date('d M Y',strtotime($iv['scheduled_date']))?></td>
        <td><?=ucfirst($iv['type'])?></td>
        <td><?=htmlspecialchars($iv['interviewer']??'—')?></td>
        <td><?=ucfirst($iv['mode'])?></td>
        <td><span class="badge <?=$bc?>"><?=$iv['status']?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="footer">
  SmartHire v2.0 · <?=htmlspecialchars($candidate['name'])?> · Generated <?=date('d M Y g:i A')?>
</div>
</div>

<script>window.onload=function(){window.print();}</script>
</body>
</html>
