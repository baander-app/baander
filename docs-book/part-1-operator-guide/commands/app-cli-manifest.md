# app:cli:manifest

Print a JSON manifest of the console commands and the project's tooling metadata. The manifest lists every registered console command with its arguments, options, aliases and hidden flag, reports configuration parsed from `phpstan.dist.neon`, `deptrac.yaml` and `composer.json`, and names the PHPUnit and Paratest setup.

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

- `console` — an object whose `commands` array lists every registered console command with its name, description, aliases, hidden flag, arguments, and options. Omitted when `--tooling-only` is passed.
- `phpstan` — level, paths, memory limit, and config file parsed from `phpstan.dist.neon`.
- `deptrac` — layer names and config file parsed from `deptrac.yaml`.
- `composer` — scripts and autoload sections from `composer.json`.
- `phpunit` — config file and coverage flag, fixed values that the command does not read from a file.
- `paratest` — process count and config file, also fixed values.

A command whose definition cannot be built is still recorded with its name, description, aliases, and hidden flag, but with empty arguments and options.

## Tips

- Pipe to `jq` to inspect a single section: `make exec cmd="php bin/console app:cli:manifest" | jq .phpstan`.
- The manifest is for tools and editors that read the project's console commands and static-analysis configuration.
