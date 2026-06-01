import type { RunDriver } from './types.js';
import { acmeDriver } from './acme/index.js';
import { wasimDriver } from './wasim/index.js';

const drivers: Record<string, RunDriver> = {
  wasim: wasimDriver,
  acme: acmeDriver,
};

export function resolveDriver(driverKey: string): RunDriver | null {
  return drivers[driverKey] ?? null;
}
