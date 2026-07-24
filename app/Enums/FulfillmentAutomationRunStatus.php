<?php

declare(strict_types=1);

namespace App\Enums;

enum FulfillmentAutomationRunStatus: string
{
    case Reserved = 'reserved';
    case Dispatched = 'dispatched';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case NeedsReview = 'needs_review';
    case Cancelled = 'cancelled';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string>
     */
    public static function activeValues(): array
    {
        return [
            self::Reserved->value,
            self::Dispatched->value,
            self::Running->value,
        ];
    }

    /**
     * @return array<string>
     */
    public static function terminalValues(): array
    {
        return [
            self::Succeeded->value,
            self::Failed->value,
            self::NeedsReview->value,
            self::Cancelled->value,
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this->value, self::terminalValues(), true);
    }

    public function isActive(): bool
    {
        return in_array($this->value, self::activeValues(), true);
    }
}
