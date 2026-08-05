import type { Page } from 'playwright';
import type { RunPayload } from '../../types.js';
import type { RunLogger } from '../../logging/runLogger.js';
import type { ProgressReporter } from '../../progress/ProgressReporter.js';
import { isWasimLoginPage } from './login.js';
import { WASIM_ORDERS_URL, isWasimHostname } from './urls.js';

function isWasimOrdersPage(url: string): boolean {
  try {
    const parsed = new URL(url);

    return isWasimHostname(parsed.hostname)
      && parsed.pathname.replace(/\/$/, '').toLowerCase().endsWith('/customer/order');
  } catch {
    return false;
  }
}

async function submitLoginForm(page: Page, username: string, password: string): Promise<void> {
  const emailField = page.locator(
    '#Input_Email, input[name="Input.Email"], input[placeholder="name@example.com"]',
  ).first();
  const passwordField = page.locator(
    '#Input_Password, input[name="Input.Password"], input[placeholder="password"]',
  ).first();

  await emailField.fill(username);
  await passwordField.fill(password);
  await page.getByRole('button', { name: 'دخول' }).click();
}

export async function openWasimOrdersPage(
  page: Page,
  payload: RunPayload,
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
      message: 'Wasim supplier credentials are not configured.',
    };
  }

  await page.setViewportSize({ width: 1920, height: 1080 });

  logger.log('navigate', `Opening Wasim orders page ${WASIM_ORDERS_URL}`);

  await page.goto(WASIM_ORDERS_URL, {
    waitUntil: 'domcontentloaded',
    timeout: 60_000,
  });

  if (isWasimLoginPage(page.url())) {
    logger.log('login', 'Login required for Wasim orders page');
    progress?.step('login_required');
    await screenshot('login');

    progress?.step('authentication_started');
    await submitLoginForm(page, username, password);

    try {
      await page.waitForURL(
        (url) => isWasimOrdersPage(url.toString()),
        { timeout: 45_000 },
      );
    } catch {
      // Verified below.
    }

    if (!isWasimLoginPage(page.url())) {
      progress?.step('authentication_succeeded');
    }
  }

  if (!isWasimOrdersPage(page.url())) {
    await screenshot('orders_page_unreachable');

    return {
      ok: false,
      errorCode: 'orders_page_unreachable',
      message: `Expected Wasim orders page but landed on ${page.url()}`,
    };
  }

  await page.locator('#responsiveDataTable2, #btn-Transaction').first().waitFor({
    state: 'visible',
    timeout: 30_000,
  }).catch(() => undefined);

  await screenshot('orders_page');

  return { ok: true };
}
