import crypto from 'node:crypto';

export function verifyLaravelRequest(
  rawBody: string,
  signatureHeader: string,
  timestampHeader: string,
  secret: string,
  skewSeconds = 300,
): boolean {
  if (!secret || !signatureHeader || !timestampHeader) {
    return false;
  }

  const timestamp = Number(timestampHeader);

  if (!Number.isFinite(timestamp)) {
    return false;
  }

  if (Math.abs(Math.floor(Date.now() / 1000) - timestamp) > skewSeconds) {
    return false;
  }

  const expected = crypto
    .createHmac('sha256', secret)
    .update(`${timestamp}.${rawBody}`)
    .digest('hex');

  const provided = signatureHeader.startsWith('sha256=')
    ? signatureHeader.slice(7)
    : signatureHeader;

  try {
    return crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(provided));
  } catch {
    return false;
  }
}

export function signCallbackBody(rawBody: string, secret: string): { signature: string; timestamp: string } {
  const timestamp = String(Math.floor(Date.now() / 1000));
  const signature = crypto
    .createHmac('sha256', secret)
    .update(`${timestamp}.${rawBody}`)
    .digest('hex');

  return {
    timestamp,
    signature: `sha256=${signature}`,
  };
}
