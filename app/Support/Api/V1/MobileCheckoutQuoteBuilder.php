<?php

declare(strict_types=1);

namespace App\Support\Api\V1;

use App\Actions\Packages\ResolvePackageRequirements;
use App\Domain\Pricing\PricingEngine;
use App\Enums\ProductAmountMode;
use App\Exceptions\MobileApiException;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WebsiteSetting;
use App\Services\WalletSpendPolicy;
use App\Support\LedgerMoney;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Builds an informational mobile checkout quote and tamper-resistant fingerprint.
 *
 * Fingerprint format: base64url(json_payload).base64url(hmac_sha256)
 * Payload binds customer, line inputs, requirements hash (not values), total, version, expiry.
 */
final class MobileCheckoutQuoteBuilder
{
    public function __construct(
        private readonly PricingEngine $pricingEngine,
        private readonly ResolvePackageRequirements $resolvePackageRequirements,
        private readonly MobileRequirementSchemaBuilder $requirementSchemaBuilder,
        private readonly WalletSpendPolicy $spendPolicy = new WalletSpendPolicy,
    ) {}

    /**
     * @param  array{
     *     product_id: int,
     *     package_id: int|null,
     *     quantity: int|null,
     *     requested_amount: int|null,
     *     requirements: array<string, mixed>
     * }  $item
     * @return array<string, mixed>
     */
    public function build(User $user, array $item): array
    {
        if (! WebsiteSetting::getPricesVisible()) {
            throw new MobileApiException(
                'messages.mobile_api.purchasing_unavailable',
                'purchasing_unavailable',
                409,
            );
        }

        $product = Product::query()
            ->with('package.requirements')
            ->whereKey($item['product_id'])
            ->where('is_active', true)
            ->first();

        if ($product === null || $product->package === null || ! $product->package->is_active) {
            throw new MobileApiException(
                'messages.mobile_api.product_unavailable',
                'product_unavailable',
                422,
            );
        }

        $packageId = $item['package_id'];
        if ($packageId !== null && (int) $product->package_id !== (int) $packageId) {
            throw ValidationException::withMessages([
                'items.0.package_id' => [__('messages.mobile_api.package_product_mismatch')],
            ]);
        }

        $requirements = is_array($item['requirements'] ?? null) ? $item['requirements'] : [];
        $this->validateRequirements($product, $requirements);

        $amountMode = $product->amount_mode ?? ProductAmountMode::Fixed;
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $requestedAmount = $item['requested_amount'] ?? null;

        try {
            if ($amountMode === ProductAmountMode::Custom) {
                $quantity = 1;
                $quote = $this->pricingEngine->quote(
                    $product,
                    1,
                    $requestedAmount !== null ? (int) $requestedAmount : null,
                    $user
                );
                $requestedAmount = (int) $quote->requestedAmount;
            } else {
                if (array_key_exists('quantity', $item) && $item['quantity'] !== null && (int) $item['quantity'] <= 0) {
                    throw ValidationException::withMessages([
                        'items.0.quantity' => [__('messages.mobile_api.invalid_quantity')],
                    ]);
                }
                $quote = $this->pricingEngine->quote($product, $quantity, null, $user);
                $requestedAmount = null;
            }
        } catch (ValidationException $exception) {
            if ($amountMode === ProductAmountMode::Custom) {
                throw new MobileApiException(
                    'messages.mobile_api.invalid_custom_amount',
                    'invalid_custom_amount',
                    422,
                    ['errors' => $exception->errors()],
                );
            }

            throw $exception;
        }

        // Prefer PricingEngine ledger decimals — never sprintf('%.2F', float).
        $lineTotal = LedgerMoney::normalize($quote->finalTotalDecimal);
        $unitPrice = LedgerMoney::normalize($quote->unitPriceDecimal);
        $fee = LedgerMoney::normalize((string) config('billing.checkout_fee_fixed', '0'));
        $subtotal = $lineTotal;
        $total = LedgerMoney::add($subtotal, $fee);

        $moneyFactory = MobileMoneyFactory::forUser($user);
        $wallet = Wallet::forUser($user);
        $available = LedgerMoney::normalize($wallet->availableToSpend());
        $canAfford = $this->spendPolicy->evaluate($wallet, $total)->allowed;

        $ttl = max(30, (int) config('mobile_api.checkout.quote_ttl_seconds', 300));
        $expiresAt = now()->addSeconds($ttl);
        $version = (int) config('mobile_api.checkout.quote_version', 1);

        $normalizedItem = [
            'product_id' => (int) $product->id,
            'package_id' => (int) $product->package_id,
            'quantity' => $quantity,
            'requested_amount' => $requestedAmount,
            'requirements' => $this->canonicalizeRequirements($requirements),
        ];

        $fingerprint = $this->issueFingerprint(
            userId: (int) $user->id,
            item: $normalizedItem,
            total: $total,
            expiresAt: $expiresAt,
            version: $version,
        );

        return [
            'quote_fingerprint' => $fingerprint,
            'expires_at' => $expiresAt->toIso8601String(),
            'item' => [
                'product_id' => (int) $product->id,
                'package_id' => (int) $product->package_id,
                'name' => (string) $product->name,
                'amount_mode' => $amountMode->value,
                'quantity' => $quantity,
                'requested_amount' => $requestedAmount,
                'unit_price' => $moneyFactory->fromUsdDecimal($unitPrice),
                'line_total' => $moneyFactory->fromUsdDecimal($lineTotal),
                'requirements_schema' => $this->requirementSchemaBuilder->forRequirements(
                    $product->package->requirements
                ),
            ],
            'subtotal' => $moneyFactory->fromUsdDecimal($subtotal),
            'fee' => $moneyFactory->fromUsdDecimal($fee),
            'total' => $moneyFactory->fromUsdDecimal($total),
            'wallet' => [
                'available_to_spend' => $moneyFactory->fromUsdDecimal($available),
                'can_afford' => $canAfford,
            ],
            'meta' => [
                'prices_visible' => true,
            ],
            '_authoritative_total' => $total,
            '_normalized_item' => $normalizedItem,
        ];
    }

    /**
     * @param  array<string, mixed>  $builtQuote
     * @return array<string, mixed>
     */
    public function publicQuote(array $builtQuote): array
    {
        return [
            'quote_fingerprint' => $builtQuote['quote_fingerprint'],
            'expires_at' => $builtQuote['expires_at'],
            'item' => $builtQuote['item'],
            'subtotal' => $builtQuote['subtotal'],
            'fee' => $builtQuote['fee'],
            'total' => $builtQuote['total'],
            'wallet' => $builtQuote['wallet'],
            'meta' => $builtQuote['meta'],
        ];
    }

    /**
     * Ensure the client fingerprint still matches the freshly calculated authoritative quote.
     *
     * @param  array<string, mixed>  $freshQuote  result of build()
     */
    public function assertFingerprintMatches(string $clientFingerprint, User $user, array $freshQuote): void
    {
        $payload = $this->decodeAndVerifyFingerprint($clientFingerprint);

        if ($payload === null) {
            throw $this->priceChanged($freshQuote);
        }

        if ((int) ($payload['user_id'] ?? 0) !== (int) $user->id) {
            throw $this->priceChanged($freshQuote);
        }

        $expiresAt = \Illuminate\Support\Carbon::createFromTimestamp((int) ($payload['exp'] ?? 0));
        if (now()->greaterThan($expiresAt)) {
            throw $this->priceChanged($freshQuote);
        }

        $normalized = $freshQuote['_normalized_item'];
        $expectedRequirementsHash = $this->requirementsHash($normalized['requirements'] ?? []);

        $matches = (int) ($payload['product_id'] ?? 0) === (int) $normalized['product_id']
            && (int) ($payload['package_id'] ?? 0) === (int) $normalized['package_id']
            && (int) ($payload['quantity'] ?? 0) === (int) $normalized['quantity']
            && ($payload['requested_amount'] ?? null) === ($normalized['requested_amount'] ?? null)
            && hash_equals((string) ($payload['requirements_hash'] ?? ''), $expectedRequirementsHash)
            && hash_equals((string) ($payload['total'] ?? ''), (string) $freshQuote['_authoritative_total'])
            && (int) ($payload['v'] ?? 0) === (int) config('mobile_api.checkout.quote_version', 1);

        if (! $matches) {
            throw $this->priceChanged($freshQuote);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function issueFingerprint(
        int $userId,
        array $item,
        string $total,
        CarbonInterface $expiresAt,
        int $version,
    ): string {
        $payload = [
            'v' => $version,
            'user_id' => $userId,
            'product_id' => (int) $item['product_id'],
            'package_id' => (int) $item['package_id'],
            'quantity' => (int) $item['quantity'],
            'requested_amount' => $item['requested_amount'],
            'requirements_hash' => $this->requirementsHash($item['requirements'] ?? []),
            'total' => $total,
            'exp' => $expiresAt->getTimestamp(),
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $body = $this->base64UrlEncode($json);
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $body, $this->signingKey(), true));

        return $body.'.'.$signature;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decodeAndVerifyFingerprint(string $fingerprint): ?array
    {
        $parts = explode('.', $fingerprint);
        if (count($parts) !== 2) {
            return null;
        }

        [$body, $signature] = $parts;
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $body, $this->signingKey(), true));

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $json = $this->base64UrlDecode($body);
        if ($json === null) {
            return null;
        }

        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<string, mixed>  $builtQuote
     */
    private function priceChanged(array $builtQuote): MobileApiException
    {
        return new MobileApiException(
            'messages.mobile_api.price_changed',
            'price_changed',
            409,
            ['current_quote' => $this->publicQuote($builtQuote)],
        );
    }

    /**
     * @param  array<string, mixed>  $requirements
     * @return array<string, mixed>
     */
    private function canonicalizeRequirements(array $requirements): array
    {
        ksort($requirements);

        return $requirements;
    }

    /**
     * @param  array<string, mixed>  $requirements
     */
    private function requirementsHash(array $requirements): string
    {
        ksort($requirements);

        return hash('sha256', json_encode($requirements, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $requirements
     */
    private function validateRequirements(Product $product, array $requirements): void
    {
        $packageRequirements = $product->package?->requirements ?? collect();
        if ($packageRequirements->isEmpty()) {
            return;
        }

        $resolved = $this->resolvePackageRequirements->handle($packageRequirements);
        $validator = Validator::make($requirements, $resolved['rules'], [], $resolved['attributes']);

        if ($validator->fails()) {
            $messages = [];
            foreach ($validator->errors()->messages() as $field => $fieldMessages) {
                $messages["items.0.requirements.$field"] = $fieldMessages;
            }

            throw ValidationException::withMessages($messages !== [] ? $messages : [
                'items.0.requirements' => [__('messages.mobile_api.invalid_requirements')],
            ]);
        }
    }

    private function signingKey(): string
    {
        return (string) config('app.key');
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
