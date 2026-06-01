import type { LogLine } from '../types.js';

export class RunLogger {
  private readonly lines: LogLine[] = [];

  constructor(
    private readonly runUuid: string,
    private readonly fulfillmentId: number,
  ) {}

  log(step: string, message: string, level: LogLine['level'] = 'info', ms?: number): void {
    const line: LogLine = {
      runUuid: this.runUuid,
      fulfillmentId: this.fulfillmentId,
      step,
      level,
      message,
      ms,
    };

    this.lines.push(line);
    console.log(JSON.stringify(line));
  }

  excerpt(limit = 50): LogLine[] {
    return this.lines.slice(-limit);
  }
}
