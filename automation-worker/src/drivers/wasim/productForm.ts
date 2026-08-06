import type { Page } from 'playwright';
import type { RunPayload } from '../../types.js';
import type { RunLogger } from '../../logging/runLogger.js';
import type { ProgressReporter } from '../../progress/ProgressReporter.js';
import { resolvePlayerId } from './requirements.js';

export function isFixedQuantityMode(payload: RunPayload): boolean {
  const mode = payload.product_amount_mode?.trim().toLowerCase();

  return mode === undefined || mode === '' || mode === 'fixed';
}

export async function fillPlayerId(
  page: Page,
  payload: RunPayload,
  logger: RunLogger,
  screenshot: (label: string) => Promise<void>,
  progress?: ProgressReporter,
): Promise<{ ok: true; playerId: string } | { ok: false; errorCode: string; message: string }> {
  const playerId = resolvePlayerId(payload.requirements ?? {});

  if (playerId === null) {
    return {
      ok: false,
      errorCode: 'requirements_missing_player_id',
      message: 'Product requires a player id in order requirements (key: id).',
    };
  }

  progress?.step('filling_requirements');

  const playerIdField = page.locator(
    '#product-request-playrid, input[name="playerId"], input[placeholder="معرف اللاعب"]',
  ).first();

  try {
    await playerIdField.waitFor({ state: 'visible', timeout: 15_000 });
  } catch {
    await screenshot('player_id_field_missing');

    return {
      ok: false,
      errorCode: 'player_id_field_missing',
      message: 'Wasim product page did not show the player id input field.',
    };
  }

  logger.log('fill_player_id', `Entering player id ${playerId}`);

  await playerIdField.fill(playerId);
  await screenshot('player_id_filled');
  progress?.step('requirements_filled');

  return {
    ok: true,
    playerId,
  };
}
