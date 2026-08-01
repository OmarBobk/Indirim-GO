<?php

declare(strict_types=1);

namespace App\DTOs\Admin;

/**
 * Server-side clawback retry eligibility decision (M7.2.1).
 */
final readonly class CommissionClawbackRetryDecisionDTO
{
    public function __construct(
        public bool $allowed,
        public string $reasonCode,
        public string $safeExplanationKey,
        public string $nextActionKey,
        public bool $isStale = false,
        public ?string $targetStatus = null,
    ) {}
}
