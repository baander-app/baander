# app:metadata:match:search

List the MusicBrainz candidates for an album, song or artist, best first, each with a fit score from 0 to 100. The list is what `GET /api/admin/metadata/match/{type}/{publicId}/candidates` returns in the admin API. Pass a candidate's ID to [app:metadata:match:compare](app-metadata-match-compare.md) to see what matching it would change.

## Quick start

The Make examples below target the checkout's development preview. For an
installed instance, use the `dc` function from
[Getting started](../getting-started/installation.md#command-context) and replace
`make exec cmd="php bin/console …"` with `dc exec app php bin/console …`.

The releases an album may match, searched by the album's own title and primary artist:

```bash
make exec cmd="php bin/console app:metadata:match:search album <album-public-id>"
```

Search again with an edited title and artist when the right release is not listed:

```bash
make exec cmd="php bin/console app:metadata:match:search album <album-public-id> --title='F-1 Trillion' --artist='Post Malone'"
```

Search by the title alone, for a compilation or an album tagged with the wrong artist:

```bash
make exec cmd="php bin/console app:metadata:match:search album <album-public-id> --no-artist"
```

## Arguments

| Argument | Description |
|----------|-------------|
| `type` | What to match: `album`, `song` or `artist` |
| `public-id` | The public ID of the album, song or artist |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--title` | the item's own | Search this title, or for an artist this name, instead of the item's own |
| `--artist` | the item's primary artist | Search this artist instead of the item's primary artist; an artist search ignores it |
| `--no-artist` | off | Search by the title alone, without an artist; cannot be combined with `--artist` |
| `--json` | off | Print the `data` payload of `GET /api/admin/metadata/match/{type}/{publicId}/candidates` as JSON instead of a table |

## Details

An album matches a release, which is one edition of the album. A song matches a recording, and an artist matches an artist. The command lists at most five candidates, the best fit first:

| Type | How the score is made | Columns |
|------|-----------------------|---------|
| `album` | Title and artist similarity, and whether the track count and each track's length agree with the album's songs | ID, Score, Title, Artist, Date, Country, Label, Format, Tracks, Cover |
| `song` | Title and artist similarity, and the length | ID, Score, Title, Artist, Length, Releases |
| `artist` | Name similarity, and the disambiguation when the library artist has one | ID, Score, Name, Disambiguation, Type, Country, Active |

The ID column holds the candidate's MusicBrainz ID. A title is followed by MusicBrainz's disambiguation in parentheses when it has one, such as `(clean)`. Cover says whether the Cover Art Archive holds a front cover for the release. Releases names the first three releases a recording appears on and counts the rest. Active gives the artist's life span, such as `1985-09-12 to now`. After the table, the command prints the `app:metadata:match:compare` call that takes a candidate's ID.

An album search asks MusicBrainz for 25 releases, ranks them by title, artist and track count, and looks up the five best with their tracks. Every process shares one budget of one MusicBrainz request per second, so an album search takes several seconds. A blank `--title` counts as not given. `--no-artist`, or a blank `--artist`, searches by the title alone, as the API does for an empty `artist` parameter; the score still compares each candidate with the item's own artist.

When MusicBrainz has no candidate, the command says so and exits with 0, as the API answers 200 with an empty list.

When MusicBrainz cannot be reached, answers a server error, or no turn of the shared budget comes free within 10 seconds, the command reports that MusicBrainz is unavailable and exits with 1. The API answers 503 with the reason `musicbrainz_unavailable`. When MusicBrainz refuses the request, the API answers 409 with the reason `musicbrainz_rejected`, and the command exits with 1.

With `--json` the output is an object with `type`, `publicId`, the `title` and `artist` searched, and `candidates`, exactly as the API returns it.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | The search ran; the table lists the candidates, possibly none |
| 1 | No album, song or artist has the public ID, or MusicBrainz is unavailable or refused the request; the message says which |
| 2 | The type is not `album`, `song` or `artist`, the public ID is malformed, or `--artist` and `--no-artist` were both given |
