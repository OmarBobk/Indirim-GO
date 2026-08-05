import type { Page } from 'playwright';
import type { RunPayload } from '../../types.js';
import type { RunLogger } from '../../logging/runLogger.js';
import type { ProgressReporter } from '../../progress/ProgressReporter.js';
import { isWasimLoginPage, isWasimOrdersPage } from './pageState.js';
import { WASIM_ORDERS_URL } from './urls.js';
import { defaultWasimUiAdapter } from './ui/registry.js';
import { resolveWasimUiForPage } from './ui/resolveAdapter.js';
import type { SafeDiagnostics, WasimUiAdapter } from './ui/types.js';

export type OpenOrdersSuccess = {
  ok: true;
  adapter: WasimUiAdapter;
  uiVersion: string;
  ordersContractVersion: string;
};

export type OpenOrdersFailure = {
  ok: false;
  errorCode: string;
  message: string;
  outcome?: 'failed' | 'needs_review';
  diagnostics?: SafeDiagnostics;
};

export async function openWasimOrdersPage(
  page: Page,
  payload: RunPayload,
  logger: RunLogger,
  screenshot: (label: string) => Promise<void>,
  progress?: ProgressReporter,
): Promise<OpenOrdersSuccess | OpenOrdersFailure> {
  const username = payload.credentials?.username?.trim();
  const password = payload.credentials?.password;
  const adapter = defaultWasimUiAdapter();

  if (!username || !password) {
    return {
      ok: false,
      errorCode: 'credentials_missing',
      message: 'Wasim supplier credentials are not configured.',
    };
  }

  await page.setViewportSize({ width: 1920, height: 1080 });

  logger.log('navigate', `Opening Wasim orders page ${WASIM_ORDERS_URL}`);

  await page.goto(WASIM_ORDERS_URL, {
    waitUntil: 'domcontentloaded',
    timeout: 60_000,
  });

  let resolved = await resolveWasimUiForPage(page, logger, progress, { allowLoginRequired: true });

  if (!resolved.ok) {
    await screenshot('ui_unsupported');

    return {
      ok: false,
      errorCode: resolved.failureCode,
      message: `Wasim orders UI quarantine: ${resolved.failureCode}.`,
      outcome: 'needs_review',
      diagnostics: resolved.diagnostics,
    };
  }

  if (isWasimLoginPage(page.url()) || resolved.detection.kind === 'login_required') {
    logger.log('login', 'Login required for Wasim orders page');
    progress?.step('login_required');
    await screenshot('login');

    progress?.step('authentication_started');
    await adapter.submitLogin(page, username, password);

    try {
      await page.waitForURL(
        (url) => isWasimOrdersPage(url.toString()),
        { timeout: 45_000 },
      );
    } catch {
      // Verified below.
    }

    if (isWasimLoginPage(page.url())) {
      await screenshot('login_failed');

      return {
        ok: false,
        errorCode: 'authentication_failed',
        message: 'Wasim orders login failed.',
      };
    }

    progress?.step('authentication_succeeded');
    resolved = await resolveWasimUiForPage(page, logger, progress, { allowLoginRequired: false });

    if (!resolved.ok) {
      await screenshot('ui_unsupported');

      return {
        ok: false,
        errorCode: resolved.failureCode,
        message: `Wasim orders UI quarantine: ${resolved.failureCode}.`,
        outcome: 'needs_review',
        diagnostics: resolved.diagnostics,
      };
    }
  }

  if (!isWasimOrdersPage(page.url()) || resolved.detection.kind !== 'recognized' || !resolved.detection.reconcileCapable) {
    await screenshot('orders_page_unreachable');

    return {
      ok: false,
      errorCode: 'orders_ui_unsupported',
      message: `Expected Wasim orders page but landed on ${page.url()}`,
      outcome: 'needs_review',
      diagnostics: await resolved.adapter.collectSafeDiagnostics(page, 'orders_ui_unsupported'),
    };
  }

  progress?.step('page_contract_validating');
  const contract = await resolved.adapter.validateOrdersContract(page);

  if (!contract.ok) {
    await screenshot('orders_contract_failed');
    progress?.step('page_contract_failed', contract.failureCode);

    return {
      ok: false,
      errorCode: contract.failureCode ?? 'orders_contract_failed',
      message: 'Wasim orders page contract failed.',
      outcome: 'needs_review',
      diagnostics: await resolved.adapter.collectSafeDiagnostics(
        page,
        contract.failureCode ?? 'orders_contract_failed',
      ),
    };
  }

  progress?.step('page_contract_valid');
  progress?.setContractMeta(resolved.adapter.uiVersion, resolved.adapter.ordersContractVersion);

  await page.locator('#responsiveDataTable2, #btn-Transaction').first().waitFor({
    state: 'visible',
    timeout: 30_000,
  }).catch(() => undefined);

  await screenshot('orders_page');

  return {
    ok: true,
    adapter: resolved.adapter,
    uiVersion: resolved.adapter.uiVersion,
    ordersContractVersion: resolved.adapter.ordersContractVersion,
  };
}
