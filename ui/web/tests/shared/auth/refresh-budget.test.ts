// @vitest-environment node
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import {
  REFRESH_ATTEMPT_TIMEOUT_MS,
  REFRESH_BUDGET_MS,
  REFRESH_RETRY_AFTER_CAP_MS,
  WORKER_PROOF_DEADLINE_MS,
  WORKER_REFRESH_DEADLINE_MS,
  outageWaitMs,
} from '@/shared/auth/refresh-budget';

function repositoryFile(relativeToThisTest: string): string {
  return readFileSync(fileURLToPath(new URL(relativeToThisTest, import.meta.url)), 'utf8');
}

/** The server's lost-response grace window, as config/packages/auth.yaml declares it. */
function serverReuseGraceSeconds(): number {
  const config = repositoryFile('../../../../../config/packages/auth.yaml');
  const match = /^\s*auth\.refresh\.reuse_grace_seconds:\s*(\d+)\s*(?:#.*)?$/m.exec(config);
  if (!match) {
    throw new Error('config/packages/auth.yaml does not declare auth.refresh.reuse_grace_seconds');
  }
  return Number(match[1]);
}

/** The worker is a single-file build with no imports, so its deadlines are read from its source. */
function workerDeadline(name: string): number {
  const source = repositoryFile('../../../src/features/player/services/auth-stream-worker.ts');
  const match = new RegExp(`^const ${name} = (\\d[\\d_]*);$`, 'm').exec(source);
  if (!match) {
    throw new Error(`auth-stream-worker.ts does not declare const ${name}`);
  }
  return Number(match[1].replaceAll('_', ''));
}

describe('refresh budget invariant', () => {
  it('keeps the budget plus the Retry-After cap below the server reuse grace window', () => {
    const graceMs = serverReuseGraceSeconds() * 1000;

    expect(REFRESH_BUDGET_MS + REFRESH_RETRY_AFTER_CAP_MS).toBeLessThan(graceMs);
  });

  it('fits a first attempt, the longest 503 wait and a retry inside the budget', () => {
    expect(REFRESH_ATTEMPT_TIMEOUT_MS + REFRESH_RETRY_AFTER_CAP_MS + REFRESH_ATTEMPT_TIMEOUT_MS)
      .toBeLessThanOrEqual(REFRESH_BUDGET_MS);
  });

  it('pins the worker deadlines to the values derived from the budget', () => {
    expect(WORKER_REFRESH_DEADLINE_MS).toBeGreaterThan(REFRESH_BUDGET_MS);
    expect(workerDeadline('REFRESH_DEADLINE_MS')).toBe(WORKER_REFRESH_DEADLINE_MS);
    expect(workerDeadline('PROOF_DEADLINE_MS')).toBe(WORKER_PROOF_DEADLINE_MS);
  });
});

describe('outageWaitMs', () => {
  it('waits Retry-After plus a uniform jitter of up to the same value', () => {
    expect(outageWaitMs(5, () => 0)).toBe(5_000);
    expect(outageWaitMs(5, () => 0.5)).toBe(7_500);
    expect(outageWaitMs(5, () => 1)).toBe(10_000);
  });

  it('caps the wait at the Retry-After cap in total', () => {
    expect(outageWaitMs(120, () => 0)).toBe(REFRESH_RETRY_AFTER_CAP_MS);
    expect(outageWaitMs(6, () => 1)).toBe(REFRESH_RETRY_AFTER_CAP_MS);
  });
});
