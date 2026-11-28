# app:video:providers:order

Read a TV or movie library's metadata provider order, or replace it with a complete order. Identify the library by its public ID or slug.

## Quick start

```bash
make exec cmd="php bin/console app:video:providers:order television"
make exec cmd="php bin/console app:video:providers:order television --providers=tmdb,local,tvdb --json"
make exec cmd="php bin/console app:video:providers:order films --providers=local,tmdb"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `library` | Yes | Public ID or slug of a TV or movie library |

## Options

| Option | Description |
|--------|-------------|
| `--providers` | Replace the order with a comma-separated list containing every supported provider exactly once |
| `--json` | Print the order as a JSON object containing `providers`, matching the API's `data` object |

## Details

Omit `--providers` to read the saved order or the library's default. TV libraries default to `tvdb,tmdb,local`, and movie libraries to `tmdb,local`. Table rows and JSON array entries appear in priority order.

Supply `--providers` to replace the whole order. A movie library accepts `local,tmdb` or `tmdb,local`. A TV library accepts any permutation of `tvdb`, `tmdb` and `local`. An incomplete list, a duplicate or an unsupported provider is rejected. Switched-off providers and providers without configured credentials keep their places in the order.

The admin API reads the same order with `GET /api/admin/video-matches/libraries/{library}/provider-order` and replaces it with `PUT` on that path. The replacement body contains a `providers` array. Both routes require an administrator. The console command runs with full authority.

The [command](../../../src/Catalog/Interface/Console/VideoProviderOrderCommand.php) and [controller](../../../src/Catalog/Interface/Controller/VideoMatchController.php) call the same [provider-order service](../../../src/Catalog/Application/Service/VideoMatch/LibraryProviderOrderService.php), which validates the complete list against the providers serving the library's type.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Provider order read or saved |
| 1 | Library not found, or the order could not be read or saved |
| 2 | Library type or replacement order is invalid |
