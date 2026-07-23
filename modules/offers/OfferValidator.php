<?php
// ═════════════════════════════════════════════════════════════════════════════
//  OfferValidator (Module 10 Phase 1). Centralized, pure validation for offer
//  create/edit (handbook Ch6). No DB, no side-effects. Returns:
//    ['success' => bool, 'errors' => [field => message], 'data' => [clean fields]]
//  `data` is the sanitized, DB-ready payload the repository expects.
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Offer;

final class OfferValidator
{
    public function validateOffer(array $in, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $errors = [];

        $candidateId = (int)($in['candidate_id'] ?? 0);
        if ($candidateId <= 0) $errors['candidate_id'] = 'A candidate is required.';

        $jobTitle = trim((string)($in['job_title'] ?? ''));
        if ($jobTitle === '') $errors['job_title'] = 'Job title is required.';
        elseif (mb_strlen($jobTitle) > 150) $errors['job_title'] = 'Job title is too long.';

        $employmentType = (string)($in['employment_type'] ?? 'full_time');
        if (!OfferWorkflow::isEmploymentType($employmentType)) $errors['employment_type'] = 'Invalid employment type.';

        $salaryRaw = $in['salary'] ?? '';
        $salary = null;
        if ($salaryRaw === '' || $salaryRaw === null) {
            $errors['salary'] = 'Salary is required.';
        } elseif (!is_numeric($salaryRaw) || (float)$salaryRaw < 0) {
            $errors['salary'] = 'Salary must be a non-negative number.';
        } else {
            $salary = round((float)$salaryRaw, 2);
        }

        $currency = strtoupper(trim((string)($in['currency'] ?? 'INR')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) $errors['currency'] = 'Currency must be a 3-letter code.';

        $joining = $this->dateOrNull($in['joining_date'] ?? '');
        if (($in['joining_date'] ?? '') !== '' && $joining === null) {
            $errors['joining_date'] = 'Joining date is invalid.';
        }

        $expiry = $this->dateOrNull($in['expiry_date'] ?? '');
        if (($in['expiry_date'] ?? '') !== '' && $expiry === null) {
            $errors['expiry_date'] = 'Expiry date is invalid.';
        } elseif ($expiry !== null && $expiry < $today) {
            $errors['expiry_date'] = 'Expiry date cannot be in the past.';
        }

        return [
            'success' => $errors === [],
            'errors'  => $errors,
            'data'    => [
                'candidate_id'    => $candidateId,
                'job_id'          => isset($in['job_id']) && $in['job_id'] !== '' ? (int)$in['job_id'] : null,
                'recruiter_id'    => isset($in['recruiter_id']) && $in['recruiter_id'] !== '' ? (int)$in['recruiter_id'] : null,
                'interview_id'    => isset($in['interview_id']) && $in['interview_id'] !== '' ? (int)$in['interview_id'] : null,
                'job_title'       => $jobTitle,
                'department'      => trim((string)($in['department'] ?? '')),
                'location'        => trim((string)($in['location'] ?? '')),
                'employment_type' => $employmentType,
                'salary'          => $salary,
                'currency'        => $currency,
                'joining_date'    => $joining,
                'expiry_date'     => $expiry,
                'benefits'        => trim((string)($in['benefits'] ?? '')),
                'notes'           => trim((string)($in['notes'] ?? '')),
            ],
        ];
    }

    private function dateOrNull(string $d): ?string
    {
        $d = trim($d);
        if ($d === '') return null;
        $dt = \DateTime::createFromFormat('Y-m-d', $d);
        return ($dt !== false && $dt->format('Y-m-d') === $d) ? $d : null;
    }
}
