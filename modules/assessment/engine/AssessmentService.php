<?php
// ═════════════════════════════════════════════════════════════════════════════
//  AssessmentService (Module 8A) — the ONLY public surface of the platform.
//  Product code (entry-point pages, future 8B/8C UIs, cron, plugins) talks to
//  this facade; it never touches engines or repositories directly. The facade
//  wires: Config → Generator → Repositories → ScoringEngine → ResultEngine,
//  and emits domain events at every state change.
//
//  Construction:
//    $svc = AssessmentService::production();        // global DB, outbox attached
//    $svc = new AssessmentService($db, $bus);        // tests inject fakes
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Assessment\Engine;

use SmartHire\Assessment\Domain\Question;
use SmartHire\Assessment\Shared\AntiCheat;
use SmartHire\Assessment\Domain\Score;
use SmartHire\Assessment\Results\ResultEngine;
use SmartHire\Assessment\Scoring\ScoringEngine;
use SmartHire\Assessment\Shared\AssessmentConfig;
use SmartHire\Assessment\Shared\AssessmentRepository;
use SmartHire\Assessment\Shared\DbAdapter;
use SmartHire\Assessment\Shared\EventBus;
use SmartHire\Assessment\Shared\Events;
use SmartHire\Assessment\Shared\GlobalDb;
use SmartHire\Assessment\Shared\OutboxRepository;
use SmartHire\Assessment\Shared\PluginRegistry;
use SmartHire\Assessment\Shared\PoolRepository;
use SmartHire\Assessment\Shared\QuestionRepository;
use SmartHire\Assessment\Shared\SubmissionRepository;
use SmartHire\Assessment\Shared\TemplateRepository;

final class AssessmentService
{
    public readonly QuestionRepository   $questions;
    public readonly PoolRepository       $pools;
    public readonly TemplateRepository   $templates;
    public readonly AssessmentRepository $assessments;
    public readonly SubmissionRepository $submissions;
    public readonly PluginRegistry       $plugins;
    public readonly \SmartHire\Assessment\Shared\AdminStatsRepository $stats;

    private readonly ScoringEngine $scorer;
    private readonly Generator     $generator;
    private readonly ResultEngine  $results;

    public function __construct(private readonly DbAdapter $db,
                                public readonly EventBus $events = new EventBus())
    {
        $this->questions   = new QuestionRepository($db);
        $this->pools       = new PoolRepository($db);
        $this->templates   = new TemplateRepository($db);
        $this->assessments = new AssessmentRepository($db);
        $this->submissions = new SubmissionRepository($db);
        $this->plugins     = new PluginRegistry($db);
        $this->stats       = new \SmartHire\Assessment\Shared\AdminStatsRepository($db);
        $this->scorer      = new ScoringEngine();
        $this->generator   = new Generator();
        $this->results     = new ResultEngine();
    }

    private static ?self $prod = null;

    /** Production singleton with the persistent event outbox attached. */
    public static function production(): self
    {
        if (self::$prod === null) {
            $db  = new GlobalDb();
            $bus = new EventBus();
            $bus->attachOutbox([new OutboxRepository($db), 'append']);
            if (function_exists('logMessage')) {
                $bus->attachLogger(fn(string $m) => \logMessage('assessment-events: ' . $m));
            }
            self::$prod = new self($db, $bus);
        }
        return self::$prod;
    }

    // ── Question Engine ──────────────────────────────────────────────────────

    /** Registry passthrough so callers never import engine internals. */
    public function questionTypes(): array { return QTypeRegistry::all(); }
    public function isValidType(string $type): bool { return QTypeRegistry::isValid($type); }

    // ── Config layer ─────────────────────────────────────────────────────────

    /** Effective config for an issued assessment (defaults ← template ← frozen snapshot). */
    public function configFor(?array $template, array $instanceConfig = []): AssessmentConfig
    {
        return AssessmentConfig::fromInstance(
            is_array($template['config'] ?? null) ? $template['config'] : [], $instanceConfig);
    }

    // ── Generator: template → issued assessment (existing tables only) ───────

    /**
     * Generate + persist an assessment instance for a candidate from a template.
     * Writes online_tests + test_questions; NOTHING parallel.
     * @return array{id:int,token:string,question_count:int,total_marks:int,shortfalls:array}
     * @throws \RuntimeException on unknown template / unfillable sections
     */
    public function generateFromTemplate(int $templateId, int $candidateId, int $createdBy,
                                         ?int $seed = null, array $overrides = []): array
    {
        $tpl = $this->templates->find($templateId);
        if (!$tpl) throw new \RuntimeException("Unknown template #$templateId");
        $cfg = AssessmentConfig::make($tpl['config'] ?? [], $overrides);

        $sections = [];
        foreach ($tpl['sections'] as $s) {
            $sections[] = [
                'name'           => $s['name'],
                'section_id'     => (int)$s['id'],
                'pool'           => $s['pool_id'] ? $this->questions->poolByDifficulty((int)$s['pool_id'])
                                                  : ['easy' => [], 'medium' => [], 'hard' => []],
                'question_count' => (int)$s['question_count'],
                'difficulty_mix' => $s['difficulty_mix'] ?: [],
                'time_minutes'   => $s['time_minutes'] !== null ? (int)$s['time_minutes'] : null,
            ];
        }
        $selection = $this->generator->select($sections, $cfg, $seed);

        $bank = $this->questions->findMany($selection['question_ids']);
        $totalMarks = 0; $rows = []; $order = 1;
        foreach ($selection['sections'] as $sec) {
            foreach ($sec['question_ids'] as $qid) {
                $q = $bank[$qid] ?? null;
                if (!$q) continue;
                $marks = $q->maxScore;
                if (!$q->isBonus()) $totalMarks += $marks;
                $rows[] = ['question_id' => $qid, 'marks' => $marks, 'order_no' => $order++,
                           'time_limit_secs' => 0, 'section_id' => $sec['section_id']];
            }
        }
        if (!$rows) throw new \RuntimeException('Generation selected no questions — check pools and section rules.');

        $token = bin2hex(random_bytes(24));
        $expiry = !empty($tpl['expiry_days'])
            ? date('Y-m-d', strtotime('+' . (int)$tpl['expiry_days'] . ' days')) : null;

        $id = $this->assessments->createInstance([
            'title'            => $tpl['name'],
            'description'      => $tpl['instructions'] ?? '',
            'candidate_id'     => $candidateId,
            'created_by'       => $createdBy,
            'duration_minutes' => (int)$tpl['duration_minutes'],
            'total_marks'      => $totalMarks,
            'passing_marks'    => (int)round($totalMarks * ((int)$tpl['passing_score']) / 100),
            'status'           => 'pending',
            'test_link_token'  => $token,
            'scheduled_date'   => date('Y-m-d'),
            'expiry_date'      => $expiry,
            'template_id'      => $templateId,
            'config'           => $cfg->snapshot(),      // freeze scoring rules at issue time
        ], $rows);
        if (!$id) throw new \RuntimeException('Failed to persist generated assessment.');

        $this->events->dispatch(Events::ASSESSMENT_GENERATED, [
            'entity' => 'online_test', 'entity_id' => $id,
            'template_id' => $templateId, 'candidate_id' => $candidateId,
            'question_count' => count($rows), 'total_marks' => $totalMarks,
            'shortfalls' => array_filter(array_column($selection['sections'], 'shortfall', 'name')),
        ]);

        return ['id' => $id, 'token' => $token, 'question_count' => count($rows),
                'total_marks' => $totalMarks,
                'shortfalls' => array_filter(array_column($selection['sections'], 'shortfall', 'name'))];
    }

    // ── Scoring Engine (used by take_test.php since 8A) ──────────────────────

    /**
     * Score one answer with the instance's frozen config.
     * $questionRow needs: question_type, correct_option, metadata, answer_key + weight (marks).
     */
    public function scoreAnswer(array $questionRow, int $weight, mixed $answer,
                                array $instanceConfig = [], array $templateConfig = []): Score
    {
        $cfg = AssessmentConfig::fromInstance($templateConfig, $instanceConfig);
        return $this->scorer->scoreAnswer(Question::fromRow($questionRow), $weight, $answer, $cfg);
    }

    public function announceSubmissionScored(int $submissionId, int $testId, int $candidateId,
                                             float $pct, string $status): void
    {
        $this->events->dispatch(Events::SUBMISSION_SCORED, [
            'entity' => 'test_submission', 'entity_id' => $submissionId,
            'test_id' => $testId, 'candidate_id' => $candidateId,
            'percentage' => $pct, 'status' => $status,
        ]);
    }


    // ═════════════════════════════════════════════════════════════════════════
    //  8B — Assessment Center facade (UI orchestrates ONLY these methods)
    // ═════════════════════════════════════════════════════════════════════════

    /** Dashboard payload: overview KPIs + chart datasets in one call. */
    public function dashboard(): array
    {
        return [
            'overview'      => $this->stats->overview(),
            'activity'      => $this->stats->activityByDay(14),
            'pass_fail'     => $this->stats->passFail(),
            'progress'      => $this->stats->candidateProgress(),
            'top_templates' => $this->stats->topTemplates(),
            'top_pools'     => $this->stats->topPools(),
            'difficulty'    => $this->questions->difficultyDistribution(),
        ];
    }

    public function candidateOptions(): array { return $this->stats->candidateOptions(); }

    public function globalSearch(string $q): array
    {
        $q = trim($q);
        return $q === '' ? [] : $this->stats->globalSearch($q);
    }

    // ── Question bank ────────────────────────────────────────────────────────

    public function bankSearch(array $filters, int $page, int $per = 15): array
    {
        $res = $this->questions->search($filters, $page, $per);
        $ids = array_map(fn($r) => (int)$r['id'], $res['rows']);
        $res['usage'] = $this->questions->usageCounts($ids);
        $res['pools'] = $this->questions->poolNamesFor($ids);
        return $res;
    }

    /**
     * Create/update a bank question. Normalises + registry-validates the type,
     * builds answer_key/metadata JSON from structured form input.
     * @return array{ok:bool,id:int,error:?string}
     */
    public function saveQuestion(array $in, ?int $id, int $userId): array
    {
        $type = (string)($in['question_type'] ?? '');
        if (!QTypeRegistry::isValid($type)) return ['ok' => false, 'id' => 0, 'error' => 'Unknown question type.'];
        if (trim((string)($in['question'] ?? '')) === '') return ['ok' => false, 'id' => 0, 'error' => 'Question text is required.'];
        $meta = [];
        if (!empty($in['bonus'])) $meta['bonus'] = true;
        if (($in['options_json'] ?? '') !== '') {
            $opts = json_decode((string)$in['options_json'], true);
            if (is_array($opts)) $meta['options'] = $opts;
        }
        $key = null;
        switch (QTypeRegistry::scoringStrategy($type)) {
            case 'multi_select':
                $correct = array_values(array_filter(array_map('trim', (array)($in['correct_set'] ?? []))));
                if (!$correct) return ['ok' => false, 'id' => 0, 'error' => 'Multi-select needs at least one correct option.'];
                $key = ['correct' => $correct]; break;
            case 'boolean':
                $key = ['value' => filter_var($in['bool_value'] ?? 'true', FILTER_VALIDATE_BOOLEAN)]; break;
            case 'text_match':
                $acc = array_values(array_filter(array_map('trim', explode("\n", (string)($in['accepted'] ?? '')))));
                if (!$acc) return ['ok' => false, 'id' => 0, 'error' => 'Provide at least one accepted answer.'];
                $key = ['accepted' => $acc]; break;
            case 'exact_output':
                if (trim((string)($in['expected_output'] ?? '')) === '') return ['ok' => false, 'id' => 0, 'error' => 'Expected output is required.'];
                $key = ['expected_output' => trim((string)$in['expected_output'])]; break;
            case 'mcq':
                if (!in_array($in['correct_option'] ?? '', ['a','b','c','d'], true))
                    return ['ok' => false, 'id' => 0, 'error' => 'Pick the correct MCQ option.'];
                break;
        }
        $data = [
            'question' => trim((string)$in['question']), 'question_type' => $type,
            'category' => (string)($in['category'] ?? 'technical'),
            'difficulty' => in_array($in['difficulty'] ?? '', ['easy','medium','hard'], true) ? $in['difficulty'] : 'medium',
            'position_tag' => trim((string)($in['position_tag'] ?? 'General')) ?: 'General',
            'max_score' => max(1, (int)($in['max_score'] ?? 10)),
            'expected_answer' => (string)($in['expected_answer'] ?? ''),
            'option_a' => (string)($in['option_a'] ?? ''), 'option_b' => (string)($in['option_b'] ?? ''),
            'option_c' => (string)($in['option_c'] ?? ''), 'option_d' => (string)($in['option_d'] ?? ''),
            'correct_option' => (string)($in['correct_option'] ?? ''),
            'metadata' => json_encode($meta, JSON_UNESCAPED_SLASHES),
            'answer_key' => $key !== null ? json_encode($key, JSON_UNESCAPED_SLASHES) : null,
            'skills' => trim((string)($in['skills'] ?? '')),
            'status' => in_array($in['status'] ?? '', ['active','draft','archived'], true) ? $in['status'] : 'active',
        ];
        $qid = $this->questions->save($data, $id);
        if ($qid > 0) {
            foreach (array_filter(array_map('intval', (array)($in['pool_ids'] ?? []))) as $pid) {
                $this->pools->addQuestions($pid, [$qid]);
            }
            $this->events->dispatch($id ? Events::QUESTION_UPDATED : Events::QUESTION_CREATED,
                ['entity' => 'interview_question', 'entity_id' => $qid, 'by' => $userId]);
        }
        return ['ok' => $qid > 0, 'id' => $qid, 'error' => $qid > 0 ? null : 'Save failed.'];
    }

    public function setQuestionStatus(int $id, string $status, int $userId): bool
    {
        if (!in_array($status, ['active','draft','archived'], true)) return false;
        $ok = $this->questions->setStatus($id, $status);
        if ($ok) $this->events->dispatch(Events::QUESTION_UPDATED, ['entity' => 'interview_question', 'entity_id' => $id, 'status' => $status, 'by' => $userId]);
        return $ok;
    }

    public function duplicateQuestion(int $id): int { return $this->questions->duplicate($id); }

    // ── Pools ────────────────────────────────────────────────────────────────

    public function poolsOverview(): array
    {
        $pools = $this->pools->listWithStats();
        foreach ($pools as &$p) $p['used_by'] = $this->pools->usedByTemplates((int)$p['id']);
        return $pools;
    }

    public function createPool(string $name, string $description, string $tags, int $userId): int
    {
        $name = trim($name);
        if ($name === '') return 0;
        $id = $this->pools->create($name, trim($description), trim($tags));
        if ($id) $this->events->dispatch(Events::POOL_CREATED, ['entity' => 'question_preset', 'entity_id' => $id, 'by' => $userId]);
        return $id;
    }

    public function clonePool(int $id, string $newName): int { return $this->pools->clonePool($id, trim($newName) ?: 'Copy'); }
    public function archivePool(int $id): bool { return $this->pools->setStatus($id, 'archived'); }
    public function mergePools(int $sourceId, int $targetId): bool { return $this->pools->merge($sourceId, $targetId); }
    public function addQuestionsToPool(int $poolId, array $qids): int { return $this->pools->addQuestions($poolId, $qids); }
    public function poolDetail(int $id): ?array
    {
        $pool = $this->pools->find($id);
        if (!$pool) return null;
        return ['pool' => $pool, 'skills' => $this->pools->skillBreakdown($id),
                'by_difficulty' => $this->questions->poolByDifficulty($id),
                'used_by' => $this->pools->usedByTemplates($id)];
    }

    // ── Templates ────────────────────────────────────────────────────────────

    public function templateList(array $filters = []): array { return $this->templates->listWithStats($filters); }

    /** @return array{ok:bool,id:int,error:?string} */
    public function saveTemplate(array $in, array $sections, ?int $id, int $userId): array
    {
        if (trim((string)($in['name'] ?? '')) === '') return ['ok' => false, 'id' => 0, 'error' => 'Template name is required.'];
        $clean = [];
        foreach ($sections as $s) {
            if (trim((string)($s['name'] ?? '')) === '' || (int)($s['question_count'] ?? 0) < 1) continue;
            $mix = array_filter(['easy' => (int)($s['mix_easy'] ?? 0), 'medium' => (int)($s['mix_medium'] ?? 0), 'hard' => (int)($s['mix_hard'] ?? 0)]);
            $clean[] = ['name' => trim($s['name']), 'pool_id' => (int)($s['pool_id'] ?? 0) ?: null,
                        'question_count' => (int)$s['question_count'],
                        'time_minutes' => ($s['time_minutes'] ?? '') !== '' ? (int)$s['time_minutes'] : null,
                        'weight' => (float)($s['weight'] ?? 1) ?: 1.0,
                        'difficulty_mix' => json_encode($mix ?: new \stdClass(), JSON_UNESCAPED_SLASHES), 'config' => '{}'];
        }
        if (!$clean) return ['ok' => false, 'id' => 0, 'error' => 'Add at least one section with a question count.'];
        $cfg = ['randomize' => !empty($in['randomize']), 'partial_credit' => !empty($in['partial_credit']),
                'negative_marking' => max(0.0, min(1.0, (float)($in['negative_marking'] ?? 0))),
                'passing_pct' => max(0, min(100, (int)($in['passing_score'] ?? 40)))];
        $head = [
            'name' => trim((string)$in['name']), 'category' => trim((string)($in['category'] ?? '')),
            'department' => trim((string)($in['department'] ?? '')), 'role' => trim((string)($in['role'] ?? '')),
            'experience_level' => (string)($in['experience_level'] ?? 'any'),
            'duration_minutes' => max(5, (int)($in['duration_minutes'] ?? 60)),
            'passing_score' => $cfg['passing_pct'], 'max_attempts' => max(1, (int)($in['max_attempts'] ?? 1)),
            'instructions' => (string)($in['instructions'] ?? ''), 'certification' => !empty($in['certification']) ? 1 : 0,
            'expiry_days' => ($in['expiry_days'] ?? '') !== '' ? max(1, (int)$in['expiry_days']) : null,
            'config' => json_encode($cfg, JSON_UNESCAPED_SLASHES),
            'status' => in_array($in['status'] ?? '', ['draft','active','archived'], true) ? $in['status'] : 'draft',
            'created_by' => $userId,
        ];
        $tid = $this->templates->save($head, $clean, $id);
        $this->events->dispatch($id ? Events::TEMPLATE_UPDATED : Events::TEMPLATE_CREATED,
            ['entity' => 'assessment_template', 'entity_id' => $tid, 'by' => $userId]);
        return ['ok' => $tid > 0, 'id' => $tid, 'error' => $tid > 0 ? null : 'Save failed.'];
    }

    public function cloneTemplate(int $id, string $newName): int { return $this->templates->cloneTemplate($id, trim($newName) ?: 'Copy'); }
    public function setTemplateStatus(int $id, string $status): bool
    { return in_array($status, ['draft','active','archived'], true) && $this->templates->setStatus($id, $status); }

    // ── Generator preview (dry run — nothing persisted) ──────────────────────

    /** @return array{ok:bool,error:?string,sections:array,total_questions:int,total_marks:int,skills:array,difficulty:array,est_minutes:int} */
    public function previewFromTemplate(int $templateId, ?int $seed = null): array
    {
        $tpl = $this->templates->find($templateId);
        if (!$tpl) return ['ok' => false, 'error' => 'Unknown template.', 'sections' => [], 'total_questions' => 0, 'total_marks' => 0, 'skills' => [], 'difficulty' => [], 'est_minutes' => 0];
        $cfg = AssessmentConfig::make($tpl['config'] ?? []);
        $sections = [];
        foreach ($tpl['sections'] as $s) {
            $sections[] = ['name' => $s['name'], 'section_id' => (int)$s['id'],
                'pool' => $s['pool_id'] ? $this->questions->poolByDifficulty((int)$s['pool_id']) : ['easy' => [], 'medium' => [], 'hard' => []],
                'question_count' => (int)$s['question_count'], 'difficulty_mix' => $s['difficulty_mix'] ?: [],
                'time_minutes' => $s['time_minutes'] !== null ? (int)$s['time_minutes'] : null];
        }
        try { $sel = (new Generator())->select($sections, $cfg, $seed); }
        catch (\RuntimeException $e) { return ['ok' => false, 'error' => $e->getMessage(), 'sections' => [], 'total_questions' => 0, 'total_marks' => 0, 'skills' => [], 'difficulty' => [], 'est_minutes' => 0]; }
        $bank = $this->questions->findMany($sel['question_ids']);
        $marks = 0; $skills = []; $diff = ['easy' => 0, 'medium' => 0, 'hard' => 0];
        foreach ($bank as $q) {
            if (!$q->isBonus()) $marks += $q->maxScore;
            $diff[$q->difficulty] = ($diff[$q->difficulty] ?? 0) + 1;
            foreach ($q->skills as $sk) $skills[$sk] = ($skills[$sk] ?? 0) + 1;
        }
        arsort($skills);
        return ['ok' => true, 'error' => null, 'sections' => $sel['sections'],
                'total_questions' => count($sel['question_ids']), 'total_marks' => $marks,
                'skills' => $skills, 'difficulty' => $diff, 'est_minutes' => (int)$tpl['duration_minutes']];
    }

    // ── Review queue ─────────────────────────────────────────────────────────

    public function reviewQueue(): array { return $this->submissions->pendingReviews(); }

    /** Answers of one submission that sit in the manual lane (with question context). */
    public function reviewWorkspace(int $submissionId): array
    {
        $rows = $this->submissions->answersWithQuestions($submissionId);
        return array_values(array_filter($rows, fn($r) => !QTypeRegistry::isAutoScorable((string)($r['question_type'] ?? 'subjective'))));
    }

    /**
     * Record a manual score and recompute the submission totals with the SAME
     * rule the platform has always used (auto marks + hr_marks, else 0).
     * @return array{ok:bool,total:float,pct:float}
     */
    public function recordManualScore(int $submissionId, int $answerId, int $marks, string $feedback, int $reviewerId): array
    {
        $sub = $this->submissions->find($submissionId);
        if (!$sub) return ['ok' => false, 'total' => 0, 'pct' => 0];
        $rows = $this->submissions->answersWithQuestions($submissionId);
        $target = null;
        foreach ($rows as $r) if ((int)$r['id'] === $answerId) { $target = $r; break; }
        if (!$target) return ['ok' => false, 'total' => 0, 'pct' => 0];
        $max = (int)($target['weight'] ?? $target['max_score'] ?? 10);
        $marks = max(0, min($max, $marks));
        if (!$this->submissions->saveManualScore($answerId, $submissionId, $marks, $feedback, $reviewerId))
            return ['ok' => false, 'total' => 0, 'pct' => 0];
        // recompute
        $total = 0.0;
        foreach ($rows as $r) {
            if ((int)$r['id'] === $answerId) { $total += $marks; continue; }
            $auto = QTypeRegistry::isAutoScorable((string)($r['question_type'] ?? 'subjective'));
            $total += $auto ? (float)($r['marks_awarded'] ?? 0)
                            : (($r['hr_marks'] ?? null) !== null ? (float)$r['hr_marks'] : 0.0);
        }
        $pct = $sub->maxScore > 0 ? round($total / $sub->maxScore * 100, 2) : 0.0;
        $this->submissions->updateTotals($submissionId, $total, $pct);
        $this->events->dispatch(Events::REVIEW_COMPLETED, [
            'entity' => 'test_submission', 'entity_id' => $submissionId,
            'answer_id' => $answerId, 'marks' => $marks, 'by' => $reviewerId, 'percentage' => $pct]);
        return ['ok' => true, 'total' => $total, 'pct' => $pct];
    }

    /** AI review suggestion via any enabled + bound ai_scorer plugin (interface-only consumption). */
    public function aiSuggestionFor(array $answerRow): ?\SmartHire\Assessment\Domain\Score
    {
        foreach ($this->plugins->enabled('ai_scorer') as $row) {
            $adapter = $this->plugins->adapter((string)$row['code']);
            if ($adapter instanceof \SmartHire\Assessment\Shared\AiAnswerEvaluator) {
                try {
                    return $adapter->evaluateAnswer(
                        Question::fromRow($answerRow), ['text' => (string)($answerRow['answer_text'] ?? '')]);
                } catch (\Throwable) { return null; }
            }
        }
        return null;
    }

    // ── Results workspace ────────────────────────────────────────────────────

    public function resultsList(array $filters = []): array { return $this->submissions->listSubmitted($filters); }

    /** CSV export of a result breakdown (pure string build — unit-tested). */
    public function resultCsv(int $submissionId): ?string
    {
        $sub = $this->submissions->find($submissionId);
        if (!$sub) return null;
        $res = $this->resultFor($submissionId);
        if (!$res) return null;
        $esc = fn($v) => '"' . str_replace('"', '""', (string)$v) . '"';
        $lines = [implode(',', array_map($esc, ['Submission', 'Overall %', 'Marks', 'Max', 'Passed', 'Recommendation', 'Pending review']))];
        $lines[] = implode(',', array_map($esc, [$submissionId, $res->overallPct, $res->totalMarks, $res->maxMarks, $res->passed ? 'yes' : 'no', $res->recommendation, $res->pendingReview]));
        $lines[] = '';
        $lines[] = implode(',', array_map($esc, ['Question', 'Type', 'Difficulty', 'Earned', 'Max', 'Correct', 'Pending', 'Time (s)']));
        foreach ($res->questionAnalysis as $qa) {
            $lines[] = implode(',', array_map($esc, [$qa['question_id'], $qa['type'], $qa['difficulty'], $qa['earned'], $qa['max'], $qa['correct'] ? 1 : 0, $qa['pending'] ? 1 : 0, $qa['time_secs']]));
        }
        $lines[] = '';
        $lines[] = implode(',', array_map($esc, ['Skill', 'Score', 'Max', '%']));
        foreach ($res->skills as $name => $v) $lines[] = implode(',', array_map($esc, [$name, $v['score'], $v['max'], $v['pct']]));
        return implode("\r\n", $lines) . "\r\n";
    }


    // ═════════════════════════════════════════════════════════════════════════
    //  8C — Candidate delivery (Player) API. The candidate page consumes ONLY
    //  these methods; timing + attempt validation are server-authoritative.
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Load (or lazily start) an attempt for a token. Returns the full player
     * bundle, or ['error'=>reason] for invalid/expired/exhausted/completed.
     */
    public function openAttempt(string $token, int $candidateId): array
    {
        $test = $this->submissions->findByToken($token, $candidateId);
        if (!$test)                         return ['error' => 'invalid'];
        if (($test['status'] ?? '') === 'expired') return ['error' => 'expired'];
        if ($this->submissions->completedAttempt((int)$test['id'], $candidateId)) return ['error' => 'completed'];

        $cfg = $this->configFor(null, Question::jsonb($test['config'] ?? null));
        $attempt = $this->submissions->activeAttempt((int)$test['id'], $candidateId);
        $resumed = $attempt !== null;
        if (!$attempt) {
            $sid = $this->submissions->startAttempt((int)$test['id'], $candidateId,
                (int)$test['total_marks'], (int)$test['duration_minutes']);
            if (!$sid) return ['error' => 'start_failed'];
            $attempt = $this->submissions->activeAttempt((int)$test['id'], $candidateId) ?? ['id' => $sid, 'nav_state' => '{}'];
            $this->events->dispatch(Events::SUBMISSION_STARTED, [
                'entity' => 'test_submission', 'entity_id' => $sid,
                'test_id' => (int)$test['id'], 'candidate_id' => $candidateId]);
        }

        $questions = $this->submissions->questionsForTest((int)$test['id']);
        if (!$questions) return ['error' => 'no_questions', 'test' => $test];

        $saved = $this->submissions->savedAnswers((int)$attempt['id']);
        $nav   = Question::jsonb($attempt['nav_state'] ?? null);

        return [
            'error'      => null,
            'test'       => $test,
            'submission' => $attempt,
            'questions'  => $questions,
            'saved'      => $saved,
            'nav'        => $nav,
            'resumed'    => $resumed,
            'remaining'  => $this->submissions->secondsRemaining((int)$attempt['id']),
            'policy'     => AssessmentConfig::make(AntiCheat::defaultPolicy(), $cfg->all()['proctoring'] ?? [])->all(),
            'config'     => $cfg,
        ];
    }

    /**
     * Autosave one answer. Scores auto-scorable types immediately (same engine),
     * defers manual types. Returns ['ok'=>bool,'remaining'=>int,'saved_at'=>iso].
     */
    public function autosave(int $submissionId, array $test, array $questionRow, mixed $answer,
                             ?array $response, int $timeSecs, int $flag, ?string $clientTs): array
    {
        $cfg = $this->configFor(null, Question::jsonb($test['config'] ?? null));
        $sc  = $this->scorer->scoreAnswer(Question::fromRow($questionRow), (int)$questionRow['marks'], $answer, $cfg);
        $marks = (int)round($sc->marks); $correct = $sc->isCorrect;
        $selected = $sc->selectedOption ?? '';
        $answerText = is_array($answer) ? (string)($answer['text'] ?? '') : (string)$answer;
        $respJson = $response !== null ? json_encode($response, JSON_UNESCAPED_SLASHES) : null;
        $ok = $this->submissions->autosaveAnswer($submissionId, (int)$questionRow['question_id'],
            $answerText, $selected, $respJson, $marks, $correct, max(0, $timeSecs), $flag ? 1 : 0, $clientTs);
        if ($ok) $this->events->dispatch(Events::ANSWER_SAVED, [
            'entity' => 'test_submission', 'entity_id' => $submissionId,
            'question_id' => (int)$questionRow['question_id']]);
        return ['ok' => $ok, 'remaining' => $this->submissions->secondsRemaining($submissionId),
                'saved_at' => date('c')];
    }

    public function saveNav(int $submissionId, int $currentQ, array $flags): bool
    {
        return $this->submissions->saveNavState($submissionId, $currentQ,
            json_encode(['flags' => array_values(array_map('intval', $flags)), 'current' => $currentQ], JSON_UNESCAPED_SLASHES));
    }

    /** Log proctoring signals (never blocks). Returns the recorded counters delta. */
    public function recordProctoring(int $submissionId, array $rawSignals, array $policy): array
    {
        $norm = AntiCheat::normalise($rawSignals, $policy);
        if ($norm['violation_delta'] || $norm['reconnect_delta'])
            $this->submissions->bumpProctoring($submissionId, $norm['violation_delta'], $norm['reconnect_delta']);
        foreach ($norm['events'] as $ev) {
            $this->events->dispatch(Events::PROCTORING_SIGNAL, [
                'entity' => 'test_submission', 'entity_id' => $submissionId,
                'signal' => $ev['type'], 'counts' => $ev['counts']]);
        }
        return ['violation_delta' => $norm['violation_delta'], 'reconnect_delta' => $norm['reconnect_delta'],
                'logged' => count($norm['events'])];
    }

    public function secondsRemaining(int $submissionId): int { return $this->submissions->secondsRemaining($submissionId); }

    /**
     * Finalise an attempt. Rescores every question from its saved answer through
     * the SAME engine (server-authoritative — never trusts client-side totals),
     * updates the pipeline exactly as the legacy flow did, and emits the scored
     * event. Returns ['pct','marks','max','time','status'].
     */
    public function submitAttempt(int $submissionId, array $test, int $candidateId, bool $autoSubmit): array
    {
        $cfg = $this->configFor(null, Question::jsonb($test['config'] ?? null));
        $questions = $this->submissions->questionsForTest((int)$test['id']);
        $saved = $this->submissions->savedAnswers($submissionId);
        $total = 0;
        foreach ($questions as $q) {
            $qid = (int)$q['question_id'];
            $row = $saved[$qid] ?? null;
            $answer = ($row['response'] ?? null) ? Question::jsonb($row['response']) : (string)($row['answer_text'] ?? '');
            $sc = $this->scorer->scoreAnswer(Question::fromRow($q), (int)$q['marks'], $answer, $cfg);
            $marks = (int)round($sc->marks); $total += $marks;
            $this->submissions->autosaveAnswer($submissionId, $qid,
                is_array($answer) ? (string)($answer['text'] ?? '') : (string)$answer,
                $sc->selectedOption ?? '', $row['response'] ?? null, $marks, $sc->isCorrect,
                (int)($row['time_spent_secs'] ?? 0), (int)($row['review_flag'] ?? 0), null);
        }
        $pct = (int)$test['total_marks'] > 0 ? round($total / (int)$test['total_marks'] * 100, 2) : 0.0;
        $sub = $this->submissions->find($submissionId);
        $started = $sub && $sub->startedAt ? strtotime($sub->startedAt) : time();
        $timeTaken = max(1, (int)((time() - $started) / 60));
        $status = $autoSubmit ? 'auto_submitted' : 'submitted';
        $this->submissions->finalizeSubmission($submissionId, $total, (float)$pct, $timeTaken, $status);
        $this->announceSubmissionScored($submissionId, (int)$test['id'], $candidateId, (float)$pct, $status);
        return ['pct' => $pct, 'marks' => $total, 'max' => (int)$test['total_marks'], 'time' => $timeTaken, 'status' => $status];
    }

    /** Candidate-facing result view (respects config visibility flags). */
    public function candidateResult(int $submissionId): ?\SmartHire\Assessment\Domain\Result
    {
        return $this->resultFor($submissionId);
    }

    // ── Result Engine ────────────────────────────────────────────────────────

    public function resultFor(int $submissionId, array $instanceConfig = [],
                              array $templateConfig = [], array $sectionNames = []): ?\SmartHire\Assessment\Domain\Result
    {
        $sub = $this->submissions->find($submissionId);
        if (!$sub) return null;
        $rows = $this->submissions->answersWithQuestions($submissionId);
        $cfg  = AssessmentConfig::fromInstance($templateConfig, $instanceConfig);
        $history = array_filter($this->submissions->candidateHistory($sub->candidateId),
                                fn($h) => (int)$h['id'] !== $submissionId);
        return (new ResultEngine())->analyze($rows, $cfg, $sectionNames, array_values($history));
    }
}
