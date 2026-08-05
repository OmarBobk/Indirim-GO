<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\AutomationCircuitCapability;

/**
 * Server-owned Wasim circuit policy (C1.3).
 *
 * Worker FAILURE_CIRCUIT_HINTS are diagnostic only — this registry is enforceable truth.
 *
 * @phpstan-type CircuitPolicyRule array{
 *     capability: AutomationCircuitCapability,
 *     scope: 'order'|'product'|'purchase_ui'|'reconcile_ui'|'authentication'|'worker'|'price_scan',
 *     severity: 'info'|'warn'|'critical',
 *     immediate: bool,
 *     threshold: bool,
 *     no_circuit: bool,
 *     probe_required: bool,
 *     manual_resume: bool,
 *     auto_recover: bool,
 *     message_key: string,
 * }
 */
final class AutomationCircuitPolicy
{
    /**
     * @return CircuitPolicyRule|null
     */
    public function ruleFor(string $failureCode): ?array
    {
        $rules = self::rules();

        return $rules[$failureCode] ?? null;
    }

    public function isAllowlisted(string $failureCode): bool
    {
        return array_key_exists($failureCode, self::rules());
    }

    /**
     * @return array<string, CircuitPolicyRule>
     */
    public static function rules(): array
    {
        return [
            'unsupported_ui' => self::immediate(AutomationCircuitCapability::Purchase, 'purchase_ui', true, true),
            'ambiguous_ui' => self::immediate(AutomationCircuitCapability::Purchase, 'purchase_ui', true, true),
            'submit_control_ambiguous' => self::immediate(AutomationCircuitCapability::Purchase, 'purchase_ui', true, true),
            'submit_control_missing' => self::immediate(AutomationCircuitCapability::Purchase, 'purchase_ui', true, true),
            'pre_submit_contract_failed' => self::immediate(AutomationCircuitCapability::Purchase, 'purchase_ui', true, true),
            'required_field_ambiguous' => self::immediate(AutomationCircuitCapability::Purchase, 'purchase_ui', true, true),
            'unsupported_required_field' => self::immediate(AutomationCircuitCapability::Purchase, 'purchase_ui', true, true),
            'requirements_contract_failed' => self::immediate(AutomationCircuitCapability::Purchase, 'purchase_ui', true, true),
            'authenticated_contract_failed' => self::immediate(AutomationCircuitCapability::Purchase, 'authentication', true, true),
            'access_denied' => self::immediate(AutomationCircuitCapability::Purchase, 'authentication', true, true),
            'product_identity_mismatch' => self::immediate(AutomationCircuitCapability::Purchase, 'product', true, true),
            'product_identity_ambiguous' => self::immediate(AutomationCircuitCapability::Purchase, 'product', true, true),

            'orders_ui_unsupported' => self::immediate(AutomationCircuitCapability::Reconcile, 'reconcile_ui', true, true),
            'orders_contract_failed' => self::immediate(AutomationCircuitCapability::Reconcile, 'reconcile_ui', true, true),

            'maintenance' => self::threshold(AutomationCircuitCapability::Purchase, 'purchase_ui', true, false),
            'authentication_required' => self::threshold(AutomationCircuitCapability::Purchase, 'authentication', true, false),
            'authentication_failed' => self::threshold(AutomationCircuitCapability::Purchase, 'authentication', true, true),
            'product_not_found' => self::threshold(AutomationCircuitCapability::Purchase, 'product', false, false),
            'supplier_price_missing' => self::threshold(AutomationCircuitCapability::Purchase, 'product', true, false),
            'supplier_price_parse_failed' => self::threshold(AutomationCircuitCapability::Purchase, 'purchase_ui', true, false),
            'supplier_price_conflict' => self::threshold(AutomationCircuitCapability::Purchase, 'product', true, true),

            // Price-scan specific codes (also reused when purchase emits price parse failures into price_scan via probe).
            'price_scan_ui_unsupported' => self::immediate(AutomationCircuitCapability::PriceScan, 'price_scan', true, true),
            'price_scan_parse_failed' => self::threshold(AutomationCircuitCapability::PriceScan, 'price_scan', true, false),

            // Order-specific — observe only, never open supplier circuit.
            'required_field_missing' => self::none('order'),
            'supplier_price_outside_tolerance' => self::none('order'),
            'uncertain_submission' => self::none('order'),
            'unknown_supplier_response' => self::none('order'),
            'duplicate_submission_warning' => self::none('order'),
            'supplier_order_duplicate_match' => self::threshold(AutomationCircuitCapability::Reconcile, 'order', true, true),
            'supplier_order_unknown_status' => self::threshold(AutomationCircuitCapability::Reconcile, 'order', true, true),
            'probe_not_configured' => self::none('worker'),
            'probe_unreachable' => self::none('worker'),
            'margin_insufficient' => self::none('order'),
            'supplier_order_rejected' => self::none('order'),
            'supplier_rate_limited' => self::none('order'),
            'supplier_order_cancelled' => self::none('order'),
            'credentials_missing' => self::threshold(AutomationCircuitCapability::Purchase, 'authentication', true, true),
            'login_failed' => self::threshold(AutomationCircuitCapability::Purchase, 'authentication', true, true),
        ];
    }

    /**
     * @return CircuitPolicyRule
     */
    private static function immediate(
        AutomationCircuitCapability $capability,
        string $scope,
        bool $probeRequired,
        bool $manualResume,
    ): array {
        return [
            'capability' => $capability,
            'scope' => $scope,
            'severity' => 'critical',
            'immediate' => true,
            'threshold' => false,
            'no_circuit' => false,
            'probe_required' => $probeRequired,
            'manual_resume' => $manualResume,
            'auto_recover' => false,
            'message_key' => 'messages.automation_circuit_reason_'.$capability->value,
        ];
    }

    /**
     * @return CircuitPolicyRule
     */
    private static function threshold(
        AutomationCircuitCapability $capability,
        string $scope,
        bool $probeRequired,
        bool $manualResume,
    ): array {
        return [
            'capability' => $capability,
            'scope' => $scope,
            'severity' => 'warn',
            'immediate' => false,
            'threshold' => true,
            'no_circuit' => false,
            'probe_required' => $probeRequired,
            'manual_resume' => $manualResume,
            'auto_recover' => false,
            'message_key' => 'messages.automation_circuit_reason_threshold',
        ];
    }

    /**
     * @return CircuitPolicyRule
     */
    private static function none(string $scope): array
    {
        return [
            'capability' => AutomationCircuitCapability::Purchase,
            'scope' => $scope,
            'severity' => 'info',
            'immediate' => false,
            'threshold' => false,
            'no_circuit' => true,
            'probe_required' => false,
            'manual_resume' => false,
            'auto_recover' => false,
            'message_key' => 'messages.automation_circuit_reason_order_specific',
        ];
    }

    public function purchaseThresholdCount(): int
    {
        return max(1, (int) config('fulfillment_automation.circuits.purchase.threshold_count', 3));
    }

    public function purchaseThresholdWindowMinutes(): int
    {
        return max(1, (int) config('fulfillment_automation.circuits.purchase.threshold_window_minutes', 10));
    }

    public function reconcileThresholdCount(): int
    {
        return max(1, (int) config('fulfillment_automation.circuits.reconcile.threshold_count', 3));
    }

    public function reconcileThresholdWindowMinutes(): int
    {
        return max(1, (int) config('fulfillment_automation.circuits.reconcile.threshold_window_minutes', 15));
    }

    public function priceScanThresholdCount(): int
    {
        return max(1, (int) config('fulfillment_automation.circuits.price_scan.threshold_count', 3));
    }

    public function priceScanThresholdWindowMinutes(): int
    {
        return max(1, (int) config('fulfillment_automation.circuits.price_scan.threshold_window_minutes', 15));
    }

    public function probeFreshnessSeconds(): int
    {
        return max(60, (int) config('fulfillment_automation.circuits.probe_freshness_seconds', 1800));
    }

    public function supportedUiVersions(): array
    {
        /** @var list<string> $versions */
        $versions = config('fulfillment_automation.circuits.supported_ui_versions', ['wasim-ui-v1']);

        return $versions;
    }

    public function thresholdFor(AutomationCircuitCapability $capability): array
    {
        return match ($capability) {
            AutomationCircuitCapability::Purchase => [
                'count' => $this->purchaseThresholdCount(),
                'window_minutes' => $this->purchaseThresholdWindowMinutes(),
            ],
            AutomationCircuitCapability::Reconcile => [
                'count' => $this->reconcileThresholdCount(),
                'window_minutes' => $this->reconcileThresholdWindowMinutes(),
            ],
            AutomationCircuitCapability::PriceScan => [
                'count' => $this->priceScanThresholdCount(),
                'window_minutes' => $this->priceScanThresholdWindowMinutes(),
            ],
        };
    }
}
