# app:metadata:provider:set

Switch a metadata provider on or off for the whole server. The command and the admin provider switch write the same `metadata.provider.<id>.enabled` setting.

## Quick start

```bash
make exec cmd="php bin/console app:metadata:provider:set discogs false"
make exec cmd="php bin/console app:metadata:provider:set discogs true --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `provider` | Yes | One of `musicbrainz`, `discogs`, `lastfm`, `spotify`, `tastedive`, `coverartarchive`, `tvdb`, `tmdb` or `local` |
| `enabled` | Yes | `true` to switch on or `false` to switch off |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print the updated provider row as the API's `data` object |

## Details

Switches default to on. A disabled provider stops new lookups and keeps the metadata it already supplied. Switching a provider on uses the credentials available to the running process. Use [app:metadata:providers](app-metadata-providers.md) to inspect both states.

The HTTP counterpart is `PATCH /api/admin/metadata/providers/{provider}` with a JSON body containing `enabled` as a boolean. An administrator may call this route. The command also accepts the provider ID and an enabled value, and reports the updated row. General system setting writes through `app:settings:set metadata.provider.discogs.enabled false` update the same value.

The [console command](../../../src/Metadata/Interface/Console/MetadataProviderSetCommand.php) and [provider controller](../../../src/Metadata/Interface/Controller/MetadataAdminController.php) dispatch the shared system-settings update. The [provider switch reader](../../../src/Metadata/Application/Settings/MetadataProviderSwitches.php) reads its effective value for each check.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Switch saved and updated provider reported |
| 1 | The setting could not be saved or read and the message explains the failure |
| 2 | Unknown provider or an enabled argument other than `true` or `false` |
