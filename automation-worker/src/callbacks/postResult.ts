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

export async function uploadArtifact(
  artifactsUrl: string,
  secret: string,
  buffer: Buffer,
  filename: string,
  label: string,
): Promise<void> {
  const form = new FormData();
  form.append('label', label);
  form.append('file', new Blob([buffer]), filename);

  const bodyBuffer = Buffer.from(await form.arrayBuffer());
  const body = bodyBuffer.toString('binary');
  const { signature, timestamp } = signCallbackBody(
    JSON.stringify({ label, filename }),
    secret,
  );

  const response = await fetch(artifactsUrl, {
    method: 'POST',
    headers: {
      'X-Automation-Signature': signature,
      'X-Automation-Timestamp': timestamp,
    },
    body: form,
  });

  if (!response.ok) {
    throw new Error(`Artifact upload failed with HTTP ${response.status}`);
  }
}
