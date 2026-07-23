<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Offers module bootstrap (Module 10). Single require point for the domain
//  module: load this once; it pulls in the workflow, DB adapter, entity,
//  repository, validator, and service. Consumers use OfferService only.
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/OfferWorkflow.php';
require_once __DIR__ . '/domain/Offer.php';
require_once __DIR__ . '/OfferRepository.php';
require_once __DIR__ . '/OfferValidator.php';
require_once __DIR__ . '/OfferService.php';
