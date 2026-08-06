<?php

declare(strict_types=1);

namespace App\DTOs\Automation;

/**
 * A single health indicator card for the automation operations dashboard.
 *
 * @param  array<string, scalar|null>  $meta
 */
final readonly class AutomationHealthCardDTO
{
    /**
     * @param  array<string, scalar|null>  $meta
     */
    public function __construct(
        public string $key,
        public string $state,
        public string $label,
        public ?string $reason = null,
        public ?string $checkedAtIso = null,
        public array $meta = [],
    ) {}
}
