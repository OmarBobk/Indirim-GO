import type { Page } from 'playwright';
import type { DriverResult, RunPayload } from '../../types.js';
import type { RunLogger } from '../../logging/runLogger.js';
import type { ProgressReporter } from '../../progress/ProgressReporter.js';
import { openWasimOrdersPage } from './ensureOrdersPage.js';
import {
  ensureWasimOrdersViewport,
  reloadWasimOrdersTable,
  setWasimStartDate,
  startDateThreeYearsAgo,
} from './ordersPageHelpers.js';

type OrderTab = 'cancelled' | 'completed' | 'new';

type FoundOrder = {
  tab: OrderTab;
  rowIndex: number;
  statusLabel: string | null;
  processingTime: string | null;
  description: string | null;
};

const TAB_BUTTONS: Record<OrderTab, string> = {
  cancelled: '#btn-Cancelled',
  completed: '#btn-Completed',
  new: '#btn-new',
};

async function clickOrdersTab(page: Page, tab: OrderTab): Promise<void> {
  const tabButton = page.locator(TAB_BUTTONS[tab]).first();

  await tabButton.scrollIntoViewIfNeeded().catch(() => undefined);
  await tabButton.click();
}

async function loadOrdersTab(page: Page, tab: OrderTab, logger: RunLogger): Promise<void> {
  await clickOrdersTab(page, tab);
  await setWasimStartDate(page, startDateThreeYearsAgo());
  await reloadWasimOrdersTable(page);

  logger.log('reconcile_tab', `Loaded Wasim orders tab=${tab}`);
}

async function searchOrderInTable(page: Page, supplierOrderId: string): Promise<number[] | 'duplicate'> {
  const search = page.locator('input[aria-controls="responsiveDataTable2"]').first();

  await search.scrollIntoViewIfNeeded().catch(() => undefined);
  await search.fill('');
  await search.fill(supplierOrderId);

  await page.locator('#responsiveDataTable2_processing').waitFor({ state: 'hidden', timeout: 15_000 }).catch(() => undefined);

  const rows = page.locator('#responsiveDataTable2 tbody tr');

  const count = await rows.count();
  const matches: number[] = [];

  for (let index = 0; index < count; index += 1) {
    const row = rows.nth(index);
    const text = (await row.innerText().catch(() => '')).replace(/\s+/g, ' ');

    if (text.includes(supplierOrderId)) {
      matches.push(index);
    }
  }

  if (matches.length > 1) {
    return 'duplicate';
  }

  return matches;
}

async function readExpandedDetails(page: Page, rowIndex: number): Promise<{
  statusLabel: string | null;
  processingTime: string | null;
  description: string | null;
}> {
  const row = page.locator('#responsiveDataTable2 tbody tr').nth(rowIndex);
  const expandControl = row.locator('td.dtr-control').first();

  if (await expandControl.isVisible().catch(() => false)) {
    await expandControl.click().catch(() => undefined);
  }

  const details = row.locator('+ tr td.child ul.dtr-details, + tr .dtr-details').first();
  const detailsText = await details.innerText().catch(() => '');

  const readField = (label: string): string | null => {
    const pattern = new RegExp(`${label}\\s*\\n?\\s*([^\\n]+)`, 'u');
    const match = detailsText.match(pattern);

    return match?.[1]?.trim() ?? null;
  };

  const statusFromRow = await row.locator('span.badge[title]').first().getAttribute('title').catch(() => null);

  return {
    statusLabel: statusFromRow ?? readField('الحالة'),
    processingTime: readField('وقت المعالجة'),
    description: readField('الوصف'),
  };
}

async function findOrderOnTab(
  page: Page,
  tab: OrderTab,
  supplierOrderId: string,
  logger: RunLogger,
): Promise<FoundOrder | null | 'duplicate'> {
  await loadOrdersTab(page, tab, logger);

  const matches = await searchOrderInTable(page, supplierOrderId);

  if (matches === 'duplicate') {
    return 'duplicate';
  }

  if (matches.length === 0) {
    return null;
  }

  const rowIndex = matches[0]!;
  const details = await readExpandedDetails(page, rowIndex);

  logger.log(
    'reconcile_found',
    `order=${supplierOrderId} tab=${tab} status=${details.statusLabel ?? 'n/a'}`,
  );

  return {
    tab,
    rowIndex,
    ...details,
  };
}

export async function reconcileWasimOrder(
  page: Page,
  payload: RunPayload,
  logger: RunLogger,
  screenshot: (label: string) => Promise<void>,
  progress?: ProgressReporter,
): Promise<DriverResult> {
  const supplierOrderId = payload.supplier_order_id?.trim()
    ?? payload.external_order_id?.trim()
    ?? null;

  if (supplierOrderId === null || supplierOrderId === '') {
    return {
      outcome: 'failed',
      errorCode: 'reconcile_order_id_missing',
      message: 'Reconcile phase requires supplier_order_id in the worker payload.',
      deliveredPayload: { checkpoint: 'reconcile', phase: 'reconcile' },
    };
  }

  await ensureWasimOrdersViewport(page);

  progress?.step('opening_orders_page');

  const ordersPage = await openWasimOrdersPage(page, payload, logger, screenshot, progress);

  if (!ordersPage.ok) {
    return {
      outcome: ordersPage.outcome ?? 'failed',
      errorCode: ordersPage.errorCode,
      message: ordersPage.message,
      deliveredPayload: {
        checkpoint: 'reconcile',
        phase: 'reconcile',
        ...(ordersPage.diagnostics ? { ui_diagnostics: ordersPage.diagnostics } : {}),
      },
    };
  }

  progress?.setContractMeta(ordersPage.uiVersion, ordersPage.ordersContractVersion);
  progress?.step('orders_page_loaded');

  try {
    progress?.step('searching_supplier_order');

    const cancelled = await findOrderOnTab(page, 'cancelled', supplierOrderId, logger);

    if (cancelled === 'duplicate') {
      await screenshot('reconcile_duplicate');

      return {
        outcome: 'needs_review',
        errorCode: 'supplier_order_duplicate_match',
        message: 'Multiple Wasim orders matched the supplier order id on cancelled tab.',
        deliveredPayload: {
          checkpoint: 'reconcile_duplicate',
          phase: 'reconcile',
          supplier_order_id: supplierOrderId,
          reconcile_tab: 'cancelled',
          adapter_id: ordersPage.adapter.adapterId,
          detected_ui_version: ordersPage.uiVersion,
          orders_contract_version: ordersPage.ordersContractVersion,
        },
      };
    }

    if (cancelled !== null) {
      await screenshot('reconcile_cancelled');
      progress?.step('supplier_order_found');
      progress?.step('reading_supplier_status');
      progress?.step('supplier_order_cancelled');

      return {
        outcome: 'failed',
        errorCode: 'supplier_order_cancelled',
        message: cancelled.description ?? 'Wasim order was cancelled.',
        deliveredPayload: {
          checkpoint: 'reconcile_cancelled',
          phase: 'reconcile',
          supplier_order_id: supplierOrderId,
          supplier_status: 'Cancelled',
          supplier_processing_time: cancelled.processingTime,
          supplier_description: cancelled.description,
          reconcile_tab: cancelled.tab,
        },
      };
    }

    const completed = await findOrderOnTab(page, 'completed', supplierOrderId, logger);

    if (completed === 'duplicate') {
      await screenshot('reconcile_duplicate');

      return {
        outcome: 'needs_review',
        errorCode: 'supplier_order_duplicate_match',
        message: 'Multiple Wasim orders matched the supplier order id on completed tab.',
        deliveredPayload: {
          checkpoint: 'reconcile_duplicate',
          phase: 'reconcile',
          supplier_order_id: supplierOrderId,
          reconcile_tab: 'completed',
          adapter_id: ordersPage.adapter.adapterId,
          detected_ui_version: ordersPage.uiVersion,
          orders_contract_version: ordersPage.ordersContractVersion,
        },
      };
    }

    if (completed !== null) {
      await screenshot('reconcile_completed');
      progress?.step('supplier_order_found');
      progress?.step('reading_supplier_status');
      progress?.step('supplier_order_completed');

      return {
        outcome: 'success',
        externalOrderId: supplierOrderId,
        message: 'Wasim order completed on supplier orders page.',
        deliveredPayload: {
          checkpoint: 'reconcile_completed',
          phase: 'reconcile',
          supplier_order_id: supplierOrderId,
          supplier_status: 'Completed',
          supplier_processing_time: completed.processingTime,
          supplier_description: completed.description,
          reconcile_tab: completed.tab,
        },
      };
    }

    const inProgress = await findOrderOnTab(page, 'new', supplierOrderId, logger);

    if (inProgress === 'duplicate') {
      await screenshot('reconcile_duplicate');

      return {
        outcome: 'needs_review',
        errorCode: 'supplier_order_duplicate_match',
        message: 'Multiple Wasim orders matched the supplier order id on new tab.',
        deliveredPayload: {
          checkpoint: 'reconcile_duplicate',
          phase: 'reconcile',
          supplier_order_id: supplierOrderId,
          reconcile_tab: 'new',
          adapter_id: ordersPage.adapter.adapterId,
          detected_ui_version: ordersPage.uiVersion,
          orders_contract_version: ordersPage.ordersContractVersion,
        },
      };
    }

    if (inProgress !== null) {
      await screenshot('reconcile_in_progress');
      progress?.step('supplier_order_found');
      progress?.step('reading_supplier_status');
      progress?.step('supplier_order_pending');
      progress?.step('scheduling_next_reconcile');

      return {
        outcome: 'pending_reconcile',
        externalOrderId: supplierOrderId,
        message: 'Wasim order is still in progress (new orders tab).',
        deliveredPayload: {
          checkpoint: 'reconcile_in_progress',
          phase: 'reconcile',
          supplier_order_id: supplierOrderId,
          supplier_status: inProgress.statusLabel ?? 'new',
          supplier_processing_time: inProgress.processingTime,
          supplier_description: inProgress.description,
          reconcile_tab: inProgress.tab,
        },
      };
    }

    await screenshot('reconcile_not_found');
    progress?.step('scheduling_next_reconcile');

    return {
      outcome: 'pending_reconcile',
      externalOrderId: supplierOrderId,
      message: 'Wasim order not found in cancelled, completed, or new tabs yet.',
      deliveredPayload: {
        checkpoint: 'reconcile_not_found',
        phase: 'reconcile',
        supplier_order_id: supplierOrderId,
      },
    };
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Wasim orders reconcile failed';

    logger.log('reconcile_error', message, 'error');
    await screenshot('reconcile_tab_error');
    progress?.step('scheduling_next_reconcile');

    return {
      outcome: 'pending_reconcile',
      externalOrderId: supplierOrderId,
      message,
      deliveredPayload: {
        checkpoint: 'reconcile_tab_error',
        phase: 'reconcile',
        supplier_order_id: supplierOrderId,
        error: message,
      },
    };
  }
}
