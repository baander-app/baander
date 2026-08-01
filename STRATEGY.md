# Baander Product Strategy

> Self-hosted media server. Music first, expanding to movies, TV, ebooks, comics, concerts.
> Own your library. Beautiful UX. Open source.

---

## Vision

Baander is the self-hosted media server for people who care about their library and their experience. It combines the media management power of Plex/Jellyfin with the UX polish of Spotify/Apple — without subscriptions, data harvesting, or vendor lock-in.

The differentiator isn't any single feature. It's doing everything well: metadata-rich library management, beautiful playback interfaces, smart recommendations, and precise editing tools — all on hardware you control.

## Target Users

### Primary: Listener
Browses, searches, plays. Wants speed and quality. Expects the UI to get out of the way and let the content speak. Mobile-first habits, desktop-grade expectations.

### Secondary: Admin
Manages the library, users, and infrastructure. Wants control and reliability. Needs visibility into scan progress, transcode queues, storage usage, and user activity.

### Tertiary: Developer
Self-hosts, extends, or integrates. Wants clean APIs, good documentation, and straightforward deployment.

## Competitive Positioning

| Competitor | What they do well | Where they fall short | Baander's answer |
|------------|-------------------|----------------------|------------------|
| Plex | Polish, discovery, broad device support | Enshittification, ads in paid product, phone-home requirements | No tracking, no ads, no account requirements |
| Jellyfin | Open source, community, no tracking | UI feels like a web app, metadata management is manual and clunky | Design-first UI, excellent metadata editing, Blurhash-derived dynamic theming |
| Navidrome | Lightweight, Subsonic-compatible | Music only, basic UI, no recommendations | Full media scope, smart recs, visual polish |
| Spotify/Apple Music | Discovery, UX, social | You don't own anything, rental model | Ownership + great UX |
| Subsonic/Airsonic | Mature API, client ecosystem | Dated architecture, poor performance | Modern async stack (Swoole), fast API |

**Core positioning:** The self-hosted media server with a UX worth switching for.

## What's Built

### Music — Production-Grade

The music vertical is substantially complete end-to-end. This is not alpha-quality — it's close to beta.

| Area | Backend | Frontend | Notes |
|------|---------|----------|-------|
| **Catalog browsing** | Catalog context (Song, Album, Artist — 6 aggregates), PGroonga full-text search | 110 files, 6 view modes (Grid, List, Column Browser, Activity, Timeline, Discover), context menus, metadata editing, duplicate merging | Full browse/search/edit flow |
| **Audio playback** | Transcode context (FFmpeg, HLS/DASH, Swoole process pool) | Queue management, shuffle/repeat, volume, WASM audio processing, activity tracking, progress bar | Play → listen end-to-end |
| **Radio** | Radio context (IPRD station sync, country subscriptions, starred stations) | Station browser, country picker, stream fallback, player integration (pauses music on radio start) | Fully functional |
| **Equalizer** | — (client-side processing) | 13 files — parametric EQ, band EQ, profiles, A/B compare panel, processing controls | Audiophile-grade |
| **Lyrics** | Lyrics context (LRCLIB anti-corruption layer, synced lyrics) | Fullscreen overlay, LRC parser, audio-lyric synchronization | Synced + unsynced |
| **Metadata** | Metadata context (Discogs, MusicBrainz, Last.fm, Spotify, CoverArtArchive, TasteDive adapters) | Inline + bulk metadata editing, provider selection, cover art management | 6 external providers |
| **Library management** | Library context (scan orchestration, path validation, stats) | Library CRUD, scan polling, path validation, stats panel | Admin + user-facing |
| **Admin dashboard** | 18+ contexts expose admin endpoints | 102 files — dashboard, job monitor, scheduler, user CRUD, genre management, duplicate detection, diagnostics, rate limiters, activity log, login blocks | SSE real-time feeds, charts |
| **Auth** | Auth context (OAuth2, WebAuthn/passkeys, TOTP, role-based ACL) | Login, register, passkey management, protected routes | 9 domain events |
| **Settings** | UserPreference context (audio, layout, player prefs, server-side sync) | Volume normalization (LUFS targets), EQ integration, account management, passkey management | Conflict detection, history |
| **Notifications** | Notification context (push, webhooks with Discord/Slack) | SSE-based bell icon, popout list | |
| **Session** | Session context (device identity, listening sessions) | Device management, session transfer | |
| **Party mode** | Party context (SyncedPartySession, PartyMember, 9 CQRS handlers, WebSocket-driven sync) | SSE + REST controllers | Real collaborative playback |
| **Recommendations** | Recommendation context (domain services, background jobs) | Discover view, recommendation cards | Uses old positional-arg pattern (migration pending) |
| **Playlists** | Playlist context (Playlist, SmartPlaylist, collaborator entities) | Add-to-playlist dialog, smart playlist editor | Functional but thin |
| **Desktop app** | — | Electron wrapper — window management, tray, menus, deep links, keyring, single-instance lock, CSP hardening | Production-quality shell |
| **Spotlight search** | — | Keyboard-driven global search overlay | |

### Shared Infrastructure

| Asset | What it is | Why it matters |
|-------|-----------|----------------|
| **Generated API client** | 32K lines auto-generated from OpenAPI spec via Orval | Every backend endpoint has typed TypeScript bindings. Zero drift between API and frontend. |
| **Design language** | `ui/DESIGN.md` — 255 lines covering layout, typography, color, motion, components, anti-patterns | Authoritative. When in doubt, the doc wins. Reference era: Apple 2010–2020. |
| **Mediator bus** | Custom event bus with devtools, store registry | Decoupled feature communication |
| **DPoP (RFC 9449)** | Shared crypto layer — proof generation, key pair management, IndexedDB auth storage | Security-grade auth |
| **Advanced player package** | `baander-player` — DASH, HLS, ABR, SegmentScheduler, ImmersiveRenderer (WebXR), PartySyncBus | Not wired in yet (web uses simpler HTMLAudioElement player). Strategic asset for video streaming. |
| **React Native scaffold** | 115 files — mobile (tabs + mini-player), desktop (three-panel), TV (D-pad, focus management) | Active scaffold with surprising TV depth. Not "no priority" — it exists and has real layouts. |

### Non-Music Media — Aspirational

| Media Type | Backend | Frontend | Status |
|------------|---------|----------|--------|
| Movies | No context exists | Placeholder page ("Coming soon") | Not started |
| TV Shows | No context exists | Placeholder page ("Coming soon") | Not started |
| eBooks/Comics | No context exists | Placeholder page ("Coming soon") | Not started |
| Concerts | No context exists | Placeholder page ("Coming soon") | Not started |
| Podcasts | No context exists | Placeholder page ("Coming soon") | Explicitly deferred |

The frontend has a shared `MediaTypeHomePage` component designed for rapid fill-in once backend support exists. The sidebar already has navigation entries for all types. The gap is backend: each new media type needs its own bounded context with aggregates, repositories, Doctrine entities, controllers, and metadata providers.

## Phased Scope

### Phase 1: Ship Music (Current)
- Polish the music vertical to dogfood-ready
- End-to-end scan → browse → play → edit with zero friction
- Get first external user running Baander
- Focus: Library + Catalog + Playback reliability + UI polish

### Phase 2: Add One New Media Type
- Pick the highest-value expansion (likely Movies — largest user base after music, exercises video streaming and the advanced player package)
- Create new bounded context, metadata providers (TMDB/IMDb), video transcoding pipeline
- Wire `baander-player` package for adaptive streaming

### Phase 3: Expand to Full Scope
- TV Shows (builds on Movies infrastructure — series/season/episode hierarchy)
- eBooks/Comics (different metadata model, reader UI)
- Concerts (unique: links to music albums, live recording metadata)
- Each type follows the same pattern: context → entities → API → frontend

### Phase 4: Engagement & Social
- Smart recommendations beyond basic content-based
- Collaborative playlists, listening rooms
- Activity feeds, social features beyond Party mode

## Work Tracks & Bounded Context Mapping

### Track 1: Library & Catalog
*The foundation. Everything depends on this being right.*

- **Contexts:** Library, Catalog, Metadata, Filesystem
- **Maturity:** Mature (Catalog, Metadata) / Partial (Library — tight coupling, no state object)
- **Focus:** Fast scanning, rich metadata extraction, multi-source providers, manual editing UI
- **Risk:** Library has the heaviest cross-context coupling (Doctrine FKs to Auth, direct commands to Metadata, port calls to Catalog). Flagged for event-driven refactoring before adding new media types.

### Track 2: Playback & Streaming
*The core experience. This is what users do most.*

- **Contexts:** Media, Transcode, Party, Radio
- **Maturity:** Mature (Transcode, Party, Radio) / Partial (Media)
- **Focus:** Audio streaming, adaptive transcoding, synchronized Party mode playback, internet radio
- **Strategic asset:** `baander-player` package (DASH/HLS/ABR) ready for video streaming in Phase 2

### Track 3: User & Auth
*Multi-user from day one.*

- **Contexts:** Auth, UserPreference, Session
- **Maturity:** Mature (Auth) / Partial (UserPreference, Session)
- **Focus:** OAuth2 + WebAuthn, per-user preferences, session management, role-based access

### Track 4: Discovery & Social
*The engagement layer. Keeps people in the app.*

- **Contexts:** Recommendation, Playlist, Activity, Lyrics
- **Maturity:** Partial (all four — Lyrics most complete)
- **Focus:** Content-based and collaborative recommendations, smart playlists, listening history, synced lyrics
- **Tech debt:** Recommendation uses old positional-arg aggregate pattern. Migration to state-object pattern pending.

### Track 5: Platform & Infrastructure
*The enablers. Not visible to users, but everything depends on them.*

- **Contexts:** Shared, Notification, Command, Scheduler
- **Maturity:** Utility (Shared, Command) / Partial (Notification, Scheduler)
- **Focus:** API surface, real-time events (SSE), async command dispatch, scheduled tasks, generated API client

## Technical Debt

| Item | Context | Impact | When to address |
|------|---------|--------|-----------------|
| Library tight coupling | Library | Direct FKs and port calls to Auth, Metadata, Catalog. Will multiply when adding non-music media types. | Before Phase 2 |
| Recommendation old pattern | Recommendation | Uses positional-arg constructors instead of state-object pattern. Inconsistent with codebase conventions. | Phase 1 cleanup |
| Contexts without state objects | Media, Playlist, Activity, Notification, Library | Inconsistent with mature contexts (Auth, Catalog, Transcode, Party all use state objects). | Ongoing |
| Published events with no consumers | Party, Playlist, Transcode, Catalog | Many domain events fire but nothing listens. Dead event infrastructure. | When consumers are needed |
| baander-player not wired | Web frontend | Advanced player package exists but web uses simpler HTMLAudioElement. Duplication risk. | Phase 2 (video) |

## Product Principles

1. **Content is the interface.** The UI gets out of the way. Album art, movie posters, book covers do the visual heavy lifting.
2. **Metadata is a first-class citizen.** Rich metadata isn't optional — it's the product. Multiple providers, merge strategies, and excellent editing tools.
3. **Speed is a feature.** Swoole async runtime, PGroonga full-text search, Redis caching — the architecture exists to make things fast. The UX must reflect that.
4. **Own your library.** No phone-home, no accounts you don't control, no DRM. The server is yours.
5. **Progressive disclosure.** Simple by default, powerful on demand. The casual listener sees a clean interface. The power user gets batch editing, advanced filters, and keyboard shortcuts.
6. **Open source, free forever.** No freemium gating. No premium tier. Community-driven.

## Technology Foundation

| Layer | Choice | Why |
|-------|--------|-----|
| Runtime | PHP 8.5 + Swoole (async coroutines) | Worker reuse, persistent connections, sub-millisecond request routing |
| Framework | Symfony 8 | Mature DI, Messenger for CQRS, Doctrine ORM integration |
| Database | PostgreSQL 18 + PGroonga | Full-text search without external service |
| Cache/PubSub | Redis | Tag-aware cache, Messenger transport, SSE pub/sub |
| Frontend | React + TypeScript + Vite + Tailwind v4 | Fast DX, type-safe, design-system-ready |
| Desktop | Electron wrapper | One codebase, desktop presence |
| Transcoding | FFmpeg via Swoole CPU process pool | Non-blocking, bounded concurrency |
| API | REST + OpenAPI (Nelmio) → Orval codegen | Standard, documented, zero-drift typed client |
| Deployment | Docker Compose | Nginx + Swoole + PostgreSQL + Redis. Frontend dev server on host. |

## Key Metrics

*No baselines measured yet. Targets below are aspirational — first external user session will establish real numbers.*

| Metric | Target | Why it matters | How to measure |
|--------|--------|----------------|----------------|
| Library scan time | < 30s for 10k tracks | First impression. Slow scans = abandoned setup | Time from scan trigger to completion |
| Search latency | < 100ms p95 | Must feel instant, like local files | PGroonga query timing |
| Stream startup | < 200ms | Play button → audio in under 200ms | Client-side: click to `timeupdate` event |
| Concurrent users | 20+ on modest hardware | Party mode and household use | Load test with Swoole workers |
| Transcode queue depth | < 5 pending | Background transcoding shouldn't block playback | Queue monitoring |
| API response time | < 50ms p99 | All interactions feel local | Nginx access log p99 |

## Current State

**Phase 1 in progress.** Music vertical is ~85% complete end-to-end.

- **Backend:** 19 bounded contexts (3 mature, 12 partial, 3 utility, 1 downstream-only). All have real business logic — no empty scaffolds. 226 test files, concentrated in Auth, Transcode, Catalog, Metadata.
- **Frontend:** 440 TypeScript/TSX files, 257 components, 40+ routes. Music catalog, player, radio, equalizer, lyrics, admin dashboard, auth, settings, library management, notifications, party mode — all functional. Non-music types are placeholders.
- **Desktop:** Electron wrapper is production-quality (menus, tray, deep links, keyring, security hardening).
- **Mobile/TV:** React Native scaffold exists (115 files) with mobile, desktop, and TV layouts. Not a current priority but not abandoned.
- **Status:** Internal dogfood for the music vertical. Not yet usable by others for non-music media.

## Near-Term Goals (Phase 1)

1. **End-to-end music flow, zero friction** — scan → browse → play → edit → search → radio → lyrics → party. One complete polished journey.
2. **Metadata editing UI** — inline and bulk editing for albums, tracks. The "fantastic UI for editing" you called out as a differentiator.
3. **Playback reliability** — gapless audio, proper seeking, correct stream handoff, queue persistence across sessions.
4. **First external user** — get someone outside the project running Baander for their music library.
5. **Library coupling refactor** — decouple Library from Auth/Metadata/Catalog before Phase 2 adds new media types that need the same orchestration.
6. **Recommendation migration** — move to state-object pattern for consistency.

## Out of Scope (Until Phase 3+)

- Movies, TV Shows, eBooks, Comics, Concerts (Phase 2+)
- Mobile apps (RN scaffold exists but not a priority until web is solid)
- Social features beyond Party mode
- Podcasts
- User-generated content / upload flows
- Federation between Baander instances
