# app:video:match:search

Search metadata providers for a series or movie, or list the current title’s ordering and file-assignment choices. Provider problems remain visible alongside the choices available locally.

## Quick start

```bash
make exec cmd="php bin/console app:video:match:search series <public-id> --json"
make exec cmd="php bin/console app:video:match:search movie <public-id> --query='Arrival'"
make exec cmd="php bin/console app:video:match:search series <public-id> --view=choices --collection=orderings --json"
make exec cmd="php bin/console app:video:match:search series <public-id> --view=choices --collection=files --page=2 --limit=50 --json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `kind` | Yes | `series` or `movie` |
| `public-id` | Yes | Title public ID |

## Options

| Option | Description |
|--------|-------------|
| `--query` | Search title, replacing the stored title for this request |
| `--view` | `search` (default) or `choices` for the existing title |
| `--collection` | Choices to return: `all` (default), `orderings`, `episodes` or `files` |
| `--page` | Choices page, starting at 1; default 1 |
| `--limit` | Choices per page, from 1 to 100; default 50 |
| `--json` | Print the API response data as JSON |

## Details

`GET /api/admin/video-matches/{kind}/{publicId}/candidates` accepts an optional `q` query parameter. Each candidate includes its provider ID, title, year and external IDs. Disabled providers and providers without credentials are excluded. A failed provider does not discard another provider's results.

With `view=choices`, the same endpoint returns the current ordering and the matched provider’s available ordering IDs and labels. Choose the exact `id` when changing an ordering; labels can share an ordering kind. An unavailable provider appears in `orderingProblem`, while local episode and file choices remain available.

Episode choices contain canonical public IDs, displayed season and episode numbers, absolute numbers and air dates. File choices contain placement public IDs, paths and current episode public IDs or extra kinds. Each requested local collection includes `items`, `total`, `page`, `perPage` and `hasNextPage`. Collections omitted by `collection` are null. `collection=episodes` and `collection=files` read local data without asking the provider for its episode structure. Load `collection=orderings` separately when paging through local choices. The [administration service](../../../src/Catalog/Application/Service/VideoMatch/VideoMatchAdminService.php) defines these views.

## API and output

The command runs with full authority. Its admin API counterpart requires a first-party administrator. `--json` writes the API response's `data` object to stdout. Errors and confirmation prompts go to stderr.

The [command](../../../src/Catalog/Interface/Console/VideoMatchSearchCommand.php) and [controller](../../../src/Catalog/Interface/Controller/VideoMatchController.php) use the same [video match administration service](../../../src/Catalog/Application/Service/VideoMatch/VideoMatchAdminService.php).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Operation completed |
| 1 | Target missing, conflicting state, provider unavailable, or confirmation declined |
| 2 | Invalid input, or a change needs confirmation without a terminal |
