# External APIs

Baander enriches your media library by querying external metadata services during library scans. No API key is strictly required -- the application works without them -- but metadata quality depends on which services are configured.

## Supported APIs

| API | Role | Env Vars | What It Provides |
|-----|------|----------|------------------|
| MusicBrainz | Primary (required) | `MUSICBRAINZ_APP_NAME`, `MUSICBRAINZ_VERSION`, `MUSICBRAINZ_CONTACT` | Artist names, MBIDs, release groups, recordings, tags |
| Discogs | Fallback | `DISCOGS_TOKEN` | Release info, genres, styles, cover art URLs |
| Last.fm | Optional | `LASTFM_API_KEY`, `LASTFM_API_SECRET` | Artist bios, similar artists, tags, top tracks |
| Spotify | Integrated, unused | `SPOTIFY_CLIENT_ID`, `SPOTIFY_CLIENT_SECRET` | Not currently consumed by any service |
| TasteDive | Integrated, unused | `TASTE_DIVE_API_KEY` | Not currently consumed by any service |

For full environment variable details including defaults, see [Configuration](configuration.md).

## Degradation Matrix

The table below shows what each API contributes and what you lose when its credentials are missing.

| Missing API | Impact |
|-------------|--------|
| **MusicBrainz** | No artist or release matching. This is the primary enrichment source -- without it, most metadata fields remain empty and fallback to Discogs is limited. |
| **Discogs** | No fallback release info, genres, styles, or cover art when MusicBrainz returns no results. |
| **Last.fm** | No artist biographies, similar-artist suggestions, or top-track lists. Music and release metadata are unaffected. |
| **Spotify** | No impact. The adapter is wired but no service currently queries it. |
| **TasteDive** | No impact. The adapter is wired but no service currently queries it. |

## How Enrichment Works

Metadata enrichment runs as part of the library scan pipeline. When Baander processes a media file during a scan, it queries external services in this order:

1. **MusicBrainz** is queried first. It provides structured identifiers (MBIDs), release groups, and canonical artist/release data. This is the authoritative source for music metadata.

2. **Discogs** acts as a fallback. If MusicBrainz returns no match or incomplete data, Baander queries Discogs for release information, genre/style tags, and cover art URLs.

3. **Last.fm** supplements the results. When its API key is configured, Baander fetches artist biographies, similar artists, tags, and top tracks from Last.fm regardless of whether MusicBrainz or Discogs returned results.

Enrichment results are stored in the database and served to clients on subsequent requests. Re-scanning a library refreshes metadata from all configured services.

For details on how scans are triggered, see [Library Management](library-management.md).

## Configuration

Set the environment variables listed above in your `.env` file or deployment configuration. MusicBrainz requires an app name, version, and contact email (these identify your Baander instance to the MusicBrainz API per their terms of service). The rest are standard API keys.

See [Configuration](configuration.md) for the full variable reference with defaults.

## Getting API Keys

Each service requires registration on its own platform:

- **MusicBrainz** -- Create an account at [musicbrainz.org](https://musicbrainz.org), then set `MUSICBRAINZ_APP_NAME` to your instance name, `MUSICBRAINZ_VERSION` to your Baander version, and `MUSICBRAINZ_CONTACT` to an email address where MusicBrainz can reach you.
- **Discogs** -- Generate a personal access token at [discogs.com/settings/developers](https://www.discogs.com/settings/developers).
- **Last.fm** -- Register an API application at [last.fm/api/account/create](https://www.last.fm/api/account/create). You will receive both an API key and a shared secret.
- **Spotify** -- Create an app in the [Spotify Developer Dashboard](https://developer.spotify.com/dashboard). Not needed unless a future release activates the Spotify adapter.
- **TasteDive** -- Request an API key at [tastedive.com/read_api](https://tastedive.com/read_api). Not needed unless a future release activates the TasteDive adapter.
