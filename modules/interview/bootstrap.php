<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Interview module bootstrap (Module 9). Single require point for the domain
//  module. Load this once; it pulls in the entity, DB adapter, repository,
//  validator, and service. Consumers use InterviewService only.
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/InterviewWorkflow.php';
require_once __DIR__ . '/domain/Interview.php';
require_once __DIR__ . '/InterviewRepository.php';
require_once __DIR__ . '/InterviewValidator.php';
require_once __DIR__ . '/InterviewService.php';
