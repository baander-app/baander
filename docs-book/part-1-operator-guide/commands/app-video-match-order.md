# app:video:match:order

Select an episode ordering offered by the series' current metadata provider. Use its exact ordering identifier.

## Quick start

```bash
make exec cmd="php bin/console app:video:match:order <public-id> aired"
make exec cmd="php bin/console app:video:match:order <public-id> dvd --force --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `public-id` | Yes | Matched series public ID |
| `ordering` | Yes | Exact ordering ID offered for this series |

## Options

| Option | Description |
|--------|-------------|
| `--force` | Change ordering without asking, required without a terminal |
| `--json` | Print the series public ID and selected ordering |

## Details

`PUT /api/admin/video-matches/series/{publicId}/ordering` accepts an `ordering` string. The provider must be usable, the series must already be matched and the requested ordering must appear in that series' offered list. Available orderings depend on the provider entry. An unknown ordering is rejected. Changing ordering reruns file placement. Manual assignments remain protected.

## API and output

The command runs with full authority. Its admin API counterpart requires a first-party administrator. `--json` writes the API response's `data` object to stdout. Errors and confirmation prompts go to stderr.

The [command](../../../src/Catalog/Interface/Console/VideoMatchOrderingCommand.php) and [controller](../../../src/Catalog/Interface/Controller/VideoMatchController.php) use the same [video match administration service](../../../src/Catalog/Application/Service/VideoMatch/VideoMatchAdminService.php).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Operation completed |
| 1 | Target missing, conflicting state, provider unavailable, or confirmation declined |
| 2 | Invalid input, or a change needs confirmation without a terminal |
