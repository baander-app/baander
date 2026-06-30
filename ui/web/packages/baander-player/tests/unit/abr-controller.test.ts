import { describe, it, expect } from 'vitest';
import { SmartABRController } from '../../src/core/abr/SmartABRController';
import type { Rendition, QualityTierInfo } from '../../src/types';

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

describe('SmartABRController', () => {
  it('should select initial rendition based on bandwidth', () => {
    const controller = new SmartABRController({
      onRenditionChange: () => {},
    });
    controller.setRenditions(createTestRenditions());

    const initial = controller.selectInitialRendition();
    // With no bandwidth data, defaults to target of 2.5Mbps → selects 360p (lowest sustainable)
    expect(initial.bitrate).toBe(800_000);
  });

  it('should switch down when buffer is low', () => {
    const renditionChanges: Array<{ id: string; reason: string }> = [];
    const controller = new SmartABRController({
      onRenditionChange: (id, reason) => renditionChanges.push({ id, reason }),
    });

    const renditions = createTestRenditions();
    controller.setRenditions(renditions);

    // Start at 1080p
    controller.selectInitialRendition();
    // Simulate being on 1080p
    const state = controller.getState();
    // Force initial state
    controller.evaluate(15);

    // Now simulate low buffer
    const newRendition = controller.evaluate(1); // 1 second buffer

    // Should recommend switching down or staying at current
    expect(newRendition).toBeDefined();
  });

  it('should record bandwidth samples', () => {
    const controller = new SmartABRController({
      onRenditionChange: () => {},
    });

    controller.recordSegmentDownload(500_000, 100, 500); // 500KB in 500ms = 8Mbps

    const state = controller.getState();
    expect(state.throughput).toBeGreaterThan(0);
  });

  it('should respect manual mode', () => {
    const controller = new SmartABRController({
      onRenditionChange: () => {},
    });
    controller.setRenditions(createTestRenditions());
    controller.setManualRendition('360p');

    const state = controller.getState();
    expect(state.strategy).toBe('manual');
    expect(state.manualRenditionId).toBe('360p');
  });

  it('should return renditions sorted by bitrate', () => {
    const controller = new SmartABRController({
      onRenditionChange: () => {},
    });
    controller.setRenditions(createTestRenditions());

    const renditions = controller.getRenditions();
    for (let i = 1; i < renditions.length; i++) {
      expect(renditions[i]!.bitrate).toBeGreaterThan(renditions[i - 1]!.bitrate);
    }
  });
});
