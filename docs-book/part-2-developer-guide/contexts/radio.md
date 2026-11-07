# Radio

The Radio context implements internet radio: country station subscription, station syncing from external directories, station starring, and radio playback sessions. Stations are synced through a pluggable adapter contract so external directories (IPRD, TuneIn, etc.) can be integrated behind an anti-corruption layer.

## Domain Models

### Aggregate Roots

| Model | Purpose |
|-------|---------|
| `CountrySubscription` | A user's subscription to a country's station catalog |
| `RadioStation` | A radio station with its streams |
| `RadioSource` | A configured radio source (provider feed) |
| `RadioSession` | An active radio playback session for a user |
| `StarredStation` | A user's star on a station |

All aggregates follow the State-object pattern, with `*State` classes (`CountrySubscriptionState`, `RadioStationState`, `RadioSourceState`, `RadioSessionState`, `StarredStationState`) holding constructor/create/reconstitute in sync per the project's aggregate root convention.

### Value Objects

| Model | Purpose |
|-------|---------|
| `Stream` | A single stream URL/codec within a station |
| `SyncConfig` | Configuration for a station sync run |

## Commands & Handlers

| Command | Handler | Purpose |
|---------|---------|---------|
| `SubscribeCountryCommand` | `SubscribeCountryHandler` | Subscribe a user to a country's stations |
| `UnsubscribeCountryCommand` | `UnsubscribeCountryHandler` | Remove a country subscription |
| `SyncCountryStationsCommand` | `SyncCountryStationsHandler` | Sync stations for a subscribed country |
| `StartRadioCommand` | `StartRadioHandler` | Start a radio playback session |
| `StopRadioCommand` | `StopRadioHandler` | Stop the active radio session |
| `StarStationCommand` | `StarStationHandler` | Star a station |
| `UnstarStationCommand` | `UnstarStationHandler` | Remove a star from a station |

## Ports

| Port | Purpose | Implemented By |
|------|---------|----------------|
| `CountrySubscriptionRepositoryInterface` | Domain repository contract for `CountrySubscription` persistence and queries | `CountrySubscriptionDoctrineRepository` |
| `RadioStationRepositoryInterface` | Domain repository contract for `RadioStation` persistence and queries | `RadioStationDoctrineRepository` |
| `RadioSourceRepositoryInterface` | Domain repository contract for `RadioSource` persistence and queries | `RadioSourceDoctrineRepository` |
| `RadioSessionRepositoryInterface` | Domain repository contract for `RadioSession` persistence and queries | `RadioSessionDoctrineRepository` |
| `StarredStationRepositoryInterface` | Domain repository contract for `StarredStation` persistence and queries | `StarredStationDoctrineRepository` |
| `CountrySubscriptionPortInterface` | Country subscription lifecycle (subscribe, unsubscribe, query) | `CountrySubscriptionService` |
| `RadioStationPortInterface` | Station lookup and listing | `RadioStationService` |
| `RadioSourcePortInterface` | Radio source CRUD | `RadioSourceService` |
| `RadioSessionPortInterface` | Playback session lifecycle (start, stop, query) | `RadioSessionService` |
| `StarredStationPortInterface` | Starred-station operations | `StarredStationService` |
| `RadioPublicIdentityInterface` | Resolves a user's `PublicId` from their internal `Uuid` | `RadioPublicIdentity` |
| `StationSyncPortInterface` | Contract for station sync adapters (IPRD, TuneIn, etc.) | `IprdStationSyncAdapter` |

## Domain Events

| Event | Trigger |
|-------|---------|
| `CountrySubscribed` | User subscribed to a country |
| `CountryUnsubscribed` | User unsubscribed from a country |
| `RadioSessionStarted` | Radio playback session started |
| `RadioSessionStopped` | Radio playback session stopped |
| `StationStarred` | User starred a station |
| `StationUnstarred` | User removed a star |

## API Endpoints

All endpoints are prefixed with `/api`.

### Country Subscriptions

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/radio/subscriptions` | List the user's country subscriptions |
| POST | `/api/radio/subscriptions` | Subscribe to a country |
| DELETE | `/api/radio/subscriptions/{countryCode}` | Unsubscribe from a country |
| POST | `/api/radio/subscriptions/{countryCode}/refresh` | Refresh stations for a subscription |

### Radio Session

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/radio/session` | Get the current radio session |
| POST | `/api/radio/session/start` | Start a radio session |
| POST | `/api/radio/session/stop` | Stop the radio session |

### Radio Sources

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/radio/sources` | List radio sources |
| POST | `/api/radio/sources` | Create a radio source (administrators only) |

### Stations

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/radio/countries` | List available countries |
| GET | `/api/radio/stations` | List stations |

### Starred Stations

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/radio/starred` | List starred stations |
| POST | `/api/radio/starred` | Star a station |
| DELETE | `/api/radio/starred/{stationId}` | Unstar a station |

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | Shared | `Uuid`, `PublicId`, `AbstractDomainEvent`, `ApiResponsesTrait` |
| Depends on | Auth | `AuthenticatedUserIdentityInterface` for authenticated user resolution |
| Depended on by | Activity | `ActivityIdentityProjection` resolves user public IDs through `RadioPublicIdentityInterface` |
| Depended on by | Shared | `WebSocketIdentityLookup` resolves user public IDs through `RadioPublicIdentityInterface` |

## Infrastructure

| Component | Type | Purpose |
|-----------|------|---------|
| `IprdStationSyncAdapter` | Sync adapter | `StationSyncPortInterface` implementation backed by the IPRD directory |
| `RadioPublicIdentity` | Port implementation | `RadioPublicIdentityInterface` implementation resolving user `PublicId` values |
| Doctrine entities | ORM | Persistence for all five aggregates (`CountrySubscriptionEntity`, `RadioStationEntity`, `RadioSourceEntity`, `RadioSessionEntity`, `StarredStationEntity`) |
| Doctrine repositories | ORM | Repository implementations for all five aggregates (`CountrySubscriptionDoctrineRepository`, `RadioStationDoctrineRepository`, `RadioSourceDoctrineRepository`, `RadioSessionDoctrineRepository`, `StarredStationDoctrineRepository`) |
| Per-aggregate infrastructure services | Application service | `CountrySubscriptionService`, `RadioStationService`, `RadioSourceService`, `RadioSessionService`, `StarredStationService` |

### Console Commands

| Command | Purpose |
|---------|---------|
| `app:radio:country:list` (`RadioCountryListCommand`) | List the countries the radio station directory offers, with their station counts |
| `app:radio:source:create` (`RadioSourceCreateCommand`) | Create a radio source that station data is synced from |
| `app:radio:station:list` (`RadioStationListCommand`) | List the synced radio stations, optionally filtered by country or search text |
| `app:radio:sync` (`SyncSubscribedCountriesCommand`) | Sync stations for all subscribed countries |

See the [Architecture](../architecture.md#anti-corruption-layer) page for details on the station sync anti-corruption layer.
