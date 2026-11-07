# Activity

The Activity context tracks user listening and viewing history — what users play, when, and what they love — and computes play analytics. It provides the data for recently played items, loved items, and administrator activity reports.

## Domain Models

| Model | Kind | Purpose |
|-------|------|---------|
| `MediaActivity` | Aggregate root | Per-user, per-item activity record (`activityType` in `['play', 'love', 'skip']`, `songId`, `albumId`, `artistId`, `movieId`, `playCount`, `love`, `lastPlayedAt`, `lastPlatform`, `lastPlayer`) |

No value objects.

## Commands & Handlers

| Command | Handler | Purpose |
|---------|---------|---------|
| `RecordPlayCommand` | `RecordPlayHandler` | Records a play event |
| `ToggleLoveCommand` | `ToggleLoveHandler` | Toggles the loved/favorite state on a play |

## Ports

| Port | Purpose |
|------|---------|
| `ActivityPortInterface` | Application port for activity operations (`ActivityService`) |
| `ActivityAnalyticsPortInterface` | Summary, top tracks, top artists, and user engagement metrics over a date range (`ActivityAnalyticsRepository`) |
| `ActivityIdentityProjectionPortInterface` | Resolves library read scopes (`LibraryReadScope`) and user and artist `PublicId` values from UUIDs (`ActivityIdentityProjection`) |

## API Endpoints

All endpoints are prefixed with `/api` and served by `ActivityController`, `UserRecentController`, and `ActivityAdminController`.

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/activity/history` | List play history (offset-paginated with `limit` and `offset`) |
| GET | `/api/activity/loved` | List loved/favorited items |
| POST | `/api/activity/play` | Record a play (`PlayActivityRequest` → `RecordPlayCommand`) |
| POST | `/api/activity/love/{publicId}` | Toggle the loved flag on an activity (`ToggleLoveCommand`) |
| GET | `/api/user/recent` | List recently played items (`UserRecentController`; optional `limit` and `mediaType`) |
| GET | `/api/admin/activity/summary` | Play, track, artist, and listening-time summary (`ActivityAdminController`, `ROLE_ADMIN`; CLI: `app:activity:summary`) |
| GET | `/api/admin/activity/top-tracks` | Top tracks by play count (`ActivityAdminController`, `ROLE_ADMIN`; CLI: `app:activity:top-tracks`) |
| GET | `/api/admin/activity/top-artists` | Top artists by play count (`ActivityAdminController`, `ROLE_ADMIN`; CLI: `app:activity:top-artists`) |
| GET | `/api/admin/activity/engagement` | Active users, average plays, and average listening time (`ActivityAdminController`, `ROLE_ADMIN`; CLI: `app:activity:engagement`) |

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | Shared | `Uuid`, `PublicId`, `LibraryReadScope` |
| Depends on | Catalog | `SongPortInterface`, `AlbumPortInterface`, `ArtistPortInterface`, `MoviePortInterface`, `SongAvailabilityPortInterface`; `MediaActivityEntity` references `SongEntity`, `AlbumEntity`, `ArtistEntity`, and `MovieEntity` |
| Depends on | Auth | `AuthenticatedUserIdentityInterface`; `MediaActivityEntity` references `UserEntity` |
| Depends on | Library | `LibraryReadScopeProviderInterface` in `ActivityEnrichmentService` |
| Depends on | Media | `ImagePortInterface` in `ActivityEnrichmentService` |
| Depended on by | Recommendation | Uses activity data (`ActivityPortInterface`) for suggestions |

`ActivityIdentityProjection` (`ActivityIdentityProjectionPortInterface`) resolves user and artist `PublicId` values from database tables without importing foreign repositories, following the same public-identity projection pattern as `Radio` (`RadioPublicIdentityInterface`) and `Party` (`PartyPublicIdentityPortInterface`).

## Infrastructure

| Component | Type | Purpose |
|-----------|------|---------|
| `MediaActivityEntity` | Doctrine entity | ORM mapping for media activity |
| `MediaActivityRepository` | Doctrine repository | Persistence implementation (`MediaActivityRepositoryInterface`) |
| `ActivityService` | Port implementation | Implements `ActivityPortInterface` |
| `ActivityEnrichmentService` | Service | Batch-resolves songs, albums, cover images, artist names, movies, and song availability for activity responses |
| `ActivityAnalyticsRepository` | Doctrine repository | Implements `ActivityAnalyticsPortInterface` |
| `ActivityIdentityProjection` | Port implementation | Implements `ActivityIdentityProjectionPortInterface` |

### Console Commands

| Command | Class | Purpose |
|---------|-------|---------|
| `app:activity:summary` | `ActivitySummaryCommand` | Show total plays, distinct tracks and artists, and listening time over a date range (`--from`, `--to`, `--json`) |
| `app:activity:top-tracks` | `ActivityTopTracksCommand` | List the most played tracks over a date range (`--from`, `--to`, `--limit`, `--json`) |
| `app:activity:top-artists` | `ActivityTopArtistsCommand` | List the most played artists over a date range (`--from`, `--to`, `--limit`, `--json`) |
| `app:activity:engagement` | `ActivityEngagementCommand` | Show active users, average plays per user, and average listening time per user over a date range (`--from`, `--to`, `--json`) |
