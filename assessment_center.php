<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Assessment Center (Module 8B) — thin entry point.
//  ARCHITECTURE RULE: no SQL and no business logic here. Every action is one
//  orchestrated call into AssessmentService (the platform facade); rendering
//  is delegated to modules/assessment/admin/ view partials.
// ═════════════════════════════════════════════════════════════════════════════
require_once 'includes/config.php';
require_once 'includes/layout.php';
require_once 'includes/ui_helpers.php';
require_once 'modules/assessment/bootstrap.php';
requireLogin();
requireRole('recruiter');

use SmartHire\Assessment\Engine\AssessmentService;

$svc  = AssessmentService::production();
$user = currentUser();
$uid  = (int)($user['id'] ?? 0);

$VIEWS = ['dashboard', 'bank', 'pools', 'templates', 'generator', 'reviews', 'results'];
$view  = in_array($_GET['view'] ?? '', $VIEWS, true) ? $_GET['view'] : 'dashboard';

// ── POST actions: orchestrate → flash → redirect (PRG) ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $act  = $_POST['form_action'] ?? '';
    $back = 'assessment_center.php?' . http_build_query(array_filter([
        'view' => $_POST['back_view'] ?? $view,
        'q' => $_POST['back_q'] ?? '', 'page' => $_POST['back_page'] ?? '',
        'type' => $_POST['back_type'] ?? '', 'status' => $_POST['back_status'] ?? '',
        'difficulty' => $_POST['back_difficulty'] ?? '', 'pool_id' => $_POST['back_pool'] ?? '',
        'sort' => $_POST['back_sort'] ?? '', 'sid' => $_POST['back_sid'] ?? '',
        'template_id' => $_POST['back_template'] ?? '', 'edit' => $_POST['back_edit'] ?? '',
    ], fn($v) => $v !== '' && $v !== null));

    switch ($act) {
        case 'question_save':
            $qid = (int)($_POST['question_id'] ?? 0) ?: null;
            $r = $svc->saveQuestion($_POST, $qid, $uid);
            if ($r['ok']) { audit_log($qid ? 'question_update' : 'question_create', 'interview_question', $r['id']); setFlash('success', $qid ? 'Question updated.' : 'Question created.'); }
            else setFlash('error', $r['error'] ?? 'Save failed.');
            break;
        case 'question_status':
            $ok = $svc->setQuestionStatus((int)$_POST['question_id'], (string)$_POST['status'], $uid);
            if ($ok) audit_log('question_status', 'interview_question', (int)$_POST['question_id'], (string)$_POST['status']);
            setFlash($ok ? 'success' : 'error', $ok ? 'Status updated.' : 'Status change failed.');
            break;
        case 'question_duplicate':
            $new = $svc->duplicateQuestion((int)$_POST['question_id']);
            if ($new) audit_log('question_duplicate', 'interview_question', $new);
            setFlash($new ? 'success' : 'error', $new ? 'Question duplicated as draft.' : 'Duplicate failed.');
            break;
        case 'pool_create':
            $pid = $svc->createPool((string)($_POST['name'] ?? ''), (string)($_POST['description'] ?? ''), (string)($_POST['tags'] ?? ''), $uid);
            if ($pid) audit_log('pool_create', 'question_preset', $pid);
            setFlash($pid ? 'success' : 'error', $pid ? 'Pool created.' : 'Pool name is required.');
            break;
        case 'pool_clone':
            $new = $svc->clonePool((int)$_POST['pool_id'], (string)($_POST['name'] ?? ''));
            if ($new) audit_log('pool_clone', 'question_preset', $new);
            setFlash($new ? 'success' : 'error', $new ? 'Pool cloned.' : 'Clone failed.');
            break;
        case 'pool_archive':
            $ok = $svc->archivePool((int)$_POST['pool_id']);
            if ($ok) audit_log('pool_archive', 'question_preset', (int)$_POST['pool_id']);
            setFlash($ok ? 'success' : 'error', $ok ? 'Pool archived.' : 'Archive failed.');
            break;
        case 'pool_merge':
            $ok = $svc->mergePools((int)$_POST['source_id'], (int)$_POST['target_id']);
            if ($ok) audit_log('pool_merge', 'question_preset', (int)$_POST['target_id'], 'from #' . (int)$_POST['source_id']);
            setFlash($ok ? 'success' : 'error', $ok ? 'Pools merged; source archived.' : 'Pick two different pools.');
            break;
        case 'pool_add_questions':
            $n = $svc->addQuestionsToPool((int)$_POST['pool_id'], (array)($_POST['question_ids'] ?? []));
            if ($n) audit_log('pool_add_questions', 'question_preset', (int)$_POST['pool_id'], "$n question(s)");
            setFlash($n ? 'success' : 'error', $n ? "$n question(s) added to pool." : 'Select questions and a pool first.');
            break;
        case 'template_save':
            $tid = (int)($_POST['template_id'] ?? 0) ?: null;
            $sections = [];
            foreach ((array)($_POST['sections'] ?? []) as $s) if (is_array($s)) $sections[] = $s;
            $r = $svc->saveTemplate($_POST, $sections, $tid, $uid);
            if ($r['ok']) { audit_log($tid ? 'template_update' : 'template_create', 'assessment_template', $r['id']); setFlash('success', $tid ? 'Template updated.' : 'Template created.'); }
            else setFlash('error', $r['error'] ?? 'Save failed.');
            break;
        case 'template_clone':
            $new = $svc->cloneTemplate((int)$_POST['template_id'], (string)($_POST['name'] ?? ''));
            if ($new) audit_log('template_clone', 'assessment_template', $new);
            setFlash($new ? 'success' : 'error', $new ? 'Template cloned as draft.' : 'Clone failed.');
            break;
        case 'template_status':
            $ok = $svc->setTemplateStatus((int)$_POST['template_id'], (string)$_POST['status']);
            if ($ok) audit_log('template_status', 'assessment_template', (int)$_POST['template_id'], (string)$_POST['status']);
            setFlash($ok ? 'success' : 'error', $ok ? 'Template status updated.' : 'Status change failed.');
            break;
        case 'generate':
            try {
                $out = $svc->generateFromTemplate((int)$_POST['template_id'], (int)$_POST['candidate_id'], $uid,
                    ($_POST['seed'] ?? '') !== '' ? (int)$_POST['seed'] : null);
                audit_log('assessment_generate', 'online_test', $out['id'], 'template #' . (int)$_POST['template_id']);
                $warn = $out['shortfalls'] ? ' (short by ' . array_sum($out['shortfalls']) . ' question(s) — pools underfilled)' : '';
                setFlash('success', 'Assessment generated — ' . $out['question_count'] . ' questions, ' . $out['total_marks'] . ' marks' . $warn . '. The candidate sees it in their portal.');
            } catch (Throwable $e) { setFlash('error', 'Generation failed: ' . $e->getMessage()); }
            break;
        case 'review_save':
            $r = $svc->recordManualScore((int)$_POST['submission_id'], (int)$_POST['answer_id'],
                (int)$_POST['marks'], (string)($_POST['feedback'] ?? ''), $uid);
            if ($r['ok']) audit_log('review_score', 'test_submission', (int)$_POST['submission_id'], 'answer #' . (int)$_POST['answer_id']);
            setFlash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Score saved — submission now at ' . $r['pct'] . '%.' : 'Review save failed.');
            break;
        default:
            setFlash('error', 'Unknown action.');
    }
    header('Location: ' . $back); exit;
}

// ── GET: CSV export streams before any markup ────────────────────────────────
if ($view === 'results' && isset($_GET['export']) && $_GET['export'] === 'csv' && isset($_GET['sid'])) {
    $csv = $svc->resultCsv((int)$_GET['sid']);
    if ($csv !== null) {
        audit_log('result_export', 'test_submission', (int)$_GET['sid'], 'csv');
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="assessment_result_' . (int)$_GET['sid'] . '.csv"');
        echo $csv; exit;
    }
    setFlash('error', 'Submission not found.');
}

// ── Per-view data (orchestration only — service calls, no SQL) ───────────────
$D = [];
$searchQ = trim($_GET['gs'] ?? '');
$D['global'] = $searchQ !== '' ? $svc->globalSearch($searchQ) : null;

switch ($view) {
    case 'dashboard':
        $D['dash'] = $svc->dashboard();
        break;
    case 'bank':
        $D['filters'] = ['q' => trim($_GET['q'] ?? ''), 'type' => $_GET['type'] ?? '', 'difficulty' => $_GET['difficulty'] ?? '',
                         'status' => $_GET['status'] ?? '', 'pool_id' => (int)($_GET['pool_id'] ?? 0), 'sort' => $_GET['sort'] ?? 'newest'];
        $D['page'] = max(1, (int)($_GET['page'] ?? 1));
        $D['bank'] = $svc->bankSearch($D['filters'], $D['page']);
        $D['pools'] = $svc->poolsOverview();
        $D['types'] = $svc->questionTypes();
        $D['edit'] = ((int)($_GET['edit'] ?? 0)) > 0 ? $svc->questions->findRow((int)$_GET['edit']) : null;
        break;
    case 'pools':
        $D['pools'] = $svc->poolsOverview();
        $D['detail'] = ((int)($_GET['pool_id'] ?? 0)) > 0 ? $svc->poolDetail((int)$_GET['pool_id']) : null;
        break;
    case 'templates':
        $D['filters'] = ['q' => trim($_GET['q'] ?? ''), 'status' => $_GET['status'] ?? ''];
        $D['templates'] = $svc->templateList($D['filters']);
        $D['pools'] = $svc->poolsOverview();
        $D['edit'] = ((int)($_GET['edit'] ?? 0)) > 0 ? $svc->templates->find((int)$_GET['edit']) : null;
        break;
    case 'generator':
        $D['templates'] = array_values(array_filter($svc->templateList(), fn($t) => $t['status'] === 'active'));
        $D['candidates'] = $svc->candidateOptions();
        $D['template_id'] = (int)($_GET['template_id'] ?? 0);
        $D['preview'] = $D['template_id'] > 0
            ? $svc->previewFromTemplate($D['template_id'], ($_GET['seed'] ?? '') !== '' ? (int)$_GET['seed'] : null) : null;
        break;
    case 'reviews':
        $D['queue'] = $svc->reviewQueue();
        $D['sid'] = (int)($_GET['sid'] ?? 0);
        $D['workspace'] = $D['sid'] > 0 ? $svc->reviewWorkspace($D['sid']) : null;
        $D['submission'] = $D['sid'] > 0 ? $svc->submissions->find($D['sid']) : null;
        $D['ai'] = [];
        if ($D['workspace']) foreach ($D['workspace'] as $row) {
            if (($row['hr_marks'] ?? null) === null) $D['ai'][(int)$row['id']] = $svc->aiSuggestionFor($row);
        }
        break;
    case 'results':
        $D['filters'] = ['q' => trim($_GET['q'] ?? '')];
        $D['list'] = $svc->resultsList($D['filters']);
        $D['sid'] = (int)($_GET['sid'] ?? 0);
        $D['result'] = $D['sid'] > 0 ? $svc->resultFor($D['sid']) : null;
        $D['detailRow'] = null;
        if ($D['sid'] > 0) foreach ($D['list'] as $r) if ((int)$r['id'] === $D['sid']) { $D['detailRow'] = $r; break; }
        break;
}

$centerTabs = [
    'dashboard' => ['fa-gauge-high', 'Dashboard'],   'bank' => ['fa-circle-question', 'Question Bank'],
    'pools' => ['fa-layer-group', 'Pools'],          'templates' => ['fa-file-invoice', 'Templates'],
    'generator' => ['fa-wand-magic-sparkles', 'Generator'], 'reviews' => ['fa-user-check', 'Review Queue'],
    'results' => ['fa-chart-column', 'Results'],
];
$centerUrl = fn(array $over = []) => 'assessment_center.php?' . http_build_query(array_filter(
    array_merge(['view' => $view], $over), fn($v) => $v !== '' && $v !== null && $v !== 0));

renderHead('Assessment Center', true);
renderSidebar('assessment_center');
require __DIR__ . '/modules/assessment/admin/_shell.php';
require __DIR__ . '/modules/assessment/admin/' . $view . '.php';
require __DIR__ . '/modules/assessment/admin/_search_modal.php';
renderFooter();
