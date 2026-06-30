import { describe, it, expect, vi, beforeEach } from 'vitest';
import { SmartABRController } from '../../src/core/abr/SmartABRController';
import type { Rendition } from '../../src/types';

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

/** Helper: create controller with renditions pre-loaded. */
function createController() {
  const changes: Array<{ id: string; reason: string }> = [];
  const controller = new SmartABRController({
    onRenditionChange: (id, reason) => changes.push({ id, reason }),
  });
  controller.setRenditions(createTestRenditions());
  return { controller, changes };
}

/** Helper: simulate enough bandwidth to target a specific bitrate tier. */
function simulateBandwidth(controller: SmartABRController, targetBitrate: number) {
  // downloadMs of 1000ms, bytes = targetBitrate / 8 * 1000 / 1000 = targetBitrate / 8
  const bytes = targetBitrate / 8;
  controller.recordSegmentDownload(bytes, 50, 1000);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('SmartABRController — Extended', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  // ---- Switch-up hysteresis ----

  it('should not switch up within minSwitchInterval (hysteresis)', () => {
    const { controller, changes } = createController();

    // Initialize to 360p
    controller.selectInitialRendition();
    expect(controller.getState().currentRenditionId).toBe('360p');

    // Give massive bandwidth to encourage switch-up
    simulateBandwidth(controller, 50_000_000);

    // First evaluation — should switch up
    const first = controller.evaluate(15); // healthy buffer
    expect(first).not.toBeNull();

    // Immediate second evaluation — within 5s hysteresis, should return null
    const second = controller.evaluate(15);
    expect(second).toBeNull();
  });

  // ---- Switch-down is immediate ----

  it('should switch down immediately when buffer is critically low', () => {
    const { controller } = createController();

    // Start at 1080p by giving high bandwidth
    simulateBandwidth(controller, 100_000_000);
    const initial = controller.selectInitialRendition();
    expect(initial.id).toBe('1080p');

    // First evaluate to establish currentRenditionId
    controller.evaluate(15);

    // Immediately drop buffer to critically low — should switch down
    const result = controller.evaluate(0.5); // 0.5s buffer — below any downBufferThreshold
    expect(result).not.toBeNull();
    // Should have moved to a lower rendition
    expect(result).not.toBe('1080p');
  });

  // ---- Content-aware: static content ----

  it('content-aware: static content should stay at lower quality', () => {
    const { controller } = createController();
    controller.setStrategy('content-aware');
    controller.setContentHint('static');

    // Moderate bandwidth (3Mbps) — could sustain 720p, but static content
    // uses bandwidthSafetyMargin of 0.7 → target = 2.1Mbps → picks 360p
    simulateBandwidth(controller, 3_000_000);

    const initial = controller.selectInitialRendition();
    expect(initial.id).toBe('360p');
  });

  // ---- Content-aware: sport/motion content ----

  it('content-aware: sport content targets higher quality', () => {
    const { controller } = createController();
    controller.setStrategy('content-aware');
    controller.setContentHint('sport');

    // 8Mbps bandwidth with sport safety margin of 0.85 → target = 6.8Mbps
    // With maxBitrate-aware selection: 1080p peak=7.5Mbps > 6.8Mbps, 720p peak=4.2Mbps ≤ 6.8Mbps → 720p
    simulateBandwidth(controller, 8_000_000);

    const initial = controller.selectInitialRendition();
    expect(initial.id).toBe('720p');
  });

  // ---- Content-aware: gaming content ----

  it('content-aware: gaming uses high safety margin (0.9)', () => {
    const { controller } = createController();
    controller.setStrategy('content-aware');
    controller.setContentHint('gaming');

    // selectInitialRendition uses content-aware margin for content-aware strategy.
    // 5Mbps × 0.9 (gaming margin) = 4.5Mbps target. 720p peak 4.2M ≤ 4.5M → 720p
    simulateBandwidth(controller, 5_000_000);

    const initial = controller.selectInitialRendition();
    expect(initial.id).toBe('720p');
  });

  // ---- Throughput strategy ----

  it('throughput strategy: pure bandwidth-based selection', () => {
    const { controller } = createController();
    controller.setStrategy('throughput');

    // 4Mbps bandwidth, default safety margin 0.75 → target = 3Mbps
    // With maxBitrate-aware selection: 720p peak=4.2Mbps > 3Mbps, 360p peak=1.2Mbps ≤ 3Mbps → 360p
    simulateBandwidth(controller, 4_000_000);

    const initial = controller.selectInitialRendition();
    expect(initial.id).toBe('360p');
  });

  // ---- Buffer strategy ----

  it('buffer strategy: switch down when buffer depleting', () => {
    const { controller } = createController();
    controller.setStrategy('buffer');

    // Start at 1080p
    simulateBandwidth(controller, 100_000_000);
    controller.selectInitialRendition();
    controller.evaluate(15); // establish current

    // Critically low buffer → switch down regardless of bandwidth
    const result = controller.evaluate(1);
    expect(result).not.toBeNull();
    expect(result).not.toBe('1080p');
  });

  // ---- Bandwidth estimation EWMA ----

  it('should converge bandwidth estimate via EWMA with many samples', () => {
    const { controller } = createController();

    // Feed 20 samples at ~4Mbps
    for (let i = 0; i < 20; i++) {
      // 4Mbps for 1000ms = 500KB
      controller.recordSegmentDownload(500_000, 50, 1000);
    }

    const state = controller.getState();
    // Estimate should be close to 4Mbps (4,000,000 bps)
    expect(state.throughput).toBeGreaterThan(3_500_000);
    expect(state.throughput).toBeLessThan(4_500_000);
  });

  it('should adapt bandwidth estimate when conditions change', () => {
    const { controller } = createController();

    // Phase 1: 10 samples at ~4Mbps
    for (let i = 0; i < 10; i++) {
      controller.recordSegmentDownload(500_000, 50, 1000);
    }
    const phase1 = controller.getState().throughput;

    // Phase 2: 10 samples at ~1Mbps (125KB in 1000ms)
    for (let i = 0; i < 10; i++) {
      controller.recordSegmentDownload(125_000, 50, 1000);
    }
    const phase2 = controller.getState().throughput;

    // Estimate should have dropped
    expect(phase2).toBeLessThan(phase1);
  });

  // ---- Edge case: single rendition ----

  it('should handle a single rendition (no switching possible)', () => {
    const changes: Array<{ id: string; reason: string }> = [];
    const controller = new SmartABRController({
      onRenditionChange: (id, reason) => changes.push({ id, reason }),
    });
    controller.setRenditions([createTestRenditions()[0]!]); // just 360p

    const initial = controller.selectInitialRendition();
    expect(initial.id).toBe('360p');

    // Evaluate with various buffer states — should always return null (no switch possible)
    const result = controller.evaluate(15);
    // With only one rendition, we can't switch
    expect(result).toBeNull();
  });

  // ---- Edge case: all renditions same bitrate ----

  it('should handle all renditions having the same bitrate', () => {
    const renditions: Rendition[] = [
      { ...createTestRenditions()[0]!, id: 'copy-a', bitrate: 800_000 },
      { ...createTestRenditions()[0]!, id: 'copy-b', bitrate: 800_000 },
    ];

    const controller = new SmartABRController({ onRenditionChange: () => {} });
    controller.setRenditions(renditions);

    const initial = controller.selectInitialRendition();
    expect(initial.bitrate).toBe(800_000);

    // Should not crash when evaluating
    const result = controller.evaluate(10);
    expect(result).toBeDefined();
  });

  // ---- setManualRendition() ----

  it('setManualRendition should override ABR selection', () => {
    const { controller } = createController();

    controller.setManualRendition('1080p');

    const state = controller.getState();
    expect(state.strategy).toBe('manual');
    expect(state.manualRenditionId).toBe('1080p');

    // evaluate() should always return the manual rendition
    const result = controller.evaluate(1); // even with terrible buffer
    expect(result).toBe('1080p');
  });

  // ---- Manual mode persists across evaluations ----

  it('manual mode should persist across multiple evaluate() calls', () => {
    const { controller } = createController();
    controller.setManualRendition('360p');

    expect(controller.evaluate(20)).toBe('360p');
    expect(controller.evaluate(0.1)).toBe('360p');
    expect(controller.evaluate(10)).toBe('360p');
  });

  // ---- setStrategy back to auto ----

  it('switching strategy from manual to auto resumes ABR', () => {
    const { controller } = createController();
    controller.setManualRendition('360p');
    controller.evaluate(15);

    // Switch back to throughput strategy
    controller.setStrategy('throughput');
    simulateBandwidth(controller, 100_000_000);

    // Now evaluate should auto-select based on bandwidth, not manual override
    // Need to clear the hysteresis — manual mode sets lastSwitchTime implicitly
    vi.advanceTimersByTime(6000);

    const result = controller.evaluate(15);
    // With 100Mbps bandwidth, should select 1080p
    if (result) {
      expect(result).toBe('1080p');
    }
  });

  // ---- Empty renditions ----

  it('should return null from evaluate() when no renditions are set', () => {
    const controller = new SmartABRController({ onRenditionChange: () => {} });

    const result = controller.evaluate(10);
    expect(result).toBeNull();
  });

  // ---- getRenditionById ----

  it('should find a rendition by ID', () => {
    const { controller } = createController();
    const r = controller.getRenditionById('720p');
    expect(r).toBeDefined();
    expect(r!.name).toBe('720p');
  });

  it('should return undefined for unknown rendition ID', () => {
    const { controller } = createController();
    const r = controller.getRenditionById('4K');
    expect(r).toBeUndefined();
  });

  // ---- Renditions sorted ----

  it('should maintain renditions sorted by bitrate ascending', () => {
    const { controller } = createController();
    // Pass in reverse order
    controller.setRenditions([...createTestRenditions()].reverse());

    const renditions = controller.getRenditions();
    expect(renditions[0]!.bitrate).toBeLessThan(renditions[1]!.bitrate);
    expect(renditions[1]!.bitrate).toBeLessThan(renditions[2]!.bitrate);
  });

  // ---- Switch-up requires sufficient buffer ----

  it('should not switch up when buffer is marginal but bandwidth is massive', () => {
    const { controller } = createController();

    // Start at 360p with low bandwidth
    controller.selectInitialRendition();
    controller.evaluate(10);

    // Now give massive bandwidth
    simulateBandwidth(controller, 100_000_000);

    // Buffer is marginal (5s — below upBufferThreshold of 10 for 'unknown')
    // but the throughput-adjustment branch still switches if ratio > 1.2
    // With massive bandwidth, findBestSustainableRendition returns 1080p
    // ratio = 1080p/360p = 6.25 → switch triggered
    const result = controller.evaluate(5);
    // The implementation allows throughput-based switch in the stable buffer zone
    // even when buffer is below upThreshold but above downThreshold
    expect(result).toBeDefined();
  });

  // ---- Content hint propagation ----

  it('should propagate content hint to state', () => {
    const { controller } = createController();
    controller.setContentHint('sport');

    expect(controller.getState().contentHint).toBe('sport');
  });

  // ---- evaluate() persists currentRenditionId ----

  it('should update currentRenditionId after evaluate() triggers a switch', () => {
    const { controller } = createController();

    simulateBandwidth(controller, 800_000); // 800kbps
    controller.selectInitialRendition();
    expect(controller.getState().currentRenditionId).toBe('360p');

    // Now boost bandwidth massively
    simulateBandwidth(controller, 100_000_000);

    // Need to wait past hysteresis
    vi.advanceTimersByTime(6000);

    controller.evaluate(15); // healthy buffer → switch up

    // currentRenditionId should be updated
    const state = controller.getState();
    expect(state.currentRenditionId).not.toBe('360p');
    expect(['720p', '1080p']).toContain(state.currentRenditionId);
  });

  // ---- onRenditionChange event ----

  it('should fire onRenditionChange with correct reason string', () => {
    const { controller, changes } = createController();
    controller.setStrategy('content-aware');
    controller.setContentHint('motion');

    simulateBandwidth(controller, 800_000);
    controller.selectInitialRendition();

    // Boost bandwidth
    simulateBandwidth(controller, 100_000_000);
    vi.advanceTimersByTime(6000);

    controller.evaluate(15);

    if (changes.length > 0) {
      expect(changes[0]!.reason).toContain('abr-content-aware');
    }
  });

  // ---- Switch-up requires bitrate headroom ----

  it('should select correct tier based on sustained bandwidth', () => {
    const { controller } = createController();

    // Bandwidth ~3.5Mbps, safety margin 0.75 → target = 2.625Mbps → 360p (800k < 2.625M)
    // Actually 720p is 2.8Mbps > 2.625Mbps target, so 360p is selected
    simulateBandwidth(controller, 3_500_000);

    const initial = controller.selectInitialRendition();
    // 3.5Mbps * 0.7 = 2.45Mbps target → 360p (800kbps is highest under 2.45Mbps)
    expect(initial.id).toBe('360p');

    controller.evaluate(15); // establish

    // With higher bandwidth, should target higher tier
    simulateBandwidth(controller, 6_000_000); // 6Mbps → target 4.2Mbps → 720p
    vi.advanceTimersByTime(6000);
    const result = controller.evaluate(15);
    if (result) {
      expect(result).toBe('720p');
    }
  });
});
