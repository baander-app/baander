# app:health:check

Check the health of all system components (database, Redis, etc.) and report their status, latency, and details.

## Quick start

```bash
make exec cmd="php bin/console app:health:check"
```

## Details

The command checks each system component and prints a table with the component name, status (`OK`, `FAIL`, or `N/A`), response latency in milliseconds, and any additional details.

A component marked `N/A` means it is not configured or not applicable in the current environment (e.g., an optional service that isn't enabled).

If all components are healthy, the command exits with code 0. If any component is unhealthy, it exits with code 1.

This is the same check used by the Docker healthcheck configured in `docker-compose.yml`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | All systems healthy |
| 1 | One or more components are unhealthy |

## Tips

- Use this as a quick diagnostic when something seems off — it checks connectivity to database and Redis in seconds.
- The Docker container healthcheck runs this command every 30 seconds.
- Combine with `app:config:validate` for a full configuration and connectivity audit.
