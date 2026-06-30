import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { TelemetryReporter } from '../../src/core/telemetry/TelemetryReporter';
import type { TelemetryConfig } from '../../src/core/telemetry/TelemetryReporter';
import type { PlayerConfig } from '../../src/types';
import { DEFAULT_CONFIG } from '../../src/types';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function createPlayerConfig(overrides: Partial<PlayerConfig> = {}): PlayerConfig {
  return { ...DEFAULT_CONFIG, baseUrl: 'https://baander.local', ...overrides };
}

function createTelemetryConfig(
  overrides: Partial<TelemetryConfig> = {},
): TelemetryConfig {
  return {
    endpoint: '/api/telemetry/player',
    batchIntervalMs: 10_000,
    maxBatchSize: 100,
    enabled: true,
    ...overrides,
  };
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('TelemetryReporter', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    globalThis.fetch = vi.fn().mockResolvedValue({ ok: true, status: 200 });
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  // ---- Construction ----

  it('should construct with player config and telemetry config', () => {
    const reporter = new TelemetryReporter(
      createPlayerConfig(),
      createTelemetryConfig(),
    );
    expect(reporter).toBeDefined();
  });

  // ---- start() ----

  it('should set session ID and video ID on start()', () => {
    const reporter = new TelemetryReporter(
      createPlayerConfig(),
      createTelemetryConfig(),
    );

    reporter.start('session-abc', 'video-123');

    // Record an event — the videoId should be set on it
    reporter.record({ type: 'test-event', timestamp: Date.now() });

    // Flush to inspect the POST body
    reporter.flush();

    const fetchCall = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls[0]!;
    const body = JSON.parse(fetchCall[1]!.body as string);
    expect(body.sessionId).toBe('session-abc');
    expect(body.videoId).toBe('video-123');
    expect(body.events).toHaveLength(1);
  });

  it('should not start batch timer when disabled', () => {
    const reporter = new TelemetryReporter(
      createPlayerConfig(),
      createTelemetryConfig({ enabled: false }),
    );

    reporter.start('session-abc', 'video-123');
    reporter.record({ type: 'test', timestamp: Date.now() });

    // Advance past batch interval
    vi.advanceTimersByTime(15_000);

    // No fetch should have been made
    expect(globalThis.fetch).not.toHaveBeenCalled();
  });

  // ---- record() ----

  it('should queue telemetry events', () => {
    const reporter = new TelemetryReporter(
      createPlayerConfig(),
      createTelemetryConfig(),
    );
    reporter.start('s1', 'v1');

    reporter.record({ type: 'buffer-health', timestamp: 1000, value: 5.2 });
    reporter.record({ type: 'buffer-health', timestamp: 1001, value: 4.8 });

    reporter.flush();

    const body = JSON.parse(
      (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls[0]![1]!.body as string,
    );
    expect(body.events).toHaveLength(2);
    expect(body.events[0].type).toBe('buffer-health');
    expect(body.events[0].value).toBe(5.2);
  });

  // ---- Auto-flush at batch size ----

  it('should auto-flush when events reach maxBatchSize', () => {
    const reporter = new TelemetryReporter(
      createPlayerConfig(),
      createTelemetryConfig({ maxBatchSize: 3 }),
    );
    reporter.start('s1', 'v1');

    reporter.record({ type: 'e1', timestamp: 1 });
    reporter.record({ type: 'e2', timestamp: 2 });
    // Third event triggers auto-flush
    reporter.record({ type: 'e3', timestamp: 3 });

    expect(globalThis.fetch).toHaveBeenCalledTimes(1);

    const body = JSON.parse(
      (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls[0]![1]!.body as string,
    );
    expect(body.events).toHaveLength(3);
  });

  // ---- Batch timer flush ----

  it('should flush on batch timer interval', () => {
    const reporter = new TelemetryReporter(
      createPlayerConfig(),
      createTelemetryConfig({ batchIntervalMs: 5_000 }),
    );
    reporter.start('s1', 'v1');

    reporter.record({ type: 'tick', timestamp: 1 });

    expect(globalThis.fetch).not.toHaveBeenCalled();

    vi.advanceTimersByTime(5_000);

    expect(globalThis.fetch).toHaveBeenCalledTimes(1);
  });

  // ---- stop() ----

  it('should flush remaining events on stop()', () => {
    const reporter = new TelemetryReporter(
      createPlayerConfig(),
      createTelemetryConfig(),
    );
    reporter.start('s1', 'v1');
    reporter.record({ type: 'final', timestamp: 999 });

    reporter.stop();

    expect(globalThis.fetch).toHaveBeenCalledTimes(1);

    const body = JSON.parse(
      (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls[0]![1]!.body as string,
    );
    expect(body.events).toHaveLength(1);
    expect(body.events[0].type).toBe('final');
  });

  it('should clear batch timer on stop()', () => {
    const reporter = new TelemetryReporter(
      createPlayerConfig(),
      createTelemetryConfig({ batchIntervalMs: 5_000 }),
    );
    reporter.start('s1', 'v1');
    // Record an event so stop() has something to flush
    reporter.record({ type: 't', timestamp: 1 });
    reporter.stop();

    // Should have been flushed once during stop
    expect(globalThis.fetch).toHaveBeenCalledTimes(1);

    // Advance timer — should NOT trigger another flush
    vi.advanceTimersByTime(10_000);
    expect(globalThis.fetch).toHaveBeenCalledTimes(1);
  });

  // ---- flush() network failure ----

  it('should re-queue events on network failure', async () => {
    (globalThis.fetch as ReturnType<typeof vi.fn>).mockRejectedValueOnce(
      new Error('Network error'),
    );

    const reporter = new TelemetryReporter(
      createPlayerConfig(),
      createTelemetryConfig(),
    );
    reporter.start('s1', 'v1');

    reporter.record({ type: 'retry-me', timestamp: 1 });
    await reporter.flush();

    // First flush failed — events re-queued
    expect(globalThis.fetch).toHaveBeenCalledTimes(1);

    // Second flush should succeed with the same events
    await reporter.flush();
    expect(globalThis.fetch).toHaveBeenCalledTimes(2);

    const body = JSON.parse(
      (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls[1]![1]!.body as string,
    );
    expect(body.events).toHaveLength(1);
    expect(body.events[0].type).toBe('retry-me');
  });

  // ---- Rapid record() calls ----

  it('should batch multiple rapid record() calls correctly', () => {
    const reporter = new TelemetryReporter(
      createPlayerConfig(),
      createTelemetryConfig({ maxBatchSize: 1000 }), // high threshold
    );
    reporter.start('s1', 'v1');

    for (let i = 0; i < 50; i++) {
      reporter.record({ type: 'rapid', timestamp: i, value: i });
    }

    reporter.flush();

    const body = JSON.parse(
      (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls[0]![1]!.body as string,
    );
    expect(body.events).toHaveLength(50);
  });

  // ---- Event metadata ----

  it('should include correct timestamp and session metadata in POST body', () => {
    const reporter = new TelemetryReporter(
      createPlayerConfig(),
      createTelemetryConfig(),
    );
    reporter.start('session-meta', 'video-meta');

    reporter.record({
      type: 'rendition-switch',
      timestamp: 1234567890,
      renditionId: '720p',
      metadata: { from: '360p', to: '720p' },
    });

    reporter.flush();

    const body = JSON.parse(
      (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls[0]![1]!.body as string,
    );
    expect(body.sessionId).toBe('session-meta');
    expect(body.videoId).toBe('video-meta');
    expect(body.events[0].timestamp).toBe(1234567890);
    expect(body.events[0].videoId).toBe('video-meta');
    expect(body.events[0].renditionId).toBe('720p');
    expect(body.events[0].metadata).toEqual({ from: '360p', to: '720p' });
  });

  // ---- POST URL construction ----

  it('should POST to baseUrl + endpoint', () => {
    const reporter = new TelemetryReporter(
      createPlayerConfig({ baseUrl: 'https://cdn.baander.local' }),
      createTelemetryConfig({ endpoint: '/api/telemetry/player' }),
    );
    reporter.start('s1', 'v1');
    reporter.record({ type: 't', timestamp: 1 });
    reporter.flush();

    const url = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls[0]![0];
    expect(url).toBe('https://cdn.baander.local/api/telemetry/player');
  });

  // ---- Custom headers ----

  it('should include custom headers from player config', () => {
    const reporter = new TelemetryReporter(
      createPlayerConfig({
        customHeaders: { Authorization: 'Bearer token-123' },
      }),
      createTelemetryConfig(),
    );
    reporter.start('s1', 'v1');
    reporter.record({ type: 't', timestamp: 1 });
    reporter.flush();

    const opts = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls[0]![1]!;
    expect(opts.headers).toHaveProperty('Authorization', 'Bearer token-123');
    expect(opts.headers).toHaveProperty('Content-Type', 'application/json');
  });

  // ---- Flush with empty queue ----

  it('should not POST when no events are queued', () => {
    const reporter = new TelemetryReporter(
      createPlayerConfig(),
      createTelemetryConfig(),
    );
    reporter.start('s1', 'v1');

    reporter.flush();

    expect(globalThis.fetch).not.toHaveBeenCalled();
  });

  // ---- keepalive flag ----

  it('should send requests with keepalive: true', () => {
    const reporter = new TelemetryReporter(
      createPlayerConfig(),
      createTelemetryConfig(),
    );
    reporter.start('s1', 'v1');
    reporter.record({ type: 't', timestamp: 1 });
    reporter.flush();

    const opts = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls[0]![1]!;
    expect(opts.keepalive).toBe(true);
  });

  // ---- Disabled mode ----

  it('should ignore record() calls when disabled', () => {
    const reporter = new TelemetryReporter(
      createPlayerConfig(),
      createTelemetryConfig({ enabled: false }),
    );
    reporter.start('s1', 'v1');
    reporter.record({ type: 'ignored', timestamp: 1 });

    // Force flush even — nothing should happen since record was gated
    reporter.flush();
    expect(globalThis.fetch).not.toHaveBeenCalled();
  });
});
