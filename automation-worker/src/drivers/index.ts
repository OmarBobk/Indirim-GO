import type { RunDriver } from './types.js';
import { acmeDriver } from './acme/index.js';

const drivers: Record<string, RunDriver> = {
  acme: acmeDriver,
};

export function resolveDriver(driverKey: string): RunDriver | null {
  return drivers[driverKey] ?? null;
}
