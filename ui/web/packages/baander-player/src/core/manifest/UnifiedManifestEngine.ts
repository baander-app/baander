/**
 * @module core/manifest/UnifiedManifestEngine
 * @description Unified manifest parser that normalises HLS v6 master/media playlists
 * and DASH MPD manifests into a single Manifest model.
 *
 * Backend API surface consumed:
 *   GET /api/stream/{videoId}/master.m3u8   → HLS master playlist
 *   GET /api/stream/{jobPublicId}/media.m3u8 → HLS media playlist per rendition
 *   GET /api/stream/{videoId}/manifest.mpd   → DASH MPD manifest
 *   GET /api/stream/{videoId}/quality-ladder → JSON quality tier array
 *
 * The backend generates:
 *   - HLS v6 with fMP4 CMAF segments (#EXT-X-MAP, seg_N.m4s, init.mp4)
 *   - DASH onDemand profile with SegmentTimeline + $Number$ template
 *   - Quality ladder: { name, height, width, bitrate, codec }[]
 *   - Segment naming: seg_{index}.m4s where index is 0-based
 *   - Init segment: init.mp4 (shared across renditions via CMAF)
 *
 * @see App\Transcode\Infrastructure\HLS\ManifestGenerator for HLS format
 * @see App\Transcode\Infrastructure\DASH\DashManifestGenerator for DASH format
 * @see App\Transcode\Infrastructure\HLS\QualityLadderRenderer for quality ladder
 */

import type {
  Manifest,
  Rendition,
  SegmentInfo,
  QualityTierInfo,
  ContentHint,
  AudioTrack,
  SubtitleTrack,
} from '../../types';

// ---------------------------------------------------------------------------
// HLS Parsing
// ---------------------------------------------------------------------------

interface HlsStreamInf {
  bandwidth: number;
  resolution: string;
  codecs: string;
  uri: string;
  audioGroupId?: string;
  subtitleGroupId?: string;
}

interface HlsMediaGroup {
  type: 'AUDIO' | 'SUBTITLES' | 'CLOSED-CAPTIONS';
  groupId: string;
  language: string;
  name: string;
  default: boolean;
  autoselect: boolean;
  channels?: string;
  uri: string;
}

/**
 * Parse an HLS v6 master playlist into stream-inf entries and media groups.
 *
 * Expected format from backend ManifestGenerator::generateMasterManifest():
 * ```
 * #EXTM3U
 * #EXT-X-VERSION:6
 * #EXT-X-INDEPENDENT-SEGMENTS
 * #EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="aac",LANGUAGE="en",NAME="English",DEFAULT=YES,AUTOSELECT=YES,CHANNELS="2",URI="..."
 * #EXT-X-STREAM-INF:BANDWIDTH=5000000,RESOLUTION=1920x1080,CODECS="hvc1.1.6.L93.B0,mp4a.40.2",AUDIO="aac",SUBTITLES="subs"
 * /api/stream/{jobPublicId}/{tierName}/media.m3u8
 * ```
 */
function parseHlsMaster(text: string): { streams: HlsStreamInf[]; mediaGroups: HlsMediaGroup[] } {
  const streams: HlsStreamInf[] = [];
  const mediaGroups: HlsMediaGroup[] = [];
  const lines = text.split('\n');

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i]!.trim();

    // Parse #EXT-X-MEDIA: lines
    if (line.startsWith('#EXT-X-MEDIA:')) {
      const attrs = line.slice('#EXT-X-MEDIA:'.length);
      const typeMatch = attrs.match(/TYPE=([^,\s]+)/);
      const groupIdMatch = attrs.match(/GROUP-ID="([^"]+)"/);
      const languageMatch = attrs.match(/LANGUAGE="([^"]+)"/);
      const nameMatch = attrs.match(/NAME="([^"]+)"/);
      const defaultMatch = attrs.match(/DEFAULT=(YES|NO)/);
      const autoselectMatch = attrs.match(/AUTOSELECT=(YES|NO)/);
      const channelsMatch = attrs.match(/CHANNELS="([^"]+)"/);
      const uriMatch = attrs.match(/URI="([^"]+)"/);

      if (typeMatch && groupIdMatch) {
        const type = typeMatch[1] as HlsMediaGroup['type'];
        if (type === 'AUDIO' || type === 'SUBTITLES' || type === 'CLOSED-CAPTIONS') {
          mediaGroups.push({
            type,
            groupId: groupIdMatch[1]!,
            language: languageMatch?.[1] ?? '',
            name: nameMatch?.[1] ?? '',
            default: defaultMatch?.[1] === 'YES',
            autoselect: autoselectMatch?.[1] === 'YES',
            channels: channelsMatch?.[1],
            uri: uriMatch?.[1] ?? '',
          });
        }
      }
      continue;
    }

    if (!line.startsWith('#EXT-X-STREAM-INF:')) continue;

    // Extract attributes from the stream-inf line
    const bandwidthMatch = line.match(/BANDWIDTH=(\d+)/);
    const resolutionMatch = line.match(/RESOLUTION=(\d+x\d+)/);
    const codecsMatch = line.match(/CODECS="([^"]+)"/);
    const audioGroupMatch = line.match(/AUDIO="([^"]+)"/);
    const subtitleGroupMatch = line.match(/SUBTITLES="([^"]+)"/);

    // Next non-empty, non-comment line is the URI
    let uri = '';
    for (let j = i + 1; j < lines.length; j++) {
      const nextLine = lines[j]!.trim();
      if (nextLine && !nextLine.startsWith('#')) {
        uri = nextLine;
        break;
      }
    }

    if (bandwidthMatch && resolutionMatch && codecsMatch && uri) {
      streams.push({
        bandwidth: parseInt(bandwidthMatch[1]!, 10),
        resolution: resolutionMatch[1]!,
        codecs: codecsMatch[1]!,
        uri,
        audioGroupId: audioGroupMatch?.[1],
        subtitleGroupId: subtitleGroupMatch?.[1],
      });
    }
  }

  return { streams, mediaGroups };
}

interface HlsMediaPlaylist {
  targetDuration: number;
  initSegmentUrl: string;
  segments: SegmentInfo[];
  totalDuration: number;
}

/**
 * Parse an HLS v6 media playlist into segment info.
 *
 * Expected format from backend ManifestGenerator::generateMediaManifest():
 * ```
 * #EXTM3U
 * #EXT-X-VERSION:6
 * #EXT-X-INDEPENDENT-SEGMENTS
 * #EXT-X-TARGETDURATION:6
 * #EXT-X-MAP:URI="/api/stream/{jobPublicId}/init.mp4"
 * #EXTINF:6.000000,
 * seg_0.m4s
 * #EXTINF:5.834167,
 * seg_1.m4s
 * #EXT-X-ENDLIST
 * ```
 */
function parseHlsMediaPlaylist(text: string, baseUrl: string): HlsMediaPlaylist {
  const lines = text.split('\n');
  let targetDuration = 6;
  let initSegmentUrl = '';
  const segments: SegmentInfo[] = [];
  let currentIndex = 0;
  let totalDuration = 0;

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i]!.trim();

    if (line.startsWith('#EXT-X-TARGETDURATION:')) {
      targetDuration = parseInt(line.split(':')[1]!, 10);
      continue;
    }

    if (line.startsWith('#EXT-X-MAP:URI=')) {
      const uriMatch = line.match(/URI="([^"]+)"/);
      if (uriMatch) {
        initSegmentUrl = resolveUrl(uriMatch[1]!, baseUrl);
      }
      continue;
    }

    if (line.startsWith('#EXTINF:')) {
      const duration = parseFloat(line.replace('#EXTINF:', '').replace(',', ''));
      if (isNaN(duration)) continue;

      // Next non-comment line is the segment URI
      let segUri = '';
      for (let j = i + 1; j < lines.length; j++) {
        const nextLine = lines[j]!.trim();
        if (nextLine && !nextLine.startsWith('#')) {
          segUri = nextLine;
          break;
        }
      }

      if (segUri) {
        segments.push({
          index: currentIndex,
          duration,
          uri: resolveUrl(segUri, baseUrl),
        });
        totalDuration += duration;
        currentIndex++;
      }
    }
  }

  return { targetDuration, initSegmentUrl, segments, totalDuration };
}

// ---------------------------------------------------------------------------
// DASH Parsing
// ---------------------------------------------------------------------------

/**
 * Parse an ISO 8601 duration string (PT1H2M3.5S) into seconds.
 * Handles PT, H, M, S components with fractional seconds.
 */
function parseIso8601Duration(iso: string): number {
  const match = iso.match(/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?$/i);
  if (!match) return 0;
  const days = parseFloat(match[1] ?? '0');
  const hours = parseFloat(match[2] ?? '0');
  const minutes = parseFloat(match[3] ?? '0');
  const seconds = parseFloat(match[4] ?? '0');
  return days * 86400 + hours * 3600 + minutes * 60 + seconds;
}

/**
 * Parse a DASH MPD manifest.
 *
 * Expected format from backend DashManifestGenerator::generate():
 *   - Profile: urn:mpeg:dash:profile:isoff-on-demand:2011
 *   - SegmentTemplate: media="seg_$Number$.m4s", initialization="init.mp4"
 *   - SegmentTimeline with <S d="..." t="0"> entries (durations in ms)
 *   - BaseURL: /api/stream/{publicId}/
 *   - Representation: id, bandwidth, width, height, codecs
 *
 * The backend uses onDemand profile with SegmentTimeline for variable-duration segments.
 */
export interface DashParseResult {
  renditions: Rendition[];
  /** Duration from MPD mediaPresentationDuration attribute (0 if absent). */
  mpdDuration: number;
}

export function parseDashMpd(text: string, videoId: string): DashParseResult {
  const parser = new DOMParser();
  const doc = parser.parseFromString(text, 'application/xml');
  const mpd = doc.querySelector('MPD');
  if (!mpd) return { renditions: [], mpdDuration: 0 };

  const renditions: Rendition[] = [];
  const representations = mpd.querySelectorAll('Representation');

  for (const rep of representations) {
    const bandwidth = parseInt(rep.getAttribute('bandwidth') ?? '0', 10);
    const width = parseInt(rep.getAttribute('width') ?? '0', 10);
    const height = parseInt(rep.getAttribute('height') ?? '0', 10);
    const codecs = rep.getAttribute('codecs') ?? '';
    const repId = rep.getAttribute('id') ?? `rendition-${renditions.length}`;

    // Extract base URL for this representation
    const baseUrlEl = rep.querySelector('BaseURL');
    const baseUrl = baseUrlEl?.textContent?.trim() ?? `/api/stream/${repId}/`;

    // Parse SegmentTemplate
    const segTemplate = rep.querySelector('SegmentTemplate');
    const initFile = segTemplate?.getAttribute('initialization') ?? 'init.mp4';
    const mediaTemplate = segTemplate?.getAttribute('media') ?? 'seg_$Number$.m4s';

    // Parse SegmentTimeline — durations are in milliseconds
    const timeline = segTemplate?.querySelector('SegmentTimeline');
    const segments: SegmentInfo[] = [];
    let segIndex = 0;
    let totalDuration = 0;

    if (timeline) {
      const sElements = timeline.querySelectorAll('S');
      for (const s of sElements) {
        const durationMs = parseInt(s.getAttribute('d') ?? '0', 10);
        const duration = durationMs / 1000;
        const mediaUrl = mediaTemplate.replace('$Number$', String(segIndex));

        segments.push({
          index: segIndex,
          duration,
          uri: resolveUrl(mediaUrl, baseUrl),
        });

        totalDuration += duration;
        segIndex++;
      }
    }

    // Target duration: average segment duration or 6s default
    const targetDuration = segments.length > 0
      ? totalDuration / segments.length
      : 6;

    renditions.push({
      id: repId,
      name: `${height}p`,
      width,
      height,
      bitrate: bandwidth,
      maxBitrate: bandwidth,
      codecs,
      initSegmentUrl: resolveUrl(initFile, baseUrl),
      segments,
      targetDuration,
      totalDuration,
    });
  }

  // Extract MPD-level duration for live/progressive manifests
  const mpdDurationAttr = mpd.getAttribute('mediaPresentationDuration');
  const mpdDuration = mpdDurationAttr ? parseIso8601Duration(mpdDurationAttr) : 0;

  return { renditions: renditions.sort((a, b) => a.bitrate - b.bitrate), mpdDuration };
}

// ---------------------------------------------------------------------------
// URL Resolution
// ---------------------------------------------------------------------------

/** Resolve a potentially relative URL against a base URL. */
export function resolveUrl(uri: string, baseUrl: string): string {
  if (uri.startsWith('http://') || uri.startsWith('https://') || uri.startsWith('/')) {
    return uri;
  }
  // Relative URL: combine with base
  const base = baseUrl.endsWith('/') ? baseUrl : `${baseUrl}/`;
  return base + uri;
}

// ---------------------------------------------------------------------------
// Content Hint Detection
// ---------------------------------------------------------------------------

/** Infer content type from manifest metadata for ABR heuristics. */
export function inferContentHint(renditions: Rendition[]): ContentHint {
  if (renditions.length === 0) return 'unknown';

  const top = renditions[renditions.length - 1]!;

  // High framerate + high bitrate suggests gaming/sport
  if (top.bitrate >= 15_000_000) return 'gaming';
  if (top.bitrate >= 8_000_000 && top.height >= 1080) return 'sport';
  if (top.height >= 2160) return 'motion';

  return 'static';
}

// ---------------------------------------------------------------------------
// UnifiedManifestEngine
// ---------------------------------------------------------------------------

export interface ManifestEngineCallbacks {
  onManifestUpdate: (manifest: Manifest) => void;
  onError: (error: Error) => void;
}

/**
 * UnifiedManifestEngine — fetches and parses both HLS and DASH manifests
 * into a normalised internal Manifest model.
 *
 * Usage:
 * ```ts
 * const engine = new UnifiedManifestEngine(config, callbacks);
 * const manifest = await engine.load('video-uuid-here');
 * ```
 *
 * The engine will:
 * 1. Fetch the quality ladder from GET /api/stream/{videoId}/quality-ladder
 * 2. Fetch the preferred manifest (HLS or DASH) based on config
 * 3. For HLS: fetch master, then each media playlist to build full Rendition objects
 * 4. For DASH: parse the single MPD which contains all representations inline
 * 5. Merge quality ladder metadata into the manifest
 */
export class UnifiedManifestEngine {
  private readonly baseUrl: string;
  private readonly preferredFormat: 'hls' | 'dash';
  private readonly customHeaders: Record<string, string>;
  private readonly onManifestUpdate: (m: Manifest) => void;
  private readonly onError: (e: Error) => void;

  private currentManifest: Manifest | null = null;
  private refreshTimer: ReturnType<typeof setTimeout> | null = null;
  private abortController: AbortController | null = null;
  private cachedAudioTracks: AudioTrack[] = [];
  private cachedSubtitleTracks: SubtitleTrack[] = [];

  constructor(
    config: { baseUrl: string; preferredFormat: 'hls' | 'dash'; customHeaders?: Record<string, string> },
    callbacks: ManifestEngineCallbacks,
  ) {
    this.baseUrl = config.baseUrl.replace(/\/$/, '');
    this.preferredFormat = config.preferredFormat;
    this.customHeaders = config.customHeaders ?? {};
    this.onManifestUpdate = callbacks.onManifestUpdate;
    this.onError = callbacks.onError;
  }

  /** Fetch and parse the manifest for a given video. */
  async load(videoId: string): Promise<Manifest> {
    this.abortController?.abort();
    this.abortController = new AbortController();
    const signal = this.abortController.signal;

    try {
      // Step 1: Fetch quality ladder
      const qualityLadder = await this.fetchQualityLadder(videoId, signal);

      // Step 2: Fetch and parse manifest
      let manifest: Manifest;

      if (this.preferredFormat === 'hls') {
        manifest = await this.loadHlsManifest(videoId, qualityLadder, signal);
      } else {
        manifest = await this.loadDashManifest(videoId, qualityLadder, signal);
      }

      this.currentManifest = manifest;
      this.onManifestUpdate(manifest);
      return manifest;
    } catch (err) {
      const error = err instanceof Error ? err : new Error(String(err));
      this.onError(error);
      throw error;
    }
  }

  /** Get the currently loaded manifest. */
  getCurrent(): Manifest | null {
    return this.currentManifest;
  }

  /** Get the available audio tracks from the loaded manifest. */
  getAudioTracks(): AudioTrack[] {
    return this.cachedAudioTracks;
  }

  /** Get the available subtitle tracks from the loaded manifest. */
  getSubtitleTracks(): SubtitleTrack[] {
    return this.cachedSubtitleTracks;
  }

  /** Switch the active audio language. Returns the selected AudioTrack or null. */
  switchAudioLanguage(language: string): AudioTrack | null {
    const track = this.cachedAudioTracks.find(t => t.language === language);
    return track ?? null;
  }

  /** Schedule periodic manifest refresh (for live streams or progressive encoding). */
  startRefresh(videoId: string, intervalMs: number = 10_000): void {
    this.stopRefresh();

    const refresh = async () => {
      try {
        await this.load(videoId);
      } catch {
        // Error already reported via callback
      }
      this.refreshTimer = setTimeout(refresh, intervalMs);
    };

    this.refreshTimer = setTimeout(refresh, intervalMs);
  }

  /** Stop periodic refresh. */
  stopRefresh(): void {
    if (this.refreshTimer) {
      clearTimeout(this.refreshTimer);
      this.refreshTimer = null;
    }
  }

  /** Clean up resources. */
  destroy(): void {
    this.stopRefresh();
    this.abortController?.abort();
    this.currentManifest = null;
  }

  // -----------------------------------------------------------------------
  // Private helpers
  // -----------------------------------------------------------------------

  private async fetchQualityLadder(
    videoId: string,
    signal: AbortSignal,
  ): Promise<QualityTierInfo[]> {
    const url = `${this.baseUrl}/api/stream/${videoId}/quality-ladder`;
    const response = await this.fetch(url, signal);

    if (!response.ok) {
      // Quality ladder is optional — return empty array
      return [];
    }

    const json = await response.json() as { data?: QualityTierInfo[] };
    const tiers = json.data ?? [];
    // Ensure maxBitrate has a sensible default if backend omits it
    for (const tier of tiers) {
      tier.maxBitrate ??= tier.bitrate;
    }
    return tiers;
  }

  private async loadHlsManifest(
    videoId: string,
    qualityLadder: QualityTierInfo[],
    signal: AbortSignal,
  ): Promise<Manifest> {
    const masterUrl = `${this.baseUrl}/api/stream/${videoId}/master.m3u8`;
    const masterResponse = await this.fetch(masterUrl, signal);
    const masterText = await masterResponse.text();

    const { streams: streamInfs, mediaGroups } = parseHlsMaster(masterText);
    const renditions: Rendition[] = [];

    // Fetch each media playlist in parallel
    const mediaPromises = streamInfs.map(async (stream) => {
      const mediaUrl = stream.uri.startsWith('/')
        ? `${this.baseUrl}${stream.uri}`
        : stream.uri;

      const mediaResponse = await this.fetch(mediaUrl, signal);
      const mediaText = await mediaResponse.text();
      const playlist = parseHlsMediaPlaylist(mediaText, mediaUrl);

      const [width, height] = stream.resolution.split('x').map(Number);
      const tier = qualityLadder.find(t => t.height === height);

      const rendition: Rendition = {
        id: this.extractJobPublicIdFromUrl(stream.uri),
        name: tier?.name ?? `${height}p`,
        width: width ?? 0,
        height: height ?? 0,
        bitrate: stream.bandwidth,
        maxBitrate: stream.bandwidth,
        codecs: stream.codecs,
        initSegmentUrl: playlist.initSegmentUrl,
        segments: playlist.segments,
        targetDuration: playlist.targetDuration,
        totalDuration: playlist.totalDuration,
        audioGroupId: stream.audioGroupId,
        subtitleGroupId: stream.subtitleGroupId,
      };

      return rendition;
    });

    const results = await Promise.allSettled(mediaPromises);
    for (const result of results) {
      if (result.status === 'fulfilled') {
        renditions.push(result.value);
      }
    }

    renditions.sort((a, b) => a.bitrate - b.bitrate);

    // Build AudioTrack[] from audio media groups
    const audioGroups = mediaGroups.filter(g => g.type === 'AUDIO' && g.uri);
    const audioTrackPromises = audioGroups.map(async (group) => {
      const audioUrl = group.uri.startsWith('/')
        ? `${this.baseUrl}${group.uri}`
        : group.uri;

      const audioResponse = await this.fetch(audioUrl, signal);
      const audioText = await audioResponse.text();
      const playlist = parseHlsMediaPlaylist(audioText, audioUrl);

      const track: AudioTrack = {
        language: group.language,
        name: group.name,
        groupId: group.groupId,
        channels: group.channels ?? '2',
        uri: group.uri,
        isDefault: group.default,
        initSegmentUrl: playlist.initSegmentUrl,
        segments: playlist.segments,
        targetDuration: playlist.targetDuration,
      };

      return track;
    });

    const audioResults = await Promise.allSettled(audioTrackPromises);
    const audioTracks: AudioTrack[] = [];
    for (const result of audioResults) {
      if (result.status === 'fulfilled') {
        audioTracks.push(result.value);
      }
    }
    this.cachedAudioTracks = audioTracks;

    // Build SubtitleTrack[] from subtitle media groups
    const subtitleGroups = mediaGroups.filter(g => g.type === 'SUBTITLES' && g.uri);
    const subtitleTrackPromises = subtitleGroups.map(async (group) => {
      const subUrl = group.uri.startsWith('/')
        ? `${this.baseUrl}${group.uri}`
        : group.uri;

      const subResponse = await this.fetch(subUrl, signal);
      const subText = await subResponse.text();
      const playlist = parseHlsMediaPlaylist(subText, subUrl);

      const track: SubtitleTrack = {
        language: group.language,
        name: group.name,
        groupId: group.groupId,
        uri: group.uri,
        isDefault: group.default,
        segments: playlist.segments,
        targetDuration: playlist.targetDuration,
      };

      return track;
    });

    const subtitleResults = await Promise.allSettled(subtitleTrackPromises);
    const subtitleTracks: SubtitleTrack[] = [];
    for (const result of subtitleResults) {
      if (result.status === 'fulfilled') {
        subtitleTracks.push(result.value);
      }
    }
    this.cachedSubtitleTracks = subtitleTracks;

    const duration = renditions.length > 0
      ? Math.max(...renditions.map(r => r.totalDuration))
      : 0;

    return {
      videoId,
      sourceFormat: 'hls',
      renditions,
      qualityLadder,
      contentHint: inferContentHint(renditions),
      fetchedAt: Date.now(),
      duration,
      audioTracks,
      subtitleTracks,
    };
  }

  private async loadDashManifest(
    videoId: string,
    qualityLadder: QualityTierInfo[],
    signal: AbortSignal,
  ): Promise<Manifest> {
    const dashUrl = `${this.baseUrl}/api/stream/${videoId}/manifest.mpd`;
    const response = await this.fetch(dashUrl, signal);
    const mpdText = await response.text();

    const { renditions, mpdDuration } = parseDashMpd(mpdText, videoId);

    // Enrich with quality ladder names
    for (const rend of renditions) {
      const tier = qualityLadder.find(t => t.height === rend.height);
      if (tier) {
        rend.name = tier.name;
      }
    }

    // Use segment-sum duration, fall back to MPD-level duration for progressive/live
    const segmentSumDuration = renditions.length > 0
      ? Math.max(...renditions.map(r => r.totalDuration))
      : 0;
    const duration = segmentSumDuration > 0 ? segmentSumDuration : mpdDuration;

    return {
      videoId,
      sourceFormat: 'dash',
      renditions,
      qualityLadder,
      contentHint: inferContentHint(renditions),
      fetchedAt: Date.now(),
      duration,
      audioTracks: [],
      subtitleTracks: [],
    };
  }

  /** Extract the jobPublicId from a media manifest URL like /api/stream/{jobPublicId}/{tier}/media.m3u8 */
  private extractJobPublicIdFromUrl(url: string): string {
    const match = url.match(/\/api\/stream\/([^/]+)/);
    return match?.[1] ?? `rendition-${Date.now()}`;
  }

  /** Authenticated fetch helper. */
  private async fetch(url: string, signal: AbortSignal): Promise<Response> {
    const headers: Record<string, string> = {
      'Accept': 'application/vnd.apple.mpegurl, application/dash+xml, application/json',
      ...this.customHeaders,
    };

    return globalThis.fetch(url, { headers, signal });
  }
}
