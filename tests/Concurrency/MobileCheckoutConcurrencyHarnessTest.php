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
 * Fixtures are committed (no RefreshDatabase).
 * Prefers a self-spawned cross-platform `artisan serve` child (Symfony Process)
 * that inherits the exact APP_KEY + DB env. External BASE_URL requires a
 * fail-closed fixture + APP_KEY handshake.
 */

use App\Enums\OrderStatus;
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
use App\Support\Api\V1\MobileCheckoutQuoteBuilder;
use App\Support\LedgerMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * @return array{baseUrl: string, runId: string, process: ?Process}
 */
function m31ConcurrencyContext(): array
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

    $runId = 'm31c_'.Str::lower(Str::random(10));
    $external = rtrim((string) env('MOBILE_CONCURRENCY_BASE_URL', ''), '/');
    $process = null;

    if ($external !== '') {
        $baseUrl = $external;
    } else {
        $port = m31ConcurrencyFreePort();
        $baseUrl = 'http://127.0.0.1:'.$port;
        $process = m31ConcurrencySpawnServer($port);
        if ($process === null || ! m31ConcurrencyWaitUntilReady($baseUrl)) {
            m31ConcurrencyStopServer($process);
            $skipReason = 'Failed to start local artisan serve for concurrency harness.';
            test()->markTestSkipped($skipReason);
        }
    }

    $context = [
        'baseUrl' => $baseUrl,
        'runId' => $runId,
        'process' => $process,
    ];

    register_shutdown_function(static function () use (&$context): void {
        if (is_array($context) && ($context['process'] ?? null) instanceof Process) {
            m31ConcurrencyStopServer($context['process']);
        }
    });

    return $context;
}

function m31ConcurrencyFreePort(): int
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        return random_int(18080, 18999);
    }

    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    if (is_string($name) && str_contains($name, ':')) {
        return (int) substr(strrchr($name, ':'), 1);
    }

    return random_int(18080, 18999);
}

function m31ConcurrencySpawnServer(int $port): ?Process
{
    $connection = (string) config('database.default');
    $db = config('database.connections.'.$connection);

    // Symfony Process env array is cross-platform (no Unix ENV=value prefix).
    $env = m31ConcurrencyChildEnv([
        'APP_ENV' => 'testing',
        'APP_KEY' => (string) config('app.key'),
        'APP_DEBUG' => 'false',
        'DB_CONNECTION' => $connection,
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
    ]);

    $process = new Process(
        [PHP_BINARY, base_path('artisan'), 'serve', '--host=127.0.0.1', '--port='.(string) $port],
        base_path(),
        $env,
    );
    $process->setTimeout(null);
    $process->start();

    return $process;
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function m31ConcurrencyChildEnv(array $overrides): array
{
    $base = [];
    foreach (array_merge($_SERVER, $_ENV) as $key => $value) {
        if (is_string($key) && is_scalar($value)) {
            $base[$key] = (string) $value;
        }
    }

    return array_merge($base, $overrides);
}

function m31ConcurrencyWaitUntilReady(string $baseUrl): bool
{
    for ($i = 0; $i < 40; $i++) {
        try {
            $response = Http::timeout(1)->get($baseUrl.'/up');
            if ($response->status() > 0) {
                return true;
            }
        } catch (Throwable) {
            // waiting
        }
        usleep(250_000);
    }

    return false;
}

function m31ConcurrencyStopServer(?Process $process): void
{
    if ($process === null) {
        return;
    }

    if ($process->isRunning()) {
        $process->stop(3, SIGTERM);
    }
}

/**
 * Fail closed unless the HTTP server sees committed fixtures and shares APP_KEY.
 *
 * @param  array{user: User, token: string, package: Package, product: Product, baseUrl: string}  $fx
 */
function m31ConcurrencyAssertEnvironmentIdentity(array $fx): void
{
    $quote = Http::withHeaders(m31Headers($fx['token']))
        ->post($fx['baseUrl'].'/api/v1/checkout/quote', [
            'items' => [['product_id' => $fx['product']->id, 'package_id' => $fx['package']->id, 'quantity' => 1]],
        ]);

    if (! $quote->successful()) {
        test()->fail('Concurrency environment identity handshake failed: server cannot see committed fixtures (wrong database or unreachable app).');
    }

    $fingerprint = (string) $quote->json('data.quote_fingerprint');
    $payload = app(MobileCheckoutQuoteBuilder::class)->decodeAndVerifyFingerprint($fingerprint);
    if ($payload === null) {
        test()->fail('Concurrency environment identity handshake failed: APP_KEY mismatch between test process and HTTP server.');
    }

    expect((int) ($payload['product_id'] ?? 0))->toBe((int) $fx['product']->id)
        ->and((int) ($payload['user_id'] ?? 0))->toBe((int) $fx['user']->id);
}

/**
 * @return array{
 *     runId: string,
 *     user: User,
 *     token: string,
 *     package: Package,
 *     product: Product,
 *     baseUrl: string,
 *     openingBalance: string
 * }
 */
function m31ConcurrencyFixtures(string $runId, string $baseUrl, string $balance = '500.00'): array
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

    $fx = [
        'runId' => $runId,
        'user' => $user,
        'token' => m31Token($user),
        'package' => $package,
        'product' => $product,
        'baseUrl' => $baseUrl,
        'openingBalance' => LedgerMoney::normalize($balance),
    ];

    m31ConcurrencyAssertEnvironmentIdentity($fx);

    return $fx;
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

function m31ConcurrencyCleanupCatalog(Product ...$products): void
{
    $packageIds = [];
    foreach ($products as $product) {
        $packageIds[] = $product->package_id;
        $product->delete();
    }
    Package::query()->whereIn('id', array_filter($packageIds))->delete();
}

/**
 * @return array{orders: int, paid: int, purchases: int, fulfillments: int, balance: string, attempts: int, processing: int, completed: int}
 */
function m31ConcurrencyDurable(User $user): array
{
    $walletIds = Wallet::query()->where('user_id', $user->id)->pluck('id');
    $orderIds = Order::query()->where('user_id', $user->id)->pluck('id');

    return [
        'orders' => Order::query()->where('user_id', $user->id)->count(),
        'paid' => Order::query()->where('user_id', $user->id)->where('status', OrderStatus::Paid)->count(),
        'purchases' => WalletTransaction::query()
            ->whereIn('wallet_id', $walletIds)
            ->where('type', WalletTransactionType::Purchase)
            ->count(),
        'fulfillments' => Fulfillment::query()->whereIn('order_id', $orderIds)->count(),
        'balance' => LedgerMoney::normalize((string) Wallet::query()->where('user_id', $user->id)->value('balance')),
        'attempts' => MobileCheckoutAttempt::query()->where('user_id', $user->id)->count(),
        'processing' => MobileCheckoutAttempt::query()
            ->where('user_id', $user->id)
            ->where('status', 'processing')
            ->count(),
        'completed' => MobileCheckoutAttempt::query()
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->count(),
    ];
}

function m31ConcurrencyAssertFloor(User $user): void
{
    $wallet = Wallet::query()->where('user_id', $user->id)->firstOrFail();
    $balance = LedgerMoney::normalize((string) $wallet->balance);
    $minimum = LedgerMoney::normalize((string) $wallet->minimumAllowedBalance());
    expect(LedgerMoney::compare($balance, $minimum))->toBeGreaterThanOrEqual(0);
}

beforeEach(function (): void {
    m31ConcurrencyContext();
});

afterAll(function (): void {
    $ctx = null;
    try {
        // Best-effort: context may have been skipped.
        $flag = (string) env('MOBILE_CONCURRENCY_TESTS', '');
        if (in_array($flag, ['1', 'true', 'TRUE'], true) && app()->environment('testing')) {
            // Re-read static via calling helper only if already initialized — stop via shutdown fn.
        }
    } catch (Throwable) {
        // ignore
    }
});

test('same customer same key same payload concurrent checkout yields one purchase', function (): void {
    $ctx = m31ConcurrencyContext();
    $fx = m31ConcurrencyFixtures($ctx['runId'].'_s1', $ctx['baseUrl'], '500.00');
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
        $total = (string) $quote->json('data.total.amount');

        $responses = Http::pool(fn ($pool) => [
            $pool->as('a')->withHeaders(m31Headers($fx['token'], $key))->post($fx['baseUrl'].'/api/v1/checkout', $payload),
            $pool->as('b')->withHeaders(m31Headers($fx['token'], $key))->post($fx['baseUrl'].'/api/v1/checkout', $payload),
        ]);

        $statuses = collect($responses)->map(fn ($r) => $r->status())->values()->all();
        expect(collect($statuses)->contains(500))->toBeFalse()
            ->and(collect($statuses)->every(fn ($s) => in_array($s, [200, 202], true)))->toBeTrue()
            ->and($statuses)->toContain(200);

        // Drain any 202 to a completed receipt without a second debit.
        for ($i = 0; $i < 10; $i++) {
            $status = Http::withHeaders(m31Headers($fx['token'], $key))->get($fx['baseUrl'].'/api/v1/checkout/status');
            if ($status->status() === 200 && $status->json('data.state') === 'completed') {
                expect($status->json('data.order.total.amount'))->toBe($total);
                break;
            }
            if (in_array(202, $statuses, true) || $status->status() === 202) {
                Http::withHeaders(m31Headers($fx['token'], $key))->post($fx['baseUrl'].'/api/v1/checkout', $payload);
            }
            usleep(50_000);
        }

        $d = m31ConcurrencyDurable($fx['user']);
        expect($d['orders'])->toBe(1)
            ->and($d['paid'])->toBe(1)
            ->and($d['purchases'])->toBe(1)
            ->and($d['fulfillments'])->toBe(1)
            ->and($d['completed'])->toBe(1)
            ->and($d['processing'])->toBe(0)
            ->and($d['balance'])->toBe(LedgerMoney::sub($fx['openingBalance'], $total));
        m31ConcurrencyAssertFloor($fx['user']);
    } finally {
        m31ConcurrencyCleanup($fx['user']);
        m31ConcurrencyCleanupCatalog($fx['product']);
    }
});

test('same customer same key different payload concurrent yields conflict and one purchase', function (): void {
    $ctx = m31ConcurrencyContext();
    $fx = m31ConcurrencyFixtures($ctx['runId'].'_s2', $ctx['baseUrl'], '500.00');
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

        $statuses = collect($responses)->map(fn ($r) => $r->status())->values()->all();
        $codes = collect($responses)->map(fn ($r) => $r->json('code'))->filter()->values()->all();
        expect(collect($statuses)->contains(500))->toBeFalse()
            ->and($statuses)->toContain(200)
            ->and($statuses)->toContain(409)
            ->and($codes)->toContain('idempotency_conflict');

        $status = Http::withHeaders(m31Headers($fx['token'], $key))->get($fx['baseUrl'].'/api/v1/checkout/status');
        expect($status->status())->toBe(200)
            ->and($status->json('data.state'))->toBe('completed');

        $d = m31ConcurrencyDurable($fx['user']);
        expect($d['orders'])->toBe(1)
            ->and($d['paid'])->toBe(1)
            ->and($d['purchases'])->toBe(1)
            ->and($d['fulfillments'])->toBe(1)
            ->and($d['processing'])->toBe(0)
            ->and($d['balance'])->toBe(LedgerMoney::sub($fx['openingBalance'], '10.00'));
        m31ConcurrencyAssertFloor($fx['user']);
    } finally {
        m31ConcurrencyCleanup($fx['user']);
        m31ConcurrencyCleanupCatalog($fx['product'], $other['product']);
    }
});

test('same customer different keys identical payload creates two intentional purchases', function (): void {
    $ctx = m31ConcurrencyContext();
    $fx = m31ConcurrencyFixtures($ctx['runId'].'_s3', $ctx['baseUrl'], '500.00');

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
            ->and(collect($responses)->contains(fn ($r) => $r->status() === 500))->toBeFalse();

        $d = m31ConcurrencyDurable($fx['user']);
        expect($d['orders'])->toBe(2)
            ->and($d['paid'])->toBe(2)
            ->and($d['purchases'])->toBe(2)
            ->and($d['fulfillments'])->toBe(2)
            ->and($d['completed'])->toBe(2)
            ->and($d['processing'])->toBe(0)
            ->and($d['balance'])->toBe(LedgerMoney::sub($fx['openingBalance'], '20.00'));
        m31ConcurrencyAssertFloor($fx['user']);
    } finally {
        m31ConcurrencyCleanup($fx['user']);
        m31ConcurrencyCleanupCatalog($fx['product']);
    }
});

test('same wallet different keys with funds for only one yields one success and insufficient balance', function (): void {
    $ctx = m31ConcurrencyContext();
    $fx = m31ConcurrencyFixtures($ctx['runId'].'_s4', $ctx['baseUrl'], '10.00');

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

        $statuses = collect($responses)->map(fn ($r) => $r->status())->values()->all();
        $codes = collect($responses)->map(fn ($r) => $r->json('code'))->filter()->values()->all();
        expect(collect($statuses)->contains(500))->toBeFalse()
            ->and($statuses)->toContain(200)
            ->and($statuses)->toContain(422)
            ->and($codes)->toContain('insufficient_wallet_balance');

        $d = m31ConcurrencyDurable($fx['user']);
        expect($d['orders'])->toBe(1)
            ->and($d['paid'])->toBe(1)
            ->and($d['purchases'])->toBe(1)
            ->and($d['fulfillments'])->toBe(1)
            ->and($d['balance'])->toBe('0.00');
        m31ConcurrencyAssertFloor($fx['user']);
    } finally {
        m31ConcurrencyCleanup($fx['user']);
        m31ConcurrencyCleanupCatalog($fx['product']);
    }
});

test('two customers using the same raw key remain isolated and may both succeed', function (): void {
    $ctx = m31ConcurrencyContext();
    $fxA = m31ConcurrencyFixtures($ctx['runId'].'_s5a', $ctx['baseUrl'], '100.00');
    $fxB = m31ConcurrencyFixtures($ctx['runId'].'_s5b', $ctx['baseUrl'], '100.00');
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
            ->and(collect($responses)->contains(fn ($r) => $r->status() === 500))->toBeFalse();

        $dA = m31ConcurrencyDurable($fxA['user']);
        $dB = m31ConcurrencyDurable($fxB['user']);
        expect($dA['orders'])->toBe(1)->and($dA['paid'])->toBe(1)->and($dA['purchases'])->toBe(1)->and($dA['fulfillments'])->toBe(1)
            ->and($dB['orders'])->toBe(1)->and($dB['paid'])->toBe(1)->and($dB['purchases'])->toBe(1)->and($dB['fulfillments'])->toBe(1)
            ->and($dA['balance'])->toBe('90.00')->and($dB['balance'])->toBe('90.00');
        m31ConcurrencyAssertFloor($fxA['user']);
        m31ConcurrencyAssertFloor($fxB['user']);
    } finally {
        m31ConcurrencyCleanup($fxA['user'], $fxB['user']);
        m31ConcurrencyCleanupCatalog($fxA['product'], $fxB['product']);
    }
});

test('checkout concurrent with status has no deadlock 500 and one purchase', function (): void {
    $ctx = m31ConcurrencyContext();
    $fx = m31ConcurrencyFixtures($ctx['runId'].'_s6', $ctx['baseUrl'], '100.00');
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

        expect($responses['checkout']->status())->not->toBe(500)
            ->and($responses['status']->status())->not->toBe(500)
            ->and(in_array($responses['checkout']->status(), [200, 202], true))->toBeTrue()
            ->and(in_array($responses['status']->status(), [200, 202, 404, 409], true))->toBeTrue();

        $finalStatus = null;
        for ($i = 0; $i < 15; $i++) {
            $finalStatus = Http::withHeaders(m31Headers($fx['token'], $key))->get($fx['baseUrl'].'/api/v1/checkout/status');
            if ($finalStatus->status() === 200 && $finalStatus->json('data.state') === 'completed') {
                break;
            }
            if ($finalStatus->status() === 409 && $finalStatus->json('code') === 'checkout_retry_required') {
                Http::withHeaders(m31Headers($fx['token'], $key))->post($fx['baseUrl'].'/api/v1/checkout', $payload);
            } elseif ($responses['checkout']->status() !== 200) {
                Http::withHeaders(m31Headers($fx['token'], $key))->post($fx['baseUrl'].'/api/v1/checkout', $payload);
            }
            usleep(50_000);
        }

        expect($finalStatus)->not->toBeNull()
            ->and($finalStatus->status())->toBe(200)
            ->and($finalStatus->json('data.state'))->toBe('completed')
            ->and($finalStatus->json('data.order.total.amount'))->toBe('10.00');

        $d = m31ConcurrencyDurable($fx['user']);
        expect($d['orders'])->toBe(1)
            ->and($d['paid'])->toBe(1)
            ->and($d['purchases'])->toBe(1)
            ->and($d['fulfillments'])->toBe(1)
            ->and($d['processing'])->toBe(0)
            ->and($d['balance'])->toBe('90.00');
        m31ConcurrencyAssertFloor($fx['user']);
    } finally {
        m31ConcurrencyCleanup($fx['user']);
        m31ConcurrencyCleanupCatalog($fx['product']);
    }
});

test('unique first-insert race loser re-reads winner without uncaught query exception', function (): void {
    $ctx = m31ConcurrencyContext();
    $fx = m31ConcurrencyFixtures($ctx['runId'].'_s7', $ctx['baseUrl'], '500.00');
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

        $responses = Http::pool(fn ($pool) => [
            $pool->as('a')->withHeaders(m31Headers($fx['token'], $key))->post($fx['baseUrl'].'/api/v1/checkout', $payload),
            $pool->as('b')->withHeaders(m31Headers($fx['token'], $key))->post($fx['baseUrl'].'/api/v1/checkout', $payload),
            $pool->as('c')->withHeaders(m31Headers($fx['token'], $key))->post($fx['baseUrl'].'/api/v1/checkout', $payload),
            $pool->as('d')->withHeaders(m31Headers($fx['token'], $key))->post($fx['baseUrl'].'/api/v1/checkout', $payload),
        ]);

        $statuses = collect($responses)->map(fn ($r) => $r->status())->values()->all();
        expect(collect($statuses)->every(fn ($s) => in_array($s, [200, 202], true)))->toBeTrue()
            ->and(collect($statuses)->contains(500))->toBeFalse();

        for ($i = 0; $i < 10; $i++) {
            $status = Http::withHeaders(m31Headers($fx['token'], $key))->get($fx['baseUrl'].'/api/v1/checkout/status');
            if ($status->status() === 200 && $status->json('data.state') === 'completed') {
                break;
            }
            Http::withHeaders(m31Headers($fx['token'], $key))->post($fx['baseUrl'].'/api/v1/checkout', $payload);
            usleep(50_000);
        }

        $d = m31ConcurrencyDurable($fx['user']);
        expect($d['attempts'])->toBe(1)
            ->and($d['orders'])->toBe(1)
            ->and($d['paid'])->toBe(1)
            ->and($d['purchases'])->toBe(1)
            ->and($d['fulfillments'])->toBe(1)
            ->and($d['processing'])->toBe(0)
            ->and($d['completed'])->toBe(1)
            ->and($d['balance'])->toBe('490.00');
        m31ConcurrencyAssertFloor($fx['user']);
    } finally {
        m31ConcurrencyCleanup($fx['user']);
        m31ConcurrencyCleanupCatalog($fx['product']);
    }
});

test('stale orphan status reaches retryable outcome then same key payload retries safely', function (): void {
    $ctx = m31ConcurrencyContext();
    $fx = m31ConcurrencyFixtures($ctx['runId'].'_s8', $ctx['baseUrl'], '100.00');
    $key = $ctx['runId'].'-stale-orphan';
    $keyHash = hash('sha256', $key);

    try {
        $quote = Http::withHeaders(m31Headers($fx['token']))
            ->post($fx['baseUrl'].'/api/v1/checkout/quote', [
                'items' => [['product_id' => $fx['product']->id, 'package_id' => $fx['package']->id, 'quantity' => 1]],
            ]);
        expect($quote->successful())->toBeTrue();

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
        expect($retry->status())->toBe(200)->and($retry->status())->not->toBe(500);

        $final = Http::withHeaders(m31Headers($fx['token'], $key))
            ->get($fx['baseUrl'].'/api/v1/checkout/status');
        expect($final->status())->toBe(200)
            ->and($final->json('data.state'))->toBe('completed')
            ->and($final->json('data.order.order_number'))->toBe($retry->json('data.order.order_number'))
            ->and($final->json('data.order.total.amount'))->toBe('10.00');

        $d = m31ConcurrencyDurable($fx['user']);
        expect($d['orders'])->toBe(1)
            ->and($d['paid'])->toBe(1)
            ->and($d['purchases'])->toBe(1)
            ->and($d['fulfillments'])->toBe(1)
            ->and($d['processing'])->toBe(0)
            ->and($d['balance'])->toBe('90.00');
        m31ConcurrencyAssertFloor($fx['user']);
    } finally {
        m31ConcurrencyCleanup($fx['user']);
        m31ConcurrencyCleanupCatalog($fx['product']);
    }
});
