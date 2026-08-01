# app:config:validate

Validate application configuration and check for common misconfigurations. Runs a suite of checks covering environment variables, connectivity, and framework settings.

## Quick start

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

- **env** — validates required environment variables are set and have sensible values (e.g., `APP_SECRET` length, OAuth keys present)
- **connectivity** — tests PostgreSQL and Redis connections
- **framework** — checks framework-level configuration (subset of env checks, excluding secrets and API keys)
- **all** — runs every check from all categories

Each check reports a severity level:

| Severity | Meaning |
|----------|---------|
| `ok` | Check passed |
| `warning` | Non-critical issue (suggestion provided) |
| `error` | Misconfiguration that will cause problems |

The command prints a summary table and exits with code 0 if no errors are found. Warnings do not cause a failure exit code.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | All checks passed (warnings are OK) |
| 1 | One or more checks failed with errors |

## Tips

- Run this after setting up a new instance to catch misconfigurations early.
- Use `--category=connectivity` to quickly verify database and Redis are reachable.
- Combine with `app:health:check` — that command checks runtime health, this one checks configuration correctness.
