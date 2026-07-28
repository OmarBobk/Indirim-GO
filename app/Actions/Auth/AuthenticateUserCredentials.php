<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\AccountLoginDenied;
use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;

final class AuthenticateUserCredentials
{
    public function __construct(private readonly Hasher $hasher) {}

    /**
     * @throws AccountLoginDenied
     */
    public function execute(string $username, string $password): ?User
    {
        $user = User::query()->where('username', $username)->first();

        if ($user === null || ! $this->hasher->check($password, $user->password)) {
            return null;
        }

        if (! $user->isActive()) {
            throw AccountLoginDenied::inactive();
        }

        if ($user->isBlocked()) {
            throw AccountLoginDenied::blocked();
        }

        return $user;
    }
}
