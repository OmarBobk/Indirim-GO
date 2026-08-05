import { wasimUiV1Adapter } from './adapters/wasimUiV1.js';
import type { WasimUiAdapter, WasimUiAdapterId } from './types.js';
import { WASIM_UI_V1_ID } from './versions.js';

/**
 * Ordered deterministic adapter registry.
 * Detection must not be first-match on a single selector; see detectWasimUi().
 * No browser/Laravel request may choose an arbitrary adapter.
 */
const ADAPTERS: WasimUiAdapter[] = [
  wasimUiV1Adapter,
];

export function listWasimUiAdapters(): WasimUiAdapter[] {
  return [...ADAPTERS];
}

export function getWasimUiAdapter(adapterId: WasimUiAdapterId): WasimUiAdapter | null {
  return ADAPTERS.find((adapter) => adapter.adapterId === adapterId) ?? null;
}

export function resolveWasimUiAdapter(adapterId: string): WasimUiAdapter | null {
  if (adapterId === WASIM_UI_V1_ID) {
    return wasimUiV1Adapter;
  }

  return null;
}

export function defaultWasimUiAdapter(): WasimUiAdapter {
  return wasimUiV1Adapter;
}
