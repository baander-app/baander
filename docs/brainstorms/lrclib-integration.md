# LRCLIB Integration

**Date:** 2026-05-10
**Status:** Draft
**Context:** Lyrics bounded context (`src/Lyrics/`)

## Problem

The `Lyrics` bounded context is a stub. `LyricsService::getLyrics()` returns `null`. Users need lyrics display during playback — both plain text and synced/timed lyrics (LRC format with `[mm:ss.xx]` timestamps) for karaoke-style display.

## Decision

Integrate the [LRCLIB API](https://lrclib.net/docs) as the lyrics source. No API key required, no rate limiting.

## Scope

| Capability | Included |
|---|---|
| Plain lyrics fetch & storage | ✅ |
| Synced/timed lyrics (LRC) fetch & storage | ✅ |
| On-demand fetch (user plays song) | ✅ |
| Bulk scan (library enrichment command) | ✅ |
| Cached-first, fallback to full `/api/get` | ✅ |
| Search fallback when auto-match fails | ✅ |
| Publish lyrics back to LRCLIB | ❌ |

## User-Facing Behavior

1. **On-demand fetch** — When a song plays and has no cached lyrics, fetch from LRCLIB. Try `/api/get-cached` first (fast, internal DB only). If 404, try `/api/get` (slower, queries external sources). Store result locally.
2. **Bulk scan** — Console command iterates songs without lyrics and fetches them. Runs via Messenger (async).
3. **Search fallback** — When auto-match by track signature fails, expose LRCLIB `/api/search` so users can manually find and select correct lyrics.
4. **Display** — API returns both `plainLyrics` and `syncedLyrics`.

## Architecture

Full DDD four-layer structure, consistent with Catalog/Metadata contexts.

### Domain Layer (modify)

- **`Lyrics` aggregate** — Add `syncedLyrics` (nullable TEXT), `lrclibId` (nullable int, LRCLIB record ID for deduplication). Migrate to State object pattern.
- **`LyricsState`** — New state class.
- **`LyricsRepositoryInterface`** — Add `findByLrclibId(int $id): ?Lyrics` for deduplication.

### Application Layer (new)

- **`Port\LrclibClientInterface`** — Port for LRCLIB HTTP operations:
  - `getBySignatureCached(trackName, artistName, albumName, duration): ?LrclibResult`
  - `getBySignature(trackName, artistName, albumName, duration): ?LrclibResult`
  - `getById(int $id): ?LrclibResult`
  - `search(string $query): array<LrclibSearchResult>`
- **`Port\LyricsPortInterface`** — Port for lyrics CRUD used by Interface layer.
- **`Command\FetchLyricsCommand`** — On-demand fetch for a single song.
- **`Command\BulkFetchLyricsCommand`** — Bulk scan trigger.
- **`CommandHandler\FetchLyricsHandler`** — Orchestrates: check local DB → call LRCLIB → persist.
- **`CommandHandler\BulkFetchLyricsHandler`** — Iterates songs, dispatches individual fetch commands.
- **`DTO\LrclibResult`** — Value object: `{id, trackName, artistName, albumName, duration, instrumental, plainLyrics, syncedLyrics}`.
- **`DTO\LrclibSearchResult`** — Lightweight search result.

### Infrastructure Layer (new)

- **`Api\LrclibClient`** — Implements `LrclibClientInterface`. Uses Symfony HttpClient. Sends `User-Agent: Baander` header. Configurable base URL via `LRCLIB_BASE_URL` env var.
- **`Doctrine\Entity\LyricsEntity`** — Doctrine ORM entity.
- **`Doctrine\Repository\LyricsRepository`** — Implements `LyricsRepositoryInterface`.
- **`Service\LyricsService`** — Refactored to work with ports instead of being a standalone service.

### Interface Layer (new)

- **`Controller\LyricsController`** — REST endpoints:
  - `GET /api/songs/{id}/lyrics` — Get cached lyrics for a song
  - `POST /api/songs/{id}/lyrics/fetch` — Trigger on-demand LRCLIB fetch
  - `GET /api/lyrics/search?q=...` — Search LRCLIB
  - `POST /api/lyrics/search/{resultId}/apply?songId=...` — Apply search result to a song
- **`Console\FetchLyricsCommand`** — `baander:lyrics:fetch` bulk scan command with `--limit`, `--delay` options.
- **`Request\SearchLyricsRequest`** — Validates search query param.
- **`Resource\LyricsResource`** — JSON response: `{plainLyrics, syncedLyrics, source, isInstrumental}`.
- **`Resource\LrclibSearchResource`** — Search result list item.

### Database

New `lyrics` table:

| Column | Type | Notes |
|---|---|---|
| `id` | UUID (PK) | UUID v7 |
| `song_id` | UUID (FK → songs) | Unique constraint |
| `plain_lyrics` | TEXT NULL | |
| `synced_lyrics` | TEXT NULL | LRC format |
| `source` | TEXT | `'embedded'`, `'lrclib'`, `'musixmatch'`, `'genius'` |
| `source_url` | TEXT NULL | |
| `lrclib_id` | INT NULL | LRCLIB record ID, unique constraint |
| `is_instrumental` | BOOLEAN | |
| `created_at` | TIMESTAMPTZ | |
| `updated_at` | TIMESTAMPTZ | |

### Cross-Context Dependencies

- Lyrics → Catalog: needs song metadata (title, duration), album name, artist name. Uses `Catalog\Application\Port\SongPortInterface` (already exists).
- Album → Artist resolution needed to get artist name for LRCLIB signature lookup.

## Edge Cases

| Case | Handling |
|---|---|
| LRCLIB returns 404 | Store nothing, return empty response to client |
| Instrumental track (`instrumental: true`) | Store with `isInstrumental=true`, no lyrics content |
| Song has no duration (`length` is null) | Cannot use signature lookup — fall back to search |
| Network/HTTP errors | Log error, return empty on-demand; skip and continue in bulk scan |
| Duplicate fetch for same song | Check `findBySongId()` first, skip if exists |
| Same LRCLIB record for different songs | `lrclibId` unique constraint prevents duplicate external records |

## LRCLIB API Details

| Endpoint | Purpose | Latency |
|---|---|---|
| `GET /api/get-cached` | Signature lookup, internal DB only | Fast, predictable |
| `GET /api/get` | Signature lookup, queries external sources | Variable |
| `GET /api/search` | Keyword search, max 20 results | Fast |
| `GET /api/get/{id}` | Fetch by LRCLIB ID | Fast |

Strategy: cached first → full if 404 → search fallback if user triggers it.

## Files Changed

### Modified
- `src/Lyrics/Domain/Model/Lyrics.php` — Add syncedLyrics, lrclibId, migrate to state object
- `src/Lyrics/Domain/Repository/LyricsRepositoryInterface.php` — Add `findByLrclibId()`
- `src/Lyrics/Infrastructure/Service/LyricsService.php` — Refactor to port-based

### New (~20 files)
- `src/Lyrics/Domain/Model/LyricsState.php`
- `src/Lyrics/Application/Port/LrclibClientInterface.php`
- `src/Lyrics/Application/Port/LyricsPortInterface.php`
- `src/Lyrics/Application/Command/FetchLyricsCommand.php`
- `src/Lyrics/Application/Command/BulkFetchLyricsCommand.php`
- `src/Lyrics/Application/CommandHandler/FetchLyricsHandler.php`
- `src/Lyrics/Application/CommandHandler/BulkFetchLyricsHandler.php`
- `src/Lyrics/Application/DTO/LrclibResult.php`
- `src/Lyrics/Application/DTO/LrclibSearchResult.php`
- `src/Lyrics/Infrastructure/Api/LrclibClient.php`
- `src/Lyrics/Infrastructure/Doctrine/Entity/LyricsEntity.php`
- `src/Lyrics/Infrastructure/Doctrine/Repository/LyricsRepository.php`
- `src/Lyrics/Interface/Controller/LyricsController.php`
- `src/Lyrics/Interface/Console/FetchLyricsCommand.php`
- `src/Lyrics/Interface/Request/SearchLyricsRequest.php`
- `src/Lyrics/Interface/Resource/LyricsResource.php`
- `src/Lyrics/Interface/Resource/LrclibSearchResource.php`
- New Doctrine migration for `lyrics` table

## Success Criteria

- [ ] On-demand fetch: play a song → lyrics appear (plain + synced)
- [ ] Bulk scan: run command → fetchable lyrics populated
- [ ] Search fallback: manual search → apply result to a song
- [ ] Synced lyrics stored in LRC format, returned as-is to frontend
- [ ] LRCLIB behind port interface — no external coupling in domain/application layers
- [ ] Lyrics context has full 4-layer DDD structure with state object pattern
