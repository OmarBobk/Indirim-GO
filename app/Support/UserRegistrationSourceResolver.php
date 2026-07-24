<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

final class UserRegistrationSourceResolver
{
    /**
     * @param  list<int>  $userIds
     * @return array<int, UserRegistrationSource>
     */
    public static function resolveForUserIds(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($userIds === []) {
            return [];
        }

        /** @var EloquentCollection<int, Activity> $rows */
        $rows = Activity::query()
            ->where('subject_type', User::class)
            ->whereIn('subject_id', $userIds)
            ->whereIn('event', ['user.created', 'user.registered'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        /** @var Collection<int, Activity> $firstBySubject */
        $firstBySubject = $rows
            ->groupBy(fn (Activity $a): int => (int) $a->subject_id)
            ->map(fn (Collection $group) => $group->first());

        $causerIds = $firstBySubject->pluck('causer_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $causers = $causerIds === []
            ? collect()
            : User::query()->whereIn('id', $causerIds)->get()->keyBy('id');

        $out = [];
        foreach ($userIds as $id) {
            $activity = $firstBySubject->get($id);
            $out[$id] = self::fromActivity($activity, $causers);
        }

        return $out;
    }

    public static function resolveForUser(User $user): UserRegistrationSource
    {
        $map = self::resolveForUserIds([(int) $user->id]);

        return $map[(int) $user->id] ?? UserRegistrationSource::unknown();
    }

    /**
     * @param  Collection<int|string, Activity>  $causers  keyed by user id
     */
    private static function fromActivity(?Activity $activity, Collection $causers): UserRegistrationSource
    {
        if ($activity === null) {
            return UserRegistrationSource::unknown();
        }

        if ($activity->event === 'user.registered') {
            return new UserRegistrationSource('self_registered', null);
        }

        if ($activity->event === 'user.created') {
            $referrerInLog = data_get($activity->properties, 'referred_by_user_id');
            $actor = $activity->causer_id !== null ? $causers->get((int) $activity->causer_id) : null;

            if ($referrerInLog !== null && $referrerInLog !== '') {
                return new UserRegistrationSource('salesperson_created', $actor instanceof User ? $actor : null);
            }

            return new UserRegistrationSource('admin_created', $actor instanceof User ? $actor : null);
        }

        return UserRegistrationSource::unknown();
    }
}
