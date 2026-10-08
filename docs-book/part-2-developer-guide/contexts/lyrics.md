# Lyrics

The Lyrics context stores song lyrics and fetches them from [LRCLIB](https://lrclib.net/docs), both plain and synced (LRC format). It has all four layers. Songs belong to Catalog; Lyrics keys its records by song ID and reaches song data only through Catalog's `SongLookupInterface` contract.

## Domain Models

### Aggregate Roots

| Model | Key Properties | Purpose |
|-------|---------------|---------|
| `Lyrics` | song ID, plain lyrics, synced lyrics, source, LRCLIB ID | Stores lyrics for a song with provenance information |

`Lyrics` uses the state-object pattern (`LyricsState`). The `source` property tracks where the lyrics came from, for example LRCLIB.

## Commands and Handlers

| Command | Handler | Purpose |
|---------|---------|---------|
| `FetchLyricsCommand` | `FetchLyricsHandler` | Fetch and store the lyrics of one song from LRCLIB |
| `BulkFetchLyricsCommand` | `BulkFetchLyricsHandler` | Walk songs without lyrics and dispatch a fetch for each (`baander:lyrics:fetch`, the admin bulk fetch and admin-created schedules) |

`FetchLyricsCommand` has no transport route, so the bulk fetch handles it synchronously. Only the automatic requests below go to the `async` transport.

## Automatic Fetch for New Songs

While the `lyrics.auto_fetch` system setting is on, Catalog ingest asks Lyrics to fetch the lyrics of each new song. `FilesDiscoveredHandler` collects the songs it creates that have no sidecar `.lrc` file, and calls `LyricsFetchRequestInterface::requestFetch()` after each flush that commits them, so a fetch never looks up a song that is not stored yet. A song with a sidecar file gets its lyrics from that file during ingest. The request is best-effort. If it throws, ingest logs the error and keeps the songs pending, and the next flush requests them again. Songs still pending after the last flush are left for the bulk fetch.

`LyricsFetchRequester` reads the setting on every call. When the setting is on, it dispatches one `FetchLyricsCommand` per song with a `TransportNamesStamp` for the `async` transport; `LyricsMessagePayloadCodec` encodes it there. Each command also carries a `DelayStamp` that spaces the fetches `BulkFetchLyricsCommand::DEFAULT_DELAY_MS` (500 ms) apart, the same pace as the bulk fetch, so LRCLIB does not throttle a large scan. The requester keeps its schedule between calls in the same worker, so the fetches for the albums of one scan queue behind each other. The setting is off by default (`LyricsSettingDefinitions`).

The bulk fetch, the on-demand fetch and the LRCLIB search are not gated by the setting, because a user or administrator starts them.

## Ports

| Port | Purpose |
|------|---------|
| `LyricsPortInterface` | Find, fetch and store, search LRCLIB, and apply a search result |
| `LyricsAdminPortInterface` | Lyrics coverage, bulk fetch trigger and sync status for the admin API |
| `LrclibClientInterface` | LRCLIB HTTP API contract, implemented by `LrclibClient` (anti-corruption layer) |
| `LyricsFetchRequestInterface` | Published contract for Catalog ingest: requests fetches for new songs while `lyrics.auto_fetch` is on (the `Lyrics Fetch Request Contract` Deptrac layer) |

## API Endpoints

| Method | Path | Role | Purpose |
|--------|------|------|---------|
| GET | `/api/songs/{publicId}/lyrics` | Signed in | Get the stored lyrics for a song |
| POST | `/api/songs/{publicId}/lyrics/fetch` | `ROLE_ADMIN` | Fetch from LRCLIB now |
| GET | `/api/lyrics/search` | Signed in | Search LRCLIB |
| POST | `/api/lyrics/search/{resultId}/apply` | `ROLE_ADMIN` | Apply a search result to a song |
| GET | `/api/admin/lyrics/coverage` | `ROLE_ADMIN` | How many songs have lyrics |
| POST | `/api/admin/lyrics/bulk-fetch` | `ROLE_SUPER_ADMIN` | Start a bulk fetch |
| GET | `/api/admin/lyrics/sync-status` | `ROLE_ADMIN` | Bulk fetch status |

## Infrastructure

| Component | Purpose |
|-----------|---------|
| `LyricsService` | Implements `LyricsPortInterface`: LRCLIB lookups by song signature and search, and persistence through the repository |
| `LrclibClient` | Symfony HttpClient adapter for the LRCLIB API |
| `LyricsRepository`, `LyricsAdminRepository` | Doctrine persistence and admin statistics |
| `LyricsMessagePayloadCodec` | Encodes `FetchLyricsCommand` for the `async` transport |

## Cross-Context Dependencies

| Direction | Context | Relationship |
|-----------|---------|--------------|
| Depends on | Shared | `Uuid`, and `SystemSettingsPortInterface` for `lyrics.auto_fetch` |
| Depends on | Catalog | `SongLookupInterface`: a visible song by public ID, song-ID pages for the bulk fetch, and the LRCLIB signature as `SongLyricSignature` |
| Depended on by | Catalog | Ingest requests fetches for new songs through `LyricsFetchRequestInterface` |
