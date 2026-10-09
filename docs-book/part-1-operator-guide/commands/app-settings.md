# app:settings

Read and change Baander's server settings from the command line. Four commands make up the family: `app:settings:list`, `app:settings:get`, `app:settings:set` and `app:settings:reset`. They do the same work as the admin **Settings** page and the `/api/admin/settings` endpoints, through the same validation, and the change takes effect without a restart. [Server settings](../configuration.md#server-settings) lists every setting, its default and what it controls.

## Quick start

See every server setting:

```bash
make exec cmd="php bin/console app:settings:list"
```

Turn on audio transcoding and cap it at 192 kbps:

```bash
make exec cmd="php bin/console app:settings:set transcode.enabled true"
make exec cmd="php bin/console app:settings:set transcode.max_bitrate 192"
```

Return a setting to its default:

```bash
make exec cmd="php bin/console app:settings:reset transcode.max_bitrate"
```

## app:settings:list

Prints a table with one row per server setting. It takes no arguments.

| Column | Meaning |
|--------|---------|
| Key | The setting key, for example `transcode.enabled` |
| Value | The value Baander uses now |
| Default | The value the setting has when nobody has set it |
| Stored | The value saved in the database, `(default)` when none is saved, or the saved value followed by `(invalid)` when it is no longer allowed |
| Enforced | `yes` when the server acts on the setting, `not yet` when the setting is defined but nothing reads it yet |

Values are printed as JSON literals, so `true` (a boolean) and `"true"` (a string) look different.

## app:settings:get

Shows one setting in detail: its key, current value, default, stored value, allowed values (or the value type when any value of that type is allowed), and whether it is enforced.

```bash
make exec cmd="php bin/console app:settings:get i18n.default_language"
```

| Argument | Required | Description |
|----------|----------|-------------|
| `key` | Yes | Setting key, for example `transcode.max_bitrate` |

A stored value that is no longer allowed, for example one saved before its setting's allowed values changed, is shown with `(invalid)`. Baander ignores such a value and uses the default until someone sets the setting again.

## app:settings:set

Validates the value against the setting's definition and saves it.

| Argument | Required | Description |
|----------|----------|-------------|
| `key` | Yes | Setting key, for example `transcode.max_bitrate` |
| `value` | Yes | New value, for example `true`, `192` or `da` |

The value is parsed by the setting's type:

| Type | Accepted input |
|------|----------------|
| Boolean | `true` or `false` |
| Integer | A whole number, within the setting's bounds if it has any |
| Enum | One of the allowed values that `app:settings:get` lists, for example `da` for `i18n.default_language` or `192` for `transcode.max_bitrate` |
| String | Any text |

An unknown key or an invalid value prints the reason, saves nothing, and exits with `1`. On success the command prints the setting's new value.

| Option | Default | Description |
|--------|---------|-------------|
| `--json` | off | Print every setting after the change, as `PATCH /api/admin/settings` returns them |

With `--json`, stdout holds only the API's `data` array, one object per setting with `key`, `value`, `storedValue`, `isExplicit` and `storedValueValid`. Rejected values go to stderr.

```bash
make exec cmd="php bin/console app:settings:set transcode.max_bitrate 192 --json"
```

## app:settings:reset

Deletes the stored value, so the setting follows its default again, and prints the value it now has.

| Argument | Required | Description |
|----------|----------|-------------|
| `key` | Yes | Setting key, for example `metadata.auto_sync` |

Resetting a setting that has no stored value succeeds and changes nothing.

## Details

The commands change only server-wide settings. A user's own settings, such as their email language, are changed with [app:user:setting](app-user-setting.md).

Console access carries full authority, so the commands ask for no role. In the admin API, any administrator can read the settings, but only a super administrator can change or reset them (see [Server settings](../configuration.md#server-settings)).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The settings were listed or shown, or the value was saved or reset |
| 1 | Unknown setting key, invalid value, or another error (the message is printed) |
