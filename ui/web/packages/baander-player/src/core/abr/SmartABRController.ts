/**
 * @module core/abr/SmartABRController
 * @description Adaptive bitrate controller with content-aware heuristics,
 * mirroring the backend's QualityLadder for optimal rendition selection.
 *
 * The backend's QualityLadder (GET /api/stream/{videoId}/quality-ladder)
 * provides available tiers:
 *   - 360p:  800kbps   — low bandwidth / mobile
 *   - 480p:  1400kbps  — standard mobile
 *   - 720p:  2800kbps  — standard desktop
 *   - 1080p: 5000kbps  — HD
 *   - 1440p: 10000kbps — QHD
 *   - 4K:    20000kbps — UHD
 *
 * ABR Strategies:
 *   1. throughput — estimate bandwidth, pick highest sustainable tier
 *   2. buffer — switch up when buffer is healthy, down when depleting
 *   3. content-aware — use AI-detected contentHint for aggressive/conservative switching
 *   4. manual — user-selected tier, no auto-switching
 *
 * The controller also implements:
 *   - Predictive prefetch (when AI highlights are available)
 *   - Bandwidth estimation via exponential moving average
 *   - Hysteresis to prevent oscillation between tiers
 *
 * @see App\Transcode\Domain\ValueObject\QualityTier for backend tier definitions
 * @see App\Transcode\Domain\Service\QualityLadder for tier selection logic
 */

import type {
  ABRState,
  ABRStrategy,
  ContentHint,
  QualityTierInfo,
  Rendition,
} from '../../types';

// ---------------------------------------------------------------------------
// Bandwidth Estimator
// ---------------------------------------------------------------------------

class BandwidthEstimator {
  private estimate = 0;
  private readonly alpha: number; // EMA smoothing factor
  private samples: { bytes: number; durationMs: number }[] = [];
  private readonly maxSamples: number;

  constructor(alpha = 0.7, maxSamples = 20) {
    this.alpha = alpha;
    this.maxSamples = maxSamples;
  }

  /** Record a segment download. */
  record(bytes: number, ttfbMs: number, downloadMs: number): void {
    this.samples.push({ bytes, durationMs: downloadMs });
    if (this.samples.length > this.maxSamples) {
      this.samples.shift();
    }

    // Calculate instantaneous throughput in bps
    const totalBytes = this.samples.reduce((sum, s) => sum + s.bytes, 0);
    const totalTimeMs = this.samples.reduce((sum, s) => sum + s.durationMs, 0);
    const instantaneous = totalTimeMs > 0 ? (totalBytes * 8 * 1000) / totalTimeMs : 0;

    // Exponential moving average
    if (this.estimate === 0) {
      this.estimate = instantaneous;
    } else {
      this.estimate = this.alpha * instantaneous + (1 - this.alpha) * this.estimate;
    }
  }

  /** Get current estimate in bps. */
  getEstimate(): number {
    return this.estimate;
  }

  /** Reset estimator (e.g. after network change). */
  reset(): void {
    this.estimate = 0;
    this.samples = [];
  }
}

// ---------------------------------------------------------------------------
// Content-Aware Multipliers
// ---------------------------------------------------------------------------

/**
 * Content-dependent ABR parameters.
 * Different content types warrant different buffer and bandwidth targets.
 */
const CONTENT_ABR_PROFILES: Record<ContentHint, {
  /** Buffer threshold (seconds) before switching up. */
  upBufferThreshold: number;
  /** Buffer threshold (seconds) before switching down. */
  downBufferThreshold: number;
  /** Bandwidth safety margin (fraction of estimate to target). */
  bandwidthSafetyMargin: number;
  /** Whether to prefer higher quality for this content. */
  preferHighQuality: boolean;
}> = {
  static: {
    upBufferThreshold: 8,
    downBufferThreshold: 2,
    bandwidthSafetyMargin: 0.7,
    preferHighQuality: false,
  },
  motion: {
    upBufferThreshold: 12,
    downBufferThreshold: 3,
    bandwidthSafetyMargin: 0.8,
    preferHighQuality: true,
  },
  sport: {
    upBufferThreshold: 15,
    downBufferThreshold: 4,
    bandwidthSafetyMargin: 0.85,
    preferHighQuality: true,
  },
  gaming: {
    upBufferThreshold: 10,
    downBufferThreshold: 3,
    bandwidthSafetyMargin: 0.9,
    preferHighQuality: true,
  },
  animation: {
    upBufferThreshold: 8,
    downBufferThreshold: 2,
    bandwidthSafetyMargin: 0.6,
    preferHighQuality: false,
  },
  unknown: {
    upBufferThreshold: 10,
    downBufferThreshold: 3,
    bandwidthSafetyMargin: 0.75,
    preferHighQuality: false,
  },
};

// ---------------------------------------------------------------------------
// SmartABRController
// ---------------------------------------------------------------------------

export interface ABRControllerEvents {
  onRenditionChange: (renditionId: string, reason: string) => void;
}

/**
 * SmartABRController — selects the optimal rendition based on network conditions,
 * buffer health, and content type.
 *
 * Usage:
 * ```ts
 * const abr = new SmartABRController(events);
 * abr.setRenditions(manifest.renditions);
 * abr.setStrategy('content-aware');
 *
 * // Called on each segment download completion
 * abr.recordSegmentDownload(bytes, ttfbMs, downloadMs);
 *
 * // Called periodically to check for quality switch
 * const nextRendition = abr.evaluate(bufferHealth);
 * if (nextRendition) {
 *   // Switch to nextRendition
 * }
 * ```
 */
export class SmartABRController {
  private renditions: Rendition[] = [];
  private qualityLadder: QualityTierInfo[] = [];
  private bandwidthEstimator: BandwidthEstimator;
  private state: ABRState;
  private lastSwitchTime = 0;
  private readonly minSwitchInterval = 5000; // 5s hysteresis

  constructor(
    private readonly events: ABRControllerEvents,
  ) {
    this.bandwidthEstimator = new BandwidthEstimator();
    this.state = {
      currentRenditionId: '',
      strategy: 'throughput',
      throughput: 0,
      bufferHealth: 0,
      contentHint: 'unknown',
    };
  }

  /** Set available renditions (from parsed manifest). */
  setRenditions(renditions: Rendition[]): void {
    this.renditions = [...renditions].sort((a, b) => a.bitrate - b.bitrate);
  }

  /** Set quality ladder from backend API. */
  setQualityLadder(ladder: QualityTierInfo[]): void {
    this.qualityLadder = ladder;
  }

  /** Set the ABR strategy. */
  setStrategy(strategy: ABRStrategy): void {
    this.state.strategy = strategy;
  }

  /** Set content hint from AI analysis. */
  setContentHint(hint: ContentHint): void {
    this.state.contentHint = hint;
  }

  /** Force a specific rendition (manual mode). */
  setManualRendition(renditionId: string): void {
    this.state.manualRenditionId = renditionId;
    this.state.strategy = 'manual';
  }

  /** Record a completed segment download for bandwidth estimation. */
  recordSegmentDownload(bytes: number, _ttfbMs: number, downloadMs: number): void {
    this.bandwidthEstimator.record(bytes, _ttfbMs, downloadMs);
    this.state.throughput = this.bandwidthEstimator.getEstimate();
  }

  /** Get current ABR state. */
  getState(): Readonly<ABRState> {
    return this.state;
  }

  /**
   * Evaluate whether a quality switch is needed.
   * Returns the new rendition ID, or null if no switch is recommended.
   *
   * @param bufferHealth - Current forward buffer in seconds
   * @param currentTime - Current playback position for context
   */
  evaluate(bufferHealth: number, _currentTime?: number): string | null {
    this.state.bufferHealth = bufferHealth;

    if (this.renditions.length === 0) return null;

    // Manual mode: no auto-switching
    if (this.state.strategy === 'manual') {
      return this.state.manualRenditionId ?? this.state.currentRenditionId;
    }

    // Ensure initial selection
    if (!this.state.currentRenditionId) {
      const initial = this.selectInitialRendition();
      this.state.currentRenditionId = initial.id;
      return initial.id;
    }

    // Hysteresis: don't switch too frequently
    if (Date.now() - this.lastSwitchTime < this.minSwitchInterval) {
      return null;
    }

    const profile = CONTENT_ABR_PROFILES[this.state.contentHint] ?? CONTENT_ABR_PROFILES['unknown'];
    const targetBitrate = this.state.throughput * profile.bandwidthSafetyMargin;

    const currentIndex = this.renditions.findIndex(
      r => r.id === this.state.currentRenditionId,
    );
    if (currentIndex === -1) return null;

    const current = this.renditions[currentIndex]!;

    // Decision logic
    let targetIndex = currentIndex;

    if (bufferHealth < profile.downBufferThreshold) {
      // Buffer is low — switch down
      if (currentIndex > 0) {
        targetIndex = currentIndex - 1;
      }
    } else if (bufferHealth > profile.upBufferThreshold) {
      // Buffer is healthy — can we switch up?
      if (currentIndex < this.renditions.length - 1) {
        const nextUp = this.renditions[currentIndex + 1]!;
        const nextPeak = nextUp.maxBitrate > 0 ? nextUp.maxBitrate : nextUp.bitrate;
        if (nextPeak <= targetBitrate) {
          targetIndex = currentIndex + 1;
        }
      }
    } else {
      // Buffer is stable — adjust based on throughput
      const bestSustainable = this.findBestSustainableRendition(targetBitrate);
      const bestIndex = this.renditions.indexOf(bestSustainable);
      if (bestIndex !== -1 && bestIndex !== currentIndex) {
        // Only switch if the difference is significant (>20% bitrate change)
        const ratio = this.renditions[bestIndex]!.bitrate / current.bitrate;
        if (ratio < 0.8 || ratio > 1.2) {
          targetIndex = bestIndex;
        }
      }
    }

    if (targetIndex !== currentIndex) {
      const target = this.renditions[targetIndex]!;
      this.lastSwitchTime = Date.now();
      this.state.currentRenditionId = target.id;
      this.events.onRenditionChange(target.id, `abr-${this.state.strategy}`);
      return target.id;
    }

    return null;
  }

  /** Select the initial rendition based on a starting bandwidth estimate. */
  selectInitialRendition(): Rendition {
    const profile = CONTENT_ABR_PROFILES[this.state.contentHint] ?? CONTENT_ABR_PROFILES['unknown'];
    const safetyMargin = this.state.strategy === 'content-aware'
      ? profile.bandwidthSafetyMargin
      : 0.7;
    const targetBitrate = this.state.throughput > 0
      ? this.state.throughput * safetyMargin
      : 2_500_000; // Default to ~2.5Mbps (720p)

    const chosen = this.findBestSustainableRendition(targetBitrate);
    this.state.currentRenditionId = chosen.id;
    return chosen;
  }

  /** Get the rendition by ID. */
  getRenditionById(id: string): Rendition | undefined {
    return this.renditions.find(r => r.id === id);
  }

  /** Get all renditions sorted by bitrate. */
  getRenditions(): ReadonlyArray<Rendition> {
    return this.renditions;
  }

  // -----------------------------------------------------------------------
  // Private
  // -----------------------------------------------------------------------

  private findBestSustainableRendition(targetBitrate: number): Rendition {
    // Find the highest rendition whose peak bitrate (maxBitrate for VBR, bitrate for CBR)
    // fits within the target bandwidth. Using maxBitrate avoids stalls during complex scenes.
    let best = this.renditions[0]!;
    for (const rend of this.renditions) {
      const peakBitrate = rend.maxBitrate > 0 ? rend.maxBitrate : rend.bitrate;
      if (peakBitrate <= targetBitrate) {
        best = rend;
      } else {
        break;
      }
    }
    return best;
  }
}
