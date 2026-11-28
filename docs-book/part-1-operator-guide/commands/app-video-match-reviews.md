# app:video:match:reviews

Read the video match review queue, including file paths and provider pauses. Use the returned cursor to request the next page.

## Quick start

```bash
make exec cmd="php bin/console app:video:match:reviews --limit=25 --json"
make exec cmd="php bin/console app:video:match:reviews --kind=file --library=television --reason=episode_not_found"
```

## Options

| Option | Description |
|--------|-------------|
| `--limit` | Page size from 1 to 100, default 50 |
| `--cursor` | Opaque `nextCursor` returned by the preceding page |
| `--kind` | Filter by `series`, `movie` or `file` |
| `--library` | Filter by library public ID or slug |
| `--reason` | Filter by the stored review reason |
| `--json` | Print the review page and provider pauses as JSON |

## Details

`GET /api/admin/video-matches/reviews` accepts the corresponding query parameters. Reviews use checked time and a stable tie breaker for pagination. The JSON page contains `items`, `nextCursor`, `hasNextPage`, `perPage` and `pausedProviders`. Pauses report the provider, retry time and failure kind. Files use placement public IDs. A malformed cursor is rejected.

## API and output

The command runs with full authority. Its admin API counterpart requires a first-party administrator. `--json` writes the API response's `data` object to stdout. Errors and confirmation prompts go to stderr.

The [command](../../../src/Catalog/Interface/Console/VideoMatchReviewsCommand.php) and [controller](../../../src/Catalog/Interface/Controller/VideoMatchController.php) use the same [video match administration service](../../../src/Catalog/Application/Service/VideoMatch/VideoMatchAdminService.php).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Operation completed |
| 1 | Target missing, conflicting state, provider unavailable, or confirmation declined |
| 2 | Invalid input, or a change needs confirmation without a terminal |
