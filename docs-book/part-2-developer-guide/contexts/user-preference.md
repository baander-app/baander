# UserPreference

`src/UserPreference/` manages per-user UI and playback preferences: accent color, sidebar configuration, audio equalizer profiles, layout preferences, player preferences, and theme mood. These are lightweight settings that control frontend appearance and playback behavior, stored per-user.

This context does not use CQRS. Controllers call port interfaces directly for both reads and writes.

## Subsystems

The context is organized into seven preference subsystems, each with its own controller, port, and persistence entity:

| Subsystem | Purpose | Versioned |
|-----------|---------|-----------|
| AccentColor | UI accent color (e.g., violet, blue, green) | No |
| SidebarConfig | Per-media-type sidebar section ordering and visibility | No |
| AudioPreferences | EQ settings, normalization, bass boost, etc. | Yes (history + rollback) |
| LayoutPreferences | Sidebar mode (compact/expanded/pioneer) and active tab | Yes (history + rollback) |
| PlayerPreferences | Volume, repeat mode, shuffle, quality, etc. | Yes (history + rollback) |
| EqDeviceProfile | Named EQ profiles per device with activate/deactivate | No |
| ThemeMood | UI theme mood (e.g., dark, light, midnight) | No |

## Ports

| Port | Purpose |
|------|---------|
| `AccentColorPortInterface` | Get and update the authenticated user's accent color |
| `SidebarConfigPortInterface` | Get, update, or reset per-media-type sidebar configuration |
| `AudioPreferencesPortInterface` | Get, save (versioned), get history, and rollback audio preferences |
| `LayoutPreferencesPortInterface` | Get, save (versioned), get history, and rollback layout preferences |
| `PlayerPreferencesPortInterface` | Get, save (versioned), get history, and rollback player preferences |
| `EqDeviceProfilePortInterface` | CRUD + activate EQ device profiles |
| `ThemeMoodPortInterface` | Get and set theme mood |

## API Endpoints

All endpoints require authentication and are scoped to the authenticated user (except where noted).

### Accent Color

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/user/accent-color` | Get accent color (default: `violet`) |
| PUT | `/api/user/accent-color` | Update accent color |

### Sidebar Config

Operates per media type. Valid media types: `music`, `movies`, `tv`, `podcasts`, `concerts`, `ebooks`.

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/user/sidebar-config/{mediaType}` | Get sidebar config for a media type (returns defaults if unset) |
| PUT | `/api/user/sidebar-config/{mediaType}` | Update sidebar sections for a media type |
| DELETE | `/api/user/sidebar-config/{mediaType}` | Reset to defaults (returns 200 with default config, not 204) |

### Audio Preferences

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/user/audio-preferences` | Get current audio preferences |
| PUT | `/api/user/audio-preferences` | Save audio preferences (versioned) |
| GET | `/api/user/audio-preferences/history` | Get version history |
| POST | `/api/user/audio-preferences/rollback` | Rollback to a specific version |

### Layout Preferences

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/user/layout-preferences` | Get current layout preferences |
| PUT | `/api/user/layout-preferences` | Save layout preferences (versioned) |
| GET | `/api/user/layout-preferences/history` | Get version history |
| POST | `/api/user/layout-preferences/rollback` | Rollback to a specific version |

Layout preferences require `mode` (one of `compact`, `expanded`, `pioneer`) and `activeTab`.

### Player Preferences

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/user/player-preferences` | Get current player preferences |
| PUT | `/api/user/player-preferences` | Save player preferences (versioned) |
| GET | `/api/user/player-preferences/history` | Get version history |
| POST | `/api/user/player-preferences/rollback` | Rollback to a specific version |

Player preferences use a strict 9-field payload. Notable fields: `volume` is a float 0–1 (not 0–100), `repeat` is one of `off`, `all`, `one`.

### EQ Device Profiles

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/user/eq-profiles` | List user's EQ profiles |
| POST | `/api/user/eq-profiles` | Create a new EQ profile |
| GET | `/api/user/eq-profiles/{id}` | Get a specific profile |
| PUT | `/api/user/eq-profiles/{id}` | Update a profile |
| DELETE | `/api/user/eq-profiles/{id}` | Delete a profile |
| POST | `/api/user/eq-profiles/{id}/activate` | Activate a profile for the user |

> **Known design gap:** Show, update, delete, and activate take a profile ID but do not filter by `userId`. Any authenticated user can access another user's EQ profile by guessing its UUID. Additionally, not-found throws `InvalidArgumentException` which maps to HTTP 500 instead of 404.

### Theme Mood

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/user/theme-mood` | Get theme mood |
| PUT | `/api/user/theme-mood` | Update theme mood |

## Versioned Save Pattern

Audio, Layout, and Player preferences support versioned saves with history and rollback. Each save increments a version number. The controller accepts a `version` field in the payload and passes it to `saveForUser()`.

```
GET  .../history    →  [{version: 3, payload: {...}}, {version: 2, ...}, ...]
POST .../rollback   →  {version: 2}  →  restores version 2 as current
```

> **Known design gap:** The controllers catch `RuntimeException` for optimistic-locking version conflicts and return HTTP 409, but `saveForUser()` never actually throws `RuntimeException`. The 409 response path is dead code — there is no real optimistic locking.

## Domain Models

### SidebarConfig

Per-media-type sidebar configuration.

| Property | Description |
|----------|-------------|
| `userId` | The user who owns this configuration |
| `items` | Ordered collection of `SidebarItem` entries |
| `updatedAt` | Last modification timestamp |

### SidebarItem

An individual sidebar entry with `label`, `icon`, `route`, `order`, and `visible` properties.

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | Shared | `Uuid` |
| Depends on | Auth | User identification (current user from security context) |
| Depended on by | — | No contexts depend on UserPreference |

## Infrastructure

| Component | Type | Purpose |
|-----------|-------|---------|
| `AccentColorEntity` | Doctrine entity | ORM entity for accent color storage |
| `SidebarConfigEntity` | Doctrine entity | ORM entity for sidebar configuration |
| `UserAudioPreferencesEntity` | Doctrine entity | Versioned audio preferences |
| `UserLayoutPreferencesEntity` | Doctrine entity | Versioned layout preferences |
| `UserPlayerPreferencesEntity` | Doctrine entity | Versioned player preferences |
| `EqDeviceProfileEntity` | Doctrine entity | EQ device profiles |
| `UserThemeMoodEntity` | Doctrine entity | Theme mood storage |
| Doctrine repositories | ORM | Implement the port interfaces |
