# app:artist:update

Change an artist's metadata, or lock and unlock its fields. The command does what `PATCH /api/artists/{publicId}` does when an administrator edits an artist in the web app's metadata panel, through the same use case and with the same lock rules.

## Quick start

```bash
make exec cmd="php bin/console app:artist:update V1StGXR8_Z5jdHi6B-myT --sort-name='Beatles, The'"
```

Lock the name so metadata enrichment keeps it:

```bash
make exec cmd="php bin/console app:artist:update V1StGXR8_Z5jdHi6B-myT --lock=name"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `public-id` | Yes | The public ID of the artist |

## Options

| Option | Description |
|--------|-------------|
| `--name=NAME` | The new name; it cannot be empty |
| `--country=COUNTRY` | The country, such as `GB` |
| `--gender=GENDER` | The gender of a person |
| `--type=TYPE` | The artist type, such as `person` or `group` |
| `--disambiguation=TEXT` | A comment that tells artists of the same name apart |
| `--sort-name=NAME` | The name to sort by |
| `--biography=TEXT` | The biography |
| `--lock=FIELD` | Lock a field. Repeat the option or separate fields with commas |
| `--unlock=FIELD` | Unlock a field. Repeat the option or separate fields with commas |
| `--json` | Print only the result, as the API's `data` payload, in JSON |

## Details

An option that is left out keeps its current value; neither the command nor the API can clear a field.

The lockable fields are `name`, `country`, `gender`, `type`, `lifeSpanBegin`, `lifeSpanEnd`, `disambiguation`, `sortName` and `biography`. Neither the command nor the API edits the life span, but locking it stops metadata enrichment from changing it. A locked field keeps its value: enrichment skips it, and an edit that changes it is rejected with `Field "name" is locked and cannot be updated.` (the API answers `422` with the same message). An unknown field name in `--lock` or `--unlock` is rejected the same way.

Unlocks apply before the edit and locks after it, so one run can unlock a field and change it, or change a field and lock it. A field that was already locked rejects the change. When any change is rejected, nothing is saved.

The API takes the complete list of locked fields in `lockedFields`, replacing the current list. The command changes single fields instead and leaves the others as they are.

With `--json` the command prints only the updated artist as the API's `data` object, with `uuid`, `publicId`, `name`, `country`, `type`, `disambiguation`, `sortName` and `createdAt`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Artist updated |
| 1 | No artist has the public ID, or another error occurred; the message says why |
| 2 | The public ID is malformed, a value was rejected, a lock names an unknown field, or the edit changes a locked field |
