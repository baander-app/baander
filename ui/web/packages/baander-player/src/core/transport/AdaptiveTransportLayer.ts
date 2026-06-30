/**
 * @module core/transport/AdaptiveTransportLayer
 * @description Multi-protocol transport layer that selects the best available
 * transport for segment fetching: MoQ → WebTransport → HTTP/3 → Fetch.
 *
 * Handles the backend's 202 Accepted + Retry-After pattern for segments
 * that are still being encoded.
 *
 * Backend segment endpoints:
 *   GET /api/stream/{jobPublicId}/init.mp4         → fMP4 init segment (200 or 404)
 *   GET /api/stream/{jobPublicId}/seg_{index}.m4s   → fMP4 media segment (200, 202, or 404)
 *
 * 202 Response handling:
 *   When a segment isn't yet encoded, the backend returns HTTP 202 with a
 *   Retry-After header. The transport layer implements exponential backoff
 *   with jitter to avoid thundering herd on progressive encodes.
 *
 * Authentication:
 *   All /api/stream/* requests go through the DPoP auth service worker
 *   (see ui/web/src/features/player/services/auth-stream-worker.ts).
 *   The service worker intercepts fetches and adds Bearer + DPoP headers.
 *
 * @see App\Transcode\Interface\Controller\StreamSegmentController
 */

import type { FetchOutcome, TransportProtocol } from '../../types';
import type { OfflineStore } from '../../offline/OfflineStore';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

export interface TransportConfig {
  baseUrl: string;
  /** Maximum concurrent segment fetches. */
  maxConcurrency: number;
  /** Maximum retries for 202 responses. */
  maxRetries: number;
  /** Base delay for 202 retry in ms. */
  retryBaseDelay: number;
  /** Maximum delay for 202 retry in ms. */
  retryMaxDelay: number;
  /** Custom headers (auth, etc). */
  customHeaders: Record<string, string>;
  /** Request timeout in ms. */
  requestTimeout: number;
}

const DEFAULT_TRANSPORT_CONFIG: TransportConfig = {
  baseUrl: '',
  maxConcurrency: 6,
  maxRetries: 10,
  retryBaseDelay: 1000,
  retryMaxDelay: 30_000,
  customHeaders: {},
  requestTimeout: 30_000,
};

// ---------------------------------------------------------------------------
// Priority Queue for segment requests
// ---------------------------------------------------------------------------

interface PendingRequest {
  url: string;
  resolve: (outcome: FetchOutcome) => void;
  priority: number;
  retryCount: number;
}

/** Min-heap priority queue for segment requests. */
export class SegmentPriorityQueue {
  private heap: PendingRequest[] = [];

  enqueue(req: PendingRequest): void {
    this.heap.push(req);
    this.bubbleUp(this.heap.length - 1);
  }

  dequeue(): PendingRequest | undefined {
    if (this.heap.length === 0) return undefined;
    const top = this.heap[0]!;
    const last = this.heap.pop()!;
    if (this.heap.length > 0) {
      this.heap[0] = last;
      this.sinkDown(0);
    }
    return top;
  }

  get size(): number {
    return this.heap.length;
  }

  private bubbleUp(idx: number): void {
    while (idx > 0) {
      const parent = Math.floor((idx - 1) / 2);
      if (this.heap[idx]!.priority >= this.heap[parent]!.priority) break;
      [this.heap[idx], this.heap[parent]] = [this.heap[parent]!, this.heap[idx]!];
      idx = parent;
    }
  }

  private sinkDown(idx: number): void {
    const n = this.heap.length;
    while (true) {
      let smallest = idx;
      const left = 2 * idx + 1;
      const right = 2 * idx + 2;
      if (left < n && this.heap[left]!.priority < this.heap[smallest]!.priority) smallest = left;
      if (right < n && this.heap[right]!.priority < this.heap[smallest]!.priority) smallest = right;
      if (smallest === idx) break;
      [this.heap[idx], this.heap[smallest]] = [this.heap[smallest]!, this.heap[idx]!];
      idx = smallest;
    }
  }
}

// ---------------------------------------------------------------------------
// Protocol Detectors
// ---------------------------------------------------------------------------

/** Detect available transport protocols in priority order. */
async function detectAvailableProtocols(): Promise<TransportProtocol[]> {
  const available: TransportProtocol[] = [];

  // MoQ: Media over QUIC — not yet available in any browser.
  // Only added when a MoQ client implementation exists.
  // if (hasMoQSupport()) available.push('moq');

  // WebTransport
  if (typeof globalThis.WebTransport !== 'undefined') {
    available.push('webtransport');
  }

  // HTTP/3: If the server supports H3, fetch() will use it automatically.
  // We track it as a separate protocol for stats purposes.
  available.push('http3');

  // Standard fetch — always available
  available.push('fetch');

  return available;
}

// ---------------------------------------------------------------------------
// AdaptiveTransportLayer
// ---------------------------------------------------------------------------

export interface TransportStats {
  /** Bytes downloaded total. */
  totalBytesDownloaded: number;
  /** Number of segment requests completed. */
  segmentsFetched: number;
  /** Average TTFB in ms. */
  avgTtfb: number;
  /** Number of 202 retries. */
  retryCount: number;
  /** Current active protocol. */
  activeProtocol: TransportProtocol;
}

/**
 * AdaptiveTransportLayer — manages segment fetching with protocol negotiation,
 * priority queuing, concurrency control, and 202 retry logic.
 *
 * Usage:
 * ```ts
 * const transport = new AdaptiveTransportLayer(config);
 * await transport.init();
 *
 * // Fetch with priority (lower = higher priority)
 * const result = await transport.fetchSegment(
 *   '/api/stream/{jobPublicId}/seg_0.m4s',
 *   { priority: 0 }
 * );
 * ```
 */
export class AdaptiveTransportLayer {
  private readonly config: TransportConfig;
  private readonly queue: SegmentPriorityQueue;
  private activeRequests = 0;
  private stats: TransportStats;
  private protocols: TransportProtocol[] = ['fetch'];
  private webTransport: WebTransport | null = null;
  private abortController: AbortController;
  /** Offline store for serving segments when network is unavailable. */
  private offlineStore: OfflineStore | null = null;

  constructor(config: Partial<TransportConfig> & { baseUrl: string }) {
    this.config = { ...DEFAULT_TRANSPORT_CONFIG, ...config };
    this.queue = new SegmentPriorityQueue();
    this.abortController = new AbortController();
    this.stats = {
      totalBytesDownloaded: 0,
      segmentsFetched: 0,
      avgTtfb: 0,
      retryCount: 0,
      activeProtocol: 'fetch',
    };
  }

  /** Initialize transport: detect protocols, optionally open WebTransport session. */
  async init(): Promise<void> {
    this.protocols = await detectAvailableProtocols();

    // Try to establish WebTransport session if available
    if (this.protocols.includes('webtransport')) {
      try {
        const wtUrl = this.config.baseUrl.replace(/^https?/, 'https') + '/api/stream/wt';
        this.webTransport = new WebTransport(wtUrl);
        await this.webTransport.ready;
        this.stats.activeProtocol = 'webtransport';
      } catch {
        // WebTransport not supported by server — fall back to fetch
        this.webTransport = null;
      }
    }

    // Set activeProtocol to the first actually usable transport.
    // WebTransport is preferred if connected; otherwise fall back to fetch.
    if (this.webTransport) {
      this.stats.activeProtocol = 'webtransport';
    } else {
      this.stats.activeProtocol = 'fetch';
    }
  }

  /**
   * Fetch an init segment with retry on 404 (transcode may still be running).
   *
   * Retries up to 3 times with 2s delay when the backend returns 404,
   * matching the 202 retry pattern for media segments.
   */
  async fetchInitSegment(url: string): Promise<FetchOutcome> {
    const maxAttempts = 3;
    const retryDelayMs = 2000;

    for (let attempt = 0; attempt < maxAttempts; attempt++) {
      const result = await this.performFetch(url, 0, 0);

      if (result.ok) return result;

      // Retry on 404 (init segment not yet created — transcode in progress)
      if (result.reason.startsWith('HTTP 404') && attempt < maxAttempts - 1) {
        await this.sleep(retryDelayMs);
        continue;
      }

      return result;
    }

    // Unreachable, but satisfies type checker
    return { ok: false, status: 0, reason: 'Max retries exceeded' };
  }

  /**
   * Fetch a media segment with priority and 202 retry logic.
   *
   * @param url - Full or relative URL to seg_{index}.m4s
   * @param options - Priority (lower = fetch sooner) and retry override
   */
  async fetchSegment(
    url: string,
    options: { priority?: number; maxRetries?: number } = {},
  ): Promise<FetchOutcome> {
    const priority = options.priority ?? 5;
    const maxRetries = options.maxRetries ?? this.config.maxRetries;

    return new Promise<FetchOutcome>((resolve) => {
      this.queue.enqueue({ url, resolve, priority, retryCount: maxRetries });
      this.drainQueue();
    });
  }

  /** Get current transport statistics. */
  getStats(): Readonly<TransportStats> {
    return this.stats;
  }

  /** Get the active transport protocol. */
  getActiveProtocol(): TransportProtocol {
    return this.stats.activeProtocol;
  }

  /** Set the offline store for cache-first segment serving. */
  setOfflineStore(store: OfflineStore | null): void {
    this.offlineStore = store;
  }

  /** Clean up all resources. */
  destroy(): void {
    this.abortController.abort();
    this.webTransport?.close();
    this.webTransport = null;
  }

  // -----------------------------------------------------------------------
  // Private: Queue Drain
  // -----------------------------------------------------------------------

  private drainQueue(): void {
    while (this.activeRequests < this.config.maxConcurrency && this.queue.size > 0) {
      const req = this.queue.dequeue();
      if (!req) break;

      this.activeRequests++;
      this.performFetch(req.url, req.priority, req.retryCount)
        .then(req.resolve)
        .finally(() => {
          this.activeRequests--;
          this.drainQueue();
        });
    }
  }

  // -----------------------------------------------------------------------
  // Private: Fetch with 202 Retry
  // -----------------------------------------------------------------------

  private async performFetch(
    url: string,
    priority: number,
    remainingRetries: number,
  ): Promise<FetchOutcome> {
    const fullUrl = this.resolveUrl(url);

    // Check offline store first (cache-first strategy)
    if (this.offlineStore) {
      try {
        const cached = await this.offlineStore.getSegment(url);
        if (cached) {
          return {
            ok: true,
            data: cached,
            ttfb: 0,
            downloadMs: 0,
            byteLength: cached.byteLength,
            fromCache: true,
          };
        }
      } catch {
        // Offline store read failed — fall through to network
      }

      // If we're definitely offline and the segment isn't cached, fail fast
      if (typeof navigator !== 'undefined' && !navigator.onLine) {
        return {
          ok: false,
          status: 0,
          reason: 'Network offline and segment not in offline store',
        };
      }
    }

    const startTime = performance.now();

    try {
      const headers: Record<string, string> = {
        ...this.config.customHeaders,
      };

      // Try WebTransport first if available
      if (this.webTransport && this.stats.activeProtocol === 'webtransport') {
        const wtResult = await this.fetchViaWebTransport(fullUrl, headers);
        if (wtResult) return wtResult;
      }

      // Standard fetch with timeout
      const controller = new AbortController();
      const timeout = setTimeout(
        () => controller.abort(),
        this.config.requestTimeout,
      );

      // Link to parent abort controller
      const onParentAbort = () => controller.abort();
      this.abortController.signal.addEventListener('abort', onParentAbort);

      const response = await globalThis.fetch(fullUrl, {
        headers,
        signal: controller.signal,
      });

      clearTimeout(timeout);
      this.abortController.signal.removeEventListener('abort', onParentAbort);

      const ttfb = performance.now() - startTime;

      // Handle 202 Accepted — segment not yet encoded
      if (response.status === 202) {
        const retryAfterHeader = response.headers.get('Retry-After');
        const retryAfter = retryAfterHeader
          ? parseInt(retryAfterHeader, 10) * 1000
          : this.config.retryBaseDelay;

        this.stats.retryCount++;

        if (remainingRetries > 0) {
          // Exponential backoff with jitter
          const delay = Math.min(
            retryAfter * Math.pow(1.5, this.config.maxRetries - remainingRetries),
            this.config.retryMaxDelay,
          );
          const jitteredDelay = delay * (0.8 + Math.random() * 0.4);

          await this.sleep(jitteredDelay);

          // Re-enqueue with same priority
          return this.performFetch(url, priority, remainingRetries - 1);
        }

        return {
          ok: false,
          status: 202,
          retryAfter: retryAfter / 1000,
          reason: 'Segment not yet encoded after max retries',
        };
      }

      if (!response.ok) {
        return {
          ok: false,
          status: response.status,
          reason: `HTTP ${response.status}: ${response.statusText}`,
        };
      }

      const data = await response.arrayBuffer();
      const byteLength = data.byteLength;
      const downloadMs = performance.now() - startTime;

      // Update stats
      this.stats.totalBytesDownloaded += byteLength;
      this.stats.segmentsFetched++;
      this.stats.avgTtfb =
        (this.stats.avgTtfb * (this.stats.segmentsFetched - 1) + ttfb) /
        this.stats.segmentsFetched;

      return {
        ok: true,
        data,
        ttfb,
        downloadMs,
        byteLength,
        fromCache: false,
      };
    } catch (err) {
      if (err instanceof DOMException && err.name === 'AbortError') {
        return {
          ok: false,
          status: 0,
          reason: 'Request aborted',
        };
      }
      return {
        ok: false,
        status: 0,
        reason: err instanceof Error ? err.message : String(err),
      };
    }
  }

  /** Attempt to fetch via WebTransport bidirectional stream.
   *
   * Uses a simple HTTP-like framing: sends the URL path as bytes on a
   * bidirectional stream, reads the binary response back.
   *
   * NOTE: This requires a compatible WebTransport endpoint on the server
   * that understands raw path-based requests. If the server only serves
   * standard HTTP, WebTransport fetch is skipped.
   */
  private async fetchViaWebTransport(
    url: string,
    _headers: Record<string, string>,
  ): Promise<FetchOutcome | null> {
    if (!this.webTransport) return null;

    try {
      // Extract the path component for the request
      const parsed = new URL(url);
      const path = parsed.pathname;

      const stream = await this.webTransport.createBidirectionalStream();
      const writer = stream.writable.getWriter();
      const encoder = new TextEncoder();

      // Send HTTP-like request line + empty line separator
      await writer.write(encoder.encode(`GET ${path} HTTP/1.1\r\n\r\n`));
      writer.close();

      // Read response
      const reader = stream.readable.getReader();
      const chunks: Uint8Array[] = [];
      let totalLength = 0;

      while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        chunks.push(value);
        totalLength += value.length;
      }

      // Combine chunks into a single ArrayBuffer
      const combined = new Uint8Array(totalLength);
      let offset = 0;
      for (const chunk of chunks) {
        combined.set(chunk, offset);
        offset += chunk.length;
      }

      // Skip HTTP response headers (find \r\n\r\n delimiter)
      let bodyOffset = 0;
      for (let i = 0; i < combined.length - 3; i++) {
        if (combined[i] === 0x0d && combined[i + 1] === 0x0a &&
            combined[i + 2] === 0x0d && combined[i + 3] === 0x0a) {
          bodyOffset = i + 4;
          break;
        }
      }

      const bodyLength = totalLength - bodyOffset;
      if (bodyLength <= 0) {
        return null;
      }

      return {
        ok: true,
        data: combined.buffer.slice(bodyOffset),
        ttfb: 0,
        downloadMs: 0,
        byteLength: bodyLength,
        fromCache: false,
      };
    } catch {
      return null;
    }
  }

  private resolveUrl(url: string): string {
    if (url.startsWith('http://') || url.startsWith('https://')) return url;
    if (url.startsWith('/')) return `${this.config.baseUrl}${url}`;
    return `${this.config.baseUrl}/${url}`;
  }

  private sleep(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }
}
