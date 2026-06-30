# Baander CLI — DX Improvement

**Date:** 2026-05-11
**Status:** Approved

## Problem

Running Symfony console commands from the host requires `make exec cmd="php bin/console ..."` — verbose, no tab completion, quoting friction. Docker lifecycle commands (build, start, stop) are Make-only. No unified entry point.

## Solution

A single `baander` CLI binary, built with Bun + TypeScript, compiled via `bun build --compile` to a zero-dependency static binary. Two-stage command generator keeps CLI metadata in sync with the PHP project automatically.

## Implementation

- **Language:** TypeScript, compiled with Bun
- **Output:** Single binary at `bin/baander` (checked into repo)
- **Source:** `cli/` directory
- **Build:** `cd cli && bun install && bun run build`

## Two-Stage Command Generator

### Stage 1 — PHP Manifest Command

A Symfony console command `app:cli:manifest` that introspects the application and outputs structured JSON:

```json
{
  "console": {
    "commands": [
      {
        "name": "doctrine:schema:validate",
        "description": "Validate mapping files...",
        "aliases": [],
        "hidden": false,
        "arguments": [
          { "name": "em", "description": "...", "default": null, "required": false }
        ],
        "options": [
          { "name": "skip-mapping", "shortcut": null, "description": "...", "default": false }
        ]
      }
    ]
  },
  "phpstan": {
    "level": 8,
    "memoryLimit": "512M",
    "paths": ["src"],
    "config": "phpstan.neon"
  },
  "deptrac": {
    "config": "deptrac.yaml",
    "layers": ["Domain", "Application", "Infrastructure"]
  },
  "composer": {
    "scripts": { "auto-scripts": ["..."] },
    "autoload": { "psr-4": { "App\\": "src/" } }
  },
  "phpunit": {
    "config": "phpunit.xml",
    "coverage": true
  },
  "paratest": {
    "processes": "auto",
    "config": "phpunit.xml"
  }
}
```

### Stage 2 — Bun CLI Consumes Manifest

The `baander` CLI reads this manifest for:
- Rich help text for `baander console --help` (all Symfony commands with descriptions)
- Shell tab completion (`baander console <tab>` shows real commands)
- Command validation before container roundtrip
- Intelligent defaults for tooling commands (phpstan level, deptrac config path, etc.)

### Manifest Generation Strategy

| Scenario | Behavior |
|----------|----------|
| **Dev** | Lazy — CLI checks manifest staleness (file age or missing), auto-regenerates via `docker compose exec app php bin/console app:cli:manifest`. Cached locally at `~/.cache/baander/manifest.json`. |
| **Release build** | Pre-build step runs the manifest command, embeds JSON in the compiled binary. No container needed at runtime. |

## Config

XDG-compliant: `~/.config/baander/config.json` (respects `$XDG_CONFIG_HOME`).

```json
{
  "defaultCommand": "help"
}
```

## Subcommands

| Command | Aliases | Action |
|---------|---------|--------|
| `console` | `c` | `php bin/console ...` passthrough (completion from manifest) |
| `php` | — | Raw `php` passthrough |
| `composer` | `comp` | Composer commands |
| `phpunit` | `test` | PHPUnit |
| `paratest` | `pt` | Paratest (--processes auto) |
| `stan` | — | PHPStan |
| `deptrac` | — | Deptrac |
| `migrate` | `m` | Doctrine migrations (main + test) |
| `migrate:dev` | `md` | Migrations (main DB only) |
| `logs` | `l` | App container logs (default: snapshot, `-f` for follow) |
| `ssh` | — | Bash into container as www-data |
| `ssh:root` | — | Bash into container as root |
| `build` | `b` | `docker compose build` (dev target) |
| `build:prod` | — | Production target build |
| `build:clean` | — | `--no-cache` build |
| `start` | — | `docker compose up -d` |
| `stop` | — | `docker compose stop` |
| `down` | — | `docker compose down` |
| `restart` | — | Stop + start |
| `restart:app` | — | Restart app container only |
| `manifest` | — | Force-regenerate manifest from PHP container |

**Routing rule:** Symfony commands are accessible only as `baander console <cmd>`. No top-level routing — prevents collision with hardcoded subcommands.

## Design Decisions

- **No Make dependency:** CLI calls `docker compose` directly with env var passthrough.
- **TTY handling:** Auto-detect via `process.stdout.isTTY`. Override with `--tty` / `--no-tty`.
- **No-args behavior:** Show help menu. Configurable via `defaultCommand`.
- **Manifest scope:** Covers bin/console, phpstan, deptrac, composer, phpunit, paratest metadata.
- **Shortcut collision check:** `c`, `comp`, `pt`, `m`, `md`, `l`, `b` — no collisions.

## Source Layout

```
cli/
  package.json
  tsconfig.json
  src/
    index.ts             # Entry point, command router
    config.ts            # XDG config loading
    manifest.ts          # Manifest loading, staleness, lazy generation
    commands/
      console.ts         # Uses manifest for completion + help
      php.ts
      composer.ts
      phpunit.ts
      paratest.ts
      stan.ts
      deptrac.ts
      migrate.ts
      logs.ts
      ssh.ts
      build.ts
      start.ts
      stop.ts
      down.ts
      restart.ts
      manifest.ts        # Force regenerate
    lib/
      docker.ts          # spawn helper, env passthrough, TTY, signal forwarding
      errors.ts          # Error types, exit codes
      output.ts          # Colored output helpers
src/App/Shared/Infrastructure/Console/
  CliManifestCommand.php # Symfony command that outputs the JSON manifest
bin/
  baander                # Compiled binary (checked in)
```

## Cache Paths (XDG)

| What | Path |
|------|------|
| Config | `~/.config/baander/config.json` |
| Manifest cache | `~/.cache/baander/manifest.json` |
| Manifest staleness | 1 hour (configurable) |

## Success Criteria

- `baander console doctrine:schema:validate` works from host with no ceremony
- `baander test` runs PHPUnit in one command
- `baander build && baander start` replaces `make build && make start`
- `baander console <tab>` tab-completes Symfony command names
- Manifest auto-refreshes in dev, embedded in release binary
- Binary is self-contained — no Bun runtime needed on host
