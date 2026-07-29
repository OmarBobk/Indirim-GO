<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\AccountLoginDenied;
use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;

final class AuthenticateUserCredentials
{
    private const DUMMY_PASSWORD_HASH = '$2y$12$Ftbbu4LfUfcaiz4fofo2O.0kkBeP4p5lHL0vDR31ARTbOceB8IPui';

    public function __construct(private readonly Hasher $hasher) {}

    /**
     * @throws AccountLoginDenied
     */
    public function execute(string $username, string $password): ?User
    {
        $user = User::query()->where('username', $username)->first();
        $passwordHash = (string) ($user?->getAuthPassword() ?? self::DUMMY_PASSWORD_HASH);
        $credentialsAreValid = $this->hasher->check($password, $passwordHash);

        if ($user === null || ! $credentialsAreValid) {
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
