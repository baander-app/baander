/**
 * QoL admin card tests: the per-worker reports of /api/admin/qol/status and /streams.
 */

import React from 'react';
import { render, screen } from '@testing-library/react-native';
import { QoLStatusCard } from '../components/QoLStatusCard';
import { QoLUtilizationCard } from '../components/QoLUtilizationCard';
import { QoLWorkerNotice } from '../components/QoLWorkerNotice';
import type { QoLStatusReport, QoLStreamsReport, QoLWorkerStatus } from '@/features/admin/qol-report';

function worker(overrides: Partial<QoLWorkerStatus>): QoLWorkerStatus {
  return {
    worker_id: 0,
    state: 'active',
    profile: 'balanced',
    active_streams: 0,
    sample_count: 120,
    model_ready: true,
    budget_cap: 0.8,
    ...overrides,
  };
}

function statusReport(workers: QoLWorkerStatus[]): QoLStatusReport {
  return {
    workers,
    total: { active_streams: workers.reduce((sum, row) => sum + row.active_streams, 0) },
    missing_workers: [],
    worker_errors: [],
  };
}

const streams: QoLStreamsReport = {
  workers: [
    { worker_id: 0, active_streams: 1, streams: [{ job_id: 'job-a', quality_tier: '1080p', predicted_cost: 12.5 }] },
    { worker_id: 1, active_streams: 1, streams: [{ job_id: 'job-b', quality_tier: '720p', predicted_cost: 6 }] },
  ],
  total: { active_streams: 2, predicted_cost: 18.5 },
  missing_workers: [],
  worker_errors: [],
};

describe('QoLStatusCard', () => {
  it('shows the state and profile the workers share, the total and every worker stream', () => {
    render(
      <QoLStatusCard
        status={statusReport([
          worker({ worker_id: 0, active_streams: 1 }),
          worker({ worker_id: 1, active_streams: 1, sample_count: 40, model_ready: false, budget_cap: 0.5 }),
        ])}
        streams={streams}
      />,
    );

    expect(screen.getByText('ACTIVE')).toBeTruthy();
    expect(screen.getByText('balanced')).toBeTruthy();
    expect(screen.getByText('2')).toBeTruthy();
    expect(screen.getByText('ACTIVE · balanced · 40 samples Learning… · cap 50%')).toBeTruthy();
    expect(screen.getByText('Worker 0 · 1080p')).toBeTruthy();
    expect(screen.getByText('Worker 1 · 720p')).toBeTruthy();
    expect(screen.getByText('6.0% CPU')).toBeTruthy();
  });

  it('shows mixed when the workers disagree', () => {
    render(
      <QoLStatusCard
        status={statusReport([
          worker({ worker_id: 0, state: 'active', profile: 'balanced' }),
          worker({ worker_id: 1, state: 'learning', profile: 'aggressive' }),
        ])}
        streams={null}
      />,
    );

    expect(screen.getByText('MIXED')).toBeTruthy();
    expect(screen.getByText('mixed')).toBeTruthy();
  });
});

describe('QoLUtilizationCard', () => {
  it('shows the budget cap of every worker and the total of active streams', () => {
    render(
      <QoLUtilizationCard
        data={statusReport([
          worker({ worker_id: 0, budget_cap: 0.8, active_streams: 2 }),
          worker({ worker_id: 1, budget_cap: 0.5, state: 'learning', active_streams: 1 }),
        ])}
      />,
    );

    expect(screen.getByText('Worker 0 cap:')).toBeTruthy();
    expect(screen.getByText('80%')).toBeTruthy();
    expect(screen.getByText('Worker 1 cap:')).toBeTruthy();
    expect(screen.getByText('50%')).toBeTruthy();
    expect(screen.getByText('3')).toBeTruthy();
    expect(screen.getByText('Some workers are still learning…')).toBeTruthy();
  });
});

describe('QoLWorkerNotice', () => {
  it('names the workers that did not answer or failed', () => {
    render(<QoLWorkerNotice missingWorkers={[2]} workerErrors={[{ worker_id: 3, error: 'timeout' }]} />);

    expect(screen.getByText('Worker 2 did not answer in time.')).toBeTruthy();
    expect(screen.getByText('Worker 3 failed: timeout')).toBeTruthy();
  });

  it('renders nothing when every worker answered', () => {
    render(<QoLWorkerNotice missingWorkers={[]} workerErrors={[]} />);

    expect(screen.queryByText('Some workers are missing from this view')).toBeNull();
  });
});
