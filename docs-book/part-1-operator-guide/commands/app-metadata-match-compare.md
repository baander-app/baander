# app:metadata:match:compare

Compare an album, song or artist with a MusicBrainz candidate and print what a match would set, row by row, before anything is written. The comparison is what `GET /api/admin/metadata/match/{type}/{publicId}/comparison?candidate=<id>` returns in the admin API. Take the candidate's ID from [app:metadata:match:search](app-metadata-match-search.md). The command changes nothing.

## Quick start

The Make examples below target the checkout's development preview. For an
installed instance, use the `dc` function from
[Getting started](../getting-started/installation.md#command-context) and replace
`make exec cmd="php bin/console …"` with `dc exec app php bin/console …`.

Compare an album with a release:

```bash
make exec cmd="php bin/console app:metadata:match:compare album <album-public-id> <release-mbid>"
```

Print the comparison as JSON, for a script:

```bash
make exec cmd="php bin/console app:metadata:match:compare album <album-public-id> <release-mbid> --json"
```

## Arguments

| Argument | Description |
|----------|-------------|
| `type` | What to match: `album`, `song` or `artist` |
| `public-id` | The public ID of the album, song or artist |
| `candidate` | The candidate's MusicBrainz ID: a release for an album, a recording for a song, an artist for an artist |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--json` | off | Print the `data` payload of `GET /api/admin/metadata/match/{type}/{publicId}/comparison` as JSON instead of tables |

## Details

The command looks the candidate up in MusicBrainz and prints up to three tables.

The first table lists every row the match could write: the item's own fields and artist credits, then for an album each paired song's fields and credits. A field MusicBrainz gives no value for has no row.

| Column | Content |
|--------|---------|
| Row | The row's key, which names the row when the match is applied |
| Current | The value the item holds now, or `-` when the field is empty |
| MusicBrainz | The value the match would set |
| Tick | Whether the row is ticked by default, as the next table explains |

A key is the field name for a row of the item itself, such as `title`, `year` or `releaseMbid`, and `credits` for its artist credits. A row of one of an album's songs carries the song's public ID in front, such as `<song-public-id>:title` or `<song-public-id>:credits`.

| Tick | Meaning |
|------|---------|
| `yes` | Ticked by default: a MusicBrainz ID, a field that is empty, or credits that would change |
| `no` | Not ticked by default: the field holds another value |
| `locked` | The field is locked and cannot be ticked |
| `same` | The row would change nothing |

The rows by type:

| Type | Rows |
|------|------|
| `album` | `releaseMbid` (the release), `mbid` (its release group), `title`, `year`, `label`, `catalogNumber`, `barcode`, `country`, `disambiguation`, `genres`, `cover` and `credits`; for each paired song `mbid` (the recording), `title`, `track`, `disc`, `year` and `credits` |
| `song` | `mbid` (the recording), `title` and `credits`. A song match never touches the song's album |
| `artist` | `mbid`, `name`, `sortName`, `type`, `country`, `disambiguation`, `lifeSpanBegin` and `lifeSpanEnd` |

The `cover` row shows `yes` under Current when the album has a cover, and under MusicBrainz the release whose front cover the match would store. A credit shows the artist and its role, such as `Post Malone (primary)`, and `as "<name>"` when the item is credited under another name. A credit the match would set notes `new artist` when no library artist holds or can take on the MusicBrainz artist ID, and `takes the ID` when a library artist of that name, which has no MusicBrainz ID yet, would take the ID on.

For an album, the second table pairs the release's tracks with the album's songs: each release track by disc and number with its length, next to the song paired with it, its public ID and its length. A release track no song pairs with, or a song that pairs with no track, shows `-` on the other side. Only paired songs are written.

The last table lists the artists the match would merge into another artist or remove, with their public IDs. An artist that the ticked credits leave with no credit merges into the artist that takes over its primary credits, or is removed when no artist does. Needs ticked lists the keys of the credits rows the action stands on; unticking any of them, or the action itself, keeps the artist as it is. When another library artist already holds the candidate's MusicBrainz ID, an artist comparison has no field rows and lists one merge into that artist.

With `--json` the output is the comparison object with `type`, `publicId`, `candidateId`, `fields`, `credits`, `tracks` and `artistActions`, exactly as the API returns it.

When MusicBrainz cannot be reached, answers a server error, or no turn of the shared one-request-per-second budget comes free within 10 seconds, the command reports that MusicBrainz is unavailable and exits with 1. The API answers 503 with the reason `musicbrainz_unavailable`. When MusicBrainz refuses the request, the API answers 409 with the reason `musicbrainz_rejected`, and the command exits with 1.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The comparison was printed |
| 1 | No album, song or artist has the public ID, MusicBrainz has no such candidate, or MusicBrainz is unavailable or refused the request; the message says which |
| 2 | The type is not `album`, `song` or `artist`, or the public ID or the candidate's MusicBrainz ID is malformed |
