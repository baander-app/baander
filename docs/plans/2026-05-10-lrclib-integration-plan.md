# Plan: LRCLIB Integration

## Problem summary

The `Lyrics` bounded context (`src/Lyrics/`) is a stub — `LyricsService::getLyrics()` returns `null`. Users need lyrics during playback: both plain text and synced/timed lyrics (LRC format `[mm:ss.xx]`) for karaoke-style display. Integrate the [LRCLIB API](https://lrclib.net/docs) as the lyrics source behind a proper DDD four-layer architecture.

Requirements: `docs/brainstorms/lrclib-integration.md`

## Relevant learnings

No existing solutions in `docs/solutions/`.

## Scope boundaries

**In scope:**
- **Prerequisite:** Fix `MusicScanner` to populate `artist_song` join table from extracted metadata; add `getArtistNameForSong()` to Catalog port
- Enhance `Lyrics` aggregate with `syncedLyrics`, `lrclibId`; migrate to State object pattern
- New Application layer: ports, commands, handlers, DTOs
- New Infrastructure layer: `LrclibClient` (Symfony HttpClient), Doctrine entity + repository
- New Interface layer: REST controller, console command, request/resource classes
- New migration: `lyrics` table
- Fetch strategy: `/api/get-cached` first → fallback `/api/get` → search as manual fallback
- On-demand fetch + bulk scan command

**Out of scope:**
- Publishing lyrics back to LRCLIB
- Other lyrics sources (Musixmatch, Genius) — architecture supports them but no implementation
- Frontend changes
- Cached repository decorator

## Implementation units

### Unit 0: Prerequisite — Scanner artist linking + Catalog port method

**Goal:** Fix the `MusicScanner` to populate the `artist_song` join table from `ExtractedMetadata::getArtist()`. Add `getArtistNameForSong(Uuid $songId): ?string` to `SongPortInterface` with a DQL implementation. This resolves the gap: LRCLIB needs artist name, album name, and track duration for signature lookup.

**Problem:** The scanner extracts `artist` from file tags (`ExtractedMetadata` has `getArtist()`) but never creates `ArtistSongEntity` records. The `artist_song` join table has 0 rows. The `Artist` model exists with `getName()` but nothing links songs to artists.

**Files:**
- Modify: `src/Library/Application/MusicScanner.php` — in `processFile()`, after creating the song, look up or create the artist via `ArtistPortInterface`, then persist an `ArtistSongEntity` with role `primary`
- Modify: `src/Catalog/Application/Port/SongPortInterface.php` — add `getArtistNameForSong(Uuid $songId): ?string`
- Modify: `src/Catalog/Infrastructure/SongService.php` — implement `getArtistNameForSong()`
- Modify: `src/Catalog/Infrastructure/Doctrine/Repository/SongRepository.php` — add DQL query joining `ArtistSongEntity → ArtistEntity → SongEntity` filtering by `role = 'primary'`
- Create: `tests/Unit/Library/Application/MusicScannerTest.php` — update existing or add test for artist linking
- Create: `tests/Functional/Catalog/Infrastructure/Doctrine/Repository/SongRepositoryArtistTest.php` — test `getArtistNameForSong()`

**Patterns to follow:**
- `ArtistPortInterface::findOrCreateByName()` — already exists, scanner should use it
- `ArtistSongEntity` constructor takes `(ArtistEntity, SongEntity, ?role)`
- `ArtistRole::Primary` for the scanner's linking

**`MusicScanner::processFile()` changes:**
```php
// After $this->songService->persist($song):
$artistName = $metadata->getArtist();
if ($artistName !== null && trim($artistName) !== '') {
    $artist = $this->artistService->findOrCreateByName($artistName);
    // Create ArtistSongEntity linking with role='primary'
}
```

**`getArtistNameForSong()` DQL:**
```dql
SELECT a.name
FROM App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity ass
JOIN ass.artist a
JOIN ass.song s
WHERE s.id = :songId AND ass.role = 'primary'
```

Note: `ArtistSongEntity` is an infrastructure-only Doctrine entity. The DQL query lives in `SongRepository`. The port method returns `?string`.

**Test scenarios:**
- Scanner processes file with artist metadata → `artist_song` row created with `role='primary'`
- Scanner processes file without artist metadata → no `artist_song` row
- Scanner processes file with existing artist name → reuses existing artist
- `getArtistNameForSong()` with song that has primary artist → returns artist name
- `getArtistNameForSong()` with song that has no artist → returns null
- `getArtistNameForSong()` with song that has only featured artist (no primary) → returns null

**Verification:**
```bash
make phpunit filter=MusicScannerTest
make phpunit filter=SongRepositoryArtistTest
```

**Dependencies:** None — independent of the Lyrics context. Can be done first or in parallel with Unit 1.

---

### Unit 1: Domain model — Lyrics state object migration + new fields

**Goal:** Migrate `Lyrics` aggregate from positional-argument constructor to State object pattern. Add `syncedLyrics` and `lrclibId` fields.

**Files:**
- Modify: `src/Lyrics/Domain/Model/Lyrics.php`
- Create: `src/Lyrics/Domain/Model/LyricsState.php`
- Modify: `src/Lyrics/Domain/Repository/LyricsRepositoryInterface.php` — add `findByLrclibId(int $id): ?Lyrics`
- Create: `tests/Unit/Lyrics/Domain/Model/LyricsTest.php`

**Patterns to follow:**
- State object pattern: `src/Catalog/Domain/Model/Song.php` + `SongState.php`
- Repository interface: `src/Catalog/Domain/Repository/SongRepositoryInterface.php`

**Test scenarios:**
- Create lyrics with plain only → assert stored correctly
- Create lyrics with both plain + synced → assert both stored
- Create instrumental lyrics (empty lyrics + `isInstrumental=true`) → valid
- Create non-instrumental with empty lyrics → `InvalidArgumentException`
- Invalid source → `InvalidArgumentException`
- `updateLyrics()` updates both plain and synced
- `updateSource()` updates source, sourceUrl, isInstrumental
- `isEmpty()` returns true for whitespace-only lyrics
- `findByLrclibId` contract on repository interface

**Verification:**
```bash
make phpunit filter=LyricsTest
```

**Dependencies:** None — this is the foundation.

---

### Unit 2: LRCLIB client — API port + infrastructure implementation

**Goal:** Create the anti-corruption layer for the LRCLIB API behind a port interface. Implements cached-first → full-fallback strategy.

**Files:**
- Create: `src/Lyrics/Application/Port/LrclibClientInterface.php`
- Create: `src/Lyrics/Application/DTO/LrclibResult.php`
- Create: `src/Lyrics/Application/DTO/LrclibSearchResult.php`
- Create: `src/Lyrics/Infrastructure/Api/LrclibClient.php`
- Create: `tests/Unit/Lyrics/Infrastructure/Api/LrclibClientTest.php`

**Patterns to follow:**
- External API adapter pattern: `src/Metadata/Infrastructure/Api/Discogs/DiscogsAdapter.php`
- DTO pattern: `src/Metadata/Infrastructure/Api/Discogs/DTO/DiscogsSearchResultDto.php`
- Port interface: `src/Auth/Application/Port/TotpVerifierInterface.php`

**`LrclibClientInterface` methods:**
```php
public function getBySignatureCached(string $trackName, string $artistName, string $albumName, float $duration): ?LrclibResult;
public function getBySignature(string $trackName, string $artistName, string $albumName, float $duration): ?LrclibResult;
public function getById(int $id): ?LrclibResult;
public function search(string $query): array;
```

**`LrclibClient` implementation details:**
- Uses Symfony `HttpClientInterface`
- Configurable base URL via `LRCLIB_BASE_URL` env var (default: `https://lrclib.net`)
- `User-Agent: Baander` header
- `getBySignature()` tries `/api/get-cached` first, falls back to `/api/get` on 404
- `search()` calls `/api/search?q=...`, returns max 20 `LrclibSearchResult`
- All methods return `null` or empty array on 404, log errors on other HTTP failures

**Test scenarios:**
- Cached endpoint returns result → return it directly (no fallback call)
- Cached endpoint 404 → fallback to full `/api/get` → returns result
- Both endpoints 404 → return null
- Cached 404 + full returns result → return result
- Search returns array of results → map to DTOs
- Search with no results → return empty array
- HTTP error (500) → log and return null
- Network timeout → log and return null
- `getById()` with valid ID → return result
- `getById()` with 404 → return null
- Verify correct query parameters are sent for each endpoint

**Verification:**
```bash
make phpunit filter=LrclibClientTest
```

**Dependencies:** Unit 0 (needs `getArtistNameForSong()` for LRCLIB signature lookup), Unit 1 (DTO types reference the domain model concepts)

---

### Unit 3: Doctrine persistence — entity + repository + migration

**Goal:** Create the persistence layer for the `lyrics` table.

**Files:**
- Create: `src/Lyrics/Infrastructure/Doctrine/Entity/LyricsEntity.php`
- Create: `src/Lyrics/Infrastructure/Doctrine/Repository/LyricsRepository.php`
- Create: `migrations/Version*CreateLyricsTable.php` (auto-generated via `make doctrine-migration`)
- Create: `tests/Functional/Lyrics/Infrastructure/Doctrine/Repository/LyricsRepositoryTest.php`

**Patterns to follow:**
- Doctrine entity: `src/Catalog/Infrastructure/Doctrine/Entity/SongEntity.php`
- Doctrine repository: `src/Catalog/Infrastructure/Doctrine/Repository/SongRepository.php`

**`lyrics` table schema:**
| Column | Type | Notes |
|---|---|---|
| `id` | UUID (PK) | UUID v7 |
| `song_id` | UUID (FK → songs.id) | Unique |
| `plain_lyrics` | TEXT NULL | |
| `synced_lyrics` | TEXT NULL | LRC format |
| `source` | TEXT NOT NULL | `'embedded'`, `'lrclib'`, etc. |
| `source_url` | TEXT NULL | |
| `lrclib_id` | INT NULL | Unique |
| `is_instrumental` | BOOLEAN NOT NULL DEFAULT false | |
| `created_at` | TIMESTAMPTZ NOT NULL | |
| `updated_at` | TIMESTAMPTZ NOT NULL | |

**Test scenarios:**
- Save new lyrics → find by song ID → assert all fields persisted
- Save lyrics with lrclibId → find by lrclibId → assert match
- Update lyrics → find again → assert updated
- Delete lyrics → find → assert null
- Save two lyrics for different songs → both retrievable

**Verification:**
```bash
make phpunit filter=LyricsRepositoryTest
```

**Dependencies:** Unit 1 (model + repository interface)

---

### Unit 4: Application layer — commands, handlers, lyrics port

**Goal:** Create the application layer with port, commands, and handlers that orchestrate lyrics fetching.

**Files:**
- Create: `src/Lyrics/Application/Port/LyricsPortInterface.php`
- Create: `src/Lyrics/Application/Command/FetchLyricsCommand.php`
- Create: `src/Lyrics/Application/Command/BulkFetchLyricsCommand.php`
- Create: `src/Lyrics/Application/CommandHandler/FetchLyricsHandler.php`
- Create: `src/Lyrics/Application/CommandHandler/BulkFetchLyricsHandler.php`
- Modify: `src/Lyrics/Infrastructure/Service/LyricsService.php` — refactor into port-based
- Create: `tests/Unit/Lyrics/Application/CommandHandler/FetchLyricsHandlerTest.php`

**Patterns to follow:**
- Command pattern: `src/Catalog/Application/Command/BatchExtractCoversCommand.php` + handler
- Port interface: `src/Catalog/Application/Port/SongPortInterface.php`

**`FetchLyricsHandler` logic:**
1. Find song by UUID via `SongPortInterface`
2. Check if lyrics already exist via `LyricsRepositoryInterface::findBySongId()` — skip if present
3. Resolve artist name via `SongPortInterface::getArtistNameForSong(songId)` (Unit 0)
4. Resolve album name via `AlbumPortInterface::findByUuid(song.getAlbumId()).getTitle()`
5. Call `LrclibClientInterface::getBySignatureCached()` with track name, artist name, album name, duration
6. If null, call `LrclibClientInterface::getBySignature()`
7. If found, create `Lyrics` aggregate and save
8. If null, log "no lyrics found" — no error

**`BulkFetchLyricsHandler` logic:**
1. Query all songs without lyrics (need new `SongPortInterface` method or DQL)
2. Iterate, dispatch `FetchLyricsCommand` for each
3. Apply configurable delay between fetches (respectful crawling)

**`LyricsPortInterface` methods:**
```php
public function findBySongId(Uuid $songId): ?Lyrics;
public function fetchAndStore(Uuid $songId): ?Lyrics;
public function searchLrclib(string $query): array;
public function applySearchResult(int $lrclibResultId, Uuid $songId): ?Lyrics;
```

**Test scenarios:**
- FetchLyrics: song has no lyrics → fetches from LRCLIB → stores → returns lyrics
- FetchLyrics: song already has lyrics → skips fetch → returns existing
- FetchLyrics: LRCLIB returns null → returns null, no error
- FetchLyrics: song has no duration → skips signature lookup, returns null (search is separate)
- FetchLyrics: song has no artist (getArtistNameForSong returns null) → skips signature lookup, returns null
- FetchLyrics: song not found → throws or returns null
- BulkFetchLyrics: iterates songs without lyrics, dispatches individual commands
- applySearchResult: fetches by LRCLIB ID → stores for song → returns lyrics

**Verification:**
```bash
make phpunit filter=FetchLyricsHandlerTest
```

**Dependencies:** Units 0, 1, 2, 3

---

### Unit 5: Service wiring — Symfony DI configuration

**Goal:** Wire all new services, port aliases, and messenger configuration.

**Files:**
- Create: `src/Lyrics/config/services.yaml` (or modify existing services.yaml)
- Modify: `config/services.yaml` or messenger config if needed
- Add: `LRCLIB_BASE_URL` to `.env`

**Wire:**
- `LrclibClientInterface` → `LrclibClient`
- `LyricsRepositoryInterface` → `LyricsRepository`
- `LyricsPortInterface` → `LyricsService`
- Command handlers registered as Messenger handlers
- `LRCLIB_BASE_URL` env var

**Verification:**
```bash
make cache-clear && console debug:container Lyrics
```

**Dependencies:** Units 2, 3, 4

---

### Unit 6: Interface layer — REST controller + resources

**Goal:** Create the HTTP API for lyrics.

**Files:**
- Create: `src/Lyrics/Interface/Controller/LyricsController.php`
- Create: `src/Lyrics/Interface/Request/SearchLyricsRequest.php`
- Create: `src/Lyrics/Interface/Resource/LyricsResource.php`
- Create: `src/Lyrics/Interface/Resource/LrclibSearchResource.php`
- Create: `tests/Functional/Lyrics/Interface/Controller/LyricsControllerTest.php`

**Patterns to follow:**
- Controller + port injection: `src/Catalog/Interface/Controller/SongController.php`
- Request DTO: `src/Catalog/Interface/Request/UpdateSongRequest.php`
- Resource: `src/Catalog/Interface/Resource/SongResource.php`
- OpenAPI attributes per `.claude/rules/ddd-ports.md`

**Endpoints:**
| Method | Path | Description |
|---|---|---|
| GET | `/api/songs/{publicId}/lyrics` | Get cached lyrics for a song |
| POST | `/api/songs/{publicId}/lyrics/fetch` | Trigger on-demand LRCLIB fetch |
| GET | `/api/lyrics/search?q=...` | Search LRCLIB |
| POST | `/api/lyrics/search/{resultId}/apply` | Apply search result to a song (body: `{songPublicId}`) |

**`LyricsResource` output:**
```json
{
  "plainLyrics": "...",
  "syncedLyrics": "[00:17.12] I feel your breath...",
  "source": "lrclib",
  "isInstrumental": false
}
```

**Test scenarios:**
- GET lyrics for song with cached lyrics → 200 with resource
- GET lyrics for song without lyrics → 200 with empty/null response
- POST fetch for song → triggers fetch → 200 with lyrics resource
- POST fetch for song already having lyrics → returns existing (no re-fetch)
- GET search with valid query → 200 with search results
- GET search without query → 400 validation error
- POST apply with valid resultId + songId → stores lyrics → 200
- POST apply with invalid resultId → 404

**Verification:**
```bash
make phpunit filter=LyricsControllerTest
```

**Dependencies:** Units 4, 5

---

### Unit 7: Console command — bulk fetch

**Goal:** Create a CLI command for bulk lyrics fetching.

**Files:**
- Create: `src/Lyrics/Interface/Console/FetchLyricsCommand.php`

**Patterns to follow:**
- Console command: `src/Catalog/Interface/Console/ExtractAlbumCoversCommand.php`

**Command:** `baander:lyrics:fetch`
- Options: `--limit` (max songs to process), `--delay` (ms between fetches, default 500)
- Finds songs without lyrics, fetches from LRCLIB, reports progress

**Verification:**
```bash
console baander:lyrics:fetch --limit=5 --dry-run
```

**Dependencies:** Units 4, 5

---

### Unit 8: DDD lint + documentation sync

**Goal:** Run project quality tools and update context documentation.

**Files:**
- Update: `src/Lyrics/README.md` (via documentation-maintainer)
- Update: `dev-docs/context-map.md` (via documentation-maintainer)

**Verification:**
```bash
# DDD lint
php artisan ddd:lint src/Lyrics/

# Documentation sync
# Trigger documentation-maintainer skill
```

**Dependencies:** All previous units complete

**Note:** Unit 0 (scanner + port method) and Unit 1 (domain model) are independent and can be parallelized. Units 2-3 depend on Unit 1. Unit 4 depends on Units 0-3. Units 5-7 depend on Unit 4.

## Verification strategy

### Unit-level (per unit above)
Each unit has its own PHPUnit test target.

### Integration-level
```bash
make phpunit filter=Lyrics
```

### Manual smoke test
```bash
# On-demand fetch
curl -X POST http://localhost:8080/api/songs/{publicId}/lyrics/fetch

# Get cached lyrics
curl http://localhost:8080/api/songs/{publicId}/lyrics

# Search
curl "http://localhost:8080/api/lyrics/search?q=still+alive+portal"

# Bulk scan
console baander:lyrics:fetch --limit=10
```

### PHPStan
```bash
make phpstan
```
