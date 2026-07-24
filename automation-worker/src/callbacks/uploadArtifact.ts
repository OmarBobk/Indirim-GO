import fs from 'node:fs';
import { extractRunUuidFromArtifactsUrl, signArtifactUpload } from '../auth/verifyLaravel.js';

export async function uploadArtifactBytes(
  callbackUrl: string,
  secret: string,
  fileData: Buffer | Uint8Array,
  label: string,
): Promise<void> {
  const buffer = Buffer.isBuffer(fileData) ? fileData : Buffer.from(fileData);
  const runUuid = extractRunUuidFromArtifactsUrl(callbackUrl);
  const { signature, timestamp } = signArtifactUpload(runUuid, label, buffer, secret);

  const formData = new FormData();
  formData.append('label', label);
  formData.append('file', new Blob([Uint8Array.from(buffer)], { type: 'image/png' }), `${label}.png`);

  const response = await fetch(callbackUrl, {
    method: 'POST',
    headers: {
      'X-Automation-Signature': signature,
      'X-Automation-Timestamp': timestamp,
    },
    body: formData,
  });

  if (!response.ok) {
    throw new Error(`Artifact upload failed with HTTP ${response.status} for ${label}`);
  }
}

/** @deprecated Prefer uploadArtifactBytes — reads from disk only for legacy callers. */
export async function uploadArtifact(
  callbackUrl: string,
  secret: string,
  filePath: string,
  label: string,
): Promise<void> {
  const fileData = fs.readFileSync(filePath);

  await uploadArtifactBytes(callbackUrl, secret, fileData, label);
}
