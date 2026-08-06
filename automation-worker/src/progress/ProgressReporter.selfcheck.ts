import { ProgressReporter } from './ProgressReporter.js';

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function run(): Promise<void> {
  const reporter = new ProgressReporter({
    progressUrl: null,
    secret: 'selfcheck-secret',
    phase: 'purchase',
    workerInstanceId: 'selfcheck-instance',
    workerBuild: 'selfcheck-build',
    driverName: 'wasim',
    driverVersion: 'wasim-1.0.0',
    sessionAlias: 'selfcheck-session',
    heartbeatIntervalMs: 40,
  });

  reporter.step('worker_received');
  reporter.step('browser_starting');

  const afterSteps = reporter.getDiagnostics();

  if (afterSteps.sequence !== 2) {
    throw new Error(`expected sequence 2 after two steps, got ${afterSteps.sequence}`);
  }

  if (afterSteps.last_step !== 'browser_starting') {
    throw new Error(`expected last_step "browser_starting", got ${String(afterSteps.last_step)}`);
  }

  reporter.heartbeat();
  const afterHeartbeat = reporter.getDiagnostics();

  if (afterHeartbeat.sequence !== 3) {
    throw new Error(`expected sequence 3 after heartbeat, got ${afterHeartbeat.sequence}`);
  }

  if (afterHeartbeat.last_step !== 'browser_starting') {
    throw new Error('heartbeat must not change last_step');
  }

  reporter.startHeartbeat();
  await sleep(130);
  reporter.stop();

  const sequenceAfterStop = reporter.getDiagnostics().sequence;

  await sleep(130);

  const sequenceAfterWait = reporter.getDiagnostics().sequence;

  if (sequenceAfterWait !== sequenceAfterStop) {
    throw new Error(
      `stop() did not clear the heartbeat timer: sequence grew from ${sequenceAfterStop} to ${sequenceAfterWait}`,
    );
  }

  if (reporter.getDiagnostics().progress_failures !== 0) {
    throw new Error('expected no progress failures when progressUrl is not configured');
  }

  console.log('ProgressReporter self-check passed');
}

run().catch((error) => {
  console.error(error instanceof Error ? error.message : error);
  process.exit(1);
});
