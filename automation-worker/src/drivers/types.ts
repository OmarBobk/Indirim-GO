import type { Page } from 'playwright';
import type { DriverResult, RunPayload } from '../types.js';
import type { RunLogger } from '../logging/runLogger.js';
import type { ProgressReporter } from '../progress/ProgressReporter.js';

export type DriverContext = {
  page: Page;
  payload: RunPayload;
  logger: RunLogger;
  screenshot: (label: string) => Promise<void>;
  progress?: ProgressReporter;
};

export type RunDriver = {
  supplierKey: string;
  execute: (ctx: DriverContext) => Promise<DriverResult>;
};
