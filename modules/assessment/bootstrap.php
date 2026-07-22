<?php
// ═════════════════════════════════════════════════════════════════════════════
//  SmartHire Assessment Platform Core — bootstrap (Module 8A)
//  The ONLY file product code should require. Loads the whole module.
//  Entry points stay thin PHP files at the web root (URL stability); all
//  assessment logic lives under modules/assessment/.
//  Public surface: SmartHire\Assessment\Engine\AssessmentService (facade).
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

require_once __DIR__ . '/domain/Entities.php';
require_once __DIR__ . '/shared/Config.php';
require_once __DIR__ . '/shared/Events.php';
require_once __DIR__ . '/shared/AiInterfaces.php';
require_once __DIR__ . '/shared/AntiCheat.php';
require_once __DIR__ . '/shared/Repositories.php';
require_once __DIR__ . '/engine/QTypeRegistry.php';
require_once __DIR__ . '/engine/QuestionRenderer.php';
require_once __DIR__ . '/engine/Generator.php';
require_once __DIR__ . '/scoring/ScoringEngine.php';
require_once __DIR__ . '/results/ResultEngine.php';
require_once __DIR__ . '/engine/AssessmentService.php';
