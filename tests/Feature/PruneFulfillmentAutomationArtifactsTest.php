<?php

declare(strict_types=1);

use App\Enums\FulfillmentAutomationRunStatus;
use App\Models\FulfillmentAutomationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'fulfillment_automation.enabled' => true,
        'fulfillment_automation.callback_secret' => 'test-automation-secret',
    ]);

    \Illuminate\Support\Facades\Queue::fake();

    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

test('prune command deletes old automation artifact files and clears run meta', function () {
    Storage::fake('local');

    $runUuid = (string) Str::uuid();
    $directory = 'fulfillment-automation/'.$runUuid;
    $path = $directory.'/product-20260101000000.png';

    Storage::disk('local')->put($path, 'png-bytes');

    $fulfillment = makeAutomationAdminFulfillment();

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => $runUuid,
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'acme',
        'status' => FulfillmentAutomationRunStatus::Failed,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:prune-test',
        'finished_at' => now()->subDays(40),
        'meta' => ['artifact_paths' => [$path]],
    ]);

    $this->artisan('fulfillment:prune-automation-artifacts', ['--days' => 30])
        ->assertSuccessful();

    Storage::disk('local')->assertMissing($path);
    Storage::disk('local')->assertMissing($directory);

    $meta = $run->refresh()->meta ?? [];

    expect($meta['artifact_paths'] ?? null)->toBe([])
        ->and($meta)->toHaveKey('artifacts_pruned_at');
});

test('prune command dry run does not delete files', function () {
    Storage::fake('local');

    $runUuid = (string) Str::uuid();
    $path = 'fulfillment-automation/'.$runUuid.'/product.png';

    Storage::disk('local')->put($path, 'png-bytes');

    $fulfillment = makeAutomationAdminFulfillment();

    FulfillmentAutomationRun::query()->create([
        'uuid' => $runUuid,
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'acme',
        'status' => FulfillmentAutomationRunStatus::Failed,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:prune-dry',
        'finished_at' => now()->subDays(40),
        'meta' => ['artifact_paths' => [$path]],
    ]);

    $this->artisan('fulfillment:prune-automation-artifacts', ['--days' => 30, '--dry-run' => true])
        ->assertSuccessful();

    Storage::disk('local')->assertExists($path);
});
