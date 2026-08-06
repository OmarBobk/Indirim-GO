import { randomUUID } from 'node:crypto';

/** Stable per-process identifier so Laravel can tell worker instances apart across restarts. */
export const WORKER_INSTANCE_ID = randomUUID();

export const WORKER_STARTED_AT_MS = Date.now();

let activeCount = 0;
let lastSuccessfulTaskAt: Date | null = null;

export function incrementActiveCount(): void {
  activeCount += 1;
}

export function decrementActiveCount(): void {
  activeCount = Math.max(0, activeCount - 1);
}

export function getActiveCount(): number {
  return activeCount;
}

export function markSuccessfulTask(): void {
  lastSuccessfulTaskAt = new Date();
}

export function getLastSuccessfulTaskAt(): Date | null {
  return lastSuccessfulTaskAt;
}

export function getUptimeSeconds(): number {
  return Math.floor((Date.now() - WORKER_STARTED_AT_MS) / 1000);
}
