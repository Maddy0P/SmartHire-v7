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

function jstatus_badge(string $s): string {
    $map = ['open'=>'green','paused'=>'amber','closed'=>'gray','draft'=>'blue'];
    return '<span class="stage-badge stage-' . ($map[$s] ?? 'gray') . '">' . ucfirst($s) . '</span>';
}

renderHead('Jobs');
renderSidebar('jobs');
?>
<div class="page-header sh-between">
  <div class="page-header-left">
    <h1>Job Postings</h1>
    <p class="sh-muted">Create and manage open roles · <?= (int)$stat['total'] ?> total · <?= (int)$stat['open_cnt'] ?> open · <?= (int)$stat['app_total'] ?> applications</p>
  </div>
  <div class="sh-flex">
    <a href="jobs.php?new=1#jobform" class="btn btn-primary"><i class="fa-solid fa-plus"></i> New Job</a>
  </div>
</div>

<?php if ($showForm): ?>
<!-- ── Create / Edit form ── -->
<div class="card sh-mb" id="jobform">
  <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-briefcase"></i> <?= $editing ? 'Edit Job' : 'Post a New Job' ?></h3></div>
  <div class="card-body">
    <form method="POST" action="jobs.php">
      <?= csrf_field() ?>
      <input type="hidden" name="form_action" value="save">
      <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
      <div class="sh-grid sh-grid-2">
        <div class="form-group">
          <label>Job Title *</label>
          <input class="form-control" name="title" required maxlength="150" value="<?= e($editing['title'] ?? '') ?>" placeholder="e.g. Backend Engineer">
        </div>
        <div class="form-group">
          <label>Category</label>
          <select class="form-control" name="category_id">
            <option value="">— Select —</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (int)($editing['category_id'] ?? 0)===(int)$c['id']?'selected':'' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Department</label><input class="form-control" name="department" value="<?= e($editing['department'] ?? '') ?>" placeholder="Engineering"></div>
        <div class="form-group"><label>Location</label><input class="form-control" name="location" value="<?= e($editing['location'] ?? '') ?>" placeholder="Pune / Remote"></div>
        <div class="form-group">
          <label>Employment Type</label>
          <select class="form-control" name="employment_type">
            <?php foreach (['full_time'=>'Full-time','part_time'=>'Part-time','contract'=>'Contract','internship'=>'Internship','remote'=>'Remote'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($editing['employment_type'] ?? '')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Openings</label><input class="form-control" type="number" min="1" name="openings" value="<?= (int)($editing['openings'] ?? 1) ?>"></div>
        <div class="form-group"><label>Experience Min (yrs)</label><input class="form-control" type="number" min="0" name="experience_min" value="<?= (int)($editing['experience_min'] ?? 0) ?>"></div>
        <div class="form-group"><label>Experience Max (yrs)</label><input class="form-control" type="number" min="0" name="experience_max" value="<?= (int)($editing['experience_max'] ?? 0) ?>"></div>
        <div class="form-group"><label>Salary Min (<?= e($editing['currency'] ?? 'INR') ?>)</label><input class="form-control" type="number" name="salary_min" value="<?= e((string)($editing['salary_min'] ?? '')) ?>"></div>
        <div class="form-group"><label>Salary Max</label><input class="form-control" type="number" name="salary_max" value="<?= e((string)($editing['salary_max'] ?? '')) ?>"></div>
        <div class="form-group">
          <label>Status</label>
          <select class="form-control" name="status">
            <?php foreach (['open'=>'Open','draft'=>'Draft','paused'=>'Paused','closed'=>'Closed'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($editing['status'] ?? 'open')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Closes On</label><input class="form-control" type="date" name="closes_on" value="<?= e($editing['closes_on'] ?? '') ?>"></div>
      </div>
      <div class="form-group"><label>Required Skills <span class="sh-muted">(comma separated — powers ATS matching)</span></label>
        <input class="form-control" name="skills_required" value="<?= e($editing['skills_required'] ?? '') ?>" placeholder="Python, Django, PostgreSQL, Docker, AWS"></div>
      <div class="form-group"><label>Job Description</label>
        <textarea class="form-control" name="description" rows="4" placeholder="Role responsibilities and about the team…"><?= e($editing['description'] ?? '') ?></textarea></div>
      <div class="form-group"><label>Requirements / Qualifications</label>
        <textarea class="form-control" name="requirements" rows="3" placeholder="Must-have qualifications…"><?= e($editing['requirements'] ?? '') ?></textarea></div>
      <div class="sh-flex">
        <button class="btn btn-primary"><i class="fa-solid fa-check"></i> <?= $editing ? 'Save Changes' : 'Post Job' ?></button>
        <a href="jobs.php" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ── Filters ── -->
<form method="GET" class="filter-bar" action="jobs.php">
  <div class="search"><i class="fa-solid fa-search"></i>
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search job titles…" aria-label="Search jobs">
  </div>
  <select name="status" aria-label="Filter by status" onchange="this.form.submit()">
    <option value="">All statuses</option>
    <?php foreach (['open','paused','closed','draft'] as $st): ?>
    <option value="<?= $st ?>" <?= $fStat===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="category" aria-label="Filter by category" onchange="this.form.submit()">
    <option value="0">All categories</option>
    <?php foreach ($categories as $c): ?>
    <option value="<?= (int)$c['id'] ?>" <?= $fCat===(int)$c['id']?'selected':'' ?>><?= e($c['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-secondary"><i class="fa-solid fa-filter"></i> Apply</button>
</form>

<!-- ── Job grid ── -->
<?php if (!$jobs): ?>
<div class="sh-empty">
  <i class="fa-solid fa-briefcase"></i>
  <h3>No jobs found</h3>
  <p>Post your first role to start receiving applications.</p>
  <a href="jobs.php?new=1#jobform" class="btn btn-primary"><i class="fa-solid fa-plus"></i> New Job</a>
</div>
<?php else: ?>
<div class="sh-grid sh-grid-3">
  <?php foreach ($jobs as $j): ?>
  <div class="job-card">
    <div class="sh-between">
      <h3><?= e($j['title']) ?></h3>
      <?= jstatus_badge($j['status']) ?>
    </div>
    <div class="job-meta">
      <?php if ($j['category_name']): ?><span><i class="fa-solid fa-layer-group"></i> <?= e($j['category_name']) ?></span><?php endif; ?>
      <?php if ($j['location']): ?><span><i class="fa-solid fa-location-dot"></i> <?= e($j['location']) ?></span><?php endif; ?>
      <span><i class="fa-solid fa-briefcase"></i> <?= ucfirst(str_replace('_',' ',$j['employment_type'])) ?></span>
      <?php if ((int)$j['experience_max'] > 0): ?><span><i class="fa-solid fa-clock"></i> <?= (int)$j['experience_min'] ?>–<?= (int)$j['experience_max'] ?> yrs</span><?php endif; ?>
    </div>
    <?php if ($j['skills_required']): ?>
    <div class="skill-tags">
      <?php foreach (array_slice(sh_parse_skills($j['skills_required']),0,5) as $sk): ?>
      <span class="skill-tag"><?= e(ucfirst($sk)) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="job-foot">
      <span class="sh-chip"><i class="fa-solid fa-users"></i> <?= (int)$j['applicant_count'] ?> applicants</span>
      <div class="sh-flex">
        <a href="applications.php?job_id=<?= (int)$j['id'] ?>" class="btn btn-sm btn-secondary" title="View applicants"><i class="fa-solid fa-people-arrows"></i></a>
        <a href="jobs.php?edit=<?= (int)$j['id'] ?>#jobform" class="btn btn-sm btn-secondary" title="Edit"><i class="fa-solid fa-pen"></i></a>
        <?php if ($j['status']!=='closed'): ?>
        <form method="POST" action="jobs.php" style="display:inline">
          <?= csrf_field() ?><input type="hidden" name="form_action" value="set_status">
          <input type="hidden" name="id" value="<?= (int)$j['id'] ?>"><input type="hidden" name="status" value="closed">
          <button class="btn btn-sm btn-danger" title="Close job" data-confirm="Close this job? Candidates can no longer apply."><i class="fa-solid fa-lock"></i></button>
        </form>
        <?php else: ?>
        <form method="POST" action="jobs.php" style="display:inline">
          <?= csrf_field() ?><input type="hidden" name="form_action" value="set_status">
          <input type="hidden" name="id" value="<?= (int)$j['id'] ?>"><input type="hidden" name="status" value="open">
          <button class="btn btn-sm btn-success" title="Reopen job"><i class="fa-solid fa-lock-open"></i></button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Pagination ── -->
<?php if ($pages > 1): $qs = fn($p)=>'jobs.php?'.http_build_query(array_filter(['q'=>$q,'status'=>$fStat,'category'=>$fCat?:'','page'=>$p])); ?>
<nav class="pagination" aria-label="Pagination">
  <a class="<?= $page<=1?'disabled':'' ?>" href="<?= $qs(max(1,$page-1)) ?>">‹ Prev</a>
  <?php for ($p=1;$p<=$pages;$p++): ?>
    <a class="<?= $p===$page?'current':'' ?>" href="<?= $qs($p) ?>"><?= $p ?></a>
  <?php endfor; ?>
  <a class="<?= $page>=$pages?'disabled':'' ?>" href="<?= $qs(min($pages,$page+1)) ?>">Next ›</a>
</nav>
<?php endif; ?>
<?php endif; ?>

<!-- ── Category quick-add ── -->
<div class="card sh-mt">
  <div class="card-header"><h3 class="card-title"><i class="fa-solid fa-layer-group"></i> Job Categories</h3></div>
  <div class="card-body">
    <div class="skill-tags sh-mb">
      <?php foreach ($categories as $c): ?><span class="sh-chip"><?= e($c['name']) ?></span><?php endforeach; ?>
    </div>
    <form method="POST" action="jobs.php" class="sh-flex sh-wrap">
      <?= csrf_field() ?><input type="hidden" name="form_action" value="add_category">
      <input class="form-control" name="cat_name" placeholder="New category name" style="max-width:220px" required>
      <input class="form-control" name="cat_desc" placeholder="Description (optional)" style="max-width:260px">
      <button class="btn btn-secondary"><i class="fa-solid fa-plus"></i> Add</button>
    </form>
  </div>
</div>

<?php renderFooter(); ?>
