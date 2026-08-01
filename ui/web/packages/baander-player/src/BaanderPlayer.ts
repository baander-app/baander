/**
 * @module BaanderPlayer
 * @description Main player class that wires together all subsystems:
 *   - UnifiedManifestEngine (manifest parsing)
 *   - AdaptiveTransportLayer (segment fetching)
 *   - HybridBufferEngine (MSE + WebCodecs)
 *   - SmartABRController (quality selection)
 *   - PlaybackStateMachine (state management)
 *   - SegmentScheduler (fetch orchestration)
 *   - ImmersiveRenderer (360° / WebXR)
 *   - AIOrchestrator (scene classification)
 *   - PartySyncBus (co-watching)
 *   - OfflineStore (caching)
 *   - TelemetryReporter (analytics)
 *
 * Usage:
 * ```ts
 * const player = new BaanderPlayer({
 *   baseUrl: 'https://baander.local',
 *   preferredFormat: 'hls',
 *   features: { immersive: true, webxr: true },
 * });
 *
 * await player.attach(videoElement);
 * await player.load('video-uuid-here');
 * player.play();
 * ```
 */

import type {
  PlayerConfig,
  Manifest,
  PlaybackState,
  PlaybackError,
  Rendition,
  BufferStats,
  ABRStrategy,
  ProjectionType,
  SpatialState,
  QualityTierInfo,
} from './types';
import { DEFAULT_CONFIG } from './types';
import { UnifiedManifestEngine } from './core/manifest/UnifiedManifestEngine';
import { AdaptiveTransportLayer } from './core/transport/AdaptiveTransportLayer';
import { HybridBufferEngine } from './core/buffer/HybridBufferEngine';
import { SmartABRController } from './core/abr/SmartABRController';
import { PlaybackStateMachine } from './core/state/PlaybackStateMachine';
import { SegmentScheduler } from './core/scheduler/SegmentScheduler';
import { TelemetryReporter } from './core/telemetry/TelemetryReporter';
import { ImmersiveRenderer } from './immersive/ImmersiveRenderer';
import { AIOrchestrator } from './ai/AIOrchestrator';
import { PartySyncBus } from './party/PartySyncBus';
import { OfflineStore } from './offline/OfflineStore';

// ---------------------------------------------------------------------------
// Player Events
// ---------------------------------------------------------------------------

export interface PlayerEvents {
  onStateChange: (state: PlaybackState) => void;
  onManifestLoaded: (manifest: Manifest) => void;
  onRenditionChange: (rendition: Rendition) => void;
  onBufferUpdate: (stats: BufferStats) => void;
  onTimeUpdate: (currentTime: number, duration: number) => void;
  onError: (error: PlaybackError) => void;
  onQualityChange: (tier: QualityTierInfo) => void;
}

// ---------------------------------------------------------------------------
// Shared no-op for intentional silencing (vs ad-hoc empty lambdas)
// ---------------------------------------------------------------------------

/** Reusable no-op callback. Use when an event must be satisfied but has no consumer. */
// eslint-disable-next-line @typescript-eslint/no-empty-function
const noop = () => {};

// ---------------------------------------------------------------------------
// BaanderPlayer
// ---------------------------------------------------------------------------

/**
 * BaanderPlayer — the main entry point for the immersive video player.
 *
 * Coordinates all subsystems through a clean event-driven architecture.
 * No external playback libraries — pure TypeScript + Web APIs.
 */
export class BaanderPlayer {
  // Configuration
  private readonly config: PlayerConfig;

  // Core subsystems
  private stateMachine: PlaybackStateMachine;
  private manifestEngine: UnifiedManifestEngine;
  private transport: AdaptiveTransportLayer;
  private buffer: HybridBufferEngine = null as unknown as HybridBufferEngine;
  private abr: SmartABRController;
  private scheduler: SegmentScheduler = null as unknown as SegmentScheduler;
  private telemetry: TelemetryReporter;

  // Optional subsystems
  private immersiveRenderer: ImmersiveRenderer | null = null;
  private aiOrchestrator: AIOrchestrator | null = null;
  private partyBus: PartySyncBus | null = null;
  private offlineStore: OfflineStore | null = null;

  // DOM references
  private videoElement: HTMLVideoElement | null = null;
  private container: HTMLElement | null = null;

  // Listener cleanup
  private videoEventsAbort: AbortController | null = null;

  // State
  private manifest: Manifest | null = null;
  private sessionId: string;
  private abrEvaluationTimer: ReturnType<typeof setInterval> | null = null;
  private timeUpdateTimer: ReturnType<typeof setInterval> | null = null;

  // Events
  private readonly listeners: PlayerEvents;

  constructor(config: Partial<PlayerConfig> & { baseUrl: string }, events: PlayerEvents) {
    this.config = { ...DEFAULT_CONFIG, ...config };
    this.listeners = events;
    this.sessionId = crypto.randomUUID();

    // Initialize core subsystems
    this.stateMachine = new PlaybackStateMachine({
      onStateChange: (from, to) => this.listeners.onStateChange(to),
      onError: (error) => this.listeners.onError(error),
    });

    this.manifestEngine = new UnifiedManifestEngine(
      {
        baseUrl: this.config.baseUrl,
        preferredFormat: this.config.preferredFormat,
        customHeaders: this.config.customHeaders,
      },
      {
        onManifestUpdate: (manifest) => this.handleManifestUpdate(manifest),
        onError: (error) => this.handlePlaybackError({
          code: 'manifest-error',
          message: error.message,
          fatal: false,
          retry: 'reload-manifest',
        }),
      },
    );

    this.transport = new AdaptiveTransportLayer({
      baseUrl: this.config.baseUrl,
      customHeaders: this.config.customHeaders ?? {},
    });

    this.abr = new SmartABRController({
      onRenditionChange: (id, reason) => this.handleRenditionChange(id, reason),
    });

    // buffer and scheduler are created lazily in attach() when the real video element is available

    this.telemetry = new TelemetryReporter(this.config);

    // Initialize optional subsystems based on feature flags
    if (this.config.features.offline) {
      this.offlineStore = new OfflineStore({
        onDownloadProgress: (_videoId, progress) => {
          this.recordTelemetry('offline-progress', progress);
        },
        onStatusChange: (videoId, status) => {
          this.recordTelemetry('offline-status', 0, { videoId, status });
        },
        onError: (videoId, error) => {
          this.handlePlaybackError({
            code: 'offline-error',
            message: `[${videoId}] ${error.message}`,
            fatal: false,
          });
        },
      });
    }
  }

  /** Attach the player to a <video> element. */
  async attach(videoElement: HTMLVideoElement, container?: HTMLElement): Promise<void> {
    this.videoElement = videoElement;
    this.container = container ?? videoElement.parentElement;

    // Create buffer engine with the real video element
    this.buffer = new HybridBufferEngine(
      videoElement,
      {
        maxBufferLength: this.config.maxBufferLength,
        maxBufferSize: 50,
        bufferAhead: 10,
        sufficientBufferThreshold: 2,
        behindBuffer: 5,
      },
      {
        onBufferAppended: (_index, bytes) => {
          this.recordTelemetry('segment-buffered', bytes);
        },
        onBufferEvicted: (range) => {
          this.recordTelemetry('buffer-evicted', range.end - range.start);
        },
        onBufferStalled: () => this.handleBufferStall(),
        onBufferFull: () => {
          this.recordTelemetry('buffer-full', 0);
        },
        onCodecChange: (codecs) => {
          this.recordTelemetry('codec-change', 0, { codecs });
        },
        onError: (error) => this.handlePlaybackError({
          code: 'buffer-error',
          message: error.message,
          fatal: false,
          retry: 'retry-segment',
        }),
      },
      this.config.features,
    );

    // Create scheduler with the real buffer instance
    this.scheduler = new SegmentScheduler(
      this.transport,
      this.buffer,
      this.abr,
      { lookAhead: 5, prefetchCount: 3 },
      {
        onSegmentFetched: (_index, bytes, ttfb) => {
          this.recordTelemetry('segment-fetch', bytes, { ttfb });
        },
        onSegmentError: (_index, error) => {
          this.recordTelemetry('segment-error', 0, { error });
        },
        onInitFetched: (renditionId) => {
          this.recordTelemetry('init-fetched', 0, { renditionId });
        },
        onRenditionSwitch: (fromId, toId) => {
          this.recordTelemetry('rendition-switch', 0, { from: fromId, to: toId });
        },
        onScheduleProgress: (fetched, total) => {
          this.recordTelemetry('schedule-progress', fetched / (total || 1));
        },
      },
    );

    // Initialize transport
    await this.transport.init();

    // Initialize offline store
    await this.offlineStore?.init();

    // Wire offline store into transport for cache-first segment serving
    if (this.offlineStore) {
      this.transport.setOfflineStore(this.offlineStore);
    }

    // Set up video element events
    this.setupVideoEvents();

    // Start telemetry
    this.telemetry.start(this.sessionId, '');
  }

  /** Load a video by UUID. Fetches manifest and prepares playback. */
  async load(videoId: string): Promise<void> {
    if (!this.videoElement) throw new Error('Player not attached. Call attach() first.');
    if (!this.buffer || !this.scheduler) throw new Error('Player not attached. Call attach() first.');

    this.stateMachine.transition('loading');
    this.telemetry.start(this.sessionId, videoId);

    try {
      // Fetch and parse manifest
      this.manifest = await this.manifestEngine.load(videoId);

      // Set up scheduler with the parsed manifest
      this.scheduler.setManifest(this.manifest);

      // Initialize AI if enabled
      if (this.config.features.ai) {
        this.aiOrchestrator = new AIOrchestrator(
          { sampleIntervalSec: 2, minConfidence: 0.6, maxHighlights: 50, useWebNN: true, modelUrl: '/models/' },
          this.config,
          {
            onClassification: (result) => {
              this.recordTelemetry('ai-classification', result.confidence, { labels: result.labels });
            },
            onHighlight: (highlight) => {
              this.recordTelemetry('ai-highlight', highlight.confidence, { label: highlight.label });
            },
            onContentHint: (hint) => {
              this.abr.setContentHint(hint);
            },
            onModeChange: (mode) => {
              this.recordTelemetry('ai-mode-change', 0, { mode });
            },
            onError: (error) => {
              this.handlePlaybackError({
                code: 'ai-error',
                message: error.message,
                fatal: false,
              });
            },
          },
        );
        this.aiOrchestrator.setVideoId(videoId);
        await this.aiOrchestrator.init();
      }

      this.stateMachine.transition('buffering');

      // Start fetching segments from the beginning
      await this.scheduler.start(0);

      // Wait for sufficient buffer
      this.waitForBuffer();
    } catch (err) {
      this.handlePlaybackError({
        code: 'load-error',
        message: err instanceof Error ? err.message : String(err),
        fatal: true,
      });
    }
  }

  /** Start playback. */
  play(): void {
    if (!this.videoElement) return;

    this.videoElement.play().catch(() => {
      // Auto-play blocked — user interaction needed
    });

    this.stateMachine.transition('playing');

    // Start ABR evaluation loop
    this.startABREvaluation();

    // Start time update reporting
    this.startTimeUpdates();

    // Start AI classification if enabled
    if (this.aiOrchestrator && this.videoElement) {
      this.aiOrchestrator.startClassification(this.videoElement);
    }
  }

  /** Pause playback. */
  pause(): void {
    this.videoElement?.pause();
    this.stateMachine.transition('paused');
    this.stopABREvaluation();
  }

  /** Seek to a time position in seconds. */
  async seekTo(time: number): Promise<void> {
    if (!this.videoElement) return;

    this.stateMachine.transition('seeking');
    this.videoElement.currentTime = time;

    await this.scheduler.seekTo(time);

    this.recordTelemetry('seek', time);
  }

  /** Set the ABR strategy. */
  setABRStrategy(strategy: ABRStrategy): void {
    this.abr.setStrategy(strategy);
  }

  /** Manually select a quality tier. */
  setQuality(tierName: string): void {
    if (!this.manifest) return;
    const rendition = this.manifest.renditions.find(r => r.name === tierName);
    if (rendition) {
      this.abr.setManualRendition(rendition.id);
    }
  }

  /** Set the projection type for immersive rendering. */
  setProjection(projection: ProjectionType): void {
    this.immersiveRenderer?.setProjection(projection);
  }

  /** Enter VR/AR mode. */
  async enterXR(mode: 'vr' | 'ar' = 'vr'): Promise<void> {
    if (!this.immersiveRenderer) {
      throw new Error('Immersive rendering not initialized');
    }
    await this.immersiveRenderer.enterXR(mode);
  }

  /** Exit VR/AR mode. */
  exitXR(): void {
    this.immersiveRenderer?.exitXR();
  }

  /** Join a co-watching party session. */
  async joinParty(sessionId: string, userId: string, displayName: string): Promise<void> {
    if (!this.config.features.party) return;

    this.partyBus = new PartySyncBus(
      {
        wsEndpoint: `${this.config.baseUrl}/api/party/ws`,
        syncToleranceMs: 500,
        broadcastIntervalMs: 1000,
        getPosition: () => this.videoElement?.currentTime ?? 0,
      },
      {
        onStateChange: (state) => {
          this.recordTelemetry('party-state', state.participants.length);
        },
        onEvent: (event) => this.handlePartyEvent(event),
        onSyncCorrection: (position, _reason) => {
          this.recordTelemetry('party-sync-correction', position, { reason: _reason });
          this.seekTo(position);
        },
        onError: (error) => {
          this.handlePlaybackError({
            code: 'party-error',
            message: error.message,
            fatal: false,
          });
        },
      },
    );

    await this.partyBus.join(sessionId, userId, displayName);
  }

  /** Download current video for offline playback. */
  async downloadForOffline(): Promise<void> {
    if (!this.offlineStore || !this.manifest) return;

    await this.offlineStore.downloadVideo(
      this.manifest.videoId,
      this.manifest,
      async (url: string, options?: { signal?: AbortSignal }) => {
        const result = await this.transport.fetchSegment(url);
        if (!result.ok) throw new Error(`Failed to fetch: ${url}`);
        return result.data;
      },
    );
  }

  /** Get the current playback state. */
  getState(): PlaybackState {
    return this.stateMachine.getState();
  }

  /** Get the current manifest. */
  getManifest(): Manifest | null {
    return this.manifest;
  }

  /** Get buffer statistics. */
  getBufferStats(): BufferStats {
    return this.buffer.getStats();
  }

  /** Get the current rendition. */
  getCurrentRendition(): Rendition | null {
    return this.scheduler.getCurrentRendition();
  }

  /** Get spatial state (for immersive modes). */
  getSpatialState(): SpatialState | null {
    return this.immersiveRenderer?.getSpatialState() ?? null;
  }

  /** Destroy the player and release all resources. */
  async destroy(): Promise<void> {
    this.stopABREvaluation();
    this.stopTimeUpdates();
    this.videoEventsAbort?.abort();
    this.videoEventsAbort = null;
    this.scheduler.stop();
    this.aiOrchestrator?.destroy();
    this.partyBus?.leave();
    await this.buffer.destroy();
    this.transport.destroy();
    this.manifestEngine.destroy();
    this.immersiveRenderer?.destroy();
    this.offlineStore?.destroy();
    this.telemetry.stop();
    this.stateMachine.reset();
    this.videoElement = null;
    this.container = null;
  }

  // -----------------------------------------------------------------------
  // Private: Video Element Events
  // -----------------------------------------------------------------------

  private setupVideoEvents(): void {
    if (!this.videoElement) return;

    // Abort previous listeners if re-attaching
    this.videoEventsAbort?.abort();
    this.videoEventsAbort = new AbortController();
    const signal = this.videoEventsAbort.signal;

    this.videoElement.addEventListener('waiting', () => {
      if (this.stateMachine.canTransitionTo('buffering')) {
        this.stateMachine.transition('buffering');
      }
    }, { signal });

    this.videoElement.addEventListener('playing', () => {
      if (this.stateMachine.canTransitionTo('playing')) {
        this.stateMachine.transition('playing');
      }
    }, { signal });

    this.videoElement.addEventListener('pause', () => {
      if (this.stateMachine.canTransitionTo('paused')) {
        this.stateMachine.transition('paused');
      }
    }, { signal });

    this.videoElement.addEventListener('ended', () => {
      if (this.stateMachine.canTransitionTo('ended')) {
        this.stateMachine.transition('ended');
      }
    }, { signal });

    this.videoElement.addEventListener('error', () => {
      const mediaError = this.videoElement?.error;
      this.handlePlaybackError({
        code: 'media-error',
        message: mediaError?.message ?? 'Unknown media error',
        fatal: true,
      });
    }, { signal });

    this.videoElement.addEventListener('timeupdate', () => {
      if (this.videoElement) {
        this.listeners.onTimeUpdate(
          this.videoElement.currentTime,
          this.videoElement.duration || 0,
        );
      }
    }, { signal });
  }

  // -----------------------------------------------------------------------
  // Private: Event Handlers
  // -----------------------------------------------------------------------

  private handleManifestUpdate(manifest: Manifest): void {
    this.manifest = manifest;
    this.listeners.onManifestLoaded(manifest);
    this.recordTelemetry('manifest-loaded', manifest.renditions.length, {
      format: manifest.sourceFormat,
      renditionCount: manifest.renditions.length,
    });
  }

  private handleRenditionChange(renditionId: string, reason: string): void {
    if (!this.manifest) return;

    const rendition = this.manifest.renditions.find(r => r.id === renditionId);
    if (rendition) {
      this.listeners.onRenditionChange(rendition);
      const tier = this.manifest.qualityLadder.find(t => t.height === rendition.height);
      if (tier) {
        this.listeners.onQualityChange(tier);
      }
    }

    this.recordTelemetry('rendition-change', 0, { renditionId, reason });
  }

  private handleBufferStall(): void {
    if (this.stateMachine.canTransitionTo('buffering')) {
      this.stateMachine.transition('buffering');
    }
    this.recordTelemetry('buffer-stall', 0);
  }

  private handlePlaybackError(error: PlaybackError): void {
    this.stateMachine.setError(error);
    this.listeners.onError(error);
    this.recordTelemetry('error', 0, { code: error.code, message: error.message });
  }

  private handlePartyEvent(event: { type: string; position?: number }): void {
    this.recordTelemetry('party-event', event.position ?? 0, { type: event.type });
  }

  // -----------------------------------------------------------------------
  // Private: ABR & Timers
  // -----------------------------------------------------------------------

  private startABREvaluation(): void {
    this.stopABREvaluation();
    this.abrEvaluationTimer = setInterval(() => {
      const stats = this.buffer.getStats();
      this.scheduler.evaluateABR(stats);
      this.listeners.onBufferUpdate(stats);
    }, 2000);
  }

  private stopABREvaluation(): void {
    if (this.abrEvaluationTimer) {
      clearInterval(this.abrEvaluationTimer);
      this.abrEvaluationTimer = null;
    }
  }

  private startTimeUpdates(): void {
    this.stopTimeUpdates();
    this.timeUpdateTimer = setInterval(() => {
      if (this.videoElement) {
        this.listeners.onTimeUpdate(
          this.videoElement.currentTime,
          this.videoElement.duration || 0,
        );
      }
    }, 250);
  }

  private stopTimeUpdates(): void {
    if (this.timeUpdateTimer) {
      clearInterval(this.timeUpdateTimer);
      this.timeUpdateTimer = null;
    }
  }

  private waitForBuffer(): void {
    const check = () => {
      if (this.buffer.hasSufficientBuffer()) {
        if (this.stateMachine.canTransitionTo('ready')) {
          this.stateMachine.transition('ready');
        }
      } else {
        requestAnimationFrame(check);
      }
    };
    requestAnimationFrame(check);
  }

  private recordTelemetry(type: string, value?: number, metadata?: Record<string, unknown>): void {
    this.telemetry.record({
      type,
      timestamp: Date.now(),
      videoId: this.manifest?.videoId ?? '',
      value,
      metadata,
    });
  }
}
