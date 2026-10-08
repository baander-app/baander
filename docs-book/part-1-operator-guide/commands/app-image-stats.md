# app:image:stats

Show how many images Baander stores and how much space they use, by the type of their owner. The command shows what `GET /api/admin/media/storage-stats` returns, which the Storage tab of the admin media page reads.

## Quick start

```bash
make exec cmd="php bin/console app:image:stats"
```

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print the statistics exactly as the API's `data` object, in JSON |

## Details

The command prints a table with one row per owner type, such as `album` or `artist`, most images first:

| Column | Content |
|--------|---------|
| Type | The type of the images' owner |
| Images | The number of image records |
| Size | The stored size, readable and in bytes |

The last row, `Total`, counts every image record. The sizes are the ones recorded when the images were stored; the command does not read the files.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Statistics printed |
| 1 | The statistics could not be read; the message says why |
