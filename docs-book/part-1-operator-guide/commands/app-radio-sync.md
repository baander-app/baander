# app:radio:sync

Sync radio stations for all subscribed countries from the active radio source. Pulls station data per country and updates the local catalogue.

## Quick start

Sync all subscribed countries:

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
1. `--country` filters to a single country code.
2. Otherwise, user subscriptions are used. If there are none, the command falls back to all countries the source advertises via `fetchCountries()`.

In dry-run mode the command lists the countries that would be synced per source without contacting the station sync adapter. The total station count is reported at the end.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Sync completed, or exited early (no source / no active source / dry run) |

## Tips

- Run `--init` once on a fresh install to seed the IPRD source.
- Use `--dry-run` to preview scope before a full sync.
- Use `--country` to re-sync a single country after fixing a data issue.
- With no subscriptions present, the command syncs every country the source offers — this can be large.
