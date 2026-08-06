export type DriverOutcome = 'success' | 'failed' | 'needs_review' | 'submitted' | 'pending_reconcile';

export type RunPayload = {
  run_uuid: string;
  fulfillment_id: number;
  supplier_key: string;
  driver: string;
  session_key: string;
  idempotency_reference: string;
  automation_phase?: 'purchase' | 'reconcile';
  supplier_order_id?: string | null;
  external_order_id?: string | null;
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
    progress?: string;
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

export type PriceScanItem = {
  product_id: number;
  product_api: string;
  amount_mode: string;
  reference_quantity?: number | null;
};

export type PriceScanPayload = {
  scan_uuid: string;
  supplier_key: string;
  driver: string;
  session_key: string;
  custom_reference_quantity: number;
  delay_ms_between_products: number;
  items: PriceScanItem[];
  credentials: Record<string, string | undefined>;
  callback_url: string;
  expires_at: string;
};

export type PriceScanItemResult = {
  product_id: number;
  ok: boolean;
  scanned_price?: number;
  displayed_raw?: string;
  error_code?: string;
  message?: string;
};

export type PriceScanCallbackBody = {
  status: 'completed' | 'failed';
  items: PriceScanItemResult[];
  error_code?: string;
  message?: string;
  log_excerpt: ScanLogLine[];
};

export type ScanLogLine = {
  id: number;
  step: string;
  level: 'info' | 'warn' | 'error';
  message: string;
  at: string;
  runUuid: string;
  scanUuid: string;
  ms?: number;
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
