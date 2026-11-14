# app:metadata:match:reviews

List the albums that the automatic MusicBrainz matcher could not match with confidence and left for an admin to match. The most recently checked album comes first, and the list is what `GET /api/admin/metadata/match/reviews` returns in the admin API. Pass an album's ID to [app:metadata:match:search](app-metadata-match-search.md) to list its MusicBrainz candidates.

## Quick start

The Make examples below target the checkout's development preview. For an
installed instance, use the `dc` function from
[Getting started](../getting-started/installation.md#command-context) and replace
`make exec cmd="php bin/console …"` with `dc exec app php bin/console …`.

The first page of albums that need a review:

```bash
make exec cmd="php bin/console app:metadata:match:reviews"
```

The next page, with the cursor the previous page printed:

```bash
make exec cmd="php bin/console app:metadata:match:reviews --cursor=<cursor>"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--limit` | `50` | Albums per page, from 1 to 100 |
| `--cursor` | none | Continue from the cursor a previous page printed |
| `--json` | off | Print the `data` payload of `GET /api/admin/metadata/match/reviews` as JSON instead of a table |

## Details

The command prints one row per album:

| Column | Content |
|--------|---------|
| Album ID | The album's public ID |
| Title | The album's title |
| Reason | Why the matcher left the album for review, as the next table explains |
| Checked | When the matcher checked the album, in ISO 8601 format |
| Best score | The fit score of the best MusicBrainz release, from 0 to 100, or `-` when MusicBrainz returned none |

| Reason | Meaning |
|--------|---------|
| `no_candidates` | MusicBrainz returned no release for the album's title and artist |
| `low_score` | The best release fits the album too poorly to be trusted |
| `close_runner_up` | Another release fits almost as well as the best one, and their tracks disagree |
| `track_count_differs` | The best release has a different number of tracks from the album |
| `track_lengths_differ` | A track of the album pairs with no track of the best release within the length tolerance |

Albums checked at the same moment are ordered by their public ID. When more albums follow, the command ends with `More albums follow. Continue with --cursor=<cursor>`. Run it again with that cursor to get the next page, which repeats no album that an earlier page listed.

With `--json` the output is an object with `items`, `next_cursor`, `has_next_page` and `per_page`, exactly as the API returns it.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | List printed, possibly empty |
| 1 | The list could not be read; the message says why |
| 2 | `--limit` is not an integer from 1 to 100, or `--cursor` is not a cursor this command printed |
