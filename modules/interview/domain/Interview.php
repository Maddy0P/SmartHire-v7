<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Interview domain entity (Module 9). Immutable value object mirroring the
//  `interviews` table. Construct via ::fromRow(); no behavior, no SQL.
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Interview\Domain;

final class Interview
{
    public function __construct(
        public readonly int     $id,
        public readonly int     $candidateId,
        public readonly string  $interviewer,
        public readonly ?string $scheduledDate,
        public readonly ?string $scheduledTime,
        public readonly string  $type,          // technical | hr | final
        public readonly string  $mode,          // online | in-person
        public readonly string  $status,        // scheduled | completed | cancelled | no-show
        public readonly string  $notes = '',
        public readonly ?string $createdAt = null,
    ) {}

    public static function fromRow(array $r): self
    {
        return new self(
            id:            (int)($r['id'] ?? 0),
            candidateId:   (int)($r['candidate_id'] ?? 0),
            interviewer:   (string)($r['interviewer'] ?? ''),
            scheduledDate: $r['scheduled_date'] ?? null,
            scheduledTime: $r['scheduled_time'] ?? null,
            type:          (string)($r['type'] ?? 'technical'),
            mode:          (string)($r['mode'] ?? 'online'),
            status:        (string)($r['status'] ?? 'scheduled'),
            notes:         (string)($r['notes'] ?? ''),
            createdAt:     $r['created_at'] ?? null,
        );
    }

    public function isCompleted(): bool { return $this->status === 'completed'; }
}
