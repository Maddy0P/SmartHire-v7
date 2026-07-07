<?php
// ═════════════════════════════════════════════════════════════════════════════
//  SmartHire ATS V2 — includes/ats_engine.php
//  The V2 intelligence layer. ALL functions are PURE (no DB, no IO) and
//  unit-tested. Extends V1 (ats.php / recruitment.php) — does not replace it.
//
//  Engines: skill normalization (ontology) · JD parsing (required vs preferred)
//           semantic skill match (exact / alias / related credit) · certifications
//           quality v2 (action verbs, weak words, passive voice, buzzwords)
//           experience (years + title families) · configurable explainable scoring
//
//  Every component returns WHY: reasons[], deductions[], suggestions[].
// ═════════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/ats_ontology.php';

// ─────────────────────────────────────────────────────────────────────────────
//  CONFIGURATION — weights are NOT hardcoded in scoring logic.
//  Override in config.local.php:  define('SH_ATS_WEIGHTS_JSON', '{"skills":30,...}');
//  Weights are normalized to sum 100, so partial overrides stay valid.
// ─────────────────────────────────────────────────────────────────────────────
function sh2_weights(): array {
    $default = [
        'completeness'  => 8,    // resume sections & contactability
        'keywords'      => 18,   // JD keyword coverage (V1 engine)
        'skills'        => 26,   // semantic skill match (V2)
        'experience'    => 18,   // years + title-family relevance
        'education'     => 8,
        'certifications'=> 6,
        'quality'       => 10,   // action verbs / weak words / achievements
        'formatting'    => 4,
        'readability'   => 2,
    ];
    if (defined('SH_ATS_WEIGHTS_JSON')) {
        $o = json_decode((string)SH_ATS_WEIGHTS_JSON, true);
        if (is_array($o)) foreach ($o as $k => $v) if (isset($default[$k]) && is_numeric($v) && $v >= 0) $default[$k] = (float)$v;
    }
    $sum = array_sum($default) ?: 1;
    foreach ($default as $k => $v) $default[$k] = $v / $sum * 100;   // normalize to 100
    return $default;
}

// ─────────────────────────────────────────────────────────────────────────────
//  SKILL NORMALIZATION (ontology)
// ─────────────────────────────────────────────────────────────────────────────

/** Alias → canonical lookup table (built once). */
function sh2_alias_map(): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    foreach (SH_SKILLS as $canon => $def) {
        $map[$canon] = $canon;
        foreach ($def['alias'] as $a) $map[strtolower($a)] = $canon;
    }
    return $map;
}

/** Normalize one raw skill token → canonical name (or lowercase original if unknown). */
function sh2_canon(string $skill): string {
    $s = strtolower(trim($skill));
    return sh2_alias_map()[$s] ?? $s;
}

/** Category of a canonical skill ('' if unknown). */
function sh2_cat(string $canon): string { return SH_SKILLS[$canon]['cat'] ?? ''; }

/**
 * Detect every ontology skill present in free text (word-boundary aware,
 * tolerant of ./+/# in tech names). Returns unique canonical names.
 */
function sh2_detect_skills(string $text): array {
    $hay = ' ' . strtolower($text) . ' ';
    $found = [];
    foreach (sh2_alias_map() as $alias => $canon) {
        if (isset($found[$canon])) continue;
        $q = preg_quote($alias, '/');
        if (preg_match('/(?<![a-z0-9])' . $q . '(?![a-z0-9])/', $hay)) $found[$canon] = true;
    }
    return array_keys($found);
}

/** Parse an explicit comma-separated skill list → unique canonical names. */
function sh2_normalize_skill_list(string $csv): array {
    $out = [];
    // Split on commas/semicolons/pipes only — NOT '/', which is part of real
    // skill names (CI/CD, UI/UX, TCP/IP) present in the ontology.
    foreach (preg_split('/[,;|]+/', $csv) as $raw) {
        $raw = trim($raw);
        if ($raw === '') continue;
        $out[sh2_canon($raw)] = true;
    }
    return array_keys($out);
}

// ─────────────────────────────────────────────────────────────────────────────
//  JOB DESCRIPTION PARSER — required vs preferred, experience, education, certs
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Parse a job row (description, requirements, skills_required, experience_min/max)
 * into a structured requirement object. Recruiter's skills_required stays the
 * authoritative "required" list; JD text ADDS detected skills (preferred unless
 * they appear near required-language).
 */
function sh2_parse_jd(array $job): array {
    $desc = strtolower(trim(($job['description'] ?? '') . "\n" . ($job['requirements'] ?? '')));
    $required = sh2_normalize_skill_list((string)($job['skills_required'] ?? ''));

    // Split JD into required-ish vs preferred-ish zones by cue phrases.
    $prefZone = '';
    if (preg_match('/(nice to have|preferred|bonus|plus|good to have|desirable)(.*)$/s', $desc, $m)) $prefZone = $m[2];

    $detected  = sh2_detect_skills($desc);
    $preferred = [];
    foreach ($detected as $canon) {
        if (in_array($canon, $required, true)) continue;
        // If the skill appears only in the preferred zone → preferred; if in the
        // main body of a JD without being in skills_required, treat as preferred
        // too (recruiter list is authoritative for hard requirements).
        $preferred[] = $canon;
    }
    // Experience: prefer explicit job fields; fall back to "N+ years" in the text.
    $expMin = (int)($job['experience_min'] ?? 0);
    $expMax = (int)($job['experience_max'] ?? 0);
    if ($expMin === 0 && preg_match('/(\d{1,2})\s*\+?\s*(?:years?|yrs?)/', $desc, $m)) $expMin = (int)$m[1];

    // Education requirement detection
    $edu = 'none';
    if (preg_match('/\b(phd|doctorate)\b/', $desc)) $edu = 'phd';
    elseif (preg_match('/\b(master|m\.?tech|m\.?sc|mba|m\.?e\.|post ?graduate)\b/', $desc)) $edu = 'master';
    elseif (preg_match('/\b(bachelor|b\.?tech|b\.?e\.|b\.?sc|bca|b\.?com|graduate|degree)\b/', $desc)) $edu = 'bachelor';

    // Certifications mentioned in JD
    $certs = [];
    foreach (SH_CERTS as $canon => $aliases) {
        foreach ($aliases as $a) {
            if (str_contains($desc, $a)) { $certs[] = $canon; break; }
        }
    }

    return [
        'required_skills'  => $required,
        'preferred_skills' => array_values(array_unique($preferred)),
        'experience_min'   => $expMin,
        'experience_max'   => $expMax,
        'education'        => $edu,
        'certifications'   => array_values(array_unique($certs)),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
//  SEMANTIC SKILL MATCH — exact/alias = 1.0, same-category related = 0.5
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Match resume skills against required + preferred lists.
 * Returns per-skill tiers so every point is explainable:
 *   matched[]      — required skill present (exact or via alias)
 *   related[]      — required skill absent but a same-category skill exists
 *                    (['need' =>, 'have' =>, 'cat' =>])   → 50% credit
 *   missing[]      — required skill with no credit
 *   preferred_hit / preferred_miss — same for preferred (worth less)
 *   extra[]        — resume skills the job didn't ask for (recruiter insight)
 *   score          — 0..100 (required 85% of weight, preferred 15%)
 */
function sh2_skill_match(array $resumeSkills, array $required, array $preferred = []): array {
    $have = array_fill_keys($resumeSkills, true);
    $haveByCat = [];
    foreach ($resumeSkills as $s) { $c = sh2_cat($s); if ($c) $haveByCat[$c][] = $s; }

    $matched = $related = $missing = [];
    $pts = 0.0; $max = 0.0;
    foreach ($required as $need) {
        $max += 1.0;
        if (isset($have[$need])) { $matched[] = $need; $pts += 1.0; continue; }
        $cat = sh2_cat($need);
        if ($cat && $cat !== 'soft' && !empty($haveByCat[$cat])) {
            $related[] = ['need' => $need, 'have' => $haveByCat[$cat][0], 'cat' => SH_SKILL_CATS[$cat] ?? $cat];
            $pts += 0.5;
        } else {
            $missing[] = $need;
        }
    }
    $reqScore = $max > 0 ? $pts / $max : 1.0;

    $phit = $pmiss = [];
    foreach ($preferred as $p) { isset($have[$p]) ? $phit[] = $p : $pmiss[] = $p; }
    $prefScore = $preferred ? count($phit) / count($preferred) : 1.0;

    $asked = array_fill_keys(array_merge($required, $preferred), true);
    $extra = array_values(array_filter($resumeSkills, fn($s) => !isset($asked[$s]) && sh2_cat($s) !== '' ));

    $score = (int)round(($reqScore * 0.85 + $prefScore * 0.15) * 100);
    return [
        'score' => $score, 'matched' => $matched, 'related' => $related, 'missing' => $missing,
        'preferred_hit' => $phit, 'preferred_miss' => $pmiss, 'extra' => array_slice($extra, 0, 12),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
//  CERTIFICATION ENGINE
// ─────────────────────────────────────────────────────────────────────────────
function sh2_certifications(string $resumeText, array $jdCerts = []): array {
    $hay = strtolower($resumeText);
    $found = [];
    foreach (SH_CERTS as $canon => $aliases) {
        foreach ($aliases as $a) if (str_contains($hay, $a)) { $found[] = $canon; break; }
    }
    $missing = array_values(array_diff($jdCerts, $found));
    // Score: if the JD asks for certs, match against them; otherwise any cert is a bonus.
    if ($jdCerts)      $score = (int)round(count(array_intersect($found, $jdCerts)) / count($jdCerts) * 100);
    elseif ($found)    $score = min(100, 60 + 20 * count($found));   // 1 cert=80, 2+=100
    else               $score = 50;                                   // neutral: none asked, none held
    return ['score' => $score, 'found' => $found, 'required' => $jdCerts, 'missing' => $missing];
}

// ─────────────────────────────────────────────────────────────────────────────
//  RESUME QUALITY ENGINE V2 — verbs, weak words, passive voice, buzzwords, numbers
// ─────────────────────────────────────────────────────────────────────────────
function sh2_quality(string $text): array {
    $lower = strtolower($text);
    $words = max(1, str_word_count($lower));

    $verbs = [];
    foreach (SH_ACTION_VERBS as $v) if (preg_match('/\b' . $v . '\b/', $lower)) $verbs[] = $v;
    $weak = [];
    foreach (SH_WEAK_WORDS as $w) if (substr_count($lower, $w) > 0) $weak[] = $w;
    $buzz = [];
    foreach (SH_BUZZWORDS as $b) if (substr_count($lower, $b) > 0) $buzz[] = $b;

    // Passive voice heuristic: was/were/been + past participle
    $passive = preg_match_all('/\b(?:was|were|been|being|is|are)\s+\w+(?:ed|en)\b/', $lower);
    // Quantified achievements: %, ₹/$, or standalone numbers ≥2 digits
    $numbers = preg_match_all('/\d+\s*%|[₹$]\s*\d|(?<![\w.])\d{2,}(?![\w.])/', $text);
    // Repeated-word check on content words
    $tokens = array_count_values(array_filter(preg_split('/[^a-z]+/', $lower), fn($t) => strlen($t) > 5));
    arsort($tokens);
    $repeated = array_slice(array_keys(array_filter($tokens, fn($n) => $n >= 6)), 0, 5);

    $score = 50;
    $reasons = []; $deductions = []; $suggestions = [];
    if (count($verbs) >= 8)      { $score += 20; $reasons[] = count($verbs) . ' strong action verbs (e.g. ' . implode(', ', array_slice($verbs, 0, 4)) . ')'; }
    elseif (count($verbs) >= 3)  { $score += 10; $reasons[] = count($verbs) . ' action verbs found'; $suggestions[] = 'Use more strong action verbs (led, built, optimized, delivered).'; }
    else                         { $deductions[] = 'Few strong action verbs'; $suggestions[] = 'Start bullet points with action verbs: built, led, automated, improved.'; }

    if ($numbers >= 5)           { $score += 20; $reasons[] = "$numbers quantified achievements (numbers/percentages)"; }
    elseif ($numbers >= 2)       { $score += 10; $reasons[] = "$numbers quantified achievements"; $suggestions[] = 'Add more measurable impact (%, counts, ₹) to achievements.'; }
    else                         { $deductions[] = 'No quantified achievements'; $suggestions[] = 'Quantify impact: "reduced load time 40%", "handled 200+ tickets/month".'; }

    if ($weak)                   { $score -= min(15, 4 * count($weak)); $deductions[] = 'Weak phrases: ' . implode(', ', array_slice($weak, 0, 4)); $suggestions[] = 'Replace weak phrases ("responsible for", "worked on") with direct action verbs.'; }
    if ($buzz)                   { $score -= min(10, 3 * count($buzz)); $deductions[] = 'Buzzwords: ' . implode(', ', array_slice($buzz, 0, 4)); $suggestions[] = 'Swap buzzwords for concrete evidence of the trait.'; }
    if ($passive > 5)            { $score -= 8;  $deductions[] = "$passive passive-voice constructions"; $suggestions[] = 'Rewrite passive sentences in active voice.'; }
    if ($repeated)               { $score -= 5;  $deductions[] = 'Overused words: ' . implode(', ', $repeated); }
    if ($words < 150)            { $score -= 10; $deductions[] = 'Resume is very short (' . $words . ' words)'; $suggestions[] = 'Expand experience and project detail (aim for 300–800 words).'; }

    return [
        'score' => max(0, min(100, $score)),
        'action_verbs' => $verbs, 'weak_words' => $weak, 'buzzwords' => $buzz,
        'passive_count' => (int)$passive, 'quantified' => (int)$numbers, 'repeated' => $repeated,
        'reasons' => $reasons, 'deductions' => $deductions, 'suggestions' => $suggestions,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
//  EXPERIENCE ENGINE V2 — years (V1) + job-title family relevance
// ─────────────────────────────────────────────────────────────────────────────
function sh2_title_families(string $text): array {
    $hay = strtolower($text);
    $fams = [];
    foreach (SH_TITLE_FAMILIES as $family => $titles) {
        foreach ($titles as $t) if (str_contains($hay, $t)) { $fams[] = $family; break; }
    }
    return $fams;
}

/** Which title family does a job title belong to ('' if unknown). */
function sh2_family_of(string $jobTitle): string {
    $t = strtolower($jobTitle);
    foreach (SH_TITLE_FAMILIES as $family => $titles) {
        foreach ($titles as $alias) if (str_contains($t, $alias)) return $family;
    }
    // fall back on keyword hints in the title
    if (preg_match('/engineer|developer|programmer/', $t)) return 'software engineering';
    if (preg_match('/data|analyst|analytics|scientist/', $t)) return 'data';
    if (preg_match('/devops|cloud|sre|platform/', $t)) return 'devops';
    if (preg_match('/security|soc|cyber/', $t)) return 'security';
    if (preg_match('/design/', $t)) return 'design';
    if (preg_match('/manager|lead/', $t)) return 'management';
    return '';
}

function sh2_experience(string $resumeText, array $jd, string $jobTitle = ''): array {
    $years    = sh_years_experience($resumeText);                    // V1 primitive
    $yearsPct = sh_experience_match($resumeText, (int)$jd['experience_min'], (int)$jd['experience_max']); // V1 primitive
    $fams     = sh2_title_families($resumeText);
    $target   = sh2_family_of($jobTitle);

    $reasons = []; $deductions = []; $suggestions = [];
    $relevance = 60;   // neutral when we can't classify
    if ($target !== '') {
        if (in_array($target, $fams, true)) { $relevance = 100; $reasons[] = "Direct experience in the role's domain ($target)"; }
        elseif ($fams)                      { $relevance = 55;  $deductions[] = 'Experience is in ' . implode('/', $fams) . ", not $target"; $suggestions[] = "Highlight any $target-related work prominently."; }
        else                                { $relevance = 40;  $deductions[] = 'No recognizable job titles found on resume'; $suggestions[] = 'State past job titles clearly (e.g. "Software Developer, X Corp").'; }
    }
    if ($years > 0) $reasons[] = "$years year(s) of experience detected";
    else            $suggestions[] = 'State total years of experience explicitly.';

    $score = (int)round($yearsPct * 0.6 + $relevance * 0.4);
    return ['score' => $score, 'years' => $years, 'years_fit' => $yearsPct,
            'families' => $fams, 'target_family' => $target, 'relevance' => $relevance,
            'reasons' => $reasons, 'deductions' => $deductions, 'suggestions' => $suggestions];
}

// ─────────────────────────────────────────────────────────────────────────────
//  V2 SCORING — configurable weights, per-component explanations
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The full V2 analysis. Pure. Combines V1 primitives with V2 engines under
 * configurable weights; returns components each carrying {score, weight, points,
 * reasons, deductions, suggestions} plus the overall score and grade.
 */
function sh2_analyze(string $resumeText, array $job, ?int $interviewScore = null): array {
    $W  = sh2_weights();
    $jd = sh2_parse_jd($job);

    // ── component scores ──
    $resumeSkills = array_values(array_unique(array_merge(
        sh2_detect_skills($resumeText),
        sh2_normalize_skill_list('')       // (explicit candidate skill CSV can be merged by caller)
    )));
    $skills = sh2_skill_match($resumeSkills, $jd['required_skills'], $jd['preferred_skills']);
    $certs  = sh2_certifications($resumeText, $jd['certifications']);
    $qual   = sh2_quality($resumeText);
    $exp    = sh2_experience($resumeText, $jd, (string)($job['title'] ?? ''));

    // V1 primitives reused (already tested)
    $jobText  = trim(($job['description'] ?? '') . ' ' . ($job['requirements'] ?? '') . ' ' . ($job['skills_required'] ?? ''));
    $cov      = sh_keyword_coverage($resumeText, $jobText);
    $fmt      = sh_formatting_score($resumeText);
    $read     = sh_readability_score($resumeText);
    $eduScore = sh_education_match($resumeText);
    $completeness = (int)round(count(array_filter($fmt['checks'])) / max(1, count($fmt['checks'])) * 100);

    // ── assemble explainable components ──
    $components = [
        'completeness' => [
            'label' => 'Resume Completeness', 'score' => $completeness,
            'reasons' => array_keys(array_filter($fmt['checks'])),
            'deductions' => array_keys(array_filter($fmt['checks'], fn($v) => !$v)),
            'suggestions' => array_map(fn($c) => "Add: $c", array_keys(array_filter($fmt['checks'], fn($v) => !$v))),
        ],
        'keywords' => [
            'label' => 'Keyword Match', 'score' => (int)$cov['coverage'],
            'reasons' => $cov['matched'] ? ['Matched job keywords: ' . implode(', ', array_slice($cov['matched'], 0, 8))] : [],
            'deductions' => $cov['missing'] ? ['Missing job keywords: ' . implode(', ', array_slice($cov['missing'], 0, 8))] : [],
            'suggestions' => $cov['missing'] ? ['Weave missing keywords into experience bullets where truthful: ' . implode(', ', array_slice($cov['missing'], 0, 5)) . '.'] : [],
        ],
        'skills' => [
            'label' => 'Skills Match', 'score' => $skills['score'],
            'reasons' => array_merge(
                $skills['matched'] ? ['Required skills present: ' . implode(', ', $skills['matched'])] : [],
                array_map(fn($r) => "Partial credit: has {$r['have']} (related to {$r['need']} — {$r['cat']})", $skills['related'])
            ),
            'deductions' => $skills['missing'] ? ['Required skills missing: ' . implode(', ', $skills['missing'])] : [],
            'suggestions' => $skills['missing'] ? array_map(fn($m) => "Add $m experience if the candidate has it (or plan to acquire it).", array_slice($skills['missing'], 0, 4)) : [],
        ],
        'experience' => [
            'label' => 'Experience Match', 'score' => $exp['score'],
            'reasons' => $exp['reasons'], 'deductions' => $exp['deductions'], 'suggestions' => $exp['suggestions'],
        ],
        'education' => [
            'label' => 'Education Match', 'score' => $eduScore,
            'reasons' => $eduScore >= 80 ? ['Degree level detected on resume'] : [],
            'deductions' => $eduScore < 55 ? ['Education level unclear from resume text'] : [],
            'suggestions' => $eduScore < 55 ? ['List degree, institution and year in a clear Education section.'] : [],
        ],
        'certifications' => [
            'label' => 'Certifications', 'score' => $certs['score'],
            'reasons' => $certs['found'] ? ['Certifications found: ' . implode(', ', $certs['found'])] : [],
            'deductions' => $certs['missing'] ? ['JD-requested certifications missing: ' . implode(', ', $certs['missing'])] : ($certs['found'] ? [] : ['No certifications detected']),
            'suggestions' => $certs['missing'] ? array_map(fn($c) => "Consider the $c certification.", $certs['missing'])
                              : (!$certs['found'] ? ['A relevant certification (AWS/Azure/Scrum) would strengthen the profile.'] : []),
        ],
        'quality' => [
            'label' => 'Resume Quality', 'score' => $qual['score'],
            'reasons' => $qual['reasons'], 'deductions' => $qual['deductions'], 'suggestions' => $qual['suggestions'],
        ],
        'formatting' => [
            'label' => 'Formatting', 'score' => (int)$fmt['score'],
            'reasons' => [], 'deductions' => [], 'suggestions' => [],
        ],
        'readability' => [
            'label' => 'Readability', 'score' => (int)$read,
            'reasons' => $read >= 80 ? ['Clear, concise sentence structure'] : [],
            'deductions' => $read < 60 ? ['Long sentences reduce scanability'] : [],
            'suggestions' => $read < 60 ? ['Shorten sentences; prefer punchy bullet points.'] : [],
        ],
    ];

    // ── weighted total (weights from config, normalized to 100) ──
    $total = 0.0;
    foreach ($components as $key => &$c) {
        $c['weight'] = round($W[$key], 1);
        $c['points'] = round($c['score'] * $W[$key] / 100, 1);
        $total += $c['points'];
    }
    unset($c);
    $overall = (int)round(min(100, max(0, $total)));

    // ── grade band (spec's color system) ──
    $grade = $overall >= 90 ? ['label' => 'Excellent',        'color' => 'green']
           : ($overall >= 75 ? ['label' => 'Very Good',        'color' => 'blue']
           : ($overall >= 60 ? ['label' => 'Needs Improvement','color' => 'amber']
           : ($overall >= 40 ? ['label' => 'Weak Match',       'color' => 'orange']
           :                   ['label' => 'Poor Match',       'color' => 'rose'])));

    // ── recruiter insights + priority ──
    $insights = sh2_recruiter_insights($components, $skills, $exp, $certs, $overall);

    return [
        'overall'    => $overall,
        'grade'      => $grade,
        'final'      => sh_final_score($overall, $interviewScore),   // V1 contract preserved
        'weights'    => $W,
        'components' => $components,
        'jd'         => $jd,
        'skills'     => $skills,
        'certs'      => $certs,
        'quality'    => $qual,
        'experience' => $exp,
        'resume_skills' => $resumeSkills,
        'insights'   => $insights,
    ];
}

/** Recruiter-facing insights with justification + priority band. */
function sh2_recruiter_insights(array $components, array $skills, array $exp, array $certs, int $overall): array {
    $notes = [];
    if ($skills['score'] >= 80 && !$skills['missing'])
        $notes[] = ['tag' => 'Strong technical fit', 'why' => 'All required skills present' . ($skills['preferred_hit'] ? ' plus preferred: ' . implode(', ', $skills['preferred_hit']) : '')];
    elseif ($skills['related'])
        $notes[] = ['tag' => 'Transferable skill set', 'why' => count($skills['related']) . ' requirement(s) covered by related technology — likely fast ramp-up'];
    if ($skills['missing'])
        $notes[] = ['tag' => 'Skill gaps', 'why' => 'Missing: ' . implode(', ', array_slice($skills['missing'], 0, 5))];
    if ($exp['target_family'] && $exp['relevance'] >= 100)
        $notes[] = ['tag' => 'Domain-relevant experience', 'why' => 'Prior titles in ' . $exp['target_family']];
    elseif ($exp['relevance'] <= 55 && $exp['families'])
        $notes[] = ['tag' => 'Domain switch', 'why' => 'Background is ' . implode('/', $exp['families']) . ' — probe motivation and ramp-up in interview'];
    if ($certs['found'])
        $notes[] = ['tag' => 'Certified', 'why' => implode(', ', $certs['found'])];
    if (($components['quality']['score'] ?? 0) < 45)
        $notes[] = ['tag' => 'Weak resume writing', 'why' => 'Low quality score — judge on interview, not resume polish'];

    $priority = $overall >= 75 ? ['band' => 'High Priority',   'action' => 'Shortlist and schedule interview', 'color' => 'green']
              : ($overall >= 55 ? ['band' => 'Medium Priority', 'action' => 'Review against other applicants; screen call recommended', 'color' => 'amber']
              :                   ['band' => 'Low Priority',    'action' => 'Hold unless pipeline is thin', 'color' => 'rose']);
    return ['notes' => $notes, 'priority' => $priority];
}
