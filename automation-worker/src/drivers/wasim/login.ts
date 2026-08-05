import type { Page } from 'playwright';
import type { RunPayload } from '../../types.js';
import type { RunLogger } from '../../logging/runLogger.js';
import type { ProgressReporter } from '../../progress/ProgressReporter.js';
import { isWasimHostname, resolveWasimProductUrl } from './urls.js';

export function isWasimLoginPage(url: string): boolean {
  try {
    const parsed = new URL(url);

    return isWasimHostname(parsed.hostname)
      && parsed.pathname.replace(/\/$/, '').toLowerCase().includes('/identity/account/login');
  } catch {
    return false;
  }
}

export function extractProductIdFromUrl(url: string): string | null {
  try {
    return new URL(url).searchParams.get('productId');
  } catch {
    return null;
  }
}

export function isWasimProductRequestPage(url: string, targetProductUrl?: string): boolean {
  try {
    const parsed = new URL(url);

    if (!isWasimHostname(parsed.hostname)) {
      return false;
    }

    const path = parsed.pathname.replace(/\/$/, '').toLowerCase();

    if (!path.includes('/customer/home/productrequest')) {
      return false;
    }

    if (targetProductUrl === undefined) {
      return true;
    }

    const expectedProductId = extractProductIdFromUrl(targetProductUrl);
    const currentProductId = extractProductIdFromUrl(url);

    if (expectedProductId !== null && currentProductId !== null) {
      return expectedProductId === currentProductId;
    }

    return url.split('?')[0] === targetProductUrl.split('?')[0];
  } catch {
    return false;
  }
}

async function isLoginFormVisible(page: Page, timeoutMs = 5_000): Promise<boolean> {
  const emailField = page.locator(
    '#Input_Email, input[name="Input.Email"], input[placeholder="name@example.com"]',
  ).first();

  try {
    await emailField.waitFor({ state: 'visible', timeout: timeoutMs });

    return true;
  } catch {
    return false;
  }
}

async function submitLoginForm(
  page: Page,
  username: string,
  password: string,
): Promise<void> {
  const emailField = page.locator(
    '#Input_Email, input[name="Input.Email"], input[placeholder="name@example.com"]',
  ).first();
  const passwordField = page.locator(
    '#Input_Password, input[name="Input.Password"], input[placeholder="password"]',
  ).first();
  const submitButton = page.getByRole('button', { name: 'دخول' });

  await emailField.fill(username);
  await passwordField.fill(password);
  await submitButton.click();
}

async function loginFromCurrentPage(
  page: Page,
  payload: RunPayload,
  productUrl: string,
  logger: RunLogger,
  screenshot: (label: string) => Promise<void>,
  progress?: ProgressReporter,
): Promise<{ ok: true } | { ok: false; errorCode: string; message: string }> {
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

  const loginFormVisible = await isLoginFormVisible(page);

  if (!loginFormVisible) {
    return {
      ok: false,
      errorCode: 'login_form_missing',
      message: 'Wasim redirected to login but the login form was not visible.',
    };
  }

  logger.log('login', 'Submitting credentials after product redirect');
  progress?.step('authentication_started');

  await submitLoginForm(page, username, password);

  try {
    await page.waitForURL(
      (url) => isWasimProductRequestPage(url.toString(), productUrl),
      { timeout: 45_000 },
    );
  } catch {
    // Verified below.
  }

  if (isWasimLoginPage(page.url())) {
    const validationError = await page
      .locator('.validation-summary-errors, .text-danger, [asp-validation-summary]')
      .first()
      .textContent()
      .catch(() => null);

    await screenshot('login_failed');

    return {
      ok: false,
      errorCode: 'login_failed',
      message: validationError?.trim() || 'Login did not return to the Wasim product page.',
    };
  }

  if (!isWasimProductRequestPage(page.url(), productUrl)) {
    await screenshot('login_failed');

    return {
      ok: false,
      errorCode: 'product_page_unreachable',
      message: `Expected Wasim product page but landed on ${page.url()}`,
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
): Promise<
  | { ok: true; productApi: string; productUrl: string; url: string }
  | { ok: false; errorCode: string; message: string }
> {
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

  if (isWasimProductRequestPage(page.url(), productUrl)) {
    logger.log('navigate', 'Product page opened without login');
    await screenshot('product');
    progress?.step('product_loaded');

    return {
      ok: true,
      productApi,
      productUrl,
      url: page.url(),
    };
  }

  if (isWasimLoginPage(page.url())) {
    logger.log('login', `Login required — redirected to ${page.url()}`);
    progress?.step('login_required');

    const loginResult = await loginFromCurrentPage(page, payload, productUrl, logger, screenshot, progress);

    if (!loginResult.ok) {
      return loginResult;
    }

    await screenshot('product');
    progress?.step('product_loaded');

    return {
      ok: true,
      productApi,
      productUrl,
      url: page.url(),
    };
  }

  await screenshot('product_unexpected');

  return {
    ok: false,
    errorCode: 'product_page_unreachable',
    message: `Expected Wasim product or login page but landed on ${page.url()}`,
  };
}
