import type { Page } from 'playwright';
import type { DriverResult, RunPayload } from '../../types.js';
import type { RunLogger } from '../../logging/runLogger.js';
import { openWasimOrdersPage } from './ensureOrdersPage.js';

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

function startDateThreeYearsAgo(): string {
  const date = new Date();
  date.setFullYear(date.getFullYear() - 3);

  return date.toISOString().slice(0, 10);
}

async function loadOrdersTab(page: Page, tab: OrderTab, logger: RunLogger): Promise<void> {
  await page.locator(TAB_BUTTONS[tab]).click();
  await page.locator('#startDate').fill(startDateThreeYearsAgo());
  await page.locator('#btn-Transaction').click();

  await page.locator('#responsiveDataTable2').waitFor({ state: 'visible', timeout: 30_000 });

  await page.locator('#responsiveDataTable2_processing').waitFor({ state: 'hidden', timeout: 30_000 }).catch(() => undefined);

  logger.log('reconcile_tab', `Loaded Wasim orders tab=${tab}`);
}

async function searchOrderInTable(page: Page, supplierOrderId: string): Promise<number | null> {
  const search = page.locator('input[aria-controls="responsiveDataTable2"]').first();

  await search.fill('');
  await search.fill(supplierOrderId);

  await page.locator('#responsiveDataTable2_processing').waitFor({ state: 'hidden', timeout: 15_000 }).catch(() => undefined);

  const rows = page.locator('#responsiveDataTable2 tbody tr');

  const count = await rows.count();

  for (let index = 0; index < count; index += 1) {
    const row = rows.nth(index);
    const text = (await row.innerText().catch(() => '')).replace(/\s+/g, ' ');

    if (text.includes(supplierOrderId)) {
      return index;
    }
  }

  return null;
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
): Promise<FoundOrder | null> {
  await loadOrdersTab(page, tab, logger);

  const rowIndex = await searchOrderInTable(page, supplierOrderId);

  if (rowIndex === null) {
    return null;
  }

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

  const ordersPage = await openWasimOrdersPage(page, payload, logger, screenshot);

  if (!ordersPage.ok) {
    return {
      outcome: 'failed',
      errorCode: ordersPage.errorCode,
      message: ordersPage.message,
      deliveredPayload: { checkpoint: 'reconcile', phase: 'reconcile' },
    };
  }

  const cancelled = await findOrderOnTab(page, 'cancelled', supplierOrderId, logger);

  if (cancelled !== null) {
    await screenshot('reconcile_cancelled');

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

  if (completed !== null) {
    await screenshot('reconcile_completed');

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

  if (inProgress !== null) {
    await screenshot('reconcile_in_progress');

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
}
