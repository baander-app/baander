# app:tv:episode:register

Registers a selected episode under an existing TV series and imports its cast,
guest and crew credits. Registering the same season and episode again retains
its public ID. Season zero supports specials.
[Registration handler](../../../src/Catalog/Application/CommandHandler/Tv/RegisterTvEpisodeHandler.php),
[TV repository](../../../src/Catalog/Infrastructure/Doctrine/Repository/TvCatalogRepository.php).

## Usage

```bash
php bin/console app:tv:episode:register <series> <season> <episode> [--refresh-credits] [--json]
```

| Argument | Meaning |
| --- | --- |
| `series` | Public ID returned by [app:tv:series:register](app-tv-series-register.md) |
| `season` | Nonnegative season number |
| `episode` | Positive episode number |

Set `TMDB_BEARER_TOKEN` before running the command. The episode inherits its
series library. The lookup finishes before the database transaction starts,
and the inline operation appears in the job monitor.
[Console command](../../../src/Catalog/Interface/Console/TvEpisodeRegisterCommand.php),
[TV episode mapping](../../../src/Catalog/Infrastructure/Doctrine/Entity/TvEpisodeEntity.php).

## Options

| Option | Behavior |
| --- | --- |
| `--refresh-credits` | Replace each complete provider credit partition, including a successful empty result |
| `--json` | Print the episode public ID, name and job ID as JSON |

An absent or malformed credit partition leaves its stored rows in place.
The library metadata sync refreshes registered series and episodes.
[TV metadata parser](../../../src/Metadata/Infrastructure/Tv/TvMetadataProvider.php),
[TV library sync handler](../../../src/Metadata/Application/MessageHandler/SyncTvLibraryHandler.php).

## Results

Success exits with status 0. Invalid season or episode numbers exit with
status 2. A missing series or failed provider lookup exits with status 1.
[Registration handler](../../../src/Catalog/Application/CommandHandler/Tv/RegisterTvEpisodeHandler.php),
[Command error handling](../../../src/Shared/Interface/Console/AdminCommandSupport.php).
