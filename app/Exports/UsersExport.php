<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\User;
use App\Support\UserRegistrationSourceResolver;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class UsersExport implements FromQuery, WithHeadings, WithMapping
{
    /** @var array<int, \App\Support\UserRegistrationSource>|null */
    private ?array $registrationSources = null;

    public function query(): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()
            ->select([
                'id',
                'name',
                'username',
                'email',
                'phone',
                'country_code',
                'referred_by_user_id',
                'email_verified_at',
                'blocked_at',
                'last_login_at',
                'created_at',
            ])
            ->with([
                'roles:id,name',
                'referredBy:id,username,name',
            ])
            ->orderBy('name');
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            __('messages.id'),
            __('messages.name'),
            __('messages.username'),
            __('messages.email'),
            __('messages.phone'),
            __('messages.roles'),
            __('messages.status'),
            __('messages.email_verified'),
            __('messages.last_login'),
            __('messages.created'),
            __('messages.user_registration'),
            __('messages.user_registration_export_detail'),
        ];
    }

    /**
     * @param  User  $row
     * @return array<int, string|null>
     */
    public function map($row): array
    {
        if ($this->registrationSources === null) {
            $this->registrationSources = UserRegistrationSourceResolver::resolveForUserIds(
                $this->query()->pluck('id')->all()
            );
        }

        $phoneDisplay = trim(($row->country_code ?? '').' '.($row->phone ?? '')) ?: null;

        $source = $this->registrationSources[$row->id] ?? null;
        $cells = $source !== null ? $source->exportCells($row) : [__('messages.user_registration_unknown'), ''];

        return [
            $row->id,
            $row->name,
            $row->username,
            $row->email,
            $phoneDisplay,
            $row->roles->pluck('name')->implode(', ') ?: null,
            $row->blocked_at ? __('messages.blocked') : __('messages.active'),
            $row->email_verified_at?->format('Y-m-d H:i'),
            $row->last_login_at?->format('Y-m-d H:i'),
            $row->created_at?->format('Y-m-d H:i'),
            $cells[0] ?? '',
            $cells[1] ?? '',
        ];
    }
}
