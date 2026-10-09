# app:album:update

Change an album's metadata, or lock and unlock its fields. The command does what `PATCH /api/albums/{publicId}` does when an administrator edits an album in the web app's metadata panel, through the same use case and with the same lock rules.

## Quick start

```bash
make exec cmd="php bin/console app:album:update V1StGXR8_Z5jdHi6B-myT --title='Abbey Road' --year=1969"
```

Unlock the title and change it in one run:

```bash
make exec cmd="php bin/console app:album:update V1StGXR8_Z5jdHi6B-myT --unlock=title --title='Abbey Road (Remastered)'"
```

Lock the label and the catalog number so enrichment and album merges keep them:

```bash
make exec cmd="php bin/console app:album:update V1StGXR8_Z5jdHi6B-myT --lock=label,catalogNumber"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `public-id` | Yes | The public ID of the album |

## Options

| Option | Description |
|--------|-------------|
| `--title=TITLE` | The new title; it cannot be empty |
| `--type=TYPE` | The new release type, such as `album`, `single` or `ep`; it cannot be empty |
| `--year=YEAR` | The new release year, as an integer |
| `--label=LABEL` | The new record label |
| `--catalog-number=NUMBER` | The new catalog number |
| `--barcode=BARCODE` | The new barcode |
| `--country=COUNTRY` | The new release country |
| `--language=LANGUAGE` | The new language |
| `--disambiguation=TEXT` | The new disambiguation comment |
| `--annotation=TEXT` | The new annotation |
| `--lock=FIELD` | Lock a field. Repeat the option or separate fields with commas |
| `--unlock=FIELD` | Unlock a field. Repeat the option or separate fields with commas |
| `--json` | Print only the result, as the API's `data` payload, in JSON |

## Details

An option that is left out keeps its current value; neither the command nor the API can clear a field.

The lockable fields are `title`, `type`, `year`, `label`, `catalogNumber`, `barcode`, `country`, `language`, `disambiguation` and `annotation`. A locked field keeps its value: metadata enrichment and album merges skip it, and an edit that changes it is rejected with `Field "label" is locked and cannot be updated.` (the API answers `422` with the same message). An unknown field name in `--lock` or `--unlock` is rejected the same way.

Unlocks apply before the edit and locks after it, so one run can unlock a field and change it, or change a field and lock it. A field that was already locked rejects the change. When any change is rejected, nothing is saved.

The API takes the complete list of locked fields in `lockedFields`, replacing the current list. The command changes single fields instead and leaves the others as they are.

With `--json` the command prints only the updated album as the API's `data` object, with `uuid`, `publicId`, `title`, `type`, `year`, `label`, `barcode`, `country` and `createdAt`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Album updated |
| 1 | No album has the public ID, or another error occurred; the message says why |
| 2 | The public ID is malformed, a value was rejected, a lock names an unknown field, or the edit changes a locked field |
