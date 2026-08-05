import type { Page } from 'playwright';
import type { WasimUiFailureCode } from './failures.js';
import type {
  WASIM_ORDERS_CONTRACT_V1,
  WASIM_PURCHASE_CONTRACT_V1,
  WASIM_UI_V1_ID,
} from './versions.js';

export type WasimUiAdapterId = typeof WASIM_UI_V1_ID;
export type WasimPurchaseContractVersion = typeof WASIM_PURCHASE_CONTRACT_V1;
export type WasimOrdersContractVersion = typeof WASIM_ORDERS_CONTRACT_V1;

export type SignatureCode =
  | 'login_email_field'
  | 'login_password_field'
  | 'login_submit_arabic'
  | 'product_path'
  | 'product_player_id_field'
  | 'product_total_price_field'
  | 'product_buy_control'
  | 'orders_path'
  | 'orders_table'
  | 'orders_tab_new'
  | 'orders_tab_completed'
  | 'orders_tab_cancelled'
  | 'orders_reload'
  | 'maintenance_marker'
  | 'access_denied_marker';

export type SignatureMatch = {
  code: SignatureCode;
  present: boolean;
  required: boolean;
};

export type DetectionKind =
  | 'recognized'
  | 'login_required'
  | 'maintenance'
  | 'access_denied'
  | 'ambiguous'
  | 'unknown';

export type RecognizedUiDetection = {
  kind: 'recognized';
  adapterId: WasimUiAdapterId;
  uiVersion: string;
  purchaseCapable: boolean;
  reconcileCapable: boolean;
  matchedSignatures: SignatureCode[];
  missingRequiredSignatures: SignatureCode[];
};

export type NonRecognizedUiDetection = {
  kind: Exclude<DetectionKind, 'recognized'>;
  failureCode: WasimUiFailureCode;
  matchedSignatures: SignatureCode[];
  missingRequiredSignatures: SignatureCode[];
  candidateAdapterIds: WasimUiAdapterId[];
};

export type WasimUiDetection = RecognizedUiDetection | NonRecognizedUiDetection;

export type AuthState =
  | 'authenticated'
  | 'login_required'
  | 'login_in_progress'
  | 'authentication_failed'
  | 'access_denied'
  | 'maintenance'
  | 'unknown_ui';

export type NormalizedRequirementKey =
  | 'player_id'
  | 'server_id'
  | 'account_identifier'
  | 'email'
  | 'phone'
  | 'custom_identifier';

export type ProductIdentityReport = {
  ok: boolean;
  failureCode?: WasimUiFailureCode;
  signals: string[];
};

export type RequirementsContractReport = {
  ok: boolean;
  failureCode?: WasimUiFailureCode;
  foundFields: NormalizedRequirementKey[];
  missingFields: NormalizedRequirementKey[];
};

export type PriceReadResult =
  | { ok: true; amountDecimal: string; displayedRaw: string }
  | { ok: false; failureCode: WasimUiFailureCode; displayedRaw?: string };

export type PreSubmitContractReport = {
  ok: boolean;
  failureCode?: WasimUiFailureCode;
  checks: Record<string, boolean>;
};

export type SupplierSubmissionInterpretation =
  | 'accepted'
  | 'rejected'
  | 'processing'
  | 'validation_error'
  | 'insufficient_supplier_balance'
  | 'price_changed'
  | 'duplicate_or_already_submitted'
  | 'authentication_required'
  | 'maintenance'
  | 'unknown_response'
  | 'uncertain_submission';

export type SupplierOrderStatusInterpretation =
  | 'new'
  | 'processing'
  | 'completed'
  | 'cancelled'
  | 'not_found'
  | 'duplicate_match'
  | 'unknown_status'
  | 'authentication_required'
  | 'maintenance'
  | 'unknown_ui';

export type SafeDiagnostics = {
  path: string | null;
  hostname: string | null;
  adapter_candidates: string[];
  matched_signature_codes: SignatureCode[];
  missing_required_signature_codes: SignatureCode[];
  failure_code: WasimUiFailureCode | null;
  driver_version: string;
  ui_version: string | null;
  purchase_contract_version: string | null;
  orders_contract_version: string | null;
  checked_at: string;
};

export type WasimUiAdapterCapabilities = {
  purchase: boolean;
  reconcile: boolean;
  priceScan: boolean;
};

/**
 * Focused Wasim UI adapter — selector knowledge stays inside implementations.
 * No generic click/locator/executeSelector surface.
 */
export type WasimUiAdapter = {
  readonly adapterId: WasimUiAdapterId;
  readonly uiVersion: string;
  readonly driverVersion: string;
  readonly purchaseContractVersion: WasimPurchaseContractVersion;
  readonly ordersContractVersion: WasimOrdersContractVersion;
  readonly capabilities: WasimUiAdapterCapabilities;

  detectPage(page: Page): Promise<WasimUiDetection>;

  collectSafeDiagnostics(
    page: Page,
    failureCode?: WasimUiFailureCode | null,
  ): Promise<SafeDiagnostics>;

  countVisible(page: Page, signature: SignatureCode): Promise<number>;

  isLoginFormVisible(page: Page, timeoutMs?: number): Promise<boolean>;

  submitLogin(page: Page, username: string, password: string): Promise<void>;

  readAuthValidationText(page: Page): Promise<string | null>;

  validateProductIdentity(
    page: Page,
    expectedProductApi: string,
    expectedProductUrl: string,
  ): Promise<ProductIdentityReport>;

  validateRequirementsContract(
    page: Page,
    required: NormalizedRequirementKey[],
  ): Promise<RequirementsContractReport>;

  fillRequirement(
    page: Page,
    key: NormalizedRequirementKey,
    value: string,
  ): Promise<{ ok: true } | { ok: false; failureCode: WasimUiFailureCode }>;

  readSupplierPrice(page: Page): Promise<PriceReadResult>;

  validatePreSubmit(page: Page, expectedProductUrl: string): Promise<PreSubmitContractReport>;

  locateUniqueSubmitControl(
    page: Page,
  ): Promise<
    | { ok: true }
    | { ok: false; failureCode: WasimUiFailureCode }
  >;

  clickSubmitOnce(page: Page): Promise<void>;

  waitForSubmissionDialog(page: Page, timeoutMs: number): Promise<boolean>;

  readSubmissionHtml(page: Page): Promise<string>;

  dismissSubmissionDialog(page: Page): Promise<void>;

  validateOrdersContract(page: Page): Promise<PreSubmitContractReport>;
};
