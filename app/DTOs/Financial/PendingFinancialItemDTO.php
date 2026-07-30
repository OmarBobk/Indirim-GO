<?php

declare(strict_types=1);

namespace App\DTOs\Financial;

use App\Enums\FinancialPendingActor;
use Carbon\CarbonInterface;

/**
 * One unresolved financial workflow row for the overview summary.
 */
final readonly class PendingFinancialItemDTO
{
    public function __construct(
        public string $id,
        public string $kind,
        public FinancialPendingActor $actor,
        public string $titleKey,
        public ?string $amount,
        public string $currency,
        public CarbonInterface $occurredAt,
        public FinancialDestinationDTO $destination,
        public ?string $referenceLabel = null,
        public ?string $customerSafeReason = null,
    ) {}
}
