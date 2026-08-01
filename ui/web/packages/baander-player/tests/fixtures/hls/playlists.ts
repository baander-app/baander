/**
 * HLS playlist test fixtures.
 *
 * Realistic HLS v6 master and media playlists matching the Baander backend
 * StreamManifestController output format.
 */

/** Multi-rendition master playlist (360p, 720p, 1080p). */
export const MASTER_MULTI_RENDITION = [
  '#EXTM3U',
  '#EXT-X-VERSION:6',
  '#EXT-X-INDEPENDENT-SEGMENTS',
  '#EXT-X-STREAM-INF:BANDWIDTH=2800000,RESOLUTION=1280x720,CODECS="hvc1.1.6.L93.B0,mp4a.40.2"',
  '/api/stream/abc123/720p/media.m3u8',
  '#EXT-X-STREAM-INF:BANDWIDTH=5000000,RESOLUTION=1920x1080,CODECS="hvc1.1.6.L93.B0,mp4a.40.2"',
  '/api/stream/def456/1080p/media.m3u8',
].join('\n');

/** Empty master playlist (no stream infs). */
export const MASTER_EMPTY = '#EXTM3U\n#EXT-X-VERSION:6\n#EXT-X-INDEPENDENT-SEGMENTS\n';

/** Media playlist with init segment + 2 media segments. */
export const MEDIA_WITH_SEGMENTS = [
  '#EXTM3U',
  '#EXT-X-VERSION:6',
  '#EXT-X-INDEPENDENT-SEGMENTS',
  '#EXT-X-TARGETDURATION:6',
  '#EXT-X-MAP:URI="/api/stream/abc123/init.mp4"',
  '#EXTINF:6.000000,',
  'seg_0.m4s',
  '#EXTINF:5.834167,',
  'seg_1.m4s',
  '#EXT-X-ENDLIST',
].join('\n');
