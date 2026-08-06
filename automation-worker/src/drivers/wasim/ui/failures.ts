/**
 * Allowlisted typed UI / contract failure codes (C1.2).
 * Safe for progress, probe results, and admin copy — never include selectors.
 */
export const WASIM_UI_FAILURE_CODES = [
  'unsupported_ui',
  'ambiguous_ui',
  'maintenance',
  'access_denied',

  'authentication_required',
  'authentication_failed',
  'authenticated_contract_failed',

  'product_not_found',
  'product_identity_mismatch',
  'product_identity_ambiguous',

  'required_field_missing',
  'required_field_ambiguous',
  'unsupported_required_field',
  'requirements_contract_failed',

  'supplier_price_missing',
  'supplier_price_parse_failed',
  'supplier_price_conflict',
  'supplier_price_outside_tolerance',

  'submit_control_missing',
  'submit_control_ambiguous',
  'pre_submit_contract_failed',
  'uncertain_submission',
  'unknown_supplier_response',
  'duplicate_submission_warning',

  'orders_ui_unsupported',
  'supplier_order_duplicate_match',
  'supplier_order_unknown_status',
  'orders_contract_failed',

  'probe_not_configured',
  'probe_unreachable',
] as const;

export type WasimUiFailureCode = (typeof WASIM_UI_FAILURE_CODES)[number];

export function isWasimUiFailureCode(value: string): value is WasimUiFailureCode {
  return (WASIM_UI_FAILURE_CODES as readonly string[]).includes(value);
}

/**
 * C1.3 signal classification metadata (documentation for circuits — not enforced here).
 */
export type CircuitSignalHint = {
  scope:
    | 'order'
    | 'product'
    | 'purchase_ui'
    | 'reconcile_ui'
    | 'authentication'
    | 'worker'
    | 'price_scan';
  severity: 'info' | 'warn' | 'critical';
  immediatePauseCandidate: boolean;
  thresholdPauseCandidate: boolean;
  noCircuit: boolean;
  probeRequiredBeforeResume: boolean;
  manualResumeRequired: boolean;
};

export const FAILURE_CIRCUIT_HINTS: Record<WasimUiFailureCode, CircuitSignalHint> = {
  unsupported_ui: {
    scope: 'purchase_ui',
    severity: 'critical',
    immediatePauseCandidate: true,
    thresholdPauseCandidate: false,
    noCircuit: false,
    probeRequiredBeforeResume: true,
    manualResumeRequired: true,
  },
  ambiguous_ui: {
    scope: 'purchase_ui',
    severity: 'critical',
    immediatePauseCandidate: true,
    thresholdPauseCandidate: false,
    noCircuit: false,
    probeRequiredBeforeResume: true,
    manualResumeRequired: true,
  },
  maintenance: {
    scope: 'purchase_ui',
    severity: 'warn',
    immediatePauseCandidate: false,
    thresholdPauseCandidate: true,
    noCircuit: false,
    probeRequiredBeforeResume: true,
    manualResumeRequired: false,
  },
  access_denied: {
    scope: 'authentication',
    severity: 'critical',
    immediatePauseCandidate: true,
    thresholdPauseCandidate: false,
    noCircuit: false,
    probeRequiredBeforeResume: true,
    manualResumeRequired: true,
  },
  authentication_required: {
    scope: 'authentication',
    severity: 'warn',
    immediatePauseCandidate: false,
    thresholdPauseCandidate: true,
    noCircuit: false,
    probeRequiredBeforeResume: true,
    manualResumeRequired: false,
  },
  authentication_failed: {
    scope: 'authentication',
    severity: 'critical',
    immediatePauseCandidate: false,
    thresholdPauseCandidate: true,
    noCircuit: false,
    probeRequiredBeforeResume: true,
    manualResumeRequired: true,
  },
  authenticated_contract_failed: {
    scope: 'authentication',
    severity: 'critical',
    immediatePauseCandidate: true,
    thresholdPauseCandidate: false,
    noCircuit: false,
    probeRequiredBeforeResume: true,
    manualResumeRequired: true,
  },
  product_not_found: {
    scope: 'product',
    severity: 'warn',
    immediatePauseCandidate: false,
    thresholdPauseCandidate: true,
    noCircuit: false,
    probeRequiredBeforeResume: false,
    manualResumeRequired: false,
  },
  product_identity_mismatch: {
    scope: 'product',
    severity: 'critical',
    immediatePauseCandidate: false,
    thresholdPauseCandidate: true,
    noCircuit: false,
    probeRequiredBeforeResume: false,
    manualResumeRequired: true,
  },
  product_identity_ambiguous: {
    scope: 'product',
    severity: 'critical',
    immediatePauseCandidate: false,
    thresholdPauseCandidate: true,
    noCircuit: false,
    probeRequiredBeforeResume: false,
    manualResumeRequired: true,
  },
  required_field_missing: {
    scope: 'order',
    severity: 'warn',
    immediatePauseCandidate: false,
    thresholdPauseCandidate: false,
    noCircuit: true,
    probeRequiredBeforeResume: false,
    manualResumeRequired: false,
  },
  required_field_ambiguous: {
    scope: 'purchase_ui',
    severity: 'critical',
    immediatePauseCandidate: true,
    thresholdPauseCandidate: false,
    noCircuit: false,
    probeRequiredBeforeResume: true,
    manualResumeRequired: true,
  },
  unsupported_required_field: {
    scope: 'purchase_ui',
    severity: 'critical',
    immediatePauseCandidate: true,
    thresholdPauseCandidate: false,
    noCircuit: false,
    probeRequiredBeforeResume: true,
    manualResumeRequired: true,
  },
  requirements_contract_failed: {
    scope: 'purchase_ui',
    severity: 'critical',
    immediatePauseCandidate: true,
    thresholdPauseCandidate: false,
    noCircuit: false,
    probeRequiredBeforeResume: true,
    manualResumeRequired: true,
  },
  supplier_price_missing: {
    scope: 'product',
    severity: 'warn',
    immediatePauseCandidate: false,
    thresholdPauseCandidate: true,
    noCircuit: false,
    probeRequiredBeforeResume: true,
    manualResumeRequired: false,
  },
  supplier_price_parse_failed: {
    scope: 'purchase_ui',
    severity: 'warn',
    immediatePauseCandidate: false,
    thresholdPauseCandidate: true,
    noCircuit: false,
    probeRequiredBeforeResume: true,
    manualResumeRequired: false,
  },
  supplier_price_conflict: {
    scope: 'product',
    severity: 'critical',
    immediatePauseCandidate: false,
    thresholdPauseCandidate: true,
    noCircuit: false,
    probeRequiredBeforeResume: true,
    manualResumeRequired: true,
  },
  supplier_price_outside_tolerance: {
    scope: 'order',
    severity: 'warn',
    immediatePauseCandidate: false,
    thresholdPauseCandidate: false,
    noCircuit: true,
    probeRequiredBeforeResume: false,
    manualResumeRequired: false,
  },
  submit_control_missing: {
    scope: 'purchase_ui',
    severity: 'critical',
    immediatePauseCandidate: true,
    thresholdPauseCandidate: false,
    noCircuit: false,
    probeRequiredBeforeResume: true,
    manualResumeRequired: true,
  },
  submit_control_ambiguous: {
    scope: 'purchase_ui',
    severity: 'critical',
    immediatePauseCandidate: true,
    thresholdPauseCandidate: false,
    noCircuit: false,
    probeRequiredBeforeResume: true,
    manualResumeRequired: true,
  },
  pre_submit_contract_failed: {
    scope: 'purchase_ui',
    severity: 'critical',
    immediatePauseCandidate: true,
    thresholdPauseCandidate: false,
    noCircuit: false,
    probeRequiredBeforeResume: true,
    manualResumeRequired: true,
  },
  uncertain_submission: {
    scope: 'order',
    severity: 'critical',
    immediatePauseCandidate: false,
    thresholdPauseCandidate: false,
    noCircuit: true,
    probeRequiredBeforeResume: false,
    manualResumeRequired: true,
  },
  unknown_supplier_response: {
    scope: 'order',
    severity: 'critical',
    immediatePauseCandidate: false,
    thresholdPauseCandidate: false,
    noCircuit: true,
    probeRequiredBeforeResume: false,
    manualResumeRequired: true,
  },
  duplicate_submission_warning: {
    scope: 'order',
    severity: 'critical',
    immediatePauseCandidate: false,
    thresholdPauseCandidate: false,
    noCircuit: true,
    probeRequiredBeforeResume: false,
    manualResumeRequired: true,
  },
  orders_ui_unsupported: {
    scope: 'reconcile_ui',
    severity: 'critical',
    immediatePauseCandidate: true,
    thresholdPauseCandidate: false,
    noCircuit: false,
    probeRequiredBeforeResume: true,
    manualResumeRequired: true,
  },
  supplier_order_duplicate_match: {
    scope: 'order',
    severity: 'critical',
    immediatePauseCandidate: false,
    thresholdPauseCandidate: false,
    noCircuit: true,
    probeRequiredBeforeResume: false,
    manualResumeRequired: true,
  },
  supplier_order_unknown_status: {
    scope: 'order',
    severity: 'critical',
    immediatePauseCandidate: false,
    thresholdPauseCandidate: false,
    noCircuit: true,
    probeRequiredBeforeResume: false,
    manualResumeRequired: true,
  },
  orders_contract_failed: {
    scope: 'reconcile_ui',
    severity: 'critical',
    immediatePauseCandidate: true,
    thresholdPauseCandidate: false,
    noCircuit: false,
    probeRequiredBeforeResume: true,
    manualResumeRequired: true,
  },
  probe_not_configured: {
    scope: 'worker',
    severity: 'info',
    immediatePauseCandidate: false,
    thresholdPauseCandidate: false,
    noCircuit: true,
    probeRequiredBeforeResume: false,
    manualResumeRequired: false,
  },
  probe_unreachable: {
    scope: 'worker',
    severity: 'warn',
    immediatePauseCandidate: false,
    thresholdPauseCandidate: true,
    noCircuit: false,
    probeRequiredBeforeResume: false,
    manualResumeRequired: false,
  },
};
