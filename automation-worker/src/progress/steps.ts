/**
 * Must match the value list in App\Enums\FulfillmentAutomationProgressStep exactly.
 * Laravel rejects any step it does not recognize.
 */
export const ALLOWED_STEPS = [
  'worker_received',
  'browser_starting',
  'browser_ready',
  'session_loading',
  'session_checking',
  'login_required',
  'authentication_started',
  'authentication_succeeded',
  'preparing_supplier_operation',
  'artifact_captured',
  'finalizing_result',
  'callback_sending',

  'ui_detecting',
  'ui_recognized',
  'ui_unsupported',
  'page_contract_validating',
  'page_contract_valid',
  'page_contract_failed',

  'opening_product',
  'product_loaded',
  'reading_supplier_price',
  'supplier_price_read',
  'validating_supplier_price',
  'supplier_price_validated',
  'filling_requirements',
  'requirements_filled',
  'preparing_submission',
  'capturing_pre_submit_artifact',
  'submitting_purchase',
  'waiting_supplier_confirmation',
  'supplier_submission_accepted',
  'supplier_order_id_captured',

  'opening_orders_page',
  'orders_page_loaded',
  'searching_supplier_order',
  'supplier_order_found',
  'reading_supplier_status',
  'supplier_order_pending',
  'supplier_order_completed',
  'supplier_order_cancelled',
  'scheduling_next_reconcile',
] as const;

export type ProgressStep = (typeof ALLOWED_STEPS)[number];

/**
 * Reported to Laravel alongside each progress event so drivers can evolve
 * independently of the worker build number.
 */
export const DRIVER_VERSIONS: Record<string, string> = {
  wasim: 'wasim-1.1.0',
  acme: 'acme-1.0.0',
};
