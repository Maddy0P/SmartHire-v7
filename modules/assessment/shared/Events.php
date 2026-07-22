<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Event-driven core (Module 8A).
//  In-process synchronous bus today; the same dispatch() call can fan out to a
//  persistent outbox (assessment_events) so future consumers — notifications,
//  analytics, workflow automation, external integrations — subscribe without
//  the engine ever changing. Listeners must never break the main flow: every
//  listener runs inside try/catch and failures are swallowed (logged if a
//  logger is attached).
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Assessment\Shared;

/** Canonical event catalog — subscribe with these, don't invent strings. */
final class Events
{
    public const ASSESSMENT_GENERATED = 'assessment.generated';
    public const ASSESSMENT_ISSUED    = 'assessment.issued';
    public const SUBMISSION_STARTED   = 'submission.started';
    public const ANSWER_SAVED         = 'submission.answer_saved';
    public const SUBMISSION_SCORED    = 'submission.scored';
    public const REVIEW_COMPLETED     = 'submission.review_completed';
    public const RESULT_PUBLISHED     = 'result.published';
    public const QUESTION_CREATED     = 'question.created';       // 8B
    public const QUESTION_UPDATED     = 'question.updated';       // 8B
    public const POOL_CREATED         = 'pool.created';           // 8B
    public const POOL_CHANGED         = 'pool.changed';           // 8B
    public const TEMPLATE_CREATED     = 'template.created';
    public const TEMPLATE_UPDATED     = 'template.updated';
    public const PLUGIN_INVOKED       = 'plugin.invoked';
    public const PROCTORING_SIGNAL    = 'proctoring.signal';    // 8C
}

final class EventBus
{
    /** @var array<string, list<callable>> */
    private array $listeners = [];
    /** @var callable|null fn(string $event, array $payload): void — outbox writer */
    private $outbox = null;
    /** @var callable|null fn(string $msg): void */
    private $logger = null;

    public function subscribe(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /** Attach a persistence sink (e.g. OutboxRepository::append). */
    public function attachOutbox(callable $writer): void { $this->outbox = $writer; }
    public function attachLogger(callable $logger): void { $this->logger = $logger; }

    /** Dispatch to outbox + listeners. Listener failures never propagate. */
    public function dispatch(string $event, array $payload = []): void
    {
        if ($this->outbox) {
            try { ($this->outbox)($event, $payload); }
            catch (\Throwable $e) { $this->log("outbox failed for $event: {$e->getMessage()}"); }
        }
        foreach ($this->listeners[$event] ?? [] as $listener) {
            try { $listener($payload, $event); }
            catch (\Throwable $e) { $this->log("listener failed for $event: {$e->getMessage()}"); }
        }
    }

    public function listenerCount(string $event): int { return count($this->listeners[$event] ?? []); }

    private function log(string $msg): void
    {
        if ($this->logger) { try { ($this->logger)($msg); } catch (\Throwable) {} }
    }
}
