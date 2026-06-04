export type DriverOutcome = 'success' | 'failed' | 'needs_review';

export type RunPayload = {
  run_uuid: string;
  fulfillment_id: number;
  supplier_key: string;
  driver: string;
  session_key: string;
  idempotency_reference: string;
  requirements: Record<string, unknown>;
  custom_amount: { amount?: number; unit?: string } | null;
  product_slug?: string | null;
  package_slug?: string | null;
  package_api?: string | null;
  product_api?: string | null;
  product_amount_mode?: string | null;
  unit_price?: number | null;
  line_total?: number | null;
  credentials: Record<string, string | undefined>;
  callback_urls: {
    result: string;
    artifacts: string;
  };
  expires_at: string;
};

export type DriverResult = {
  outcome: DriverOutcome;
  externalOrderId?: string;
  deliveredPayload?: Record<string, unknown>;
  errorCode?: string;
  message?: string;
};

export type LogLine = {
  id: number;
  step: string;
  level: 'info' | 'warn' | 'error';
  message: string;
  at: string;
  runUuid: string;
  fulfillmentId: number;
  ms?: number;
};
