<?php
require_once 'includes/layout.php';
requireLogin();
requireRole('recruiter');
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fa = $_POST['form_action'] ?? '';
    if ($fa === 'create') {
        $tech=$_POST['technical_score']; $comm=$_POST['communication'];
        $prob=$_POST['problem_solving']; $cult=$_POST['cultural_fit'];
        $overall=round(((int)$tech+(int)$comm+(int)$prob+(int)$cult)/4);
        $ok=dbExecute("INSERT INTO results (interview_id,candidate_id,technical_score,communication,problem_solving,cultural_fit,overall_score,recommendation,feedback) VALUES (?,?,?,?,?,?,?,?,?)",
            'iiiiiiiss',(int)$_POST['interview_id'],(int)$_POST['candidate_id'],$tech,$comm,$prob,$cult,$overall,$_POST['recommendation'],trim($_POST['feedback']??''));
        if($ok) dbExecute("UPDATE candidates SET status='interviewed' WHERE id=?",'i',(int)$_POST['candidate_id']);
        setFlash($ok?'success':'error',$ok?'Result recorded!':'Failed.');
        header('Location: results.php'); exit;
    } elseif ($fa === 'delete') {
        dbExecute("DELETE FROM results WHERE id=?",'i',(int)$_POST['result_id']);
        setFlash('success','Result deleted.');
        header('Location: results.php'); exit;
    }
}

// Perf: latest ATS score per candidate is fetched via LATERAL join in the same
// query instead of one extra round-trip per row (was O(N) queries, now O(1)).
// Identical result to the old per-row "ORDER BY scanned_at DESC LIMIT 1" lookup.
$results = dbFetchAll("
    SELECT r.*, c.name AS cname, c.position, c.id AS cid, i.type AS iv_type, i.scheduled_date,
           rs.ats_score AS latest_ats_score
    FROM results r
    JOIN candidates c ON c.id = r.candidate_id
    JOIN interviews i ON i.id = r.interview_id
    LEFT JOIN LATERAL (
        SELECT ats_score FROM resume_scans
        WHERE candidate_id = r.candidate_id
        ORDER BY scanned_at DESC LIMIT 1
    ) rs ON true
    ORDER BY r.created_at DESC");
$completedIvs = dbFetchAll("SELECT i.id,i.type,i.scheduled_date,c.id AS cid,c.name AS cname,c.position FROM interviews i JOIN candidates c ON c.id=i.candidate_id WHERE i.status='completed' AND i.id NOT IN(SELECT interview_id FROM results) ORDER BY i.scheduled_date DESC");
$statusData = dbFetchAll("SELECT status, COUNT(*) n FROM candidates GROUP BY status");
$avgData    = dbFetchOne("SELECT ROUND(AVG(technical_score),1) tech,ROUND(AVG(communication),1) comm,ROUND(AVG(problem_solving),1) prob,ROUND(AVG(cultural_fit),1) cult,ROUND(AVG(overall_score),1) overall FROM results");
$recDist    = dbFetchAll("SELECT recommendation, COUNT(*) n FROM results GROUP BY recommendation");
$trend      = dbFetchAll("SELECT c.name, r.overall_score FROM results r JOIN candidates c ON c.id=r.candidate_id ORDER BY r.created_at ASC LIMIT 10");

renderHead('Results');
renderSidebar('results');
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<div class="page-header">
  <div class="page-header-left">
    <div class="breadcrumb"><a href="dashboard.php">Home</a> <i class="fa-solid fa-chevron-right"></i> Results</div>
    <h1 class="page-title">Interview Results</h1>
    <p class="page-subtitle"><?=count($results)?> evaluations with visual analytics</p>
  </div>
  <?php if(!empty($completedIvs)): ?>
  <button class="btn btn-primary" onclick="openModal('resultModal')">
    <i class="fa-solid fa-plus"></i> Add Result
  </button>
  <?php endif; ?>
</div>

<!-- ── Analytics Charts ───────────────────────────────────── -->
<?php if(!empty($results) && $avgData): ?>
<div style="display:grid;grid-template-columns:2fr 1fr;gap:18px;margin-bottom:20px">
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-chart-bar"></i> Average Scores Across All Interviews</div></div>
    <div class="card-body"><canvas id="avgBarChart" height="160"></canvas></div>
  </div>
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-chart-pie"></i> Hire Recommendations</div></div>
    <div class="card-body" style="display:flex;align-items:center;justify-content:center;min-height:150px">
      <canvas id="recDonut" style="max-width:170px;max-height:170px"></canvas>
    </div>
  </div>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:20px">
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-people-group"></i> Candidate Pipeline</div></div>
    <div class="card-body" style="display:flex;gap:20px;align-items:center">
      <canvas id="pipelineDonut" style="max-width:140px;max-height:140px;flex-shrink:0"></canvas>
      <div id="pipelineLegend" style="flex:1"></div>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-chart-line"></i> Score Trend</div></div>
    <div class="card-body"><canvas id="trendLine" height="130"></canvas></div>
  </div>
</div>
<?php endif; ?>

<!-- Search -->
<div class="card" style="margin-bottom:16px">
  <div class="card-body" style="padding:12px 16px">
    <div style="display:flex;gap:10px;align-items:center">
      <i class="fa-solid fa-search" style="color:var(--text-muted)"></i>
      <input type="text" id="tableSearch" class="form-control" aria-label="Search results" placeholder="Search results…" style="border:none;background:transparent;padding:0;flex:1">
    </div>
  </div>
</div>

<!-- Results Table -->
<div class="card">
  <div class="table-container">
    <table>
      <thead>
        <tr><th>#</th><th>Candidate</th><th>Interview</th><th>Tech</th><th>Comm</th><th>Problem</th><th>Cultural</th><th>Overall</th><th>Rec.</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php if(empty($results)): ?>
        <tr><td colspan="10" class="text-center" style="padding:40px;color:var(--text-muted)">
          <i class="fa-solid fa-chart-bar" style="font-size:24px;display:block;margin-bottom:8px"></i>No results yet
        </td></tr>
        <?php endif; ?>
        <?php foreach($results as $i=>$r):
          $sc=(int)$r['overall_score'];
          $cls=$sc>=75?'score-high':($sc>=50?'score-medium':'score-low');
          $recMap=['strong_yes'=>['✅ Strong Yes','green'],'yes'=>['👍 Yes','blue'],'maybe'=>['🤔 Maybe','amber'],'no'=>['❌ No','rose']];
          [$rl,$rc]=$recMap[$r['recommendation']]??['—','text-muted'];
          // ATS score now comes pre-joined on $r (see LATERAL join above) — no per-row query.
          $atsScore = $r['latest_ats_score'] !== null ? (int)$r['latest_ats_score'] : null;
        ?>
        <tr>
          <td class="td-muted"><?=$i+1?></td>
          <td>
            <div class="d-flex align-center gap-2">
              <div class="avatar sm"><?=strtoupper(substr($r['cname'],0,1))?></div>
              <div>
                <div class="fw-600"><?=htmlspecialchars($r['cname'])?></div>
                <div class="td-muted"><?=htmlspecialchars($r['position'])?></div>
              </div>
            </div>
          </td>
          <td>
            <span class="badge-status badge-completed" style="text-transform:capitalize"><?=$r['iv_type']?></span>
            <div class="td-muted"><?=date('d M Y',strtotime($r['scheduled_date']))?></div>
          </td>
          <?php foreach(['technical_score','communication','problem_solving','cultural_fit'] as $f):
            $v=(int)$r[$f];$c2=$v>=75?'score-high':($v>=50?'score-medium':'score-low');
          ?>
          <td><div class="score-bar <?=$c2?>" style="min-width:100px">
            <div class="score-bar-track"><div class="score-bar-fill" data-pct="<?=$v?>"></div></div>
            <span class="score-text"><?=$v?></span></div></td>
          <?php endforeach; ?>
          <td>
            <div class="score-bar <?=$cls?>" style="min-width:110px">
              <div class="score-bar-track"><div class="score-bar-fill" data-pct="<?=$sc?>"></div></div>
              <span class="score-text fw-600"><?=$sc?>%</span>
            </div>
            <?php if ($atsScore !== null): ?>
            <div style="font-size:10px;color:var(--violet);margin-top:3px">
              ATS: <strong><?=$atsScore?>%</strong>
            </div>
            <?php endif; ?>
          </td>
          <td>
            <span style="font-size:13px;font-weight:600;color:var(--<?=$rc?>)"><?=$rl?></span>
          </td>
          <td>
            <div class="d-flex gap-2" style="flex-wrap:nowrap">
              <a href="candidate_detail.php?candidate_id=<?=$r['cid']?>"
                 class="btn btn-secondary btn-sm btn-icon" title="Full Report & Charts">
                <i class="fa-solid fa-chart-area"></i>
              </a>
              <a href="print_result.php?candidate_id=<?=$r['cid']?>" target="_blank"
                 class="btn btn-sm btn-icon" title="Download PDF Report"
                 style="background:var(--rose-bg);color:var(--rose);border:1px solid rgba(244,63,94,.2)">
                <i class="fa-solid fa-file-pdf"></i>
              </a>
              <form method="POST" style="display:inline">
      <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="delete">
                <input type="hidden" name="result_id" value="<?=$r['id']?>">
                <button type="submit" class="btn btn-danger btn-sm btn-icon" data-confirm="Delete this result?" title="Delete">
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

<!-- Add Result Modal -->
<?php if(!empty($completedIvs)): ?>
<div class="modal-overlay" id="resultModal">
  <div class="modal" style="max-width:600px">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fa-solid fa-chart-bar"></i> Record Result</h3>
      <button class="modal-close" onclick="closeModal('resultModal')">×</button>
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="form_action" value="create">
      <input type="hidden" name="candidate_id" id="res_cid">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group form-full">
            <label class="form-label">Interview *</label>
            <select name="interview_id" class="form-control" onchange="document.getElementById('res_cid').value=this.options[this.selectedIndex].dataset.cid" required>
              <option value="">— Select Completed Interview —</option>
              <?php foreach($completedIvs as $iv): ?>
              <option value="<?=$iv['id']?>" data-cid="<?=$iv['cid']?>">
                <?=htmlspecialchars($iv['cname'])?> — <?=$iv['type']?> (<?=date('d M',strtotime($iv['scheduled_date']))?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php foreach([['technical_score','Technical','fa-code'],['communication','Communication','fa-comments'],['problem_solving','Problem Solving','fa-brain'],['cultural_fit','Cultural Fit','fa-heart']] as [$n,$l,$ic]): ?>
          <div class="form-group">
            <label class="form-label"><i class="fa-solid <?=$ic?>"></i> <?=$l?> (0–100)</label>
            <input name="<?=$n?>" type="number" min="0" max="100" class="form-control" placeholder="75" required>
          </div>
          <?php endforeach; ?>
          <div class="form-group">
            <label class="form-label">Recommendation</label>
            <select name="recommendation" class="form-control" required>
              <option value="strong_yes">✅ Strong Yes</option>
              <option value="yes">👍 Yes</option>
              <option value="maybe" selected>🤔 Maybe</option>
              <option value="no">❌ No</option>
            </select>
          </div>
          <div class="form-group form-full">
            <label class="form-label">Feedback</label>
            <textarea name="feedback" class="form-control" placeholder="Summary…"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('resultModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Save Result</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if(!empty($results) && $avgData): ?>
<script>
new Chart(document.getElementById('avgBarChart'),{type:'bar',data:{
  labels:['Technical','Communication','Problem Solving','Cultural Fit','Overall'],
  datasets:[{data:[<?=$avgData['tech']?>,<?=$avgData['comm']?>,<?=$avgData['prob']?>,<?=$avgData['cult']?>,<?=$avgData['overall']?>],
    backgroundColor:['rgba(59,130,246,.75)','rgba(139,92,246,.75)','rgba(245,158,11,.75)','rgba(244,63,94,.75)','rgba(16,185,129,.85)'],
    borderRadius:8,borderWidth:0}]
},options:{plugins:{legend:{display:false}},scales:{
  y:{beginAtZero:true,max:100,grid:{color:'rgba(148,163,184,.1)'},ticks:{color:'#94a3b8',font:{size:11}}},
  x:{grid:{display:false},ticks:{color:'#94a3b8',font:{size:11}}}
}}});
<?php $rd=array_column($recDist,'n','recommendation'); ?>
new Chart(document.getElementById('recDonut'),{type:'doughnut',data:{
  labels:['Strong Yes','Yes','Maybe','No'],
  datasets:[{data:[<?=$rd['strong_yes']??0?>,<?=$rd['yes']??0?>,<?=$rd['maybe']??0?>,<?=$rd['no']??0?>],
    backgroundColor:['#10b981','#3b82f6','#f59e0b','#f43f5e'],borderWidth:0,cutout:'62%'}]
},options:{plugins:{legend:{position:'bottom',labels:{color:'#94a3b8',font:{size:10},padding:6}}}}});
const pColors={'pending':'#f59e0b','scheduled':'#3b82f6','interviewed':'#8b5cf6','hired':'#10b981','rejected':'#f43f5e'};
const pLabels=<?=json_encode(array_column($statusData,'status'))?>;
const pData=<?=json_encode(array_values(array_column($statusData,'n')))?>;
new Chart(document.getElementById('pipelineDonut'),{type:'doughnut',data:{
  labels:pLabels,datasets:[{data:pData,backgroundColor:pLabels.map(l=>pColors[l]||'#94a3b8'),borderWidth:0,cutout:'60%'}]
},options:{plugins:{legend:{display:false}}}});
const leg=document.getElementById('pipelineLegend');
pLabels.forEach((l,i)=>{const d=document.createElement('div');d.style.cssText='display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:12px;color:#94a3b8';d.innerHTML=`<span style="width:10px;height:10px;border-radius:50%;background:${pColors[l]||'#94a3b8'};flex-shrink:0"></span>${l} (${pData[i]})`;leg.appendChild(d);});
<?php $tLabels=array_map(fn($r)=>explode(' ',$r['name'])[0],$trend);$tData=array_column($trend,'overall_score'); ?>
new Chart(document.getElementById('trendLine'),{type:'line',data:{
  labels:<?=json_encode($tLabels)?>,
  datasets:[{label:'Score',data:<?=json_encode(array_map('intval',$tData))?>,borderColor:'#3b82f6',backgroundColor:'rgba(59,130,246,.1)',tension:.4,fill:true,pointRadius:4,pointBackgroundColor:'#3b82f6'}]
},options:{plugins:{legend:{display:false}},scales:{
  y:{beginAtZero:true,max:100,grid:{color:'rgba(148,163,184,.1)'},ticks:{color:'#94a3b8',font:{size:11}}},
  x:{grid:{display:false},ticks:{color:'#94a3b8',font:{size:10}}}
}}});
</script>
<?php endif; ?>
<?php renderFooter(); ?>
