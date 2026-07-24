import type { LogLine } from '../types.js';

export class RunLogger {
  private readonly lines: LogLine[] = [];

  constructor(
    private readonly runUuid: string,
    private readonly fulfillmentId: number,
  ) {}

  log(step: string, message: string, level: LogLine['level'] = 'info', ms?: number): void {
    const line: LogLine = {
      id: this.lines.length + 1,
      step,
      level,
      message,
      at: new Date().toISOString(),
      runUuid: this.runUuid,
      fulfillmentId: this.fulfillmentId,
      ms,
    };

    this.lines.push(line);
    console.log(JSON.stringify(line));
  }

  excerpt(limit = 50): LogLine[] {
    return this.lines.slice(-limit);
  }
}
