import { withBrowserContext } from '../../../browser/pool.js';
import { WORKER_BUILD } from '../../../build.js';
import { WORKER_INSTANCE_ID } from '../../../workerIdentity.js';
import { resolveWasimProductUrl, WASIM_ORDERS_URL } from '../urls.js';
import { isWasimLoginPage, isWasimOrdersPage, isWasimProductRequestPage } from '../pageState.js';
import { defaultWasimUiAdapter } from './registry.js';
import { detectWasimUi } from './detect.js';
import {
  WASIM_DRIVER_VERSION,
  WASIM_ORDERS_CONTRACT_V1,
  WASIM_PURCHASE_CONTRACT_V1,
  WASIM_UI_V1_VERSION,
} from './versions.js';
import type { WasimUiFailureCode } from './failures.js';
import type { BrowserContext } from 'playwright';

export type WasimProbeMode = 'full' | 'session' | 'purchase_contract' | 'reconcile_contract';

export type WasimProbeRequest = {
  mode?: WasimProbeMode;
  session_key: string;
  credentials?: {
    username?: string;
    password?: string;
  };
  test_product?: {
    product_api?: string | null;
    expected_product_id?: string | null;
    expected_currency?: string | null;
  } | null;
};

export type WasimProbeResponse = {
  checked_at: string;
  worker_build: string;
  worker_instance_id: string;
  driver_version: string;
  detected_ui_version: string | null;
  purchase_contract_version: string;
  orders_contract_version: string;
  session_state: string;
  purchase_contract_state: string;
  reconcile_contract_state: string;
  test_product_state: string;
  state: string;
  failure_codes: WasimUiFailureCode[];
  duration_ms: number;
  operational_classification: string;
};

function classifyState(parts: {
  session: string;
  purchase: string;
  reconcile: string;
  product: string;
  failures: WasimUiFailureCode[];
}): { state: string; operational: string } {
  if (parts.failures.includes('maintenance')) {
    return { state: 'maintenance', operational: 'supplier_maintenance' };
  }

  if (parts.failures.includes('unsupported_ui') || parts.failures.includes('ambiguous_ui') || parts.failures.includes('orders_ui_unsupported')) {
    return { state: 'unsupported_ui', operational: 'unsafe_ui' };
  }

  if (parts.session === 'authentication_required' || parts.failures.includes('authentication_required')) {
    return { state: 'authentication_required', operational: 'auth_required' };
  }

  if (parts.failures.includes('authentication_failed') || parts.failures.includes('access_denied')) {
    return { state: 'authentication_required', operational: 'auth_failed' };
  }

  if (parts.purchase === 'contract_failed' || parts.reconcile === 'contract_failed') {
    return { state: 'contract_failed', operational: 'contract_failed' };
  }

  if (parts.product === 'not_configured' && parts.session === 'authenticated' && parts.reconcile === 'healthy') {
    return { state: 'degraded', operational: 'product_probe_not_configured' };
  }

  if (parts.session === 'authenticated' && (parts.purchase === 'healthy' || parts.purchase === 'skipped') && parts.reconcile === 'healthy') {
    return { state: 'healthy', operational: 'healthy' };
  }

  if (parts.failures.length > 0) {
    return { state: 'degraded', operational: 'degraded' };
  }

  return { state: 'degraded', operational: 'degraded' };
}

/**
 * Non-mutating Wasim health probe. Never fills requirements or clicks purchase.
 */
export async function runWasimHealthProbe(request: WasimProbeRequest): Promise<WasimProbeResponse> {
  const started = Date.now();
  const checkedAt = new Date().toISOString();
  const mode: WasimProbeMode = request.mode ?? 'full';
  const failureCodes: WasimUiFailureCode[] = [];
  const adapter = defaultWasimUiAdapter();

  let sessionState = 'unknown';
  let purchaseState = mode === 'session' || mode === 'reconcile_contract' ? 'skipped' : 'unknown';
  let reconcileState = mode === 'session' || mode === 'purchase_contract' ? 'skipped' : 'unknown';
  let testProductState = 'not_configured';
  let detectedUi: string | null = null;

  const productApi = request.test_product?.product_api?.trim() || '';

  try {
    await withBrowserContext(request.session_key, request.credentials, async (context: BrowserContext) => {
      const page = await context.newPage();

      try {
        if (mode === 'full' || mode === 'session' || mode === 'purchase_contract') {
          if (productApi !== '') {
            const productUrl = resolveWasimProductUrl(productApi);
            await page.goto(productUrl, { waitUntil: 'domcontentloaded', timeout: 60_000 });

            const detection = await detectWasimUi(page);
            if (detection.kind === 'recognized') {
              detectedUi = detection.uiVersion;
            } else if (detection.kind === 'login_required') {
              sessionState = 'authentication_required';
              failureCodes.push('authentication_required');
              detectedUi = WASIM_UI_V1_VERSION;
            } else {
              failureCodes.push(detection.failureCode);
              sessionState = detection.kind;
            }

            if (isWasimLoginPage(page.url())) {
              sessionState = 'authentication_required';
              if (!failureCodes.includes('authentication_required')) {
                failureCodes.push('authentication_required');
              }
            } else if (isWasimProductRequestPage(page.url())) {
              sessionState = 'authenticated';

              if (mode !== 'session') {
                const identity = await adapter.validateProductIdentity(page, productApi, productUrl);
                const price = await adapter.readSupplierPrice(page);
                const preSubmit = await adapter.validatePreSubmit(page, productUrl);

                testProductState = identity.ok ? 'identity_ok' : (identity.failureCode ?? 'identity_failed');

                if (!identity.ok && identity.failureCode) {
                  failureCodes.push(identity.failureCode);
                  purchaseState = 'contract_failed';
                } else if (!price.ok) {
                  failureCodes.push(price.failureCode);
                  purchaseState = 'contract_failed';
                  testProductState = price.failureCode;
                } else if (!preSubmit.ok) {
                  // Probe must not require filled player id — treat missing player uniqueness softer.
                  const onlyPlayer = preSubmit.checks.player_field_unique === false
                    && preSubmit.checks.price_field_present
                    && preSubmit.checks.submit_unique_enabled === false;

                  // For probe: validate domain/path/price/submit presence without filled requirements.
                  const buyOk = (await adapter.locateUniqueSubmitControl(page)).ok;
                  const priceOk = price.ok;

                  if (buyOk && priceOk && identity.ok) {
                    purchaseState = 'healthy';
                    testProductState = 'price_readable';
                    void onlyPlayer;
                    void preSubmit;
                  } else {
                    purchaseState = 'contract_failed';
                    if (preSubmit.failureCode) {
                      failureCodes.push(preSubmit.failureCode);
                    }
                  }
                } else {
                  purchaseState = 'healthy';
                  testProductState = 'price_readable';
                }
              }
            }
          } else if (mode === 'full' || mode === 'purchase_contract') {
            purchaseState = 'not_configured';
            testProductState = 'not_configured';
            failureCodes.push('probe_not_configured');
          }
        }

        if (mode === 'full' || mode === 'session' || mode === 'reconcile_contract') {
          await page.goto(WASIM_ORDERS_URL, { waitUntil: 'domcontentloaded', timeout: 60_000 });

          const detection = await detectWasimUi(page);

          if (detection.kind === 'recognized') {
            detectedUi = detection.uiVersion;
          } else if (detection.kind === 'login_required') {
            sessionState = sessionState === 'authenticated' ? sessionState : 'authentication_required';
            if (!failureCodes.includes('authentication_required')) {
              failureCodes.push('authentication_required');
            }
          } else {
            failureCodes.push(detection.failureCode);
          }

          if (isWasimLoginPage(page.url())) {
            sessionState = sessionState === 'authenticated' ? sessionState : 'authentication_required';
            reconcileState = mode === 'session' ? 'skipped' : 'authentication_required';
          } else if (isWasimOrdersPage(page.url())) {
            sessionState = 'authenticated';

            if (mode !== 'session') {
              const ordersContract = await adapter.validateOrdersContract(page);
              reconcileState = ordersContract.ok ? 'healthy' : 'contract_failed';

              if (!ordersContract.ok && ordersContract.failureCode) {
                failureCodes.push(ordersContract.failureCode);
              }
            }
          } else if (mode !== 'session') {
            reconcileState = 'contract_failed';
            failureCodes.push('orders_ui_unsupported');
          }
        }
      } finally {
        await page.close().catch(() => undefined);
      }
    });
  } catch {
    failureCodes.push('probe_unreachable');
    sessionState = 'unreachable';
    purchaseState = purchaseState === 'unknown' ? 'unreachable' : purchaseState;
    reconcileState = reconcileState === 'unknown' ? 'unreachable' : reconcileState;
  }

  const uniqueFailures = [...new Set(failureCodes)];
  const { state, operational } = classifyState({
    session: sessionState,
    purchase: purchaseState,
    reconcile: reconcileState,
    product: testProductState,
    failures: uniqueFailures,
  });

  if (state === 'unreachable' || uniqueFailures.includes('probe_unreachable')) {
    return {
      checked_at: checkedAt,
      worker_build: WORKER_BUILD,
      worker_instance_id: WORKER_INSTANCE_ID,
      driver_version: WASIM_DRIVER_VERSION,
      detected_ui_version: detectedUi,
      purchase_contract_version: WASIM_PURCHASE_CONTRACT_V1,
      orders_contract_version: WASIM_ORDERS_CONTRACT_V1,
      session_state: 'unreachable',
      purchase_contract_state: purchaseState,
      reconcile_contract_state: reconcileState,
      test_product_state: testProductState,
      state: 'unreachable',
      failure_codes: uniqueFailures,
      duration_ms: Date.now() - started,
      operational_classification: 'unreachable',
    };
  }

  return {
    checked_at: checkedAt,
    worker_build: WORKER_BUILD,
    worker_instance_id: WORKER_INSTANCE_ID,
    driver_version: WASIM_DRIVER_VERSION,
    detected_ui_version: detectedUi,
    purchase_contract_version: WASIM_PURCHASE_CONTRACT_V1,
    orders_contract_version: WASIM_ORDERS_CONTRACT_V1,
    session_state: sessionState,
    purchase_contract_state: purchaseState,
    reconcile_contract_state: reconcileState,
    test_product_state: testProductState,
    state,
    failure_codes: uniqueFailures,
    duration_ms: Date.now() - started,
    operational_classification: operational,
  };
}
