# Requirements: Internet Radio

## Problem

Baander is library-only — no live content. Users who want internet radio must leave the app. This feature adds live radio streaming sourced from the International Public Radio Directory (IPRD), with an extensible source model for future providers.

## Goals

- Users can discover and stream internet radio stations inside Baander
- Radio playback integrates with the existing player bar (mutual exclusion with music)
- Station data sourced from IPRD initially, extensible to other providers
- Users subscribe to countries, browse/search stations, star favorites
- Station data stays current via periodic sync + manual refresh
- Dead streams handled gracefully with fallback and suggestions

## Non-goals

- Recording radio streams
- Radio-specific equalizer / DSP (uses existing equalizer)
- User-uploaded custom stations (future consideration)
- Social features (sharing stations, collaborative radio)
- Offline radio

## Data source: IPRD

**Endpoints:**
- Summary: `https://iprd-org.github.io/iprd/site_data/summary.json` — country codes + station counts
- Catalog: `https://iprd-org.github.io/iprd/site_data/metadata/catalog.json` — structured station objects
- Country M3U: `https://iprd-org.github.io/iprd/site_data/by_country/{code}.m3u` — fallback format

**Station object (catalog.json):**
- `id` — unique IPRD identifier
- `name`, `country`, `language`
- `genres[]`, `tags[]`
- `streams[]` — each with `url`, `format`, `bitrate`, `reliability` (0-1)
- `logo`, `website`, `lastChecked`, `source`

**Limitations:** Static data on GitHub Pages. Rate-limited. Station availability not real-time. Cache aggressively.

## Approach options

### Option 1: New bounded context with source abstraction (recommended)

New `Radio` bounded context at `src/Radio/` with 5 aggregates:

1. **RadioSource** — global config for a station provider (IPRD, future TuneIn/custom). Holds name, sync strategy config, format type, auth config.
2. **RadioStation** — synced station data. Source-agnostic: name, country, language, genres, streams collection, logo. References RadioSource + externalId.
3. **CountrySubscription** — per-user. Tracks subscribed countries per source + last sync timestamp.
4. **StarredStation** — per-user. References a RadioStation. Survives country unsubscribe.
5. **RadioSession** — per-user. Active station, active stream URL, state (playing/stopped).

Sync model: hybrid — summary on demand, country data fetched when user subscribes, weekly auto-refresh via Messenger + manual refresh button. `StationSyncPortInterface` in domain, `IprdStationSyncAdapter` in infrastructure.

Player integration: mutual exclusion. Starting radio pauses music session. Stopping radio resumes music. Player bar swaps UI between radio mode (station logo + name + stop) and music mode.

Stream failure: auto-fallback through station's streams by reliability score. If all fail, suggest genre-matching starred stations.

**Tradeoffs:** Most upfront work, but extensible and aligned with existing DDD architecture.

### Option 2: UI-only (rejected)

Client fetches IPRD directly, stores favorites in localStorage, adds radio mode to player.

**Rejected because:** No multi-device sync, no source abstraction, no shared cache, no admin management.

### Option 3: Full catalog pre-import (rejected)

Scheduled job imports all 23k stations at deploy time.

**Rejected because:** Massive storage for unused data, no source abstraction benefit, heavy refresh cycles.

## Recommended direction

**Option 1.** New `Radio` bounded context with full source abstraction.

Rationale:
- Multi-source requirement demands first-class source model
- Country-subscription sync is efficient (only what users need)
- DDD alignment matches existing architecture
- Stream fallback + suggestions provide resilient UX
- Clean cross-context boundary: Radio tells Session to pause/resume via port, no Doctrine FKs

## Aggregate model

```
RadioSource (global, admin-managed)
  ├── name: string
  ├── syncConfig: SyncConfig (url templates, format, auth)
  └── syncSchedule: string (cron expression)

RadioStation (global, synced from source)
  ├── sourceId: Uuid (→ RadioSource)
  ├── externalId: string (source-specific ID)
  ├── name: string
  ├── country: string (ISO code)
  ├── language: string
  ├── genres: string[]
  ├── tags: string[]
  ├── streams: Stream[] (url, format, bitrate, reliability)
  ├── logo: ?string
  └── website: ?string

CountrySubscription (per-user, per-source)
  ├── userId: Uuid
  ├── sourceId: Uuid (→ RadioSource)
  ├── countryCode: string
  └── lastSyncedAt: ?DateTime

StarredStation (per-user)
  ├── userId: Uuid
  ├── stationId: Uuid (→ RadioStation)
  └── starredAt: DateTime

RadioSession (per-user)
  ├── userId: Uuid
  ├── activeStationId: ?Uuid (→ RadioStation)
  ├── activeStreamUrl: ?string
  └── state: RadioSessionState (playing|stopped)
```

## Ports

| Port | Purpose |
|------|---------|
| `StationSyncPortInterface` | Fetch station data from an external source. Implemented per source (IPRD, future sources). |
| `RadioSourcePortInterface` | CRUD for radio source configs |
| `RadioStationPortInterface` | Station CRUD, browse, search |
| `CountrySubscriptionPortInterface` | Subscribe/unsubscribe, list subscriptions |
| `StarredStationPortInterface` | Star/unstar, list starred stations |
| `RadioSessionPortInterface` | Start/stop radio, get active session |
| `SessionControlPortInterface` | Cross-context: tell Session context to pause/resume music |

## Cross-context interactions

- **Radio → Session**: When radio starts, call `SessionControlPortInterface::pause()`. When radio stops, call `SessionControlPortInterface::resume()`. No Doctrine FKs — port-based communication only.
- **Player UI**: Checks both `RadioSession` and `ListeningSession` to determine which mode to render.

## Sync flow

1. User opens radio section → fetch `summary.json` → render country picker (228 countries with counts)
2. User subscribes to a country → `CountrySubscription` created → dispatch `SyncCountryStations` command
3. `SyncCountryStations` handler resolves `StationSyncPortInterface` for the source → fetches data → upserts `RadioStation` entities → updates `lastSyncedAt`
4. Weekly cron: iterate all `CountrySubscription` records → dispatch `SyncCountryStations` per country
5. Manual: API endpoint `POST /radio/countries/{code}/refresh` → same command
6. Refresh diffs: add new stations, update changed, soft-delete removed

## Stream fallback flow

1. User plays station → sorted streams by reliability descending
2. Try stream[0] → if fails, try stream[1], etc.
3. All streams fail → query user's starred stations matching any of the failed station's genres
4. Return suggestions in response: `"unavailable": true, "suggestions": [...]`

## UI structure

New frontend feature at `ui/web/src/features/radio/`:
- **CountryPicker** — grid/list of countries with station counts, toggle subscribe
- **StationBrowser** — searchable/filterable list of stations from subscribed countries
- **MyStations** — starred stations, quick-access grid
- **RadioPlayer** — replaces music player bar content when radio active (station logo, name, stop button, no progress/seek)

## Success criteria

1. User can subscribe to countries and see station counts
2. User can browse and search stations from subscribed countries
3. User can star/unstar stations; starred stations persist after country unsubscribe
4. User can play a radio station; player bar shows radio mode
5. Starting radio pauses music; stopping radio resumes music
6. Dead streams auto-fallback; all-fail shows genre suggestions
7. Weekly sync refreshes subscribed country data
8. Manual refresh works per-country
9. Admin can manage radio sources
