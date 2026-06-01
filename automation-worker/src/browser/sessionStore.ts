import fs from 'node:fs';
import path from 'node:path';

const sessionsRoot = path.resolve('storage/sessions');

export function sessionStatePath(sessionKey: string): string {
  return path.join(sessionsRoot, sessionKey, 'storageState.json');
}

export function ensureSessionDir(sessionKey: string): void {
  const dir = path.join(sessionsRoot, sessionKey);
  fs.mkdirSync(dir, { recursive: true });
}

export function hasSessionState(sessionKey: string): boolean {
  return fs.existsSync(sessionStatePath(sessionKey));
}
