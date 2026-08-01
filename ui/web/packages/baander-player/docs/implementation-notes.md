# Baander Player — Implementation Notes

## Backend Documentation Gaps

This document fills documentation gaps in the current Symfony backend and provides
recommendations for FFmpeg flags, manifest conventions, and segment naming rules.

---

## 1. FFmpeg Transcode Recommendations

### fMP4/CMAF Segment Generation

The backend should use these FFmpeg flags for maximum player compatibility:

```bash
ffmpeg -i input.mkv \
  -c:v libx265 \
  -tag:v hvc1 \                          # Force hvc1 tag (not hev1)
  -profile:v main10 \
  -level 4.1 \
  -pix_fmt yuv420p10le \
  -b:v 5000k \
  -maxrate:v 7500k \
  -bufsize:v 10000k \
  -preset medium \
  -keyint_min 60 \                       # Keyframe every 2s at 30fps
  -g 60 \
  -sc_threshold 0 \                      # Disable scene-cut detection for consistent segments
  -c:a aac \
  -b:a 128k \
  -ac 2 \
  -ar 48000 \
  -f mp4 \
  -movflags +frag_keyframe+empty_moov+default_base_moof \  # CMAF-compliant fMP4
  -seg_duration 6 \                      # 6-second segments
  -segment_format_options movflags=+frag_keyframe+empty_moov+default_base_moof \
  output_%03d.mp4
```

### Key Flags Explained

| Flag | Why |
|------|-----|
| `-tag:v hvc1` | Forces hvc1 (not hev1) — required for MSE compatibility. hvc1 stores parameter sets in the moov box, hev1 stores them in-band. |
| `-movflags +frag_keyframe+empty_moov+default_base_moof` | Creates CMAF-compliant fMP4 with independent segments. `empty_moov` puts the moov box first. `default_base_moof` ensures consistent timing across renditions. |
| `-sc_threshold 0` | Disables scene-cut detection so keyframes land exactly where we expect them. This makes segment boundaries deterministic. |
| `-keyint_min 60 -g 60` | Forces a keyframe every 60 frames (2s at 30fps). Combined with 6s segments, this gives 3 keyframes per segment for good seek resolution. |
| `-seg_duration 6` | 6-second target segments. This matches `EXT-X-TARGETDURATION:6` in the HLS manifest and the DASH SegmentTimeline durations. |

### Multi-Quality Transcode Command

For the full quality ladder in a single pass:

```bash
#!/bin/bash
INPUT="input.mkv"
OUTPUT_DIR="/output/${VIDEO_ID}"

# Generate each tier
for TIER in "360p:640:360:800k:1200k" "480p:854:480:1400k:2100k" \
            "720p:1280:720:2800k:4200k" "1080p:1920:1080:5000k:7500k" \
            "1440p:2560:1440:10000k:15000k" "4K:3840:2160:20000k:30000k"; do
  IFS=':' read -r NAME W H BR MAX <<< "$TIER"

  ffmpeg -i "$INPUT" \
    -c:v libx265 -tag:v hvc1 \
    -vf "scale=${W}:${H}:force_original_aspect_ratio=decrease,pad=${W}:${H}:(ow-iw)/2:(oh-ih)/2" \
    -b:v "$BR" -maxrate:v "$MAX" -bufsize:v "$((2 * ${MAX%k}))k" \
    -profile:v main10 -level 4.1 -pix_fmt yuv420p10le \
    -preset medium \
    -keyint_min 60 -g 60 -sc_threshold 0 \
    -c:a aac -b:a 128k -ac 2 -ar 48000 \
    -f mp4 \
    -movflags +frag_keyframe+empty_moov+default_base_moof \
    -segments_flags +batch \
    "${OUTPUT_DIR}/${NAME}/init.mp4" \
    "${OUTPUT_DIR}/${NAME}/seg_%d.m4s"
done
```

---

## 2. Manifest Conventions

### HLS Master Playlist (master.m3u8)

The backend's `ManifestGenerator::generateMasterManifest()` produces:

```
#EXTM3U
#EXT-X-VERSION:6
#EXT-X-INDEPENDENT-SEGMENTS
#EXT-X-STREAM-INF:BANDWIDTH=2800000,RESOLUTION=1280x720,CODECS="hvc1.1.6.L93.B0,mp4a.40.2"
/api/stream/{jobPublicId}/720p/media.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=5000000,RESOLUTION=1920x1080,CODECS="hvc1.1.6.L93.B0,mp4a.40.2"
/api/stream/{jobPublicId}/1080p/media.m3u8
```

**Note:** The URL pattern is `/api/stream/{jobPublicId}/{tierName}/media.m3u8`.
The `{jobPublicId}` is the TranscodeJob's public ID (not the video UUID).
Each quality tier has its own job with its own public ID.

### HLS Media Playlist (media.m3u8)

The backend's `ManifestGenerator::generateMediaManifest()` produces:

```
#EXTM3U
#EXT-X-VERSION:6
#EXT-X-INDEPENDENT-SEGMENTS
#EXT-X-TARGETDURATION:6
#EXT-X-MAP:URI="/api/stream/{jobPublicId}/init.mp4"
#EXTINF:6.000000,
seg_0.m4s
#EXTINF:5.834167,
seg_1.m4s
#EXTINF:6.000000,
seg_2.m4s
...
#EXT-X-ENDLIST
```

**Key conventions:**
- `#EXT-X-VERSION:6` — Required for fMP4 byte-range support
- `#EXT-X-INDEPENDENT-SEGMENTS` — All segments are independently decodable (CMAF)
- `#EXT-X-MAP` — Points to the init segment (moov box)
- Segment URIs are relative (e.g., `seg_0.m4s`) — the player resolves them against the media manifest URL
- `#EXT-X-ENDLIST` — Always present (VOD, not live)

### DASH MPD (manifest.mpd)

The backend's `DashManifestGenerator::generate()` produces:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<MPD xmlns="urn:mpeg:dash:schema:mpd:2011"
     profiles="urn:mpeg:dash:profile:isoff-on-demand:2011"
     type="static"
     mediaPresentationDuration="PT120S"
     minBufferTime="PT6S">
  <Period id="0">
    <AdaptationSet mimeType="video/mp4" contentType="video" segmentAlignment="true" subsegmentAlignment="true" startWithSAP="1">
      <Representation id="job-public-id-720p" bandwidth="2800000" width="1280" height="720" codecs="hvc1.1.6.L93.B0,mp4a.40.2">
        <BaseURL>/api/stream/{jobPublicId}/</BaseURL>
        <SegmentTemplate media="seg_$Number$.m4s" initialization="init.mp4" startNumber="0">
          <SegmentTimeline>
            <S d="6000" t="0"/>
            <S d="5834"/>
            <S d="6000"/>
            ...
          </SegmentTimeline>
        </SegmentTemplate>
      </Representation>
    </AdaptationSet>
  </Period>
</MPD>
```

**Key conventions:**
- `onDemand` profile — no $Time$ template, uses SegmentTimeline
- Duration values in `<S d="...">` are in **milliseconds**
- `startNumber="0"` — segments are 0-indexed
- `BaseURL` ends with `/` — segment URIs are relative (`seg_0.m4s`)
- All representations in a single `AdaptationSet` — player can switch between them

---

## 3. Segment Naming Rules

```
init.mp4                 — Initialization segment (moov box)
seg_0.m4s                — First media segment (moof + mdat)
seg_1.m4s                — Second media segment
seg_2.m4s                — ...
seg_{N}.m4s              — Nth media segment (0-indexed)
```

**Rules:**
1. **Zero-indexed**: Segment indices start at 0 (matching DASH `startNumber="0"`)
2. **File extensions**: `.mp4` for init, `.m4s` for media segments
3. **Naming pattern**: Fixed `seg_{index}.m4s` — no variable bitrates in filename
4. **Unique per job**: Each TranscodeJob (quality tier) has its own segment set
   under `/api/stream/{jobPublicId}/`
5. **CMAF independent segments**: Each `.m4s` file is independently decodable
   after the init segment has been loaded

---

## 4. Quality Ladder Response Schema

**Endpoint:** `GET /api/stream/{videoId}/quality-ladder`

**Response:**
```json
{
  "data": [
    {
      "name": "360p",
      "height": 360,
      "width": 640,
      "bitrate": 800000,
      "codec": "hvc1.1.6.L93.B0"
    },
    {
      "name": "720p",
      "height": 720,
      "width": 1280,
      "bitrate": 2800000,
      "codec": "hvc1.1.6.L93.B0"
    },
    {
      "name": "1080p",
      "height": 1080,
      "width": 1920,
      "bitrate": 5000000,
      "codec": "hvc1.1.6.L93.B0"
    }
  ]
}
```

**Notes:**
- Only returns tiers that have at least one completed segment (not just "queued")
- Tiers are ordered by resolution ascending
- `bitrate` is the average bitrate in bps
- `codec` is the RFC 6381 codec string for the video track
- Audio codec is always `mp4a.40.2` (AAC-LC) — not included in the response

---

## 5. 202 Accepted + Retry-After Protocol

### Backend Behaviour (from StreamSegmentController)

When `getSegment(jobPublicId, index)` returns `null`:
- Response: `HTTP 202 Accepted`
- Header: `Retry-After: <seconds>`

This means the segment exists in the job's segment map (the manifest knows about it)
but the actual encoded data is not yet available on disk.

### Player Behaviour

1. Receive 202 → read `Retry-After` header
2. Calculate backoff delay: `retryAfter * 1.5^attempt` with ±20% jitter
3. Wait, then retry the same URL
4. Maximum 10 retries before reporting error
5. Cap retry delay at 30 seconds

This is particularly important for **progressive encoding** — the backend may be
actively transcoding while the user watches. Lower-quality tiers finish first,
so the player should fall back to a lower tier if the current tier's segments
aren't ready.

---

## 6. Transcode Session API

### Create Session

**Endpoint:** `POST /api/transcode/sessions/`

**Request Body:**
```json
{
  "videoId": "550e8400-e29b-41d4-a716-446655440000",
  "qualityTier": "1080p",
  "audioProfile": "streaming_stereo",
  "priority": "normal"
}
```

**Quality Tiers:** `360p`, `480p`, `720p`, `1080p`, `1440p`, `4K`
**Audio Profiles:** `mobile_mono`, `mobile_stereo`, `streaming_stereo`, `streaming_5.1`,
  `broadcast_stereo`, `broadcast_5.1`, `hifi_stereo`, `opus_stereo`
**Priorities:** `critical`, `high`, `normal`, `low`, `bulk`

### Use Case: "Remix This Moment"

The player's AI layer can trigger a new transcode session to re-encode a specific
moment at a different quality or with different parameters:

```ts
const sessionId = await aiOrchestrator.remixMoment({
  startTime: 42.5,
  endTime: 55.0,
  qualityTier: '4K',
});
```

This creates a new TranscodeSession via `POST /api/transcode/sessions/`
with `priority: 'critical'` and the specified quality tier.

---

## 7. Audio Profile Reference

| Profile | Codec | Bitrate | Channels | Sample Rate | Use Case |
|---------|-------|---------|----------|-------------|----------|
| mobile_mono | AAC | 32 kbps | 1.0 | 44.1 kHz | Low bandwidth / voice-only |
| mobile_stereo | AAC | 64 kbps | 2.0 | 44.1 kHz | Mobile streaming |
| streaming_stereo | AAC | 128 kbps | 2.0 | 48 kHz | Default streaming |
| streaming_5.1 | AAC | 256 kbps | 5.1 | 48 kHz | Surround sound |
| broadcast_stereo | AAC | 192 kbps | 2.0 | 48 kHz | High-quality stereo |
| broadcast_5.1 | AAC | 320 kbps | 5.1 | 48 kHz | Broadcast surround |
| hifi_stereo | AAC | 256 kbps | 2.0 | 48 kHz | Audiophile quality |
| opus_stereo | Opus | 96 kbps | 2.0 | 48 kHz | WebRTC / low-latency |

The default audio profile for streaming is `streaming_stereo` (128kbps AAC, 2-channel, 48kHz).
This is hardcoded in the backend's `StreamManifestController::mediaManifest()` as the
second parameter to `getMediaManifest()`.

---

## 8. DPoP Authentication

All `/api/stream/*` endpoints require authentication (`ROLE_USER`).
The frontend uses a DPoP (Demonstration of Proof-of-Possession) flow
handled by the service worker at `ui/web/src/features/player/services/auth-stream-worker.ts`.

The service worker:
1. Intercepts all fetch requests to `/api/stream/*` and `/api/images/*`
2. Requests a DPoP proof signature from the main thread via `postMessage`
3. Adds `Authorization: DPoP <token>` and `DPoP: <proof>` headers
4. Forwards the request

The player doesn't need to handle authentication directly — it just makes normal
`fetch()` calls and the service worker adds the auth headers transparently.

---

## 9. Recommended Backend Improvements

1. **Add `Retry-After` to segment 202 responses**: The `StreamSegmentController::segment()`
   method should explicitly set the `Retry-After` header when returning 202.

2. **Add `Content-Type` to quality-ladder response**: The quality-ladder endpoint
   returns JSON but should explicitly set `Content-Type: application/json`.

3. **DASH audio AdaptationSet**: The current `DashManifestGenerator` only creates
   a video AdaptationSet. For multi-audio-track support, add a separate
   `<AdaptationSet mimeType="audio/mp4">` with audio representations.

4. **HLS audio group**: The HLS master playlist should include `#EXT-X-MEDIA`
   tags for audio renditions to support multi-audio-track selection.

5. **Segment duration in manifest**: The DASH `<S d="...">` values should match
   the actual encoded segment durations precisely (currently they're rounded to ms).

6. **CORS headers**: Ensure all streaming endpoints include proper CORS headers
   for cross-origin player embedding:
   ```
   Access-Control-Allow-Origin: *
   Access-Control-Allow-Headers: Authorization, DPoP
   Access-Control-Expose-Headers: Retry-After, Content-Range
   ```
