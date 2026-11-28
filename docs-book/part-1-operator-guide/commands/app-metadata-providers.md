# app:metadata:providers

List the metadata providers with their enabled state, credential status and attribution. The command prints the data returned by `GET /api/admin/metadata/providers`, which the admin metadata page reads.

## Quick start

```bash
make exec cmd="php bin/console app:metadata:providers"
```

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print the providers exactly as the API's `data` array, in JSON |

## Details

The list contains MusicBrainz, Discogs, Last.fm, Spotify, TasteDive, CoverArtArchive, TheTVDB, TMDB and the local provider. Each JSON row carries `id`, `name`, `enabled`, `configured`, `attributionText` and `attributionUrl`.

| Column | Content |
|--------|---------|
| Provider | The provider's name |
| Enabled | Whether the server-wide switch is on |
| Configured | Whether the required credentials are set |
| Attribution | The provider attribution text |
| Link | The provider attribution URL |

Every switch defaults to on. Configured status is independent of the switch. MusicBrainz, CoverArtArchive and the local provider need no credentials. The other providers use `DISCOGS_TOKEN`, `LASTFM_API_KEY`, `SPOTIFY_CLIENT_ID` with `SPOTIFY_CLIENT_SECRET`, `TASTE_DIVE_API_KEY`, `TVDB_API_KEY` or `TMDB_BEARER_TOKEN`. Values are read from the running process's environment.

Use [app:metadata:provider:set](app-metadata-provider-set.md) to change a switch. The list and switch behavior are defined by the [provider administration service](../../../src/Metadata/Infrastructure/Admin/MetadataAdminService.php) and [provider definitions](../../../src/Metadata/Application/Settings/MetadataProvider.php).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | List printed |
| 1 | The providers could not be read and the message explains the failure |
