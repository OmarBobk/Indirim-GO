<?php

declare(strict_types=1);

/**
 * Child process entry for AutomationConcurrencyHarnessTest.
 *
 * Usage: php tests/Concurrency/bin/automation_race.php <action> <json-payload>
 */

use App\Actions\Fulfillments\IngestFulfillmentAutomationProgress;
use App\Actions\Fulfillments\IngestFulfillmentAutomationResult;
use App\Actions\Fulfillments\ObserveAutomationSafetySignal;
use App\Actions\Fulfillments\PauseAutomationSupplierCircuit;
use App\Actions\Fulfillments\ReserveFulfillmentAutomationRun;
use App\Actions\Fulfillments\ResumeAutomationSupplierCircuit;
use App\DTOs\Automation\AutomationProgressPayloadDTO;
use App\Enums\AutomationCircuitCapability;
use App\Enums\AutomationCircuitPauseReason;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$config = [
    'fulfillment_automation.enabled' => true,
    'fulfillment_automation.callback_secret' => 'concurrency-secret',
    'fulfillment_automation.worker_url' => 'http://automation-worker.test',
    'broadcasting.default' => 'null',
];
foreach ($config as $key => $value) {
    config([$key => $value]);
}

$action = $argv[1] ?? '';
$payload = json_decode($argv[2] ?? '{}', true, 512, JSON_THROW_ON_ERROR);

try {
    $result = match ($action) {
        'reserve' => (function () use ($payload): array {
            $run = app(ReserveFulfillmentAutomationRun::class)->handle(
                Fulfillment::query()->findOrFail((int) $payload['fulfillment_id'])
            );

            return ['ok' => true, 'uuid' => $run->uuid];
        })(),
        'progress' => app(IngestFulfillmentAutomationProgress::class)->handle(
            FulfillmentAutomationRun::query()->findOrFail((int) $payload['run_id']),
            AutomationProgressPayloadDTO::fromValidated([
                'progress_sequence' => (int) $payload['sequence'],
                'phase' => 'purchase',
                'step' => (string) $payload['step'],
                'emitted_at' => now()->toIso8601String(),
                'heartbeat' => false,
                'worker_instance_id' => 'concurrency-worker',
                'worker_build' => 'concurrency',
                'driver_name' => 'wasim',
                'driver_version' => 'wasim-1.1.0',
            ]),
        ),
        'final' => (function () use ($payload): array {
            $run = app(IngestFulfillmentAutomationResult::class)->handle(
                FulfillmentAutomationRun::query()->findOrFail((int) $payload['run_id']),
                $payload['body'],
            );

            return ['ok' => true, 'status' => $run->status->value];
        })(),
        'signal' => (function () use ($payload): array {
            $circuit = app(ObserveAutomationSafetySignal::class)->handle($payload['input']);

            return ['ok' => true, 'state' => $circuit?->state->value];
        })(),
        'pause' => (function () use ($payload): array {
            $circuit = app(PauseAutomationSupplierCircuit::class)->handle(
                User::query()->findOrFail((int) $payload['admin_id']),
                'wasim',
                AutomationCircuitCapability::Purchase,
                AutomationCircuitPauseReason::Investigation,
                (string) ($payload['note'] ?? 'pause'),
            );

            return ['ok' => true, 'state' => $circuit->state->value];
        })(),
        'resume' => (function () use ($payload): array {
            $circuit = app(ResumeAutomationSupplierCircuit::class)->handle(
                User::query()->findOrFail((int) $payload['admin_id']),
                'wasim',
                AutomationCircuitCapability::Purchase,
                true,
                (string) ($payload['note'] ?? 'resume'),
            );

            return ['ok' => true, 'state' => $circuit->state->value, 'version' => $circuit->version];
        })(),
        default => throw new InvalidArgumentException('Unknown action: '.$action),
    };

    echo json_encode(['successful' => true, 'result' => $result], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $exception) {
    echo json_encode([
        'successful' => false,
        'error' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
    exit(0);
}
