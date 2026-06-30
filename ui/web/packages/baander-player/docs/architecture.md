# Baander Immersive Video Player — Architecture

## Overview

A zero-dependency, fully custom immersive video player written in modern TypeScript (5.5+).
Replaces hls.js / dash.js / Shaka Player with a purpose-built player tailored to the Baander
backend's exact streaming API surface.

**No external playback libraries.** Only Three.js is used as a peer dependency for immersive
(WebXR / splat) rendering, and it's tree-shaken away when not needed.

---

## Backend API Mapping

| Player Module | Backend Endpoint | Backend Class |
|---|---|---|
| UnifiedManifestEngine | `GET /api/stream/{videoId}/master.m3u8` | StreamManifestController::masterManifest |
| UnifiedManifestEngine | `GET /api/stream/{jobPublicId}/media.m3u8` | StreamManifestController::mediaManifest |
| UnifiedManifestEngine | `GET /api/stream/{videoId}/manifest.mpd` | StreamManifestController::dashManifest |
| UnifiedManifestEngine | `GET /api/stream/{videoId}/quality-ladder` | StreamManifestController::qualityLadder |
| AdaptiveTransportLayer | `GET /api/stream/{jobPublicId}/init.mp4` | StreamSegmentController::initSegment |
| AdaptiveTransportLayer | `GET /api/stream/{jobPublicId}/seg_{index}.m4s` | StreamSegmentController::segment |
| AIOrchestrator | `POST /api/transcode/sessions/` | TranscodeSessionController::create |
| PartySyncBus | WebSocket `/api/party/ws` | (future: PartyController) |
| TelemetryReporter | `POST /api/telemetry/player` | (future: TelemetryController) |

---

## Module Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│                        BaanderPlayer (orchestrator)               │
│                                                                  │
│  ┌─────────────┐  ┌──────────────┐  ┌─────────────────────┐     │
│  │ PlaybackSM  │  │ Segment      │  │ TelemetryReporter   │     │
│  │ (state)     │  │ Scheduler    │  │                     │     │
│  └─────────────┘  └──────┬───────┘  └─────────────────────┘     │
│                          │                                       │
│  ┌───────────────────────┼───────────────────────────────┐      │
│  │                       ▼                                │      │
│  │  ┌────────────────────────────────┐  ┌─────────────┐ │      │
│  │  │  AdaptiveTransportLayer        │  │ OfflineStore│ │      │
│  │  │  (Fetch / HTTP3 / WT / MoQ)   │  │ (IndexedDB) │ │      │
│  │  └───────────────┬────────────────┘  └─────────────┘ │      │
│  │                  │                                    │      │
│  │                  ▼                                    │      │
│  │  ┌────────────────────────────────┐  ┌─────────────┐ │      │
│  │  │  HybridBufferEngine            │  │ SmartABR    │ │      │
│  │  │  (MSE primary / WebCodecs fb) │◄─┤ Controller  │ │      │
│  │  └───────────────┬────────────────┘  └──────┬──────┘ │      │
│  │                  │                          │        │      │
│  │                  ▼                          │        │      │
│  │  ┌────────────────────────────────┐        │        │      │
│  │  │  UnifiedManifestEngine         │────────┘        │      │
│  │  │  (HLS v6 + DASH MPD → Model)  │                  │      │
│  │  └────────────────────────────────┘                  │      │
│  └──────────────────────────────────────────────────────┘      │
│                                                                  │
│  ┌─────────────────┐  ┌──────────────┐  ┌──────────────────┐   │
│  │ ImmersiveRenderer│  │ AIOrchestrator│  │ PartySyncBus    │   │
│  │ (360° / WebXR)  │  │ (TF.js/WebNN)│  │ (WebSocket)    │   │
│  └─────────────────┘  └──────────────┘  └──────────────────┘   │
└──────────────────────────────────────────────────────────────────┘
```

---

## Data Flow

### 1. Initialization

```
User → BaanderPlayer.attach(videoElement)
     → BaanderPlayer.load(videoId)
       → UnifiedManifestEngine.load(videoId)
         → fetch /api/stream/{videoId}/quality-ladder
         → fetch /api/stream/{videoId}/master.m3u8  (or manifest.mpd)
         → parse HLS master → fetch each media.m3u8 → parse segments
         → normalise → Manifest model
       → SegmentScheduler.setManifest(manifest)
       → SmartABRController.selectInitialRendition()
       → HybridBufferEngine.init(rendition)
       → AdaptiveTransportLayer.fetchInitSegment(init.mp4)
       → HybridBufferEngine.appendInit(data)
       → SegmentScheduler.start(0)
         → AdaptiveTransportLayer.fetchSegment(seg_0.m4s, { priority: 0 })
         → HybridBufferEngine.appendSegment(0, data)
         → ... next segments with increasing priority
       → PlaybackStateMachine: loading → buffering → ready
```

### 2. Playback Loop

```
Every 2s:
  → SmartABRController.evaluate(bufferHealth)
  → if rendition change → SegmentScheduler.switchRendition()
  → HybridBufferEngine.evictIfNeeded() (remove old buffer behind playhead)

Every 250ms:
  → onTimeUpdate callback

Continuous:
  → SegmentScheduler fills look-ahead window
  → AdaptiveTransportLayer drains priority queue
  → HybridBufferEngine appends to SourceBuffer
```

### 3. 202 Accepted Handling

```
AdaptiveTransportLayer.fetchSegment() → HTTP 202
  → read Retry-After header
  → exponential backoff: delay = retryAfter * 1.5^attempt
  → jitter: delay * (0.8 + random * 0.4)
  → re-enqueue with same priority
  → max 10 retries, then report FetchError
```

---

## Key Design Decisions

### Why MSE + WebCodecs Hybrid?

- **MSE** is the primary path because it's the most mature and performant for
  HTMLMediaElement-based playback. All major browsers support it.
- **WebCodecs** is the fallback for environments where MSE is unavailable or
  doesn't support the codec (e.g., HEVC in Firefox). It decodes directly and
  renders to a canvas.
- The hybrid approach is transparent — `HybridBufferEngine` auto-selects at init.

### Why a Unified Manifest Model?

- Both HLS and DASH carry the same information: renditions with bitrate/resolution,
  init segments, media segments with durations.
- Normalising to a single `Manifest` type means the buffer, transport, and ABR
  modules are **format-agnostic** — they don't care whether the source was HLS or DASH.

### Why Priority-Queued Transport?

- Segments near the playhead must be fetched before prefetch segments.
- A min-heap priority queue ensures O(log n) enqueue/dequeue.
- Concurrency control (default: 6 parallel) prevents connection exhaustion.

### Why Content-Aware ABR?

- Sport and gaming content needs higher sustained quality than static content.
- The AI layer classifies frames and provides `ContentHint` to the ABR controller.
- ABR adjusts bandwidth safety margins and buffer thresholds per content type.

---

## Backend Integration Notes

### Authentication

All `/api/stream/*` requests go through the DPoP auth service worker
(`ui/web/src/features/player/services/auth-stream-worker.ts`).
The player passes `customHeaders` if needed, but the SW intercepts and adds
the actual `Authorization: DPoP ...` headers automatically.

### Codec Compatibility

| Codec | MSE Support | Notes |
|-------|-------------|-------|
| hvc1.1.6.L93.B0 (HEVC) | Safari ✓, Chrome 107+ ✓, Firefox ✗ | Primary backend codec |
| avc1.640028 (AVC/H.264) | All browsers ✓ | Fallback for non-HEVC browsers |
| mp4a.40.2 (AAC-LC) | All browsers ✓ | Audio codec (always supported) |

The `HybridBufferEngine` auto-detects MSE codec support and falls back to AVC
or WebCodecs as needed.

### CMAF Segment Structure

All segments use the CMAF (Common Media Application Format) pattern:
- **init.mp4**: `ftyp` + `moov` boxes (codec config, track metadata, sample descriptions)
- **seg_N.m4s**: `moof` + `mdat` boxes (fragment metadata + compressed samples)

The `moov` box in init.mp4 contains:
- `mvhd` (movie header — timescale, duration)
- `trak` → `mdia` → `minf` → `stbl` (sample table with empty samples — filled by moof)
- `trak` → `mdia` → `hvcC` (HEVC decoder config record)

---

## Feature Flags

| Flag | Default | Description |
|------|---------|-------------|
| `webcodecs` | true | Enable WebCodecs fallback |
| `webtransport` | true | Enable WebTransport transport |
| `moq` | false | Enable MoQ (experimental) |
| `immersive` | false | Enable 360° rendering |
| `webxr` | false | Enable WebXR VR/AR |
| `splats` | false | Enable 3D Gaussian Splatting |
| `ai` | false | Enable AI scene classification |
| `party` | false | Enable co-watching sync |
| `offline` | true | Enable offline caching |
| `telemetry` | true | Enable telemetry reporting |
| `predictivePrefetch` | true | Enable AI-driven prefetch |
| `multiViewport` | false | Enable multi-viewport / director cuts |

---

## Web Workers

| Worker | Purpose | Location |
|--------|---------|----------|
| ai-worker.ts | AI inference (TF.js / WebNN) | Runs scene classification + highlight detection off main thread |

Workers communicate via structured clone (postMessage). ImageBitmap transfers
avoid copying pixel data.

---

## File Structure

```
src/
├── index.ts                          # Public API re-exports
├── BaanderPlayer.ts                  # Main orchestrator class
├── types.ts                          # All core types
│
├── core/
│   ├── manifest/
│   │   └── UnifiedManifestEngine.ts  # HLS + DASH → normalised Manifest
│   ├── transport/
│   │   └── AdaptiveTransportLayer.ts # Fetch/HTTP3/WT/MoQ with 202 retry
│   ├── buffer/
│   │   └── HybridBufferEngine.ts     # MSE + WebCodecs buffer engine
│   ├── abr/
│   │   └── SmartABRController.ts     # Content-aware adaptive bitrate
│   ├── state/
│   │   └── PlaybackStateMachine.ts   # Strict state machine
│   ├── scheduler/
│   │   └── SegmentScheduler.ts       # Fetch orchestration
│   └── telemetry/
│       └── TelemetryReporter.ts      # Batched telemetry
│
├── immersive/
│   └── ImmersiveRenderer.ts          # 360° WebGL + WebXR
│
├── ai/
│   └── AIOrchestrator.ts             # Scene classification + highlights
│
├── party/
│   └── PartySyncBus.ts               # Co-watching WebSocket sync
│
├── offline/
│   └── OfflineStore.ts               # IndexedDB + Cache API
│
├── workers/
│   └── ai-worker.ts                  # AI inference Web Worker
│
└── utils/                            # (future utilities)
```

---

## Quality Ladder (Backend Presets)

| Tier | Resolution | Bitrate | Max Bitrate | Buffer Size |
|------|-----------|---------|-------------|-------------|
| 360p | 640×360 | 800 Kbps | 1.2 Mbps | 1.6 Mbps |
| 480p | 854×480 | 1.4 Mbps | 2.1 Mbps | 2.8 Mbps |
| 720p | 1280×720 | 2.8 Mbps | 4.2 Mbps | 5.6 Mbps |
| 1080p | 1920×1080 | 5.0 Mbps | 7.5 Mbps | 10.0 Mbps |
| 1440p | 2560×1440 | 10.0 Mbps | 15.0 Mbps | 20.0 Mbps |
| 4K | 3840×2160 | 20.0 Mbps | 30.0 Mbps | 40.0 Mbps |

All tiers use HEVC (`hvc1`) with RFC 6381 codec string `hvc1.1.6.L93.B0`
and AAC-LC audio (`mp4a.40.2`).

---

## Segment URL Convention

```
/api/stream/{jobPublicId}/init.mp4       — Init segment (fMP4 moov)
/api/stream/{jobPublicId}/seg_0.m4s      — First media segment
/api/stream/{jobPublicId}/seg_1.m4s      — Second media segment
...
/api/stream/{jobPublicId}/seg_N.m4s      — Nth media segment
```

Where `{jobPublicId}` is the public ID of the TranscodeJob for a specific
quality tier. Each quality tier has its own job and thus its own segment set.

### 202 Accepted Response

When a segment is not yet encoded, the endpoint returns:
```
HTTP/1.1 202 Accepted
Retry-After: 2
```

The player retries with exponential backoff (1s → 1.5s → 2.25s → ... → 30s max).
Up to 10 retries before reporting an error.
