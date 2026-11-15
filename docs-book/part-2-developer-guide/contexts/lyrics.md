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
| `BulkFetchLyricsCommand` | `BulkFetchLyricsHandler` | Walk songs without lyrics and queue a fetch for each, up to an optional limit (`app:lyrics:fetch`, the admin bulk fetch and admin-created schedules); returns a `BulkFetchLyricsResult`: the number queued, and the number skipped for a missing artist and for a missing length |

`FetchLyricsCommand` has no transport route, so the on-demand fetch of one song, from the API or `app:song:lyrics:fetch`, handles it synchronously. The bulk fetch and the automatic requests below queue it on the `async` transport with a `TransportNamesStamp`, spaced by a `DelayStamp` and marked `queued`. The bulk fetch handler therefore returns once the fetches are queued, so the admin request does not wait for LRCLIB. The bulk fetch spaces its fetches by the command's delay, 3,000 ms by default. That default is also the smallest spacing a run uses: `BulkFetchLyricsHandler::spacingMs()` raises a smaller delay to it, whether it comes from the admin page, `app:lyrics:fetch` or a schedule's stored `delayMs`. Two turns under the LRCLIB pace are two seconds apart and a request takes time of its own, so fetches spaced closer would come due faster than a worker sends them. Without a limit, both the admin page and `app:lyrics:fetch` queue every song without lyrics that has a primary artist credit and a positive length. The walk skips a song that lacks either, by the rule `LyricsLookupRequirements` shares with `FetchLyricsHandler`, counts it per cause, and does not count it toward the limit. The admin route and `app:lyrics:fetch` run the bulk fetch through `JobMonitorAdministrationInterface::runInline()`, which records the run in the job monitor and returns the handler's result.

`BulkFetchLyricsHandler` and `FetchLyricsHandler` coordinate delayed bulk work through `QueuedLyricsFetchesInterface` (`RedisQueuedLyricsFetches`). Before dispatching `FetchLyricsCommand`, the bulk handler calls `markQueued()` for the song with a TTL that lasts until a day after the song's delayed fetch is due, so overlapping bulk runs skip songs already waiting in the queue; `FetchLyricsHandler` calls `clearQueued()` in a `finally` block once the fetch runs. Each bulk-queued `FetchLyricsCommand` carries its bulk run UUID. If the bulk job is cancelled while queueing and has queued at least one fetch, `BulkFetchLyricsHandler` calls `markRunCancelled()`. If a job-monitor job finishes queueing with at least one fetch queued, the handler links the job ID to the run via `recordJobRun()`, and `RedisQueuedLyricsFetches` implements `QueuedJobWorkInterface` so cancelling the finished bulk job in the job monitor also marks the run cancelled. A run that queued nothing leaves its job nothing to cancel. When `FetchLyricsHandler` sees `isRunCancelled()` for its run UUID, it skips the LRCLIB lookup and clears the song's queued mark.

Each bulk run also keeps a tally of its outcomes beside its queued marks. `startRun()` opens the tally before the walk queues its first fetch, so a fetch that runs while the walk goes on is counted. When the walk finishes, also when it queued nothing, `finishRun()` adds its queued count, its skipped counts, its job ID and its finish time, and makes the run the latest. `FetchLyricsHandler` passes the outcome of each bulk-queued fetch to `recordOutcome()`, which counts a song once per run, so a redelivered fetch is not counted twice. A song that lost its artist credit or its length after the walk queued it adds to the tally's skipped count for that cause. A fetch skipped because its run was cancelled, or whose song no longer exists, counts toward no outcome. `latestRun()` returns the tally of the run whose walk finished last as a `BulkLyricsRunTally`, which the sync status reports as `latestRun`. The tally expires with the run's other records, a day after its last fetch was due, and `latestRun()` then returns null.

Every LRCLIB request takes a turn before it is sent. `LrclibClient` reserves the turn under the `lrclib` rate limiter, a one-token bucket in Redis that refills two seconds after each turn, so all processes together send at most one request every two seconds. The client reserves the turn itself, so the pace also holds in `dev`, where the limiters a request listener enforces are skipped; the `test` environment raises the limit. Each request sends the User-Agent `Baander/1.0 (https://baander.app; contact@baander.app)`: the name, version and contact address LRCLIB asks a client to send. A fetch sends one request, to `/api/get`.

LRCLIB answers that requests come too fast with 429, or with 503 and a `Retry-After` header. The client then stores a pause through `LrclibPauseInterface`. `RedisLrclibPause` keeps it as the Redis key `lyrics:lrclib:pause`, whose expiry is the pause, so web and worker processes read the same pause and Redis alone measures it. The pause lasts the whole seconds the header names. When the header is missing or is not a whole number, it lasts 5 seconds after the call's first such answer and 15 after a later one. A pause in effect that ends later stays as it is, so a later answer never shortens it. `app:rate-limiter:clear lrclib` resets the turns and leaves a stored pause alone. The client logs the pause with the time it ends and sends the request again afterwards, three sends at most.

Each call has one deadline for all of this, its `LrclibWait`: `Interactive`, the default, gives the per-song fetch, the search and the apply 10 seconds, and `Queued`, which `FetchLyricsHandler` passes for a command marked `queued`, gives a worker's fetch 30. A pause or a turn that ends after the deadline answers `LrclibUnavailable` without a request, and so does a limiter or a pause that cannot be read in Redis. The waits sleep through `Async::sleepThroughSignals()`, so a worker's keepalive alarm does not end them early.

`LrclibClient` returns null when LRCLIB answers 404, and an empty list when a search finds nothing. It returns `LrclibRejected` when LRCLIB refuses the request with a client error other than 404, 408 or 429, and `LrclibUnavailable` when a request cannot be sent within its wait or fails, LRCLIB answers 408, a redirect or a server error, still answers that requests come too fast after three sends, or the body is unreadable. A request that gets no data for 10 seconds, or takes longer than 10 seconds in all, fails as well, so a stalled LRCLIB cannot hold a request or worker open. `FetchLyricsHandler` turns the answer into a `LyricsFetchResult` whose `LyricsFetchOutcome` is found, no match, rejected or unavailable. A song without a primary artist credit, or whose length is missing or not positive, is not sent to LRCLIB; its outcome is no artist or no length. The handler never throws for any outcome, so a queued fetch is acknowledged without a retry and its run can count the outcome. The per-song route and `app:song:lyrics:fetch` call `lyricsOrFail()`, which returns the lyrics, or null for no match, and throws for every other outcome, which exits the command with 1: `LyricsFetchNotAttemptedException` for no artist or no length (HTTP 409, `details.reason` `song_has_no_artist` or `song_has_no_length`), `LyricsRequestRejectedException` for a rejection (409, `lrclib_rejected`), and `LyricsProviderUnavailableException` for an outage (503). Search and apply throw the rejection and outage exceptions directly.

A fetch for a song that has lyrics returns them without calling LRCLIB. Apply refuses that song with a conflict (HTTP 409, `song_has_lyrics`). Both store through `LyricsRepositoryInterface::add()`, an `INSERT ... ON CONFLICT (song_id) DO NOTHING` that reports whether it stored anything, because another fetch or apply can store lyrics for the song while one waits on LRCLIB. An apply that loses that race answers the same conflict, and a fetch that loses it returns the stored lyrics. Catching the unique violation instead would close the entity manager. Several songs can store the same LRCLIB record, such as one recording on an album and a compilation, or one album in two libraries, so `lrclib_id` is not unique.

## Automatic Fetch for New and Repaired Songs

While the `lyrics.auto_fetch` system setting is on, Catalog ingest asks Lyrics to fetch the lyrics of each new song, and of each stored song whose repair, as on a rescan, or whose retag update gives it a primary artist credit or a new length. `FilesDiscoveredHandler` collects the songs it creates that have no sidecar `.lrc` file, and the repaired songs that have no sidecar lyrics. It calls `LyricsFetchRequestInterface::requestFetch()` after each flush that commits them, so a fetch never looks up a song that is not stored yet. A song with a sidecar file gets its lyrics from that file during ingest. The request is best-effort. If it throws, ingest logs the error and keeps the songs pending, and the next flush requests them again. Songs still pending after the last flush are left for the bulk fetch.

`LyricsFetchRequester` reads the setting on every call. When the setting is on, it dispatches one `FetchLyricsCommand` per song with a `TransportNamesStamp` for the `async` transport; `LyricsMessagePayloadCodec` encodes it there. Each command is marked `queued` and carries a `DelayStamp` that spaces the fetches `BulkFetchLyricsCommand::DEFAULT_DELAY_MS` (3,000 ms) apart, the bulk fetch's default and smallest spacing, so the fetches of a large scan come due no faster than a worker sends them. The requester keeps its schedule between calls in the same worker, so the fetches for the albums of one scan queue behind each other. The setting is off by default (`LyricsSettingDefinitions`).

The bulk fetch, the on-demand fetch and the LRCLIB search are not gated by the setting, because a user or administrator starts them.

## Ports

| Port | Purpose |
|------|---------|
| `LyricsPortInterface` | Find the stored lyrics of a song |
| `LyricsAdminPortInterface` | Lyrics coverage and sync status for the admin API and `app:lyrics:coverage` and `app:lyrics:status`; the sync status includes the latest bulk run's tally |
| `LrclibClientInterface` | LRCLIB HTTP API contract, implemented by `LrclibClient` (anti-corruption layer); each call takes the `LrclibWait` its request may wait before it is sent |
| `LyricsFetchRequestInterface` | Published contract for Catalog ingest: requests fetches for new and repaired songs while `lyrics.auto_fetch` is on (the `Lyrics Fetch Request Contract` Deptrac layer) |
| `QueuedLyricsFetchesInterface` | Tracks delayed song fetches, cancelled bulk runs and each run's tally, so overlapping bulk runs do not queue a song twice, cancelled bulk runs skip remaining queued `FetchLyricsCommand` messages, and the sync status can report the latest run's outcomes |

## API Endpoints

| Method | Path | Role | Purpose |
|--------|------|------|---------|
| GET | `/api/songs/{publicId}/lyrics` | Signed in | Get the stored lyrics for a song |
| POST | `/api/songs/{publicId}/lyrics/fetch` | `ROLE_ADMIN` | Fetch from LRCLIB now |
| GET | `/api/lyrics/search` | Signed in | Search LRCLIB |
| POST | `/api/lyrics/search/{resultId}/apply` | `ROLE_ADMIN` | Apply a search result to a song |
| GET | `/api/admin/lyrics/coverage` | `ROLE_ADMIN` | How many songs have lyrics |
| POST | `/api/admin/lyrics/bulk-fetch` | `ROLE_SUPER_ADMIN` | Queue a fetch for every song without lyrics, or up to the body's optional `limit`; an empty body is accepted. Returns `jobsEnqueued`, `skippedNoArtist` and `skippedNoLength` |
| GET | `/api/admin/lyrics/sync-status` | `ROLE_ADMIN` | Counts of the `FetchLyricsCommand` and `BulkFetchLyricsCommand` jobs in the job monitor, and the latest bulk run's tally as `latestRun`, or null when none is recorded |

### Console Commands

| Command | Class | Purpose |
|---------|-------|---------|
| `app:lyrics:fetch` | `LyricsFetchCommand` | Queue a lyrics fetch from LRCLIB for every song without lyrics, or up to `--limit` |
| `app:lyrics:coverage` | `LyricsCoverageCommand` | Show lyrics coverage statistics |
| `app:lyrics:status` | `LyricsStatusCommand` | Show `FetchLyricsCommand` and `BulkFetchLyricsCommand` job counts from the job monitor, and the latest bulk run's tally |
| `app:song:lyrics:fetch` | `SongLyricsFetchCommand` | Fetch the lyrics of one song from LRCLIB synchronously |
| `app:lyrics:search` | `LyricsSearchCommand` | Search LRCLIB by keywords and list the results with their IDs |
| `app:lyrics:apply` | `LyricsApplyCommand` | Store an LRCLIB search result as the lyrics of a song that has none |

## Infrastructure

| Component | Purpose |
|-----------|---------|
| `LyricsService` | Implements `LyricsPortInterface` through the repository |
| `LrclibClient` | Symfony HttpClient adapter for the LRCLIB API; reserves each request's turn under the `lrclib` rate limiter and waits out a stored pause |
| `RedisLrclibPause` | Implements `LrclibPauseInterface`: the pause LRCLIB asked for, as one Redis key that expires when the pause ends |
| `LyricsRepository`, `LyricsAdminRepository` | Doctrine persistence and admin statistics |
| `RedisQueuedLyricsFetches` | Implements `QueuedLyricsFetchesInterface` and `QueuedJobWorkInterface` using expiring Redis keys to deduplicate queued songs across bulk runs, cancel remaining queued fetches when a bulk job is cancelled, and keep each run's tally as a hash, with a `latest_run` key naming the run whose walk finished last |
| `LyricsMessagePayloadCodec` | Encodes `FetchLyricsCommand`, with its bulk run and its `queued` flag, for the `async` transport, and `BulkFetchLyricsCommand` for the job monitor's stored payload |

## Cross-Context Dependencies

| Direction | Context | Relationship |
|-----------|---------|--------------|
| Depends on | Shared | `Uuid`, `PublicId`, `LibraryReadScope`, `QueuedJobWorkInterface`, and `SystemSettingsPortInterface` for `lyrics.auto_fetch` |
| Depends on | Catalog | `SongLookupInterface`: a visible song by public ID, song-ID pages for the bulk fetch, and the LRCLIB signature of one song or of a page of songs as `SongLyricSignature` |
| Depends on | Library | `LyricsController` uses `LibraryReadScopeProviderInterface` to scope song lookups to the caller's accessible libraries |
| Depended on by | Catalog | Ingest requests fetches for new and repaired songs through `LyricsFetchRequestInterface` |
