<?php

declare(strict_types=1);

namespace App\DTOs\Financial;

use App\Enums\FinancialDestinationType;

/**
 * Typed navigation target for financial overview surfaces. No URLs or HTML.
 */
final readonly class FinancialDestinationDTO
{
    /**
     * @param  array<string, scalar|null>  $params
     */
    public function __construct(
        public FinancialDestinationType $type,
        public array $params = [],
    ) {}
}
