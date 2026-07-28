<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Shared customer-facing status → Flux badge color mapping.
 * Presentation only — does not change domain status values.
 */
final class CustomerStatusPresentation
{
    public static function badgeColor(string $status): string
    {
        $normalized = strtolower(trim($status));

        return match ($normalized) {
            'paid', 'completed', 'fulfilled', 'approved', 'posted', 'success', 'credited', 'active' => 'green',
            'processing', 'pending', 'queued', 'in_progress', 'waiting' => 'amber',
            'failed', 'rejected', 'cancelled', 'canceled', 'expired', 'debt', 'overdrawn' => 'red',
            'refunded', 'partial', 'needs_attention', 'blocked' => 'rose',
            'unread', 'new', 'info' => 'sky',
            default => 'zinc',
        };
    }

    /**
     * Map Activity semantic status tokens to the shared Flux badge palette.
     */
    public static function activityBadgeColor(string $statusToken): string
    {
        return match (strtolower(trim($statusToken))) {
            'success' => 'green',
            'warning' => 'amber',
            'danger' => 'red',
            'progress' => 'sky',
            default => 'zinc',
        };
    }
}
