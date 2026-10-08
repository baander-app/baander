# Shared Kernel

`src/Shared/` is not a bounded context. It provides cross-cutting infrastructure used by all 21 bounded contexts. It follows the same four-layer structure (Domain, Application, Infrastructure, Interface) but contains no business domain logic of its own.

When you need a primitive type, async utility, or shared controller, look here first before creating something new in your context.

## Domain Models

### Value Objects

| Model | Purpose |
|-------|---------|
| `Uuid` | UUID v7 — used as all primary keys throughout the system |
| `PublicId` | Public-facing opaque identifier, separate from internal UUID |
| `Email` | Typed email value object with validation |

### Pagination

| Model | Purpose |
|-------|---------|
| `Cursor` | Opaque cursor-based pagination token |
| `CursorDirection` | Pagination direction (`asc` or `desc`) |
| `CursorPage` | A page of results with cursor metadata (has next/previous cursors) |

### Search

| Model | Purpose |
|-------|---------|
| `SearchOptions` | Encapsulates search query parameters |
| `SearchResult` | A single search result with relevance data |

### Events

| Model | Purpose |
|-------|---------|
| `AbstractDomainEvent` | Base class for all domain events across contexts |
| `DomainEventInterface` | Contract that all domain events must implement |

### Jobs

| Model | Purpose |
|-------|---------|
| `JobStatus` | Enum representing async job statuses (pending, running, completed, failed) |

## Repository Interfaces

| Interface | Purpose |
|-----------|---------|
| `Searchable` | Contract for repositories that support full-text search. Implementations use `PgroongaSearchTrait` |

## Swoole Runtime

### Async Sleep

Use `App\Shared\Infrastructure\Swoole\Async::sleep()` for all sleeping. It auto-detects coroutine context and routes to `Swoole\Coroutine::sleep()` or `usleep()` accordingly. Never call `sleep()`, `usleep()`, or `Swoole\Coroutine::sleep()` directly.

### Process Pool

`Swoole\ProcessPool` provides isolated worker processes for CPU-bound work. Workers communicate via Unix sockets (`SWOOLE_IPC_UNIXSOCK`). The Transcode context uses this for FFmpeg encoding.

### WebSocket

| Component | Purpose |
|-----------|---------|
| `WebSocketConnectionRegistry` | Tracks active WebSocket connections by user |
| `WebSocketPusher` | Pushes messages to connected clients |

## Job Monitoring

Async jobs dispatched via Symfony Messenger are tracked through three components:

| Component | Purpose |
|-----------|---------|
| `JobIdStamp` | Middleware stamp that assigns a unique job ID to dispatched messages |
| `JobMonitoringMiddleware` | Assigns each dispatched message its job ID (`JobIdStamp`) |
| `JobMonitorService` | Queries job status and history |

See the [CQRS and Messaging](../cqrs-and-messaging.md) page for dispatching patterns.

## Messenger

| Component | Purpose |
|-----------|---------|
| `SwooleTaskDispatcherInterface` | Contract for dispatching tasks to Swoole workers |
| `HttpServerTaskDispatcher` | Implementation that dispatches via Swoole HTTP server task workers |

## Doctrine

| Component | Purpose |
|-----------|---------|
| `UuidType` | Custom Doctrine column type for `Uuid` |
| `PublicIdType` | Custom Doctrine column type for `PublicId` |
| `GeneratePublicIdListener` | Doctrine event listener that auto-generates `PublicId` values on persist |

## Caching

Redis-backed tag-aware cache pools via `RedisTagAwareAdapter`. The `noeviction` policy is required for tag invalidation to work correctly.

## Redis

Connection management and configuration for all Redis-backed features: caching, Messenger transport, SSE Pub/Sub, and session storage.

## Server-Sent Events

SSE delivery uses Redis Pub/Sub channels. When a domain event is published, an event listener pushes it to a Redis channel. The SSE controller subscribes to that channel and streams events to connected clients.

See the [Real-Time Patterns](../real-time-patterns.md) page for the full SSE and WebSocket architecture.

## Security

| Component | Purpose |
|-----------|---------|
| `SseQueryTokenAuthenticator` | Authenticates SSE connections via query string token |
| `WsQueryTokenAuthenticator` | Authenticates WebSocket connections via query string token |

## Logging

| Component | Purpose |
|-----------|---------|
| `BoundedContextLogger` | Creates loggers scoped to a bounded context |
| `CorrelationIdProcessor` | Monolog processor that attaches a correlation ID to log entries |

## Settings

Baander has one settings mechanism for both server-wide settings (system scope) and per-user settings (user scope). Each setting is described by a definition that the owning context contributes. The definitions drive validation, the admin settings page, the API and the CLI, so a new setting needs no endpoint, command or form of its own. Shared holds the definitions, the registry, the parser and the system settings store; UserPreference holds the user settings store (see [UserPreference](user-preference.md#user-settings)).

### Definitions

`SettingDefinition` (`Domain/Model/Setting/`) is a value object. Its constructor rejects an inconsistent definition with `InvalidArgumentException`.

| Field | Meaning |
|-------|---------|
| `key` | Dot-separated lowercase words, for example `transcode.max_bitrate` |
| `type` | `SettingValueType`: `Boolean`, `Integer`, `Enum` or `String` |
| `scope` | `SettingScope`: `System` or `User` |
| `label`, `description`, `group` | What the admin settings page shows; settings are grouped by `group` |
| `default` | Required, and must be an allowed value, unless the definition has a `fallbackKey` |
| `allowedValues`, `valueLabels` | Enum only: the allowed values, all strings or all integers, and an optional display label for each |
| `min`, `max` | Integer only: optional bounds |
| `editRole` | `ROLE_SUPER_ADMIN` (default) or `ROLE_USER`. A user setting with `ROLE_USER` is one users may change themselves; administrators may change every user setting |
| `userVisible` | System only. Marks a system setting signed-in users may read. No endpoint serves system settings to users; today a user sees one only as the `resetValue` of a user setting that follows it |
| `enforced` | `true` (default) when the server acts on the setting. Declare `false` while the setting is defined but nothing reads it yet: the admin page shows a **Not yet enforced** badge and `app:settings:list` shows `not yet` |
| `fallbackKey` | User only. The system setting this user setting follows; the user setting then has no default of its own, and its default is that system setting's current value |

`SupportedLanguages` lists the email languages with their native names. `i18n.default_language` and the user setting `language` both take their allowed values from it.

### Registry, parser and stores

A context contributes definitions through a class implementing `SettingDefinitionProviderInterface` (`Application/Port/`). Autoconfiguration tags every implementation `baander.setting_definition_provider`, and `SettingDefinitionRegistry` collects them on first use. The registry rejects a key defined twice, and a `fallbackKey` that does not name a system setting of the same type, with `LogicException`.

`SettingValueParser` is the only path from input to a typed value, so the API and the CLI cannot drift. It accepts a CLI string or a decoded JSON value: `true`/`false` or the strings `"true"`/`"false"` for a boolean, an integer or a string of digits for an integer or an integer enum, and a string for a string enum or a string setting. It then checks the value against the definition and returns either the typed value or a `SettingViolation` describing what is allowed.

System settings live in `system_settings` (`key text` primary key, `value jsonb`, `updated_at timestamptz`). A row exists only for a value someone set. `SystemSettingRepository` implements `SystemSettingStoreInterface` with DBAL queries rather than the ORM identity map, so a long-running worker sees an administrator's change on its next read; `SystemSettingEntity` is a read-only schema mapping. `SystemSettings` implements `SystemSettingsPortInterface::get()`, which returns the stored value while the definition allows it and the default otherwise. Its `entries()` and `entry()` also report the stored value and whether it is still valid, for the admin API and the CLI.

`UpdateSystemSettingsHandler` parses every key in the command before it writes any. An unknown key or invalid value raises `InvalidSettingValuesException` with one violation per key, and nothing is written; otherwise all values are saved in one statement. `ResetSystemSettingHandler` deletes the row.

### Endpoints and commands

`SystemSettingsController` is gated with `ROLE_ADMIN`; writes also need the `SYSTEM_SETTINGS` attribute, which `AdminVoter` grants to super administrators only. Each endpoint has a console command that dispatches the same command or reads the same service.

| Endpoint | Command | Purpose |
|----------|---------|---------|
| `GET /api/admin/settings` | `app:settings:list`, `app:settings:get` | System settings with effective value, stored value and validity |
| `GET /api/admin/settings/definitions` | — | Every definition, system and user, as the admin page renders them |
| `PATCH /api/admin/settings` | `app:settings:set` | Validate all, then write; `422` with per-key messages |
| `DELETE /api/admin/settings/{key}` | `app:settings:reset` | Reset to the default; `404` for an unknown key |

The user settings endpoints are in [UserPreference](user-preference.md#user-settings), and the administrator's view of a user's settings is in [Auth](auth.md#admin--user-settings). The operator reference is [Server settings](../../part-1-operator-guide/configuration.md#server-settings).

### Adding a setting

1. Add a definition to the owning context's provider in `Application/Settings/`, or create the provider, for example `LyricsSettingDefinitions` implementing `SettingDefinitionProviderInterface`. Give the key a constant on the provider; the code that reads the setting uses it. Autoconfiguration registers the provider.
2. Choose the default so that adding the setting does not change behavior: it equals what the server did before the setting existed.
3. Read the setting where the behavior happens, every time it happens. A system setting is read with `SystemSettingsPortInterface::get()`. Do not cache the value in a long-lived service; reading fresh is what lets a change apply without a restart. A user setting is read inside UserPreference with `UserSettingsReader`, and from another context through `UserSettingsContractInterface`.
4. If the definition lands before the code that acts on it, declare `enforced: false`, and remove that argument once the setting is honored, so the admin page and the CLI tell the truth.
5. If another context must name the key constant, the provider has to be reachable through a contract Deptrac layer. `TranscodeSettingDefinitions` is in the `Transcode Audio Rendition Contract` layer so that Media can read `transcode.enabled` and `transcode.max_bitrate`.

The setting then appears on the admin settings page and in `/api/admin/settings` and `app:settings:*` (system scope), or in `/api/user/settings`, the admin user settings endpoints and `app:user:setting` (user scope). The web **Settings** page does not render user settings from definitions; a new user-editable setting needs its own control there, as the email language has.

### Adding an email language

1. Translate every key of the `auth` and `notification` domains: `translations/auth+intl-icu.<code>.yaml` and `translations/notification+intl-icu.<code>.yaml`. `tests/Unit/Shared/Translation/EmailTranslationParityTest.php` fails while any supported language misses a key that English has, or has a key that English lacks.
2. Add the code and its native name to `SupportedLanguages::NATIVE_NAMES`. That extends the allowed values of `language` and `i18n.default_language`, and the languages registration matches against `Accept-Language`.
3. Add the code to `enabled_locales` in `config/packages/translation.yaml`.

Removing a language reverses the steps. A stored choice of the removed language is then no longer allowed: users read it as no choice, emails fall back to the server default and then English, and the admin API and CLI show the stored value as invalid.

## Controllers

| Controller | Path | Purpose |
|-----------|------|---------|
| `SystemSettingsController` | `/api/admin/settings` | Server settings and every setting definition (see [Settings](#settings)) |
| `HealthCheckController` | `/health`, `/ready`, `/live` | Health and readiness probes |
| `ServerStatsController` | `/api/stats` | Server diagnostics and statistics |
| `JobMonitorController` | `/api/jobs` | Job status and management |
| `JobAnalyticsController` | `/api/jobs/analytics` | Job analytics and metrics |
| `PrometheusMetricsController` | `/api/metrics` | Prometheus metrics endpoint |
| `TransportController` | `/api/transport` | Messenger transport status |
| `ConfigCheckController` | `/api/config/check` | Configuration validation |
| `RateLimiterMonitorController` | `/api/rate-limiter` | Rate limiter status and statistics |
| `SpaController` | `/` | Single-page application entry point (catch-all) |
| `SseController` | `/api/sse` | Server-sent events endpoint |
| `NotificationSseController` | `/api/sse/notifications` | Notification-specific SSE stream |
| `WebSocketController` | `/ws` | WebSocket connection endpoint |

## DTOs

Shared response types used across contexts:

| DTO | Purpose |
|-----|---------|
| `ApiError` | Standard error response format |
| `OAuthError` | OAuth-specific error response |
| `ValidationError` | Validation failure details |
| `PaginatedResponse` | Offset-based pagination wrapper |
| `CursorPaginatedResponse` | Cursor-based pagination wrapper |

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | — | Nothing — Shared is the foundation |
| Depended on by | All 21 bounded contexts | Types, infrastructure, and shared controllers |

## See Also

- [Shared Kernel (detailed)](../shared-kernel.md) — UUID v7 usage, cursor pagination, and Redis configuration
- [Real-Time Patterns](../real-time-patterns.md) — SSE and WebSocket architecture
- [CQRS and Messaging](../cqrs-and-messaging.md) — Job monitoring and task dispatching
