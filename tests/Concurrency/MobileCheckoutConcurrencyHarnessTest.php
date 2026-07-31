<?php

declare(strict_types=1);

/**
 * Opt-in MySQL/MariaDB concurrency harness for Mobile M3.1 checkout.
 *
 * Safety gates (all required):
 * - MOBILE_CONCURRENCY_TESTS=1
 * - APP_ENV=testing
 * - database driver mysql/mariadb
 * - database name ends with `_concurrency`
 *
 * Never uses SQLite fallback. Never calls migrate:fresh.
 * Fixtures are committed (no RefreshDatabase) so an external HTTP server can see them.
 * The HTTP server and Pest runner must share the same disposable DB and APP_KEY.
 *
 * Local Windows example:
 *   $env:APP_ENV="testing"
 *   $env:DB_CONNECTION="mysql"
 *   $env:DB_DATABASE="indirimgo_m31_concurrency"
 *   $env:MOBILE_CONCURRENCY_TESTS="1"
 *   php artisan migrate --force
 *   php artisan serve --port=8088
 *   # other shell with the same env:
 *   $env:MOBILE_CONCURRENCY_BASE_URL="http://127.0.0.1:8088"
 *   php artisan test --compact tests/Concurrency/MobileCheckoutConcurrencyHarnessTest.php
 */

use App\Enums\WalletTransactionType;
use App\Models\Fulfillment;
use App\Models\MobileCheckoutAttempt;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WebsiteSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * @return array{baseUrl: string, runId: string, spawned: bool, process: resource|null}|null
 */
function m31ConcurrencyContext(): ?array
{
    static $context = null;
    static $skipReason = null;

    if ($skipReason !== null) {
        test()->markTestSkipped($skipReason);
    }

    if ($context !== null) {
        return $context;
    }

    $flag = (string) env('MOBILE_CONCURRENCY_TESTS', '');
    if (! in_array($flag, ['1', 'true', 'TRUE'], true)) {
        $skipReason = 'Opt-in MySQL concurrency harness — set MOBILE_CONCURRENCY_TESTS=1 (skipped).';
        test()->markTestSkipped($skipReason);
    }

    if (! app()->environment('testing')) {
        $skipReason = 'Opt-in concurrency harness requires APP_ENV=testing.';
        test()->markTestSkipped($skipReason);
    }

    $driver = DB::connection()->getDriverName();
    if (! in_array($driver, ['mysql', 'mariadb'], true)) {
        $skipReason = 'Opt-in concurrency harness requires mysql/mariadb (refusing '.$driver.').';
        test()->markTestSkipped($skipReason);
    }

    $database = (string) DB::connection()->getDatabaseName();
    if ($database === '' || ! str_ends_with($database, '_concurrency')) {
        $skipReason = 'Opt-in concurrency harness refuses database "'.$database.'" — name must end with _concurrency.';
        test()->markTestSkipped($skipReason);
    }

    if (! Schema::hasTable('mobile_checkout_attempts') || ! Schema::hasColumn('orders', 'mobile_attempt_key_hash')) {
        $skipReason = 'Disposable concurrency schema incomplete — run migrations manually on the _concurrency DB (migrate:fresh is never auto-run).';
        test()->markTestSkipped($skipReason);
    }

    $baseUrl = rtrim((string) env('MOBILE_CONCURRENCY_BASE_URL', ''), '/');
    $process = null;
    $spawned = false;

    if ($baseUrl === '') {
        $port = (int) env('MOBILE_CONCURRENCY_PORT', (string) random_int(18080, 18999));
        $baseUrl = 'http://127.0.0.1:'.$port;
        $process = m31ConcurrencySpawnServer($port);
        $spawned = true;

        $ready = false;
        for ($i = 0; $i < 40; $i++) {
            try {
                $response = Http::timeout(1)->get($baseUrl.'/up');
                if ($response->successful() || $response->status() > 0) {
                    $ready = true;
                    break;
                }
            } catch (Throwable) {
                // waiting for artisan serve
            }
            usleep(250_000);
        }

        if (! $ready) {
            m31ConcurrencyStopServer($process);
            $skipReason = 'Failed to start local artisan serve for concurrency harness.';
            test()->markTestSkipped($skipReason);
        }
    }

    $context = [
        'baseUrl' => $baseUrl,
        'runId' => 'm31c_'.Str::lower(Str::random(10)),
        'spawned' => $spawned,
        'process' => $process,
    ];

    register_shutdown_function(static function () use (&$context): void {
        if (is_array($context) && ($context['spawned'] ?? false) && isset($context['process'])) {
            m31ConcurrencyStopServer($context['process']);
        }
    });

    return $context;
}

/**
 * @return resource|null
 */
function m31ConcurrencySpawnServer(int $port)
{
    $connection = config('database.default');
    $db = config('database.connections.'.$connection);

    $env = [
        'APP_ENV' => 'testing',
        'APP_KEY' => (string) config('app.key'),
        'APP_DEBUG' => 'false',
        'DB_CONNECTION' => (string) $connection,
        'DB_HOST' => (string) ($db['host'] ?? '127.0.0.1'),
        'DB_PORT' => (string) ($db['port'] ?? '3306'),
        'DB_DATABASE' => (string) ($db['database'] ?? ''),
        'DB_USERNAME' => (string) ($db['username'] ?? ''),
        'DB_PASSWORD' => (string) ($db['password'] ?? ''),
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
        'TELESCOPE_ENABLED' => 'false',
        'MOBILE_CONCURRENCY_TESTS' => '1',
    ];

    $pairs = [];
    foreach ($env as $key => $value) {
        $pairs[] = $key.'='.escapeshellarg($value);
    }

    $command = implode(' ', $pairs).' '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('artisan')).' serve --host=127.0.0.1 --port='.$port;

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', sys_get_temp_dir().'/m31-concurrency-serve.out', 'a'],
        2 => ['file', sys_get_temp_dir().'/m31-concurrency-serve.err', 'a'],
    ];

    $process = proc_open($command, $descriptors, $pipes, base_path());
    if (! is_resource($process)) {
        return null;
    }

    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    return $process;
}

/**
 * @param  resource|null  $process
 */
function m31ConcurrencyStopServer($process): void
{
    if (! is_resource($process)) {
        return;
    }

    $status = proc_get_status($process);
    if (($status['running'] ?? false) && isset($status['pid'])) {
        if (PHP_OS_FAMILY === 'Windows') {
            exec('taskkill /F /T /PID '.(int) $status['pid'].' 2>NUL');
        } else {
            posix_kill((int) $status['pid'], SIGTERM);
        }
    }

    proc_terminate($process);
    proc_close($process);
}

/**
 * @return array{
 *     runId: string,
 *     user: User,
 *     token: string,
 *     package: Package,
 *     product: Product,
 *     baseUrl: string
 * }
 */
function m31ConcurrencyFixtures(string $runId, string $baseUrl, string|float $balance = 500): array
{
    if (! WebsiteSetting::query()->exists()) {
        m31Website();
    } else {
        WebsiteSetting::query()->update([
            'prices_visible' => true,
            'usd_try_rate' => 40,
            'usd_try_rate_updated_at' => now(),
        ]);
    }

    PricingRule::query()->firstOrCreate(
        [
            'min_price' => 0,
            'max_price' => 999999.99,
            'priority' => 0,
        ],
        [
            'retail_percentage' => 0,
            'wholesale_percentage' => 0,
            'is_active' => true,
        ]
    );

    $user = m31Customer([
        'email' => $runId.'.'.Str::lower(Str::random(6)).'@concurrency.test',
        'name' => $runId,
    ]);
    m31Fund($user, $balance);
    ['package' => $package, 'product' => $product] = m31FixedProduct([
        'name' => $runId.' product',
        'entry_price' => 10,
    ]);
    $package->update(['name' => $runId.' package']);

    return [
        'runId' => $runId,
        'user' => $user,
        'token' => m31Token($user),
        'package' => $package,
        'product' => $product,
        'baseUrl' => $baseUrl,
    ];
}

function m31ConcurrencyCleanup(User ...$users): void
{
    foreach ($users as $user) {
        $orderIds = Order::query()->where('user_id', $user->id)->pluck('id');
        if ($orderIds->isNotEmpty()) {
            Fulfillment::query()->whereIn('order_id', $orderIds)->delete();
            OrderItem::query()->whereIn('order_id', $orderIds)->delete();
        }
        MobileCheckoutAttempt::query()->where('user_id', $user->id)->delete();
        Order::query()->where('user_id', $user->id)->delete();
        WalletTransaction::query()->whereIn(
            'wallet_id',
            Wallet::query()->where('user_id', $user->id)->pluck('id')
        )->delete();
        Wallet::query()->where('user_id', $user->id)->delete();
        $user->tokens()->delete();
        $user->delete();
    }
}

/**
 * @param  array<string, string>  $headers
 * @param  array<string, mixed>  $payload
 * @return array<int, \Illuminate\Http\Client\Response>
 */
function m31ConcurrencyPool(string $baseUrl, string $path, array $headers, array $payload, int $copies = 2): array
{
    $requests = [];
    for ($i = 0; $i < $copies; $i++) {
        $requests['r'.$i] = $headers;
    }

    return Http::pool(function ($pool) use ($baseUrl, $path, $requests, $payload) {
        $out = [];
        foreach ($requests as $as => $headers) {
            $out[] = $pool->as($as)->withHeaders($headers)->post($baseUrl.$path, $payload);
        }

        return $out;
    });
}

beforeEach(function (): void {
    m31ConcurrencyContext();
});

test('same customer same key same payload concurrent checkout yields one purchase', function (): void {
    $ctx = m31ConcurrencyContext();
    $fx = m31ConcurrencyFixtures($ctx['runId'].'_s1', $ctx['baseUrl'], 500);
    $key = $ctx['runId'].'-same-payload';

    try {
        $quote = Http::withHeaders(m31Headers($fx['token']))
            ->post($fx['baseUrl'].'/api/v1/checkout/quote', [
                'items' => [['product_id' => $fx['product']->id, 'package_id' => $fx['package']->id, 'quantity' => 1]],
            ]);
        expect($quote->successful())->toBeTrue();

        $payload = [
            'items' => [['product_id' => $fx['product']->id, 'package_id' => $fx['package']->id, 'quantity' => 1]],
            'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
        ];

        $responses = m31ConcurrencyPool(
            $fx['baseUrl'],
            '/api/v1/checkout',
            m31Headers($fx['token'], $key),
            $payload,
            2,
        );

        $statuses = collect($responses)->map(fn ($r) => $r->status())->sort()->values()->all();
        expect(collect($statuses)->every(fn ($s) => in_array($s, [200, 202], true)))->toBeTrue()
            ->and($statuses)->toContain(200)
            ->and(Order::query()->where('user_id', $fx['user']->id)->count())->toBe(1)
            ->and(WalletTransaction::query()->where('type', WalletTransactionType::Purchase)->whereIn(
                'wallet_id',
                Wallet::query()->where('user_id', $fx['user']->id)->pluck('id')
            )->count())->toBe(1)
            ->and(Fulfillment::query()->whereIn(
                'order_id',
                Order::query()->where('user_id', $fx['user']->id)->pluck('id')
            )->count())->toBe(1);

        // Safe 202 path resolves via status or retry without a second debit.
        if (in_array(202, $statuses, true)) {
            $status = Http::withHeaders(m31Headers($fx['token'], $key))
                ->get($fx['baseUrl'].'/api/v1/checkout/status');
            expect(in_array($status->status(), [200, 202], true))->toBeTrue();
            if ($status->status() === 202) {
                $retry = Http::withHeaders(m31Headers($fx['token'], $key))
                    ->post($fx['baseUrl'].'/api/v1/checkout', $payload);
                expect($retry->status())->toBe(200);
            }
        }

        expect(Order::query()->where('user_id', $fx['user']->id)->count())->toBe(1);
    } finally {
        m31ConcurrencyCleanup($fx['user']);
        Product::query()->whereKey($fx['product']->id)->delete();
        Package::query()->whereKey($fx['package']->id)->delete();
    }
});

test('same customer same key different payload concurrent yields conflict and one purchase', function (): void {
    $ctx = m31ConcurrencyContext();
    $fx = m31ConcurrencyFixtures($ctx['runId'].'_s2', $ctx['baseUrl'], 500);
    $other = m31FixedProduct(['name' => $ctx['runId'].'_s2 other', 'entry_price' => 10]);
    $key = $ctx['runId'].'-diff-payload';

    try {
        $quoteA = Http::withHeaders(m31Headers($fx['token']))
            ->post($fx['baseUrl'].'/api/v1/checkout/quote', [
                'items' => [['product_id' => $fx['product']->id, 'package_id' => $fx['package']->id, 'quantity' => 1]],
            ]);
        $quoteB = Http::withHeaders(m31Headers($fx['token']))
            ->post($fx['baseUrl'].'/api/v1/checkout/quote', [
                'items' => [['product_id' => $other['product']->id, 'package_id' => $other['package']->id, 'quantity' => 1]],
            ]);
        expect($quoteA->successful())->toBeTrue()->and($quoteB->successful())->toBeTrue();

        $responses = Http::pool(fn ($pool) => [
            $pool->as('a')->withHeaders(m31Headers($fx['token'], $key))->post($fx['baseUrl'].'/api/v1/checkout', [
                'items' => [['product_id' => $fx['product']->id, 'package_id' => $fx['package']->id, 'quantity' => 1]],
                'quote_fingerprint' => $quoteA->json('data.quote_fingerprint'),
            ]),
            $pool->as('b')->withHeaders(m31Headers($fx['token'], $key))->post($fx['baseUrl'].'/api/v1/checkout', [
                'items' => [['product_id' => $other['product']->id, 'package_id' => $other['package']->id, 'quantity' => 1]],
                'quote_fingerprint' => $quoteB->json('data.quote_fingerprint'),
            ]),
        ]);

        $statuses = collect($responses)->map(fn ($r) => $r->status())->sort()->values()->all();
        $codes = collect($responses)->map(fn ($r) => $r->json('code'))->filter()->values()->all();

        expect($statuses)->toContain(200)
            ->and($statuses)->toContain(409)
            ->and($codes)->toContain('idempotency_conflict')
            ->and(Order::query()->where('user_id', $fx['user']->id)->count())->toBe(1)
            ->and(collect($statuses)->contains(500))->toBeFalse();
    } finally {
        m31ConcurrencyCleanup($fx['user']);
        Product::query()->whereKey([$fx['product']->id, $other['product']->id])->delete();
        Package::query()->whereKey([$fx['package']->id, $other['package']->id])->delete();
    }
});

test('same customer different keys identical payload creates two intentional purchases', function (): void {
    $ctx = m31ConcurrencyContext();
    $fx = m31ConcurrencyFixtures($ctx['runId'].'_s3', $ctx['baseUrl'], 500);

    try {
        $quote = Http::withHeaders(m31Headers($fx['token']))
            ->post($fx['baseUrl'].'/api/v1/checkout/quote', [
                'items' => [['product_id' => $fx['product']->id, 'package_id' => $fx['package']->id, 'quantity' => 1]],
            ]);
        expect($quote->successful())->toBeTrue();
        $payload = [
            'items' => [['product_id' => $fx['product']->id, 'package_id' => $fx['package']->id, 'quantity' => 1]],
            'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
        ];

        $responses = Http::pool(fn ($pool) => [
            $pool->as('a')->withHeaders(m31Headers($fx['token'], $ctx['runId'].'-key-a'))->post($fx['baseUrl'].'/api/v1/checkout', $payload),
            $pool->as('b')->withHeaders(m31Headers($fx['token'], $ctx['runId'].'-key-b'))->post($fx['baseUrl'].'/api/v1/checkout', $payload),
        ]);

        expect(collect($responses)->every(fn ($r) => $r->status() === 200))->toBeTrue()
            ->and(Order::query()->where('user_id', $fx['user']->id)->count())->toBe(2)
            ->and(WalletTransaction::query()->where('type', WalletTransactionType::Purchase)->whereIn(
                'wallet_id',
                Wallet::query()->where('user_id', $fx['user']->id)->pluck('id')
            )->count())->toBe(2);
    } finally {
        m31ConcurrencyCleanup($fx['user']);
        Product::query()->whereKey($fx['product']->id)->delete();
        Package::query()->whereKey($fx['package']->id)->delete();
    }
});

test('same wallet different keys with funds for only one yields one success and insufficient balance', function (): void {
    $ctx = m31ConcurrencyContext();
    $fx = m31ConcurrencyFixtures($ctx['runId'].'_s4', $ctx['baseUrl'], 10);

    try {
        $quote = Http::withHeaders(m31Headers($fx['token']))
            ->post($fx['baseUrl'].'/api/v1/checkout/quote', [
                'items' => [['product_id' => $fx['product']->id, 'package_id' => $fx['package']->id, 'quantity' => 1]],
            ]);
        expect($quote->successful())->toBeTrue();
        $payload = [
            'items' => [['product_id' => $fx['product']->id, 'package_id' => $fx['package']->id, 'quantity' => 1]],
            'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
        ];

        $responses = Http::pool(fn ($pool) => [
            $pool->as('a')->withHeaders(m31Headers($fx['token'], $ctx['runId'].'-fund-a'))->post($fx['baseUrl'].'/api/v1/checkout', $payload),
            $pool->as('b')->withHeaders(m31Headers($fx['token'], $ctx['runId'].'-fund-b'))->post($fx['baseUrl'].'/api/v1/checkout', $payload),
        ]);

        $statuses = collect($responses)->map(fn ($r) => $r->status())->sort()->values()->all();
        $codes = collect($responses)->map(fn ($r) => $r->json('code'))->filter()->values()->all();

        expect($statuses)->toContain(200)
            ->and($statuses)->toContain(422)
            ->and($codes)->toContain('insufficient_wallet_balance')
            ->and(Order::query()->where('user_id', $fx['user']->id)->count())->toBe(1);

        $wallet = Wallet::query()->where('user_id', $fx['user']->id)->firstOrFail();
        expect((float) $wallet->fresh()->balance)->toBeLessThanOrEqual(0.0)
            ->and((float) $wallet->fresh()->availableToSpend())->toBeGreaterThanOrEqual(0.0);
    } finally {
        m31ConcurrencyCleanup($fx['user']);
        Product::query()->whereKey($fx['product']->id)->delete();
        Package::query()->whereKey($fx['package']->id)->delete();
    }
});

test('two customers using the same raw key remain isolated and may both succeed', function (): void {
    $ctx = m31ConcurrencyContext();
    $fxA = m31ConcurrencyFixtures($ctx['runId'].'_s5a', $ctx['baseUrl'], 100);
    $fxB = m31ConcurrencyFixtures($ctx['runId'].'_s5b', $ctx['baseUrl'], 100);
    $sharedKey = $ctx['runId'].'-shared-raw-key';

    try {
        $quoteA = Http::withHeaders(m31Headers($fxA['token']))
            ->post($fxA['baseUrl'].'/api/v1/checkout/quote', [
                'items' => [['product_id' => $fxA['product']->id, 'package_id' => $fxA['package']->id, 'quantity' => 1]],
            ]);
        $quoteB = Http::withHeaders(m31Headers($fxB['token']))
            ->post($fxB['baseUrl'].'/api/v1/checkout/quote', [
                'items' => [['product_id' => $fxB['product']->id, 'package_id' => $fxB['package']->id, 'quantity' => 1]],
            ]);

        $responses = Http::pool(fn ($pool) => [
            $pool->as('a')->withHeaders(m31Headers($fxA['token'], $sharedKey))->post($fxA['baseUrl'].'/api/v1/checkout', [
                'items' => [['product_id' => $fxA['product']->id, 'package_id' => $fxA['package']->id, 'quantity' => 1]],
                'quote_fingerprint' => $quoteA->json('data.quote_fingerprint'),
            ]),
            $pool->as('b')->withHeaders(m31Headers($fxB['token'], $sharedKey))->post($fxB['baseUrl'].'/api/v1/checkout', [
                'items' => [['product_id' => $fxB['product']->id, 'package_id' => $fxB['package']->id, 'quantity' => 1]],
                'quote_fingerprint' => $quoteB->json('data.quote_fingerprint'),
            ]),
        ]);

        expect(collect($responses)->every(fn ($r) => $r->status() === 200))->toBeTrue()
            ->and(Order::query()->where('user_id', $fxA['user']->id)->count())->toBe(1)
            ->and(Order::query()->where('user_id', $fxB['user']->id)->count())->toBe(1);
    } finally {
        m31ConcurrencyCleanup($fxA['user'], $fxB['user']);
        Product::query()->whereKey([$fxA['product']->id, $fxB['product']->id])->delete();
        Package::query()->whereKey([$fxA['package']->id, $fxB['package']->id])->delete();
    }
});

test('checkout concurrent with status has no deadlock 500 and one purchase', function (): void {
    $ctx = m31ConcurrencyContext();
    $fx = m31ConcurrencyFixtures($ctx['runId'].'_s6', $ctx['baseUrl'], 100);
    $key = $ctx['runId'].'-checkout-status';

    try {
        $quote = Http::withHeaders(m31Headers($fx['token']))
            ->post($fx['baseUrl'].'/api/v1/checkout/quote', [
                'items' => [['product_id' => $fx['product']->id, 'package_id' => $fx['package']->id, 'quantity' => 1]],
            ]);
        expect($quote->successful())->toBeTrue();
        $payload = [
            'items' => [['product_id' => $fx['product']->id, 'package_id' => $fx['package']->id, 'quantity' => 1]],
            'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
        ];

        $responses = Http::pool(fn ($pool) => [
            $pool->as('checkout')->withHeaders(m31Headers($fx['token'], $key))->post($fx['baseUrl'].'/api/v1/checkout', $payload),
            $pool->as('status')->withHeaders(m31Headers($fx['token'], $key))->get($fx['baseUrl'].'/api/v1/checkout/status'),
        ]);

        $checkout = $responses['checkout'];
        $status = $responses['status'];

        expect($checkout->status())->not->toBe(500)
            ->and($status->status())->not->toBe(500)
            ->and(in_array($checkout->status(), [200, 202], true))->toBeTrue()
            ->and(in_array($status->status(), [200, 202, 404, 409], true))->toBeTrue();

        // Drain to a terminal single purchase.
        for ($i = 0; $i < 10; $i++) {
            $probe = Http::withHeaders(m31Headers($fx['token'], $key))->get($fx['baseUrl'].'/api/v1/checkout/status');
            if ($probe->status() === 200 && $probe->json('data.state') === 'completed') {
                break;
            }
            if ($probe->status() === 409 && $probe->json('code') === 'checkout_retry_required') {
                Http::withHeaders(m31Headers($fx['token'], $key))->post($fx['baseUrl'].'/api/v1/checkout', $payload);
                break;
            }
            if ($checkout->status() !== 200) {
                Http::withHeaders(m31Headers($fx['token'], $key))->post($fx['baseUrl'].'/api/v1/checkout', $payload);
            }
            usleep(100_000);
        }

        expect(Order::query()->where('user_id', $fx['user']->id)->count())->toBe(1)
            ->and(WalletTransaction::query()->where('type', WalletTransactionType::Purchase)->whereIn(
                'wallet_id',
                Wallet::query()->where('user_id', $fx['user']->id)->pluck('id')
            )->count())->toBe(1);
    } finally {
        m31ConcurrencyCleanup($fx['user']);
        Product::query()->whereKey($fx['product']->id)->delete();
        Package::query()->whereKey($fx['package']->id)->delete();
    }
});

test('unique first-insert race loser re-reads winner without uncaught query exception', function (): void {
    $ctx = m31ConcurrencyContext();
    $fx = m31ConcurrencyFixtures($ctx['runId'].'_s7', $ctx['baseUrl'], 500);
    $key = $ctx['runId'].'-first-insert';

    try {
        expect(MobileCheckoutAttempt::query()->where('user_id', $fx['user']->id)->count())->toBe(0);

        $quote = Http::withHeaders(m31Headers($fx['token']))
            ->post($fx['baseUrl'].'/api/v1/checkout/quote', [
                'items' => [['product_id' => $fx['product']->id, 'package_id' => $fx['package']->id, 'quantity' => 1]],
            ]);
        $payload = [
            'items' => [['product_id' => $fx['product']->id, 'package_id' => $fx['package']->id, 'quantity' => 1]],
            'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
        ];

        $responses = m31ConcurrencyPool(
            $fx['baseUrl'],
            '/api/v1/checkout',
            m31Headers($fx['token'], $key),
            $payload,
            4,
        );

        $statuses = collect($responses)->map(fn ($r) => $r->status())->all();
        expect(collect($statuses)->every(fn ($s) => in_array($s, [200, 202], true)))->toBeTrue()
            ->and(collect($statuses)->contains(500))->toBeFalse()
            ->and(MobileCheckoutAttempt::query()->where('user_id', $fx['user']->id)->count())->toBe(1)
            ->and(Order::query()->where('user_id', $fx['user']->id)->count())->toBe(1);
    } finally {
        m31ConcurrencyCleanup($fx['user']);
        Product::query()->whereKey($fx['product']->id)->delete();
        Package::query()->whereKey($fx['package']->id)->delete();
    }
});

test('stale orphan status reaches retryable outcome then same key payload retries safely', function (): void {
    $ctx = m31ConcurrencyContext();
    $fx = m31ConcurrencyFixtures($ctx['runId'].'_s8', $ctx['baseUrl'], 100);
    $key = $ctx['runId'].'-stale-orphan';
    $keyHash = hash('sha256', $key);

    try {
        $quote = Http::withHeaders(m31Headers($fx['token']))
            ->post($fx['baseUrl'].'/api/v1/checkout/quote', [
                'items' => [['product_id' => $fx['product']->id, 'package_id' => $fx['package']->id, 'quantity' => 1]],
            ]);
        expect($quote->successful())->toBeTrue();

        // Committed stale orphan visible to both Pest and HTTP server.
        MobileCheckoutAttempt::query()->create([
            'user_id' => $fx['user']->id,
            'key_hash' => $keyHash,
            'request_hash' => hash('sha256', json_encode([
                'item' => [
                    'product_id' => (int) $fx['product']->id,
                    'package_id' => (int) $fx['package']->id,
                    'quantity' => 1,
                    'requested_amount' => null,
                    'requirements' => [],
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'status' => 'processing',
            'processing_started_at' => now()->subMinutes(10),
            'order_id' => null,
            'receipt' => null,
        ]);

        expect(Order::query()->where('user_id', $fx['user']->id)->count())->toBe(0);

        $status = Http::withHeaders(m31Headers($fx['token'], $key))
            ->get($fx['baseUrl'].'/api/v1/checkout/status');

        expect($status->status())->toBe(409)
            ->and($status->json('code'))->toBe('checkout_retry_required')
            ->and($status->json('details.idempotency_key_policy'))->toBe('reuse_same_key');

        $retry = Http::withHeaders(m31Headers($fx['token'], $key))
            ->post($fx['baseUrl'].'/api/v1/checkout', [
                'items' => [['product_id' => $fx['product']->id, 'package_id' => $fx['package']->id, 'quantity' => 1]],
                'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
            ]);

        expect($retry->status())->toBe(200)
            ->and(Order::query()->where('user_id', $fx['user']->id)->count())->toBe(1)
            ->and(WalletTransaction::query()->where('type', WalletTransactionType::Purchase)->whereIn(
                'wallet_id',
                Wallet::query()->where('user_id', $fx['user']->id)->pluck('id')
            )->count())->toBe(1);
    } finally {
        m31ConcurrencyCleanup($fx['user']);
        Product::query()->whereKey($fx['product']->id)->delete();
        Package::query()->whereKey($fx['package']->id)->delete();
    }
});
