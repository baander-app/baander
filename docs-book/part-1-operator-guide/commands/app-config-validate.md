# app:config:validate

Check the application's configuration for common mistakes. The checks cover environment variables, connectivity, and framework settings.

## Quick start

The Make examples below target the checkout's development preview. For an
installed instance, use the `dc` function from
[Getting started](../getting-started/installation.md#command-context) and replace
`make exec cmd="php bin/console …"` with `dc exec app php bin/console …`.

Run all checks:

```bash
make exec cmd="php bin/console app:config:validate"
```

Run only environment variable checks:

```bash
make exec cmd="php bin/console app:config:validate --category=env"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--category`, `-c` | `all` | Check category to run: `all`, `env`, `connectivity`, `framework` |

## Details

The command groups checks into four categories:

- **env** — checks that the required environment variables are set and have sensible values, such as the length of `APP_SECRET` and the presence of the OAuth keys
- **connectivity** — tests the PostgreSQL and Redis connections
- **framework** — runs the framework-level checks of the `env` category, leaving out those for environment variables, secrets and API keys
- **all** — runs every check of every category

Each check reports a severity level:

| Severity | Meaning |
|----------|---------|
| `ok` | Check passed |
| `warning` | Non-critical issue (suggestion provided) |
| `error` | Misconfiguration that will cause problems |

The command prints a table of the checks and a summary line, and exits with code 0 when no check reports an error. Warnings do not change the exit code.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | All checks passed (warnings are OK) |
| 1 | One or more checks failed with errors, or `--category` names no category |

## Tips

- Run this after setting up a new instance to catch misconfigurations early.
- Use `--category=connectivity` to quickly verify database and Redis are reachable.
- Combine with `app:health:check`: that command checks runtime health, this one checks the configuration.
