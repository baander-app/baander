# app:recommendation:stats

Show how much of the library has recommendations, where they come from and how old they are. It is the shell counterpart of the statistics on the admin Recommendations page, which come from `GET /api/admin/recommendations/coverage`, `/source-quality` and `/freshness`.

## Quick start

```bash
make exec cmd="php bin/console app:recommendation:stats"
```

## Options

| Option | Description |
|--------|-------------|
| `--json` | Print the three API `data` payloads as one JSON object, under `coverage`, `source_quality` and `freshness` |

## Details

The command prints three sections:

| Section | Content |
|---------|---------|
| Coverage | Tracks in the library, tracks with and without recommendations, and the share with them |
| Source quality | The average confidence score, and the number of recommendations per source type |
| Freshness | The average age of the stored recommendations in seconds, and when the newest was generated |

The command only reads. It shows the whole library, whatever libraries an administrator can see on the web.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Statistics printed |
| 1 | The statistics could not be read; the message says why |
