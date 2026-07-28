<?php

namespace App\Providers;

use App\Actions\Auth\AuthenticateUserCredentials;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\LoginResponse;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\TwoFactorLoginResponse;
use App\Domain\Security\Listeners\RecordSuccessfulRegistration;
use App\Exceptions\AccountLoginDenied;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\RegisteredUserController as FortifyRegisteredUserController;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Swap Fortify's registration controller so security runs before CreateNewUser.
        $this->app->bind(FortifyRegisteredUserController::class, RegisteredUserController::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configureRegistrationProtection();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);

        Fortify::authenticateUsing(function (Request $request): ?User {
            try {
                return app(AuthenticateUserCredentials::class)->execute(
                    (string) $request->input(Fortify::username()),
                    (string) $request->input('password'),
                );
            } catch (AccountLoginDenied $exception) {
                throw ValidationException::withMessages([
                    Fortify::username() => [__($exception->translationKey)],
                ]);
            }
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('livewire.auth.login'));
        Fortify::verifyEmailView(fn () => view('livewire.auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('livewire.auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('livewire.auth.confirm-password'));
        if (Features::enabled(Features::registration())) {
            Fortify::registerView(fn () => view('livewire.auth.register'));
        }
        Fortify::resetPasswordView(fn () => view('livewire.auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('livewire.auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('mobile-login', function (Request $request): Limit {
            $username = $request->input(Fortify::username());
            $normalizedUsername = is_string($username) ? Str::lower($username) : '';
            $throttleKey = Str::transliterate($normalizedUsername.'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('mobile-two-factor', function (Request $request): Limit {
            $challengeToken = $request->input('challenge_token');
            $challengeHash = hash('sha256', is_string($challengeToken) ? $challengeToken : '');

            return Limit::perMinute(10)->by($challengeHash.'|'.$request->ip());
        });
    }

    /**
     * Record success budgets after Fortify creates the user (Registered event).
     * Pre-create security runs in {@see RegisteredUserController}.
     */
    private function configureRegistrationProtection(): void
    {
        Event::listen(Registered::class, RecordSuccessfulRegistration::class);
    }
}
