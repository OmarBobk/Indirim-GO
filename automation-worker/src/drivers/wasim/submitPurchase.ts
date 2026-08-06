import type { Page } from 'playwright';
import type { DriverResult, RunPayload } from '../../types.js';
import type { RunLogger } from '../../logging/runLogger.js';
import type { ProgressReporter } from '../../progress/ProgressReporter.js';
import {
  isSupplierOrderPendingReconcile,
  isSupplierOrderRejected,
  isSupplierOrderSuccessful,
  isSupplierRateLimitedReply,
  parseSwalPurchaseContent,
} from './parseSwalPurchase.js';
import { defaultWasimUiAdapter } from './ui/registry.js';
import type { WasimUiAdapter } from './ui/types.js';

type ProductContext = {
  productApi: string | null;
  productUrl: string | null;
  playerId: string | null;
  unitPrice: number | null;
  lineTotal: number | null;
  customQuantity: number | null;
  supplierTotal: number | null;
  productAmountMode: string | null;
  adapter?: WasimUiAdapter;
  uiVersion?: string | null;
  purchaseContractVersion?: string | null;
};

export async function submitWasimPurchase(
  page: Page,
  payload: RunPayload,
  logger: RunLogger,
  screenshot: (label: string) => Promise<void>,
  context: ProductContext,
  progress?: ProgressReporter,
): Promise<DriverResult> {
  const adapter = context.adapter ?? defaultWasimUiAdapter();

  progress?.setContractMeta(
    context.uiVersion ?? adapter.uiVersion,
    context.purchaseContractVersion ?? adapter.purchaseContractVersion,
  );
  progress?.step('preparing_submission');
  progress?.step('page_contract_validating');

  const contract = await adapter.validatePreSubmit(page, context.productUrl ?? page.url());

  if (!contract.ok) {
    await screenshot('pre_submit_contract_failed');
    progress?.step('page_contract_failed', contract.failureCode);

    return {
      outcome: 'needs_review',
      errorCode: contract.failureCode ?? 'pre_submit_contract_failed',
      message: 'Wasim pre-submit page contract failed; purchase click blocked.',
      deliveredPayload: {
        ...baseDeliveredPayload(context, page.url(), 'pre_submit_contract'),
        contract_checks: contract.checks,
        ui_diagnostics: await adapter.collectSafeDiagnostics(page, contract.failureCode ?? 'pre_submit_contract_failed'),
      },
    };
  }

  progress?.step('page_contract_valid');

  const submitControl = await adapter.locateUniqueSubmitControl(page);

  if (!submitControl.ok) {
    await screenshot('purchase_button_missing');

    return {
      outcome: 'needs_review',
      errorCode: submitControl.failureCode,
      message: 'Wasim product page did not expose a unique purchase control.',
      deliveredPayload: baseDeliveredPayload(context, page.url(), 'product'),
    };
  }

  progress?.step('capturing_pre_submit_artifact');
  await screenshot('pre_submit');

  let submitted = false;

  try {
    if (submitted) {
      return {
        outcome: 'needs_review',
        errorCode: 'duplicate_submission_warning',
        message: 'Duplicate purchase submit prevented by worker guard.',
        deliveredPayload: baseDeliveredPayload(context, page.url(), 'purchase'),
      };
    }

    logger.log('submit_purchase', 'Clicking Wasim purchase button');
    progress?.step('submitting_purchase');

    submitted = true;
    await adapter.clickSubmitOnce(page);

    progress?.step('waiting_supplier_confirmation');

    const dialogVisible = await adapter.waitForSubmissionDialog(page, 30_000);

    if (!dialogVisible) {
      await screenshot('purchase_response_missing');

      return {
        outcome: 'needs_review',
        errorCode: 'uncertain_submission',
        message: 'Wasim did not show a purchase confirmation dialog after submit.',
        deliveredPayload: baseDeliveredPayload(context, page.url(), 'purchase'),
      };
    }

    await screenshot('purchase_response');

    const htmlContent = await adapter.readSubmissionHtml(page);
    const parsed = parseSwalPurchaseContent(htmlContent);

    logger.log(
      'purchase_parsed',
      `order=${parsed.supplierOrderId ?? 'n/a'} status=${parsed.supplierStatus ?? 'n/a'} price=${parsed.supplierEntryPrice ?? 'n/a'}`,
    );

    await adapter.dismissSubmissionDialog(page);

    const deliveredBase = {
      ...baseDeliveredPayload(context, page.url(), 'purchase'),
      supplier_order_id: parsed.supplierOrderId,
      supplier_product_id: parsed.supplierProductId,
      supplier_entry_price: parsed.supplierEntryPrice,
      supplier_status: parsed.supplierStatus,
      supplier_reply: parsed.supplierReply,
      adapter_id: adapter.adapterId,
      detected_ui_version: adapter.uiVersion,
      purchase_contract_version: adapter.purchaseContractVersion,
      driver_version: adapter.driverVersion,
    };

    if (isSupplierOrderSuccessful(parsed.supplierStatus)) {
      if (parsed.supplierOrderId === null) {
        return {
          outcome: 'needs_review',
          errorCode: 'unknown_supplier_response',
          message: 'Wasim reported success but no supplier order id was found.',
          deliveredPayload: deliveredBase,
        };
      }

      progress?.step('supplier_submission_accepted');
      progress?.step('supplier_order_id_captured', undefined, { supplier_order_id: parsed.supplierOrderId });

      return {
        outcome: 'submitted',
        externalOrderId: parsed.supplierOrderId,
        message: 'Wasim order submitted; awaiting supplier completion.',
        deliveredPayload: {
          ...deliveredBase,
          checkpoint: 'purchase_submitted',
          phase: 'purchase',
        },
      };
    }

    if (
      parsed.supplierReply !== null
      && isSupplierRateLimitedReply(parsed.supplierReply)
    ) {
      return {
        outcome: 'failed',
        errorCode: 'supplier_rate_limited',
        message: parsed.supplierReply,
        deliveredPayload: {
          ...deliveredBase,
          checkpoint: 'purchase_rate_limited',
        },
      };
    }

    if (isSupplierOrderPendingReconcile(parsed.supplierStatus, parsed.supplierOrderId)) {
      const replyNote = parsed.supplierReply ? ` (${parsed.supplierReply})` : '';

      progress?.step('supplier_submission_accepted');
      progress?.step('supplier_order_id_captured', undefined, { supplier_order_id: parsed.supplierOrderId });

      return {
        outcome: 'submitted',
        externalOrderId: parsed.supplierOrderId!,
        message: `Wasim order ${parsed.supplierOrderId} pending; reconcile on supplier orders page${replyNote}.`,
        deliveredPayload: {
          ...deliveredBase,
          checkpoint: 'purchase_submitted_pending',
          phase: 'purchase',
        },
      };
    }

    if (isSupplierOrderRejected(parsed.supplierStatus, parsed.supplierReply, parsed.supplierOrderId)) {
      return {
        outcome: 'failed',
        errorCode: 'supplier_order_rejected',
        message: parsed.supplierReply ?? 'Wasim order rejected (pending).',
        deliveredPayload: {
          ...deliveredBase,
          checkpoint: 'purchase_rejected',
        },
      };
    }

    return {
      outcome: 'needs_review',
      errorCode: 'unknown_supplier_response',
      message: `Unexpected Wasim order status: ${parsed.supplierStatus ?? 'unknown'}.`,
      deliveredPayload: deliveredBase,
    };
  } finally {
    void payload;
  }
}

function baseDeliveredPayload(
  context: ProductContext,
  url: string,
  checkpoint: string,
): Record<string, unknown> {
  return {
    checkpoint,
    url,
    product_api: context.productApi,
    product_url: context.productUrl,
    player_id: context.playerId,
    unit_price: context.unitPrice,
    line_total: context.lineTotal,
    custom_quantity: context.customQuantity,
    supplier_total: context.supplierTotal,
    product_amount_mode: context.productAmountMode ?? 'fixed',
    adapter_id: context.adapter?.adapterId ?? null,
    detected_ui_version: context.uiVersion ?? context.adapter?.uiVersion ?? null,
    purchase_contract_version: context.purchaseContractVersion
      ?? context.adapter?.purchaseContractVersion
      ?? null,
  };
}
