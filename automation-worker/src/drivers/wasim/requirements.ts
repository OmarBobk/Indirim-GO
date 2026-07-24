export function resolvePlayerId(requirements: Record<string, unknown>): string | null {
  for (const key of ['id', 'playerId', 'player_id']) {
    const value = requirements[key];

    if (typeof value === 'string' && value.trim() !== '') {
      return value.trim();
    }

    if (typeof value === 'number' && Number.isFinite(value)) {
      return String(value);
    }
  }

  return null;
}
