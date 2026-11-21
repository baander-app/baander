# app:tv:series:register

Registers a TV series in a TV show library using its confirmed TMDB series ID.
It imports the series title and aggregate cast and crew credits. Registering the
same series again updates the existing record and retains its public ID.
[Registration handler](../../../src/Catalog/Application/CommandHandler/Tv/RegisterTvSeriesHandler.php),
[TV repository](../../../src/Catalog/Infrastructure/Doctrine/Repository/TvCatalogRepository.php).

## Usage

```bash
php bin/console app:tv:series:register <library> <tmdb-id> [--refresh-credits] [--json]
```

| Argument | Meaning |
| --- | --- |
| `library` | Internal UUID of an existing TV show library |
| `tmdb-id` | Confirmed positive TMDB series ID |

Set `TMDB_BEARER_TOKEN` before running the command. The lookup finishes before the
database transaction starts. The command runs inline and records its job in the
job monitor. [Console command](../../../src/Catalog/Interface/Console/TvSeriesRegisterCommand.php),
[TMDB adapter](../../../src/Metadata/Infrastructure/Api/Tmdb/TmdbAdapter.php).

## Options

| Option | Behavior |
| --- | --- |
| `--refresh-credits` | Replace each complete provider credit partition, including a successful empty result |
| `--json` | Print the series public ID, name and job ID as JSON |

An absent or malformed credit partition leaves its stored rows in place.
Register selected episodes separately with
[app:tv:episode:register](app-tv-episode-register.md).
[Person credit writer](../../../src/Catalog/Infrastructure/Doctrine/Repository/PersonCreditRepository.php),
[TV metadata parser](../../../src/Metadata/Infrastructure/Tv/TvMetadataProvider.php).

## Results

Success exits with status 0. An invalid ID or a library with another media type
exits with status 2. A missing library or failed provider lookup exits with
status 1. [Registration handler](../../../src/Catalog/Application/CommandHandler/Tv/RegisterTvSeriesHandler.php),
[Command error handling](../../../src/Shared/Interface/Console/AdminCommandSupport.php).
