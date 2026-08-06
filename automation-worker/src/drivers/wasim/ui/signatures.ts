import type { Page } from 'playwright';
import type { SignatureCode, SignatureMatch } from './types.js';

export type SignatureDefinition = {
  code: SignatureCode;
  requiredFor: Array<'login' | 'product' | 'orders'>;
  /**
   * Counts visible matches. Prefer data attributes / names over generated CSS.
   */
  count: (page: Page) => Promise<number>;
};

async function countLocator(page: Page, selector: string): Promise<number> {
  return page.locator(selector).filter({ visible: true }).count().catch(() => 0);
}

export const WASIM_V1_SIGNATURES: SignatureDefinition[] = [
  {
    code: 'login_email_field',
    requiredFor: ['login'],
    count: (page) => countLocator(
      page,
      '#Input_Email, input[name="Input.Email"], input[placeholder="name@example.com"]',
    ),
  },
  {
    code: 'login_password_field',
    requiredFor: ['login'],
    count: (page) => countLocator(
      page,
      '#Input_Password, input[name="Input.Password"], input[placeholder="password"]',
    ),
  },
  {
    code: 'login_submit_arabic',
    requiredFor: ['login'],
    count: async (page) => page.getByRole('button', { name: 'دخول' }).count().catch(() => 0),
  },
  {
    code: 'product_player_id_field',
    requiredFor: ['product'],
    count: (page) => countLocator(
      page,
      '#product-request-playrid, input[name="playerId"], input[placeholder="معرف اللاعب"]',
    ),
  },
  {
    code: 'product_total_price_field',
    requiredFor: ['product'],
    count: (page) => countLocator(
      page,
      '#product-request-TotalPrice, input[name="TotalPrice"], input[placeholder="الاجمالي"]',
    ),
  },
  {
    code: 'product_buy_control',
    requiredFor: ['product'],
    count: (page) => countLocator(page, '#product-request-buyid, a:has-text("إتمام الشراء")'),
  },
  {
    code: 'orders_table',
    requiredFor: ['orders'],
    count: (page) => countLocator(page, '#responsiveDataTable2'),
  },
  {
    code: 'orders_tab_new',
    requiredFor: ['orders'],
    count: (page) => countLocator(page, '#btn-new'),
  },
  {
    code: 'orders_tab_completed',
    requiredFor: ['orders'],
    count: (page) => countLocator(page, '#btn-Completed'),
  },
  {
    code: 'orders_tab_cancelled',
    requiredFor: ['orders'],
    count: (page) => countLocator(page, '#btn-Cancelled'),
  },
  {
    code: 'orders_reload',
    requiredFor: ['orders'],
    count: (page) => countLocator(page, '#btn-Transaction'),
  },
  {
    code: 'maintenance_marker',
    requiredFor: [],
    count: async (page) => {
      const text = ((await page.locator('body').innerText().catch(() => '')) || '').toLowerCase();
      const hay = text.replace(/\s+/g, ' ');

      if (hay.includes('under maintenance') || hay.includes('الصيانة') || hay.includes('maintenance')) {
        return 1;
      }

      return countLocator(page, '[data-wasim-maintenance], .maintenance-page');
    },
  },
  {
    code: 'access_denied_marker',
    requiredFor: [],
    count: async (page) => {
      const text = ((await page.locator('body').innerText().catch(() => '')) || '').toLowerCase();
      const hay = text.replace(/\s+/g, ' ');

      if (hay.includes('access denied') || hay.includes('غير مصرح') || hay.includes('403')) {
        return countLocator(page, 'h1, h2, .alert, .text-danger').then((n) => (n > 0 ? 1 : 0));
      }

      return 0;
    },
  },
];

export async function evaluateSignatures(
  page: Page,
  requiredFor: 'login' | 'product' | 'orders',
): Promise<SignatureMatch[]> {
  const matches: SignatureMatch[] = [];

  for (const definition of WASIM_V1_SIGNATURES) {
    if (definition.requiredFor.length > 0 && !definition.requiredFor.includes(requiredFor)) {
      continue;
    }

    if (definition.requiredFor.length === 0 && requiredFor !== 'login') {
      // Always evaluate maintenance / access markers.
    }

    const count = await definition.count(page);
    const required = definition.requiredFor.includes(requiredFor);

    matches.push({
      code: definition.code,
      present: count > 0,
      required,
    });
  }

  return matches;
}

export async function countSignature(page: Page, code: SignatureCode): Promise<number> {
  const definition = WASIM_V1_SIGNATURES.find((item) => item.code === code);

  if (!definition) {
    return 0;
  }

  return definition.count(page);
}
