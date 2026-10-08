# app:settings:definitions

List the definition of every setting: the server settings the admin **Settings** page edits and the per-user settings each user edits for themselves. The command shows what `GET /api/admin/settings/definitions` returns in the admin API. [app:settings](app-settings.md) covers reading and changing the server settings' values.

## Quick start

```bash
make exec cmd="php bin/console app:settings:definitions --scope=user"
```

## Options

| Option | Description |
|--------|-------------|
| `--scope` | `system` for the server settings only, `user` for the per-user settings only; every setting when omitted |
| `--json` | Print the definitions as the admin API returns them, as a JSON array on stdout |

## Details

The command prints a table with one row per setting:

| Column | Content |
|--------|---------|
| Key | The setting key, for example `transcode.max_bitrate` |
| Scope | `system` or `user` |
| Type | `boolean`, `integer`, `enum` or `string` |
| Default | The default value as a JSON literal, or `-` when the setting follows another one |
| Allowed | The allowed values, the allowed range, or `any` and the type |
| Follows | For a user setting, the server setting whose value applies while the user has not set it; otherwise `-` |
| Edit role | `ROLE_SUPER_ADMIN` for a server setting, `ROLE_USER` for a setting users edit themselves |
| Enforced | `yes` when Baander acts on the setting, `not yet` when nothing reads it yet |

With `--json` each definition also carries its label, description, settings-page group, the labels of its allowed values, and whether users can see its value. `--scope` filters the JSON output the same way.

The command is read-only.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Definitions printed |
| 2 | `--scope` is neither `system` nor `user` |
