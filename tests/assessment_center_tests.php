<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Assessment Center — Module 8B workflow tests (service-facade level).
//  Required by tests/run_tests.php after the 8A core tests. No real DB:
//  a broader in-memory FakeDb routes the repository SQL surface.
// ═════════════════════════════════════════════════════════════════════════════

use SmartHire\Assessment\Engine\AssessmentService;
use SmartHire\Assessment\Shared\DbAdapter;
use SmartHire\Assessment\Shared\Events;

$fake8b = new class implements DbAdapter {
    public array $bank = [
        1 => ['id' => 1, 'question' => 'Explain indexing', 'question_type' => 'mcq', 'category' => 'technical', 'difficulty' => 'medium', 'position_tag' => 'Backend', 'max_score' => 10, 'correct_option' => 'b', 'option_a' => 'A', 'option_b' => 'B', 'option_c' => 'C', 'option_d' => 'D', 'metadata' => '{}', 'answer_key' => null, 'skills' => 'SQL', 'status' => 'active', 'expected_answer' => ''],
        2 => ['id' => 2, 'question' => 'Rate limiter design', 'question_type' => 'system_design', 'category' => 'technical', 'difficulty' => 'hard', 'position_tag' => 'Backend', 'max_score' => 20, 'correct_option' => '', 'option_a' => '', 'option_b' => '', 'option_c' => '', 'option_d' => '', 'metadata' => '{}', 'answer_key' => null, 'skills' => 'Design', 'status' => 'active', 'expected_answer' => ''],
    ];
    public array $poolItems = [3 => [1, 2]];
    public array $pools = [3 => ['id' => 3, 'name' => 'Core Pool', 'description' => '', 'tags' => '', 'status' => 'active']];
    public array $templates = [];
    public array $sections = [];
    public array $answers = [
        901 => ['id' => 901, 'submission_id' => 500, 'question_id' => 1, 'answer_text' => 'b', 'marks_awarded' => 10, 'is_correct' => 1, 'hr_marks' => null, 'hr_feedback' => null, 'time_spent_secs' => 30, 'weight' => 10, 'section_id' => null, 'question_type' => 'mcq', 'category' => 'technical', 'difficulty' => 'easy', 'skills' => 'SQL', 'metadata' => '{}', 'max_score' => 10],
        902 => ['id' => 902, 'submission_id' => 500, 'question_id' => 2, 'answer_text' => 'essay', 'marks_awarded' => 0, 'is_correct' => 0, 'hr_marks' => null, 'hr_feedback' => null, 'time_spent_secs' => 120, 'weight' => 15, 'section_id' => null, 'question_type' => 'subjective', 'category' => 'hr', 'difficulty' => 'medium', 'skills' => 'Communication', 'metadata' => '{}', 'max_score' => 15],
    ];
    public array $submission = ['id' => 500, 'test_id' => 50, 'candidate_id' => 9, 'status' => 'submitted', 'started_at' => null, 'submitted_at' => null, 'total_score' => 10, 'max_score' => 25, 'percentage' => 40.0, 'time_taken_mins' => 30, 'violations' => 0, 'fullscreen_exits' => 0];
    public array $writes = [];
    public array $lastSearch = [];
    private int $next = 100;

    public function fetchAll(string $sql, string $t = '', mixed ...$p): array {
        if (str_contains($sql, 'FROM interview_questions iq WHERE')) { $this->lastSearch = [$sql, $p]; return array_values($this->bank); }
        if (str_contains($sql, 'FROM test_questions WHERE question_id IN')) return [['question_id' => 1, 'n' => 3]];
        if (str_contains($sql, 'FROM candidate_responses WHERE question_id IN')) return [];
        if (str_contains($sql, 'pi JOIN question_presets qp')) return [['question_id' => 1, 'name' => 'Core Pool']];
        if (str_contains($sql, 'iq.difficulty FROM question_preset_items pi')) {
            $poolId = (int)$p[0]; $out = [];
            foreach ($this->poolItems[$poolId] ?? [] as $qid) $out[] = ['id' => $qid, 'difficulty' => $this->bank[$qid]['difficulty']];
            return $out;
        }
        if (str_contains($sql, 'SELECT question_id FROM question_preset_items WHERE preset_id=?'))
            return array_map(fn($q) => ['question_id' => $q], $this->poolItems[(int)$p[0]] ?? []);
        if (str_contains($sql, 'FROM interview_questions WHERE id IN'))
            return array_values(array_intersect_key($this->bank, array_flip(array_map('intval', $p))));
        if (str_contains($sql, 'FROM question_presets qp WHERE')) return array_values(array_filter($this->pools, fn($x) => ($x['status'] ?? 'active') === 'active'));
        if (str_contains($sql, 'GROUP BY pi.preset_id, iq.difficulty')) return [['preset_id' => 3, 'difficulty' => 'medium', 'n' => 1], ['preset_id' => 3, 'difficulty' => 'hard', 'n' => 1]];
        if (str_contains($sql, 'FROM assessment_template_sections ats')) return [];
        if (str_contains($sql, 'FROM assessment_templates at WHERE')) {
            $out = [];
            foreach ($this->templates as $tp) {
                $tp['section_count'] = count(array_filter($this->sections, fn($s) => $s['template_id'] === $tp['id']));
                $tp['question_count'] = array_sum(array_map(fn($s) => $s['question_count'], array_filter($this->sections, fn($s) => $s['template_id'] === $tp['id'])));
                $tp['issued_count'] = 0; $out[] = $tp;
            }
            return $out;
        }
        if (str_contains($sql, 'FROM assessment_template_sections WHERE template_id=?'))
            return array_values(array_filter($this->sections, fn($s) => $s['template_id'] === (int)$p[0]));
        if (str_contains($sql, 'FROM test_answers ta')) return array_values($this->answers);
        if (str_contains($sql, 'ORDER BY submitted_at ASC')) return [];
        return [];
    }
    public function fetchOne(string $sql, string $t = '', mixed ...$p): ?array {
        if (str_contains($sql, 'COUNT(*) n FROM interview_questions iq WHERE')) return ['n' => 42];
        if (str_contains($sql, 'FROM interview_questions WHERE id=?')) return $this->bank[(int)$p[0]] ?? null;
        if (str_contains($sql, 'FROM question_presets WHERE id=?')) return $this->pools[(int)$p[0]] ?? null;
        if (str_contains($sql, 'FROM assessment_templates WHERE id=?')) return $this->templates[(int)$p[0]] ?? null;
        if (str_contains($sql, 'FROM test_submissions WHERE id=?')) return $this->submission;
        return null;
    }
    public function execute(string $sql, string $t = '', mixed ...$p): bool|int {
        $this->writes[] = ['sql' => preg_replace('/\s+/', ' ', trim($sql)), 'params' => $p];
        if (str_contains($sql, 'INSERT INTO interview_questions')) { $id = $this->next++; $this->bank[$id] = ['id' => $id] + array_combine(
            ['question','question_type','category','difficulty','position_tag','max_score','expected_answer','option_a','option_b','option_c','option_d','correct_option','metadata','answer_key','skills','status'], $p); return $id; }
        if (str_contains($sql, 'UPDATE interview_questions SET question=')) return true;
        if (str_contains($sql, 'INSERT INTO question_presets')) { $id = $this->next++; $this->pools[$id] = ['id' => $id, 'name' => $p[0], 'description' => $p[1], 'tags' => $p[2], 'status' => 'active']; return $id; }
        if (str_contains($sql, 'UPDATE question_presets SET status=?')) { if (isset($this->pools[(int)$p[1]])) $this->pools[(int)$p[1]]['status'] = $p[0]; return true; }
        if (str_contains($sql, 'question_id FROM question_preset_items WHERE preset_id=?')) { $this->poolItems[(int)$p[0]] = array_unique(array_merge($this->poolItems[(int)$p[0]] ?? [], $this->poolItems[(int)$p[1]] ?? [])); return true; }
        if (str_contains($sql, 'INSERT INTO question_preset_items')) { $this->poolItems[(int)$p[0]][] = (int)$p[1]; $this->poolItems[(int)$p[0]] = array_unique($this->poolItems[(int)$p[0]]); return true; }
        if (str_contains($sql, 'INSERT INTO assessment_templates')) { $id = $this->next++; $this->templates[$id] = ['id' => $id, 'name' => $p[0], 'category' => $p[1], 'department' => $p[2], 'role' => $p[3], 'experience_level' => $p[4], 'duration_minutes' => $p[5], 'passing_score' => $p[6], 'max_attempts' => $p[7], 'instructions' => $p[8], 'certification' => $p[9], 'expiry_days' => $p[10], 'config' => $p[11], 'status' => $p[12]]; return $id; }
        if (str_contains($sql, 'INSERT INTO assessment_template_sections')) { $this->sections[] = ['id' => $this->next++, 'template_id' => (int)$p[0], 'name' => $p[1], 'pool_id' => $p[2], 'question_count' => (int)$p[3], 'time_minutes' => $p[4], 'weight' => $p[5], 'difficulty_mix' => $p[6], 'config' => $p[7], 'sort_order' => (int)$p[8]]; return true; }
        if (str_contains($sql, 'DELETE FROM assessment_template_sections')) { $this->sections = array_values(array_filter($this->sections, fn($s) => $s['template_id'] !== (int)$p[0])); return true; }
        if (str_contains($sql, 'UPDATE test_answers SET hr_marks')) { $this->answers[(int)$p[3]]['hr_marks'] = (int)$p[0]; $this->answers[(int)$p[3]]['hr_feedback'] = $p[1]; return true; }
        if (str_contains($sql, 'UPDATE test_submissions SET total_score')) { $this->submission['total_score'] = $p[0]; $this->submission['percentage'] = $p[1]; return true; }
        if (str_contains($sql, 'INSERT INTO online_tests')) return $this->next++;
        return true;
    }
};
$svc8b = new AssessmentService($fake8b);

// ─────────────────────────────────────────────────────────────────────────────
section('8B — Question bank workspace (service facade)');
$res = $svc8b->bankSearch(['q' => 'index', 'type' => 'mcq', 'difficulty' => 'medium', 'status' => 'active', 'pool_id' => 3, 'sort' => 'newest'], 2, 15);
ok($res['total'] === 42 && count($res['rows']) === 2, 'bankSearch returns rows + true total for pagination');
[$sql, $params] = $fake8b->lastSearch;
ok(str_contains($sql, 'question_type = ?') && str_contains($sql, 'difficulty = ?') && str_contains($sql, 'preset_id = ?'), 'every filter reaches the WHERE clause');
ok(str_contains($sql, 'OFFSET 15'), 'server-side pagination offsets page 2');
ok(($res['usage'][1]['tests'] ?? 0) === 3 && ($res['pools'][1][0] ?? '') === 'Core Pool', 'usage counts and pool names enrich the listing');

$bad = $svc8b->saveQuestion(['question' => 'X?', 'question_type' => 'hologram'], null, 1);
ok(!$bad['ok'], 'unknown question type rejected by the registry gate');
$bad = $svc8b->saveQuestion(['question' => 'Pick one', 'question_type' => 'mcq'], null, 1);
ok(!$bad['ok'] && str_contains($bad['error'], 'correct'), 'mcq without a correct option rejected');
$evts = [];
$svc8b->events->subscribe(Events::QUESTION_CREATED, function ($p) use (&$evts) { $evts[] = $p; });
$msq = $svc8b->saveQuestion(['question' => 'Pick ACID props', 'question_type' => 'multi_select', 'difficulty' => 'hard',
    'correct_set' => ['a', 'c'], 'skills' => 'SQL', 'max_score' => 8, 'pool_ids' => [3]], null, 1);
ok($msq['ok'] && $msq['id'] >= 100, 'multi-select question saves through the facade');
$savedRow = $fake8b->bank[$msq['id']];
ok(json_decode($savedRow['answer_key'], true) === ['correct' => ['a', 'c']], 'structured answer_key built from the form input');
ok(in_array($msq['id'], $fake8b->poolItems[3], true), 'new question lands in the selected pool');
ok(count($evts) === 1 && $evts[0]['entity_id'] === $msq['id'], 'question.created event dispatched');
$fb = $svc8b->saveQuestion(['question' => 'Capital of France is ___', 'question_type' => 'fill_blank', 'accepted' => "Paris\n paris "], null, 1);
ok($fb['ok'] && json_decode($fake8b->bank[$fb['id']]['answer_key'], true)['accepted'] === ['Paris', 'paris'], 'fill-blank accepted list parsed line-by-line');
ok(!$svc8b->setQuestionStatus(1, 'vaporised', 1), 'status whitelist enforced');
ok($svc8b->setQuestionStatus(1, 'archived', 1), 'archive via inline status change');

section('8B — Pools workspace');
ok($svc8b->createPool('', '', '', 1) === 0, 'pool creation requires a name');
$pid = $svc8b->createPool('React Pool', 'Frontend set', 'react', 1);
ok($pid >= 100, 'pool created through the facade');
$cloneId = $svc8b->clonePool(3, 'Core Pool (copy)');
ok($cloneId >= 100 && $fake8b->poolItems[$cloneId] === $fake8b->poolItems[3], 'clone copies every pool item');
ok(!$svc8b->mergePools(3, 3), 'merging a pool into itself refused');
ok($svc8b->mergePools($cloneId, $pid), 'merge copies items and archives the source');
ok(($fake8b->pools[$cloneId]['status'] ?? '') === 'archived', 'merge source archived');
ok(in_array(1, $fake8b->poolItems[$pid], true), 'merge target received the questions');
$pd = $svc8b->poolDetail(3);
ok($pd !== null && isset($pd['by_difficulty'], $pd['skills'], $pd['used_by']), 'pool detail bundles stats + dependencies');

section('8B — Template builder + generator preview');
$badT = $svc8b->saveTemplate(['name' => ''], [], null, 1);
ok(!$badT['ok'], 'template requires a name');
$badT = $svc8b->saveTemplate(['name' => 'X'], [['name' => '', 'question_count' => 0]], null, 1);
ok(!$badT['ok'], 'template requires at least one valid section');
$tpl = $svc8b->saveTemplate(
    ['name' => 'Backend Screen', 'role' => 'Backend Dev', 'duration_minutes' => 45, 'passing_score' => 55,
     'max_attempts' => 2, 'negative_marking' => '2.5', 'randomize' => '1', 'status' => 'active'],
    [['name' => 'Core', 'pool_id' => 3, 'question_count' => 2, 'mix_medium' => 1, 'mix_hard' => 1, 'time_minutes' => '', 'weight' => 1]],
    null, 1);
ok($tpl['ok'], 'template with section saves');
$savedTpl = $fake8b->templates[$tpl['id']];
$savedCfg = json_decode($savedTpl['config'], true);
ok((float)$savedCfg['negative_marking'] === 1.0 && $savedCfg['randomize'] === true && $savedCfg['passing_pct'] === 55, 'config layer built + negative marking clamped to 1.0');
ok(json_decode($fake8b->sections[0]['difficulty_mix'], true) === ['medium' => 1, 'hard' => 1], 'section difficulty mix persisted');
$cl = $svc8b->cloneTemplate($tpl['id'], 'Backend Screen v2');
ok($cl >= 100 && $fake8b->templates[$cl]['status'] === 'draft', 'template clone lands as draft');
$pv = $svc8b->previewFromTemplate($tpl['id'], 7);
ok($pv['ok'] && $pv['total_questions'] === 2, 'preview dry-run selects the sectioned count');
ok($pv['total_marks'] === 30 && $pv['difficulty']['hard'] === 1, 'preview aggregates marks + difficulty from the bank');
ok(($pv['skills']['SQL'] ?? 0) === 1, 'preview reports skill coverage');
ok(!$svc8b->previewFromTemplate(9999)['ok'], 'preview refuses unknown templates');

section('8B — Review queue: manual scoring recompute');
$evR = null;
$svc8b->events->subscribe(Events::REVIEW_COMPLETED, function ($p) use (&$evR) { $evR = $p; });
$r = $svc8b->recordManualScore(500, 902, 99, 'great depth', 1);
ok($r['ok'], 'manual score records through the facade');
ok($fake8b->answers[902]['hr_marks'] === 15, 'score clamped to the question weight (99 → 15)');
ok($r['total'] === 25.0 && $r['pct'] === 100.0, 'totals recomputed with the auto+hr rule (10 auto + 15 hr / 25)');
ok((float)$fake8b->submission['percentage'] === 100.0, 'submission row updated');
ok($evR !== null && $evR['entity_id'] === 500, 'review.completed event dispatched');
ok(!$svc8b->recordManualScore(500, 777, 5, '', 1)['ok'], 'unknown answer refused');

section('8B — Results workspace: CSV export + search');
$csv = $svc8b->resultCsv(500);
ok($csv !== null && str_starts_with($csv, '"Submission","Overall %"'), 'CSV starts with the summary header');
ok(substr_count($csv, "\r\n") >= 7 && str_contains($csv, '"Skill","Score"'), 'CSV contains question + skill sections');
ok(str_contains($csv, '"100"') || str_contains($csv, '"100.0"') || str_contains($csv, '"25"'), 'CSV reflects the recomputed totals');
ok($svc8b->globalSearch('  ') === [], 'blank global search returns nothing (no wasted queries)');
ok(is_array($svc8b->candidateOptions()), 'candidate picker flows through the service');
$dash = $svc8b->dashboard();
ok(isset($dash['overview']['pending_reviews'], $dash['activity'], $dash['pass_fail'], $dash['top_templates'], $dash['difficulty']), 'dashboard payload carries every chart dataset');
