import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { workerStoragePath } from '../storage/workerPaths.js';

export function sessionStatePath(sessionKey: string): string {
  return workerStoragePath('sessions', sessionKey, 'storageState.json');
}

function sessionCredentialFingerprintPath(sessionKey: string): string {
  return workerStoragePath('sessions', sessionKey, 'credentialFingerprint.txt');
}

export function ensureSessionDir(sessionKey: string): void {
  const dir = path.dirname(sessionStatePath(sessionKey));
  fs.mkdirSync(dir, { recursive: true });
}

export function hasSessionState(sessionKey: string): boolean {
  return fs.existsSync(sessionStatePath(sessionKey));
}

export function credentialFingerprint(username?: string, password?: string): string {
  return crypto
    .createHash('sha256')
    .update(`${username ?? ''}\0${password ?? ''}`)
    .digest('hex');
}

function readSessionCredentialFingerprint(sessionKey: string): string | null {
  const pathToFile = sessionCredentialFingerprintPath(sessionKey);

  if (!fs.existsSync(pathToFile)) {
    return null;
  }

  const value = fs.readFileSync(pathToFile, 'utf8').trim();

  return value === '' ? null : value;
}

export function writeSessionCredentialFingerprint(
  sessionKey: string,
  username?: string,
  password?: string,
): void {
  ensureSessionDir(sessionKey);
  fs.writeFileSync(sessionCredentialFingerprintPath(sessionKey), credentialFingerprint(username, password));
}

export function clearSessionState(sessionKey: string): boolean {
  let cleared = false;

  for (const filePath of [sessionStatePath(sessionKey), sessionCredentialFingerprintPath(sessionKey)]) {
    if (fs.existsSync(filePath)) {
      fs.rmSync(filePath, { force: true });
      cleared = true;
    }
  }

  return cleared;
}

export function ensureSessionMatchesCredentials(
  sessionKey: string,
  username?: string,
  password?: string,
): boolean {
  if (!hasSessionState(sessionKey)) {
    return false;
  }

  const expected = credentialFingerprint(username, password);
  const stored = readSessionCredentialFingerprint(sessionKey);

  if (stored !== null && stored === expected) {
    return false;
  }

  clearSessionState(sessionKey);

  return true;
}
