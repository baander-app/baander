/**
 * QoL admin reports -- the per-worker answers of GET /api/admin/qol/status and
 * GET /api/admin/qol/streams, and helpers that summarize them for one screen.
 *
 * Every HTTP worker runs its own stream governor. A report has one row per worker
 * that answered, lists the workers that did not answer in `missing_workers` and
 * those whose call failed in `worker_errors`.
 */

export type QoLState = 'learning' | 'active';
export type QoLProfile = 'conservative' | 'balanced' | 'aggressive';

export interface QoLWorkerError {
  worker_id: number;
  error: string;
}

interface QoLWorkerFailures {
  missing_workers: number[];
  worker_errors: QoLWorkerError[];
}

export interface QoLWorkerStatus {
  worker_id: number;
  state: QoLState;
  profile: QoLProfile;
  active_streams: number;
  sample_count: number;
  model_ready: boolean;
  budget_cap: number;
}

export interface QoLStatusReport extends QoLWorkerFailures {
  workers: QoLWorkerStatus[];
  total: { active_streams: number };
}

export interface QoLStream {
  job_id: string;
  quality_tier: string;
  predicted_cost: number;
}

export interface QoLWorkerStreams {
  worker_id: number;
  active_streams: number;
  streams: QoLStream[];
}

export interface QoLStreamsReport extends QoLWorkerFailures {
  workers: QoLWorkerStreams[];
  total: { active_streams: number; predicted_cost: number };
}

/** A stream with the worker that serves it. */
export interface QoLWorkerStream extends QoLStream {
  worker_id: number;
}

/** The value every worker shares, 'mixed' when they disagree, null when no worker answered. */
export function sharedValue<T>(values: T[]): T | 'mixed' | null {
  if (values.length === 0) return null;
  return values.every((value) => value === values[0]) ? values[0] : 'mixed';
}

export function flattenStreams(report: QoLStreamsReport | null): QoLWorkerStream[] {
  if (!report) return [];
  return report.workers.flatMap((worker) =>
    worker.streams.map((stream) => ({ ...stream, worker_id: worker.worker_id })),
  );
}

/**
 * The workers that did not answer or failed in any of the reports, each named once,
 * so a partial snapshot never looks complete.
 */
export function workerFailures(reports: Array<QoLWorkerFailures | null>): QoLWorkerFailures {
  const missing = new Set<number>();
  const errors = new Map<string, QoLWorkerError>();
  for (const report of reports) {
    if (!report) continue;
    report.missing_workers.forEach((workerId) => missing.add(workerId));
    report.worker_errors.forEach((failure) => errors.set(`${failure.worker_id}:${failure.error}`, failure));
  }

  return {
    missing_workers: [...missing].sort((a, b) => a - b),
    worker_errors: [...errors.values()].sort((a, b) => a.worker_id - b.worker_id),
  };
}
