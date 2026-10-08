# UserPreference

`src/UserPreference/` manages per-user UI and playback preferences: accent color, sidebar configuration, audio equalizer profiles, layout preferences, player preferences, and theme mood. These are lightweight settings that control frontend appearance and playback behavior, stored per-user. The context also owns the per-user half of the [settings mechanism](shared.md#settings): the `user_settings` store, the user settings API, and the contract through which other contexts read and change a user's settings.

The preference subsystems do not use CQRS: their controllers call port interfaces directly for both reads and writes. User settings are written through `SetUserSettingCommand` and `ResetUserSettingCommand` and read through `UserSettingsReader`.

## Subsystems

The context is organized into seven preference subsystems, each with its own controller, port, and persistence entity, and the user settings store:

| Subsystem | Purpose | Versioned |
|-----------|---------|-----------|
| AccentColor | UI accent color, any string of up to 32 characters (default `violet`) | No |
| SidebarConfig | Per-media-type sidebar section ordering and visibility | No |
| AudioPreferences | EQ settings, normalization, bass boost, etc. | Yes (history + rollback) |
| LayoutPreferences | Sidebar mode (`compact` or `expanded`) and active tab | Yes (history + rollback) |
| PlayerPreferences | Volume, repeat mode, shuffle, crossfade and ReplayGain | Yes (history + rollback) |
| EqDeviceProfile | Named EQ profiles per device with activate/deactivate | No |
| ThemeMood | UI theme mood: `dark`, `warm`, `cool` or `balanced` (default `dark`) | No |
| UserSettings | Scalar per-user settings declared by setting definitions; currently the email language | No |

## Ports

| Port | Purpose |
|------|---------|
| `AccentColorPortInterface` | Get and update the authenticated user's accent color |
| `SidebarConfigPortInterface` | Get, update, or reset per-media-type sidebar configuration |
| `AudioPreferencesPortInterface` | Get, save (versioned), get history, and rollback audio preferences |
| `LayoutPreferencesPortInterface` | Get, save (versioned), get history, and rollback layout preferences |
| `PlayerPreferencesPortInterface` | Get, save (versioned), get history, and rollback player preferences |
| `PreferenceWriterPortInterface` | Versioned write shared by the audio, layout and player adapters |
| `EqDeviceProfilePortInterface` | CRUD + activate EQ device profiles |
| `ThemeMoodPortInterface` | Get and set theme mood |
| `UserSettingStoreInterface` | Read, save and delete rows of `user_settings` |
| `UserSettingsContractInterface` | Published contract for other contexts: resolve a user's email language, seed it at registration, and read, set and reset a user's settings for administrators |

## API Endpoints

All endpoints require authentication and are scoped to the authenticated user.

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

Layout preferences require `mode` (`compact` or `expanded`) and `activeTab`.

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

Every port method takes the user's ID along with the profile ID. A profile that does not exist, or belongs to another user, raises `EqDeviceProfileNotFound`, which the controller answers with `404`.

### Theme Mood

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/user/theme-mood` | Get theme mood |
| PUT | `/api/user/theme-mood` | Update theme mood |

### User Settings

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/user/settings` | Every user setting with the user's `choice`, the effective `value`, the `resetValue`, the `source` (`user`, `server_default` or `default`), whether it is `editable`, and its definition |
| PUT | `/api/user/settings/{key}` | Store a choice; the body is `{"value": …}` |
| DELETE | `/api/user/settings/{key}` | Remove the choice |

An unknown key is `404`. A key that names a system setting, or a user setting whose `editRole` is not `ROLE_USER`, is `403`. An invalid value is `422` with the violation under the key, and nothing is stored. The controller identifies the user through Auth's `AuthenticatedUserIdentityInterface`; the older controllers cast to `SecurityUser`.

Administrators reach the same store through Auth's endpoints under `/api/admin/users/{id}/settings` and `app:user:setting`, which call the settings contract (see [Auth](auth.md#admin--user-settings)).

## Versioned Save Pattern

Audio, Layout, and Player preferences support versioned saves with history and rollback. The `PUT` body is `{"payload": {...}, "version": n}`, where `version` is the version the client last read; a first save sends `0`. `VersionedPreferencesWriter` applies optimistic locking in one transaction: a first save inserts version 1, and a later save updates the row only if its version still equals the expected one, then increments it. Every successful save also appends the payload to `preference_history`. If the expected version is stale, the writer throws `PreferenceVersionConflict`, and the controller answers `409` with the `currentVersion`.

```
GET  .../history    →  [{version: 3, payload: {...}}, {version: 2, ...}, ...]
POST .../rollback   →  {version: 2}  →  saves version 2's payload as a new version
```

Rollback reads the payload from history and saves it through the same writer, so it produces a new version rather than rewinding the counter.

## User Settings

A user setting is a scalar value, typed and validated by a `SettingDefinition` with `SettingScope::User` from the Shared [setting definition registry](shared.md#settings). UserPreference contributes its own definitions through `LanguageSettingDefinitions`; currently the only one is `language`.

### Storage

`user_settings` has `user_id` (foreign key to `users`, `ON DELETE CASCADE`), `key`, `value jsonb` and `updated_at timestamptz`, with primary key `(user_id, key)`. A check constraint limits `value` to a JSON boolean, number or string. A row exists only for an explicit choice; resetting deletes it. There is no version and no history: settings are last-write-wins.

`UserSettingRepository` implements `UserSettingStoreInterface` with DBAL queries, never the ORM identity map, so a long-running worker sees a change made elsewhere on its next read. `UserSettingEntity` is a read-only schema mapping only.

### Reading

`UserSettingsReader` turns a definition and the stored value into a `UserSettingEntry`:

- `choice` is the stored value while the definition still allows it, otherwise `null`. A stored value that is no longer allowed reads as no choice for the user.
- `resetValue` is the value the setting has without a choice. For a setting with a `fallbackKey`, it is that system setting's current value; otherwise it is the definition's default.
- `value` is `choice ?? resetValue`.
- `source` is `user`, `server_default` (no choice, and the setting follows a system setting) or `default`.

`resetValue` is how the web app's **Email language** control names the current server default even after the user has chosen a language. Signed-in users never call the admin settings endpoint.

### Writing

`SetUserSettingHandler` parses the input with `SettingValueParser`, the same parser the system settings use, and saves the typed value; an invalid value raises `InvalidSettingValuesException`. `ResetUserSettingHandler` deletes the row. Neither handler checks who may edit the setting: `UserSettingsController` refuses settings the user may not change, and the admin path in Auth may change every user setting.

### The settings contract

Other contexts reach user settings only through `UserSettingsContractInterface` and its DTO `UserSettingView`. The two classes form the `UserPreference Settings Contract` Deptrac layer; the rest of UserPreference Application stays out of reach. `UserSettingsContract` in Infrastructure implements it by calling the reader and the two handlers directly, so a call joins the caller's open transaction.

| Method | Caller | Purpose |
|--------|--------|---------|
| `resolveLanguage()` | Auth `AfterResponseMailer`, Notification `SendEmailHandler` | The email language: the user's choice while it is offered, otherwise the server default while it is offered, otherwise English |
| `seedLanguage()` | Auth `RegisterUserHandler` | Store the browser's language as the user's choice, only when it is offered and differs from the current server default |
| `settings()`, `setting()` | Auth `AdminUserSettings` | The user's settings as `UserSettingView`, with a stored value that is no longer allowed kept visible and marked invalid |
| `set()`, `reset()` | Auth `AdminUserSettings` | Change a user's setting on an administrator's behalf, including settings the user may not change |

Every read goes to the stores afresh, so the language of an email is decided when it is sent.

## Moving Preferences onto User Settings

The preference subsystems predate the settings mechanism. Each has its own port, adapter, controller, entity and table, and most have a domain model as well. The plan is to move them onto `user_settings` where the store fits them, in this order:

1. **Theme mood and accent color first.** Both are one scalar per user with a default, which is exactly what a user setting is. Each becomes a definition in a UserPreference provider with `editRole: ROLE_USER`: theme mood an enum of `dark`, `warm`, `cool` and `balanced` with default `dark`, and accent color an enum of the web palette (`VALID_ACCENTS` in `ui/web/src/shared/theme/theme.types.ts`) with default `violet`. Today the API accepts any accent color string of up to 32 characters, and the string type of a setting definition has no length bound, so an enum is the way to keep the value validated. A stored value outside the enum then reads as no choice, as described under [Reading](#reading). The web app switches to `/api/user/settings/{key}`, and the old ports, adapters, controllers, entities and the `user_accent_colors` and `user_theme_moods` tables are removed. Copy only rows that differ from the default, because a row in `user_settings` means an explicit choice.
2. **The versioned payloads only if the store gains versioning.** Audio, layout and player preferences are JSON objects with optimistic locking and a history that rollback depends on. `user_settings` holds scalar values only (its check constraint enforces it) and has neither versions nor history. These three move only after the store is given both; until then they keep their tables and `VersionedPreferencesWriter`.
3. **Sidebar configuration and EQ profiles stay aggregates.** A sidebar configuration is an ordered list of items per media type, and EQ profiles are several named records per user with an active one. Neither is one value per key, so neither belongs in a key-value store.

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

### Other models

`AudioPreferences`, `LayoutPreferences`, `PlayerPreferences`, `EqDeviceProfile` and `PreferenceHistory` use the state-object pattern (`*State` classes). Accent color and theme mood have no domain model; their repositories store the value directly.

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | Shared | `Uuid`, the setting definition registry, `SettingValueParser`, and `SystemSettingsPortInterface` for the system setting a user setting follows |
| Depends on | Auth | The current user: `SecurityUser` in the preference controllers, `AuthenticatedUserIdentityInterface` in `UserSettingsController` |
| Depended on by | Auth | Registration seeds the email language; credential emails resolve it; the admin user settings endpoints and `app:user:setting` read and change settings, all through `UserSettingsContractInterface` |
| Depended on by | Notification | `SendEmailHandler` resolves each recipient's email language through `UserSettingsContractInterface` |

## Infrastructure

| Component | Type | Purpose |
|-----------|-------|---------|
| `UserAccentColorEntity` | Doctrine entity | Accent color storage (`user_accent_colors`) |
| `SidebarConfigEntity` | Doctrine entity | Sidebar configuration |
| `AudioPreferencesEntity` | Doctrine entity | Versioned audio preferences |
| `LayoutPreferencesEntity` | Doctrine entity | Versioned layout preferences |
| `PlayerPreferencesEntity` | Doctrine entity | Versioned player preferences |
| `PreferenceHistoryEntity` | Doctrine entity | History of the versioned preferences (`preference_history`) |
| `EqDeviceProfileEntity` | Doctrine entity | EQ device profiles |
| `UserThemeMoodEntity` | Doctrine entity | Theme mood storage (`user_theme_moods`) |
| `UserSettingEntity` | Doctrine entity | Read-only schema mapping of `user_settings` |
| `VersionedPreferencesWriter` | DBAL | Optimistic-locking save and history row for the versioned preferences |
| `UserSettingRepository` | DBAL | Implements `UserSettingStoreInterface` |
| `UserSettingsContract` | Contract implementation | Implements `UserSettingsContractInterface` |
| Doctrine repositories and adapters | ORM | Implement the remaining port interfaces |
