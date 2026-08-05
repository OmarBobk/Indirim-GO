<?php

declare(strict_types=1);

namespace App\Enums;

enum FulfillmentAutomationProgressStep: string
{
    case WorkerReceived = 'worker_received';
    case BrowserStarting = 'browser_starting';
    case BrowserReady = 'browser_ready';
    case SessionLoading = 'session_loading';
    case SessionChecking = 'session_checking';
    case LoginRequired = 'login_required';
    case AuthenticationStarted = 'authentication_started';
    case AuthenticationSucceeded = 'authentication_succeeded';
    case PreparingSupplierOperation = 'preparing_supplier_operation';
    case ArtifactCaptured = 'artifact_captured';
    case FinalizingResult = 'finalizing_result';
    case CallbackSending = 'callback_sending';

    case OpeningProduct = 'opening_product';
    case ProductLoaded = 'product_loaded';
    case ReadingSupplierPrice = 'reading_supplier_price';
    case SupplierPriceRead = 'supplier_price_read';
    case ValidatingSupplierPrice = 'validating_supplier_price';
    case SupplierPriceValidated = 'supplier_price_validated';
    case FillingRequirements = 'filling_requirements';
    case RequirementsFilled = 'requirements_filled';
    case PreparingSubmission = 'preparing_submission';
    case CapturingPreSubmitArtifact = 'capturing_pre_submit_artifact';
    case SubmittingPurchase = 'submitting_purchase';
    case WaitingSupplierConfirmation = 'waiting_supplier_confirmation';
    case SupplierSubmissionAccepted = 'supplier_submission_accepted';
    case SupplierOrderIdCaptured = 'supplier_order_id_captured';

    case OpeningOrdersPage = 'opening_orders_page';
    case OrdersPageLoaded = 'orders_page_loaded';
    case SearchingSupplierOrder = 'searching_supplier_order';
    case SupplierOrderFound = 'supplier_order_found';
    case ReadingSupplierStatus = 'reading_supplier_status';
    case SupplierOrderPending = 'supplier_order_pending';
    case SupplierOrderCompleted = 'supplier_order_completed';
    case SupplierOrderCancelled = 'supplier_order_cancelled';
    case SchedulingNextReconcile = 'scheduling_next_reconcile';

    case UiDetecting = 'ui_detecting';
    case UiRecognized = 'ui_recognized';
    case UiUnsupported = 'ui_unsupported';
    case PageContractValidating = 'page_contract_validating';
    case PageContractValid = 'page_contract_valid';
    case PageContractFailed = 'page_contract_failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function labelKey(): string
    {
        return 'messages.automation_step_'.$this->value;
    }

    /**
     * @return list<string>
     */
    public function compatiblePhases(): array
    {
        return match ($this) {
            self::OpeningProduct,
            self::ProductLoaded,
            self::ReadingSupplierPrice,
            self::SupplierPriceRead,
            self::ValidatingSupplierPrice,
            self::SupplierPriceValidated,
            self::FillingRequirements,
            self::RequirementsFilled,
            self::PreparingSubmission,
            self::CapturingPreSubmitArtifact,
            self::SubmittingPurchase,
            self::WaitingSupplierConfirmation,
            self::SupplierSubmissionAccepted,
            self::SupplierOrderIdCaptured => ['purchase'],

            self::OpeningOrdersPage,
            self::OrdersPageLoaded,
            self::SearchingSupplierOrder,
            self::SupplierOrderFound,
            self::ReadingSupplierStatus,
            self::SupplierOrderPending,
            self::SupplierOrderCompleted,
            self::SupplierOrderCancelled,
            self::SchedulingNextReconcile => ['reconcile'],

            // UI detection runs before we know which phase we're in.
            self::UiDetecting,
            self::UiRecognized,
            self::UiUnsupported,
            // Page contract checks apply to both the purchase and reconcile flows.
            self::PageContractValidating,
            self::PageContractValid,
            self::PageContractFailed => ['purchase', 'reconcile'],

            default => ['purchase', 'reconcile'],
        };
    }

    public function isCompatibleWithPhase(string $phase): bool
    {
        return in_array($phase, $this->compatiblePhases(), true);
    }
}
