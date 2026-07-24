<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\Enums\AdminDashboardVariant;
use App\Models\User;

class GetAdminSidebarCounts
{
    public function __construct(
        private GetAdminExceptionCounts $exceptionCounts,
        private ResolveAdminDashboardVariant $resolveVariant,
    ) {}

    /**
     * @return array<string, int>
     */
    public function handle(User $user): array
    {
        $counts = $this->exceptionCounts->handle($user);
        $counts['total_exceptions'] = $this->totalExceptionsForVariant(
            $counts,
            $this->resolveVariant->handle($user),
        );

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     */
    public function totalExceptionsForVariant(array $counts, AdminDashboardVariant $variant): int
    {
        $keys = GetAdminExceptionCounts::actionableKeysForVisible($variant->visibleExceptionKeys());

        return (int) collect($counts)->only($keys)->sum();
    }

    public function metric(User $user, string $key): int
    {
        $counts = $this->handle($user);

        return (int) ($counts[$key] ?? 0);
    }
}
