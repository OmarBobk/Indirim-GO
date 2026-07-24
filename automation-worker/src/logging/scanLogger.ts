import type { LogLine } from '../types.js';

export type ScanLogLine = Omit<LogLine, 'fulfillmentId'> & {
  scanUuid: string;
};

export class ScanLogger {
  private readonly lines: ScanLogLine[] = [];

  constructor(private readonly scanUuid: string) {}

  log(step: string, message: string, level: ScanLogLine['level'] = 'info', ms?: number): void {
    const line: ScanLogLine = {
      id: this.lines.length + 1,
      step,
      level,
      message,
      at: new Date().toISOString(),
      runUuid: this.scanUuid,
      scanUuid: this.scanUuid,
      ms,
    };

    this.lines.push(line);
    console.log(JSON.stringify(line));
  }

  excerpt(limit = 100): ScanLogLine[] {
    return this.lines.slice(-limit);
  }
}
