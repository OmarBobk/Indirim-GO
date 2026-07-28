<?php

declare(strict_types=1);

namespace App\Support\Activity;

use App\DTOs\CustomerActivityDTO;
use App\Enums\CustomerActivityImportance;

/**
 * Merge notification and action-required Activity rows with deterministic dedupe.
 */
final class CustomerActivityMerger
{
    public const SUMMARY_LIMIT = 3;

    /**
     * @param  list<CustomerActivityDTO>  $actionItems
     * @param  list<CustomerActivityDTO>  $notificationItems
     * @return list<CustomerActivityDTO>
     */
    public function withNotificationTwins(array $actionItems, array $notificationItems): array
    {
        $notificationsByKey = [];
        foreach ($notificationItems as $notification) {
            foreach ($this->keysFor($notification) as $key) {
                $notificationsByKey[$key] ??= $notification;
            }
        }

        return array_map(
            function (CustomerActivityDTO $action) use ($notificationsByKey): CustomerActivityDTO {
                foreach ($this->keysFor($action) as $key) {
                    if (isset($notificationsByKey[$key])) {
                        return $this->attachTwinNotification($action, $notificationsByKey[$key]);
                    }
                }

                return $action;
            },
            $actionItems
        );
    }

    /**
     * Remove notification rows that share a dedupe key with an action-required item.
     *
     * @param  list<CustomerActivityDTO>  $notificationItems
     * @param  list<CustomerActivityDTO>  $actionItems
     * @return list<CustomerActivityDTO>
     */
    public function suppressNotificationTwins(array $notificationItems, array $actionItems): array
    {
        $actionKeys = [];
        foreach ($actionItems as $action) {
            foreach ($this->keysFor($action) as $key) {
                $actionKeys[$key] = true;
            }
        }

        return array_values(array_filter(
            $notificationItems,
            function (CustomerActivityDTO $notification) use ($actionKeys): bool {
                foreach ($this->keysFor($notification) as $key) {
                    if (isset($actionKeys[$key])) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    /**
     * @param  list<CustomerActivityDTO>  $actionItems
     * @return list<CustomerActivityDTO>
     */
    public function sortActionRequired(array $actionItems): array
    {
        usort($actionItems, function (CustomerActivityDTO $a, CustomerActivityDTO $b): int {
            $importance = $this->importanceRank($a->importance) <=> $this->importanceRank($b->importance);
            if ($importance !== 0) {
                return $importance;
            }

            $time = $b->occurredAt->getTimestamp() <=> $a->occurredAt->getTimestamp();
            if ($time !== 0) {
                return $time;
            }

            return strcmp($b->stableKey, $a->stableKey);
        });

        return array_values($actionItems);
    }

    /**
     * @param  list<CustomerActivityDTO>  $actionItems
     * @return list<CustomerActivityDTO>
     */
    public function summary(array $actionItems): array
    {
        $actionable = array_values(array_filter(
            $actionItems,
            static fn (CustomerActivityDTO $item): bool => $item->requiresAction
        ));

        return array_slice($this->sortActionRequired($actionable), 0, self::SUMMARY_LIMIT);
    }

    /**
     * @return list<string>
     */
    public function keysFor(CustomerActivityDTO $item): array
    {
        $keys = [$item->dedupeKey];
        $related = $item->secondaryMeta['related_dedupe_keys'] ?? null;

        if (is_string($related) && $related !== '') {
            foreach (explode(',', $related) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $keys[] = $part;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    private function attachTwinNotification(CustomerActivityDTO $action, CustomerActivityDTO $notification): CustomerActivityDTO
    {
        $notificationId = str_starts_with($notification->id, 'notification:')
            ? substr($notification->id, strlen('notification:'))
            : null;

        $meta = $action->secondaryMeta;
        if ($notificationId !== null && $notificationId !== '') {
            $meta['twin_notification_id'] = $notificationId;
        }

        return new CustomerActivityDTO(
            id: $action->id,
            stableKey: $action->stableKey,
            sourceType: $action->sourceType,
            sourceId: $action->sourceId,
            dedupeKey: $action->dedupeKey,
            groupKey: $action->groupKey,
            category: $action->category,
            importance: $action->importance,
            statusToken: $action->statusToken,
            title: $action->title,
            description: $action->description,
            occurredAt: $action->occurredAt,
            readAt: $notification->readAt,
            isUnread: false,
            requiresAction: $action->requiresAction,
            actionLabel: $action->actionLabel,
            destination: $action->destination,
            secondaryMeta: $meta,
            money: $action->money,
            iconKey: $action->iconKey,
        );
    }

    private function importanceRank(CustomerActivityImportance $importance): int
    {
        return match ($importance) {
            CustomerActivityImportance::Urgent => 0,
            CustomerActivityImportance::Attention => 1,
            CustomerActivityImportance::Success => 2,
            CustomerActivityImportance::Informational => 3,
        };
    }
}
