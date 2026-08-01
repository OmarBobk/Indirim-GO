<?php

declare(strict_types=1);

use App\Enums\MobileCheckoutAttemptStatus;
use App\Models\MobileCheckoutAttempt;
use App\Models\Order;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    m31Website();
    m31EnsurePricingRule();
    RateLimiter::clear('mobile-purchase-write-user|1');
});

test('prune removes only terminal attempts older than configured retention', function (): void {
    config(['mobile_api.checkout.idempotency_retention_hours' => 72]);

    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $token = m31Token($user);

    $completed = m31CheckoutOnce($user, $token, $product, $package, 'prune-old-completed');
    $oldCompleted = MobileCheckoutAttempt::query()->where('user_id', $user->id)->firstOrFail();
    $oldCompleted->forceFill([
        'completed_at' => now()->subHours(73),
        'created_at' => now()->subHours(74),
        'updated_at' => now()->subHours(73),
    ])->save();

    $recent = MobileCheckoutAttempt::query()->create([
        'user_id' => $user->id,
        'key_hash' => hash('sha256', 'prune-recent'),
        'request_hash' => hash('sha256', 'recent'),
        'status' => MobileCheckoutAttemptStatus::Completed,
        'order_id' => Order::query()->where('order_number', $completed['order_number'])->value('id'),
        'receipt' => ['order_number' => $completed['order_number']],
        'completed_at' => now()->subHours(1),
    ]);

    $processing = MobileCheckoutAttempt::query()->create([
        'user_id' => $user->id,
        'key_hash' => hash('sha256', 'prune-processing'),
        'request_hash' => hash('sha256', 'processing'),
        'status' => MobileCheckoutAttemptStatus::Processing,
        'processing_started_at' => now()->subHours(80),
        'created_at' => now()->subHours(80),
    ]);

    $failedOld = MobileCheckoutAttempt::query()->create([
        'user_id' => $user->id,
        'key_hash' => hash('sha256', 'prune-failed'),
        'request_hash' => hash('sha256', 'failed'),
        'status' => MobileCheckoutAttemptStatus::Failed,
        'failure_code' => 'checkout_failed',
        'completed_at' => now()->subHours(100),
        'created_at' => now()->subHours(100),
    ]);

    Artisan::call('mobile-checkout:prune-attempts');
    expect(Artisan::output())->toContain('Pruned');

    expect(MobileCheckoutAttempt::query()->whereKey($oldCompleted->id)->exists())->toBeFalse()
        ->and(MobileCheckoutAttempt::query()->whereKey($failedOld->id)->exists())->toBeFalse()
        ->and(MobileCheckoutAttempt::query()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(MobileCheckoutAttempt::query()->whereKey($processing->id)->exists())->toBeTrue();

    // Safe to run repeatedly.
    Artisan::call('mobile-checkout:prune-attempts');
    expect(MobileCheckoutAttempt::query()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(MobileCheckoutAttempt::query()->whereKey($processing->id)->exists())->toBeTrue();
});

test('prune dry-run does not delete rows', function (): void {
    $user = m31Customer();
    MobileCheckoutAttempt::query()->create([
        'user_id' => $user->id,
        'key_hash' => hash('sha256', 'dry-run'),
        'request_hash' => hash('sha256', 'dry'),
        'status' => MobileCheckoutAttemptStatus::Completed,
        'completed_at' => now()->subHours(100),
        'created_at' => now()->subHours(100),
        'receipt' => ['order_number' => 'IG-DRY'],
    ]);

    Artisan::call('mobile-checkout:prune-attempts', ['--dry-run' => true]);

    expect(MobileCheckoutAttempt::query()->count())->toBe(1);
});

test('prune deletes more than one batch of eligible terminal attempts', function (): void {
    config(['mobile_api.checkout.idempotency_retention_hours' => 72]);

    $user = m31Customer();
    $recent = MobileCheckoutAttempt::query()->create([
        'user_id' => $user->id,
        'key_hash' => hash('sha256', 'prune-batch-recent'),
        'request_hash' => hash('sha256', 'recent-batch'),
        'status' => MobileCheckoutAttemptStatus::Completed,
        'completed_at' => now()->subHours(1),
        'receipt' => ['order_number' => 'IG-RECENT'],
    ]);

    $processing = MobileCheckoutAttempt::query()->create([
        'user_id' => $user->id,
        'key_hash' => hash('sha256', 'prune-batch-processing'),
        'request_hash' => hash('sha256', 'processing-batch'),
        'status' => MobileCheckoutAttemptStatus::Processing,
        'processing_started_at' => now()->subHours(100),
        'created_at' => now()->subHours(100),
    ]);

    $retryable = MobileCheckoutAttempt::query()->create([
        'user_id' => $user->id,
        'key_hash' => hash('sha256', 'prune-batch-retryable'),
        'request_hash' => hash('sha256', 'retryable-batch'),
        'status' => MobileCheckoutAttemptStatus::Failed,
        'failure_code' => 'checkout_retry_required',
        'created_at' => now()->subHours(1),
        'completed_at' => null,
    ]);

    $rows = [];
    for ($i = 0; $i < 501; $i++) {
        $rows[] = [
            'user_id' => $user->id,
            'key_hash' => hash('sha256', 'prune-batch-old-'.$i),
            'request_hash' => hash('sha256', 'old-batch-'.$i),
            'status' => MobileCheckoutAttemptStatus::Completed->value,
            'order_id' => null,
            'receipt' => json_encode(['order_number' => 'IG-OLD-'.$i], JSON_THROW_ON_ERROR),
            'failure_code' => null,
            'processing_started_at' => null,
            'completed_at' => now()->subHours(100),
            'created_at' => now()->subHours(101),
            'updated_at' => now()->subHours(100),
        ];
    }

    foreach (array_chunk($rows, 100) as $chunk) {
        MobileCheckoutAttempt::query()->insert($chunk);
    }

    expect(MobileCheckoutAttempt::query()->count())->toBe(504);

    Artisan::call('mobile-checkout:prune-attempts');
    $output = Artisan::output();
    expect($output)->toContain('Pruned 501 terminal mobile checkout attempt(s)');

    expect(MobileCheckoutAttempt::query()->count())->toBe(3)
        ->and(MobileCheckoutAttempt::query()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(MobileCheckoutAttempt::query()->whereKey($processing->id)->exists())->toBeTrue()
        ->and(MobileCheckoutAttempt::query()->whereKey($retryable->id)->exists())->toBeTrue()
        ->and(MobileCheckoutAttempt::query()->where('status', MobileCheckoutAttemptStatus::Completed)->where('completed_at', '<=', now()->subHours(72))->count())->toBe(0);

    Artisan::call('mobile-checkout:prune-attempts');
    expect(Artisan::output())->toContain('Pruned 0 terminal mobile checkout attempt(s)')
        ->and(MobileCheckoutAttempt::query()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(MobileCheckoutAttempt::query()->whereKey($processing->id)->exists())->toBeTrue()
        ->and(MobileCheckoutAttempt::query()->whereKey($retryable->id)->exists())->toBeTrue();
});

test('scheduler registers mobile checkout prune command', function (): void {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
    $matched = $events->first(
        fn ($event) => str_contains($event->command ?? '', 'mobile-checkout:prune-attempts')
            || str_contains($event->description ?? '', 'mobile-checkout:prune-attempts')
            || (is_string($event->command) && str_contains($event->command, 'prune-attempts'))
    );

    // Laravel stores artisan commands as php artisan ... prune-attempts
    $found = $events->contains(function ($event): bool {
        $command = (string) ($event->command ?? '');
        $expression = method_exists($event, 'getExpression') ? (string) $event->getExpression() : '';

        return str_contains($command, 'mobile-checkout:prune-attempts');
    });

    expect($found)->toBeTrue();
});
