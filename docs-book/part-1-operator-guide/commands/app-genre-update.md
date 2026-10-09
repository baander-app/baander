# app:genre:update

Rename a genre, change its slug or MusicBrainz ID, or move it below another parent genre. The command does what `PATCH /api/genres/{slug}` does in the admin API, through the same use case and with the same hierarchy rule.

## Quick start

```bash
make exec cmd="php bin/console app:genre:update blues --name='Blues Rock' --slug=blues-rock"
```

Move a genre below another one:

```bash
make exec cmd="php bin/console app:genre:update grunge --parent=0192a3b4-c5d6-7890-abcd-ef1234567890"
```

Print the result as JSON, for a script:

```bash
make exec cmd="php bin/console app:genre:update hard-rock --name='Hard Rock' --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `slug` | Yes | The current slug of the genre |

## Options

| Option | Description |
|--------|-------------|
| `--name=NAME` | The new name |
| `--slug=SLUG` | The new slug: lowercase letters, digits and single hyphens |
| `--parent=UUID` | The UUID of the new parent genre, as [app:genre:list](app-genre-list.md) shows it |
| `--mbid=ID` | The new MusicBrainz ID |
| `--json` | Print only the result, as the admin API's `data` payload, in JSON |

## Details

An option that is left out keeps its current value. A parent cannot be removed with this command or the API; a genre becomes a root genre again only when its parent is deleted.

A new parent must be an existing genre, and it cannot be the genre itself or one of its descendants. Such a parent is rejected with `Cannot set parent: would create a circular reference.`, which the API answers with `422` and the same message. When any change is rejected, nothing is saved.

With `--json` the command prints only the updated genre as the API's `data` object, with `uuid`, `name`, `slug`, `parentId` and `mbid`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Genre updated |
| 1 | No genre has the slug, another genre has the new slug, or another error occurred; the message says why |
| 2 | A value or the parent was rejected; the message says why |
