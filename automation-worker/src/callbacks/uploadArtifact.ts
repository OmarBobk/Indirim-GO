import fs from 'node:fs';
import { extractRunUuidFromArtifactsUrl, signArtifactUpload } from '../auth/verifyLaravel.js';

export async function uploadArtifact(
  callbackUrl: string,
  secret: string,
  filePath: string,
  label: string,
): Promise<void> {
  const fileData = fs.readFileSync(filePath);
  const runUuid = extractRunUuidFromArtifactsUrl(callbackUrl);
  const { signature, timestamp } = signArtifactUpload(runUuid, label, fileData, secret);

  const formData = new FormData();
  formData.append('label', label);
  formData.append('file', new Blob([fileData], { type: 'image/png' }), `${label}.png`);

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
