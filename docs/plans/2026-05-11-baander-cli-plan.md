# Plan: Baander CLI

## Problem summary

Running Symfony console commands from the host requires `make exec cmd="php bin/console ..."` — verbose, no tab completion, quoting friction. Docker lifecycle commands are Make-only. The approved brainstorm (`docs/brainstorms/2026-05-11-console-dx-requirements.md`) specifies a Bun + TypeScript CLI compiled to a single binary, with a two-stage command generator (PHP manifest → Bun consumer).

## Relevant learnings

No prior solutions in `docs/solutions/`. This is a greenfield CLI project.

## Scope boundaries

**In scope:**
- PHP `app:cli:manifest` command that introspects Symfony console, phpstan, deptrac, composer, phpunit/paratest
- Bun + TypeScript CLI source in `cli/`
- All 21 subcommands (console, php, composer, phpunit, paratest, stan, deptrac, migrate, migrate:dev, logs, ssh, ssh:root, build, build:prod, build:clean, start, stop, down, restart, restart:app, manifest)
- Config via XDG (`~/.config/baander/config.json`)
- Manifest caching with lazy regeneration (`~/.cache/baander/manifest.json`)
- TTY auto-detection with `--tty`/`--no-tty` overrides
- Compiled binary output to `bin/baander`
- Shell tab completion for `baander console <cmd>`

**Out of scope:**
- Replacing the Makefile entirely (backward compat)
- GUI / TUI
- CI/CD pipeline integration (separate concern)
- Auto-update mechanism for the binary

## Implementation units

### Unit 1: CLI skeleton — project scaffold, build pipeline, command router

**Goal:** Working `baander` binary that shows help, routes subcommands, and has a build pipeline.

**Files:**
- `cli/package.json` (create)
- `cli/tsconfig.json` (create)
- `cli/src/index.ts` (create)
- `cli/src/lib/docker.ts` (create)
- `cli/src/lib/errors.ts` (create)
- `cli/src/lib/output.ts` (create)
- `cli/src/config.ts` (create)

**Patterns to follow:**
- Standard Bun project structure with `bun build --compile` for binary output
- Argparse-style CLI routing (Bun supports Node.js `commander` or native args parsing)

**Test scenarios:**
- `baander` with no args → shows help menu listing all subcommands
- `baander --help` → same as no args
- `baander --version` → shows version from package.json
- `baander nonexistent` → error with "unknown command" message, exit 1
- `baander help console` → shows console subcommand help
- Build command `bun run build` produces `../bin/baander` binary
- Binary runs without Bun installed

**Verification:**
```bash
cd cli && bun install && bun run build
./bin/baander --help
./bin/baander --version
./bin/baander nonexistent  # exit 1
```

**Dependencies:** None (first unit).

---

### Unit 2: Docker spawn helper — env passthrough, TTY detection, signal forwarding

**Goal:** Shared `docker.ts` module that all container-proxy commands use. Handles env vars from `.env`, TTY auto-detection, signal forwarding (SIGINT/SIGTERM), and proper exit codes.

**Files:**
- `cli/src/lib/docker.ts` (modify)
- `cli/src/lib/errors.ts` (modify)

**Patterns to follow:**
- The Makefile currently passes: `HOST_UID`, `HOST_GID`, `WEB_PORT_HTTP`, `WEB_PORT_SSL`, `XDEBUG_CONFIG`, `XDEBUG_VERSION`, `REDIS_PASSWORD` via docker compose env
- `.env` file at repo root contains project env vars
- `docker compose exec` for running commands in existing container
- `docker compose` for lifecycle commands (no `exec`)

**Test scenarios:**
- Spawns `docker compose exec app php -v` with correct env vars
- Detects `process.stdout.isTTY` and passes `-t` flag to docker compose exec accordingly
- `--tty` flag forces TTY allocation
- `--no-tty` flag disables TTY allocation
- SIGINT (Ctrl+C) forwarded to child process
- Non-zero exit code from container propagated to host
- Error when containers not running → clear message "Containers not running. Run `baander start` first."
- Error when docker compose not found → clear message

**Verification:**
```bash
./bin/baander php -v         # should print PHP version
./bin/baander php -v --no-tty  # should work without TTY
echo "test" | ./bin/baander php -v  # auto-detects non-TTY
```

**Dependencies:** Unit 1.

---

### Unit 3: Container proxy commands — console, php, composer, ssh, ssh:root

**Goal:** Core passthrough commands that proxy into the `baander-app` container.

**Files:**
- `cli/src/commands/console.ts` (create)
- `cli/src/commands/php.ts` (create)
- `cli/src/commands/composer.ts` (create)
- `cli/src/commands/ssh.ts` (create)

**Patterns to follow:**
- `console` runs `docker compose exec app php bin/console <args...>` as www-data
- `php` runs `docker compose exec app php <args...>` as www-data
- `composer` runs `docker compose exec app composer <args...>` as www-data
- `ssh` runs `docker compose exec -t app bash` (interactive, as www-data)
- `ssh:root` runs `docker compose exec -t app bash` (interactive, as root)
- Aliases: `c` → `console`, `comp` → `composer`

**Test scenarios:**
- `baander console doctrine:schema:validate` → runs command, shows output
- `baander c doctrine:schema:validate` → alias works identically
- `baander console --help` → shows Symfony console help (proxied)
- `baander php -v` → shows PHP version
- `baander composer --version` → shows composer version
- `baander ssh` → opens interactive bash (manual test)
- `baander console nonexistent:command` → propagates error from container

**Verification:**
```bash
./bin/baander console doctrine:schema:validate
./bin/baander c doctrine:schema:validate
./bin/baander php -v
./bin/baander comp --version
```

**Dependencies:** Unit 2.

---

### Unit 4: Tooling commands — phpunit, paratest, stan, deptrac

**Goal:** Commands for PHP tooling with sensible defaults matching current Makefile behavior.

**Files:**
- `cli/src/commands/phpunit.ts` (create)
- `cli/src/commands/paratest.ts` (create)
- `cli/src/commands/stan.ts` (create)
- `cli/src/commands/deptrac.ts` (create)

**Patterns to follow:**
- `phpunit`: runs `./vendor/bin/phpunit -c phpunit.xml $(args)` (same as `make phpunit` but without coverage flags by default)
  - `--coverage` flag adds `--coverage-html reports/coverage --coverage-clover reports/clover.xml --log-junit reports/junit.xml`
  - Alias: `test`
- `paratest`: runs `./vendor/bin/paratest --processes auto --tmp-dir var $(args)`
  - Alias: `pt`
- `stan`: runs `XDEBUG_MODE=off php ./vendor/bin/phpstan analyse --memory-limit=512M $(args)`
  - Alias: none (already short)
- `deptrac`: runs `vendor/bin/deptrac analyse --no-cache --no-progress $(args)`

**Test scenarios:**
- `baander test` → runs phpunit
- `baander phpunit` → same
- `baander phpunit --coverage` → runs with coverage flags
- `baander pt` → runs paratest
- `baander stan` → runs phpstan
- `baander deptrac` → runs deptrac
- Extra args pass through: `baender test --filter=MyTest`

**Verification:**
```bash
./bin/baander test --filter=HealthCheckCommandTest
./bin/baander stan --no-progress
./bin/baander deptrac
```

**Dependencies:** Unit 2.

---

### Unit 5: Migration commands — migrate, migrate:dev

**Goal:** Doctrine migration commands matching current Makefile `migrate` and `migrate-dev` targets.

**Files:**
- `cli/src/commands/migrate.ts` (create)

**Patterns to follow:**
- `migrate`: runs main DB migration then test DB migration (two sequential commands)
  - `php bin/console doctrine:migrations:migrate --no-interaction`
  - `php bin/console doctrine:migrations:migrate --no-interaction --env=test`
  - Alias: `m`
- `migrate:dev`: runs main DB only
  - `php bin/console doctrine:migrations:migrate --no-interaction`
  - Alias: `md`

**Test scenarios:**
- `baander migrate` → runs both main + test migrations
- `baander m` → alias works
- `baander migrate:dev` → runs main only
- `baander md` → alias works
- Shows output from each step sequentially

**Verification:**
```bash
./bin/baander m
./bin/baander md
```

**Dependencies:** Unit 2.

---

### Unit 6: Docker lifecycle commands — build, start, stop, down, restart

**Goal:** Docker Compose lifecycle commands calling `docker compose` directly (no Make dependency).

**Files:**
- `cli/src/commands/build.ts` (create)
- `cli/src/commands/start.ts` (create)
- `cli/src/commands/stop.ts` (create)
- `cli/src/commands/down.ts` (create)
- `cli/src/commands/restart.ts` (create)

**Patterns to follow:**
- Env passthrough matches Makefile: `HOST_UID`, `HOST_GID`, `WEB_PORT_HTTP`, `WEB_PORT_SSL`, `XDEBUG_CONFIG`, `XDEBUG_VERSION`, `REDIS_PASSWORD`
- `build`: `docker compose --progress=plain build` (dev target)
  - Alias: `b`
- `build:prod`: `docker compose --progress=plain build --target production`
- `build:clean`: `docker compose --progress=plain build --no-cache`
- `start`: `docker compose up -d`
- `stop`: `docker compose stop`
- `down`: `docker compose down`
- `restart`: `docker compose stop` then `docker compose up -d`
- `restart:app`: `docker compose restart app`

**Test scenarios:**
- `baander build` → builds dev image
- `baander b` → alias works
- `baander build:clean` → builds with --no-cache
- `baander start` → starts containers
- `baander stop` → stops containers
- `baander restart` → stops then starts
- `baander restart:app` → restarts app only
- `baander down` → tears down

**Verification:**
```bash
./bin/baander b
./bin/baander start
./bin/baander restart:app
./bin/baander stop
```

**Dependencies:** Unit 2.

---

### Unit 7: Logs command

**Goal:** Container log viewing with snapshot (default) and follow modes.

**Files:**
- `cli/src/commands/logs.ts` (create)

**Patterns to follow:**
- Default: `docker logs baander-app 2>&1` (snapshot, last ~100 lines)
- `--follow` / `-f`: `docker logs -f baander-app 2>&1`
- `--nginx`: switch to `baander-nginx` container
- `--all`: `docker compose logs`
- `--lines` / `-n`: control line count (default: 100)
- Alias: `l`

**Test scenarios:**
- `baander logs` → shows last 100 lines of app logs
- `baander l` → alias works
- `baander logs -f` → follows logs (Ctrl+C to stop)
- `baander logs --nginx` → shows nginx logs
- `baander logs --all` → shows all container logs
- `baander logs -n 50` → shows last 50 lines

**Verification:**
```bash
./bin/baander logs
./bin/baander l -n 20
```

**Dependencies:** Unit 2.

---

### Unit 8: PHP manifest command — `app:cli:manifest`

**Goal:** Symfony console command that introspects the application and outputs structured JSON manifest.

**Files:**
- `src/Shared/Interface/Console/CliManifestCommand.php` (create)
- `config/services.yaml` (modify — already auto-discovered via `autowire: true` + `autoconfigure: true`)

**Patterns to follow:**
- Follow existing `#[AsCommand]` pattern from `HealthCheckCommand.php`
- Inject the console application to introspect registered commands
- Output JSON to stdout (not a file) — the CLI captures it
- Parse `phpstan.dist.neon` for phpstan config (level, paths, memory limit)
- Parse `deptrac.yaml` for deptrac layers
- Parse `composer.json` for scripts/autoload
- Parse `phpunit.xml` for test config
- Use `json_encode` with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`

**Test scenarios:**
- `php bin/console app:cli:manifest` outputs valid JSON
- JSON contains `console.commands` array with all registered commands
- Each command has: name, description, aliases, hidden, arguments, options
- JSON contains `phpstan` section with level, memoryLimit, paths, config
- JSON contains `deptrac` section with config path
- JSON contains `composer` section with scripts, autoload
- JSON contains `phpunit` section with config path
- Hidden commands included with `hidden: true`
- Command runs without errors

**Verification:**
```bash
make exec cmd="php bin/console app:cli:manifest" | jq .
make exec cmd="php bin/console app:cli:manifest" | jq '.console.commands | length'
```

**Dependencies:** None (independent of CLI units, can run in parallel with Units 1-7).

---

### Unit 9: Manifest integration — lazy loading, caching, tab completion

**Goal:** CLI reads manifest cache, auto-regenerates when stale, uses it for `baander console` completion and help.

**Files:**
- `cli/src/manifest.ts` (create)
- `cli/src/commands/manifest.ts` (create)
- `cli/src/commands/console.ts` (modify — add completion from manifest)

**Patterns to follow:**
- Cache at `$XDG_CACHE_HOME/baander/manifest.json` (default `~/.cache/baander/`)
- Staleness threshold: 1 hour (configurable via config)
- Lazy generation: if cache missing/stale, run `docker compose exec app php bin/console app:cli:manifest --no-interaction` and cache result
- `baander manifest` → force regenerate regardless of staleness
- `baander console <tab>` → reads manifest for command names
- `baander console --help` → shows all Symfony commands from manifest with descriptions

**Test scenarios:**
- First run with no cache → auto-generates manifest, caches it, runs command
- Subsequent run within threshold → uses cache, no regeneration
- `baander manifest` → force regenerates even if cache fresh
- `baander console <tab>` → completes Symfony command names
- `baander console --help` → lists all Symfony commands with descriptions
- Cache directory created automatically if missing
- Container not running during lazy generation → clear error message

**Verification:**
```bash
rm -rf ~/.cache/baander/
./bin/baander console doctrine:schema:validate  # triggers lazy manifest generation
./bin/baander console --help  # shows all commands from cached manifest
./bin/baander manifest  # force refresh
```

**Dependencies:** Unit 3, Unit 8.

---

### Unit 10: Config system — XDG config loading, defaultCommand

**Goal:** XDG-compliant config file loading with `defaultCommand` support.

**Files:**
- `cli/src/config.ts` (modify)

**Patterns to follow:**
- Config path: `$XDG_CONFIG_HOME/baander/config.json` (default `~/.config/baander/`)
- Schema: `{ "defaultCommand": "help" }`
- Missing config file → use defaults (no error)
- Invalid JSON in config → clear error message, use defaults
- `defaultCommand` changes bare `baander` behavior

**Test scenarios:**
- No config file → `baander` shows help
- Config with `defaultCommand: "ssh"` → `baander` opens SSH
- Invalid JSON config → warning message, falls back to help
- Unknown command in `defaultCommand` → warning, falls back to help
- `$XDG_CONFIG_HOME` env var respected

**Verification:**
```bash
rm -rf ~/.config/baander/
./bin/baander  # shows help
mkdir -p ~/.config/baander && echo '{"defaultCommand":"ssh"}' > ~/.config/baander/config.json
./bin/baander  # opens SSH
rm ~/.config/baander/config.json
```

**Dependencies:** Unit 1.

---

## Execution order and parallelism

```
Unit 1 (scaffold)  ──────────────────────────────────────┐
                                                          │
Unit 8 (PHP manifest) ────────────────────────────────────┤ (parallel with 1-7)
                                                          │
Unit 2 (docker helper) ← Unit 1 ─────────────────────────┤
  ├─ Unit 3 (proxy cmds) ────────────────────────────────┤
  ├─ Unit 4 (tooling cmds) ──────────────────────────────┤
  ├─ Unit 5 (migrate) ───────────────────────────────────┤
  ├─ Unit 6 (lifecycle cmds) ────────────────────────────┤
  └─ Unit 7 (logs) ──────────────────────────────────────┤
                                                          │
Unit 10 (config) ← Unit 1 ───────────────────────────────┤
                                                          │
Unit 9 (manifest integration) ← Unit 3 + Unit 8 ─────────┘
```

**Parallel-safe groups:**
- Group A (Units 1, 8): no shared files
- Group B (Units 3, 4, 5, 6, 7): each creates independent command files, shares `docker.ts`
- Group C (Unit 10): only touches `config.ts`

## Verification strategy

**Per-unit:** Each unit's verification command listed above.

**End-to-end smoke test (after all units):**
```bash
# Build from scratch
cd cli && bun install && bun run build

# Lifecycle
./bin/baander b
./bin/baander start

# Tooling
./bin/baander console doctrine:schema:validate
./bin/baander php -v
./bin/baander comp --version
./bin/baander test --filter=HealthCheckCommandTest
./bin/baander stan --no-progress
./bin/baander m
./bin/baander logs -n 10

# Manifest
./bin/baander console --help
./bin/baander manifest

# Config
mkdir -p ~/.config/baander
echo '{"defaultCommand":"help"}' > ~/.config/baander/config.json
./bin/baander

# Binary self-contained
file bin/baander  # ELF binary, not a script
```
