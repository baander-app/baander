# app:cli:manifest

Output a JSON manifest of all CLI commands and tooling metadata. The manifest enumerates every registered console command (with arguments, options, aliases, and hidden flag) and reports configuration parsed from `phpstan.dist.neon`, `deptrac.yaml`, `composer.json`, and the PHPUnit/Paratest setup.

## Quick start

```bash
make exec cmd="php bin/console app:cli:manifest"
```

Output only tooling metadata, skipping the console-command introspection:

```bash
make exec cmd="php bin/console app:cli:manifest --tooling-only"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--tooling-only` | off (boolean flag) | Only output tooling metadata (skip console commands) |

## Details

The command has no arguments. By default it emits a JSON object with the following top-level keys:

- `console` — array of every registered console command with its name, description, aliases, hidden flag, arguments, and options. Omitted when `--tooling-only` is passed.
- `phpstan` — level, paths, memory limit, and config file parsed from `phpstan.dist.neon`.
- `deptrac` — layer names and config file parsed from `deptrac.yaml`.
- `composer` — scripts and autoload sections from `composer.json`.
- `phpunit` — config file and coverage flag.
- `paratest` — process count and config file.

Commands that fail during definition instantiation are still recorded with their name, description, aliases, and hidden flag, but with empty arguments and options.

## Tips

- Pipe to `jq` to inspect a single section: `make exec cmd="php bin/console app:cli:manifest" | jq .phpstan`.
- The manifest is intended for tooling and editors that need to reason about the project's CLI surface and static-analysis config.
