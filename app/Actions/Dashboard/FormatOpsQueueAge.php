<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use Carbon\CarbonInterface;

class FormatOpsQueueAge
{
    private const MIN_HOURS_FOR_BADGE = 4;

    /**
     * @return array{label: string, severity: string}|null
     */
    public function handle(?CarbonInterface $oldestAt): ?array
    {
        if ($oldestAt === null) {
            return null;
        }

        $hours = (int) $oldestAt->diffInHours(now(), false);

        if ($hours < self::MIN_HOURS_FOR_BADGE) {
            return null;
        }

        if ($hours < 24) {
            return [
                'label' => __('messages.admin_ops_age_hours', ['hours' => $hours]),
                'severity' => 'zinc',
            ];
        }

        $days = max(1, (int) floor($hours / 24));

        return [
            'label' => __('messages.admin_ops_age_days', ['days' => $days]),
            'severity' => $days >= 3 ? 'red' : 'amber',
        ];
    }
}
