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
| `FetchLyricsCommand` | `FetchLyricsHandler` | Fetch and store the lyrics of one song from LRCLIB; returns a `LyricsFetchResult` |
| `ApplyLyricsCommand` | `ApplyLyricsHandler` | Store an LRCLIB search result as the lyrics of a song that has none (the apply route and `app:lyrics:apply`) |
| `SearchLyricsQuery` | `SearchLyricsHandler` | Search LRCLIB by keywords (the search route and `app:lyrics:search`) |
| `BulkFetchLyricsCommand` | `BulkFetchLyricsHandler` | Walk songs without lyrics and queue a fetch for each, up to an optional limit (`app:lyrics:fetch`, the admin bulk fetch and admin-created schedules); returns the number queued |

`FetchLyricsCommand` has no transport route, so the on-demand fetch of one song, from the API or `app:song:lyrics:fetch`, handles it synchronously. The bulk fetch and the automatic requests below queue it on the `async` transport with a `TransportNamesStamp`, spaced by a `DelayStamp`; the bulk fetch spaces its fetches by the command's delay, 500 ms by default. The bulk fetch handler therefore returns once the fetches are queued, so the admin request does not wait for LRCLIB. Without a limit, both the admin page and `app:lyrics:fetch` queue every song without lyrics. `app:lyrics:fetch` runs the bulk fetch through `JobMonitorAdministrationInterface::runInline()`, which records the run in the job monitor.

`LrclibClient` returns null or an empty list when LRCLIB has nothing, and `LrclibUnavailable` when a request fails, LRCLIB answers with another error, or the body is unreadable. A request that gets no data for 10 seconds, or takes longer than 10 seconds in all, fails as well, so a stalled LRCLIB cannot hold a request or worker open. `FetchLyricsHandler` turns that into a `LyricsFetchResult`: found, not found, or LRCLIB unavailable. It never throws for an outage, so a queued fetch is acknowledged without a retry. The per-song route and `app:song:lyrics:fetch` call `lyricsOrFail()`, which throws `LyricsProviderUnavailableException` (HTTP 503, exit 1); search and apply throw it directly.

A fetch for a song that has lyrics returns them without calling LRCLIB. Apply refuses that song with a conflict (HTTP 409). Both store through `LyricsRepositoryInterface::add()`, an `INSERT ... ON CONFLICT (song_id) DO NOTHING` that reports whether it stored anything, because another fetch or apply can store lyrics for the song while one waits on LRCLIB. An apply that loses that race answers the same conflict, and a fetch that loses it returns the stored lyrics. Catching the unique violation instead would close the entity manager. Several songs can store the same LRCLIB record, such as one recording on an album and a compilation, or one album in two libraries, so `lrclib_id` is not unique.

## Automatic Fetch for New Songs

While the `lyrics.auto_fetch` system setting is on, Catalog ingest asks Lyrics to fetch the lyrics of each new song. `FilesDiscoveredHandler` collects the songs it creates that have no sidecar `.lrc` file, and calls `LyricsFetchRequestInterface::requestFetch()` after each flush that commits them, so a fetch never looks up a song that is not stored yet. A song with a sidecar file gets its lyrics from that file during ingest. The request is best-effort. If it throws, ingest logs the error and keeps the songs pending, and the next flush requests them again. Songs still pending after the last flush are left for the bulk fetch.

`LyricsFetchRequester` reads the setting on every call. When the setting is on, it dispatches one `FetchLyricsCommand` per song with a `TransportNamesStamp` for the `async` transport; `LyricsMessagePayloadCodec` encodes it there. Each command also carries a `DelayStamp` that spaces the fetches `BulkFetchLyricsCommand::DEFAULT_DELAY_MS` (500 ms) apart, the bulk fetch's default pace, so LRCLIB does not throttle a large scan. The requester keeps its schedule between calls in the same worker, so the fetches for the albums of one scan queue behind each other. The setting is off by default (`LyricsSettingDefinitions`).

The bulk fetch, the on-demand fetch and the LRCLIB search are not gated by the setting, because a user or administrator starts them.

## Ports

| Port | Purpose |
|------|---------|
| `LyricsPortInterface` | Find the stored lyrics of a song |
| `LyricsAdminPortInterface` | Lyrics coverage and sync status for the admin API and `app:lyrics:coverage` and `app:lyrics:status` |
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
| POST | `/api/admin/lyrics/bulk-fetch` | `ROLE_SUPER_ADMIN` | Queue a fetch for every song without lyrics, or up to the body's optional `limit`; an empty body is accepted |
| GET | `/api/admin/lyrics/sync-status` | `ROLE_ADMIN` | Counts of the `FetchLyricsCommand` and `BulkFetchLyricsCommand` jobs in the job monitor |

## Infrastructure

| Component | Purpose |
|-----------|---------|
| `LyricsService` | Implements `LyricsPortInterface` through the repository |
| `LrclibClient` | Symfony HttpClient adapter for the LRCLIB API |
| `LyricsRepository`, `LyricsAdminRepository` | Doctrine persistence and admin statistics |
| `LyricsMessagePayloadCodec` | Encodes `FetchLyricsCommand` for the `async` transport, and `BulkFetchLyricsCommand` for the job monitor's stored payload |

## Cross-Context Dependencies

| Direction | Context | Relationship |
|-----------|---------|--------------|
| Depends on | Shared | `Uuid`, and `SystemSettingsPortInterface` for `lyrics.auto_fetch` |
| Depends on | Catalog | `SongLookupInterface`: a visible song by public ID, song-ID pages for the bulk fetch, and the LRCLIB signature as `SongLyricSignature` |
| Depended on by | Catalog | Ingest requests fetches for new songs through `LyricsFetchRequestInterface` |
