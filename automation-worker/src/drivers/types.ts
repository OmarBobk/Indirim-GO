import type { Page } from 'playwright';
import type { DriverResult, RunPayload } from '../types.js';
import type { RunLogger } from '../logging/runLogger.js';

export type DriverContext = {
  page: Page;
  payload: RunPayload;
  logger: RunLogger;
  screenshot: (label: string) => Promise<void>;
};

export type RunDriver = {
  supplierKey: string;
  execute: (ctx: DriverContext) => Promise<DriverResult>;
};
