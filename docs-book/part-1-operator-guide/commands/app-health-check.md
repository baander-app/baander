# app:health:check

Check the health of all system components (database, Redis, the background worker, etc.) and report their status, latency, and details.

## Quick start

```bash
make exec cmd="php bin/console app:health:check"
```

## Details

The command checks each system component and prints a table with the component name, status (`OK`, `FAIL`, or `N/A`), response latency in milliseconds, and any additional details.

A component marked `N/A` means it is not configured or not applicable in the current environment (e.g., an optional service that isn't enabled). The background worker (`messenger`) reads `N/A` while Redis holds no worker heartbeat, for example before a worker has started, and while Redis is unreachable.

The exit status describes this container, so it leaves out the background worker and memory; the web server alerts administrators about those instead. If no other component is unhealthy, the command prints `This container is healthy.` and exits with code 0. If the worker or memory is unhealthy, it also prints a warning that names them as unhealthy but not counted toward this container. If any other component is unhealthy, it exits with code 1.

The command runs in its own PHP process, so the `memory` row measures that process against its `memory_limit`, not the web server's workers. The [`/health` endpoint](../monitoring.md#health-checks) reports the workers.

This is the same check used by the Docker healthcheck, which the web image defines in `Dockerfile` and the development stack in `docker-compose.yml`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | No component other than the background worker and memory is unhealthy |
| 1 | One or more components other than the background worker and memory are unhealthy |

## Tips

- Use this as a quick diagnostic when something seems off — it checks connectivity to database and Redis in seconds.
- The Docker container healthcheck runs this command every 30 seconds.
- Combine with `app:config:validate` for a full configuration and connectivity audit.
