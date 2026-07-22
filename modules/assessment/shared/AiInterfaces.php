<?php
// ═════════════════════════════════════════════════════════════════════════════
//  AI extension interfaces (Module 8A) — contracts ONLY, no implementations.
//  Future providers (OpenAI, Claude, AWS Bedrock, Azure AI, …) implement these
//  and register through the plugin registry (kind: 'ai_scorer' /
//  'question_source'). The engine consumes the interface, never a vendor SDK —
//  vendors plug into the engine rather than replacing it.
//  Governance note (Phase 2 decision carried forward): AI outputs are always
//  SUGGESTIONS routed into the human review lane (hr_marks), never silently
//  written as final scores.
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Assessment\Shared;

use SmartHire\Assessment\Domain\Question;
use SmartHire\Assessment\Domain\Score;

/** Drafts new questions for the bank (returned as drafts; humans approve). */
interface AiQuestionAuthor
{
    /**
     * @param array $spec ['role','skills'=>[], 'type','difficulty','count']
     * @return Question[] draft questions (id=0, status='draft')
     */
    public function draftQuestions(array $spec): array;
}

/** Suggests a score for one answer that needs review (essay, code, scenario…). */
interface AiAnswerEvaluator
{
    /**
     * @param array $response the candidate's structured response payload
     * @return Score suggestion — needsReview stays true; marks are advisory
     */
    public function evaluateAnswer(Question $question, array $response, array $context = []): Score;
}

/** Writes narrative insight (strengths, weaknesses, improvement plan) for a result. */
interface AiInsightWriter
{
    /**
     * @param array $resultData serialized Domain\Result
     * @return array ['summary','strengths'=>[], 'weaknesses'=>[], 'suggestions'=>[]]
     */
    public function writeInsights(array $resultData, array $context = []): array;
}
