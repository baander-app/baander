/**
 * QoL admin report helper tests.
 */

import { flattenStreams, sharedValue, workerFailures } from '../qol-report';
import type { QoLStreamsReport } from '../qol-report';

describe('sharedValue', () => {
  it('returns the value every worker shares', () => {
    expect(sharedValue(['active', 'active'])).toBe('active');
  });

  it('returns mixed when workers disagree', () => {
    expect(sharedValue(['active', 'learning'])).toBe('mixed');
  });

  it('returns null when no worker answered', () => {
    expect(sharedValue([])).toBeNull();
  });
});

describe('flattenStreams', () => {
  it('lists the streams of every worker with the worker that serves each', () => {
    const report: QoLStreamsReport = {
      workers: [
        { worker_id: 0, active_streams: 1, streams: [{ job_id: 'a', quality_tier: '1080p', predicted_cost: 12.5 }] },
        { worker_id: 1, active_streams: 0, streams: [] },
        { worker_id: 2, active_streams: 1, streams: [{ job_id: 'b', quality_tier: '720p', predicted_cost: 6 }] },
      ],
      total: { active_streams: 2, predicted_cost: 18.5 },
      missing_workers: [],
      worker_errors: [],
    };

    expect(flattenStreams(report)).toEqual([
      { worker_id: 0, job_id: 'a', quality_tier: '1080p', predicted_cost: 12.5 },
      { worker_id: 2, job_id: 'b', quality_tier: '720p', predicted_cost: 6 },
    ]);
  });

  it('returns no streams before the report has loaded', () => {
    expect(flattenStreams(null)).toEqual([]);
  });
});

describe('workerFailures', () => {
  it('names each missing or failed worker once across reports', () => {
    const failures = workerFailures([
      { missing_workers: [3, 1], worker_errors: [{ worker_id: 2, error: 'timeout' }] },
      null,
      { missing_workers: [1], worker_errors: [{ worker_id: 2, error: 'timeout' }, { worker_id: 0, error: 'crashed' }] },
    ]);

    expect(failures).toEqual({
      missing_workers: [1, 3],
      worker_errors: [{ worker_id: 0, error: 'crashed' }, { worker_id: 2, error: 'timeout' }],
    });
  });
});
