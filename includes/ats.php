<?php
// ═════════════════════════════════════════════════════════════════════════════
//  SmartHire v7 — includes/ats.php   (Build 4 · Enterprise Edition)
//  Jobscan/Resume-Worded-style resume analysis. All functions are PURE (no DB)
//  and unit-tested. Powers ats_report.php. Requires includes/recruitment.php
//  for sh_parse_skills().
// ═════════════════════════════════════════════════════════════════════════════

/** Common English + resume stopwords excluded from keyword analysis. */
const SH_STOPWORDS = [
    'the','and','for','are','but','with','you','your','our','their','has','have','had',
    'was','were','will','would','can','could','should','from','that','this','these','those',
    'a','an','of','to','in','on','at','by','as','is','it','or','be','we','i','my','me',
    'work','working','worked','experience','years','year','role','team','using','used','use',
    'responsible','including','various','strong','good','excellent','ability','skills','skill',
    'knowledge','proficient','familiar','etc','also','across','within','via','per','into',
];

/** Tokenise text into meaningful lowercase word tokens (keeps tech tokens like c++, ci/cd). */
function sh_tokenize(string $text): array {
    $text = strtolower($text);
    preg_match_all('/[a-z][a-z0-9+#.\/-]{1,}/', $text, $m);
    $out = [];
    foreach ($m[0] as $w) {
        $w = trim($w, './-');
        if (mb_strlen($w) < 3) continue;
        if (in_array($w, SH_STOPWORDS, true)) continue;
        $out[] = $w;
    }
    return $out;
}

/** Frequency-ranked keywords from text → [word => count], highest first. */
function sh_keyword_freq(string $text, int $limit = 25): array {
    $counts = array_count_values(sh_tokenize($text));
    arsort($counts);
    return array_slice($counts, 0, $limit, true);
}

/**
 * Keyword coverage of a resume against a job's text (description+requirements+skills).
 * Returns matched/missing keyword lists + coverage %.
 */
function sh_keyword_coverage(string $resumeText, string $jobText): array {
    $jobKws    = array_keys(sh_keyword_freq($jobText, 30));
    if (!$jobKws) return ['coverage' => 0, 'matched' => [], 'missing' => []];
    $resumeSet = array_flip(sh_tokenize($resumeText));
    $matched = $missing = [];
    foreach ($jobKws as $kw) {
        if (isset($resumeSet[$kw])) $matched[] = $kw; else $missing[] = $kw;
    }
    $cov = (int)round(count($matched) / count($jobKws) * 100);
    return ['coverage' => $cov, 'matched' => $matched, 'missing' => $missing];
}

/** Required skills from the job that are absent in the resume. */
function sh_missing_skills(string $resumeText, string $jobSkills): array {
    $hay = strtolower($resumeText);
    $missing = [];
    foreach (sh_parse_skills($jobSkills) as $sk) {
        if (!str_contains($hay, $sk)) $missing[] = $sk;
    }
    return $missing;
}

/** Matched skills (present in resume). */
function sh_matched_skills(string $resumeText, string $jobSkills): array {
    $hay = strtolower($resumeText);
    $hit = [];
    foreach (sh_parse_skills($jobSkills) as $sk) if (str_contains($hay, $sk)) $hit[] = $sk;
    return $hit;
}

/** Resume formatting score: sections, structure, contactability, length (0..100). */
function sh_formatting_score(string $text): array {
    $t = strtolower($text);
    $checks = [
        'Contact info present'   => (bool)preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/', $t) && (bool)preg_match('/\d[\d\s\-()]{7,}/', $t),
        'Experience section'     => str_contains($t, 'experience'),
        'Education section'      => str_contains($t, 'education'),
        'Skills section'         => str_contains($t, 'skill'),
        'Projects / achievements'=> str_contains($t, 'project') || str_contains($t, 'achiev'),
        'Quantified impact (numbers)' => (bool)preg_match('/\b\d+%|\b\d{2,}\b/', $t),
        'Reasonable length'      => (function($w){return $w>=180 && $w<=1200;})(str_word_count($t)),
    ];
    $passed = count(array_filter($checks));
    $score  = (int)round($passed / count($checks) * 100);
    return ['score' => $score, 'checks' => $checks];
}

/** Readability proxy from average sentence length (shorter = clearer) (0..100). */
function sh_readability_score(string $text): int {
    $sentences = max(1, preg_match_all('/[.!?]+/', $text));
    $words = max(1, str_word_count($text));
    $avg = $words / $sentences;                 // words per sentence
    if ($avg <= 12) return 90;
    if ($avg <= 18) return 80;
    if ($avg <= 25) return 65;
    if ($avg <= 32) return 50;
    return 35;
}

/** ATS compatibility: penalise things real ATS parsers choke on. (0..100) */
function sh_ats_compatibility(string $text): int {
    $score = 100;
    if (str_word_count($text) < 120) $score -= 30;                 // too sparse to parse
    if (!preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/', $text)) $score -= 15; // no email
    if (!preg_match('/\bskills?\b/i', $text)) $score -= 10;        // no skills header
    if (preg_match('/[│┃▪◦●]/u', $text)) $score -= 10;             // exotic bullets
    return max(0, min(100, $score));
}

/**
 * Strengths & weaknesses derived from the ATS sub-score breakdown + extras.
 * $b = sh_ats_breakdown(...), plus keyword coverage & formatting.
 */
function sh_strengths_weaknesses(array $b, int $keywordCoverage, int $formatting): array {
    $metrics = [
        'Skill match'      => $b['skill_match'],
        'Experience fit'   => $b['experience_match'],
        'Education'        => $b['education_match'],
        'Resume quality'   => $b['resume_quality'],
        'Keyword coverage' => $keywordCoverage,
        'Formatting'       => $formatting,
    ];
    $strengths = $weaknesses = [];
    foreach ($metrics as $label => $val) {
        if ($val >= 75)      $strengths[]  = ['label' => $label, 'value' => $val];
        elseif ($val < 55)   $weaknesses[] = ['label' => $label, 'value' => $val];
    }
    usort($strengths,  fn($a,$c) => $c['value'] <=> $a['value']);
    usort($weaknesses, fn($a,$c) => $a['value'] <=> $c['value']);
    return ['strengths' => $strengths, 'weaknesses' => $weaknesses];
}

/** Recruiter recommendation band from the final score. */
function sh_recommendation(int $finalScore): array {
    if ($finalScore >= 80) return ['band' => 'Strong Hire',   'color' => 'green',  'text' => 'Excellent match — prioritise for interview.'];
    if ($finalScore >= 65) return ['band' => 'Interview',     'color' => 'blue',   'text' => 'Good match — worth advancing to interview.'];
    if ($finalScore >= 50) return ['band' => 'Consider',      'color' => 'amber',  'text' => 'Partial match — review carefully against must-haves.'];
    if ($finalScore >= 35) return ['band' => 'Hold',          'color' => 'violet', 'text' => 'Weak match — keep as backup if pipeline is thin.'];
    return                        ['band' => 'Not a Fit',     'color' => 'rose',   'text' => 'Low match — likely not suited to this role.'];
}

/** Hiring probability (%) — blends final score with a mild logistic curve. */
function sh_hiring_probability(int $finalScore, ?int $interviewScore = null): int {
    $base = $finalScore;
    if ($interviewScore !== null) $base = (int)round($finalScore * 0.5 + $interviewScore * 0.5);
    // gentle S-curve so mid scores aren't over-optimistic
    $p = 1 / (1 + exp(-($base - 55) / 12));
    return (int)round($p * 100);
}

/** Interview probability (%) from the ATS score alone (pre-interview). */
function sh_interview_probability(int $atsScore): int {
    $p = 1 / (1 + exp(-($atsScore - 50) / 14));
    return (int)round($p * 100);
}

/** Actionable improvement suggestions for the candidate/recruiter notes. */
function sh_improvement_suggestions(array $b, array $missingSkills, array $missingKeywords, array $formatting): array {
    $s = [];
    if ($missingSkills) $s[] = 'Add missing required skills if the candidate has them: ' . implode(', ', array_slice($missingSkills, 0, 6)) . '.';
    if ($b['experience_match'] < 55) $s[] = 'Experience signal is weak — confirm years/seniority in a screening call.';
    if ($b['education_match'] < 55)  $s[] = 'Education level unclear from the resume — verify qualifications.';
    if (($formatting['checks']['Quantified impact (numbers)'] ?? true) === false) $s[] = 'Resume lacks quantified impact (metrics/numbers) — ask for measurable achievements.';
    if (($formatting['checks']['Contact info present'] ?? true) === false) $s[] = 'Contact details are missing or unparseable.';
    if ($missingKeywords) $s[] = 'Job keywords not found in resume: ' . implode(', ', array_slice($missingKeywords, 0, 8)) . '.';
    if (!$s) $s[] = 'Strong all-round match — no major gaps detected.';
    return $s;
}

/**
 * Full ATS report object for one application. Pure — pass in resume text + job row
 * (+ optional interview score). Returns everything ats_report.php renders.
 */
function sh_full_ats_report(string $resumeText, array $job, ?int $interviewScore = null): array {
    require_once __DIR__ . '/ats_engine.php';   // V2 layer (pure)
    $b        = sh_ats_breakdown($resumeText, $job);
    $jobText  = trim(($job['description'] ?? '') . ' ' . ($job['requirements'] ?? '') . ' ' . ($job['skills_required'] ?? ''));
    $cov      = sh_keyword_coverage($resumeText, $jobText);
    $fmt      = sh_formatting_score($resumeText);
    $missSk   = sh_missing_skills($resumeText, (string)($job['skills_required'] ?? ''));
    $matchSk  = sh_matched_skills($resumeText, (string)($job['skills_required'] ?? ''));
    $final    = sh_final_score($b['ats_score'], $interviewScore);
    $sw       = sh_strengths_weaknesses($b, $cov['coverage'], $fmt['score']);

    return [
        'breakdown'       => $b,
        'final_score'     => $final,
        'jd_match'        => $cov['coverage'],
        'keyword'         => $cov,
        'matched_skills'  => $matchSk,
        'missing_skills'  => $missSk,
        'formatting'      => $fmt,
        'readability'     => sh_readability_score($resumeText),
        'ats_compat'      => sh_ats_compatibility($resumeText),
        'strengths'       => $sw['strengths'],
        'weaknesses'      => $sw['weaknesses'],
        'recommendation'  => sh_recommendation($final),
        'hire_prob'       => sh_hiring_probability($final, $interviewScore),
        'interview_prob'  => sh_interview_probability($b['ats_score']),
        'suggestions'     => sh_improvement_suggestions($b, $missSk, $cov['missing'], $fmt),
        // ── ATS V2: semantic ontology matching, JD parsing, certifications,
        //    quality v2, configurable explainable scoring, recruiter insights ──
        'v2'              => sh2_analyze($resumeText, $job, $interviewScore),
    ];
}
