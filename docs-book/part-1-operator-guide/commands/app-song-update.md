# app:song:update

Change a song's metadata, or lock and unlock its fields. The command does what `PATCH /api/songs/{publicId}` does when an administrator edits a song in the web app's metadata panel, through the same use case and with the same lock rules.

## Quick start

```bash
make exec cmd="php bin/console app:song:update V1StGXR8_Z5jdHi6B-myT --title='Come Together' --track=1"
```

Mark a song as not explicit and lock the flag:

```bash
make exec cmd="php bin/console app:song:update V1StGXR8_Z5jdHi6B-myT --no-explicit"
make exec cmd="php bin/console app:song:update V1StGXR8_Z5jdHi6B-myT --lock=explicit"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `public-id` | Yes | The public ID of the song |

## Options

| Option | Description |
|--------|-------------|
| `--title=TITLE` | The new title; it cannot be empty |
| `--track=NUMBER` | The new track number, as an integer |
| `--disc=NUMBER` | The new disc number, as an integer |
| `--year=YEAR` | The new year, as an integer |
| `--comment=TEXT` | The new comment |
| `--lyrics=TEXT` | The new lyrics |
| `--explicit`, `--no-explicit` | Mark the song explicit, or not explicit |
| `--lock=FIELD` | Lock a field. Repeat the option or separate fields with commas |
| `--unlock=FIELD` | Unlock a field. Repeat the option or separate fields with commas |
| `--json` | Print only the result, as the API's `data` payload, in JSON |

## Details

An option that is left out keeps its current value; neither the command nor the API can clear a field.

The lockable fields are `title`, `track`, `disc`, `year`, `comment`, `lyrics` and `explicit`. A locked field keeps its value, and an edit that changes it is rejected with `Field "title" is locked and cannot be updated.` (the API answers `422` with the same message). An unknown field name in `--lock` or `--unlock` is rejected the same way.

Unlocks apply before the edit and locks after it, so one run can unlock a field and change it, or change a field and lock it. A field that was already locked rejects the change. When any change is rejected, nothing is saved.

The API takes the complete list of locked fields in `lockedFields`, replacing the current list. The command changes single fields instead and leaves the others as they are.

With `--json` the command prints only the updated song as the API's `data` object, including its artist and album names and its `lockedFields`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Song updated |
| 1 | No song has the public ID, or another error occurred; the message says why |
| 2 | The public ID is malformed, a value was rejected, a lock names an unknown field, or the edit changes a locked field |
