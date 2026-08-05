/**
 * HTML-string signature matching for fixture tests (no Playwright / no live Wasim).
 * Mirrors wasim-ui-v1 required markers only — never invents v2 selectors.
 */

export type FixtureKind =
  | 'login'
  | 'product'
  | 'orders'
  | 'maintenance'
  | 'access_denied'
  | 'unknown'
  | 'ambiguous'
  | 'swal_accepted'
  | 'swal_rejected';

export type FixtureDetectionKind =
  | 'recognized'
  | 'login_required'
  | 'maintenance'
  | 'access_denied'
  | 'ambiguous'
  | 'unknown';

function has(html: string, needle: string): boolean {
  return html.includes(needle);
}

export function detectWasimUiFromHtml(html: string, path: string): {
  kind: FixtureDetectionKind;
  failureCode?: string;
  adapterId?: string;
  purchaseCapable?: boolean;
  reconcileCapable?: boolean;
} {
  const normalizedPath = path.replace(/\/$/, '').toLowerCase();
  const body = html.toLowerCase();

  if (body.includes('data-wasim-maintenance') || body.includes('under maintenance') || body.includes('الصيانة')) {
    return { kind: 'maintenance', failureCode: 'maintenance' };
  }

  if (
    (body.includes('access denied') || body.includes('غير مصرح'))
    && !normalizedPath.includes('/identity/account/login')
    && !normalizedPath.includes('/customer/home/productrequest')
    && !normalizedPath.endsWith('/customer/order')
  ) {
    return { kind: 'access_denied', failureCode: 'access_denied' };
  }

  const loginMarkers = [
    has(html, 'id="Input_Email"') || has(html, 'name="Input.Email"'),
    has(html, 'id="Input_Password"') || has(html, 'name="Input.Password"'),
    has(html, 'دخول'),
  ];
  const productMarkers = [
    has(html, 'id="product-request-playrid"') || has(html, 'name="playerId"'),
    has(html, 'id="product-request-TotalPrice"') || has(html, 'name="TotalPrice"'),
    has(html, 'id="product-request-buyid"') || has(html, 'إتمام الشراء'),
  ];
  const ordersMarkers = [
    has(html, 'id="responsiveDataTable2"'),
    has(html, 'id="btn-new"'),
    has(html, 'id="btn-Completed"'),
    has(html, 'id="btn-Cancelled"'),
    has(html, 'id="btn-Transaction"'),
  ];

  const loginOk = loginMarkers.every(Boolean);
  const productOk = productMarkers.every(Boolean);
  const ordersOk = ordersMarkers.every(Boolean);

  // Conflicting full marker sets → ambiguous regardless of path.
  if ((loginOk && productOk) || (loginOk && ordersOk) || (productOk && ordersOk)) {
    return { kind: 'ambiguous', failureCode: 'ambiguous_ui' };
  }

  const loginPathOk = loginOk && normalizedPath.includes('/identity/account/login');
  const productPathOk = productOk && normalizedPath.includes('/customer/home/productrequest');
  const ordersPathOk = ordersOk && normalizedPath.endsWith('/customer/order');

  const contexts = [loginPathOk, productPathOk, ordersPathOk].filter(Boolean).length;

  if (contexts > 1) {
    return { kind: 'ambiguous', failureCode: 'ambiguous_ui' };
  }

  if (loginPathOk) {
    return { kind: 'login_required', failureCode: 'authentication_required', adapterId: 'wasim-ui-v1' };
  }

  if (productPathOk) {
    return {
      kind: 'recognized',
      adapterId: 'wasim-ui-v1',
      purchaseCapable: true,
      reconcileCapable: false,
    };
  }

  if (ordersPathOk) {
    return {
      kind: 'recognized',
      adapterId: 'wasim-ui-v1',
      purchaseCapable: false,
      reconcileCapable: true,
    };
  }

  const partialProduct = productMarkers.filter(Boolean).length;
  const partialOrders = ordersMarkers.filter(Boolean).length;

  if (partialProduct > 0 && partialProduct < 3) {
    return { kind: 'ambiguous', failureCode: 'ambiguous_ui' };
  }

  if (partialOrders > 0 && partialOrders < 5) {
    return { kind: 'ambiguous', failureCode: 'orders_ui_unsupported' };
  }

  return { kind: 'unknown', failureCode: 'unsupported_ui' };
}

export function countBuyControlsInHtml(html: string): number {
  let count = 0;

  if (has(html, 'id="product-request-buyid"')) {
    count += 1;
  }

  // Count additional exact CTA anchors beyond the id control.
  const matches = html.match(/إتمام الشراء/g);

  if (matches && !has(html, 'id="product-request-buyid"')) {
    count += matches.length;
  } else if (matches && matches.length > 1) {
    count += matches.length - 1;
  }

  return count;
}

export function fixtureBlocksSubmit(detection: ReturnType<typeof detectWasimUiFromHtml>): boolean {
  return detection.kind !== 'recognized' || detection.purchaseCapable !== true;
}
