# app:user:setting

Show, set or reset one user's settings, such as the language Baander emails them in. It is the command-line counterpart of the settings section in the admin user dialog and of the `/api/admin/users/{id}/settings` endpoints, and it runs the same application service, so the same validation and logging apply.

## Quick start

List a user's settings:

```bash
make exec cmd="php bin/console app:user:setting get alice@baander.app"
```

Email the user in Danish:

```bash
make exec cmd="php bin/console app:user:setting set alice@baander.app language da"
```

Let the user follow the server default again:

```bash
make exec cmd="php bin/console app:user:setting reset alice@baander.app language"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `action` | Yes | `get`, `set` or `reset` |
| `identifier` | Yes | The user's email address or UUID |
| `key` | For `set` and `reset` | Setting key, for example `language`. `get` without a key lists every setting |
| `value` | For `set` | New value, for example `da` |

## Details

**get** without a key prints a table of the user's settings: the key, the value Baander uses now, where that value comes from, and the stored choice. With a key it also shows the value the setting would have after a reset, the allowed values, and whether the user may change the setting themselves.

The source column tells you why the user gets the value they get:

| Source | Meaning |
|--------|---------|
| `user` | The user, or an administrator on their behalf, chose this value |
| `server_default` | No choice is stored, so the setting follows a server setting; `language` follows `i18n.default_language` |
| `default` | No choice is stored, and the setting uses its own default |

The stored column shows `(none)` when no choice is stored. A stored value that is no longer allowed, such as a language Baander stopped offering, is shown with `(invalid)`; the user gets the value after a reset until the setting is changed.

**set** parses the value against the setting's definition the same way as [app:settings:set](app-settings.md) and stores it as the user's choice. It works for every user setting, including any that users cannot change themselves. **reset** deletes the stored choice. Both print the new value and its source.

Every `set` and `reset` writes an info log entry with the actor, the target user's ID, the key, and the old and new stored values; a change made here is logged with the actor `cli`. Console access carries full authority, so the command asks for no role. In the admin API, an administrator can read a user's settings, and only a super administrator can change them.

Currently `language` is the only user setting. Its values are `en` (English), `da` (Dansk) and `th` (ไทย). See [Email language](../configuration.md#email-language) for how Baander picks the language of each email.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The settings were shown, or the choice was stored or removed |
| 1 | Unknown action, missing key or value, unknown user, unknown setting key, invalid value, or another error (the message is printed) |
