# app:metadata:providers

List the external metadata providers and whether each one is configured. The command shows what `GET /api/admin/metadata/providers` returns in the admin API, which the provider list of the admin metadata page reads.

## Quick start

```bash
make exec cmd="php bin/console app:metadata:providers"
```

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print the providers exactly as the API's `data` array, in JSON |

## Details

The command prints one row per provider: MusicBrainz, Discogs, Last.fm, Spotify, TasteDive and CoverArtArchive.

| Column | Content |
|--------|---------|
| Provider | The provider's name |
| Enabled | `yes` for every provider |
| Configured | `yes` when the provider's credentials are set in the environment; MusicBrainz and CoverArtArchive need none |

The credentials are `DISCOGS_TOKEN`, `LASTFM_API_KEY`, `SPOTIFY_CLIENT_ID` with `SPOTIFY_CLIENT_SECRET`, and `TASTE_DIVE_API_KEY`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | List printed |
| 1 | The providers could not be read; the message says why |
