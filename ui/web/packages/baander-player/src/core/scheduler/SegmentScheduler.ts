/**
 * @module core/scheduler/SegmentScheduler
 * @description Coordinates segment fetching order, manages the interplay between
 * ABR decisions, buffer state, and transport priority queue.
 *
 * Responsibilities:
 * 1. Determine which segments to fetch next (based on playhead + buffer health)
 * 2. Assign priorities to the transport layer (init > segments near playhead > prefetch)
 * 3. Handle rendition switches (abort in-flight, re-fetch init, resume at same position)
 * 4. Implement predictive prefetch using AI highlight predictions
 *
 * Backend segment URL pattern:
 *   /api/stream/{jobPublicId}/init.mp4
 *   /api/stream/{jobPublicId}/seg_{index}.m4s
 *
 * Where {jobPublicId} is the public ID of the TranscodeJob for a given quality tier.
 * Each rendition has its own jobPublicId and thus its own init.mp4 + segment set.
 */

import type {
  Manifest,
  Rendition,
  SegmentInfo,
  BufferStats,
  AudioTrack,
  SubtitleTrack,
} from '../../types';
import { AdaptiveTransportLayer } from '../transport/AdaptiveTransportLayer';
import { HybridBufferEngine } from '../buffer/HybridBufferEngine';
import { SmartABRController } from '../abr/SmartABRController';

export interface SchedulerConfig {
  /** Number of segments to look ahead for scheduling. */
  lookAhead: number;
  /** Number of segments to prefetch (beyond lookAhead). */
  prefetchCount: number;
}

const DEFAULT_SCHEDULER_CONFIG: SchedulerConfig = {
  lookAhead: 5,
  prefetchCount: 3,
};

export interface SchedulerEvents {
  onSegmentFetched: (index: number, bytes: number, ttfb: number) => void;
  onSegmentError: (index: number, error: string) => void;
  onInitFetched: (renditionId: string) => void;
  onRenditionSwitch: (fromId: string, toId: string) => void;
  onScheduleProgress: (fetched: number, total: number) => void;
}

/**
 * SegmentScheduler — the brain that decides what to fetch and when.
 *
 * Integrates with:
 *   - UnifiedManifestEngine (provides Manifest → renditions + segments)
 *   - AdaptiveTransportLayer (performs the actual HTTP fetches)
 *   - HybridBufferEngine (receives ArrayBuffer data)
 *   - SmartABRController (decides which rendition to use)
 *
 * Usage:
 * ```ts
 * const scheduler = new SegmentScheduler(transport, buffer, abr, config, events);
 * scheduler.setManifest(manifest);
 * await scheduler.start(0); // start from segment 0
 * ```
 */
export class SegmentScheduler {
  private manifest: Manifest | null = null;
  private currentRendition: Rendition | null = null;
  private nextSegmentIndex = 0;
  private initLoaded = false;
  private readonly fetchedSegments = new Set<number>();
  private aborted = false;
  private inflightRequests = new Map<string, AbortController>();
  private currentAudioTrack: AudioTrack | null = null;
  private audioInitLoaded = false;
  private readonly fetchedAudioSegments = new Set<number>();
  private currentSubtitleTrack: SubtitleTrack | null = null;
  private readonly fetchedSubtitleSegments = new Set<number>();

  constructor(
    private readonly transport: AdaptiveTransportLayer,
    private readonly buffer: HybridBufferEngine,
    private readonly abr: SmartABRController,
    private readonly config: SchedulerConfig,
    private readonly events: SchedulerEvents,
  ) {}

  /** Set the manifest and initialize. */
  setManifest(manifest: Manifest): void {
    this.manifest = manifest;
    this.abr.setRenditions(manifest.renditions);
    this.abr.setQualityLadder(manifest.qualityLadder);
    this.fetchedSegments.clear();
    this.initLoaded = false;
    this.nextSegmentIndex = 0;

    // Select default audio track
    const defaultAudio = manifest.audioTracks.find(t => t.isDefault) ?? manifest.audioTracks[0] ?? null;
    this.currentAudioTrack = defaultAudio;
    this.audioInitLoaded = false;
    this.fetchedAudioSegments.clear();

    // Select default subtitle track (off by default)
    this.currentSubtitleTrack = null;
    this.fetchedSubtitleSegments.clear();
  }

  /** Start scheduling from a given segment index. */
  async start(fromSegmentIndex: number = 0): Promise<void> {
    if (!this.manifest) throw new Error('No manifest loaded');
    this.aborted = false;

    // Select initial rendition via ABR
    const initialRendition = this.abr.selectInitialRendition();
    await this.switchRendition(initialRendition.id);

    // Fetch audio init segment alongside video init
    if (this.currentAudioTrack) {
      const audioInitResult = await this.transport.fetchInitSegment(this.currentAudioTrack.initSegmentUrl);
      if (audioInitResult.ok) {
        await this.buffer.appendAudioInit(audioInitResult.data);
        this.audioInitLoaded = true;
      }
    }

    // Start fetching from the given index
    this.nextSegmentIndex = fromSegmentIndex;
    await this.scheduleNext();
  }

  /** Stop scheduling. */
  stop(): void {
    this.aborted = true;
    for (const [, controller] of this.inflightRequests) {
      controller.abort();
    }
    this.inflightRequests.clear();
    this.fetchedAudioSegments.clear();
    this.fetchedSubtitleSegments.clear();
  }

  /** Handle a seek — reset scheduling to the new position. */
  async seekTo(timeSeconds: number): Promise<void> {
    if (!this.currentRendition) return;

    const targetIndex = this.findSegmentIndexForTime(timeSeconds);
    if (targetIndex === -1) return;

    // Abort all inflight requests
    for (const [, controller] of this.inflightRequests) {
      controller.abort();
    }
    this.inflightRequests.clear();

    this.nextSegmentIndex = targetIndex;
    this.fetchedAudioSegments.clear();
    this.fetchedSubtitleSegments.clear();
    await this.scheduleNext();
  }

  /** Evaluate ABR and potentially switch rendition. Called periodically. */
  async evaluateABR(bufferStats: BufferStats): Promise<void> {
    const newRenditionId = this.abr.evaluate(bufferStats.forwardBuffer);
    if (newRenditionId && newRenditionId !== this.currentRendition?.id) {
      await this.switchRendition(newRenditionId);
    }
  }

  /** Get the current rendition. */
  getCurrentRendition(): Rendition | null {
    return this.currentRendition;
  }

  /** Get the number of segments fetched. */
  getFetchedCount(): number {
    return this.fetchedSegments.size;
  }

  /** Find the segment index containing a given time position. */
  findSegmentIndexForTime(timeSeconds: number): number {
    if (!this.currentRendition) return -1;

    let cumulativeTime = 0;
    for (const seg of this.currentRendition.segments) {
      cumulativeTime += seg.duration;
      if (cumulativeTime > timeSeconds) {
        return seg.index;
      }
    }
    return 0;
  }

  /** Switch to a different audio language track. */
  async switchAudioLanguage(language: string): Promise<void> {
    if (!this.manifest) return;

    const track = this.manifest.audioTracks.find(t => t.language === language);
    if (!track) return;

    this.currentAudioTrack = track;
    this.audioInitLoaded = false;
    this.fetchedAudioSegments.clear();

    // Fetch new audio init segment
    const audioInitResult = await this.transport.fetchInitSegment(track.initSegmentUrl);
    if (audioInitResult.ok) {
      await this.buffer.appendAudioInit(audioInitResult.data);
      this.audioInitLoaded = true;
    }
  }

  /** Enable subtitle track by language. */
  enableSubtitle(language: string): void {
    if (!this.manifest) return;

    const track = this.manifest.subtitleTracks.find(t => t.language === language);
    if (track) {
      this.currentSubtitleTrack = track;
      this.fetchedSubtitleSegments.clear();
    }
  }

  /** Get the current audio track. */
  getCurrentAudioTrack(): AudioTrack | null {
    return this.currentAudioTrack;
  }

  /** Get the current subtitle track. */
  getCurrentSubtitleTrack(): SubtitleTrack | null {
    return this.currentSubtitleTrack;
  }

  // -----------------------------------------------------------------------
  // Private
  // -----------------------------------------------------------------------

  private async switchRendition(renditionId: string): Promise<void> {
    if (!this.manifest) return;

    const oldId = this.currentRendition?.id ?? '';
    const rendition = this.manifest.renditions.find(r => r.id === renditionId);
    if (!rendition) return;

    // Abort all inflight requests (video, audio, subtitle)
    for (const [, controller] of this.inflightRequests) {
      controller.abort();
    }
    this.inflightRequests.clear();

    const previousIndex = this.nextSegmentIndex;
    this.currentRendition = rendition;
    this.initLoaded = false;
    this.fetchedSegments.clear();
    this.fetchedAudioSegments.clear();
    this.fetchedSubtitleSegments.clear();

    // Initialize buffer for new rendition
    await this.buffer.init(rendition);

    // Fetch init segment
    const initResult = await this.transport.fetchInitSegment(rendition.initSegmentUrl);
    if (initResult.ok) {
      await this.buffer.appendInit(initResult.data);
      this.initLoaded = true;
      this.events.onInitFetched(rendition.id);
    } else {
      throw new Error(`Failed to fetch init segment for ${rendition.id}: ${initResult.reason}`);
    }

    // Restore position
    this.nextSegmentIndex = previousIndex;

    if (oldId && oldId !== renditionId) {
      this.events.onRenditionSwitch(oldId, renditionId);
    }
  }

  private async scheduleNext(): Promise<void> {
    if (!this.currentRendition || this.aborted || !this.initLoaded) return;

    const segments = this.currentRendition.segments;
    const total = segments.length;

    // Schedule video segments
    for (let i = 0; i < this.config.lookAhead + this.config.prefetchCount; i++) {
      const idx = this.nextSegmentIndex + i;
      if (idx >= total) break;
      if (this.fetchedSegments.has(idx)) continue;
      if (this.inflightRequests.has(`video:${idx}`)) continue;

      const seg = segments[idx]!;
      const priority = i < this.config.lookAhead ? i : this.config.lookAhead + (i - this.config.lookAhead);
      this.fetchSegment(seg, priority);
    }

    // Schedule audio segments
    if (this.currentAudioTrack && this.audioInitLoaded) {
      const audioSegments = this.currentAudioTrack.segments;
      for (let i = 0; i < this.config.lookAhead + this.config.prefetchCount; i++) {
        const idx = this.nextSegmentIndex + i;
        if (idx >= audioSegments.length) break;
        if (this.fetchedAudioSegments.has(idx)) continue;
        if (this.inflightRequests.has(`audio:${idx}`)) continue;

        const seg = audioSegments[idx]!;
        const priority = i < this.config.lookAhead ? i : this.config.lookAhead + (i - this.config.lookAhead);
        this.fetchAudioSegment(seg, priority);
      }
    }

    // Schedule subtitle segments
    if (this.currentSubtitleTrack) {
      const subSegments = this.currentSubtitleTrack.segments;
      for (let i = 0; i < this.config.lookAhead + this.config.prefetchCount; i++) {
        const idx = this.nextSegmentIndex + i;
        if (idx >= subSegments.length) break;
        if (this.fetchedSubtitleSegments.has(idx)) continue;
        if (this.inflightRequests.has(`sub:${idx}`)) continue;

        const seg = subSegments[idx]!;
        this.fetchSubtitleSegment(seg);
      }
    }
  }

  private async fetchSegment(seg: SegmentInfo, priority: number): Promise<void> {
    if (this.fetchedSegments.has(seg.index)) return;
    const key = `video:${seg.index}`;
    if (this.inflightRequests.has(key)) return;

    const controller = new AbortController();
    this.inflightRequests.set(key, controller);

    try {
      const result = await this.transport.fetchSegment(seg.uri, { priority });

      if (!result.ok) {
        this.events.onSegmentError(seg.index, result.reason);
        return;
      }

      await this.buffer.appendSegment(seg.index, result.data);
      this.fetchedSegments.add(seg.index);

      this.abr.recordSegmentDownload(result.byteLength, result.ttfb, result.downloadMs);
      this.events.onSegmentFetched(seg.index, result.byteLength, result.ttfb);

      if (seg.index === this.nextSegmentIndex) {
        this.nextSegmentIndex++;
        await this.scheduleNext();
      }

      if (this.manifest) {
        const total = this.currentRendition?.segments.length ?? 0;
        this.events.onScheduleProgress(this.fetchedSegments.size, total);
      }
    } finally {
      this.inflightRequests.delete(key);
    }
  }

  private async fetchAudioSegment(seg: SegmentInfo, priority: number): Promise<void> {
    if (this.fetchedAudioSegments.has(seg.index)) return;
    const key = `audio:${seg.index}`;
    if (this.inflightRequests.has(key)) return;

    const controller = new AbortController();
    this.inflightRequests.set(key, controller);

    try {
      const result = await this.transport.fetchSegment(seg.uri, { priority });

      if (!result.ok) {
        return;
      }

      await this.buffer.appendAudioSegment(result.data);
      this.fetchedAudioSegments.add(seg.index);
    } finally {
      this.inflightRequests.delete(key);
    }
  }

  private async fetchSubtitleSegment(seg: SegmentInfo): Promise<void> {
    if (this.fetchedSubtitleSegments.has(seg.index)) return;
    const key = `sub:${seg.index}`;
    if (this.inflightRequests.has(key)) return;

    const controller = new AbortController();
    this.inflightRequests.set(key, controller);

    try {
      const result = await this.transport.fetchSegment(seg.uri, { priority: 0 });

      if (!result.ok) {
        return;
      }

      this.fetchedSubtitleSegments.add(seg.index);
    } finally {
      this.inflightRequests.delete(key);
    }
  }
}
