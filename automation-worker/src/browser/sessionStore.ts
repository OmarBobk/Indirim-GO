import fs from 'node:fs';
import path from 'node:path';
import { workerStoragePath } from '../storage/workerPaths.js';

export function sessionStatePath(sessionKey: string): string {
  return workerStoragePath('sessions', sessionKey, 'storageState.json');
}

export function ensureSessionDir(sessionKey: string): void {
  const dir = path.dirname(sessionStatePath(sessionKey));
  fs.mkdirSync(dir, { recursive: true });
}

export function hasSessionState(sessionKey: string): boolean {
  return fs.existsSync(sessionStatePath(sessionKey));
}
