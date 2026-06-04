import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const workerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

export function workerStoragePath(...segments: string[]): string {
  const target = path.join(workerRoot, 'storage', ...segments);
  fs.mkdirSync(path.dirname(target), { recursive: true });

  return target;
}

/**
 * Remove on-disk screenshots from older worker versions (Laravel is the only store now).
 */
export function removeLegacyWorkerScreenshotsDir(runUuid: string): void {
  const dir = path.join(workerRoot, 'storage', 'screenshots', runUuid);

  if (!fs.existsSync(dir)) {
    return;
  }

  fs.rmSync(dir, { recursive: true, force: true });
}
