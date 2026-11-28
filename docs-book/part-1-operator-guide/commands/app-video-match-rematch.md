# app:video:match:rematch

Preview a library rematch before queueing it. The preview counts titles, manual matches and manually assigned files.

## Quick start

```bash
make exec cmd="php bin/console app:video:match:rematch television --json"
make exec cmd="php bin/console app:video:match:rematch television --apply"
make exec cmd="php bin/console app:video:match:rematch television --apply --force --include-hand-matched --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `library` | Yes | TV or movie library public ID or slug |

## Options

| Option | Description |
|--------|-------------|
| `--apply` | Ask for confirmation and queue eligible titles |
| `--include-hand-matched` | Include manual matches when applying |
| `--force` | Skip the confirmation prompt, required without a terminal |
| `--json` | Print the preview or queue result as JSON |

## Details

Without `--apply`, the command only previews. Before applying, it reports manual-match and manual-assignment counts on stderr. An ordinary apply skips manually matched titles. `--force` skips the prompt while preserving that selection rule. Add `--include-hand-matched` to include those titles. A replaced series match resets its ordering to the new entry's default and returns manually assigned files to review.

`GET /api/admin/video-matches/libraries/{library}/rematch-preview` returns the preview. `POST /api/admin/video-matches/libraries/{library}/rematch` accepts `confirmed` and `force` booleans. HTTP `force` corresponds to the CLI's `--include-hand-matched` selection. HTTP `confirmed` corresponds to confirming an `--apply` run. The result reports `queued` and `skipped` counts as well as the preview counts.

## API and output

The command runs with full authority. Its admin API counterpart requires a first-party administrator. `--json` writes the API response's `data` object to stdout. Errors and confirmation prompts go to stderr.

The [command](../../../src/Catalog/Interface/Console/VideoMatchRematchCommand.php) and [controller](../../../src/Catalog/Interface/Controller/VideoMatchController.php) use the same [video match administration service](../../../src/Catalog/Application/Service/VideoMatch/VideoMatchAdminService.php).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Operation completed |
| 1 | Target missing, conflicting state, provider unavailable, or confirmation declined |
| 2 | Invalid input, or a change needs confirmation without a terminal |
