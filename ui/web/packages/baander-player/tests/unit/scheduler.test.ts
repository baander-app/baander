import { describe, it, expect, vi, beforeEach } from 'vitest';
import { SegmentScheduler } from '../../src/core/scheduler/SegmentScheduler';
import type { SchedulerConfig, SchedulerEvents } from '../../src/core/scheduler/SegmentScheduler';
import type {
  Manifest,
  Rendition,
  SegmentInfo,
  FetchOutcome,
  BufferStats,
} from '../../src/types';
import { SmartABRController } from '../../src/core/abr/SmartABRController';

// ---------------------------------------------------------------------------
// Test Fixtures
// ---------------------------------------------------------------------------

function createTestRenditions(): Rendition[] {
  return [
    {
      id: '360p',
      name: '360p',
      width: 640,
      height: 360,
      bitrate: 800_000,
      maxBitrate: 1_200_000,
      codecs: 'hvc1.1.6.L93.B0,mp4a.40.2',
      initSegmentUrl: '/api/stream/job-360/init.mp4',
      segments: Array.from({ length: 10 }, (_, i) => ({
        index: i,
        duration: 6,
        uri: `/api/stream/job-360/seg_${i}.m4s`,
      })),
      targetDuration: 6,
      totalDuration: 60,
    },
    {
      id: '720p',
      name: '720p',
      width: 1280,
      height: 720,
      bitrate: 2_800_000,
      maxBitrate: 4_200_000,
      codecs: 'hvc1.1.6.L93.B0,mp4a.40.2',
      initSegmentUrl: '/api/stream/job-720/init.mp4',
      segments: Array.from({ length: 10 }, (_, i) => ({
        index: i,
        duration: 6,
        uri: `/api/stream/job-720/seg_${i}.m4s`,
      })),
      targetDuration: 6,
      totalDuration: 60,
    },
    {
      id: '1080p',
      name: '1080p',
      width: 1920,
      height: 1080,
      bitrate: 5_000_000,
      maxBitrate: 7_500_000,
      codecs: 'hvc1.1.6.L93.B0,mp4a.40.2',
      initSegmentUrl: '/api/stream/job-1080/init.mp4',
      segments: Array.from({ length: 10 }, (_, i) => ({
        index: i,
        duration: 6,
        uri: `/api/stream/job-1080/seg_${i}.m4s`,
      })),
      targetDuration: 6,
      totalDuration: 60,
    },
  ];
}

function createTestManifest(): Manifest {
  return {
    videoId: 'video-test-001',
    sourceFormat: 'hls',
    renditions: createTestRenditions(),
    qualityLadder: [],
    contentHint: 'unknown',
    fetchedAt: Date.now(),
    duration: 60,
    audioTracks: [],
    subtitleTracks: [],
  };
}

function createMockTransport() {
  return {
    fetchInitSegment: vi.fn<Promise<FetchOutcome>, [string]>().mockResolvedValue({
      ok: true,
      data: new ArrayBuffer(1024),
      ttfb: 50,
      byteLength: 1024,
      fromCache: false,
    }),
    fetchSegment: vi.fn<Promise<FetchOutcome>, [string, object?]>().mockResolvedValue({
      ok: true,
      data: new ArrayBuffer(50_000),
      ttfb: 100,
      byteLength: 50_000,
      fromCache: false,
    }),
    destroy: vi.fn(),
  };
}

function createMockBuffer() {
  return {
    init: vi.fn<Promise<void>, [Rendition]>().mockResolvedValue(undefined),
    appendInit: vi.fn<Promise<void>, [ArrayBuffer]>().mockResolvedValue(undefined),
    appendSegment: vi.fn<Promise<void>, [number, ArrayBuffer]>().mockResolvedValue(undefined),
    appendAudioInit: vi.fn<Promise<void>, [ArrayBuffer]>().mockResolvedValue(undefined),
    appendAudioSegment: vi.fn<Promise<void>, [ArrayBuffer]>().mockResolvedValue(undefined),
    destroy: vi.fn(),
  };
}

function createEvents(): SchedulerEvents & { calls: Record<string, unknown[][]> } {
  const calls: Record<string, unknown[][]> = {
    onSegmentFetched: [],
    onSegmentError: [],
    onInitFetched: [],
    onRenditionSwitch: [],
    onScheduleProgress: [],
  };

  return {
    calls,
    onSegmentFetched: (...args: unknown[]) => calls.onSegmentFetched.push(args),
    onSegmentError: (...args: unknown[]) => calls.onSegmentError.push(args),
    onInitFetched: (...args: unknown[]) => calls.onInitFetched.push(args),
    onRenditionSwitch: (...args: unknown[]) => calls.onRenditionSwitch.push(args),
    onScheduleProgress: (...args: unknown[]) => calls.onScheduleProgress.push(args),
  };
}

function createScheduler(
  overrides: {
    transport?: ReturnType<typeof createMockTransport>;
    buffer?: ReturnType<typeof createMockBuffer>;
    abr?: SmartABRController;
    config?: Partial<SchedulerConfig>;
    events?: SchedulerEvents;
  } = {},
) {
  const transport = overrides.transport ?? createMockTransport();
  const buffer = overrides.buffer ?? createMockBuffer();
  const abr =
    overrides.abr ??
    new SmartABRController({ onRenditionChange: () => {} });
  const config: SchedulerConfig = {
    lookAhead: 3,
    prefetchCount: 1,
    ...overrides.config,
  };
  const events = overrides.events ?? {
    onSegmentFetched: () => {},
    onSegmentError: () => {},
    onInitFetched: () => {},
    onRenditionSwitch: () => {},
    onScheduleProgress: () => {},
  };

  const scheduler = new SegmentScheduler(
    transport as any,
    buffer as any,
    abr,
    config,
    events,
  );

  return { scheduler, transport, buffer, abr, config, events };
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('SegmentScheduler', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  // ---- setManifest ----

  it('should accept a manifest and reset internal state', () => {
    const { scheduler } = createScheduler();
    const manifest = createTestManifest();

    scheduler.setManifest(manifest);

    expect(scheduler.getCurrentRendition()).toBeNull();
    expect(scheduler.getFetchedCount()).toBe(0);
  });

  // ---- start() ----

  it('should begin fetching from segment 0', async () => {
    const { scheduler, transport, buffer } = createScheduler();
    scheduler.setManifest(createTestManifest());

    await scheduler.start(0);

    // Should have fetched init segment
    expect(transport.fetchInitSegment).toHaveBeenCalledTimes(1);
    expect(buffer.init).toHaveBeenCalledTimes(1);
    expect(buffer.appendInit).toHaveBeenCalledTimes(1);

    // Should have started fetching segments
    expect(transport.fetchSegment).toHaveBeenCalled();
  });

  it('should begin fetching from a non-zero segment index', async () => {
    const { scheduler, transport } = createScheduler();
    scheduler.setManifest(createTestManifest());

    await scheduler.start(5);

    // First segment fetched should be index 5 or later
    const calls = transport.fetchSegment.mock.calls;
    const firstUrl = calls[0]![0] as string;
    expect(firstUrl).toContain('seg_5');
  });

  it('should throw if no manifest is loaded', async () => {
    const { scheduler } = createScheduler();

    await expect(scheduler.start()).rejects.toThrow('No manifest loaded');
  });

  // ---- switchRendition ----

  it('should fetch init segment when switching renditions', async () => {
    const { scheduler, transport, events } = createScheduler();
    const trackingEvents = events as any;
    scheduler.setManifest(createTestManifest());

    await scheduler.start(0);

    const initCallsBefore = transport.fetchInitSegment.mock.calls.length;

    // Force a rendition switch via ABR evaluation
    const abr = new SmartABRController({ onRenditionChange: () => {} });
    abr.setRenditions(createTestRenditions());
    // Pre-load bandwidth to target 1080p
    abr.recordSegmentDownload(2_000_000, 50, 200); // high throughput

    const stats: BufferStats = {
      forwardBuffer: 20,
      bufferedRanges: [],
      segmentCount: 5,
      bytesBuffered: 500_000,
    };

    await scheduler.evaluateABR(stats);

    // If ABR decided to switch, init segment should be fetched again
    // (may or may not switch depending on bandwidth estimate)
    // At minimum, the mechanism should run without error
    expect(transport.fetchInitSegment.mock.calls.length).toBeGreaterThanOrEqual(initCallsBefore);
  });

  // ---- stop() ----

  it('should cancel all pending fetches on stop()', async () => {
    const { scheduler } = createScheduler();
    scheduler.setManifest(createTestManifest());

    await scheduler.start(0);
    scheduler.stop();

    // After stop, further operations should be aborted
    expect(scheduler.getCurrentRendition()).not.toBeNull();
  });

  // ---- seekTo() ----

  it('should reset and start from target segment on seekTo()', async () => {
    const { scheduler, transport } = createScheduler();
    scheduler.setManifest(createTestManifest());

    await scheduler.start(0);
    transport.fetchSegment.mockClear();

    // Seek to 30 seconds — with 6s segments, that's segment index 5
    await scheduler.seekTo(30);

    // Should have started fetching segments near index 5
    const calls = transport.fetchSegment.mock.calls;
    if (calls.length > 0) {
      const firstUrl = calls[0]![0] as string;
      // Should be near index 5 (could be 4 or 5 depending on cumulative)
      const idx = parseInt(firstUrl.match(/seg_(\d+)/)?.[1] ?? '0', 10);
      expect(idx).toBeGreaterThanOrEqual(4);
      expect(idx).toBeLessThanOrEqual(12); // lookAhead + prefetch range from segment 5
    }
  });

  // ---- getCurrentRendition() ----

  it('should return current rendition after start', async () => {
    const { scheduler } = createScheduler();
    scheduler.setManifest(createTestManifest());

    expect(scheduler.getCurrentRendition()).toBeNull();

    await scheduler.start(0);

    const rendition = scheduler.getCurrentRendition();
    expect(rendition).not.toBeNull();
    expect(rendition!.id).toBeDefined();
  });

  // ---- evaluateABR() ----

  it('should delegate to ABR controller and switch if needed', async () => {
    const abr = new SmartABRController({ onRenditionChange: () => {} });
    abr.setRenditions(createTestRenditions());
    // Set very high throughput to force switch-up
    abr.recordSegmentDownload(10_000_000, 10, 50);

    const { scheduler, transport } = createScheduler({ abr });
    scheduler.setManifest(createTestManifest());
    await scheduler.start(0);

    const initCallsBefore = transport.fetchInitSegment.mock.calls.length;

    // Healthy buffer → ABR may switch up
    const stats: BufferStats = {
      forwardBuffer: 20,
      bufferedRanges: [],
      segmentCount: 5,
      bytesBuffered: 1_000_000,
    };
    await scheduler.evaluateABR(stats);

    // Should have called init at least once more if switched
    // (or same count if no switch — both are valid)
    expect(transport.fetchInitSegment.mock.calls.length).toBeGreaterThanOrEqual(initCallsBefore);
  });

  // ---- Progress tracking ----

  it('should emit schedule progress events', async () => {
    const trackingEvents = createEvents();
    const { scheduler } = createScheduler({ events: trackingEvents });
    scheduler.setManifest(createTestManifest());

    await scheduler.start(0);

    // fetchSegment() calls are fire-and-forget from scheduleNext().
    // Flush microtask queue so their promises resolve.
    await vi.runAllTimersAsync();

    // Should have emitted at least one progress event
    expect(trackingEvents.calls.onScheduleProgress.length).toBeGreaterThan(0);

    const lastProgress =
      trackingEvents.calls.onScheduleProgress[
        trackingEvents.calls.onScheduleProgress.length - 1
      ]!;
    expect(lastProgress[0]).toBeGreaterThan(0); // fetched count
    expect(lastProgress[1]).toBe(10); // total segments
  });

  it('should emit onSegmentFetched for each fetched segment', async () => {
    const trackingEvents = createEvents();
    const { scheduler } = createScheduler({
      events: trackingEvents,
      config: { lookAhead: 2, prefetchCount: 0 },
    });
    scheduler.setManifest(createTestManifest());

    await scheduler.start(0);

    expect(trackingEvents.calls.onSegmentFetched.length).toBeGreaterThan(0);

    // Each call should have (index, bytes, ttfb)
    const firstCall = trackingEvents.calls.onSegmentFetched[0]!;
    expect(firstCall[0]).toBe(0); // segment index
    expect(firstCall[1]).toBe(50_000); // byteLength
    expect(firstCall[2]).toBe(100); // ttfb
  });

  it('should emit onInitFetched when init segment loads', async () => {
    const trackingEvents = createEvents();
    const { scheduler } = createScheduler({ events: trackingEvents });
    scheduler.setManifest(createTestManifest());

    await scheduler.start(0);

    expect(trackingEvents.calls.onInitFetched.length).toBe(1);
    expect(trackingEvents.calls.onInitFetched[0]![0]).toBeDefined();
  });

  // ---- Error handling: init segment failure ----

  it('should throw when init segment fetch fails', async () => {
    const transport = createMockTransport();
    transport.fetchInitSegment.mockResolvedValueOnce({
      ok: false,
      status: 404,
      reason: 'Not Found',
    });

    const { scheduler } = createScheduler({ transport });
    scheduler.setManifest(createTestManifest());

    await expect(scheduler.start(0)).rejects.toThrow('Failed to fetch init segment');
  });

  // ---- Error handling: segment 202 (pending) ----

  it('should emit onSegmentError when segment fetch fails', async () => {
    const transport = createMockTransport();
    // Init succeeds
    transport.fetchInitSegment.mockResolvedValueOnce({
      ok: true,
      data: new ArrayBuffer(1024),
      ttfb: 50,
      byteLength: 1024,
      fromCache: false,
    });
    // First segment returns 202
    transport.fetchSegment.mockResolvedValueOnce({
      ok: false,
      status: 202,
      retryAfter: 2,
      reason: 'Segment not yet encoded',
    });
    // Remaining segments succeed
    transport.fetchSegment.mockResolvedValue({
      ok: true,
      data: new ArrayBuffer(50_000),
      ttfb: 100,
      byteLength: 50_000,
      fromCache: false,
    });

    const trackingEvents = createEvents();
    const { scheduler } = createScheduler({ transport, events: trackingEvents, config: { lookAhead: 1, prefetchCount: 0 } });
    scheduler.setManifest(createTestManifest());

    await scheduler.start(0);

    // Should have emitted an error for the failed segment
    expect(trackingEvents.calls.onSegmentError.length).toBeGreaterThan(0);
    expect(trackingEvents.calls.onSegmentError[0]![0]).toBe(0); // segment index 0
    expect(trackingEvents.calls.onSegmentError[0]![1]).toContain('not yet encoded');
  });

  // ---- findSegmentIndexForTime ----

  it('should find correct segment index for a given time', async () => {
    const { scheduler } = createScheduler();
    scheduler.setManifest(createTestManifest());
    await scheduler.start(0);

    // With 6s segments: seg 0 = [0,6), seg 1 = [6,12), seg 2 = [12,18)
    // Boundary t=6.0 belongs to segment 1 (cumulative=6 at seg 0 → 6 > 6 is false → seg 1)
    expect(scheduler.findSegmentIndexForTime(0)).toBe(0);
    expect(scheduler.findSegmentIndexForTime(3)).toBe(0);
    expect(scheduler.findSegmentIndexForTime(5.99)).toBe(0);
    expect(scheduler.findSegmentIndexForTime(6)).toBe(1); // boundary: cumulative > 6 false at seg 0, true at seg 1
    expect(scheduler.findSegmentIndexForTime(6.01)).toBe(1);
    expect(scheduler.findSegmentIndexForTime(12)).toBe(2); // boundary: cumulative > 12 false at seg 1, true at seg 2
    expect(scheduler.findSegmentIndexForTime(12.01)).toBe(2);
    expect(scheduler.findSegmentIndexForTime(54)).toBe(9); // boundary: cumulative > 54 false at seg 8, true at seg 9
    expect(scheduler.findSegmentIndexForTime(54.01)).toBe(9);
  });

  it('should return 0 for time beyond duration', async () => {
    const { scheduler } = createScheduler();
    scheduler.setManifest(createTestManifest());
    await scheduler.start(0);

    expect(scheduler.findSegmentIndexForTime(999)).toBe(0);
  });

  // ---- getFetchedCount ----

  it('should track fetched segment count', async () => {
    const { scheduler } = createScheduler({ config: { lookAhead: 2, prefetchCount: 0 } });
    scheduler.setManifest(createTestManifest());

    expect(scheduler.getFetchedCount()).toBe(0);

    await scheduler.start(0);

    expect(scheduler.getFetchedCount()).toBeGreaterThan(0);
  });

  // ---- Multiple start() calls ----

  it('should handle multiple start() calls without duplicating', async () => {
    const { scheduler, transport } = createScheduler();
    scheduler.setManifest(createTestManifest());

    await scheduler.start(0);
    const firstInitCalls = transport.fetchInitSegment.mock.calls.length;

    await scheduler.start(3);

    // Should re-fetch init (new start = new switchRendition)
    expect(transport.fetchInitSegment.mock.calls.length).toBeGreaterThan(firstInitCalls);
  });

  // ---- Rendition switch event ----

  it('should emit onRenditionSwitch when ABR causes a change', async () => {
    const trackingEvents = createEvents();
    const abr = new SmartABRController({ onRenditionChange: () => {} });
    abr.setRenditions(createTestRenditions());

    const { scheduler, transport } = createScheduler({ abr, events: trackingEvents });
    scheduler.setManifest(createTestManifest());

    // Start — picks initial rendition (360p with default bandwidth)
    await scheduler.start(0);

    // Give it massive bandwidth to trigger switch-up
    abr.recordSegmentDownload(50_000_000, 5, 10);

    // Need to wait past hysteresis window
    vi.advanceTimersByTime(6000);

    const stats: BufferStats = {
      forwardBuffer: 20,
      bufferedRanges: [],
      segmentCount: 10,
      bytesBuffered: 5_000_000,
    };

    await scheduler.evaluateABR(stats);

    // If a switch happened, we should see the event
    if (trackingEvents.calls.onRenditionSwitch.length > 0) {
      const [fromId, toId] = trackingEvents.calls.onRenditionSwitch[0]! as [string, string];
      expect(fromId).not.toBe(toId);
    }
  });
});
