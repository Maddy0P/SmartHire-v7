<?php
// ═════════════════════════════════════════════════════════════════════════════
//  jobs.php — Recruiter Job Management (list · create · edit · open/close)
//  Security: recruiter-or-higher, CSRF on writes, prepared statements, audit.
// ═════════════════════════════════════════════════════════════════════════════
require_once 'includes/config.php';
require_once 'includes/layout.php';
require_once 'includes/recruitment.php';
requireRole('recruiter');
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

$categories = dbFetchAll("SELECT * FROM job_categories ORDER BY name");

// ── Handle writes ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    if ($act === 'save') {
        $id      = (int)($_POST['id'] ?? 0);
        $title   = trim($_POST['title'] ?? '');
        $catId   = (int)($_POST['category_id'] ?? 0) ?: null;
        $dept    = trim($_POST['department'] ?? '');
        $loc     = trim($_POST['location'] ?? '');
        $etype   = in_array($_POST['employment_type'] ?? '', ['full_time','part_time','contract','internship','remote'], true) ? $_POST['employment_type'] : 'full_time';
        $expMin  = max(0, (int)($_POST['experience_min'] ?? 0));
        $expMax  = max(0, (int)($_POST['experience_max'] ?? 0));
        $salMin  = ($_POST['salary_min'] ?? '') !== '' ? (int)$_POST['salary_min'] : null;
        $salMax  = ($_POST['salary_max'] ?? '') !== '' ? (int)$_POST['salary_max'] : null;
        $openings= max(1, (int)($_POST['openings'] ?? 1));
        $desc    = trim($_POST['description'] ?? '');
        $reqs    = trim($_POST['requirements'] ?? '');
        $skills  = trim($_POST['skills_required'] ?? '');
        $status  = in_array($_POST['status'] ?? '', ['draft','open','paused','closed'], true) ? $_POST['status'] : 'open';
        $closes  = ($_POST['closes_on'] ?? '') !== '' ? $_POST['closes_on'] : null;

        if (!v_len($title, 3, 150)) {
            setFlash('error', 'Job title must be 3–150 characters.');
        } elseif ($expMax > 0 && $expMax < $expMin) {
            setFlash('error', 'Max experience cannot be less than min experience.');
        } else {
            if ($id > 0) {
                $ok = dbExecute(
                    "UPDATE jobs SET title=?,category_id=?,department=?,location=?,employment_type=?,
                        experience_min=?,experience_max=?,salary_min=?,salary_max=?,openings=?,
                        description=?,requirements=?,skills_required=?,status=?,closes_on=? WHERE id=?",
                    'sisssiiiiisssssi',
                    $title,$catId,$dept,$loc,$etype,$expMin,$expMax,$salMin,$salMax,$openings,
                    $desc,$reqs,$skills,$status,$closes,$id);
                audit_log('job_update', 'job', $id, $title);
                setFlash($ok !== false ? 'success' : 'error', $ok !== false ? 'Job updated.' : 'Update failed.');
            } else {
                $newId = dbExecute(
                    "INSERT INTO jobs (title,category_id,department,location,employment_type,
                        experience_min,experience_max,salary_min,salary_max,openings,
                        description,requirements,skills_required,status,closes_on,posted_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    'sisssiiiiisssssi',
                    $title,$catId,$dept,$loc,$etype,$expMin,$expMax,$salMin,$salMax,$openings,
                    $desc,$reqs,$skills,$status,$closes,currentUser()['id']);
                audit_log('job_create', 'job', is_int($newId) ? $newId : null, $title);
                setFlash($newId ? 'success' : 'error', $newId ? 'Job posted.' : 'Could not post job.');
            }
        }
        redirect('jobs.php');
    }

    if ($act === 'set_status') {
        $id = (int)($_POST['id'] ?? 0);
        $st = in_array($_POST['status'] ?? '', ['open','paused','closed','draft'], true) ? $_POST['status'] : 'open';
        dbExecute("UPDATE jobs SET status=? WHERE id=?", 'si', $st, $id);
        audit_log('job_status', 'job', $id, $st);
        setFlash('success', 'Job marked ' . $st . '.');
        redirect('jobs.php');
    }

    if ($act === 'add_category') {
        $name = trim($_POST['cat_name'] ?? '');
        if (v_len($name, 2, 100)) {
            $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
            dbExecute("INSERT INTO job_categories (name,slug,description) VALUES (?,?,?) ON CONFLICT (name) DO NOTHING",
                'sss', $name, trim($slug, '-'), trim($_POST['cat_desc'] ?? ''));
            audit_log('category_add', 'job_category', null, $name);
            setFlash('success', 'Category added.');
        } else setFlash('error', 'Category name must be 2–100 characters.');
        redirect('jobs.php');
    }
}

// ── Filters + pagination ─────────────────────────────────────────────────────
$q      = trim($_GET['q'] ?? '');
$fStat  = $_GET['status'] ?? '';
$fCat   = (int)($_GET['category'] ?? 0);
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 9;
$off    = ($page - 1) * $per;

$where = "1=1"; $types = ''; $args = [];
if ($q !== '')     { $where .= " AND j.title LIKE ?"; $types .= 's'; $args[] = "%$q%"; }
if ($fStat !== '' && in_array($fStat, ['draft','open','paused','closed'], true)) { $where .= " AND j.status=?"; $types .= 's'; $args[] = $fStat; }
if ($fCat > 0)     { $where .= " AND j.category_id=?"; $types .= 'i'; $args[] = $fCat; }

$total = dbFetchOne("SELECT COUNT(*) n FROM jobs j WHERE $where", $types, ...$args)['n'] ?? 0;
$pages = max(1, (int)ceil($total / $per));

$jobs = dbFetchAll(
    "SELECT j.*, c.name AS category_name,
            (SELECT COUNT(*) FROM job_applications a WHERE a.job_id=j.id) AS applicant_count,
            (SELECT COUNT(*) FROM job_applications a WHERE a.job_id=j.id AND a.stage='shortlisted') AS shortlisted_count
     FROM jobs j LEFT JOIN job_categories c ON c.id=j.category_id
     WHERE $where ORDER BY j.created_at DESC LIMIT $per OFFSET $off",
    $types, ...$args);

// stats
$stat = dbFetchOne("SELECT
    COUNT(*) total,
    SUM((status='open')::int) open_cnt,
    (SELECT COUNT(*) FROM job_applications) app_total
  FROM jobs") ?? ['total'=>0,'open_cnt'=>0,'app_total'=>0];

$editing = null;
if (($eid = (int)($_GET['edit'] ?? 0)) > 0) {
    $editing = dbFetchOne("SELECT * FROM jobs WHERE id=?", 'i', $eid);
}
$showForm = isset($_GET['new']) || $editing;

// ── v8 presentation additions (read-only; Module 4) ──────────────────────────
// Duplicate: prefill create form from an existing job (uses the existing 'save'
// create path — id stays 0, so submitting inserts a new row).
$dup = null;
if (($did = (int)($_GET['duplicate'] ?? 0)) > 0 && !$editing) {
    $dup = dbFetchOne("SELECT * FROM jobs WHERE id=?", 'i', $did);
    if ($dup) { $dup['id'] = 0; $dup['title'] = 'Copy of ' . $dup['title']; $editing = $dup; $showForm = true; }
}

// Whitelisted sort (presentation-layer ORDER BY; prepared filters unchanged)
$sorts = [
    'newest'     => 'j.created_at DESC',
    'oldest'     => 'j.created_at ASC',
    'title'      => 'j.title ASC',
    'applicants' => 'applicant_count DESC',
    'closing'    => 'j.closes_on ASC NULLS LAST',
];
$fSort = isset($sorts[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'newest';
if ($fSort !== 'newest') {
    $jobs = dbFetchAll(
        "SELECT j.*, c.name AS category_name,
                (SELECT COUNT(*) FROM job_applications a WHERE a.job_id=j.id) AS applicant_count,
                (SELECT COUNT(*) FROM job_applications a WHERE a.job_id=j.id AND a.stage='shortlisted') AS shortlisted_count
         FROM jobs j LEFT JOIN job_categories c ON c.id=j.category_id
         WHERE $where ORDER BY {$sorts[$fSort]} LIMIT $per OFFSET $off",
        $types, ...$args);
}

// Status counts for filter chips + shortlisted total for stats
$jbs = [];
foreach (dbFetchAll("SELECT status, COUNT(*) AS n FROM jobs GROUP BY status") as $r) $jbs[$r['status']] = (int)$r['n'];
$shortTotal = (int)(dbFetchOne("SELECT COUNT(*) n FROM job_applications WHERE stage='shortlisted'")['n'] ?? 0);

// Recent audit events for the jobs on this page (detail slide-over timeline)
$events = [];
if ($jobs) {
    $ids = array_map(fn($j) => (int)$j['id'], $jobs);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    foreach (dbFetchAll(
        "SELECT entity_id, action, detail, created_at FROM audit_logs
         WHERE entity='job' AND entity_id IN ($ph)
         ORDER BY created_at DESC LIMIT 60",
        str_repeat('i', count($ids)), ...$ids) as $ev) {
        if (count($events[$ev['entity_id']] ?? []) < 4) $events[$ev['entity_id']][] = $ev;
    }
}

$qs = fn(array $over = []) => 'jobs.php?' . http_build_query(array_filter(array_merge(
    ['q' => $q, 'status' => $fStat, 'category' => $fCat ?: '', 'sort' => $fSort === 'newest' ? '' : $fSort], $over)));

renderHead('Jobs', true);
renderSidebar('jobs');
$etypes = ['full_time'=>'Full-time','part_time'=>'Part-time','contract'=>'Contract','internship'=>'Internship','remote'=>'Remote'];
?>

<div class="sh-page-header">
  <div>
    <h1 class="sh-page-title">Jobs</h1>
    <p class="sh-page-sub">
      <span class="sh-tnum"><?= (int)$stat['total'] ?></span> roles ·
      <span class="sh-tnum"><?= (int)$stat['open_cnt'] ?></span> open ·
      <span class="sh-tnum"><?= (int)$stat['app_total'] ?></span> applications ·
      <span class="sh-tnum"><?= $shortTotal ?></span> shortlisted
    </p>
  </div>
  <a href="<?= $qs(['new' => 1]) ?>#jobform" class="sh-btn sh-btn-primary"><i class="fa-solid fa-plus" aria-hidden="true"></i> New job</a>
</div>

<?php if ($showForm): ?>
<!-- Create / Edit / Duplicate — page hero card (the one hero surface) -->
<section class="sh-card sh-card-hero sh-mb-6" id="jobform" aria-labelledby="jobform-title">
  <div class="sh-card-header">
    <div>
      <h2 class="sh-card-title" id="jobform-title"><?= $dup ? 'Duplicate job' : (($editing['id'] ?? 0) ? 'Edit job' : 'Post a new job') ?></h2>
      <p class="sh-card-sub"><?= $dup ? 'Prefilled from the original — review and post as a new role.' : 'Skills you list here power ATS matching for this role.' ?></p>
    </div>
    <a href="<?= $qs() ?>" class="sh-iconbtn" aria-label="Close form"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
  </div>
  <form method="POST" action="jobs.php">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="save">
    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
    <div class="sh-form-grid">
      <div class="sh-field">
        <label class="sh-label" for="jf_title">Job title <span class="req" aria-hidden="true">*</span></label>
        <input id="jf_title" class="sh-input" name="title" required maxlength="150" value="<?= e($editing['title'] ?? '') ?>" placeholder="e.g. Backend Engineer">
      </div>
      <div class="sh-field">
        <label class="sh-label" for="jf_cat">Category</label>
        <select id="jf_cat" class="sh-input" name="category_id">
          <option value="">— Select —</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (int)($editing['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="sh-field">
        <label class="sh-label" for="jf_dept">Department</label>
        <input id="jf_dept" class="sh-input" name="department" value="<?= e($editing['department'] ?? '') ?>" placeholder="Engineering">
      </div>
      <div class="sh-field">
        <label class="sh-label" for="jf_loc">Location</label>
        <input id="jf_loc" class="sh-input" name="location" value="<?= e($editing['location'] ?? '') ?>" placeholder="Pune / Remote">
      </div>
      <div class="sh-field">
        <label class="sh-label" for="jf_etype">Employment type</label>
        <select id="jf_etype" class="sh-input" name="employment_type">
          <?php foreach ($etypes as $k => $v): ?>
          <option value="<?= $k ?>" <?= ($editing['employment_type'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="sh-field">
        <label class="sh-label" for="jf_open">Openings</label>
        <input id="jf_open" class="sh-input" type="number" min="1" name="openings" value="<?= (int)($editing['openings'] ?? 1) ?>">
      </div>
      <div class="sh-field">
        <label class="sh-label" for="jf_expmin">Experience min (yrs)</label>
        <input id="jf_expmin" class="sh-input" type="number" min="0" name="experience_min" value="<?= (int)($editing['experience_min'] ?? 0) ?>">
      </div>
      <div class="sh-field">
        <label class="sh-label" for="jf_expmax">Experience max (yrs)</label>
        <input id="jf_expmax" class="sh-input" type="number" min="0" name="experience_max" value="<?= (int)($editing['experience_max'] ?? 0) ?>">
        <p class="sh-help">Leave 0 for no upper bound.</p>
      </div>
      <div class="sh-field">
        <label class="sh-label" for="jf_salmin">Salary min (<?= e($editing['currency'] ?? 'INR') ?>)</label>
        <input id="jf_salmin" class="sh-input" type="number" name="salary_min" value="<?= e((string)($editing['salary_min'] ?? '')) ?>">
      </div>
      <div class="sh-field">
        <label class="sh-label" for="jf_salmax">Salary max</label>
        <input id="jf_salmax" class="sh-input" type="number" name="salary_max" value="<?= e((string)($editing['salary_max'] ?? '')) ?>">
      </div>
      <div class="sh-field">
        <label class="sh-label" for="jf_status">Status</label>
        <select id="jf_status" class="sh-input" name="status">
          <?php foreach (['open'=>'Open','draft'=>'Draft','paused'=>'Paused','closed'=>'Closed'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= ($editing['status'] ?? 'open') === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="sh-field">
        <label class="sh-label" for="jf_closes">Closes on</label>
        <input id="jf_closes" class="sh-input" type="date" name="closes_on" value="<?= e($editing['closes_on'] ?? '') ?>">
      </div>
      <div class="sh-field sh-colspan">
        <label class="sh-label" for="jf_skills">Required skills</label>
        <input id="jf_skills" class="sh-input" name="skills_required" value="<?= e($editing['skills_required'] ?? '') ?>" placeholder="Python, Django, PostgreSQL, Docker, AWS">
        <p class="sh-help">Comma separated — powers ATS matching.</p>
      </div>
      <div class="sh-field sh-colspan">
        <label class="sh-label" for="jf_desc">Job description</label>
        <textarea id="jf_desc" class="sh-input" name="description" rows="4" placeholder="Role responsibilities and about the team…"><?= e($editing['description'] ?? '') ?></textarea>
      </div>
      <div class="sh-field sh-colspan">
        <label class="sh-label" for="jf_reqs">Requirements / qualifications</label>
        <textarea id="jf_reqs" class="sh-input" name="requirements" rows="3" placeholder="Must-have qualifications…"><?= e($editing['requirements'] ?? '') ?></textarea>
      </div>
    </div>
    <div class="sh-flex sh-gap-3">
      <button class="sh-btn sh-btn-primary"><?= ($editing['id'] ?? 0) ? 'Save changes' : 'Post job' ?></button>
      <a href="<?= $qs() ?>" class="sh-btn sh-btn-secondary">Cancel</a>
    </div>
  </form>
</section>
<?php endif; ?>

<!-- Filters: search + status chips + category + sort (state in URL) -->
<form method="GET" action="jobs.php" class="sh-flex sh-items-center sh-gap-3 sh-wrap sh-mb-4">
  <?php if ($fSort !== 'newest'): ?><input type="hidden" name="sort" value="<?= e($fSort) ?>"><?php endif; ?>
  <?php if ($fCat): ?><input type="hidden" name="category" value="<?= $fCat ?>"><?php endif; ?>
  <div class="sh-topbar-search sh-search-inline">
    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
    <label class="sh-sr-only" for="jobSearch">Search job titles</label>
    <input type="search" id="jobSearch" name="q" value="<?= e($q) ?>" placeholder="Search job titles…" autocomplete="off">
  </div>
  <label class="sh-sr-only" for="jobCat">Filter by category</label>
  <select id="jobCat" class="sh-input sh-input-auto" name="category" onchange="this.form.submit()">
    <option value="0">All categories</option>
    <?php foreach ($categories as $c): ?>
    <option value="<?= (int)$c['id'] ?>" <?= $fCat === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <label class="sh-sr-only" for="jobSort">Sort jobs</label>
  <select id="jobSort" class="sh-input sh-input-auto" name="sort" onchange="this.form.submit()">
    <?php foreach (['newest'=>'Newest first','oldest'=>'Oldest first','title'=>'Title A–Z','applicants'=>'Most applicants','closing'=>'Closing soon'] as $k => $v): ?>
    <option value="<?= $k ?>" <?= $fSort === $k ? 'selected' : '' ?>><?= $v ?></option>
    <?php endforeach; ?>
  </select>
  <button class="sh-btn sh-btn-secondary sh-btn-sm">Apply</button>
</form>

<nav class="sh-flex sh-gap-2 sh-mb-4 sh-wrap" aria-label="Filter jobs by status">
  <a href="<?= $qs(['status' => '', 'page' => '']) ?>" class="sh-chip <?= $fStat === '' ? 'active' : '' ?>" <?= $fStat === '' ? 'aria-current="page"' : '' ?>>All <span class="sh-count"><?= (int)$stat['total'] ?></span></a>
  <?php foreach (['open'=>'Open','paused'=>'Paused','draft'=>'Draft','closed'=>'Closed'] as $st => $lbl): ?>
  <a href="<?= $qs(['status' => $st, 'page' => '']) ?>" class="sh-chip <?= $fStat === $st ? 'active' : '' ?>" <?= $fStat === $st ? 'aria-current="page"' : '' ?>><?= $lbl ?> <span class="sh-count"><?= $jbs[$st] ?? 0 ?></span></a>
  <?php endforeach; ?>
</nav>

<div class="sh-bulkbar" id="shBulkBar" role="toolbar" aria-label="Bulk actions">
  <span><span id="shBulkCount" class="sh-tnum">0</span> selected</span>
  <button class="sh-btn sh-btn-secondary sh-btn-sm" onclick="shJobsBulk('paused')"><i class="fa-solid fa-pause" aria-hidden="true"></i> Pause</button>
  <button class="sh-btn sh-btn-secondary sh-btn-sm" onclick="shJobsBulk('closed')"><i class="fa-solid fa-lock" aria-hidden="true"></i> Close</button>
  <button class="sh-btn sh-btn-secondary sh-btn-sm" onclick="shJobsBulk('open')"><i class="fa-solid fa-lock-open" aria-hidden="true"></i> Reopen</button>
  <button class="sh-btn sh-btn-secondary sh-btn-sm" onclick="shBulkExport(['Title','Location','Type','Status','Applicants','Closes'],'jobs.csv')"><i class="fa-solid fa-download" aria-hidden="true"></i> Export CSV</button>
</div>

<section class="sh-card sh-card-flush" aria-label="Jobs table">
  <?php if (!$jobs): ?>
  <div class="sh-empty">
    <div class="sh-empty-icon"><i class="fa-solid fa-briefcase" aria-hidden="true"></i></div>
    <h3><?= ($q || $fStat || $fCat) ? 'No jobs match these filters' : 'No jobs yet' ?></h3>
    <p><?= ($q || $fStat || $fCat) ? 'Try different filters, or clear them to see every role.' : 'Post your first role to start receiving applications.' ?></p>
    <?php if ($q || $fStat || $fCat): ?>
    <a href="jobs.php" class="sh-btn sh-btn-secondary sh-btn-sm sh-mt-2">Clear filters</a>
    <?php else: ?>
    <a href="jobs.php?new=1#jobform" class="sh-btn sh-btn-primary sh-mt-2">Post your first job</a>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="sh-table-wrap">
    <table class="sh-table">
      <thead>
        <tr>
          <th scope="col" class="sh-col-check"><input type="checkbox" class="sh-check" id="shCheckAll" aria-label="Select all jobs"></th>
          <th scope="col">Role</th>
          <th scope="col">Location · Type</th>
          <th scope="col" class="num">Applicants</th>
          <th scope="col">Status</th>
          <th scope="col">Closes</th>
          <th scope="col"><span class="sh-sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($jobs as $j): $jid = (int)$j['id']; ?>
        <tr data-title="<?= e($j['title']) ?>" data-location="<?= e($j['location'] ?? '') ?>"
            data-type="<?= e($etypes[$j['employment_type']] ?? $j['employment_type']) ?>"
            data-status="<?= e($j['status']) ?>" data-applicants="<?= (int)$j['applicant_count'] ?>"
            data-closes="<?= e($j['closes_on'] ?? '') ?>">
          <td><input type="checkbox" class="sh-check sh-row-check" value="<?= $jid ?>" aria-label="Select <?= e($j['title']) ?>"></td>
          <td data-th="Role">
            <button class="sh-cellbtn" onclick='shShowJob(<?= htmlspecialchars(json_encode([
                  "id"=>$jid,"title"=>$j["title"],"category"=>$j["category_name"],"department"=>$j["department"],
                  "location"=>$j["location"],"etype"=>$etypes[$j["employment_type"]] ?? $j["employment_type"],
                  "exp"=>((int)$j["experience_max"]>0 ? (int)$j["experience_min"]."–".(int)$j["experience_max"]." yrs" : ((int)$j["experience_min"]>0 ? (int)$j["experience_min"]."+ yrs" : "Any")),
                  "openings"=>(int)$j["openings"],"skills"=>$j["skills_required"],"status"=>$j["status"],
                  "applicants"=>(int)$j["applicant_count"],"shortlisted"=>(int)$j["shortlisted_count"],
                  "posted"=>date("d M Y", strtotime($j["created_at"])),"closes"=>$j["closes_on"] ? date("d M Y", strtotime($j["closes_on"])) : "",
                  "desc"=>$j["description"],
                  "events"=>array_map(fn($ev) => ["a"=>$ev["action"],"d"=>$ev["detail"],"t"=>date("d M Y", strtotime($ev["created_at"]))], $events[$jid] ?? []),
                ], JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES) ?>, this)' aria-haspopup="dialog">
              <span class="sh-flex-1">
                <span class="sh-cell-main sh-truncate sh-block"><?= e($j['title']) ?></span>
                <span class="sh-cell-sub sh-truncate sh-block"><?= e($j['category_name'] ?: 'Uncategorized') ?><?= $j['department'] ? ' · ' . e($j['department']) : '' ?></span>
              </span>
            </button>
          </td>
          <td data-th="Location · Type">
            <span class="sh-block"><?= e($j['location'] ?: '—') ?></span>
            <span class="sh-cell-sub"><?= e($etypes[$j['employment_type']] ?? $j['employment_type']) ?></span>
          </td>
          <td data-th="Applicants" class="num">
            <span class="sh-tnum sh-cell-main"><?= (int)$j['applicant_count'] ?></span>
            <?php if ((int)$j['shortlisted_count'] > 0): ?>
            <span class="sh-cell-sub sh-block sh-tnum"><?= (int)$j['shortlisted_count'] ?> shortlisted</span>
            <?php endif; ?>
          </td>
          <td data-th="Status"><?= sh_job_status_badge($j['status']) ?></td>
          <td data-th="Closes" class="sh-cell-sub sh-hide-mobile"><?= $j['closes_on'] ? date('d M Y', strtotime($j['closes_on'])) : '—' ?></td>
          <td>
            <div class="sh-row-actions">
              <a href="applications.php?job_id=<?= $jid ?>" class="sh-iconbtn" aria-label="View applicants for <?= e($j['title']) ?>" title="View applicants"><i class="fa-solid fa-people-arrows" aria-hidden="true"></i></a>
              <a href="<?= $qs(['edit' => $jid]) ?>#jobform" class="sh-iconbtn" aria-label="Edit <?= e($j['title']) ?>" title="Edit"><i class="fa-solid fa-pen" aria-hidden="true"></i></a>
              <a href="<?= $qs(['duplicate' => $jid]) ?>#jobform" class="sh-iconbtn" aria-label="Duplicate <?= e($j['title']) ?>" title="Duplicate"><i class="fa-regular fa-copy" aria-hidden="true"></i></a>
              <div class="sh-rel">
                <button class="sh-iconbtn" data-sh-menu="jmenu-<?= $jid ?>" aria-label="Change status of <?= e($j['title']) ?>" aria-haspopup="true" aria-expanded="false" aria-controls="jmenu-<?= $jid ?>"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button>
                <div class="sh-menu" id="jmenu-<?= $jid ?>" role="menu" aria-label="Job status">
                  <?php foreach (['open'=>'Mark open','paused'=>'Pause','closed'=>'Close','draft'=>'Move to draft'] as $st => $lbl): if ($st === $j['status']) continue; ?>
                  <form method="POST" action="jobs.php" class="sh-inline-form">
                    <?= csrf_field() ?><input type="hidden" name="form_action" value="set_status">
                    <input type="hidden" name="id" value="<?= $jid ?>"><input type="hidden" name="status" value="<?= $st ?>">
                    <button class="sh-menu-item" role="menuitem" <?= $st === 'closed' ? 'data-confirm="Close this job? Candidates can no longer apply."' : '' ?>><?= $lbl ?></button>
                  </form>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<?= sh_pagination($page, $pages, fn($p) => $qs(['page' => $p])) ?>

<!-- Job detail slide-over -->
<aside class="sh-slideover" id="jobPanel" role="dialog" aria-modal="false" aria-labelledby="jpName" aria-hidden="true">
  <div class="sh-slideover-head">
    <div class="sh-flex-1">
      <h2 class="sh-card-title sh-panel-title" id="jpName"></h2>
      <p class="sh-card-sub" id="jpMeta"></p>
    </div>
    <button class="sh-iconbtn" onclick="shCloseSlideover('jobPanel')" aria-label="Close panel"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
  </div>
  <div class="sh-slideover-body">
    <dl class="sh-dl">
      <dt>Status</dt><dd id="jpStatus"></dd>
      <dt>Location</dt><dd id="jpLocation"></dd>
      <dt>Type</dt><dd id="jpType"></dd>
      <dt>Experience</dt><dd id="jpExp"></dd>
      <dt>Openings</dt><dd class="sh-tnum" id="jpOpenings"></dd>
      <dt>Applicants</dt><dd class="sh-tnum" id="jpApplicants"></dd>
      <dt>Skills</dt><dd id="jpSkills"></dd>
      <dt>Description</dt><dd id="jpDesc"></dd>
    </dl>
    <h3 class="sh-card-title sh-panel-title sh-mt-4">Timeline</h3>
    <ul class="sh-timeline" id="jpTimeline"></ul>
  </div>
  <div class="sh-slideover-foot">
    <a class="sh-btn sh-btn-primary" id="jpAppsLink" href="#">View applicants</a>
    <a class="sh-btn sh-btn-secondary" id="jpEditLink" href="#">Edit</a>
    <a class="sh-btn sh-btn-secondary" id="jpDupLink" href="#">Duplicate</a>
  </div>
</aside>

<script>
function shJobsBulk(st) {
  var labels = {open:'reopen', paused:'pause', closed:'close', draft:'move to draft'};
  shBulkPost('jobs.php',
    function (id) { return {form_action:'set_status', id:id, status:st}; },
    'Really ' + labels[st] + ' {n} selected job(s)?');
}
function shShowJob(j, trigger) {
  var set = function (id, v) { document.getElementById(id).textContent = v || '—'; };
  set('jpName', j.title);
  set('jpMeta', [j.category, j.department].filter(Boolean).join(' · '));
  set('jpStatus', j.status);
  set('jpLocation', j.location);
  set('jpType', j.etype);
  set('jpExp', j.exp);
  set('jpOpenings', j.openings);
  set('jpApplicants', j.applicants + (j.shortlisted ? ' (' + j.shortlisted + ' shortlisted)' : ''));
  set('jpSkills', j.skills);
  set('jpDesc', j.desc);
  var tl = document.getElementById('jpTimeline');
  tl.textContent = '';
  var add = function (t, label) {
    var li = document.createElement('li');
    var b = document.createElement('strong'); b.textContent = t;
    li.appendChild(b); li.appendChild(document.createTextNode(' — ' + label));
    tl.appendChild(li);
  };
  add(j.posted, 'Posted');
  (j.events || []).forEach(function (ev) { add(ev.t, ev.a.replace('job_', '').replace('_', ' ') + (ev.d ? ': ' + ev.d : '')); });
  if (j.closes) add(j.closes, j.status === 'closed' ? 'Closed' : 'Closes');
  document.getElementById('jpAppsLink').href = 'applications.php?job_id=' + j.id;
  document.getElementById('jpEditLink').href = 'jobs.php?edit=' + j.id + '#jobform';
  document.getElementById('jpDupLink').href  = 'jobs.php?duplicate=' + j.id + '#jobform';
  shOpenSlideover('jobPanel', trigger);
}
</script>

<!-- Job categories -->
<section class="sh-card sh-mt-4" aria-labelledby="cats-title">
  <div class="sh-card-header">
    <div>
      <h2 class="sh-card-title" id="cats-title">Job categories</h2>
      <p class="sh-card-sub">Used to group roles and filter the jobs list</p>
    </div>
  </div>
  <div class="sh-flex sh-gap-2 sh-wrap sh-mb-4">
    <?php foreach ($categories as $c): ?><span class="sh-chip"><?= e($c['name']) ?></span><?php endforeach; ?>
    <?php if (!$categories): ?><span class="sh-text-muted">No categories yet.</span><?php endif; ?>
  </div>
  <form method="POST" action="jobs.php" class="sh-flex sh-gap-3 sh-wrap sh-items-center">
    <?= csrf_field() ?><input type="hidden" name="form_action" value="add_category">
    <div>
      <label class="sh-sr-only" for="cat_name">New category name</label>
      <input id="cat_name" class="sh-input sh-input-auto" name="cat_name" placeholder="New category name" required maxlength="100">
    </div>
    <div>
      <label class="sh-sr-only" for="cat_desc">Category description (optional)</label>
      <input id="cat_desc" class="sh-input sh-input-auto" name="cat_desc" placeholder="Description (optional)">
    </div>
    <button class="sh-btn sh-btn-secondary">Add category</button>
  </form>
</section>

<?php renderFooter(); ?>
