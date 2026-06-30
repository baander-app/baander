# @baander/player

Zero-dependency immersive video player for Baander. Replaces hls.js / dash.js / Shaka
with a purpose-built TypeScript player tailored to the Baander backend's streaming API.

## Features

- **Unified Manifest Parser** — HLS v6 + DASH MPD → normalised internal model
- **Hybrid Buffer Engine** — MSE primary + WebCodecs fallback
- **Adaptive Transport** — Fetch / HTTP/3 / WebTransport / MoQ with auto-negotiation
- **Smart ABR** — Content-aware adaptive bitrate mirroring backend QualityLadder
- **202 Accepted Retry** — Full backoff logic for progressive encoding
- **360° Immersive** — Equirectangular / cubemap rendering via Three.js
- **WebXR** — Full VR/AR session support
- **AI Layer** — On-device scene classification, highlight detection, predictive prefetch
- **Party Sync** — Real-time co-watching with WebSocket synchronisation
- **Offline** — IndexedDB + Cache API for full offline playback
- **Telemetry** — Batched analytics reporting back to backend

## Installation

```bash
yarn add @baander/player
```

For immersive features, also install Three.js:

```bash
yarn add three
```

## Quick Start

```typescript
import { BaanderPlayer } from '@baander/player';
import type { PlayerConfig, PlayerEvents } from '@baander/player';

const videoElement = document.querySelector('video')!;
const container = document.querySelector('#player-container')!;

const events: PlayerEvents = {
  onStateChange: (state) => console.log('State:', state),
  onManifestLoaded: (manifest) => console.log('Manifest loaded:', manifest.renditions.length, 'renditions'),
  onRenditionChange: (rendition) => console.log('Quality:', rendition.name),
  onBufferUpdate: (stats) => console.log('Buffer:', stats.forwardBuffer.toFixed(1) + 's'),
  onTimeUpdate: (time, duration) => console.log(`${time.toFixed(1)} / ${duration.toFixed(1)}`),
  onError: (error) => console.error('Error:', error.message),
  onQualityChange: (tier) => console.log('Tier:', tier.name),
};

const player = new BaanderPlayer(
  {
    baseUrl: 'https://baander.local',
    preferredFormat: 'hls',
    initialQuality: '720p',
    features: {
      immersive: false,
      webxr: false,
      ai: false,
      party: false,
      offline: true,
    },
  },
  events,
);

await player.attach(videoElement, container);
await player.load('video-uuid-here');
player.play();
```

## API Reference

### BaanderPlayer

| Method | Description |
|--------|-------------|
| `attach(video, container?)` | Attach to a `<video>` element |
| `load(videoId)` | Load a video by UUID |
| `play()` | Start playback |
| `pause()` | Pause playback |
| `seekTo(time)` | Seek to time in seconds |
| `setABRStrategy(strategy)` | Set ABR mode: `throughput`, `buffer`, `content-aware`, `manual` |
| `setQuality(tierName)` | Manually select quality (e.g. `"1080p"`) |
| `setProjection(type)` | Set projection: `flat`, `equirectangular`, `cubemap` |
| `enterXR(mode?)` | Enter VR/AR mode |
| `exitXR()` | Exit VR/AR |
| `joinParty(sessionId, userId, name)` | Join co-watching session |
| `downloadForOffline()` | Download current video for offline |
| `getState()` | Get current PlaybackState |
| `getManifest()` | Get current Manifest |
| `getBufferStats()` | Get BufferStats |
| `getCurrentRendition()` | Get current Rendition |
| `destroy()` | Release all resources |

### Types

All types are exported from the main entry point:

```typescript
import type {
  PlayerConfig,
  FeatureFlags,
  Manifest,
  Rendition,
  PlaybackState,
  QualityTierInfo,
  BufferStats,
  SpatialState,
} from '@baander/player';
```

## Backend Integration

The player is designed to work with the Baander Symfony backend's streaming endpoints:

| Endpoint | Purpose |
|----------|---------|
| `GET /api/stream/{videoId}/master.m3u8` | HLS master playlist |
| `GET /api/stream/{jobPublicId}/media.m3u8` | HLS media playlist |
| `GET /api/stream/{videoId}/manifest.mpd` | DASH MPD manifest |
| `GET /api/stream/{videoId}/quality-ladder` | Available quality tiers |
| `GET /api/stream/{jobPublicId}/init.mp4` | fMP4 init segment |
| `GET /api/stream/{jobPublicId}/seg_{index}.m4s` | fMP4 media segment |
| `POST /api/transcode/sessions/` | Create transcode session |

Authentication is handled transparently by the DPoP service worker.

## Documentation

- [architecture.md](./docs/architecture.md) — Full architecture overview
- [implementation-notes.md](./docs/implementation-notes.md) — Backend integration details

## Development

```bash
yarn install
yarn dev          # Start Vite dev server
yarn build        # Build for production
yarn test         # Run tests
yarn test:watch   # Watch mode
yarn typecheck    # TypeScript checking
```

## License

Private — Baander project internal.
