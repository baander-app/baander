# app:radio:sync

Sync radio stations for every country a user subscribes to, from the active radio source. The command pulls station data per country and updates the local catalog.

## Quick start

Sync every subscribed country:

```bash
make exec cmd="php bin/console app:radio:sync"
```

Sync a single country:

```bash
make exec cmd="php bin/console app:radio:sync --country US"
```

Bootstrap the default IPRD source on a fresh install:

```bash
make exec cmd="php bin/console app:radio:sync --init"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--dry-run` | off | Show what would be synced without syncing |
| `--country` (`-c`) | — | Sync only a specific country code |
| `--init` | off | Create the default IPRD source if none exists |

## Details

The command selects the active radio source. If no source exists, it warns and exits unless `--init` is given, in which case it creates the default IPRD source (sync URL `https://iprd-org.github.io/iprd`, schedule `0 */6 * * *`).

Country selection, in priority order:
1. `--country` syncs that one country code, subscribed or not.
2. Otherwise the command syncs each country that at least one user subscribes to, once, in alphabetical order. When no user subscribes to a country, it prints a note and syncs nothing.

In dry-run mode the command lists the countries that would be synced, without syncing them. After a sync it reports the total number of stations synced.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Sync completed, or exited early: no source, no active source, no subscribed country, or a dry run |
| 1 | A country could not be synced; the message says why |

## Tips

- Run `--init` once on a fresh install to seed the IPRD source.
- Use `--dry-run` to preview scope before a full sync.
- Use `--country` to re-sync a single country after fixing a data issue.
- To sync a country nobody subscribes to, pass it with `--country`; [app:radio:country:list](app-radio-country-list.md) lists the codes.
