import { signCallbackBody } from '../auth/verifyLaravel.js';
import type { PriceScanCallbackBody, ScanLogLine } from '../types.js';

export async function postPriceScanResult(
  callbackUrl: string,
  secret: string,
  result: Omit<PriceScanCallbackBody, 'log_excerpt'>,
  logExcerpt: ScanLogLine[],
): Promise<void> {
  const body = JSON.stringify({
    ...result,
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
    throw new Error(`Price scan callback failed with HTTP ${response.status}`);
  }
}
