import { signCallbackBody } from '../auth/verifyLaravel.js';
import type { DriverResult, LogLine } from '../types.js';

export async function postResult(
  callbackUrl: string,
  secret: string,
  result: DriverResult,
  logExcerpt: LogLine[],
): Promise<void> {
  const body = JSON.stringify({
    outcome: result.outcome === 'success' ? 'success' : result.outcome,
    external_order_id: result.externalOrderId ?? null,
    delivered_payload: result.deliveredPayload ?? null,
    error_code: result.errorCode ?? null,
    message: result.message ?? null,
    log_excerpt: logExcerpt,
  });

  const { signature, timestamp } = signCallbackBody(body, secret);

  const response = await fetch(callbackUrl, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Automation-Signature': signature,
      'X-Automation-Timestamp': timestamp,
    },
    body,
  });

  if (!response.ok) {
    throw new Error(`Callback failed with HTTP ${response.status}`);
  }
}
