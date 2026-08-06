import type { Page } from 'playwright';
import { isWasimLoginPage, isWasimOrdersPage, isWasimProductRequestPage } from '../pageState.js';
import { isWasimHostname } from '../urls.js';
import { evaluateSignatures } from './signatures.js';
import type { SignatureCode, WasimUiDetection } from './types.js';
import { WASIM_UI_V1_ID, WASIM_UI_V1_VERSION } from './versions.js';

function presentCodes(matches: Array<{ code: SignatureCode; present: boolean }>): SignatureCode[] {
  return matches.filter((item) => item.present).map((item) => item.code);
}

function missingRequired(
  matches: Array<{ code: SignatureCode; present: boolean; required: boolean }>,
): SignatureCode[] {
  return matches.filter((item) => item.required && !item.present).map((item) => item.code);
}

/**
 * Deterministic Wasim UI detection. No numerical AI confidence.
 * Ambiguous / unknown / maintenance / access_denied never authorize purchase submit.
 */
export async function detectWasimUi(page: Page): Promise<WasimUiDetection> {
  const url = page.url();

  let hostnameOk = false;
  try {
    hostnameOk = isWasimHostname(new URL(url).hostname);
  } catch {
    hostnameOk = false;
  }

  if (!hostnameOk) {
    return {
      kind: 'unknown',
      failureCode: 'unsupported_ui',
      matchedSignatures: [],
      missingRequiredSignatures: [],
      candidateAdapterIds: [],
    };
  }

  const loginMatches = await evaluateSignatures(page, 'login');
  const productMatches = await evaluateSignatures(page, 'product');
  const ordersMatches = await evaluateSignatures(page, 'orders');

  const maintenancePresent = loginMatches.some((m) => m.code === 'maintenance_marker' && m.present)
    || productMatches.some((m) => m.code === 'maintenance_marker' && m.present)
    || ordersMatches.some((m) => m.code === 'maintenance_marker' && m.present);

  if (maintenancePresent) {
    return {
      kind: 'maintenance',
      failureCode: 'maintenance',
      matchedSignatures: presentCodes([...loginMatches, ...productMatches, ...ordersMatches]),
      missingRequiredSignatures: [],
      candidateAdapterIds: [WASIM_UI_V1_ID],
    };
  }

  const accessDenied = loginMatches.some((m) => m.code === 'access_denied_marker' && m.present)
    || productMatches.some((m) => m.code === 'access_denied_marker' && m.present);

  if (accessDenied && !isWasimLoginPage(url) && !isWasimProductRequestPage(url) && !isWasimOrdersPage(url)) {
    return {
      kind: 'access_denied',
      failureCode: 'access_denied',
      matchedSignatures: presentCodes([...loginMatches, ...productMatches]),
      missingRequiredSignatures: [],
      candidateAdapterIds: [WASIM_UI_V1_ID],
    };
  }

  const loginRequiredMissing = missingRequired(loginMatches);
  const productRequiredMissing = missingRequired(productMatches);
  const ordersRequiredMissing = missingRequired(ordersMatches);

  const loginLooksValid = isWasimLoginPage(url) && loginRequiredMissing.length === 0;
  const productLooksValid = isWasimProductRequestPage(url) && productRequiredMissing.length === 0;
  const ordersLooksValid = isWasimOrdersPage(url) && ordersRequiredMissing.length === 0;

  const recognizedContexts = [
    loginLooksValid ? 'login' : null,
    productLooksValid ? 'product' : null,
    ordersLooksValid ? 'orders' : null,
  ].filter((value): value is string => value !== null);

  if (recognizedContexts.length > 1) {
    return {
      kind: 'ambiguous',
      failureCode: 'ambiguous_ui',
      matchedSignatures: presentCodes([...loginMatches, ...productMatches, ...ordersMatches]),
      missingRequiredSignatures: [],
      candidateAdapterIds: [WASIM_UI_V1_ID],
    };
  }

  if (loginLooksValid) {
    return {
      kind: 'login_required',
      failureCode: 'authentication_required',
      matchedSignatures: presentCodes(loginMatches),
      missingRequiredSignatures: [],
      candidateAdapterIds: [WASIM_UI_V1_ID],
    };
  }

  if (productLooksValid) {
    return {
      kind: 'recognized',
      adapterId: WASIM_UI_V1_ID,
      uiVersion: WASIM_UI_V1_VERSION,
      purchaseCapable: true,
      reconcileCapable: false,
      matchedSignatures: presentCodes(productMatches),
      missingRequiredSignatures: [],
    };
  }

  if (ordersLooksValid) {
    return {
      kind: 'recognized',
      adapterId: WASIM_UI_V1_ID,
      uiVersion: WASIM_UI_V1_VERSION,
      purchaseCapable: false,
      reconcileCapable: true,
      matchedSignatures: presentCodes(ordersMatches),
      missingRequiredSignatures: [],
    };
  }

  // Partial product/orders signals without required set → ambiguous if conflicting, else unknown.
  const productSignalCount = productMatches.filter((m) => m.present && m.required).length;
  const ordersSignalCount = ordersMatches.filter((m) => m.present && m.required).length;
  const loginSignalCount = loginMatches.filter((m) => m.present && m.required).length;

  if (productSignalCount > 0 && ordersSignalCount > 0) {
    return {
      kind: 'ambiguous',
      failureCode: 'ambiguous_ui',
      matchedSignatures: presentCodes([...productMatches, ...ordersMatches]),
      missingRequiredSignatures: [...productRequiredMissing, ...ordersRequiredMissing],
      candidateAdapterIds: [WASIM_UI_V1_ID],
    };
  }

  if (productSignalCount > 0 && productRequiredMissing.length > 0) {
    return {
      kind: 'ambiguous',
      failureCode: 'ambiguous_ui',
      matchedSignatures: presentCodes(productMatches),
      missingRequiredSignatures: productRequiredMissing,
      candidateAdapterIds: [WASIM_UI_V1_ID],
    };
  }

  if (ordersSignalCount > 0 && ordersRequiredMissing.length > 0) {
    return {
      kind: 'ambiguous',
      failureCode: 'orders_ui_unsupported',
      matchedSignatures: presentCodes(ordersMatches),
      missingRequiredSignatures: ordersRequiredMissing,
      candidateAdapterIds: [WASIM_UI_V1_ID],
    };
  }

  if (loginSignalCount > 0 && loginRequiredMissing.length > 0) {
    return {
      kind: 'ambiguous',
      failureCode: 'ambiguous_ui',
      matchedSignatures: presentCodes(loginMatches),
      missingRequiredSignatures: loginRequiredMissing,
      candidateAdapterIds: [WASIM_UI_V1_ID],
    };
  }

  return {
    kind: 'unknown',
    failureCode: 'unsupported_ui',
    matchedSignatures: presentCodes([...loginMatches, ...productMatches, ...ordersMatches]),
    missingRequiredSignatures: [
      ...loginRequiredMissing,
      ...productRequiredMissing,
      ...ordersRequiredMissing,
    ],
    candidateAdapterIds: [],
  };
}
