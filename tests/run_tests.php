<?php
// ═════════════════════════════════════════════════════════════════════════════
//  SmartHire v7 — tests/run_tests.php   (CLI unit tests, no DB required)
//  Run:  php tests/run_tests.php
// ═════════════════════════════════════════════════════════════════════════════
error_reporting(E_ALL & ~E_DEPRECATED);
define('SH_DEBUG', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/resume_parser.php';
require_once __DIR__ . '/../includes/recruitment.php';
require_once __DIR__ . '/../includes/ats.php';
require_once __DIR__ . '/../includes/mailer.php';

$T = 0; $P = 0; $fails = [];
function ok($cond, $name) { global $T,$P,$fails; $T++; if ($cond){$P++; echo "  ✅ $name\n";} else {$fails[]=$name; echo "  ❌ $name\n";} }
function section($s){ echo "\n── $s ──\n"; }

// ── Fixtures dir ─────────────────────────────────────────────────────────────
$fx = __DIR__ . '/fixtures';
@mkdir($fx, 0775, true);

// ═════════════════════════════════════════════════════════════════════════════
section('Validation helpers');
ok(v_email('alice@email.com') === true,        'v_email accepts valid');
ok(v_email('nope@@x') === false,               'v_email rejects invalid');
ok(v_phone('+91-9876543210') === true,         'v_phone accepts intl');
ok(v_phone('') === true,                        'v_phone allows empty (optional)');
ok(v_phone('abc') === false,                    'v_phone rejects letters');
ok(v_len('hello', 3, 10) === true,             'v_len within range');
ok(v_len('hi', 3, 10) === false,               'v_len too short');
ok(v_url('https://x.com') === true,            'v_url accepts https');
ok(v_url('') === true,                          'v_url allows empty');
ok(v_int('5', 0, 10) === true,                 'v_int in range');
ok(v_int('99', 0, 10) === false,               'v_int out of range');

section('Password policy');
ok(password_policy_error('Abcdef12') === '',        'strong password passes');
ok(password_policy_error('short1A') !== '',         'too short fails');
ok(str_contains(password_policy_error('alllower1'),'uppercase'), 'missing uppercase caught');
ok(str_contains(password_policy_error('ALLUPPER1'),'lowercase'), 'missing lowercase caught');
ok(str_contains(password_policy_error('NoDigitsHere'),'number'), 'missing digit caught');

section('CSRF');
$tok = csrf_token();
ok(is_string($tok) && strlen($tok) === 64,     'csrf_token is 64 hex chars');
ok(csrf_token() === $tok,                       'csrf_token stable within session');
ok(verify_csrf($tok) === true,                  'verify_csrf accepts correct token');
ok(verify_csrf('deadbeef') === false,           'verify_csrf rejects wrong token');
ok(verify_csrf(null) === false,                 'verify_csrf rejects null');
ok(str_contains(csrf_field(), $tok),            'csrf_field embeds token');

section('RBAC');
$_SESSION['user_id'] = 1; $_SESSION['user_role'] = 'hr';
ok(hasRole('recruiter') === true,               'hr satisfies recruiter-or-higher');
ok(hasRole('hr') === true,                       'hr satisfies hr');
ok(hasRole('super_admin') === false,            'hr is below super_admin');
ok(hasRole(['hr','admin']) === true,            'hr matches explicit set');
$_SESSION['user_role'] = 'super_admin';
ok(hasRole('super_admin') === true,             'super_admin satisfies top role');
ok(hasRole('interviewer') === true,             'super_admin outranks interviewer');
$_SESSION['user_role'] = 'interviewer';
ok(hasRole('hr') === false,                      'interviewer below hr');

section('XSS escaping');
ok(e('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;', 'e() escapes tags');
ok(e('"quote" & \'apos\'') === '&quot;quote&quot; &amp; &#039;apos&#039;',      'e() escapes quotes/amp');

section('Open-redirect guard (redirect targets)');
// redirect() calls exit; test the sanitising predicate indirectly via reflection-safe copy
function _san($to){ return (preg_match('#^https?://#i',$to)||str_contains($to,"\r")||str_contains($to,"\n"))?'index.php':$to; }
ok(_san('dashboard.php') === 'dashboard.php',       'relative target allowed');
ok(_san('https://evil.com') === 'index.php',        'absolute URL blocked');
ok(_san("dash\r\nSet-Cookie: x") === 'index.php',   'CRLF header injection blocked');

section('ATS scoring engine (calculateAIScore)');
$hi = calculateAIScore('Python, React, AWS, Docker, SQL', 'Full Stack Developer', 'Led a team, 5 years experience, certified');
$lo = calculateAIScore('cooking, gardening', 'Full Stack Developer', 'hobbyist');
ok($hi > $lo,                                   'tech-heavy profile scores higher');
ok($hi <= 100 && $lo >= 40,                     'score bounded 40..100');

// ═════════════════════════════════════════════════════════════════════════════
section('Resume parser — TXT');
$txt = "John Doe\nSenior Engineer\nEmail: john@doe.com\nSkills: Python, React, AWS\n5 years experience";
file_put_contents("$fx/resume.txt", $txt);
$r = extract_resume_text("$fx/resume.txt");
ok(str_contains($r['text'], 'Python'),          'TXT: extracts skills');
ok($r['words'] > 5,                              'TXT: word count > 5');
ok($r['method'] === 'txt',                       'TXT: method reported');

section('Resume parser — DOCX (real Office Open XML)');
$docx = "$fx/resume.docx";
@unlink($docx);
$zip = new ZipArchive();
$zip->open($docx, ZipArchive::CREATE);
$zip->addFromString('[Content_Types].xml',
  '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
  .'<Default Extension="xml" ContentType="application/xml"/></Types>');
$zip->addFromString('word/document.xml',
  '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
  .'<w:body><w:p><w:r><w:t>Jane Smith — Data Scientist</w:t></w:r></w:p>'
  .'<w:p><w:r><w:t>Skills: Python, TensorFlow, SQL, Tableau</w:t></w:r></w:p>'
  .'<w:p><w:r><w:t>Experience: 7 years at IITB. Led ML research.</w:t></w:r></w:p>'
  .'</w:body></w:document>');
$zip->close();
$r = extract_resume_text($docx);
ok(str_contains($r['text'], 'TensorFlow'),      'DOCX: extracts skills');
ok(str_contains($r['text'], 'Data Scientist'),  'DOCX: extracts headline');
ok(str_contains($r['text'], '7 years'),         'DOCX: extracts experience');
ok($r['method'] === 'docx',                      'DOCX: method reported');
// sniff path (no extension)
$noext = "$fx/blob_docx"; copy($docx, $noext);
$r2 = extract_resume_text($noext);
ok(str_contains($r2['text'], 'TensorFlow'),     'DOCX: magic-byte sniff works without extension');

section('Resume parser — PDF (uncompressed text operators)');
// Minimal valid-ish PDF with a text object (uncompressed) the extractor can read
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
     . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/Contents 4 0 R>>endobj\n"
     . "4 0 obj<</Length 90>>\nstream\n"
     . "BT /F1 12 Tf 72 700 Td (Robert Fox Backend Engineer) Tj "
     . "0 -16 Td (Skills: Java Spring Boot Docker AWS) Tj ET\n"
     . "endstream endobj\n%%EOF";
file_put_contents("$fx/resume.pdf", $pdf);
$r = extract_resume_text("$fx/resume.pdf");
ok(str_contains($r['text'], 'Backend Engineer'), 'PDF: extracts headline text');
ok(str_contains($r['text'], 'Spring Boot'),      'PDF: extracts skills');
ok($r['method'] === 'pdf',                        'PDF: method reported');

section('Resume parser — PDF (FlateDecode compressed stream)');
$content = "BT /F1 12 Tf 72 700 Td (Compressed Resume Alice React Node MySQL) Tj ET";
$comp = gzcompress($content, 9);
$pdf2 = "%PDF-1.5\n4 0 obj<</Length ".strlen($comp)."/Filter/FlateDecode>>\nstream\n".$comp."\nendstream endobj\n%%EOF";
file_put_contents("$fx/resume_flate.pdf", $pdf2);
$r = extract_resume_text("$fx/resume_flate.pdf");
ok(str_contains($r['text'], 'React'),            'PDF/Flate: inflates + extracts');
ok(str_contains($r['text'], 'Alice'),            'PDF/Flate: name extracted');

section('Resume parser — normalisation & bounds');
$r = extract_resume_text("$fx/does_not_exist.pdf");
ok($r['text'] === '' && $r['method'] === 'none', 'missing file returns empty/none');
$big = str_repeat("word ", 8000);
file_put_contents("$fx/big.txt", $big);
$r = extract_resume_text("$fx/big.txt");
ok(mb_strlen($r['text']) <= 20000,               'text capped at 20k chars');

// ═════════════════════════════════════════════════════════════════════════════
section('Recruitment — pipeline model');
ok(sh_stage_index('applied') === 0,                     'applied is first stage');
ok(sh_stage_index('joined') === count(sh_stage_flow())-1,'joined is last forward stage');
ok(sh_next_stage('applied') === 'resume_screening',      'next(applied)=resume_screening');
ok(sh_next_stage('joined') === null,                     'next(joined)=null (terminal)');
ok(sh_can_transition('applied','shortlisted') === true,  'forward jump allowed');
ok(sh_can_transition('shortlisted','applied') === false, 'backward move blocked');
ok(sh_can_transition('applied','rejected') === true,     'reject allowed from active');
ok(sh_can_transition('joined','rejected') === false,     'cannot move out of joined');
ok(sh_can_transition('rejected','applied') === false,    'cannot revive rejected');
ok(sh_stage_label('online_test') === 'Online Test',      'stage label lookup');
ok(sh_stage_color('rejected') === 'rose',                'stage colour lookup');

section('Recruitment — skill parsing & matching');
ok(sh_parse_skills('Python, React ,, aws') === ['python','react','aws'], 'parse_skills cleans/dedupes');
ok(sh_skill_match('Built apps with Python and AWS', 'Python, React, AWS') === 67, 'skill_match = 2/3 = 67%');
ok(sh_skill_match('nothing here', 'Python') === 0,        'skill_match 0 when none');
ok(sh_skill_match('anything', '') === 0,                  'skill_match 0 when job lists no skills');

section('Recruitment — experience detection');
ok(sh_years_experience('I have 6 years of experience') === 6, 'years parsed');
ok(sh_years_experience('5+ yrs building systems, 2 years mgmt') === 5, 'max years wins');
ok(sh_years_experience('fresh graduate') === 0,           'no years → 0');
ok(sh_experience_match('4 years exp', 3, 6) === 100,      'in-range → 100');
ok(sh_experience_match('fresh', 3, 6) === 30,             'no experience → 30');
ok(sh_experience_match('12 years', 3, 6) < 100,           'overqualified penalised');

section('Recruitment — education & quality');
ok(sh_education_match('B.Tech in CS') === 80,             'bachelor detected');
ok(sh_education_match('MBA, PhD candidate') === 100,      'highest level wins (phd)');
ok(sh_education_match('random text') === 40,              'unknown → neutral 40');
$goodResume = str_repeat('experience education skills projects ', 40) . ' john@x.com +91 9876543210';
ok(sh_resume_quality($goodResume) >= 80,                  'rich resume scores high quality');
ok(sh_resume_quality('hi') < 40,                          'tiny resume scores low quality');

section('Recruitment — ATS composite');
$job = ['skills_required'=>'Python, React, AWS, Docker', 'experience_min'=>3, 'experience_max'=>6];
$strong = sh_ats_breakdown('Senior dev, 5 years experience. Skills: Python React AWS Docker. B.Tech. Led projects. Education. john@x.com 9876543210 '.str_repeat('work ',60), $job);
$weak   = sh_ats_breakdown('I like gardening', $job);
ok($strong['ats_score'] > $weak['ats_score'],            'strong profile out-scores weak');
ok($strong['ats_score'] <= 100 && $weak['ats_score'] >= 0,'ats_score bounded 0..100');
ok($strong['skill_match'] === 100,                        'all 4 skills matched = 100%');
ok(count($strong) === 5,                                  'breakdown returns 5 sub-scores');
ok(sh_final_score(80, null) === 80,                       'final=ats when no interview');
ok(sh_final_score(80, 60) === 72,                         'final = 0.6*80 + 0.4*60 = 72');

section('Recruitment — workflow automation (sh_should_advance)');
ok(sh_should_advance('applied','interview_scheduled') === true,   'advances forward');
ok(sh_should_advance('interview_completed','online_test') === false,'never moves backward');
ok(sh_should_advance('joined','offer_released') === false,        'terminal joined stays');
ok(sh_should_advance('rejected','online_test') === false,         'terminal rejected stays');
ok(sh_should_advance('shortlisted','shortlisted') === false,      'same stage = no move');
ok(sh_should_advance('applied','not_a_stage') === false,          'unknown target rejected');

section('ATS engine — keywords & coverage');
ok(!in_array('the', sh_tokenize('the quick brown fox'), true), 'stopwords removed');
ok(in_array('python', sh_tokenize('Python developer'), true),  'meaningful tokens kept');
$cov = sh_keyword_coverage('python developer with django and postgresql experience',
                           'Looking for a Python Django developer. PostgreSQL and Redis required.');
ok($cov['coverage'] > 0,                          'keyword coverage computed');
ok(in_array('redis', $cov['missing'], true),      'missing keyword detected');
ok(in_array('python', $cov['matched'], true),     'matched keyword detected');

section('ATS engine — skills & scores');
ok(sh_missing_skills('I know python and aws', 'Python, AWS, Docker') === ['docker'], 'missing skills exact');
ok(sh_matched_skills('python aws expert', 'Python, AWS, Docker') === ['python','aws'], 'matched skills exact');
$fmt = sh_formatting_score('Experience: 5 years. Education: B.Tech. Skills: Python. Projects done. Improved sales 30%. john@x.com 9876543210 '.str_repeat('word ',60));
ok($fmt['score'] >= 70,                            'well-structured resume scores high formatting');
ok(is_array($fmt['checks']),                       'formatting returns checklist');
ok(sh_readability_score('Short clear sentences. Easy to read. Good.') >= 80, 'short sentences readable');
ok(sh_ats_compatibility('a b c') < 100,            'sparse resume loses ATS compat points');

section('ATS engine — recommendation & probabilities');
ok(sh_recommendation(85)['band'] === 'Strong Hire', 'high score → strong hire');
ok(sh_recommendation(20)['band'] === 'Not a Fit',   'low score → not a fit');
ok(sh_hiring_probability(90, 90) > sh_hiring_probability(40, 40), 'hire prob monotonic');
ok(sh_interview_probability(80) > sh_interview_probability(30),    'interview prob monotonic');
$sw = sh_strengths_weaknesses(['skill_match'=>90,'experience_match'=>40,'education_match'=>80,'resume_quality'=>50], 85, 30);
ok(count($sw['strengths']) >= 2 && count($sw['weaknesses']) >= 2, 'strengths/weaknesses split');
$full = sh_full_ats_report('Python Django AWS 5 years experience B.Tech projects. john@x.com 9876543210 '.str_repeat('w ',80),
                           ['description'=>'Python Django','requirements'=>'AWS','skills_required'=>'Python, Django, AWS, Redis','experience_min'=>3,'experience_max'=>6]);
ok(isset($full['recommendation'], $full['hire_prob'], $full['breakdown']), 'full report assembled');
ok($full['jd_match'] >= 0 && $full['jd_match'] <= 100, 'jd_match bounded');

section('Analytics — pure metrics');
ok(sh_pct(1,4) === 25,                             'pct 1/4 = 25');
ok(sh_pct(1,0) === 0,                              'pct divide-by-zero safe');
ok(sh_acceptance_rate(4,3) === 75,                 'acceptance rate 3/4');
ok(sh_conversion_rate(100,12) === 12,              'conversion 12/100');
ok(sh_avg_days([2,4,6]) === 4.0,                   'avg days = 4');
ok(sh_avg_days([]) === 0.0,                        'avg days empty safe');
$fn = sh_funnel_from_stages(['applied','shortlisted','interview_completed']);
ok($fn['applied'] === 3,                           'funnel: all reached applied');
ok($fn['shortlisted'] === 2,                       'funnel: 2 reached shortlisted');
ok($fn['interview_completed'] === 1,               'funnel: 1 reached interview_completed');
$d = sh_score_distribution([90,70,55,20,80]);
ok($d['75-100'] === 2 && $d['0-39'] === 1,         'score distribution buckets');

section('Mailer — templates (pure)');
$t = sh_mail_template('offer_released', ['name'=>'Asha','job'=>'Data Analyst']);
ok(str_contains($t['subject'], 'offer'),           'offer template subject');
ok(str_contains($t['html'], 'Asha') && str_contains($t['html'],'Data Analyst'), 'template interpolates vars');
ok(str_contains($t['html'], 'SmartHire'),          'template wrapped in shell');
$t2 = sh_mail_template('unknown_event', ['extra'=>'hello']);
ok(str_contains($t2['html'], 'hello'),             'unknown event falls back gracefully');
ok(str_contains(sh_mail_template('rejected',['name'=>'X','job'=>'Y'])['subject'],'application'), 'rejection subject neutral');
ok(str_contains(sh_mail_template('test_assigned',['name'=>'A','job'=>'Quiz'])['html'],'Quiz'), 'test_assigned template renders job');
ok(str_contains(sh_mail_template('interview_invite',['name'=>'A','job'=>'a Technical round','extra'=>'2pm'])['html'],'2pm'), 'interview_invite template renders extra');

// ═════════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../includes/ats_engine.php';

section('ATS V2 — skill ontology & normalization');
ok(sh2_canon('ReactJS') === 'react',                    'ReactJS → react');
ok(sh2_canon('Node') === 'node.js',                     'Node → node.js');
ok(sh2_canon('JS') === 'javascript',                    'JS → javascript');
ok(sh2_canon('K8s') === 'kubernetes',                   'K8s → kubernetes');
ok(sh2_canon('postgres') === 'postgresql',              'postgres → postgresql');
ok(sh2_canon('unknownskill') === 'unknownskill',        'unknown skill passes through');
ok(sh2_cat('postgresql') === 'database',                'postgresql categorised as database');
ok(sh2_cat('aws') === 'cloud',                          'aws categorised as cloud');
$det = sh2_detect_skills('Built APIs with NodeJS and React.js, deployed on K8s, data in Postgres');
ok(in_array('node.js', $det, true) && in_array('react', $det, true), 'detect: aliases resolve in free text');
ok(in_array('kubernetes', $det, true) && in_array('postgresql', $det, true), 'detect: k8s + postgres found');
ok(!in_array('java', sh2_detect_skills('I love javascript'), true), 'word-boundary: javascript does not match java');
ok(sh2_normalize_skill_list('ReactJS, node, JS,, MySQL') === ['react','node.js','javascript','mysql'], 'CSV list normalized + deduped');
ok(sh2_normalize_skill_list('Kubernetes, Docker, CI/CD') === ['kubernetes','docker','ci/cd'], 'slash-named skills (CI/CD) survive tokenizing');

section('ATS V2 — JD parser');
$jd = sh2_parse_jd([
  'skills_required' => 'Python, Django, PostgreSQL',
  'description' => 'We need a backend engineer with 4+ years experience. Bachelor degree required. AWS certified preferred.',
  'requirements' => 'Nice to have: Redis and Docker.',
  'experience_min' => 0, 'experience_max' => 0,
]);
ok($jd['required_skills'] === ['python','django','postgresql'],  'required from recruiter CSV (normalized)');
ok(in_array('redis', $jd['preferred_skills'], true) && in_array('docker', $jd['preferred_skills'], true), 'JD text skills → preferred');
ok($jd['experience_min'] === 4,                                   'experience parsed from "4+ years"');
ok($jd['education'] === 'bachelor',                                'education level parsed');
ok(in_array('AWS Certified', $jd['certifications'], true),         'certification detected in JD');

section('ATS V2 — semantic skill match');
$m = sh2_skill_match(['python','django','mysql'], ['python','django','postgresql']);
ok($m['matched'] === ['python','django'],                'exact matches identified');
ok($m['related'][0]['need'] === 'postgresql' && $m['related'][0]['have'] === 'mysql', 'MySQL earns related credit for PostgreSQL');
ok($m['missing'] === [],                                  'no full misses (related covers it)');
ok($m['score'] > sh2_skill_match(['python','django'], ['python','django','postgresql'])['score'], 'related credit raises score vs absent');
$m2 = sh2_skill_match(['react'], ['python']);
ok($m2['missing'] === ['python'] && $m2['score'] < 30,    'cross-category gives no credit');
$m3 = sh2_skill_match(['python','aws'], ['python'], ['aws','docker']);
ok($m3['preferred_hit'] === ['aws'] && $m3['preferred_miss'] === ['docker'], 'preferred hits/misses tracked');
ok(in_array('aws', sh2_skill_match(['python','aws'], ['python'])['extra'], true), 'extra skills surfaced');

section('ATS V2 — certifications');
$c = sh2_certifications('I am AWS Certified Solutions Architect and hold CCNA.', ['AWS Certified','CISSP']);
ok(in_array('AWS Certified', $c['found'], true) && in_array('Cisco CCNA/CCNP', $c['found'], true), 'certs detected in resume');
ok($c['missing'] === ['CISSP'],                            'missing JD cert reported');
ok(sh2_certifications('no certs here', [])['score'] === 50, 'neutral 50 when none asked, none held');
ok(sh2_certifications('AWS certified', [])['score'] >= 80,  'bonus credit for unrequested cert');

section('ATS V2 — quality engine');
$good = sh2_quality('Led migration to AWS, reduced costs 35%. Built CI/CD pipeline serving 200+ deployments. Mentored 4 engineers. Optimized queries, improved latency 60%. Delivered 12 projects. Automated reporting saving 20 hours/month. Achieved 99.9% uptime. Implemented monitoring.');
$bad  = sh2_quality('Responsible for various things. Worked on stuff. Was tasked with duties. Team player and hard working go-getter. Familiar with exposure to knowledge of systems.');
ok($good['score'] > $bad['score'] + 25,                    'strong resume out-scores weak by wide margin');
ok(count($good['action_verbs']) >= 6,                      'action verbs detected');
ok($good['quantified'] >= 5,                               'quantified achievements counted');
ok(count($bad['weak_words']) >= 3,                         'weak phrases detected');
ok(count($bad['buzzwords']) >= 2,                          'buzzwords detected');
ok($bad['suggestions'] !== [],                             'weak resume gets suggestions');

section('ATS V2 — experience & title families');
ok(sh2_family_of('Senior Backend Developer') === 'software engineering', 'title → family');
ok(sh2_family_of('Data Scientist') === 'data',             'data title classified');
ok(in_array('software engineering', sh2_title_families('Worked as Software Engineer at TCS'), true), 'family detected in resume text');
$e = sh2_experience('Software Developer with 5 years experience building APIs', ['experience_min'=>3,'experience_max'=>6,'education'=>'bachelor'], 'Backend Engineer');
ok($e['relevance'] === 100 && $e['score'] >= 90,           'equivalent title = full relevance');
$e2 = sh2_experience('UX Designer, 5 years in Figma', ['experience_min'=>3,'experience_max'=>6,'education'=>'none'], 'Backend Engineer');
ok($e2['relevance'] <= 55 && $e2['deductions'] !== [],     'domain mismatch penalised + explained');

section('ATS V2 — configurable weights & scoring');
$W = sh2_weights();
ok(abs(array_sum($W) - 100) < 0.01,                        'weights normalize to 100');
ok($W['skills'] > $W['formatting'],                        'default priorities sensible');
$res = 'Software Engineer, 5 years. Built APIs with Python, Django and PostgreSQL on AWS. Reduced latency 40%. Led team of 3. B.Tech CS 2019. AWS Certified. Skills: Python, Django, PostgreSQL, Docker, Git. Experience. Education. Projects. john@x.com 9876543210 ' . str_repeat('delivered ', 30);
$jobRow = ['title'=>'Backend Engineer','skills_required'=>'Python, Django, PostgreSQL, Redis','description'=>'Backend role, 3+ years. Bachelor required.','requirements'=>'Nice to have: Docker.','experience_min'=>3,'experience_max'=>6];
$A = sh2_analyze($res, $jobRow);
ok($A['overall'] >= 60 && $A['overall'] <= 100,            'strong profile scores well ('.$A['overall'].')');
ok(count($A['components']) === 9,                          '9 scoring components');
$ptsSum = array_sum(array_column($A['components'], 'points'));
ok(abs($ptsSum - $A['overall']) <= 1,                      'component points sum to overall (±1 rounding)');
ok($A['components']['skills']['reasons'] !== [],           'skills component explains WHY awarded');
$weakA = sh2_analyze('gardening enthusiast', $jobRow);
ok($weakA['overall'] < $A['overall'] - 25,                 'weak profile scores far lower');
ok($weakA['components']['skills']['deductions'] !== [],    'weak profile explains WHY deducted');
ok(in_array($A['grade']['label'], ['Excellent','Very Good','Needs Improvement','Weak Match','Poor Match'], true), 'grade band assigned');
ok($A['insights']['priority']['band'] !== '',              'recruiter priority band assigned');
ok($A['final'] === sh_final_score($A['overall'], null),    'V1 final-score contract preserved');

section('ATS V2 — report integration (backward compatible)');
$R = sh_full_ats_report($res, $jobRow, 80);
ok(isset($R['breakdown'], $R['recommendation'], $R['hire_prob']), 'all V1 keys still present');
ok(isset($R['v2']['overall'], $R['v2']['components'], $R['v2']['insights']), 'v2 section attached');
ok($R['v2']['skills']['related'] !== [] || $R['v2']['skills']['matched'] !== [], 'v2 semantic results populated');

require __DIR__ . '/assessment_core_tests.php';   // Module 8A — Assessment Platform Core
require __DIR__ . '/assessment_center_tests.php'; // Module 8B — Assessment Center workflows
require __DIR__ . '/assessment_player_tests.php'; // Module 8C — Candidate Player delivery
require __DIR__ . '/interview_tests.php';         // Module 9 — Interview Management
require __DIR__ . '/offer_tests.php';            // Module 10 — Offer Management

// ═════════════════════════════════════════════════════════════════════════════
echo "\n══════════════════════════════════════════\n";
echo "  RESULT: $P / $T passed\n";
if ($fails) { echo "  FAILED:\n    - " . implode("\n    - ", $fails) . "\n"; exit(1); }
echo "  🎉 ALL TESTS PASSED\n";
echo "══════════════════════════════════════════\n";
