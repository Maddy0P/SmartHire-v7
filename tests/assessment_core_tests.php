<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Assessment Platform Core — Module 8A tests
//  Required by tests/run_tests.php (uses its ok()/section() helpers).
//  No DB needed: pure engines + an in-memory FakeDb for repositories/service.
// ═════════════════════════════════════════════════════════════════════════════

require_once dirname(__DIR__) . '/modules/assessment/bootstrap.php';

use SmartHire\Assessment\Domain\Question;
use SmartHire\Assessment\Engine\AssessmentService;
use SmartHire\Assessment\Engine\Generator;
use SmartHire\Assessment\Engine\QTypeRegistry;
use SmartHire\Assessment\Results\ResultEngine;
use SmartHire\Assessment\Scoring\ScoringEngine;
use SmartHire\Assessment\Shared\AssessmentConfig;
use SmartHire\Assessment\Shared\DbAdapter;
use SmartHire\Assessment\Shared\EventBus;
use SmartHire\Assessment\Shared\Events;
use SmartHire\Assessment\Shared\PluginRegistry;

// ─────────────────────────────────────────────────────────────────────────────
section('8A — QType registry (single type authority)');
$ALL = QTypeRegistry::all();
ok(count($ALL) === 32, 'registry holds 32 types');
foreach (['mcq','subjective'] as $t) ok(QTypeRegistry::isDeliverable($t), "legacy type '$t' stays deliverable");
$directive = ['multi_select','true_false','fill_blank','short_answer','long_answer','essay','paragraph','rating_scale',
              'coding','sql_query','debug_code','output_prediction','algorithm','cloud_scenario','system_design','api_design','database_design',
              'case_study','business_scenario','incident_response','customer_support','team_management','project_management',
              'video_response','audio_response','screen_recording','whiteboard','file_upload','diagram_builder','interactive_lab'];
$missing = array_diff($directive, array_keys($ALL));
ok($missing === [], 'every directive type registered (' . (count($directive)) . ')');
ok(!QTypeRegistry::isValid('hologram'), 'unknown type rejected by isValid()');
ok(QTypeRegistry::byGroup('future') === ['video_response','audio_response','screen_recording','whiteboard','file_upload','diagram_builder','interactive_lab'], 'future group complete');
$futureDeliverable = array_filter(QTypeRegistry::byGroup('future'), fn($t) => QTypeRegistry::isDeliverable($t));
ok($futureDeliverable === [], 'future types authorable but not yet deliverable');
$strategies = array_unique(array_column($ALL, 'scoring'));
ok(array_diff($strategies, ['mcq','manual','multi_select','boolean','text_match','exact_output']) === [], 'every type maps to a known scoring strategy');
ok(!QTypeRegistry::isAutoScorable('essay') && QTypeRegistry::isAutoScorable('true_false'), 'auto-scorable derived from strategy');

// ─────────────────────────────────────────────────────────────────────────────
section('8A — AssessmentConfig layer (defaults ← template ← frozen instance)');
$def = AssessmentConfig::make();
ok($def->get('negative_marking') === 0.0 && $def->get('partial_credit') === false, 'defaults are legacy-equivalent (no negatives, no partial)');
$cfg = AssessmentConfig::fromInstance(['negative_marking' => 0.25, 'passing_pct' => 50], ['passing_pct' => 60]);
ok($cfg->get('negative_marking') === 0.25, 'template layer overrides defaults');
ok($cfg->get('passing_pct') === 60, 'frozen instance snapshot beats template');
$snap = json_decode(AssessmentConfig::make(['partial_credit' => true])->snapshot(), true);
ok(($snap['partial_credit'] ?? null) === true && isset($snap['recommendation_bands']), 'snapshot() serializes the full effective config');
ok($def->recommendationFor(90.0) === 'strong_yes' && $def->recommendationFor(70.0) === 'yes'
   && $def->recommendationFor(50.0) === 'maybe' && $def->recommendationFor(10.0) === 'no', 'recommendation bands resolve in order');
$bandsCfg = AssessmentConfig::make(['recommendation_bands' => [['min' => 90, 'value' => 'strong_yes'], ['min' => 0, 'value' => 'no']]]);
ok($bandsCfg->recommendationFor(70.0) === 'no', 'bands are config, not hard-coded');

// ─────────────────────────────────────────────────────────────────────────────
section('8A — Scoring parity: engine ≡ pre-8A take_test.php inline logic');
// Byte-copied reference of the legacy branch (the regression oracle):
$legacyRef = function (array $q, $ans): array {
    $marks = 0; $correct = 0; $selected = null;
    if ($q['question_type'] === 'mcq') { $selected = $ans; if ($selected && $selected === $q['correct_option']) { $marks = $q['marks']; $correct = 1; } }
    return [$marks, $correct, $selected ?? ''];   // exact write triple
};
$eng = new ScoringEngine();
$dcf = AssessmentConfig::make();
$parity = function (array $q, $ans) use ($eng, $dcf, $legacyRef): bool {
    $sc = $eng->scoreAnswer(Question::fromRow($q), (int)$q['marks'], $ans, $dcf);
    return [(int)round($sc->marks), $sc->isCorrect, $sc->selectedOption ?? ''] === $legacyRef($q, $ans);
};
$mcq  = ['id' => 1, 'question_type' => 'mcq', 'correct_option' => 'b', 'marks' => 10];
$subj = ['id' => 2, 'question_type' => 'subjective', 'correct_option' => null, 'marks' => 15];
ok($parity($mcq, 'b'),  'mcq: correct option → full weight, correct=1');
ok($parity($mcq, 'a'),  'mcq: wrong option → 0, selection still stored');
ok($parity($mcq, ''),   'mcq: blank answer → 0');
ok($parity($mcq, '0'),  "mcq: '0' answer preserves legacy truthy semantics");
ok($parity(['id' => 3, 'question_type' => 'mcq', 'correct_option' => '', 'marks' => 5], ''), 'mcq: blank vs blank key never matches (legacy)');
ok($parity($subj, 'my long essay answer'), 'subjective: 0 marks, empty selection (HR lane)');
ok($parity($subj, ''), 'subjective: blank → same');
$scSub = $eng->scoreAnswer(Question::fromRow($subj), 15, 'text', $dcf);
ok($scSub->needsReview === true, 'subjective flags needsReview for the manual lane');
$scM = $eng->scoreAnswer(Question::fromRow($mcq), 10, 'a', $dcf);
ok($scM->needsReview === false && $scM->marks === 0.0, 'default config never penalises wrong mcq (legacy parity)');

section('8A — Scoring: extended strategies (config/registry-gated)');
$tf = ['id' => 4, 'question_type' => 'true_false', 'marks' => 4, 'answer_key' => '{"value": true}'];
ok($eng->scoreAnswer(Question::fromRow($tf), 4, 'true', $dcf)->marks === 4.0,  'true_false: correct');
ok($eng->scoreAnswer(Question::fromRow($tf), 4, 'false', $dcf)->marks === 0.0, 'true_false: wrong');
ok($eng->scoreAnswer(Question::fromRow($tf), 4, '', $dcf)->marks === 0.0,      'true_false: blank scores zero, no penalty');
ok($eng->scoreAnswer(Question::fromRow(['id' => 5, 'question_type' => 'true_false', 'marks' => 4]), 4, 'true', $dcf)->needsReview, 'true_false: missing answer_key falls to review lane');
$fb = ['id' => 6, 'question_type' => 'fill_blank', 'marks' => 5, 'answer_key' => '{"accepted": ["Polymorphism", "poly morphism"]}'];
ok($eng->scoreAnswer(Question::fromRow($fb), 5, '  polymorphism ', $dcf)->marks === 5.0, 'fill_blank: trim/case-insensitive match');
ok($eng->scoreAnswer(Question::fromRow($fb), 5, 'inheritance', $dcf)->marks === 0.0,     'fill_blank: non-match');
$op = ['id' => 7, 'question_type' => 'output_prediction', 'marks' => 6, 'answer_key' => '{"expected_output": "42"}'];
ok($eng->scoreAnswer(Question::fromRow($op), 6, '42', $dcf)->marks === 6.0, 'output_prediction: exact match');
$ms = ['id' => 8, 'question_type' => 'multi_select', 'marks' => 9, 'answer_key' => '{"correct": ["a","c","d"]}'];
ok($eng->scoreAnswer(Question::fromRow($ms), 9, ['a','c','d'], $dcf)->marks === 9.0, 'multi_select all-or-nothing: exact set → full');
ok($eng->scoreAnswer(Question::fromRow($ms), 9, ['a','c'], $dcf)->marks === 0.0,     'multi_select all-or-nothing: subset → 0 (partial off by default)');
$pcf = AssessmentConfig::make(['partial_credit' => true]);
ok($eng->scoreAnswer(Question::fromRow($ms), 9, ['a','c'], $pcf)->marks === 6.0,      'multi_select partial: 2/3 correct → 6/9');
ok($eng->scoreAnswer(Question::fromRow($ms), 9, ['a','c','b'], $pcf)->marks === 3.0,  'multi_select partial: wrong pick deducts (2−1)/3');
ok($eng->scoreAnswer(Question::fromRow($ms), 9, ['b'], $pcf)->marks === 0.0,          'multi_select partial: clamped at zero');
$ncf = AssessmentConfig::make(['negative_marking' => 0.25]);
ok($eng->scoreAnswer(Question::fromRow($mcq), 10, 'a', $ncf)->marks === -2.5, 'negative marking: wrong mcq → −0.25 × weight');
ok($eng->scoreAnswer(Question::fromRow($mcq), 10, '', $ncf)->marks === 0.0,   'negative marking: blank never penalised');
$flr = AssessmentConfig::make(['negative_marking' => 0.5, 'min_question_marks' => -2.0]);
ok($eng->scoreAnswer(Question::fromRow($mcq), 10, 'a', $flr)->marks === -2.0, 'negative marking respects min_question_marks floor');
ok($eng->scoreAnswer(Question::fromRow($subj), 15, 'essay', $ncf)->marks === 0.0, 'negative marking never touches the manual lane');
$bonusQ = ['id' => 9, 'question_type' => 'mcq', 'correct_option' => 'a', 'marks' => 5, 'metadata' => '{"bonus": true}'];
ok(Question::fromRow($bonusQ)->isBonus(), 'bonus flag read from metadata');

// ─────────────────────────────────────────────────────────────────────────────
section('8A — Generator (pure, seeded, section-aware)');
$gen = new Generator();
$pool = ['easy' => [1,2,3,4], 'medium' => [5,6,7,8], 'hard' => [9,10]];
$sel = $gen->select([['name' => 'S1', 'pool' => $pool, 'question_count' => 5,
                      'difficulty_mix' => ['easy' => 2, 'medium' => 2, 'hard' => 1]]], AssessmentConfig::make());
ok(count($sel['question_ids']) === 5, 'generator fills the requested count');
$byDiff = fn(array $ids, array $p) => [count(array_intersect($ids, $p['easy'])), count(array_intersect($ids, $p['medium'])), count(array_intersect($ids, $p['hard']))];
ok($byDiff($sel['question_ids'], $pool) === [2,2,1], 'difficulty mix honoured exactly');
ok($sel['question_ids'] === [1,2,5,6,9], 'no randomize → deterministic first-N order');
$two = $gen->select([
    ['name' => 'A', 'pool' => $pool, 'question_count' => 4],
    ['name' => 'B', 'pool' => $pool, 'question_count' => 4],
], AssessmentConfig::make());
ok(count(array_unique($two['question_ids'])) === 8, 'no question repeats across sections');
$short = $gen->select([['name' => 'S', 'pool' => ['easy' => [1], 'medium' => [], 'hard' => []], 'question_count' => 5]], AssessmentConfig::make());
ok($short['sections'][0]['shortfall'] === 4 && $short['question_ids'] === [1], 'underfill allowed: shortfall reported');
$threw = false;
try { $gen->select([['name' => 'S', 'pool' => ['easy' => [1], 'medium' => [], 'hard' => []], 'question_count' => 5]],
                   AssessmentConfig::make(['allow_underfill' => false])); }
catch (RuntimeException $e) { $threw = true; }
ok($threw, 'underfill disallowed: generation refuses');
$r1 = $gen->select([['name' => 'S', 'pool' => $pool, 'question_count' => 6]], AssessmentConfig::make(['randomize' => true]), seed: 42);
$r2 = $gen->select([['name' => 'S', 'pool' => $pool, 'question_count' => 6]], AssessmentConfig::make(['randomize' => true]), seed: 42);
$r3 = $gen->select([['name' => 'S', 'pool' => $pool, 'question_count' => 6]], AssessmentConfig::make(['randomize' => true]), seed: 7);
ok($r1['question_ids'] === $r2['question_ids'], 'same seed → reproducible selection');
ok($r1['question_ids'] !== $r3['question_ids'], 'different seed → different order');

// ─────────────────────────────────────────────────────────────────────────────
section('8A — ResultEngine (overall/section/skill/difficulty/time/trend)');
$rows = [
    ['question_id' => 1, 'question_type' => 'mcq', 'difficulty' => 'easy',   'skills' => 'SQL,Databases', 'weight' => 10, 'marks_awarded' => 10, 'is_correct' => 1, 'section_id' => 1, 'time_spent_secs' => 30],
    ['question_id' => 2, 'question_type' => 'mcq', 'difficulty' => 'hard',   'skills' => 'SQL',           'weight' => 10, 'marks_awarded' => 0,  'is_correct' => 0, 'section_id' => 1, 'time_spent_secs' => 90],
    ['question_id' => 3, 'question_type' => 'subjective', 'difficulty' => 'medium', 'skills' => 'Communication', 'weight' => 20, 'marks_awarded' => 0, 'hr_marks' => 15, 'section_id' => 2, 'time_spent_secs' => 120],
    ['question_id' => 4, 'question_type' => 'essay', 'difficulty' => 'medium', 'skills' => 'Communication', 'weight' => 10, 'marks_awarded' => 0, 'hr_marks' => null, 'section_id' => 2, 'time_spent_secs' => 60],
    ['question_id' => 5, 'question_type' => 'mcq', 'difficulty' => 'easy', 'skills' => 'SQL', 'weight' => 5, 'marks_awarded' => 5, 'is_correct' => 1, 'section_id' => 1, 'metadata' => '{"bonus": true}', 'time_spent_secs' => 10],
];
$res = (new ResultEngine())->analyze($rows, AssessmentConfig::make(), [1 => 'Technical', 2 => 'Written'], [['percentage' => 40.0], ['percentage' => 55.0]]);
ok($res->maxMarks === 50.0, 'bonus question excluded from max marks');
ok($res->totalMarks === 30.0, 'earned = auto marks + hr_marks + bonus marks');
ok($res->overallPct === 60.0, 'overall percentage computed');
ok($res->passed === true, 'passed at default 40% threshold');
ok($res->pendingReview === 1, 'unreviewed manual answers counted as pending');
ok(($res->sections['Technical']['pct'] ?? 0) === 75.0 && ($res->sections['Written']['pct'] ?? 0) === 50.0, 'section scores bucketed by name');
ok(($res->skills['SQL']['score'] ?? 0) === 15.0 && ($res->skills['Communication']['pct'] ?? 0) === 50.0, 'skill analysis from comma tags');
ok(($res->difficulty['hard']['pct'] ?? 1) === 0.0, 'difficulty analysis bucketed');
ok($res->timeAnalysis['total_secs'] === 310 && $res->timeAnalysis['slowest']['question_id'] === 3, 'time analysis totals + slowest question');
ok($res->trend === [40.0, 55.0], 'trend carries prior attempt percentages');
ok($res->recommendation === 'maybe', 'recommendation from config bands');
ok($res->suggestions !== [] , 'improvement suggestions generated');
$resHi = (new ResultEngine())->analyze([$rows[0]], AssessmentConfig::make());
ok($resHi->strengths !== [] && $resHi->recommendation === 'strong_yes', 'strengths + strong_yes at 100%');

// ─────────────────────────────────────────────────────────────────────────────
section('8A — EventBus (event-driven core)');
$bus = new EventBus();
$seen = [];
$bus->subscribe(Events::SUBMISSION_SCORED, function ($p) use (&$seen) { $seen[] = $p['entity_id']; });
$bus->subscribe(Events::SUBMISSION_SCORED, function () { throw new RuntimeException('bad listener'); });
$outboxRows = [];
$bus->attachOutbox(function ($event, $payload) use (&$outboxRows) { $outboxRows[] = [$event, $payload]; });
$bus->dispatch(Events::SUBMISSION_SCORED, ['entity' => 'test_submission', 'entity_id' => 77]);
ok($seen === [77], 'listeners receive dispatched payloads');
ok($outboxRows[0][0] === Events::SUBMISSION_SCORED, 'outbox sink receives every dispatch');
ok(true, 'throwing listener never breaks the dispatch flow');   // reaching here proves isolation
ok($bus->listenerCount(Events::SUBMISSION_SCORED) === 2, 'listener registry counts subscriptions');

// ─────────────────────────────────────────────────────────────────────────────
section('8A — AI extension interfaces (contracts only)');
foreach (['AiQuestionAuthor', 'AiAnswerEvaluator', 'AiInsightWriter'] as $i) {
    ok(interface_exists("SmartHire\\Assessment\\Shared\\$i"), "interface $i declared");
    ok(!class_exists("SmartHire\\Assessment\\Shared\\{$i}Impl"), "no premature $i implementation shipped");
}

// ─────────────────────────────────────────────────────────────────────────────
section('8A — AssessmentService facade over an in-memory DB');
/** Minimal in-memory DbAdapter: enough SQL routing for the facade paths under test. */
$fake = new class implements DbAdapter {
    public array $tests = []; public array $testQuestions = []; public array $outbox = []; public array $plugins = [];
    private int $nextId = 100;
    public array $templates = [
        7 => ['id' => 7, 'name' => 'Cloud Engineer Screen', 'duration_minutes' => 60, 'passing_score' => 50,
              'expiry_days' => 5, 'instructions' => 'Good luck', 'config' => '{"randomize": false}'],
    ];
    public array $sections = [
        ['id' => 71, 'template_id' => 7, 'name' => 'Cloud', 'pool_id' => 3, 'question_count' => 2,
         'time_minutes' => null, 'weight' => 1, 'difficulty_mix' => '{"easy":1,"medium":1}', 'config' => '{}', 'sort_order' => 1],
    ];
    public array $bank = [
        11 => ['id' => 11, 'question_type' => 'mcq', 'category' => 'technical', 'difficulty' => 'easy',   'question' => 'Q11', 'max_score' => 10, 'correct_option' => 'a'],
        12 => ['id' => 12, 'question_type' => 'mcq', 'category' => 'technical', 'difficulty' => 'medium', 'question' => 'Q12', 'max_score' => 10, 'correct_option' => 'b'],
        13 => ['id' => 13, 'question_type' => 'mcq', 'category' => 'technical', 'difficulty' => 'medium', 'question' => 'Q13', 'max_score' => 10, 'correct_option' => 'c'],
    ];
    public function fetchAll(string $sql, string $types = '', mixed ...$p): array {
        if (str_contains($sql, 'FROM assessment_template_sections')) return array_values(array_filter($this->sections, fn($s) => $s['template_id'] === $p[0]));
        if (str_contains($sql, 'FROM question_preset_items'))        return array_map(fn($q) => ['id' => $q['id'], 'difficulty' => $q['difficulty']], array_values($this->bank));
        if (str_contains($sql, 'FROM interview_questions WHERE id IN')) return array_values(array_intersect_key($this->bank, array_flip($p)));
        if (str_contains($sql, 'FROM assessment_plugins'))           return array_values(array_filter($this->plugins, fn($r) => $r['enabled'] === 1 && (!str_contains($sql, 'kind=?') || $r['kind'] === $p[0])));
        return [];
    }
    public function fetchOne(string $sql, string $types = '', mixed ...$p): ?array {
        if (str_contains($sql, 'FROM assessment_templates')) return $this->templates[$p[0]] ?? null;
        return null;
    }
    public function execute(string $sql, string $types = '', mixed ...$p): bool|int {
        if (str_contains($sql, 'INSERT INTO online_tests'))       { $id = $this->nextId++; $this->tests[$id] = $p; return $id; }
        if (str_contains($sql, 'INSERT INTO test_questions'))     { $this->testQuestions[] = $p; return true; }
        if (str_contains($sql, 'INSERT INTO assessment_events'))  { $this->outbox[] = $p; return true; }
        if (str_contains($sql, 'INSERT INTO assessment_plugins')) { $this->plugins[] = ['code' => $p[0], 'name' => $p[1], 'kind' => $p[2], 'config' => $p[3], 'enabled' => $p[4]]; return true; }
        return true;
    }
};
$svc = new AssessmentService($fake);
$out = $svc->generateFromTemplate(7, candidateId: 55, createdBy: 1, seed: 1);
ok($out['id'] >= 100 && strlen($out['token']) === 48, 'facade persists an online_tests instance with a 48-char token');
ok($out['question_count'] === 2 && count($fake->testQuestions) === 2, 'template sections drive test_questions rows');
ok($out['total_marks'] === 20, 'total marks derived from bank weights');
$frozen = json_decode($fake->tests[$out['id']][12], true);
ok(isset($frozen['negative_marking']) && $frozen['randomize'] === false, 'effective config frozen into the instance snapshot');
$genThrew = false;
try { $svc->generateFromTemplate(999, 55, 1); } catch (RuntimeException $e) { $genThrew = true; }
ok($genThrew, 'unknown template refused');
$sc = $svc->scoreAnswer(['question_type' => 'mcq', 'correct_option' => 'a', 'marks' => 10], 10, 'a');
ok($sc->marks === 10.0 && $sc->isCorrect === 1, 'facade scoreAnswer proxies the scoring engine');
$busSeen = null;
$svc->events->subscribe(Events::SUBMISSION_SCORED, function ($p) use (&$busSeen) { $busSeen = $p; });
$svc->announceSubmissionScored(9, 8, 55, 72.5, 'submitted');
ok($busSeen !== null && $busSeen['percentage'] === 72.5, 'submission-scored event dispatched through the service bus');

section('8A — PluginRegistry (generic integration surface)');
$reg = new PluginRegistry($fake);
ok($reg->register('hackerrank', 'HackerRank', 'question_source', ['api' => 'v3'], true), 'plugin rows register (question_source)');
ok($reg->register('claude', 'Claude', 'ai_scorer', [], true), 'plugin rows register (ai_scorer)');
ok(!$reg->register('bad', 'Bad', 'time_machine'), 'unknown plugin kind rejected');
ok(count($reg->enabled('ai_scorer')) === 1, 'enabled() filters by kind');
$adapter = new class { public function ping(): string { return 'pong'; } };
$reg->bind('claude', $adapter);
ok($reg->adapter('claude')?->ping() === 'pong' && $reg->adapter('ghost') === null, 'runtime adapters bind by code');
