# app:video:match:apply

Choose a provider candidate for a series or movie. The match becomes a manual choice, so an ordinary library rematch preserves it.

## Quick start

```bash
make exec cmd="php bin/console app:video:match:apply series <public-id> tvdb 42"
make exec cmd="php bin/console app:video:match:apply movie <public-id> tmdb 329865 --force --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `kind` | Yes | `series` or `movie` |
| `public-id` | Yes | Title public ID |
| `provider` | Yes | `tvdb`, `tmdb` or `local`, when usable for the title |
| `candidate-id` | Yes | Exact candidate ID from that provider |

## Options

| Option | Description |
|--------|-------------|
| `--force` | Apply without asking for confirmation, required without a terminal |
| `--json` | Print the selected provider, provider ID and ordering |

## Details

`POST /api/admin/video-matches/{kind}/{publicId}/apply` accepts `provider` and `candidateId`. A series match starts with the new provider entry's default ordering. Manually assigned files return to review when their match is replaced. Use [app:video:match:order](app-video-match-order.md) to select another offered ordering afterward. `--json` still requires confirmation or `--force`.

## API and output

The command runs with full authority. Its admin API counterpart requires a first-party administrator. `--json` writes the API response's `data` object to stdout. Errors and confirmation prompts go to stderr.

The [command](../../../src/Catalog/Interface/Console/VideoMatchApplyCommand.php) and [controller](../../../src/Catalog/Interface/Controller/VideoMatchController.php) use the same [video match administration service](../../../src/Catalog/Application/Service/VideoMatch/VideoMatchAdminService.php).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Operation completed |
| 1 | Target missing, conflicting state, provider unavailable, or confirmation declined |
| 2 | Invalid input, or a change needs confirmation without a terminal |
