<?php
// ═════════════════════════════════════════════════════════════════════════════
//  SmartHire v7 — includes/recruitment.php   (Build 2)
//  The recruitment engine: pipeline stage model, stage transitions with event
//  logging, and job-description-aware ATS scoring of applications.
//  Pure-logic functions (stages + scoring) are DB-free and unit-tested.
//  Requires includes/config.php + includes/resume_parser.php to be loaded.
// ═════════════════════════════════════════════════════════════════════════════

// ── Pipeline model ───────────────────────────────────────────────────────────
// The single source of truth for stage order/labels/colours. Must match the
// `stage` ENUM on job_applications (Build 1 migration). Offer *acceptance* is
// tracked on offers.status, not as a separate pipeline stage.
const SH_STAGES = [
    'applied'              => ['label' => 'Applied',              'color' => 'gray',   'icon' => 'fa-inbox'],
    'resume_screening'     => ['label' => 'Resume Screening',     'color' => 'blue',   'icon' => 'fa-file-lines'],
    'ats_analysis'         => ['label' => 'ATS Analysis',         'color' => 'violet', 'icon' => 'fa-robot'],
    'shortlisted'          => ['label' => 'Shortlisted',          'color' => 'amber',  'icon' => 'fa-star'],
    'online_test'          => ['label' => 'Online Test',          'color' => 'blue',   'icon' => 'fa-laptop-code'],
    'interview_scheduled'  => ['label' => 'Interview Scheduled',  'color' => 'violet', 'icon' => 'fa-calendar-check'],
    'interview_completed'  => ['label' => 'Interview Completed',  'color' => 'blue',   'icon' => 'fa-clipboard-check'],
    'selected'             => ['label' => 'Selected',             'color' => 'green',  'icon' => 'fa-circle-check'],
    'offer_released'        => ['label' => 'Offer Released',        'color' => 'green',  'icon' => 'fa-file-signature'],
    'joined'               => ['label' => 'Joined',               'color' => 'green',  'icon' => 'fa-handshake'],
    'rejected'             => ['label' => 'Rejected',             'color' => 'rose',   'icon' => 'fa-circle-xmark'],
];

/** Ordered list of the "forward" pipeline (excludes the terminal 'rejected'). */
function sh_stage_flow(): array {
    return ['applied','resume_screening','ats_analysis','shortlisted','online_test',
            'interview_scheduled','interview_completed','selected','offer_released','joined'];
}
function sh_stage_label(string $stage): string { return SH_STAGES[$stage]['label'] ?? ucwords(str_replace('_',' ',$stage)); }
function sh_stage_color(string $stage): string { return SH_STAGES[$stage]['color'] ?? 'gray'; }
function sh_stage_icon(string $stage):  string { return SH_STAGES[$stage]['icon']  ?? 'fa-circle'; }
function sh_stage_index(string $stage): int { $i = array_search($stage, sh_stage_flow(), true); return $i === false ? -1 : $i; }

/** The next forward stage after $stage, or null if none / terminal. */
function sh_next_stage(string $stage): ?string {
    $flow = sh_stage_flow();
    $i = array_search($stage, $flow, true);
    if ($i === false || $i >= count($flow) - 1) return null;
    return $flow[$i + 1];
}

/**
 * Is moving $from → $to a legal transition?
 * Rules: may advance to the immediate next stage, jump forward (recruiter skip),
 * or reject from any non-terminal stage. Cannot move out of joined/rejected.
 */
function sh_can_transition(string $from, string $to): bool {
    if ($from === $to) return false;
    if (in_array($from, ['joined','rejected'], true)) return false;
    if ($to === 'rejected') return true;
    if (!isset(SH_STAGES[$to]) || $to === 'rejected') return isset(SH_STAGES[$to]);
    $fi = sh_stage_index($from); $ti = sh_stage_index($to);
    if ($fi === -1 || $ti === -1) return false;
    return $ti > $fi;                       // forward moves only (no backward)
}

// ═════════════════════════════════════════════════════════════════════════════
//  ATS SCORING  (job-description aware) — all pure, no DB
// ═════════════════════════════════════════════════════════════════════════════

/** Split a comma/newline/pipe separated skills string into a clean lowercase list. */
function sh_parse_skills(string $raw): array {
    $parts = preg_split('/[,\n|;]+/', strtolower($raw));
    $out = [];
    foreach ($parts as $p) { $p = trim($p); if ($p !== '') $out[] = $p; }
    return array_values(array_unique($out));
}

/** % of the job's required skills that appear in the resume text (0..100). */
function sh_skill_match(string $resumeText, string $jobSkills): int {
    $skills = sh_parse_skills($jobSkills);
    if (!$skills) return 0;
    $hay = strtolower($resumeText);
    $hit = 0;
    foreach ($skills as $sk) {
        // word-ish contains: allow multi-word skills and punctuation like "ci/cd", "node.js"
        if (str_contains($hay, $sk)) $hit++;
    }
    return (int)round($hit / count($skills) * 100);
}

/** Estimate years of experience mentioned in a resume. */
function sh_years_experience(string $resumeText): int {
    $max = 0;
    if (preg_match_all('/(\d{1,2})\s*\+?\s*(?:years?|yrs?)\b/i', $resumeText, $m)) {
        foreach ($m[1] as $y) $max = max($max, (int)$y);
    }
    return $max;
}

/** How well the candidate's years fit the job's [min,max] window (0..100). */
function sh_experience_match(string $resumeText, int $expMin, int $expMax): int {
    $yrs = sh_years_experience($resumeText);
    if ($expMin <= 0 && $expMax <= 0) return $yrs > 0 ? 70 : 50;   // no requirement stated
    $hi = $expMax > 0 ? $expMax : $expMin + 3;
    if ($yrs >= $expMin && $yrs <= $hi) return 100;                // in range
    if ($yrs > $hi)  return max(60, 100 - ($yrs - $hi) * 8);        // overqualified
    if ($yrs === 0)  return 30;                                     // none found
    // under-qualified: proportional to how close to the minimum
    return (int)max(20, round($yrs / max(1, $expMin) * 80));
}

/** Detect the highest education level present (0..100). */
function sh_education_match(string $resumeText): int {
    $t = strtolower($resumeText);
    $levels = [
        ['kw' => ['ph.d','phd','doctorate'],                                  'score' => 100],
        ['kw' => ['m.tech','mtech','m.e ','mba','msc','m.sc','mca','master'],  'score' => 90],
        ['kw' => ['b.tech','btech','b.e','bsc','b.sc','bca','bba','bachelor'], 'score' => 80],
        ['kw' => ['diploma','associate'],                                     'score' => 55],
        ['kw' => ['12th','higher secondary','hsc','high school'],             'score' => 35],
    ];
    foreach ($levels as $lvl) {
        foreach ($lvl['kw'] as $kw) if (str_contains($t, $kw)) return $lvl['score'];
    }
    return 40; // unknown → neutral-ish
}

/** Resume quality: length + presence of standard sections + contactability (0..100). */
function sh_resume_quality(string $resumeText): int {
    $t = strtolower($resumeText);
    $words = str_word_count($t);
    $score = 0;
    // length band
    if ($words >= 200 && $words <= 1200) $score += 40;
    elseif ($words > 60)                 $score += 25;
    else                                 $score += 10;
    // sections
    foreach (['experience','education','skill','project'] as $sec) {
        if (str_contains($t, $sec)) $score += 10;               // up to 40
    }
    // contactability
    if (preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/', $t)) $score += 10;
    if (preg_match('/(\+?\d[\d\s\-().]{7,})/', $t))    $score += 10;
    return min(100, $score);
}

/**
 * Full ATS breakdown for an application, given the resume text and the job row.
 * Returns sub-scores + weighted composite ats_score. Pure — no DB.
 */
function sh_ats_breakdown(string $resumeText, array $job): array {
    $skill = sh_skill_match($resumeText, (string)($job['skills_required'] ?? ''));
    $exp   = sh_experience_match($resumeText, (int)($job['experience_min'] ?? 0), (int)($job['experience_max'] ?? 0));
    $edu   = sh_education_match($resumeText);
    $qual  = sh_resume_quality($resumeText);
    // weighted composite
    $ats = (int)round($skill * 0.40 + $exp * 0.25 + $edu * 0.15 + $qual * 0.20);
    return [
        'skill_match'      => $skill,
        'experience_match' => $exp,
        'education_match'  => $edu,
        'resume_quality'   => $qual,
        'ats_score'        => max(0, min(100, $ats)),
    ];
}

/** Composite final score once an interview score exists (else = ats_score). */
function sh_final_score(int $atsScore, ?int $interviewScore): int {
    if ($interviewScore === null) return $atsScore;
    return (int)round($atsScore * 0.6 + $interviewScore * 0.4);
}

// ═════════════════════════════════════════════════════════════════════════════
//  DB HELPERS  (transition + create) — used by the pages
// ═════════════════════════════════════════════════════════════════════════════

/** Record a pipeline move on job_applications + append an immutable event row. */
function sh_move_stage(int $appId, string $toStage, ?string $note = null): bool {
    if (!isset(SH_STAGES[$toStage])) return false;
    $app = dbFetchOne("SELECT * FROM job_applications WHERE id=?", 'i', $appId);
    if (!$app) return false;
    $from = $app['stage'];
    if ($from === $toStage) return true;
    if ($toStage !== 'rejected' && !sh_can_transition($from, $toStage)) return false;

    return (bool)withTransaction(function () use ($appId, $from, $toStage, $note, $app) {
        dbExecute("UPDATE job_applications SET stage=? WHERE id=?", 'si', $toStage, $appId);
        $u = function_exists('currentUser') && !empty($_SESSION['user_id']) ? currentUser() : ['id'=>0,'role'=>'system'];
        dbExecute(
            "INSERT INTO application_events (application_id,from_stage,to_stage,note,actor_id,actor_role)
             VALUES (?,?,?,?,?,?)",
            'isssss', $appId, $from, $toStage, $note, (int)$u['id'], $u['role']);
        audit_log('app_stage', 'application', $appId, $from . '→' . $toStage);
        // notify candidate of meaningful moves
        notifyCandidate((int)$app['candidate_id'], 'application_status',
            'Your application moved to "' . sh_stage_label($toStage) . '".');
        return true;
    });
}

// ═════════════════════════════════════════════════════════════════════════════
//  WORKFLOW AUTOMATION — keep the pipeline in sync with other modules
// ═════════════════════════════════════════════════════════════════════════════

/** Pure: should an application at $current auto-advance to $target? (forward only, not terminal) */
function sh_should_advance(string $current, string $target): bool {
    if (in_array($current, ['joined','rejected'], true)) return false;
    if (!isset(SH_STAGES[$target])) return false;
    return sh_stage_index($target) > sh_stage_index($current);
}

/**
 * Auto-advance a candidate's active applications to $targetStage when an event
 * happens elsewhere (test submitted, interview scheduled/completed, selected…).
 * Only moves forward, never past joined/rejected. Returns how many were moved.
 * If $jobId is given, only that job's application is affected.
 */
function sh_advance_candidate_applications(int $candidateId, string $targetStage, ?string $note = null, ?int $jobId = null): int {
    if (!isset(SH_STAGES[$targetStage])) return 0;
    $sql  = "SELECT id, stage FROM job_applications WHERE candidate_id=? AND stage NOT IN ('joined','rejected')";
    $types = 'i'; $args = [$candidateId];
    if ($jobId) { $sql .= " AND job_id=?"; $types .= 'i'; $args[] = $jobId; }
    $moved = 0;
    foreach (dbFetchAll($sql, $types, ...$args) as $a) {
        if (sh_should_advance($a['stage'], $targetStage)) {
            if (sh_move_stage((int)$a['id'], $targetStage, $note)) $moved++;
        }
    }
    return $moved;
}

/**
 * Create an application: stores optional resume, runs ATS against the job,
 * writes sub-scores, and logs the applied event. Returns app id or false.
 */
function sh_create_application(int $jobId, int $candidateId, string $coverNote, ?string $resumePath, string $resumeText): bool|int {
    $job = dbFetchOne("SELECT * FROM jobs WHERE id=? AND status='open'", 'i', $jobId);
    if (!$job) return false;
    if (dbFetchOne("SELECT id FROM job_applications WHERE job_id=? AND candidate_id=?", 'ii', $jobId, $candidateId)) {
        return false; // already applied (UNIQUE also guards this)
    }
    $b = sh_ats_breakdown($resumeText, $job);
    $final = sh_final_score($b['ats_score'], null);

    return withTransaction(function () use ($jobId,$candidateId,$coverNote,$resumePath,$b,$final,$job) {
        $appId = dbExecute(
            "INSERT INTO job_applications
             (job_id,candidate_id,cover_note,resume_path,stage,ats_score,skill_match,
              experience_match,education_match,resume_quality,final_score)
             VALUES (?,?,?,?,'ats_analysis',?,?,?,?,?,?)",
            'iissiiiiii', $jobId, $candidateId, $coverNote, $resumePath,
            $b['ats_score'], $b['skill_match'], $b['experience_match'],
            $b['education_match'], $b['resume_quality'], $final);
        if (!$appId) throw new RuntimeException('insert failed');
        dbExecute("INSERT INTO application_events (application_id,from_stage,to_stage,note,actor_id,actor_role)
                   VALUES (?,?,?,?,?,?)",
            'isssss', $appId, null, 'applied', 'Application submitted', $candidateId, 'candidate');
        dbExecute("INSERT INTO application_events (application_id,from_stage,to_stage,note,actor_id,actor_role)
                   VALUES (?,?,?,?,?,?)",
            'isssss', $appId, 'applied', 'ats_analysis',
            'Auto ATS score: ' . $b['ats_score'] . '%', 0, 'system');
        addNotification('application_received',
            'New application for "' . $job['title'] . '" (ATS ' . $b['ats_score'] . '%)', $candidateId);
        audit_log('app_create', 'application', is_int($appId) ? $appId : null, 'job=' . $jobId);
        return $appId;
    });
}

// ═════════════════════════════════════════════════════════════════════════════
//  ANALYTICS — pure metric helpers (Build 4), unit-tested
// ═════════════════════════════════════════════════════════════════════════════

/** Percentage helper, safe against divide-by-zero. */
function sh_pct(int $part, int $whole): int { return $whole > 0 ? (int)round($part / $whole * 100) : 0; }

/** Offer acceptance rate (%) from released vs accepted counts. */
function sh_acceptance_rate(int $released, int $accepted): int { return sh_pct($accepted, $released); }

/** Overall conversion rate applied → hired (%). */
function sh_conversion_rate(int $applied, int $hired): int { return sh_pct($hired, $applied); }

/** Average of a list of day-differences, rounded to 1 decimal (0 if empty). */
function sh_avg_days(array $dayDiffs): float {
    $vals = array_filter($dayDiffs, fn($d) => $d !== null);
    if (!$vals) return 0.0;
    return round(array_sum($vals) / count($vals), 1);
}

/**
 * Funnel counts: given the current stage of each application, how many have
 * reached (are at or past) each forward stage. Pure.
 */
function sh_funnel_from_stages(array $currentStages): array {
    $flow = sh_stage_flow();
    $out = array_fill_keys($flow, 0);
    foreach ($currentStages as $st) {
        if ($st === 'rejected') continue;
        $idx = sh_stage_index($st);
        if ($idx < 0) continue;
        foreach ($flow as $i => $stage) { if ($i <= $idx) $out[$stage]++; }
    }
    return $out;
}

/** Bucket ATS scores into distribution bands. Pure. */
function sh_score_distribution(array $scores): array {
    $b = ['0-39' => 0, '40-59' => 0, '60-74' => 0, '75-100' => 0];
    foreach ($scores as $s) {
        $s = (int)$s;
        if ($s >= 75) $b['75-100']++;
        elseif ($s >= 60) $b['60-74']++;
        elseif ($s >= 40) $b['40-59']++;
        else $b['0-39']++;
    }
    return $b;
}
