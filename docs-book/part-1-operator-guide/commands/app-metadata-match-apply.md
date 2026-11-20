# app:metadata:match:apply

Apply a MusicBrainz candidate to an album, song or artist: write its IDs, fields and artist credits, and merge or remove the artists those credits leave behind. The command does what `POST /api/admin/metadata/match/{type}/{publicId}/apply` does in the admin API. Take the candidate's ID from [app:metadata:match:search](app-metadata-match-search.md), and see what the match would change with [app:metadata:match:compare](app-metadata-match-compare.md) first.

## Quick start

The Make examples below target the checkout's development preview. For an
installed instance, use the `dc` function from
[Getting started](../getting-started/installation.md#command-context) and replace
`make exec cmd="php bin/console …"` with `dc exec app php bin/console …`.

Print the comparison and apply the rows it ticks by default, after confirming:

```bash
make exec cmd="php bin/console app:metadata:match:apply album <album-public-id> <release-mbid>"
```

Apply exactly the rows a selection names, without a terminal:

```bash
make exec cmd="php bin/console app:metadata:match:apply album <album-public-id> <release-mbid> --force --selection='{\"fields\":[{\"key\":\"year\",\"current\":2023}],\"credits\":[],\"artistActions\":[],\"keptArtists\":[]}'"
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
| `--selection` | the default ticks | The rows to apply, as the JSON object the API takes as its body, without the candidate |
| `--force` | off | Apply without asking; required when no terminal is attached |
| `--json` | off | Print the `data` payload of `POST /api/admin/metadata/match/{type}/{publicId}/apply` as JSON |

## Details

Without `--selection`, the command compares the item with the candidate, prints the comparison as `app:metadata:match:compare` does, and applies the rows marked `yes` in its Tick column, with the current values it just printed. Each merge or removal marked `yes` is applied; one marked `no` keeps its artist.

`--selection` names the rows as the web match dialog sends them, by the keys `app:metadata:match:compare` prints:

| Member | Content |
|--------|---------|
| `fields` | The field rows to write, each `{"key": "<key>", "current": <value>}` with the current value the comparison showed: text, a number, `true` for an album's cover, a list of genre names, or `null` for an empty field |
| `credits` | The credits rows to write, each `{"key": "credits"}` or `{"key": "<song-public-id>:credits"}` with `"current"`: the list of credits the row showed, each `{"artistPublicId": …, "role": …, "creditedName": …}` |
| `artistActions` | The public IDs of the artists whose listed merge or removal to apply |
| `keptArtists` | The public IDs of the artists whose listed merge or removal to leave out |

A member that is absent counts as an empty list, and other members, such as `candidate`, are ignored. Copy the values from `app:metadata:match:compare --json`: every row there has a `key` and a `current`, every credit has its `artistPublicId`, `role` and `creditedName`, and every listed artist action has its `artistPublicId`.

The apply looks the candidate up in MusicBrainz again and compares it with the item as it is stored. It writes nothing when a row no longer shows the current value it names, or when the match would merge or remove an artist the selection names in neither `artistActions` nor `keptArtists`; compare again and choose anew. The changes land together or not at all. A match can merge and remove artists, so the command asks before it writes; without a terminal it needs `--force`, and `--json` does not imply it.

An album apply that leaves the album with a release queues the album for automatic matching again when a song of the album has not been compared, such as one imported while the apply ran, but only while the `metadata.auto_sync` setting is on; see [Matching by hand](../media/musicbrainz-matching.md#matching-by-hand). An album or song apply that creates a credited artist with a MusicBrainz ID, or gives a stored artist its ID, queues the fill of that artist's details whatever the setting says; see [Artist details](../media/musicbrainz-matching.md#artist-details). An artist apply queues no fill. When a request cannot be queued, the error is logged and the match stays applied.

For a ticked `cover` row the apply downloads the release's front cover from the Cover Art Archive before it writes anything. When the Cover Art Archive refuses the download, with a client error other than 404, 408 or 429, the apply writes nothing and answers `cover_refused`. The default ticks include the cover of an album that has none, so the rest of the match is applied with a `--selection` that leaves the `cover` row out.

The command prints what it wrote: the item's own fields, whether its artist credits changed, how many of an album's songs took fields or credits, and the public IDs of the artists merged and removed. With `--json` the output is an object with `type`, `publicId`, `candidateId`, `fields`, `creditsChanged`, `songsChanged`, `mergedArtists` and `removedArtists`, exactly as the API returns it.

| Failure | API answer | Exit code |
|---------|------------|-----------|
| No album, song or artist has the public ID, or MusicBrainz has no such candidate, including an artist ID it merged into another artist | 404 | 1 |
| The item changed since the comparison, or a merge or removal is missing from the selection | 409, reason `match_stale` | 1 |
| Another change to the same songs or artists ran at the same time; applying again may succeed | 409, reason `match_busy` | 1 |
| MusicBrainz refused the request | 409, reason `musicbrainz_rejected` | 1 |
| The Cover Art Archive refused the cover the selection ticks; a selection without the `cover` row applies the rest | 409, reason `cover_refused` | 1 |
| The type, public ID, candidate ID or selection is malformed; the message lists each malformed entry | 422 | 2 |
| MusicBrainz or the Cover Art Archive did not answer, or no turn of the shared one-request-per-second budget came free within 10 seconds | 503, reason `musicbrainz_unavailable` | 1 |

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The match is applied |
| 1 | Nothing was written: the item or candidate is unknown, the item changed, the apply collided with another change, MusicBrainz refused or did not answer, the Cover Art Archive refused the cover, or the operator declined; the message says which |
| 2 | Nothing was written: the input is malformed, or no terminal is attached and `--force` was not given |
