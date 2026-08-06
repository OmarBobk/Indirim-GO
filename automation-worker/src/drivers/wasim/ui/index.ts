export { detectWasimUi } from './detect.js';
export { resolveWasimUiForPage } from './resolveAdapter.js';
export { listWasimUiAdapters, getWasimUiAdapter, defaultWasimUiAdapter } from './registry.js';
export { wasimUiV1Adapter } from './adapters/wasimUiV1.js';
export {
  WASIM_DRIVER_VERSION,
  WASIM_UI_V1_ID,
  WASIM_UI_V1_VERSION,
  WASIM_PURCHASE_CONTRACT_V1,
  WASIM_ORDERS_CONTRACT_V1,
} from './versions.js';
export { WASIM_UI_FAILURE_CODES, FAILURE_CIRCUIT_HINTS, isWasimUiFailureCode } from './failures.js';
export type {
  WasimUiAdapter,
  WasimUiDetection,
  SafeDiagnostics,
  PreSubmitContractReport,
  NormalizedRequirementKey,
} from './types.js';
