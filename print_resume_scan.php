<?php
require_once 'includes/config.php';
requireLogin();
$scan_id = (int)($_GET['scan_id'] ?? ($_GET['id'] ?? 0));
if (!$scan_id) die('Invalid request.');
$scan = dbFetchOne("SELECT rs.*, c.name AS cname FROM resume_scans rs LEFT JOIN candidates c ON c.id=rs.candidate_id WHERE rs.id=?", 'i', $scan_id);
if (!$scan) die('Scan not found.');
$matched = array_filter(explode(',', $scan['matched_keywords']));
$missing = array_filter(explode(',', $scan['missing_keywords']));
$recs    = array_filter(explode('|', $scan['recommendations']));
$name = $scan['cname'] ?? $scan['candidate_name_free'] ?? 'Candidate';
$sc   = (int)$scan['ats_score'];
$lvl  = $sc>=80?'Excellent':($sc>=65?'Good':($sc>=50?'Needs Improvement':'Poor'));
$color= $sc>=80?'#10b981':($sc>=65?'#3b82f6':($sc>=50?'#f59e0b':'#f43f5e'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>ATS Report — <?=htmlspecialchars($name)?></title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Inter',sans-serif;background:#fff;color:#111;font-size:12px;line-height:1.6}
  .page{max-width:780px;margin:0 auto;padding:36px}
  .header{background:linear-gradient(135deg,#0d1829,#1a3250);color:#fff;padding:26px 28px;border-radius:12px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center}
  .brand{font-size:20px;font-weight:800;color:#60a5fa}
  .brand span{color:#a78bfa;font-size:11px;display:block;font-weight:400}
  .score-big{font-size:44px;font-weight:900;color:<?=$color?>;line-height:1}
  .score-label{font-size:11px;color:#94a3b8;text-align:right}
  .section{margin-bottom:20px}
  .section-title{font-size:12px;font-weight:700;color:#1e293b;text-transform:uppercase;letter-spacing:.6px;border-bottom:2px solid #e2e8f0;padding-bottom:5px;margin-bottom:12px}
  .bar-row{display:flex;align-items:center;gap:10px;margin-bottom:8px}
  .bar-label{font-size:11px;color:#475569;min-width:120px}
  .bar-track{flex:1;height:10px;background:#e2e8f0;border-radius:5px;overflow:hidden}
  .bar-fill{height:100%;border-radius:5px}
  .bar-val{font-size:11px;font-weight:700;min-width:40px;text-align:right}
  .tag{display:inline-block;padding:3px 9px;border-radius:100px;font-size:10px;font-weight:600;margin:2px}
  .tag-match{background:#dbeafe;border:1px solid #93c5fd;color:#1d4ed8}
  .tag-miss{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b}
  .rec-item{display:flex;gap:10px;padding:8px 0;border-bottom:1px solid #f1f5f9;align-items:flex-start}
  .rec-num{width:20px;height:20px;background:#fef3c7;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:#92400e;flex-shrink:0;margin-top:1px}
  .score-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:20px}
  .score-cell{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center}
  .score-cell .v{font-size:22px;font-weight:800}
  .score-cell .l{font-size:10px;color:#64748b}
  .footer{text-align:center;color:#94a3b8;font-size:10px;margin-top:24px;padding-top:12px;border-top:1px solid #e2e8f0}
  @media print{body{print-color-adjust:exact;-webkit-print-color-adjust:exact}@page{margin:15mm;size:A4}}
</style>
</head>
<body>
<div class="page">
<div class="header">
  <div>
    <div class="brand">⚡ SmartHire <span>ATS Resume Analysis Report</span></div>
    <div style="font-size:18px;font-weight:800;color:#fff;margin-top:6px"><?=htmlspecialchars($name)?></div>
    <div style="color:#94a3b8;font-size:12px"><?=htmlspecialchars($scan['position_applied']??'Position not specified')?></div>
    <div style="color:#94a3b8;font-size:11px;margin-top:8px">Scanned: <?=date('d M Y g:i A',strtotime($scan['scanned_at']))?></div>
  </div>
  <div style="text-align:right">
    <div class="score-big"><?=$sc?></div>
    <div class="score-label">ATS Score / 100</div>
    <div style="margin-top:6px;font-size:13px;font-weight:700;color:<?=$color?>"><?=$lvl?> Match</div>
  </div>
</div>

<div class="score-grid">
  <?php foreach([['Contact',$scan['contact_score'],15],['Format',$scan['format_score'],15],['Keywords',$scan['keyword_score'],25],['Experience',$scan['experience_score'],20],['Education',$scan['education_score'],10],['Achievements',$scan['action_verb_score'],10]] as [$l,$v,$m]): ?>
  <div class="score-cell">
    <div class="v"><?=$v?>/<?=$m?></div>
    <div class="l"><?=$l?></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="section">
  <div class="section-title">Detailed Score Breakdown</div>
  <?php foreach([['Contact Information',$scan['contact_score'],15],['Document Format & Structure',$scan['format_score'],15],['Technical Keywords',$scan['keyword_score'],25],['Experience Level',$scan['experience_score'],20],['Education',$scan['education_score'],10],['Achievements & Action Verbs',$scan['action_verb_score'],10]] as [$l,$v,$m]):
    $p=$m?round($v/$m*100):0;
    $c=$p>=75?'#10b981':($p>=40?'#f59e0b':'#f43f5e');
  ?>
  <div class="bar-row">
    <div class="bar-label"><?=$l?></div>
    <div class="bar-track"><div class="bar-fill" style="width:<?=$p?>%;background:<?=$c?>"></div></div>
    <div class="bar-val" style="color:<?=$c?>"><?=$v?>/<?=$m?></div>
  </div>
  <?php endforeach; ?>
</div>

<?php if(!empty($matched)): ?>
<div class="section">
  <div class="section-title">✅ Detected Keywords (<?=count($matched)?>)</div>
  <?php foreach($matched as $kw): ?><span class="tag tag-match"><?=trim($kw)?></span><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if(!empty($missing)): ?>
<div class="section">
  <div class="section-title">⚠ Missing Keywords for Position (<?=count($missing)?>)</div>
  <?php foreach($missing as $kw): ?><span class="tag tag-miss"><?=trim($kw)?></span><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if(!empty($recs)): ?>
<div class="section">
  <div class="section-title">Recommendations to Improve ATS Score</div>
  <?php foreach($recs as $i=>$rec): ?>
  <div class="rec-item">
    <div class="rec-num"><?=$i+1?></div>
    <div style="font-size:11.5px;color:#334155"><?=htmlspecialchars($rec)?></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="footer">SmartHire v2.0 ATS Scanner · Report for <?=htmlspecialchars($name)?> · Generated <?=date('d M Y g:i A')?></div>
</div>
<script>window.onload=function(){window.print();}</script>
</body>
</html>
