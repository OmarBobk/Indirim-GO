import type { Page } from 'playwright';
import {
  extractProductIdFromUrl,
  isWasimLoginPage,
  isWasimOrdersPage,
  isWasimProductRequestPage,
} from '../../pageState.js';
import { isWasimHostname } from '../../urls.js';
import { detectWasimUi } from '../detect.js';
import type { WasimUiFailureCode } from '../failures.js';
import { countSignature } from '../signatures.js';
import type {
  NormalizedRequirementKey,
  PreSubmitContractReport,
  PriceReadResult,
  ProductIdentityReport,
  RequirementsContractReport,
  SafeDiagnostics,
  SignatureCode,
  WasimUiAdapter,
  WasimUiDetection,
} from '../types.js';
import {
  WASIM_DRIVER_VERSION,
  WASIM_ORDERS_CONTRACT_V1,
  WASIM_PURCHASE_CONTRACT_V1,
  WASIM_UI_V1_ID,
  WASIM_UI_V1_VERSION,
} from '../versions.js';

const PLAYER_SELECTOR = '#product-request-playrid, input[name="playerId"], input[placeholder="معرف اللاعب"]';
const TOTAL_SELECTOR = '#product-request-TotalPrice, input[name="TotalPrice"], input[placeholder="الاجمالي"]';
const BUY_SELECTOR = '#product-request-buyid, a:has-text("إتمام الشراء")';
const EMAIL_SELECTOR = '#Input_Email, input[name="Input.Email"], input[placeholder="name@example.com"]';
const PASSWORD_SELECTOR = '#Input_Password, input[name="Input.Password"], input[placeholder="password"]';

function pathAndHost(url: string): { path: string | null; hostname: string | null } {
  try {
    const parsed = new URL(url);

    return {
      path: parsed.pathname.replace(/\/$/, '').toLowerCase(),
      hostname: parsed.hostname.toLowerCase(),
    };
  } catch {
    return { path: null, hostname: null };
  }
}

/**
 * Proven Wasim UI adapter — selectors from the live driver as of C1.2 audit.
 * No alternate UI is invented here; unknown pages must fail closed.
 */
export const wasimUiV1Adapter: WasimUiAdapter = {
  adapterId: WASIM_UI_V1_ID,
  uiVersion: WASIM_UI_V1_VERSION,
  driverVersion: WASIM_DRIVER_VERSION,
  purchaseContractVersion: WASIM_PURCHASE_CONTRACT_V1,
  ordersContractVersion: WASIM_ORDERS_CONTRACT_V1,
  capabilities: {
    purchase: true,
    reconcile: true,
    priceScan: true,
  },

  detectPage(page: Page): Promise<WasimUiDetection> {
    return detectWasimUi(page);
  },

  async collectSafeDiagnostics(
    page: Page,
    failureCode: WasimUiFailureCode | null = null,
  ): Promise<SafeDiagnostics> {
    const detection = await detectWasimUi(page);
    const { path, hostname } = pathAndHost(page.url());

    return {
      path,
      hostname,
      adapter_candidates: detection.kind === 'recognized'
        ? [detection.adapterId]
        : detection.candidateAdapterIds,
      matched_signature_codes: detection.matchedSignatures,
      missing_required_signature_codes: detection.missingRequiredSignatures,
      failure_code: failureCode
        ?? (detection.kind === 'recognized' ? null : detection.failureCode),
      driver_version: WASIM_DRIVER_VERSION,
      ui_version: detection.kind === 'recognized' ? detection.uiVersion : null,
      purchase_contract_version: WASIM_PURCHASE_CONTRACT_V1,
      orders_contract_version: WASIM_ORDERS_CONTRACT_V1,
      checked_at: new Date().toISOString(),
    };
  },

  countVisible(page: Page, signature: SignatureCode): Promise<number> {
    return countSignature(page, signature);
  },

  async isLoginFormVisible(page: Page, timeoutMs = 5_000): Promise<boolean> {
    try {
      await page.locator(EMAIL_SELECTOR).first().waitFor({ state: 'visible', timeout: timeoutMs });

      return true;
    } catch {
      return false;
    }
  },

  async submitLogin(page: Page, username: string, password: string): Promise<void> {
    await page.locator(EMAIL_SELECTOR).first().fill(username);
    await page.locator(PASSWORD_SELECTOR).first().fill(password);
    await page.getByRole('button', { name: 'دخول' }).click();
  },

  async readAuthValidationText(page: Page): Promise<string | null> {
    const text = await page
      .locator('.validation-summary-errors, .text-danger, [asp-validation-summary]')
      .first()
      .textContent()
      .catch(() => null);

    return text?.trim() || null;
  },

  async validateProductIdentity(
    page: Page,
    expectedProductApi: string,
    expectedProductUrl: string,
  ): Promise<ProductIdentityReport> {
    const url = page.url();
    const signals: string[] = [];

    if (!isWasimHostname(pathAndHost(url).hostname ?? '')) {
      return { ok: false, failureCode: 'product_identity_mismatch', signals };
    }

    if (!isWasimProductRequestPage(url)) {
      return { ok: false, failureCode: 'product_not_found', signals };
    }

    signals.push('product_path');

    const expectedId = extractProductIdFromUrl(expectedProductUrl);
    const currentId = extractProductIdFromUrl(url);

    if (expectedId !== null) {
      if (currentId === null) {
        return { ok: false, failureCode: 'product_identity_ambiguous', signals };
      }

      signals.push('product_id_query');

      if (expectedId !== currentId) {
        return { ok: false, failureCode: 'product_identity_mismatch', signals };
      }
    } else if (expectedProductApi.trim() !== '') {
      signals.push('product_api_configured');
    }

    if (signals.length < 2) {
      return { ok: false, failureCode: 'product_identity_ambiguous', signals };
    }

    return { ok: true, signals };
  },

  async validateRequirementsContract(
    page: Page,
    required: NormalizedRequirementKey[],
  ): Promise<RequirementsContractReport> {
    const foundFields: NormalizedRequirementKey[] = [];
    const missingFields: NormalizedRequirementKey[] = [];

    for (const key of required) {
      if (key !== 'player_id') {
        missingFields.push(key);

        return {
          ok: false,
          failureCode: 'unsupported_required_field',
          foundFields,
          missingFields,
        };
      }

      const count = await page.locator(PLAYER_SELECTOR).filter({ visible: true }).count().catch(() => 0);

      if (count === 0) {
        missingFields.push(key);

        return {
          ok: false,
          failureCode: 'required_field_missing',
          foundFields,
          missingFields,
        };
      }

      if (count > 1) {
        return {
          ok: false,
          failureCode: 'required_field_ambiguous',
          foundFields,
          missingFields: required,
        };
      }

      foundFields.push(key);
    }

    return { ok: true, foundFields, missingFields };
  },

  async fillRequirement(
    page: Page,
    key: NormalizedRequirementKey,
    value: string,
  ): Promise<{ ok: true } | { ok: false; failureCode: WasimUiFailureCode }> {
    if (key !== 'player_id') {
      return { ok: false, failureCode: 'unsupported_required_field' };
    }

    const field = page.locator(PLAYER_SELECTOR).first();

    try {
      await field.waitFor({ state: 'visible', timeout: 15_000 });
    } catch {
      return { ok: false, failureCode: 'required_field_missing' };
    }

    await field.fill(value);

    return { ok: true };
  },

  async readSupplierPrice(page: Page): Promise<PriceReadResult> {
    const field = page.locator(TOTAL_SELECTOR).first();

    try {
      await field.waitFor({ state: 'visible', timeout: 15_000 });
    } catch {
      return { ok: false, failureCode: 'supplier_price_missing' };
    }

    const displayedRaw = (await field.inputValue()).trim()
      || (await field.getAttribute('value'))?.trim()
      || '';

    const normalized = displayedRaw.replace(/,/g, '').replace(/[^\d.-]/g, '');
    const amount = Number.parseFloat(normalized);

    if (!Number.isFinite(amount) || amount <= 0) {
      return { ok: false, failureCode: 'supplier_price_parse_failed', displayedRaw };
    }

    return {
      ok: true,
      amountDecimal: amount.toFixed(4).replace(/\.?0+$/, '') === ''
        ? amount.toFixed(2)
        : amount.toFixed(4).replace(/0+$/, '').replace(/\.$/, ''),
      displayedRaw,
    };
  },

  async validatePreSubmit(page: Page, expectedProductUrl: string): Promise<PreSubmitContractReport> {
    const url = page.url();
    const { hostname } = pathAndHost(url);
    const checks: Record<string, boolean> = {
      expected_domain: hostname !== null && isWasimHostname(hostname),
      product_path: isWasimProductRequestPage(url, expectedProductUrl),
      not_login: !isWasimLoginPage(url),
      player_field_unique: false,
      price_field_present: false,
      submit_unique_enabled: false,
      no_maintenance: true,
    };

    if (!checks.expected_domain || !checks.product_path || !checks.not_login) {
      return { ok: false, failureCode: 'pre_submit_contract_failed', checks };
    }

    const playerCount = await page.locator(PLAYER_SELECTOR).filter({ visible: true }).count().catch(() => 0);
    checks.player_field_unique = playerCount === 1;

    const priceCount = await page.locator(TOTAL_SELECTOR).filter({ visible: true }).count().catch(() => 0);
    checks.price_field_present = priceCount >= 1;

    const buyLocator = page.locator(BUY_SELECTOR);
    const buyCount = await buyLocator.filter({ visible: true }).count().catch(() => 0);
    checks.submit_unique_enabled = buyCount === 1;

    if (buyCount === 0) {
      return { ok: false, failureCode: 'submit_control_missing', checks };
    }

    if (buyCount > 1) {
      return { ok: false, failureCode: 'submit_control_ambiguous', checks };
    }

    const enabled = await buyLocator.first().isEnabled().catch(() => false);
    checks.submit_unique_enabled = enabled;

    if (!checks.player_field_unique || !checks.price_field_present || !enabled) {
      return { ok: false, failureCode: 'pre_submit_contract_failed', checks };
    }

    return { ok: true, checks };
  },

  async locateUniqueSubmitControl(
    page: Page,
  ): Promise<{ ok: true } | { ok: false; failureCode: WasimUiFailureCode }> {
    const count = await page.locator(BUY_SELECTOR).filter({ visible: true }).count().catch(() => 0);

    if (count === 0) {
      return { ok: false, failureCode: 'submit_control_missing' };
    }

    if (count > 1) {
      return { ok: false, failureCode: 'submit_control_ambiguous' };
    }

    return { ok: true };
  },

  async clickSubmitOnce(page: Page): Promise<void> {
    await page.locator(BUY_SELECTOR).first().click({ trial: false });
  },

  async waitForSubmissionDialog(page: Page, timeoutMs: number): Promise<boolean> {
    const swal = page.locator(
      '.swal2-container.swal2-backdrop-show .swal2-popup.swal2-show',
    ).first();

    try {
      await swal.waitFor({ state: 'visible', timeout: timeoutMs });

      return true;
    } catch {
      return false;
    }
  },

  async readSubmissionHtml(page: Page): Promise<string> {
    const swal = page.locator(
      '.swal2-container.swal2-backdrop-show .swal2-popup.swal2-show',
    ).first();
    const htmlContainer = swal.locator('.swal2-html-container').first();

    return (await htmlContainer.innerHTML().catch(() => ''))
      || (await htmlContainer.innerText().catch(() => ''));
  },

  async dismissSubmissionDialog(page: Page): Promise<void> {
    const swal = page.locator(
      '.swal2-container.swal2-backdrop-show .swal2-popup.swal2-show',
    ).first();
    const confirmButton = swal.locator('.swal2-confirm').first();

    if (await confirmButton.isVisible().catch(() => false)) {
      await confirmButton.click();
      await swal.waitFor({ state: 'hidden', timeout: 10_000 }).catch(() => undefined);
    }
  },

  async validateOrdersContract(page: Page): Promise<PreSubmitContractReport> {
    const url = page.url();
    const { hostname } = pathAndHost(url);
    const checks: Record<string, boolean> = {
      expected_domain: hostname !== null && isWasimHostname(hostname),
      orders_path: isWasimOrdersPage(url),
      table: false,
      tab_new: false,
      tab_completed: false,
      tab_cancelled: false,
      reload: false,
    };

    checks.table = (await countSignature(page, 'orders_table')) > 0;
    checks.tab_new = (await countSignature(page, 'orders_tab_new')) > 0;
    checks.tab_completed = (await countSignature(page, 'orders_tab_completed')) > 0;
    checks.tab_cancelled = (await countSignature(page, 'orders_tab_cancelled')) > 0;
    checks.reload = (await countSignature(page, 'orders_reload')) > 0;

    const ok = Object.values(checks).every(Boolean);

    return {
      ok,
      failureCode: ok ? undefined : 'orders_contract_failed',
      checks,
    };
  },
};
