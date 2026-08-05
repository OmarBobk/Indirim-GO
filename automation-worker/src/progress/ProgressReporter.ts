import { signCallbackBody } from '../auth/verifyLaravel.js';
import type { ProgressStep } from './steps.js';

const DEFAULT_HEARTBEAT_SECONDS = 15;

export type ProgressReporterOptions = {
  progressUrl: string | null | undefined;
  secret: string;
  phase: 'purchase' | 'reconcile';
  workerInstanceId: string;
  workerBuild: string;
  driverName: string;
  driverVersion: string;
  sessionAlias: string;
  heartbeatIntervalMs?: number;
};

export type ProgressDiagnostics = {
  progress_failures: number;
  last_step: ProgressStep | null;
  sequence: number;
};

/**
 * Fire-and-forget progress beacon for a single automation run. Never throws —
 * a failing progress callback must not affect the underlying automation.
 */
export class ProgressReporter {
  private readonly progressUrl: string | null;
  private readonly secret: string;
  private readonly phase: 'purchase' | 'reconcile';
  private readonly workerInstanceId: string;
  private readonly workerBuild: string;
  private readonly driverName: string;
  private readonly driverVersion: string;
  private readonly sessionAlias: string;
  private readonly heartbeatIntervalMs: number;

  private sequence = 0;
  private failures = 0;
  private lastStep: ProgressStep | null = null;
  private heartbeatTimer: ReturnType<typeof setInterval> | null = null;
  private detectedUiVersion: string | null = null;
  private pageContractVersion: string | null = null;

  constructor(options: ProgressReporterOptions) {
    this.progressUrl = options.progressUrl?.trim() || null;
    this.secret = options.secret;
    this.phase = options.phase;
    this.workerInstanceId = options.workerInstanceId;
    this.workerBuild = options.workerBuild;
    this.driverName = options.driverName;
    this.driverVersion = options.driverVersion;
    this.sessionAlias = options.sessionAlias;
    this.heartbeatIntervalMs = options.heartbeatIntervalMs
      ?? Number(process.env.FULFILLMENT_AUTOMATION_PROGRESS_HEARTBEAT_SECONDS ?? DEFAULT_HEARTBEAT_SECONDS) * 1000;
  }

  /** Code-owned UI / contract versions once detection succeeds (C1.2). */
  setContractMeta(detectedUiVersion: string | null, pageContractVersion: string | null): void {
    this.detectedUiVersion = detectedUiVersion;
    this.pageContractVersion = pageContractVersion;
  }

  /** Non-blocking: callers should not await this. */
  step(step: ProgressStep, safeMessageCode?: string, safeParams?: Record<string, unknown>): void {
    this.lastStep = step;
    const sequence = this.nextSequence();

    void this.send(step, sequence, false, safeMessageCode, safeParams);
  }

  /** Non-blocking: re-emits the last step with heartbeat:true. */
  heartbeat(): void {
    if (this.lastStep === null) {
      return;
    }

    const sequence = this.nextSequence();

    void this.send(this.lastStep, sequence, true);
  }

  startHeartbeat(): void {
    if (this.heartbeatTimer !== null) {
      return;
    }

    this.heartbeatTimer = setInterval(() => this.heartbeat(), this.heartbeatIntervalMs);
    this.heartbeatTimer.unref();
  }

  stop(): void {
    if (this.heartbeatTimer !== null) {
      clearInterval(this.heartbeatTimer);
      this.heartbeatTimer = null;
    }
  }

  getDiagnostics(): ProgressDiagnostics {
    return {
      progress_failures: this.failures,
      last_step: this.lastStep,
      sequence: this.sequence,
    };
  }

  private nextSequence(): number {
    this.sequence += 1;

    return this.sequence;
  }

  private async send(
    step: ProgressStep,
    sequence: number,
    heartbeat: boolean,
    safeMessageCode?: string,
    safeParams?: Record<string, unknown>,
  ): Promise<void> {
    if (this.progressUrl === null || this.secret === '') {
      return;
    }

    const body = JSON.stringify({
      progress_sequence: sequence,
      phase: this.phase,
      step,
      emitted_at: new Date().toISOString(),
      heartbeat,
      safe_message_code: safeMessageCode ?? null,
      safe_params: safeParams ?? null,
      worker_instance_id: this.workerInstanceId,
      worker_build: this.workerBuild,
      driver_name: this.driverName,
      driver_version: this.driverVersion,
      detected_ui_version: this.detectedUiVersion,
      page_contract_version: this.pageContractVersion,
      session_alias: this.sessionAlias,
    });

    try {
      const { signature, timestamp } = signCallbackBody(body, this.secret);

      const response = await fetch(this.progressUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Automation-Signature': signature,
          'X-Automation-Timestamp': timestamp,
        },
        body,
      });

      if (!response.ok) {
        throw new Error(`Progress callback failed with HTTP ${response.status}`);
      }
    } catch (error) {
      this.failures += 1;
      const message = error instanceof Error ? error.message : 'Progress callback failed';

      console.warn(JSON.stringify({
        event: 'progress_callback_failed',
        step,
        sequence,
        heartbeat,
        message,
      }));
    }
  }
}
