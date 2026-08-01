<?php

declare(strict_types=1);

namespace App\DTOs\Admin;

final readonly class CommissionClawbackDisputeDecisionDTO
{
    public function __construct(
        public bool $allowed,
        public string $mode,
        public string $safeDenialKey,
        public string $status,
        public bool $isPosted,
        public ?int $activeDisputeId = null,
        public ?string $activeDisputeRef = null,
    ) {}
}
