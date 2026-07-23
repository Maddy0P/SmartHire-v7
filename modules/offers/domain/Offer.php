<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Offer entity (Module 10 Phase 1). Immutable value object hydrated from a DB
//  row via ::fromRow(). No behavior beyond shaping — business rules live in the
//  service, SQL in the repository.
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Offer\Domain;

final class Offer
{
    public function __construct(
        public readonly int     $id,
        public readonly int     $candidateId,
        public readonly ?int    $jobId,
        public readonly ?int    $recruiterId,
        public readonly ?int    $interviewId,
        public readonly string  $jobTitle,
        public readonly string  $department,
        public readonly string  $location,
        public readonly string  $employmentType,
        public readonly ?float  $salary,
        public readonly string  $currency,
        public readonly ?string $joiningDate,
        public readonly ?string $expiryDate,
        public readonly string  $benefits,
        public readonly string  $notes,
        public readonly string  $status,
        public readonly ?string $hiredAt = null,
    ) {}

    public static function fromRow(array $r): self
    {
        return new self(
            id:             (int)($r['id'] ?? 0),
            candidateId:    (int)($r['candidate_id'] ?? 0),
            jobId:          isset($r['job_id']) ? (int)$r['job_id'] : null,
            recruiterId:    isset($r['recruiter_id']) ? (int)$r['recruiter_id'] : null,
            interviewId:    isset($r['interview_id']) ? (int)$r['interview_id'] : null,
            jobTitle:       (string)($r['job_title'] ?? ''),
            department:     (string)($r['department'] ?? ''),
            location:       (string)($r['location'] ?? ''),
            employmentType: (string)($r['employment_type'] ?? ''),
            salary:         isset($r['salary']) && $r['salary'] !== null ? (float)$r['salary'] : null,
            currency:       (string)($r['currency'] ?? 'INR'),
            joiningDate:    $r['joining_date'] ?? null,
            expiryDate:     $r['expiry_date'] ?? null,
            benefits:       (string)($r['benefits'] ?? ''),
            notes:          (string)($r['notes'] ?? ''),
            status:         (string)($r['status'] ?? 'draft'),
            hiredAt:        $r['hired_at'] ?? null,
        );
    }
}
