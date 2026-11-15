# app:metadata:status

Show the metadata sync status: how many tracks have genres, the metadata sync jobs that failed, and the sync jobs by type. The command shows what `GET /api/admin/metadata/sync-status` returns in the admin API, which the statistics of the admin metadata page read.

## Quick start

```bash
make exec cmd="php bin/console app:metadata:status"
```

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print the status exactly as the API's `data` object, in JSON |

## Details

The metadata sync jobs are those that [app:metadata:sync](app-metadata-sync.md) and the admin page run, the library, album, song, genre and artist syncs they queue, and the artist fills a match or an import queues. The counts cover every such job the job monitor still holds.

| Metric | Content |
|--------|---------|
| Total tracks | All songs in the catalog |
| Tracks with genres | Songs linked to at least one genre |
| Pending tracks | Total tracks less those with genres and the failed sync jobs, at least 0 |
| Failed sync jobs | Metadata sync jobs that failed |
| Last sync | Creation time of the newest metadata sync job in ISO 8601, or `never` |

A second table lists the jobs by type, such as `SyncAlbumMessage`, with the number that finished and failed. A finished `SyncAlbumMessage` job has only requested the album's MusicBrainz match; the match itself runs in the album match queue as `DrainAlbumMatchesMessage` jobs, which these counts leave out. A `SyncArtistsMessage` job queued the artist fills of one metadata sync, and each `SyncArtistMessage` job is one artist's fill, whoever queued it. A fill counts as finished also when it wrote nothing, for example because MusicBrainz did not know the ID, refused the request or was unavailable; a fill queued again after an outage counts as a new job. When the monitor holds no metadata sync job, the command prints `No metadata sync jobs are recorded.` instead.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Status printed |
| 1 | The status could not be read; the message says why |
