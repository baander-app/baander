/**
 * @module offline/OfflineStore
 * @description Offline caching layer using IndexedDB + Cache API.
 *
 * Stores complete video manifests and their fMP4 segments for offline playback.
 * The backend's CMAF segments (init.mp4 + seg_N.m4s) are stored as ArrayBuffers
 * keyed by their segment URI.
 *
 * Storage strategy:
 *   - Manifest JSON: IndexedDB store "manifests" (keyed by videoId)
 *   - Init segments: IndexedDB store "segments" (keyed by URI)
 *   - Media segments: IndexedDB store "segments" (keyed by URI)
 *   - Cache API: Used as a secondary hot cache for recently fetched segments
 *
 * The offline store integrates with the transport layer — when a segment is
 * fetched from the network, it's also stored offline. When offline, segments
 * are served from IndexedDB/Cache API.
 */

import type { Manifest, OfflineEntry, OfflineStatus } from '../types';

// ---------------------------------------------------------------------------
// IndexedDB Schema
// ---------------------------------------------------------------------------

const DB_NAME = 'baander-player-offline';
const DB_VERSION = 2;

const STORES = {
  manifests: 'manifests',
  segments: 'segments',
  /** Maps segment URI → videoId for ownership tracking. */
  segmentIndex: 'segmentIndex',
  meta: 'meta',
} as const;

// ---------------------------------------------------------------------------
// OfflineStore
// ---------------------------------------------------------------------------

export interface OfflineStoreEvents {
  onDownloadProgress: (videoId: string, progress: number) => void;
  onStatusChange: (videoId: string, status: OfflineStatus) => void;
  onError: (videoId: string, error: Error) => void;
}

/**
 * OfflineStore — manages offline caching of video content.
 *
 * Usage:
 * ```ts
 * const store = new OfflineStore(events);
 * await store.init();
 *
 * // Store a segment after fetching
 * await store.putSegment('/api/stream/{jobId}/seg_0.m4s', arrayBuffer);
 *
 * // Retrieve a segment for offline playback
 * const data = await store.getSegment('/api/stream/{jobId}/seg_0.m4s');
 *
 * // Download an entire video for offline
 * await store.downloadVideo(videoId, manifest, fetchFn);
 *
 * // Check if a video is available offline
 * const entry = await store.getEntry(videoId);
 * ```
 */
export class OfflineStore {
  private db: IDBDatabase | null = null;
  private readonly downloads = new Map<string, AbortController>();

  constructor(
    private readonly events: OfflineStoreEvents,
  ) {}

  /** Initialize the IndexedDB database. */
  async init(): Promise<void> {
    return new Promise((resolve, reject) => {
      const request = indexedDB.open(DB_NAME, DB_VERSION);

      request.onupgradeneeded = () => {
        const db = request.result;

        if (!db.objectStoreNames.contains(STORES.manifests)) {
          db.createObjectStore(STORES.manifests, { keyPath: 'videoId' });
        }

        if (!db.objectStoreNames.contains(STORES.segments)) {
          db.createObjectStore(STORES.segments);
        }

        if (!db.objectStoreNames.contains(STORES.segmentIndex)) {
          // key = segment URI, value = videoId
          const idxStore = db.createObjectStore(STORES.segmentIndex);
          idxStore.createIndex('videoId', 'videoId', { unique: false });
        }

        if (!db.objectStoreNames.contains(STORES.meta)) {
          db.createObjectStore(STORES.meta, { keyPath: 'videoId' });
        }
      };

      request.onsuccess = () => {
        this.db = request.result;
        resolve();
      };

      request.onerror = () => {
        reject(new Error(`IndexedDB open failed: ${request.error?.message}`));
      };
    });
  }

  /** Store a manifest for offline use. */
  async putManifest(entry: OfflineEntry): Promise<void> {
    if (!this.db) throw new Error('OfflineStore not initialized');

    return new Promise((resolve, reject) => {
      const tx = this.db!.transaction(STORES.manifests, 'readwrite');
      const store = tx.objectStore(STORES.manifests);

      // Serialize the segments Map for IndexedDB storage
      const serialized = {
        videoId: entry.videoId,
        manifest: entry.manifest,
        status: entry.status,
        progress: entry.progress,
        totalBytes: entry.totalBytes,
        downloadedAt: entry.downloadedAt,
        // Segments are stored separately in the segments store
      };

      const request = store.put(serialized);
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
  }

  /** Store a single segment. */
  async putSegment(uri: string, data: ArrayBuffer): Promise<void> {
    if (!this.db) throw new Error('OfflineStore not initialized');

    return new Promise((resolve, reject) => {
      const tx = this.db!.transaction(STORES.segments, 'readwrite');
      const store = tx.objectStore(STORES.segments);
      const request = store.put(data, uri);
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
  }

  /** Get a segment by URI. Returns null if not stored. */
  async getSegment(uri: string): Promise<ArrayBuffer | null> {
    if (!this.db) return null;

    return new Promise((resolve, reject) => {
      const tx = this.db!.transaction(STORES.segments, 'readonly');
      const store = tx.objectStore(STORES.segments);
      const request = store.get(uri);

      request.onsuccess = () => {
        resolve(request.result ?? null);
      };
      request.onerror = () => reject(request.error);
    });
  }

  /** Get a stored manifest entry, including reconstructed segment data. */
  async getEntry(videoId: string): Promise<OfflineEntry | null> {
    if (!this.db) return null;

    const data = await new Promise<{ videoId: string; manifest: Manifest; status: OfflineStatus; progress: number; totalBytes: number; downloadedAt: number } | null>((resolve, reject) => {
      const tx = this.db!.transaction(STORES.manifests, 'readonly');
      const store = tx.objectStore(STORES.manifests);
      const request = store.get(videoId);

      request.onsuccess = () => resolve(request.result ?? null);
      request.onerror = () => reject(request.error);
    });

    if (!data) return null;

    // Reconstruct segments Map from the manifest's rendition segment list.
    // Look up each segment URI in the segments store.
    const segments = new Map<string, ArrayBuffer>();
    let initSegment: ArrayBuffer | null = null;

    // Use the first rendition's segment list (offline downloads use one rendition)
    const rendition = data.manifest.renditions[0];
    if (rendition) {
      // Look up init segment
      initSegment = await this.getSegment(rendition.initSegmentUrl);

      // Look up each media segment
      for (const seg of rendition.segments) {
        const segData = await this.getSegment(seg.uri);
        if (segData) {
          segments.set(seg.uri, segData);
        }
      }
    }

    return {
      videoId: data.videoId,
      manifest: data.manifest,
      status: data.status,
      progress: data.progress,
      totalBytes: data.totalBytes,
      downloadedAt: data.downloadedAt,
      segments,
      initSegment,
    };
  }

  /** Get all stored video IDs. */
  async getStoredVideoIds(): Promise<string[]> {
    if (!this.db) return [];

    return new Promise((resolve, reject) => {
      const tx = this.db!.transaction(STORES.manifests, 'readonly');
      const store = tx.objectStore(STORES.manifests);
      const request = store.getAllKeys();

      request.onsuccess = () => resolve(request.result as string[]);
      request.onerror = () => reject(request.error);
    });
  }

  /**
   * Download an entire video for offline playback.
   *
   * @param videoId - The video UUID
   * @param manifest - The parsed manifest
   * @param fetchFn - Function to fetch a segment URL → ArrayBuffer.
   *                  Receives an options object with an optional AbortSignal.
   * @param options - Download options including quality preference.
   */
  async downloadVideo(
    videoId: string,
    manifest: Manifest,
    fetchFn: (url: string, options?: { signal?: AbortSignal }) => Promise<ArrayBuffer>,
    options: { qualityPreference?: 'lowest' | 'highest' | string } = {},
  ): Promise<void> {
    if (!this.db) throw new Error('OfflineStore not initialized');

    const controller = new AbortController();
    this.downloads.set(videoId, controller);

    try {
      this.events.onStatusChange(videoId, 'downloading');

      const entry: OfflineEntry = {
        videoId,
        manifest,
        segments: new Map(),
        initSegment: null,
        status: 'downloading',
        progress: 0,
        totalBytes: 0,
        downloadedAt: Date.now(),
      };

      // Select rendition based on quality preference
      const preference = options.qualityPreference ?? 'highest';
      let rendition: typeof manifest.renditions[number] | undefined;

      if (preference === 'lowest') {
        rendition = manifest.renditions[0];
      } else if (preference === 'highest') {
        rendition = manifest.renditions[manifest.renditions.length - 1];
      } else {
        // Treat as a rendition ID or name (e.g. '720p', 'job-720p')
        rendition = manifest.renditions.find(r => r.id === preference || r.name === preference)
          ?? manifest.renditions[manifest.renditions.length - 1];
      }

      if (!rendition) {
        throw new Error('No renditions available for download');
      }

      // Fetch init segment
      const initData = await fetchFn(rendition.initSegmentUrl, { signal: controller.signal });
      entry.initSegment = initData;
      entry.totalBytes += initData.byteLength;
      await this.putSegment(rendition.initSegmentUrl, initData);
      await this.recordSegmentOwnership(rendition.initSegmentUrl, videoId);

      // Fetch all media segments
      const totalSegments = rendition.segments.length;
      for (let i = 0; i < totalSegments; i++) {
        if (controller.signal.aborted) {
          entry.status = 'paused';
          await this.putManifest(entry);
          this.events.onStatusChange(videoId, 'paused');
          return;
        }

        const seg = rendition.segments[i]!;
        const data = await fetchFn(seg.uri, { signal: controller.signal });

        entry.segments.set(seg.uri, data);
        entry.totalBytes += data.byteLength;
        entry.progress = (i + 1) / totalSegments;

        await this.putSegment(seg.uri, data);
        await this.recordSegmentOwnership(seg.uri, videoId);

        this.events.onDownloadProgress(videoId, entry.progress);
      }

      entry.status = 'complete';
      entry.progress = 1;
      await this.putManifest(entry);
      this.events.onStatusChange(videoId, 'complete');
    } catch (err) {
      this.events.onError(videoId, err instanceof Error ? err : new Error(String(err)));
      this.events.onStatusChange(videoId, 'error');
    } finally {
      this.downloads.delete(videoId);
    }
  }

  /** Cancel an ongoing download. */
  cancelDownload(videoId: string): void {
    const controller = this.downloads.get(videoId);
    if (controller) {
      controller.abort();
    }
  }

  /** Delete all stored data for a video, including all its segments. */
  async deleteVideo(videoId: string): Promise<void> {
    if (!this.db) return;

    const entry = await this.getEntry(videoId);
    if (!entry) return;

    // Collect all segment URIs from the segment index for this video
    const segmentUris = await this.getSegmentUrisForVideo(videoId);

    const tx = this.db.transaction(
      [STORES.manifests, STORES.segments, STORES.segmentIndex, STORES.meta],
      'readwrite',
    );
    const segStore = tx.objectStore(STORES.segments);
    const idxStore = tx.objectStore(STORES.segmentIndex);
    const manStore = tx.objectStore(STORES.manifests);

    // Delete manifest
    manStore.delete(videoId);

    // Delete every segment and its index entry
    for (const uri of segmentUris) {
      segStore.delete(uri);
      idxStore.delete(uri);
    }

    // Delete from meta store
    tx.objectStore(STORES.meta).delete(videoId);

    return new Promise((resolve, reject) => {
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    });
  }

  /** Look up all segment URIs owned by a video via the segment index. */
  private async getSegmentUrisForVideo(videoId: string): Promise<string[]> {
    if (!this.db) return [];

    return new Promise((resolve, reject) => {
      const tx = this.db!.transaction(STORES.segmentIndex, 'readonly');
      const store = tx.objectStore(STORES.segmentIndex);
      const index = store.index('videoId');
      // openCursor on the index yields entries whose index key matches videoId.
      // cursor.primaryKey is the object store key (= segment URI), which is
      // what we need. getAllKeys would return the INDEX keys (= videoId repeated).
      const request = index.openCursor(IDBKeyRange.only(videoId));
      const uris: string[] = [];

      request.onsuccess = () => {
        const cursor = request.result;
        if (cursor) {
          uris.push(cursor.primaryKey as string);
          cursor.continue();
        } else {
          resolve(uris);
        }
      };
      request.onerror = () => reject(request.error);
    });
  }

  /** Record that a segment URI belongs to a video. */
  private async recordSegmentOwnership(segmentUri: string, videoId: string): Promise<void> {
    if (!this.db) return;

    return new Promise((resolve, reject) => {
      const tx = this.db!.transaction(STORES.segmentIndex, 'readwrite');
      const store = tx.objectStore(STORES.segmentIndex);
      const request = store.put({ uri: segmentUri, videoId }, segmentUri);
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
  }

  /** Destroy the store. */
  destroy(): void {
    this.db?.close();
    this.db = null;
  }
}
