# Activity

The Activity context tracks user listening history — what users play, when, and what they love (favorite). It provides the data for "recently played" and "liked songs" features.

## Domain Models

| Model | Kind | Purpose |
|-------|------|---------|
| `MediaActivity` | Aggregate root | A listen event — media type, playback position, duration, and loved state |

No value objects.

## Commands & Handlers

| Command | Handler | Purpose |
|---------|---------|---------|
| `RecordPlayCommand` | `RecordPlayHandler` | Records a play event |
| `ToggleLoveCommand` | `ToggleLoveHandler` | Toggles the loved/favorite state on a play |

## Ports

| Port | Purpose |
|------|---------|
| `ActivityPortInterface` | Application port for activity operations |

## API Endpoints

All endpoints are prefixed with `/api` and served by `ActivityController`.

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/activity` | List play history (cursor-paginated) |
| GET | `/api/activity/loved` | List loved/favorited items |
| POST | `/api/activity` | Record a play (`PlayActivityRequest`) |

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | Shared | `Uuid`, `PublicId`, `CursorPage`, `CursorPaginatedResponse` |
| Depended on by | Recommendation | Uses activity data for suggestions |

## Infrastructure

| Component | Type | Purpose |
|-----------|------|---------|
| `MediaActivityEntity` | Doctrine entity | ORM mapping for media activity |
| `MediaActivityRepository` | Doctrine repository | Persistence implementation |
