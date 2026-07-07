<?php
require_once 'includes/layout.php';
require_once 'includes/resume_parser.php';
requireLogin();
requireRole('recruiter');
if ($_SERVER['REQUEST_METHOD']==='POST') require_csrf();

$candidates = dbFetchAll("SELECT id,name,position FROM candidates ORDER BY name");
$scans      = dbFetchAll("SELECT rs.*, c.name AS cname FROM resume_scans rs LEFT JOIN candidates c ON c.id=rs.candidate_id ORDER BY rs.scanned_at DESC LIMIT 20");
$result = null;

function runATS(string $text, string $position): array {
    $t = strtolower($text);
    $score = 0; $details = [];

    // ── 1. Contact Information (15 pts) ──────────────────────
    // Real ATS looks for email, phone, location, LinkedIn, portfolio
    $contactScore = 0;
    if (preg_match('/[\w.%+-]+@[\w.-]+\.[a-z]{2,}/i', $text))             $contactScore += 5;
    if (preg_match('/(\+?\d[\d\s\-().]{7,}\d)/i', $text))                  $contactScore += 4;
    if (preg_match('/(linkedin\.com\/in\/|github\.com\/|behance\.net\/|portfolio\.)/i', $text)) $contactScore += 4;
    if (preg_match('/\b(city|location|address|\b[A-Z][a-z]+,\s*[A-Z]{2}\b)/i', $text))         $contactScore += 2;
    $details['contact'] = min(15, $contactScore);
    $score += $details['contact'];

    // ── 2. Document Format & Structure (15 pts) ──────────────
    // ATS checks for standard section headings
    $sectionGroups = [
        [['experience','work experience','work history','employment history','professional experience'], 4],
        [['education','academic background','qualifications'], 3],
        [['skills','technical skills','core competencies','key skills'], 3],
        [['summary','professional summary','objective','profile','about me'], 2],
        [['projects','key projects','portfolio','notable projects'], 2],
        [['certifications','certificates','credentials','licenses'], 1],
    ];
    $formatScore = 0;
    foreach ($sectionGroups as [$keywords, $pts]) {
        foreach ($keywords as $kw) {
            if (str_contains($t, $kw)) { $formatScore += $pts; break; }
        }
    }
    // Penalise if resume is in table/column format (ATS can't parse well)
    $details['format'] = min(15, $formatScore);
    $score += $details['format'];

    // ── 3. Technical Keyword Matching (25 pts) ────────────────
    // Industry-standard tech keywords pool
    $techPool = [
        // Languages
        'python','java','javascript','typescript','php','ruby','go','rust','swift','kotlin',
        'c++','c#','scala','r programming','matlab',
        // Frontend
        'react','vue','angular','html','css','sass','webpack','next.js','tailwind',
        // Backend
        'node.js','django','flask','spring boot','laravel','express','fastapi','rails',
        // Data
        'sql','mysql','postgresql','mongodb','redis','elasticsearch','cassandra','hadoop',
        'spark','kafka','tableau','power bi','data analysis','machine learning','deep learning',
        'tensorflow','pytorch','scikit-learn','pandas','numpy',
        // Cloud/DevOps
        'aws','azure','gcp','docker','kubernetes','terraform','ansible','jenkins',
        'ci/cd','github actions','linux','bash',
        // APIs
        'rest api','graphql','microservices','api design','websocket',
        // Tools
        'git','agile','scrum','jira','postman','figma',
    ];

    // Position-specific high-value keywords (double weight)
    $posKw   = strtolower($position);
    $roleKws = [];
    if (str_contains($posKw,'data'))       $roleKws = ['python','sql','tableau','machine learning','pandas','numpy','data analysis','statistics'];
    elseif (str_contains($posKw,'full'))   $roleKws = ['react','node.js','javascript','mysql','rest api','git','typescript'];
    elseif (str_contains($posKw,'backend'))$roleKws = ['java','python','rest api','docker','postgresql','microservices','spring boot'];
    elseif (str_contains($posKw,'devops')) $roleKws = ['docker','kubernetes','aws','terraform','ci/cd','jenkins','ansible','linux'];
    elseif (str_contains($posKw,'front'))  $roleKws = ['react','vue','angular','html','css','javascript','typescript','figma'];
    elseif (str_contains($posKw,'mobile')) $roleKws = ['swift','kotlin','react','flutter','ios','android','mobile development'];
    elseif (str_contains($posKw,'ml') || str_contains($posKw,'ai')) $roleKws = ['python','tensorflow','pytorch','scikit-learn','machine learning','deep learning'];
    elseif (str_contains($posKw,'cloud'))  $roleKws = ['aws','azure','gcp','kubernetes','terraform','docker','cloud architecture'];

    $matchedGeneral = [];
    foreach ($techPool as $kw) {
        if (str_contains($t, $kw)) $matchedGeneral[] = $kw;
    }
    $matchedRole    = array_filter($roleKws, fn($k) => str_contains($t, $k));
    $missingRole    = array_filter($roleKws, fn($k) => !str_contains($t, $k));

    // Scoring: general = 1pt each (max 15), role-specific = 2pt each (max 10)
    $kwScore = min(15, count($matchedGeneral)) + min(10, count($matchedRole) * 2);
    $details['keywords']         = min(25, $kwScore);
    $details['matched_general']  = array_values($matchedGeneral);
    $details['matched_role']     = array_values($matchedRole);
    $details['missing_role']     = array_values(array_slice($missingRole, 0, 8));
    $score += $details['keywords'];

    // ── 4. Experience Level & Depth (20 pts) ─────────────────
    $expScore = 0;
    // Years of experience
    if      (preg_match('/\b(8|9|10|1[0-9]|20)\s*(year|yr)/i', $text))  $expScore += 10;
    elseif  (preg_match('/\b([5-7])\s*(year|yr)/i', $text))              $expScore += 8;
    elseif  (preg_match('/\b([3-4])\s*(year|yr)/i', $text))              $expScore += 6;
    elseif  (preg_match('/\b([1-2])\s*(year|yr)/i', $text))              $expScore += 4;
    elseif  (preg_match('/\b(month|intern|fresher|graduate)\b/i', $text))$expScore += 2;
    // Leadership / seniority
    if (preg_match('/\b(led|managed|headed|directed|spearheaded|oversaw)\b/i', $text)) $expScore += 5;
    if (preg_match('/\b(senior|lead|principal|staff|head|director|architect|manager)\b/i', $text)) $expScore += 5;
    $details['experience'] = min(20, $expScore);
    $score += $details['experience'];

    // ── 5. Education Signals (10 pts) ────────────────────────
    $eduScore = 0;
    // Degree level
    if      (preg_match('/\b(phd|ph\.d|doctor|m\.tech|master|mba|m\.s\b)/i', $text))    $eduScore += 5;
    elseif  (preg_match('/\b(b\.tech|b\.e\b|bachelor|b\.s\b|degree|engineering)/i', $text)) $eduScore += 4;
    elseif  (preg_match('/\b(diploma|associate|certification|certified)\b/i', $text))         $eduScore += 3;
    // Relevant field
    if (preg_match('/\b(computer science|information technology|software engineering|data science|mathematics|statistics|electrical|electronics)\b/i', $text)) $eduScore += 5;
    $details['education'] = min(10, $eduScore);
    $score += $details['education'];

    // ── 6. Achievements & Quantified Impact (10 pts) ─────────
    // ATS rewards numbers, %, metrics — shows real impact
    $achScore = 0;
    $numMatches = preg_match_all('/\b(\d+\.?\d*)\s*(%|x\b|k\b|million|billion|cr|lakh|users|requests|ms|seconds|hours|days|clients|projects|teams)\b/i', $text);
    $achScore += min(6, $numMatches * 2);
    // Strong action verbs
    $strongVerbs = ['achieved','delivered','exceeded','improved','increased','reduced','optimised','optimized',
                    'automated','streamlined','scaled','launched','built','designed','developed','implemented',
                    'established','transformed','generated','saved','grew','trained','mentored'];
    $verbCount = count(array_filter($strongVerbs, fn($v) => str_contains($t, $v)));
    $achScore += min(4, $verbCount);
    $details['action_verb'] = min(10, $achScore);
    $score += $details['action_verb'];

    // ── 7. Smart recommendations ─────────────────────────────
    $recs = [];
    if ($details['contact'] < 10)    $recs[] = 'Add LinkedIn URL, phone number, and city/location to pass contact filters';
    if ($details['format'] < 9)      $recs[] = 'Use clear section headers: "Work Experience", "Education", "Skills", "Summary"';
    if ($details['keywords'] < 15)   $recs[] = 'Add more keywords for ' . ($position ?: 'your target role') . ': ' . implode(', ', array_slice($details['missing_role'] ?: ['relevant technologies'], 0, 5));
    if ($details['experience'] < 8)  $recs[] = 'Clearly state total years of experience (e.g. "5+ years of experience in…")';
    if ($details['education'] < 5)   $recs[] = 'List your degree, field of study, and university clearly';
    if ($details['action_verb'] < 5) $recs[] = 'Add quantified achievements: "Improved API response time by 40%", "Built system serving 50K users"';
    $wordCount = str_word_count($text);
    if ($wordCount < 200) $recs[] = 'Resume is too brief (' . $wordCount . ' words) — expand to at least 400 words for ATS to extract enough context';
    if ($wordCount > 1200) $recs[] = 'Resume is very long (' . $wordCount . ' words) — consider condensing to 1-2 pages (~500-800 words)';

    return [
        'ats_score'        => min(100, $score),
        'contact_score'    => $details['contact'],
        'keyword_score'    => $details['keywords'],
        'format_score'     => $details['format'],
        'experience_score' => $details['experience'],
        'education_score'  => $details['education'],
        'action_verb_score'=> $details['action_verb'],
        'matched_keywords' => implode(',', array_values(array_merge($details['matched_general'], $details['matched_role']))),
        'missing_keywords' => implode(',', $details['missing_role']),
        'recommendations'  => implode('|', $recs),
    ];
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $position   = trim($_POST['position_applied'] ?? '');
    $cid        = (int)($_POST['candidate_id'] ?? 0);
    $freeName   = trim($_POST['candidate_name_free'] ?? '');
    $resumeText = '';
    $savedResumePath = null;

    $uploadError = '';
    if (!empty($_FILES['resume_file']['name'])) {
        $up = store_resume_upload($_FILES['resume_file']);
        if ($up['ok']) {
            $abs = __DIR__ . '/' . $up['path'];
            $parsed = extract_resume_text($abs);
            $resumeText = $parsed['text'];
            $savedResumePath = $up['path'];
            if ($cid && $savedResumePath) {
                dbExecute("UPDATE candidates SET resume_path=? WHERE id=?", 'si', $savedResumePath, $cid);
            }
            if (trim($resumeText) === '') {
                $uploadError = 'We could not extract readable text from that file. If it is a scanned/image PDF, please paste the text instead.';
            }
        } else {
            $uploadError = $up['error'];
        }
    }
    if (empty($resumeText)) {
        $resumeText = trim($_POST['resume_text'] ?? '');
    }

    if ($resumeText && $position) {
        $ats = runATS($resumeText, $position);
        $result = $ats;
        $result['position_applied'] = $position;
        $result['candidate_name_free'] = $freeName ?: null;
        $result['candidate_id'] = $cid ?: null;

        $insertId = dbExecute(
            "INSERT INTO resume_scans (candidate_id,candidate_name_free,position_applied,raw_text,ats_score,contact_score,keyword_score,format_score,experience_score,education_score,action_verb_score,matched_keywords,missing_keywords,recommendations) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            'isssiiiiiiisss',
            $cid ?: null, $freeName ?: null, $position,
            substr($resumeText, 0, 5000),
            $ats['ats_score'], $ats['contact_score'], $ats['keyword_score'],
            $ats['format_score'], $ats['experience_score'], $ats['education_score'],
            $ats['action_verb_score'], $ats['matched_keywords'],
            $ats['missing_keywords'], $ats['recommendations']
        );
        $result['scan_id'] = $insertId;
        audit_log('ats_scan', 'resume_scan', is_int($insertId) ? $insertId : null, 'pos=' . $position);

        if ($cid) {
            dbExecute("UPDATE candidates SET ai_score=GREATEST(ai_score,?) WHERE id=?", 'ii', $ats['ats_score'], $cid);
        }
        $scans = dbFetchAll("SELECT rs.*, c.name AS cname FROM resume_scans rs LEFT JOIN candidates c ON c.id=rs.candidate_id ORDER BY rs.scanned_at DESC LIMIT 20");
    }
}

renderHead('ATS Resume Scanner');
renderSidebar('resume_scanner');
?>
<div class="page-header">
  <div class="page-header-left">
    <div class="breadcrumb"><a href="dashboard.php">Home</a> <i class="fa-solid fa-chevron-right"></i> ATS Scanner</div>
    <h1 class="page-title">ATS Resume Scanner</h1>
    <p class="page-subtitle">Upload a resume to get a real-time ATS compatibility score with detailed breakdown</p>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1.4fr;gap:24px;align-items:start">
<!-- Upload Form -->
<div class="table-card">
  <div class="table-header"><h3 class="table-title"><i class="fa-solid fa-upload"></i> Scan Resume</h3></div>
  <div style="padding:20px">
    <form method="POST" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="form-group">
        <label class="form-label">Position Applied For *</label>
        <input type="text" name="position_applied" class="form-control" placeholder="e.g. Full Stack Developer" required>
      </div>
      <div class="form-group">
        <label class="form-label">Link to Candidate (optional)</label>
        <select name="candidate_id" class="form-control">
          <option value="">— Walk-in / External Candidate —</option>
          <?php foreach ($candidates as $c): ?>
          <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> — <?= htmlspecialchars($c['position']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Candidate Name (if not linked)</label>
        <input type="text" name="candidate_name_free" class="form-control" placeholder="Enter name manually">
      </div>
      <div class="form-group">
        <label class="form-label">Upload Resume File (TXT/PDF text)</label>
        <div id="dropzone" style="border:2px dashed var(--border);border-radius:12px;padding:28px;text-align:center;cursor:pointer;transition:all .2s;background:rgba(255,255,255,0.02)" onclick="document.getElementById('resumeFile').click()">
          <i class="fa-solid fa-cloud-arrow-up" style="font-size:32px;color:var(--accent);margin-bottom:8px;display:block"></i>
          <p style="color:var(--text-muted);font-size:13px;margin:0">Click to upload or drag &amp; drop<br><small>TXT, PDF (text-based) — max 2MB</small></p>
          <div id="fileName" style="margin-top:8px;font-size:13px;color:var(--text);display:none"></div>
        </div>
        <input type="file" id="resumeFile" name="resume_file" accept=".txt,.pdf,.doc,.docx" style="display:none" onchange="showFileName(this)">
      </div>
      <div class="form-group">
        <label class="form-label">Or Paste Resume Text</label>
        <textarea name="resume_text" class="form-control" rows="8" placeholder="Paste the full resume content here…"></textarea>
      </div>
      <button type="submit" class="btn btn-primary w-100">
        <i class="fa-solid fa-magnifying-glass"></i> Scan Resume Now
      </button>
    </form>
  </div>
</div>

<!-- Result -->
<div>
<?php if (!empty($uploadError)): ?>
<div class="alert alert-error" style="margin-bottom:14px"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($uploadError) ?></div>
<?php endif; ?>
<?php if ($result): ?>
<?php
  $atsScore = $result['ats_score'];
  $atsColor = $atsScore >= 80 ? '#10b981' : ($atsScore >= 60 ? '#f59e0b' : ($atsScore >= 40 ? '#6366f1' : '#ef4444'));
  $atsLabel = $atsScore >= 80 ? 'Excellent Match' : ($atsScore >= 60 ? 'Good Match' : ($atsScore >= 40 ? 'Fair Match' : 'Poor Match'));
  $circ = 2 * pi() * 54;
  $filled = $circ * ($atsScore / 100);
  $scoreBreakdown = [
    'Contact Info'    => [$result['contact_score'],    15, 'fa-address-card'],
    'Keywords'        => [$result['keyword_score'],    25, 'fa-tags'],
    'Sections/Format' => [$result['format_score'],     15, 'fa-list'],
    'Experience'      => [$result['experience_score'], 20, 'fa-briefcase'],
    'Education'       => [$result['education_score'],  10, 'fa-graduation-cap'],
    'Action Verbs'    => [$result['action_verb_score'],10, 'fa-pen'],
  ];
  $recs = array_filter(explode('|', $result['recommendations'] ?? ''));
  $matched = array_filter(explode(',', $result['matched_keywords'] ?? ''));
  $missing = array_filter(explode(',', $result['missing_keywords'] ?? ''));
?>

<!-- ATS Score Card -->
<div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:24px;margin-bottom:16px">
  <div style="display:flex;align-items:center;gap:24px;margin-bottom:20px">
    <!-- Ring -->
    <div style="position:relative;width:120px;height:120px;flex-shrink:0">
      <svg width="120" height="120" viewBox="0 0 120 120" style="transform:rotate(-90deg)">
        <circle cx="60" cy="60" r="54" fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="10"/>
        <circle cx="60" cy="60" r="54" fill="none" stroke="<?= $atsColor ?>" stroke-width="10" stroke-linecap="round"
                stroke-dasharray="<?= $circ ?>" stroke-dashoffset="<?= $circ - $filled ?>"/>
      </svg>
      <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center">
        <div style="font-size:26px;font-weight:800;color:<?= $atsColor ?>"><?= $atsScore ?></div>
        <div style="font-size:10px;color:var(--text-muted)">/ 100</div>
      </div>
    </div>
    <div>
      <div style="font-size:22px;font-weight:800;color:var(--text)"><?= $atsLabel ?></div>
      <div style="color:var(--text-muted);font-size:13px;margin-top:4px">For: <?= htmlspecialchars($result['position_applied']) ?></div>
      <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:6px">
        <?php if ($atsScore >= 80): ?>
          <span class="badge badge-green">ATS Friendly</span>
          <span class="badge badge-green">Ready to Apply</span>
        <?php elseif ($atsScore >= 60): ?>
          <span class="badge badge-amber">Some Improvements Needed</span>
        <?php else: ?>
          <span class="badge badge-rose">Needs Major Work</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Score Bars -->
  <div style="border-top:1px solid var(--border);padding-top:16px">
    <div style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:14px">Score Breakdown</div>
    <?php foreach ($scoreBreakdown as $label => [$val, $max, $icon]): ?>
    <div style="margin-bottom:10px">
      <div style="display:flex;justify-content:space-between;margin-bottom:4px">
        <span style="font-size:13px;color:var(--text)"><i class="fa-solid <?= $icon ?>" style="width:14px;color:var(--accent)"></i> <?= $label ?></span>
        <span style="font-size:12px;font-weight:600;color:<?= $val>=$max*0.7?'#10b981':($val>=$max*0.4?'#f59e0b':'#ef4444') ?>"><?= $val ?>/<?= $max ?></span>
      </div>
      <div style="height:7px;background:rgba(255,255,255,0.07);border-radius:99px;overflow:hidden">
        <div style="height:100%;width:<?= round($val/$max*100) ?>%;background:<?= $val>=$max*0.7?'#10b981':($val>=$max*0.4?'#f59e0b':'#ef4444') ?>;border-radius:99px;transition:width 1s"></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Keywords -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
  <?php if (!empty($matched)): ?>
  <div style="background:rgba(16,185,129,0.07);border:1px solid rgba(16,185,129,0.2);border-radius:12px;padding:16px">
    <div style="font-size:11px;font-weight:700;color:#10b981;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px"><i class="fa-solid fa-check-circle"></i> Matched Keywords (<?= count($matched) ?>)</div>
    <div style="display:flex;flex-wrap:wrap;gap:5px">
      <?php foreach (array_slice($matched,0,16) as $kw): ?>
      <span style="background:rgba(16,185,129,0.15);color:#10b981;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:500"><?= htmlspecialchars(trim($kw)) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php if (!empty($missing)): ?>
  <div style="background:rgba(239,68,68,0.07);border:1px solid rgba(239,68,68,0.2);border-radius:12px;padding:16px">
    <div style="font-size:11px;font-weight:700;color:#ef4444;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px"><i class="fa-solid fa-xmark-circle"></i> Missing Keywords (<?= count($missing) ?>)</div>
    <div style="display:flex;flex-wrap:wrap;gap:5px">
      <?php foreach ($missing as $kw): ?>
      <span style="background:rgba(239,68,68,0.1);color:#f87171;padding:3px 9px;border-radius:20px;font-size:11px"><?= htmlspecialchars(trim($kw)) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Recommendations -->
<?php if (!empty($recs)): ?>
<div style="background:rgba(99,102,241,0.07);border:1px solid rgba(99,102,241,0.2);border-radius:12px;padding:16px;margin-bottom:16px">
  <div style="font-size:11px;font-weight:700;color:#818cf8;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px"><i class="fa-solid fa-lightbulb"></i> Recommendations to Improve</div>
  <?php foreach ($recs as $i => $rec): ?>
  <div style="display:flex;gap:8px;margin-bottom:8px;font-size:13px;color:var(--text-muted)">
    <span style="background:rgba(99,102,241,0.2);color:#818cf8;min-width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0"><?= $i+1 ?></span>
    <span><?= htmlspecialchars($rec) ?></span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<a href="print_resume_scan.php?scan_id=<?= (int)($result['scan_id'] ?? 0) ?>" class="btn btn-secondary w-100" target="_blank">
  <i class="fa-solid fa-print"></i> Print / Export Report
</a>

<?php else: ?>
<!-- Placeholder -->
<div class="table-card">
  <div style="text-align:center;padding:60px">
    <div style="font-size:64px;margin-bottom:16px">📄</div>
    <h3 style="color:var(--text);margin-bottom:8px">Upload a Resume to Scan</h3>
    <p style="color:var(--text-muted);font-size:14px">Fill in the form and upload/paste a resume to get a detailed ATS compatibility report with keyword analysis and improvement suggestions.</p>
    <div style="display:flex;justify-content:center;gap:12px;margin-top:20px;flex-wrap:wrap">
      <span class="badge badge-green">Contact Info</span>
      <span class="badge badge-blue">Keyword Match</span>
      <span class="badge badge-amber">Format Check</span>
      <span class="badge badge-violet">Experience</span>
      <span class="badge badge-rose">Recommendations</span>
    </div>
  </div>
</div>
<?php endif; ?>
</div>
</div>

<!-- History Table -->
<div class="table-card" style="margin-top:24px">
  <div class="table-header"><h3 class="table-title"><i class="fa-solid fa-history"></i> Recent Scans</h3></div>
  <?php if (empty($scans)): ?>
    <div style="text-align:center;padding:40px;color:var(--text-muted)">No scans yet.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Candidate</th><th>Position</th><th>ATS Score</th><th>Contact</th><th>Keywords</th><th>Format</th><th>Scanned</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($scans as $s): ?>
        <tr>
          <td><strong><?= htmlspecialchars($s['cname'] ?: $s['candidate_name_free'] ?: 'Unknown') ?></strong></td>
          <td style="font-size:13px"><?= htmlspecialchars($s['position_applied'] ?? '—') ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <span class="badge badge-<?= getScoreColor($s['ats_score']) ?>"><?= $s['ats_score'] ?>%</span>
              <div style="flex:1;height:5px;background:rgba(255,255,255,0.07);border-radius:99px;overflow:hidden;width:50px">
                <div style="height:100%;width:<?= $s['ats_score'] ?>%;background:<?= $s['ats_score']>=80?'#10b981':($s['ats_score']>=60?'#f59e0b':'#ef4444') ?>;border-radius:99px"></div>
              </div>
            </div>
          </td>
          <td><span class="badge badge-<?= getScoreColor($s['contact_score']*7) ?>"><?= $s['contact_score'] ?>/15</span></td>
          <td><span class="badge badge-<?= getScoreColor($s['keyword_score']*4) ?>"><?= $s['keyword_score'] ?>/25</span></td>
          <td><span class="badge badge-<?= getScoreColor($s['format_score']*7) ?>"><?= $s['format_score'] ?>/15</span></td>
          <td style="font-size:12px;color:var(--text-muted)"><?= date('d M Y', strtotime($s['scanned_at'])) ?></td>
          <td><a href="print_resume_scan.php?scan_id=<?= (int)$s['id'] ?>" class="btn btn-xs btn-secondary" target="_blank"><i class="fa-solid fa-print"></i></a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<script>
const dz = document.getElementById('dropzone');
dz.addEventListener('dragover', e=>{ e.preventDefault(); dz.style.borderColor='var(--accent)'; dz.style.background='rgba(99,102,241,0.07)'; });
dz.addEventListener('dragleave', ()=>{ dz.style.borderColor='var(--border)'; dz.style.background='rgba(255,255,255,0.02)'; });
dz.addEventListener('drop', e=>{ e.preventDefault(); dz.style.borderColor='var(--border)'; const files=e.dataTransfer.files; if(files.length){ document.getElementById('resumeFile').files=files; showFileName({files}); }});
function showFileName(inp) {
  const f = inp.files && inp.files[0];
  const fn = document.getElementById('fileName');
  if (f) { fn.style.display='block'; fn.textContent='📎 ' + f.name + ' (' + (f.size/1024).toFixed(1) + ' KB)'; }
}
</script>
<?php renderFooter(); ?>
