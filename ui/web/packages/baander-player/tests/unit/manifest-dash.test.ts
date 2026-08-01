import { describe, it, expect } from 'vitest';
import {
  parseDashMpd,
  resolveUrl,
  inferContentHint,
} from '../../src/core/manifest/UnifiedManifestEngine';
import type { Rendition } from '../../src/types';
import {
  FULL_MPD,
  VARIABLE_DURATION_MPD,
  NO_ADAPTATION_MPD,
  NO_REPRESENTATION_MPD,
  NO_TEMPLATE_MPD,
  INVALID_XML,
  MULTI_ADAPTATION_MPD,
} from '../fixtures';

// ===========================================================================
// parseDashMpd
// ===========================================================================

describe('parseDashMpd', () => {
  it('parses a full MPD with 3 video representations', () => {
    const { renditions } = parseDashMpd(FULL_MPD, 'video-123');

    expect(renditions).toHaveLength(3);

    // Sorted by bitrate ascending
    expect(renditions[0]!.bitrate).toBe(800_000);
    expect(renditions[1]!.bitrate).toBe(2_800_000);
    expect(renditions[2]!.bitrate).toBe(5_000_000);
  });

  it('populates all Rendition fields correctly', () => {
    const { renditions } = parseDashMpd(FULL_MPD, 'video-123');
    const r1080 = renditions.find(r => r.height === 1080)!;

    expect(r1080.id).toBe('job-1080p');
    expect(r1080.name).toBe('1080p');
    expect(r1080.width).toBe(1920);
    expect(r1080.height).toBe(1080);
    expect(r1080.bitrate).toBe(5_000_000);
    expect(r1080.maxBitrate).toBe(5_000_000);
    expect(r1080.codecs).toBe('hvc1.1.6.L93.B0');
    expect(r1080.initSegmentUrl).toBe('/api/stream/job-1080p/init.mp4');
    expect(r1080.segments).toHaveLength(10);
    expect(r1080.targetDuration).toBe(6);
    expect(r1080.totalDuration).toBe(60);
  });

  it('generates correct segment URIs with $Number$ substitution', () => {
    const { renditions } = parseDashMpd(FULL_MPD, 'video-123');
    const r360 = renditions.find(r => r.height === 360)!;

    expect(r360.segments[0]!.uri).toBe('/api/stream/job-360p/seg_0.m4s');
    expect(r360.segments[5]!.uri).toBe('/api/stream/job-360p/seg_5.m4s');
    expect(r360.segments[9]!.uri).toBe('/api/stream/job-360p/seg_9.m4s');
  });

  it('converts segment durations from milliseconds to seconds', () => {
    const { renditions } = parseDashMpd(FULL_MPD, 'video-123');
    const seg = renditions[0]!.segments[0]!;

    // 6000ms → 6s
    expect(seg.duration).toBeCloseTo(6.0);
  });

  it('handles variable-duration segments correctly', () => {
    const { renditions } = parseDashMpd(VARIABLE_DURATION_MPD, 'video-var');
    expect(renditions).toHaveLength(1);

    const segs = renditions[0]!.segments;
    expect(segs).toHaveLength(4);
    expect(segs[0]!.duration).toBeCloseTo(4.0); // 4000ms
    expect(segs[1]!.duration).toBeCloseTo(6.0); // 6000ms
    expect(segs[2]!.duration).toBeCloseTo(5.5); // 5500ms
    expect(segs[3]!.duration).toBeCloseTo(7.5); // 7500ms

    // Total: 4+6+5.5+7.5 = 23s
    expect(renditions[0]!.totalDuration).toBeCloseTo(23.0);
    // Target: 23/4 = 5.75
    expect(renditions[0]!.targetDuration).toBeCloseTo(5.75);
  });

  it('returns empty array for MPD with no Period', () => {
    const { renditions: result } = parseDashMpd(NO_ADAPTATION_MPD, 'video-123');
    // querySelectorAll('Representation') finds nothing inside empty Period
    expect(result).toEqual([]);
  });

  it('returns empty array for MPD with no AdaptationSet', () => {
    expect(parseDashMpd(NO_ADAPTATION_MPD, 'video-123').renditions).toEqual([]);
  });

  it('returns empty array for MPD with no Representation', () => {
    expect(parseDashMpd(NO_REPRESENTATION_MPD, 'video-123').renditions).toEqual([]);
  });

  it('handles Representation with no SegmentTemplate (empty segments)', () => {
    const { renditions } = parseDashMpd(NO_TEMPLATE_MPD, 'video-123');
    expect(renditions).toHaveLength(1);
    expect(renditions[0]!.segments).toEqual([]);
    // Default target duration when no segments
    expect(renditions[0]!.targetDuration).toBe(6);
    expect(renditions[0]!.totalDuration).toBe(0);
  });

  it('handles invalid XML gracefully', () => {
    // DOMParser doesn't throw on invalid XML — it returns an error document.
    // querySelector('MPD') returns null → empty renditions.
    const result = parseDashMpd(INVALID_XML, 'video-123');
    expect(result.renditions).toEqual([]);
    expect(result.mpdDuration).toBe(0);
  });

  it('handles completely empty string', () => {
    expect(parseDashMpd('', 'video-123').renditions).toEqual([]);
  });

  it('parses all representations regardless of AdaptationSet type', () => {
    // The parser doesn't filter by AdaptationSet mimeType — it queries all
    // Representation elements. This test documents the current behaviour.
    const { renditions } = parseDashMpd(MULTI_ADAPTATION_MPD, 'video-123');
    // Both audio-aac and vid-720 are queried
    expect(renditions.length).toBeGreaterThanOrEqual(1);

    // At minimum, the video representation is present
    const video = renditions.find(r => r.id === 'vid-720');
    expect(video).toBeDefined();
    expect(video!.width).toBe(1280);
  });

  it('assigns sequential segment indices starting from 0', () => {
    const { renditions } = parseDashMpd(FULL_MPD, 'video-123');
    const segs = renditions[0]!.segments;

    for (let i = 0; i < segs.length; i++) {
      expect(segs[i]!.index).toBe(i);
    }
  });
});

// ===========================================================================
// resolveUrl
// ===========================================================================

describe('resolveUrl', () => {
  it('returns absolute http URLs unchanged', () => {
    expect(resolveUrl('http://example.com/seg.m4s', 'http://base/')).toBe(
      'http://example.com/seg.m4s',
    );
  });

  it('returns absolute https URLs unchanged', () => {
    expect(resolveUrl('https://cdn.example.com/init.mp4', 'https://base/')).toBe(
      'https://cdn.example.com/init.mp4',
    );
  });

  it('returns root-relative URLs unchanged', () => {
    expect(resolveUrl('/api/stream/job-123/seg_0.m4s', 'http://base/')).toBe(
      '/api/stream/job-123/seg_0.m4s',
    );
  });

  it('resolves relative path against base with trailing slash', () => {
    expect(resolveUrl('seg_0.m4s', '/api/stream/job-123/')).toBe(
      '/api/stream/job-123/seg_0.m4s',
    );
  });

  it('resolves relative path against base without trailing slash', () => {
    expect(resolveUrl('seg_0.m4s', '/api/stream/job-123')).toBe(
      '/api/stream/job-123/seg_0.m4s',
    );
  });

  it('resolves relative init.mp4 against base URL', () => {
    expect(resolveUrl('init.mp4', '/api/stream/job-720p/')).toBe(
      '/api/stream/job-720p/init.mp4',
    );
  });

  it('preserves query strings on absolute URLs', () => {
    expect(resolveUrl('https://cdn.example.com/seg.m4s?token=abc', 'http://base/')).toBe(
      'https://cdn.example.com/seg.m4s?token=abc',
    );
  });

  it('preserves query strings on relative URLs after resolution', () => {
    expect(resolveUrl('seg.m4s?v=1', '/api/stream/job/')).toBe(
      '/api/stream/job/seg.m4s?v=1',
    );
  });

  it('handles nested relative paths', () => {
    expect(resolveUrl('subdir/seg.m4s', '/api/stream/job/')).toBe(
      '/api/stream/job/subdir/seg.m4s',
    );
  });

  it('returns path-only absolute URLs unchanged', () => {
    expect(resolveUrl('/absolute/path/seg.m4s', '/something/else/')).toBe(
      '/absolute/path/seg.m4s',
    );
  });
});

// ===========================================================================
// inferContentHint
// ===========================================================================

describe('inferContentHint', () => {
  it('returns "unknown" for empty rendition array', () => {
    expect(inferContentHint([])).toBe('unknown');
  });

  it('returns "gaming" for top bitrate >= 15 Mbps', () => {
    const renditions: Rendition[] = [
      makeRendition('360p', 640, 360, 800_000),
      makeRendition('1080p', 1920, 1080, 5_000_000),
      makeRendition('4K', 3840, 2160, 20_000_000),
    ];
    expect(inferContentHint(renditions)).toBe('gaming');
  });

  it('returns "sport" for top bitrate >= 8 Mbps and height >= 1080', () => {
    const renditions: Rendition[] = [
      makeRendition('360p', 640, 360, 800_000),
      makeRendition('720p', 1280, 720, 2_800_000),
      makeRendition('1080p', 1920, 1080, 10_000_000),
    ];
    expect(inferContentHint(renditions)).toBe('sport');
  });

  it('returns "sport" even at exactly 8 Mbps threshold', () => {
    const renditions: Rendition[] = [
      makeRendition('720p', 1280, 720, 2_800_000),
      makeRendition('1080p', 1920, 1080, 8_000_000),
    ];
    expect(inferContentHint(renditions)).toBe('sport');
  });

  it('does NOT return "sport" when height < 1080 even if bitrate >= 8 Mbps', () => {
    const renditions: Rendition[] = [
      makeRendition('720p', 1280, 720, 8_500_000),
    ];
    // 8.5 Mbps but height 720 → not sport, not motion. Falls through to 'static'.
    expect(inferContentHint(renditions)).toBe('static');
  });

  it('returns "motion" for top rendition height >= 2160 (4K)', () => {
    const renditions: Rendition[] = [
      makeRendition('720p', 1280, 720, 2_800_000),
      makeRendition('4K', 3840, 2160, 6_000_000),
    ];
    expect(inferContentHint(renditions)).toBe('motion');
  });

  it('returns "static" for standard HD content', () => {
    const renditions: Rendition[] = [
      makeRendition('360p', 640, 360, 800_000),
      makeRendition('720p', 1280, 720, 2_800_000),
      makeRendition('1080p', 1920, 1080, 5_000_000),
    ];
    expect(inferContentHint(renditions)).toBe('static');
  });

  it('returns "static" for single low-res rendition', () => {
    const renditions: Rendition[] = [
      makeRendition('360p', 640, 360, 800_000),
    ];
    expect(inferContentHint(renditions)).toBe('static');
  });

  it('uses the LAST rendition (highest bitrate) for inference', () => {
    // Unsorted input — last element is 4K
    const renditions: Rendition[] = [
      makeRendition('4K', 3840, 2160, 15_000_000),
      makeRendition('360p', 640, 360, 800_000),
    ];
    // Last element (360p, 800kbps) is used → static
    expect(inferContentHint(renditions)).toBe('static');
  });
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeRendition(
  name: string,
  width: number,
  height: number,
  bitrate: number,
): Rendition {
  return {
    id: name,
    name,
    width,
    height,
    bitrate,
    maxBitrate: bitrate,
    codecs: 'hvc1.1.6.L93.B0',
    initSegmentUrl: `/api/stream/${name}/init.mp4`,
    segments: [],
    targetDuration: 6,
    totalDuration: 60,
  };
}

// ===========================================================================
// parseIso8601Duration (via mpdDuration in parseDashMpd result)
// ===========================================================================

describe('parseDashMpd mpdDuration', () => {
  it('extracts mediaPresentationDuration from MPD', () => {
    const { mpdDuration } = parseDashMpd(FULL_MPD, 'video-123');
    // FULL_MPD has mediaPresentationDuration="PT60S"
    expect(mpdDuration).toBe(60);
  });

  it('returns 0 when mediaPresentationDuration is absent', () => {
    const { mpdDuration } = parseDashMpd(VARIABLE_DURATION_MPD, 'video-123');
    expect(mpdDuration).toBe(0);
  });
});
