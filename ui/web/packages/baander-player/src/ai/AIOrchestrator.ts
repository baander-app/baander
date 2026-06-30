/**
 * @module ai/AIOrchestrator
 * @description On-device AI layer for scene classification, highlight detection,
 * and predictive prefetch. Uses TensorFlow.js / WebNN when available.
 *
 * Features:
 * 1. Scene Classification — classifies video frames into content categories
 *    (sport, gaming, animation, static, motion) to feed ABR content hints
 * 2. Highlight Detection — identifies exciting moments for chapter markers
 * 3. Predictive Prefetch — uses highlight predictions to pre-buffer segments
 * 4. Generative Remix — triggers a new TranscodeSession via the backend API
 *    POST /api/transcode/sessions/ with a specific time range
 *
 * AI inference runs in a dedicated Web Worker to avoid blocking the main thread.
 * Frame sampling happens every N seconds (configurable) by capturing the video
 * element to an OffscreenCanvas / ImageBitmap.
 *
 * WebNN acceleration is used when available (Chrome 113+), falling back to
 * WebGL compute, then CPU.
 */

import type {
  AIMode,
  ContentHint,
  SceneClassification,
  Highlight,
  AIRemixRequest,
  PlayerConfig,
} from '../types';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

export interface AIConfig {
  /** How often to sample a frame for classification (seconds). */
  sampleIntervalSec: number;
  /** Minimum confidence threshold for classifications. */
  minConfidence: number;
  /** Maximum number of highlights to track. */
  maxHighlights: number;
  /** Whether to use WebNN acceleration. */
  useWebNN: boolean;
  /** Model URL for the scene classifier (TFLite or ONNX). */
  modelUrl: string;
}

const DEFAULT_AI_CONFIG: AIConfig = {
  sampleIntervalSec: 2,
  minConfidence: 0.6,
  maxHighlights: 50,
  useWebNN: true,
  modelUrl: '/models/scene_classifier/',
};

// ---------------------------------------------------------------------------
// AIOrchestrator
// ---------------------------------------------------------------------------

export interface AIEvents {
  onClassification: (result: SceneClassification) => void;
  onHighlight: (highlight: Highlight) => void;
  onContentHint: (hint: ContentHint) => void;
  onModeChange: (mode: AIMode) => void;
  onError: (error: Error) => void;
}

/**
 * AIOrchestrator — coordinates on-device AI inference for the player.
 *
 * Usage:
 * ```ts
 * const ai = new AIOrchestrator(config, playerConfig, events);
 * await ai.init();
 * ai.startClassification(videoElement);
 *
 * // When a highlight is detected, it fires the onHighlight callback
 * // which can be used to show chapter markers or trigger prefetch
 *
 * // Remix a moment
 * await ai.remixMoment({ startTime: 10, endTime: 20, qualityTier: '1080p' });
 * ```
 */
export class AIOrchestrator {
  private mode: AIMode = 'idle';
  private worker: Worker | null = null;
  private sampleTimer: ReturnType<typeof setInterval> | null = null;
  private highlights: Highlight[] = [];
  private lastClassification: SceneClassification | null = null;
  private initialized = false;
  private contentHintBuffer: ContentHint[] = [];
  private videoId: string = '';

  constructor(
    private readonly config: AIConfig,
    private readonly playerConfig: PlayerConfig,
    private readonly events: AIEvents,
  ) {}

  /** Initialize the AI worker and model. */
  async init(): Promise<void> {
    try {
      // Create a dedicated worker for AI inference
      this.worker = new Worker(
        new URL('../workers/ai-worker.ts', import.meta.url),
        { type: 'module' },
      );

      this.worker.onmessage = (e: MessageEvent) => {
        this.handleWorkerMessage(e.data);
      };

      this.worker.onerror = (e) => {
        this.events.onError(new Error(`AI worker error: ${e.message}`));
      };

      // Initialize the worker with model config
      this.postMessage({ type: 'init', config: this.config });

      this.initialized = true;
    } catch (err) {
      this.events.onError(err instanceof Error ? err : new Error(String(err)));
    }
  }

  /** Start periodic frame classification. */
  startClassification(videoElement: HTMLVideoElement): void {
    if (!this.initialized) return;

    this.setMode('classifying');

    this.sampleTimer = setInterval(() => {
      this.captureAndClassify(videoElement);
    }, this.config.sampleIntervalSec * 1000);
  }

  /** Stop classification. */
  stopClassification(): void {
    if (this.sampleTimer) {
      clearInterval(this.sampleTimer);
      this.sampleTimer = null;
    }
    this.setMode('idle');
  }

  /** Set the video ID (called when the manifest is loaded). */
  setVideoId(videoId: string): void {
    this.videoId = videoId;
  }

  /** Get detected highlights. */
  getHighlights(): ReadonlyArray<Highlight> {
    return this.highlights;
  }

  /** Get the latest scene classification. */
  getLastClassification(): SceneClassification | null {
    return this.lastClassification;
  }

  /** Get the current content hint (consensus from recent classifications). */
  getContentHint(): ContentHint {
    if (this.contentHintBuffer.length === 0) return 'unknown';
    // Return the most common hint from recent samples
    const counts = new Map<ContentHint, number>();
    for (const hint of this.contentHintBuffer) {
      counts.set(hint, (counts.get(hint) ?? 0) + 1);
    }
    let best: ContentHint = 'unknown';
    let bestCount = 0;
    for (const [hint, count] of counts) {
      if (count > bestCount) {
        best = hint;
        bestCount = count;
      }
    }
    return best;
  }

  /**
   * Remix a moment — triggers a new transcode session via the backend.
   *
   * POST /api/transcode/sessions/
   * Body: { videoId, qualityTier, audioProfile, priority }
   */
  async remixMoment(request: AIRemixRequest): Promise<string> {
    const url = `${this.playerConfig.baseUrl}/api/transcode/sessions/`;
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        ...this.playerConfig.customHeaders,
      },
      body: JSON.stringify({
        videoId: this.videoId,
        qualityTier: request.qualityTier,
        audioProfile: 'streaming_stereo',
        priority: 'normal',
      }),
    });

    if (!response.ok) {
      throw new Error(`Remix failed: ${response.status} ${response.statusText}`);
    }

    const json = await response.json() as { data: { uuid: string } };
    return json.data.uuid;
  }

  /** Destroy the orchestrator and worker. */
  destroy(): void {
    this.stopClassification();
    this.postMessage({ type: 'terminate' });
    this.worker?.terminate();
    this.worker = null;
    this.initialized = false;
    this.highlights = [];
    this.contentHintBuffer = [];
  }

  // -----------------------------------------------------------------------
  // Private
  // -----------------------------------------------------------------------

  private async captureAndClassify(videoElement: HTMLVideoElement): Promise<void> {
    if (!this.worker || videoElement.readyState < 2) return;

    try {
      // Capture current frame as ImageBitmap
      const canvas = new OffscreenCanvas(
        Math.min(videoElement.videoWidth, 224), // Resize to model input size
        Math.min(videoElement.videoHeight, 224),
      );
      const ctx = canvas.getContext('2d');
      if (!ctx) return;

      ctx.drawImage(videoElement, 0, 0, canvas.width, canvas.height);
      const bitmap = await createImageBitmap(canvas);

      // Send to worker for classification
      this.postMessage({
        type: 'classify-frame',
        frameData: bitmap,
        timestamp: videoElement.currentTime,
      });
    } catch {
      // Frame capture might fail during seeks / state changes
    }
  }

  private handleWorkerMessage(data: { type: string; payload?: unknown }): void {
    switch (data.type) {
      case 'classification': {
        const result = data.payload as SceneClassification;
        this.lastClassification = result;

        // Update content hint buffer (keep last 10 samples)
        this.contentHintBuffer.push(result.contentHint);
        if (this.contentHintBuffer.length > 10) {
          this.contentHintBuffer.shift();
        }

        this.events.onClassification(result);
        this.events.onContentHint(this.getContentHint());
        break;
      }

      case 'highlight': {
        const highlight = data.payload as Highlight;
        if (this.highlights.length < this.config.maxHighlights) {
          this.highlights.push(highlight);
          this.events.onHighlight(highlight);
        }
        break;
      }

      case 'error': {
        this.events.onError(new Error(data.payload as string));
        break;
      }
    }
  }

  private setMode(mode: AIMode): void {
    if (this.mode === mode) return;
    this.mode = mode;
    this.events.onModeChange(mode);
  }

  private postMessage(msg: unknown): void {
    this.worker?.postMessage(msg);
  }
}
