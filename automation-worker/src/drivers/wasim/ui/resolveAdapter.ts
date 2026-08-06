import type { Page } from 'playwright';
import type { ProgressReporter } from '../../../progress/ProgressReporter.js';
import type { RunLogger } from '../../../logging/runLogger.js';
import { detectWasimUi } from './detect.js';
import type { WasimUiFailureCode } from './failures.js';
import { getWasimUiAdapter, defaultWasimUiAdapter } from './registry.js';
import type {
  SafeDiagnostics,
  WasimUiAdapter,
  WasimUiDetection,
} from './types.js';

export type ResolvedWasimUi =
  | {
    ok: true;
    detection: WasimUiDetection;
    adapter: WasimUiAdapter;
  }
  | {
    ok: false;
    failureCode: WasimUiFailureCode;
    detection: WasimUiDetection;
    diagnostics: SafeDiagnostics;
    adapter: WasimUiAdapter;
  };

/**
 * Detect UI and resolve the matching adapter. Unknown/ambiguous/maintenance/access_denied fail closed.
 */
export async function resolveWasimUiForPage(
  page: Page,
  logger: RunLogger,
  progress?: ProgressReporter,
  options?: { allowLoginRequired?: boolean },
): Promise<ResolvedWasimUi> {
  progress?.step('ui_detecting');
  logger.log('ui_detect', 'Detecting Wasim UI version');

  const detection = await detectWasimUi(page);
  const fallbackAdapter = defaultWasimUiAdapter();

  if (detection.kind === 'recognized') {
    const adapter = getWasimUiAdapter(detection.adapterId) ?? fallbackAdapter;
    progress?.setContractMeta(detection.uiVersion, adapter.purchaseContractVersion);
    progress?.step('ui_recognized', undefined, {
      ui_version: detection.uiVersion,
      adapter_id: detection.adapterId,
    });

    return { ok: true, detection, adapter };
  }

  if (detection.kind === 'login_required' && options?.allowLoginRequired !== false) {
    const adapter = fallbackAdapter;
    progress?.setContractMeta(adapter.uiVersion, adapter.purchaseContractVersion);
    progress?.step('ui_recognized', undefined, {
      ui_version: adapter.uiVersion,
      adapter_id: adapter.adapterId,
      auth: 'login_required',
    });

    return {
      ok: true,
      detection,
      adapter,
    };
  }

  const failureCode: WasimUiFailureCode = detection.failureCode;

  const diagnostics = await fallbackAdapter.collectSafeDiagnostics(page, failureCode);

  progress?.step('ui_unsupported', failureCode, {
    detection_kind: detection.kind,
  });

  logger.log('ui_detect', `Wasim UI not safe to continue: ${failureCode}`, 'warn');

  return {
    ok: false,
    failureCode,
    detection,
    diagnostics,
    adapter: fallbackAdapter,
  };
}
