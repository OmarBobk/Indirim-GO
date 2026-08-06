import type { Page } from 'playwright';
import type { RunPayload } from '../../types.js';
import type { RunLogger } from '../../logging/runLogger.js';
import type { ProgressReporter } from '../../progress/ProgressReporter.js';
import { resolveWasimProductUrl } from './urls.js';
import { resolveWasimUiForPage } from './ui/resolveAdapter.js';
import type { SafeDiagnostics, WasimUiAdapter } from './ui/types.js';
import {
  extractProductIdFromUrl,
  isWasimLoginPage,
  isWasimProductRequestPage,
} from './pageState.js';

export { extractProductIdFromUrl, isWasimLoginPage, isWasimProductRequestPage };

export type OpenProductSuccess = {
  ok: true;
  productApi: string;
  productUrl: string;
  url: string;
  adapter: WasimUiAdapter;
  uiVersion: string;
  purchaseContractVersion: string;
};

export type OpenProductFailure = {
  ok: false;
  errorCode: string;
  message: string;
  outcome?: 'failed' | 'needs_review';
  diagnostics?: SafeDiagnostics;
};

async function loginFromCurrentPage(
  page: Page,
  payload: RunPayload,
  productUrl: string,
  logger: RunLogger,
  screenshot: (label: string) => Promise<void>,
  adapter: WasimUiAdapter,
  progress?: ProgressReporter,
): Promise<{ ok: true } | OpenProductFailure> {
  const username = payload.credentials?.username?.trim();
  const password = payload.credentials?.password;

  if (!username || !password) {
    return {
      ok: false,
      errorCode: 'credentials_missing',
      message: 'Wasim supplier credentials are not configured in FULFILLMENT_AUTOMATION_WASIM_USERNAME/PASSWORD.',
    };
  }

  await screenshot('login');

  const loginFormVisible = await adapter.isLoginFormVisible(page);

  if (!loginFormVisible) {
    return {
      ok: false,
      errorCode: 'login_form_missing',
      message: 'Wasim redirected to login but the login form was not visible.',
      outcome: 'needs_review',
    };
  }

  logger.log('login', 'Submitting credentials after product redirect');
  progress?.step('authentication_started');

  await adapter.submitLogin(page, username, password);

  try {
    await page.waitForURL(
      (url) => isWasimProductRequestPage(url.toString(), productUrl),
      { timeout: 45_000 },
    );
  } catch {
    // Verified below.
  }

  if (isWasimLoginPage(page.url())) {
    const validationError = await adapter.readAuthValidationText(page);

    await screenshot('login_failed');

    return {
      ok: false,
      errorCode: 'authentication_failed',
      message: validationError?.trim() || 'Login did not return to the Wasim product page.',
    };
  }

  if (!isWasimProductRequestPage(page.url(), productUrl)) {
    await screenshot('login_failed');

    return {
      ok: false,
      errorCode: 'authenticated_contract_failed',
      message: `Expected Wasim product page but landed on ${page.url()}`,
      outcome: 'needs_review',
    };
  }

  const postLogin = await resolveWasimUiForPage(page, logger, progress, { allowLoginRequired: false });

  if (!postLogin.ok || postLogin.detection.kind !== 'recognized' || !postLogin.detection.purchaseCapable) {
    await screenshot('ui_unsupported');

    return {
      ok: false,
      errorCode: postLogin.ok ? 'unsupported_ui' : postLogin.failureCode,
      message: 'Wasim UI was not recognized after authentication; purchase submit blocked.',
      outcome: 'needs_review',
      diagnostics: postLogin.ok
        ? await postLogin.adapter.collectSafeDiagnostics(page, 'unsupported_ui')
        : postLogin.diagnostics,
    };
  }

  logger.log('login', `Authenticated — returned to product page ${page.url()}`);
  progress?.step('authentication_succeeded');

  return { ok: true };
}

export async function openWasimProductPage(
  page: Page,
  payload: RunPayload,
  logger: RunLogger,
  screenshot: (label: string) => Promise<void>,
  progress?: ProgressReporter,
): Promise<OpenProductSuccess | OpenProductFailure> {
  const productApi = payload.product_api?.trim();

  if (!productApi) {
    return {
      ok: false,
      errorCode: 'product_api_missing',
      message: 'Product API is not configured on this product.',
    };
  }

  progress?.step('session_loading');

  const productUrl = resolveWasimProductUrl(productApi);

  logger.log('navigate', `Opening product target ${productUrl}`);
  progress?.step('opening_product');

  const response = await page.goto(productUrl, {
    waitUntil: 'domcontentloaded',
    timeout: 60_000,
  });

  if (response !== null && !response.ok()) {
    logger.log('navigate', `Product page returned HTTP ${response.status()}`, 'warn');
  }

  await page.waitForLoadState('networkidle', { timeout: 10_000 }).catch(() => {
    logger.log('navigate', 'Network idle timeout after product page load', 'warn');
  });

  progress?.step('session_checking');

  const resolved = await resolveWasimUiForPage(page, logger, progress, { allowLoginRequired: true });

  if (!resolved.ok) {
    await screenshot('ui_unsupported');

    return {
      ok: false,
      errorCode: resolved.failureCode,
      message: `Wasim UI quarantine: ${resolved.failureCode}. Purchase submit blocked.`,
      outcome: 'needs_review',
      diagnostics: resolved.diagnostics,
    };
  }

  const { adapter, detection } = resolved;

  if (detection.kind === 'recognized' && detection.purchaseCapable) {
    const identity = await adapter.validateProductIdentity(page, productApi, productUrl);

    if (!identity.ok) {
      await screenshot('product_identity_failed');

      return {
        ok: false,
        errorCode: identity.failureCode ?? 'product_identity_mismatch',
        message: 'Wasim product identity contract failed; purchase submit blocked.',
        outcome: 'needs_review',
        diagnostics: await adapter.collectSafeDiagnostics(page, identity.failureCode ?? 'product_identity_mismatch'),
      };
    }

    logger.log('navigate', 'Product page opened without login');
    await screenshot('product');
    progress?.step('product_loaded');

    return {
      ok: true,
      productApi,
      productUrl,
      url: page.url(),
      adapter,
      uiVersion: adapter.uiVersion,
      purchaseContractVersion: adapter.purchaseContractVersion,
    };
  }

  if (detection.kind === 'login_required' || isWasimLoginPage(page.url())) {
    logger.log('login', `Login required — redirected to ${page.url()}`);
    progress?.step('login_required');

    const loginResult = await loginFromCurrentPage(
      page,
      payload,
      productUrl,
      logger,
      screenshot,
      adapter,
      progress,
    );

    if (!loginResult.ok) {
      return loginResult;
    }

    const postLogin = await resolveWasimUiForPage(page, logger, progress, { allowLoginRequired: false });

    if (!postLogin.ok || postLogin.detection.kind !== 'recognized') {
      await screenshot('ui_unsupported');

      return {
        ok: false,
        errorCode: postLogin.ok ? 'unsupported_ui' : postLogin.failureCode,
        message: 'Wasim UI quarantine after login; purchase submit blocked.',
        outcome: 'needs_review',
        diagnostics: postLogin.ok
          ? await postLogin.adapter.collectSafeDiagnostics(page, 'unsupported_ui')
          : postLogin.diagnostics,
      };
    }

    await screenshot('product');
    progress?.step('product_loaded');

    return {
      ok: true,
      productApi,
      productUrl,
      url: page.url(),
      adapter: postLogin.adapter,
      uiVersion: postLogin.adapter.uiVersion,
      purchaseContractVersion: postLogin.adapter.purchaseContractVersion,
    };
  }

  await screenshot('product_unexpected');

  return {
    ok: false,
    errorCode: 'product_page_unreachable',
    message: `Expected Wasim product or login page but landed on ${page.url()}`,
  };
}
