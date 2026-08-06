<?php

declare(strict_types=1);

use App\Actions\Fulfillments\RunWasimHealthProbe;
use App\Livewire\Admin\AutomationMonitor;
use App\Models\User;
use App\Models\WebsiteSetting;
use App\Support\Automation\WasimHealthProbeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'fulfillment_automation.enabled' => true,
        'fulfillment_automation.callback_secret' => 'test-automation-secret',
        'fulfillment_automation.worker_url' => 'http://automation-worker.test',
        'fulfillment_automation.suppliers.wasim.session_key' => 'wasim-main',
        'fulfillment_automation.suppliers.wasim.credentials.username' => 'wasim-user',
        'fulfillment_automation.suppliers.wasim.credentials.password' => 'wasim-pass',
        'fulfillment_automation.wasim_probe.enabled' => true,
        'fulfillment_automation.wasim_probe.timeout_seconds' => 90,
        'fulfillment_automation.wasim_probe.cache_seconds' => 60,
    ]);

    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    WebsiteSetting::query()->delete();
    WebsiteSetting::create(['automation_enabled' => true]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function wasimProbeResponse(array $overrides = []): array
{
    return array_merge([
        'checked_at' => now()->toIso8601String(),
        'worker_build' => '2026-08-05-c1.2-probe',
        'worker_instance_id' => 'inst-probe-1',
        'driver_version' => 'wasim-1.2.0',
        'detected_ui_version' => 'wasim-ui-v3',
        'purchase_contract_version' => 'purchase-contract-v2',
        'orders_contract_version' => 'orders-contract-v2',
        'session_state' => 'valid',
        'purchase_contract_state' => 'valid',
        'reconcile_contract_state' => 'valid',
        'test_product_state' => 'ok',
        'state' => 'healthy',
        'failure_codes' => [],
        'duration_ms' => 1234,
        'operational_classification' => 'nominal',
    ], $overrides);
}

it('records a healthy snapshot from a valid worker probe response', function (): void {
    Http::fake([
        'automation-worker.test/v1/suppliers/wasim/probe' => Http::response(wasimProbeResponse(), 200),
    ]);

    $snapshot = app(RunWasimHealthProbe::class)->handle(force: true);

    expect($snapshot['last_result']['state'])->toBe('healthy')
        ->and($snapshot['last_result']['detected_ui_version'])->toBe('wasim-ui-v3')
        ->and($snapshot['last_result']['driver_version'])->toBe('wasim-1.2.0')
        ->and($snapshot['consecutive_failure_count'])->toBe(0)
        ->and($snapshot['last_healthy_at'])->not->toBeNull();

    $stored = app(WasimHealthProbeStore::class)->get();
    expect($stored['last_result']['state'])->toBe('healthy');

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return $request->url() === 'http://automation-worker.test/v1/suppliers/wasim/probe'
            && ($data['session_key'] ?? null) === 'wasim-main'
            && ($data['mode'] ?? null) === 'full'
            && $request->hasHeader('X-Automation-Signature')
            && $request->hasHeader('X-Automation-Timestamp');
    });
});

it('records unreachable when the worker rejects the HMAC signature', function (): void {
    Http::fake([
        'automation-worker.test/v1/suppliers/wasim/probe' => Http::response(['message' => 'invalid signature'], 401),
    ]);

    $snapshot = app(RunWasimHealthProbe::class)->handle(force: true);

    expect($snapshot['last_result']['state'])->toBe('unreachable')
        ->and($snapshot['last_result']['failure_codes'])->toContain('hmac_unauthorized')
        ->and($snapshot['consecutive_failure_count'])->toBe(1);
});

it('records unreachable when the probe request times out', function (): void {
    Http::fake(function (): never {
        throw new ConnectionException('Connection timed out');
    });

    $snapshot = app(RunWasimHealthProbe::class)->handle(force: true);

    expect($snapshot['last_result']['state'])->toBe('unreachable')
        ->and($snapshot['last_result']['failure_codes'])->toContain('connection_failed');
});

it('records unreachable when the worker is unreachable (non-2xx)', function (): void {
    Http::fake([
        'automation-worker.test/v1/suppliers/wasim/probe' => Http::response('bad gateway', 502),
    ]);

    $snapshot = app(RunWasimHealthProbe::class)->handle(force: true);

    expect($snapshot['last_result']['state'])->toBe('unreachable')
        ->and($snapshot['last_result']['failure_codes'])->toContain('http_502');
});

it('records unreachable when the worker signals it is busy (409)', function (): void {
    Http::fake([
        'automation-worker.test/v1/suppliers/wasim/probe' => Http::response(['message' => 'busy'], 409),
    ]);

    $snapshot = app(RunWasimHealthProbe::class)->handle(force: true);

    expect($snapshot['last_result']['state'])->toBe('unreachable')
        ->and($snapshot['last_result']['failure_codes'])->toContain('probe_busy');
});

it('records contract_failed for a malformed worker response', function (): void {
    Http::fake([
        'automation-worker.test/v1/suppliers/wasim/probe' => Http::response(['unexpected' => true], 200),
    ]);

    $snapshot = app(RunWasimHealthProbe::class)->handle(force: true);

    expect($snapshot['last_result']['state'])->toBe('contract_failed')
        ->and($snapshot['last_result']['failure_codes'])->toContain('invalid_state_field');
});

it('records an unsupported_ui snapshot from the worker', function (): void {
    Http::fake([
        'automation-worker.test/v1/suppliers/wasim/probe' => Http::response(wasimProbeResponse([
            'state' => 'unsupported_ui',
            'failure_codes' => ['ui_signature_unknown'],
        ]), 200),
    ]);

    $snapshot = app(RunWasimHealthProbe::class)->handle(force: true);

    expect($snapshot['last_result']['state'])->toBe('unsupported_ui')
        ->and($snapshot['last_result']['failure_codes'])->toContain('ui_signature_unknown');
});

it('records an authentication_required snapshot from the worker', function (): void {
    Http::fake([
        'automation-worker.test/v1/suppliers/wasim/probe' => Http::response(wasimProbeResponse([
            'state' => 'authentication_required',
            'session_state' => 'expired',
            'failure_codes' => ['session_expired'],
        ]), 200),
    ]);

    $snapshot = app(RunWasimHealthProbe::class)->handle(force: true);

    expect($snapshot['last_result']['state'])->toBe('authentication_required')
        ->and($snapshot['last_result']['session_state'])->toBe('expired');
});

it('tracks consecutive failures and clears them on recovery', function (): void {
    Http::fake([
        'automation-worker.test/v1/suppliers/wasim/probe' => Http::sequence()
            ->push(wasimProbeResponse(['state' => 'authentication_required', 'failure_codes' => ['session_expired']]), 200)
            ->push(wasimProbeResponse(['state' => 'authentication_required', 'failure_codes' => ['session_expired']]), 200)
            ->push(wasimProbeResponse(['state' => 'healthy']), 200),
    ]);

    $action = app(RunWasimHealthProbe::class);

    $first = $action->handle(force: true);
    expect($first['consecutive_failure_count'])->toBe(1);

    $second = $action->handle(force: true);
    expect($second['consecutive_failure_count'])->toBe(2)
        ->and($second['previous_healthy'])->toBeFalse();

    $third = $action->handle(force: true);
    expect($third['consecutive_failure_count'])->toBe(0)
        ->and($third['last_result']['state'])->toBe('healthy');
});

it('does not re-probe the worker within the cache window unless forced', function (): void {
    Http::fake([
        'automation-worker.test/v1/suppliers/wasim/probe' => Http::response(wasimProbeResponse(), 200),
    ]);

    $action = app(RunWasimHealthProbe::class);
    $action->handle(force: true);
    $action->handle(force: false);

    Http::assertSentCount(1);
});

it('never mutates fulfillments while probing', function (): void {
    $fulfillment = makeAutomationAdminFulfillment();
    $originalStatus = $fulfillment->status;
    $originalUpdatedAt = $fulfillment->updated_at;

    Http::fake([
        'automation-worker.test/v1/suppliers/wasim/probe' => Http::response(wasimProbeResponse(), 200),
    ]);

    app(RunWasimHealthProbe::class)->handle(force: true);

    $fulfillment->refresh();
    expect($fulfillment->status)->toBe($originalStatus)
        ->and($fulfillment->updated_at->equalTo($originalUpdatedAt))->toBeTrue();
});

it('never mutates the automation_enabled kill switch while probing', function (): void {
    Http::fake([
        'automation-worker.test/v1/suppliers/wasim/probe' => Http::response(wasimProbeResponse([
            'state' => 'unreachable',
        ]), 200),
    ]);

    $before = WebsiteSetting::getAutomationEnabled();

    app(RunWasimHealthProbe::class)->handle(force: true);

    expect(WebsiteSetting::getAutomationEnabled())->toBe($before);
});

it('allows an admin to trigger the probe from the automation monitor', function (): void {
    Http::fake([
        'automation-worker.test/v1/suppliers/wasim/probe' => Http::response(wasimProbeResponse(), 200),
    ]);

    Livewire::actingAs(assistantAdminUser())
        ->test(AutomationMonitor::class)
        ->call('runWasimHealthProbe')
        ->assertHasNoErrors();

    expect(app(WasimHealthProbeStore::class)->get()['last_result']['state'])->toBe('healthy');
});

it('rejects a non-admin calling the probe action on the automation monitor', function (): void {
    $user = User::factory()->create();

    expect(fn () => Livewire::actingAs($user)->test(AutomationMonitor::class)->call('runWasimHealthProbe'))
        ->toThrow(Exception::class);
});
