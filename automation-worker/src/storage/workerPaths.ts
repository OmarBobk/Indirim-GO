import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const workerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

export function workerStoragePath(...segments: string[]): string {
  const target = path.join(workerRoot, 'storage', ...segments);
  fs.mkdirSync(path.dirname(target), { recursive: true });

  return target;
}

export function workerScreenshotsDir(runUuid: string): string {
  const dir = workerStoragePath('screenshots', runUuid);
  fs.mkdirSync(dir, { recursive: true });

  return dir;
}
