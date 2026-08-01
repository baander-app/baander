/**
 * @module core/telemetry/TelemetryReporter
 * @description Telemetry loop that reports player metrics back to the Baander backend.
 *
 * Collects:
 *   - Playback quality metrics (buffer health, rendition switches, stalls)
 *   - Transport metrics (TTFB, throughput, protocol used)
 *   - Error events
 *   - User interactions (seek, pause, quality change)
 *
 * Reports are batched and sent periodically to reduce request overhead.
 * The telemetry endpoint mirrors the backend's analytics pipeline.
 */

import type { TelemetryEvent, TelemetryBatch, PlayerConfig } from '../../types';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

export interface TelemetryConfig {
  /** Endpoint URL for telemetry reports. */
  endpoint: string;
  /** Batch interval in ms. */
  batchIntervalMs: number;
  /** Maximum events per batch. */
  maxBatchSize: number;
  /** Whether telemetry is enabled. */
  enabled: boolean;
}

const DEFAULT_TELEMETRY_CONFIG: TelemetryConfig = {
  endpoint: '/api/telemetry/player',
  batchIntervalMs: 10_000,
  maxBatchSize: 100,
  enabled: true,
};

/** Maximum events retained across failed flushes. Oldest are dropped. */
const MAX_QUEUE_SIZE = 1000;

// ---------------------------------------------------------------------------
// TelemetryReporter
// ---------------------------------------------------------------------------

/**
 * TelemetryReporter — batches and sends player telemetry to the backend.
 *
 * Usage:
 * ```ts
 * const reporter = new TelemetryReporter(playerConfig, config);
 * reporter.start('session-uuid', 'video-uuid');
 *
 * reporter.record({ type: 'buffer-health', timestamp: Date.now(), value: 5.2 });
 * reporter.record({ type: 'rendition-switch', timestamp: Date.now(), metadata: { from: '720p', to: '1080p' } });
 *
 * reporter.stop(); // Flush remaining events
 * ```
 */
export class TelemetryReporter {
  private sessionId = '';
  private videoId = '';
  private events: TelemetryEvent[] = [];
  private batchTimer: ReturnType<typeof setInterval> | null = null;
  /** Set to true when the backend returns 404, preventing future flushes. */
  private disabled = false;

  constructor(
    private readonly playerConfig: PlayerConfig,
    private readonly config: TelemetryConfig = DEFAULT_TELEMETRY_CONFIG,
  ) {}

  /** Start the telemetry reporter. */
  start(sessionId: string, videoId: string): void {
    if (!this.config.enabled) return;

    this.sessionId = sessionId;
    this.videoId = videoId;

    this.batchTimer = setInterval(() => {
      this.flush();
    }, this.config.batchIntervalMs);
  }

  /** Record a telemetry event. */
  record(event: Partial<TelemetryEvent> & { type: string; timestamp: number }): void {
    if (!this.config.enabled) return;

    this.events.push({
      ...event,
      videoId: this.videoId,
    });

    // Flush immediately if batch is full
    if (this.events.length >= this.config.maxBatchSize) {
      this.flush();
    }
  }

  /** Stop the reporter and flush remaining events. */
  stop(): void {
    if (this.batchTimer) {
      clearInterval(this.batchTimer);
      this.batchTimer = null;
    }
    this.flush();
  }

  /** Flush accumulated events to the backend. */
  async flush(): Promise<void> {
    if (this.events.length === 0 || this.disabled) return;

    const batch: TelemetryBatch = {
      sessionId: this.sessionId,
      videoId: this.videoId,
      events: this.events.splice(0), // Move events out
    };

    try {
      const url = `${this.playerConfig.baseUrl}${this.config.endpoint}`;
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          ...this.playerConfig.customHeaders,
        },
        body: JSON.stringify(batch),
        keepalive: true, // Allow sending during page unload
      });

      // If the endpoint doesn't exist, disable future flushes to stop
      // hammering a non-existent route. Will re-enable when the backend
      // adds the endpoint.
      if (response.status === 404) {
        this.disabled = true;
        // Drop the batch — no point re-queueing for a missing endpoint
        return;
      }
    } catch {
      // Telemetry failures are non-critical — re-queue events with overflow protection
      this.events.unshift(...batch.events);
      if (this.events.length > MAX_QUEUE_SIZE) {
        this.events = this.events.slice(this.events.length - MAX_QUEUE_SIZE);
      }
    }
  }
}
