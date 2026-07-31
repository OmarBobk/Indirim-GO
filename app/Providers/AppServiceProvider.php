<?php

namespace App\Providers;

use App\Domain\Security\Contracts\HumanVerifier;
use App\Domain\Security\Services\TurnstileVerifier;
use App\Events\AutomationRunChanged;
use App\Events\BugInboxChanged;
use App\Events\FulfillmentListChanged;
use App\Events\TopupRequestsChanged;
use App\Listeners\BroadcastAdminOpsInboxOnDomainEvents;
use App\Listeners\SendBugRecordedAdminNotifications;
use App\Services\CustomerPriceService;
use App\Services\PriceCalculator;
use App\Support\ActivityLogBroadcaster;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CustomerPriceService::class, function ($app): CustomerPriceService {
            return new CustomerPriceService($app->make(PriceCalculator::class));
        });

        $this->app->bind(HumanVerifier::class, TurnstileVerifier::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureMobileCatalogRateLimiting();
        $this->configureMobilePurchaseRateLimiting();
        $this->registerAuthActivityHooks();
        $this->registerActivityBroadcasting();
        $this->registerBugNotifications();
        $this->registerAdminOpsBroadcasting();
        $this->registerNotificationChannels();
        $this->registerPwaInstallButtonPermission();
        $this->configureVitePreload();
    }

    protected function configureMobileCatalogRateLimiting(): void
    {
        RateLimiter::for('mobile-catalog', function (Request $request): array {
            $userId = $request->user()?->getAuthIdentifier() ?? 'guest';

            return [
                Limit::perMinute(60)->by('mobile-catalog-user|'.$userId),
                Limit::perMinute(120)->by('mobile-catalog-ip|'.$request->ip()),
            ];
        });
    }

    protected function configureMobilePurchaseRateLimiting(): void
    {
        RateLimiter::for('mobile-purchase-read', function (Request $request): array {
            $userId = $request->user()?->getAuthIdentifier() ?? 'guest';

            return [
                Limit::perMinute(60)->by('mobile-purchase-read-user|'.$userId),
                Limit::perMinute(120)->by('mobile-purchase-read-ip|'.$request->ip()),
            ];
        });

        RateLimiter::for('mobile-purchase-write', function (Request $request): array {
            $userId = $request->user()?->getAuthIdentifier() ?? 'guest';

            return [
                Limit::perMinute(20)->by('mobile-purchase-write-user|'.$userId),
                Limit::perMinute(60)->by('mobile-purchase-write-ip|'.$request->ip()),
            ];
        });
    }

    /**
     * Disable preload for CSS to avoid "preloaded but not used" warnings
     * when using Livewire wire:navigate (browser can treat preload as unused).
     */
    protected function configureVitePreload(): void
    {
        app(Vite::class)->usePreloadTagAttributes(function ($src, $url, $chunk, $manifest) {
            if (isset($chunk['file']) && str_ends_with((string) $chunk['file'], '.css')) {
                return false;
            }

            return [];
        });
    }

    protected function registerNotificationChannels(): void
    {
        Notification::extend('fcm', function ($app) {
            return $app->make(\App\Notifications\Channels\FcmChannel::class);
        });
    }

    /**
     * Show PWA install button only to users with install_pwa_app permission.
     * Runs before head partials render so @PwaHead sees the updated config.
     */
    protected function registerPwaInstallButtonPermission(): void
    {
        $views = ['partials.head', 'partials.frontend.head'];

        View::composer($views, function (): void {
            $show = auth()->check() && auth()->user()->can('install_pwa_app');
            config(['pwa.install-button' => $show]);
        });
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );
    }

    protected function registerAuthActivityHooks(): void
    {
        Event::listen(Logout::class, function (Logout $event): void {
            $user = $event->user;

            if ($user === null) {
                return;
            }

            $properties = [
                'user_id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->getRoleNames()->toArray(),
                'is_active' => $user->is_active,
                'email_verified_at' => $user->email_verified_at?->format('M d, Y H:i') ?? '—',
                'phone' => $user->phone,
            ];

            if ($user->hasAnyRole(['admin', 'supervisor'])) {
                activity()
                    ->inLog('admin')
                    ->event('admin.logout')
                    ->performedOn($user)
                    ->causedBy($user)
                    ->withProperties($properties)
                    ->log('Admin logout');
            } else {
                activity()
                    ->inLog('admin')
                    ->event('user.logout')
                    ->performedOn($user)
                    ->causedBy($user)
                    ->withProperties($properties)
                    ->log('User logout');
            }
        });
    }

    protected function registerBugNotifications(): void
    {
        Event::listen(BugInboxChanged::class, SendBugRecordedAdminNotifications::class);
    }

    protected function registerAdminOpsBroadcasting(): void
    {
        $listener = BroadcastAdminOpsInboxOnDomainEvents::class;

        Event::listen(FulfillmentListChanged::class, [$listener, 'handleFulfillmentListChanged']);
        Event::listen(TopupRequestsChanged::class, [$listener, 'handleTopupRequestsChanged']);
        Event::listen(BugInboxChanged::class, [$listener, 'handleBugInboxChanged']);
        Event::listen(AutomationRunChanged::class, [$listener, 'handleAutomationRunChanged']);
    }

    protected function registerActivityBroadcasting(): void
    {
        Activity::created(function (Activity $activity): void {
            ActivityLogBroadcaster::dispatchCreated($activity->id);
        });
    }
}
