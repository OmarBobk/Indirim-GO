<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FulfillmentAutomationRunStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\WalletTransactionType;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use App\Models\Order;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\URL;

class FulfillmentAutomationService
{
    public function isEnabled(): bool
    {
        return (bool) config('fulfillment_automation.enabled', false)
            && config('fulfillment_automation.callback_secret') !== ''
            && config('fulfillment_automation.worker_url') !== '';
    }

    public function isEligible(Fulfillment $fulfillment): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        if (! $fulfillment->isBrowserAutomated()) {
            return false;
        }

        if ($fulfillment->status !== FulfillmentStatus::Queued) {
            return false;
        }

        if ($fulfillment->claimed_by !== null) {
            return false;
        }

        $supplierKey = $fulfillment->browserSupplierKey();

        if ($supplierKey === null || ! $this->supplierConfig($supplierKey)) {
            return false;
        }

        if ($this->hasActiveRun($fulfillment)) {
            return false;
        }

        if ($this->hasSucceededRun($fulfillment)) {
            return false;
        }

        if ($this->hasBlockingRefund($fulfillment)) {
            return false;
        }

        $order = $fulfillment->order ?? Order::query()->find($fulfillment->order_id);

        if ($order === null || $order->status !== OrderStatus::Paid) {
            return false;
        }

        return true;
    }

    public function hasActiveRun(Fulfillment $fulfillment): bool
    {
        return FulfillmentAutomationRun::query()
            ->where('fulfillment_id', $fulfillment->id)
            ->active()
            ->exists();
    }

    public function hasSucceededRun(Fulfillment $fulfillment): bool
    {
        return FulfillmentAutomationRun::query()
            ->where('fulfillment_id', $fulfillment->id)
            ->where('status', FulfillmentAutomationRunStatus::Succeeded)
            ->exists();
    }

    public function hasBlockingRefund(Fulfillment $fulfillment): bool
    {
        return WalletTransaction::query()
            ->where('type', WalletTransactionType::Refund->value)
            ->whereIn('status', [WalletTransaction::STATUS_PENDING, WalletTransaction::STATUS_POSTED])
            ->where(function ($query) use ($fulfillment): void {
                $query->where(function ($subQuery) use ($fulfillment): void {
                    $subQuery->where('reference_type', Fulfillment::class)
                        ->where('reference_id', $fulfillment->id);
                })->orWhere(function ($subQuery) use ($fulfillment): void {
                    $subQuery->where('reference_type', \App\Models\OrderItem::class)
                        ->where('reference_id', $fulfillment->order_item_id);
                });
            })
            ->exists();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function supplierConfig(string $supplierKey): ?array
    {
        $config = config('fulfillment_automation.suppliers.'.$supplierKey);

        return is_array($config) ? $config : null;
    }

    public function nextAttemptNumber(Fulfillment $fulfillment): int
    {
        $latest = FulfillmentAutomationRun::query()
            ->where('fulfillment_id', $fulfillment->id)
            ->max('attempt');

        return max(1, (int) $latest + 1);
    }

    public function buildIdempotencyKey(int $fulfillmentId, int $attempt): string
    {
        return 'automation:fulfillment:'.$fulfillmentId.':attempt:'.$attempt;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildWorkerPayload(FulfillmentAutomationRun $run, Fulfillment $fulfillment): array
    {
        $fulfillment->loadMissing(['orderItem.product', 'orderItem.package']);

        $orderItem = $fulfillment->orderItem;
        $supplierKey = $run->supplier_key;
        $supplier = $this->supplierConfig($supplierKey) ?? [];

        $requirements = data_get($fulfillment->meta, 'requirements_payload')
            ?? $orderItem?->requirements_payload
            ?? [];

        $customAmount = null;
        if (data_get($fulfillment->meta, 'type') === 'custom_amount') {
            $customAmount = [
                'amount' => data_get($fulfillment->meta, 'amount'),
                'unit' => data_get($fulfillment->meta, 'unit'),
            ];
        }

        return [
            'run_uuid' => $run->uuid,
            'fulfillment_id' => $fulfillment->id,
            'supplier_key' => $supplierKey,
            'driver' => $supplier['driver'] ?? $supplierKey,
            'session_key' => $supplier['session_key'] ?? $supplierKey.'-main',
            'idempotency_reference' => 'fulfillment:'.$fulfillment->id,
            'requirements' => $requirements,
            'custom_amount' => $customAmount,
            'product_slug' => $orderItem?->product?->slug,
            'package_slug' => $orderItem?->package?->slug,
            'package_api' => $orderItem?->package?->package_api,
            'product_api' => $orderItem?->product?->product_api,
            'credentials' => $supplier['credentials'] ?? [],
            'callback_urls' => [
                'result' => URL::to('/internal/automation/runs/'.$run->uuid.'/result'),
                'artifacts' => URL::to('/internal/automation/runs/'.$run->uuid.'/artifacts'),
            ],
            'expires_at' => now()->addSeconds((int) config('fulfillment_automation.timeouts.run_seconds', 300))->toIso8601String(),
        ];
    }

    public function signPayload(string $rawBody, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $secret = (string) config('fulfillment_automation.callback_secret');
        $signature = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        return 'sha256='.$signature;
    }

    public function verifySignature(string $rawBody, string $signatureHeader, string $timestampHeader): bool
    {
        $secret = (string) config('fulfillment_automation.callback_secret');

        if ($secret === '') {
            return false;
        }

        if (! ctype_digit($timestampHeader)) {
            return false;
        }

        $timestamp = (int) $timestampHeader;
        $skew = (int) config('fulfillment_automation.timeouts.signature_skew_seconds', 300);

        if (abs(time() - $timestamp) > $skew) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        $provided = str_starts_with($signatureHeader, 'sha256=')
            ? substr($signatureHeader, 7)
            : $signatureHeader;

        return hash_equals($expected, $provided);
    }

    public function signArtifactPayload(
        string $runUuid,
        string $label,
        string $fileHash,
        ?int $timestamp = null,
    ): string {
        $timestamp ??= time();
        $secret = (string) config('fulfillment_automation.callback_secret');
        $payload = $timestamp.'.'.$runUuid.'.'.$label.'.'.$fileHash;

        return 'sha256='.hash_hmac('sha256', $payload, $secret);
    }

    public function verifyArtifactSignature(
        string $runUuid,
        string $label,
        string $fileHash,
        string $signatureHeader,
        string $timestampHeader,
    ): bool {
        $secret = (string) config('fulfillment_automation.callback_secret');

        if ($secret === '' || $runUuid === '' || $label === '' || $fileHash === '') {
            return false;
        }

        if (! ctype_digit($timestampHeader)) {
            return false;
        }

        $timestamp = (int) $timestampHeader;
        $skew = (int) config('fulfillment_automation.timeouts.signature_skew_seconds', 300);

        if (abs(time() - $timestamp) > $skew) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$runUuid.'.'.$label.'.'.$fileHash, $secret);
        $provided = str_starts_with($signatureHeader, 'sha256=')
            ? substr($signatureHeader, 7)
            : $signatureHeader;

        return hash_equals($expected, $provided);
    }

    public function artifactStorageDirectory(string $runUuid): string
    {
        return 'fulfillment-automation/'.$runUuid;
    }

    /**
     * @param  array<string, mixed>|null  $resultPayload
     * @return array<string, mixed>|null
     */
    public function enrichResultPayload(Fulfillment $fulfillment, ?array $resultPayload): ?array
    {
        $payload = is_array($resultPayload) ? $resultPayload : [];

        $fulfillment->loadMissing('orderItem.product');

        $productApi = $payload['product_api'] ?? $fulfillment->orderItem?->product?->product_api;

        if (! is_string($productApi) || trim($productApi) === '') {
            return $payload === [] ? null : $payload;
        }

        $productApi = trim($productApi);
        $payload['product_api'] = $productApi;
        $payload['product_url'] ??= $this->buildWasimProductUrl($productApi);

        return $payload;
    }

    public function buildWasimProductUrl(string $productApi): string
    {
        $trimmed = trim($productApi);

        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            return $trimmed;
        }

        return 'https://wasim-store.com/'.ltrim($trimmed, '/');
    }
}
