# app:person:manage

Create or correct a Person profile, manage provider IDs, and reconcile identities
that represent the same person. The command uses the public IDs displayed in the
Person and Artist URLs.

| Action | Arguments and options |
| --- | --- |
| `create` | `--fields` with at least a `name`. |
| `edit` | Person public ID and `--fields` with the fields to change. |
| `release-field` | Person public ID and the profile field to return to routine enrichment. |
| `add-id`, `remove-id` | Person public ID, external ID and `--namespace`. |
| `link-artist`, `unlink-artist` | Person public ID and Artist public ID. |
| `conflicts` | Lists unresolved identifier claims with their current Person public IDs. |
| `merge` | Source Person public ID followed by the survivor's public ID. |

Use `tmdb_person`, `musicbrainz_artist`, `apple_music_artist`, or `imdb_name`
as the namespace. Only attach a MusicBrainz artist ID for an individual. A
removed identifier and an unlinked Artist stay detached during routine
enrichment. Add the identifier or link explicitly to restore it.

The profile JSON accepts `name`, `biography`, `aliases`, `birth_date`,
`death_date`, `is_deceased`, `birthplace`, `sex`, `gender`, `profile_url`,
and `image_id`. Use ISO calendar dates at their known precision, for example
`1986`, `1986-04`, or `1986-04-12`. Send `null` to clear an optional text,
date, or image field. Use `[]` to clear aliases. The URL accepts a TMDB
portrait. The image ID refers to a managed image. Edits take precedence over
routine enrichment. To let enrichment fill a field you deliberately cleared,
release it with `release-field`.

A merge moves credits, IDs and Artist links to the survivor. Old Person links
open the survivor within the reader's library access. If both profiles have
different operator-set values for a field, correct them first or pass
`--prefer-survivor` to keep the survivor's value.

`merge`, `remove-id`, and `unlink-artist` prompt for confirmation. Use
`--force` for non-interactive execution. The command returns JSON.
