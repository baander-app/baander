# app:dev:setup

Bootstrap the development environment: run migrations, generate OAuth keys, seed OAuth clients, and create dev users. This is a dev-only command that orchestrates the other setup commands as isolated subprocesses.

## Quick start

```bash
make exec cmd="php bin/console app:dev:setup"
```

Start from a clean database:

```bash
make exec cmd="php bin/console app:dev:setup --fresh"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--fresh`, `-f` | off (boolean flag) | Drop all tables and clear cache before setup |
| `--skip-keys`, `-k` | off (boolean flag) | Skip OAuth key generation |

## Details

The command runs these steps in order, each as a separate `php bin/console` subprocess:

1. If `--fresh`: `doctrine:schema:drop --force --full-database`, then the cache directory is removed.
2. `doctrine:migrations:migrate --no-interaction`
3. Unless `--skip-keys`: `app:oauth:generate-keys --no-interaction`
4. `app:auth:setup-clients --no-interaction`
5. `app:dev:create-users --no-interaction`

Subprocesses boot a fresh kernel, so they survive the cache clear performed by `--fresh` (which deletes compiled container classes the parent process has already loaded). A failed subprocess prints its error output but does not stop the overall run.

On success the command prints the dev account credentials (`admin@baander.test` / `admin`, `user@baander.test` / `user`).

## Tips

- Use `--fresh` only when you want a completely clean database — it drops every table.
- Pass `--skip-keys` if you already have OAuth keys and don't want them regenerated.
- Never run this in production.
