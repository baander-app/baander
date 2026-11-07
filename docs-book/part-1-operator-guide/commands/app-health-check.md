# app:health:check

Check the health of all system components (database, Redis, the background worker, etc.) and report their status, latency, and details.

## Quick start

Run from the installed-instance host shell with the `dc` function from
[Getting started](../getting-started/installation.md#command-context). Paths name locations
inside the application container.

```bash
dc exec app php bin/console app:health:check
```

## Details

The command checks each system component and prints a table with the component name, status (`OK`, `FAIL`, or `N/A`), response latency in milliseconds, and any additional details.

The `messenger` row observes a shared consumer heartbeat. It reads `N/A` when
Redis is unreachable, when no heartbeat has been seen by this observation process,
or during a fresh `stopped` phase. A heartbeat that disappears after this process
has seen it, an invalid heartbeat, or a stale heartbeat reads `FAIL`. Freshness
allows 90 seconds for `busy` and 45 seconds for other phases. This signal does
not prove that every consumer, the scheduler, the outbox relay or media execution
is making progress. See [Monitoring](../operations/health-and-metrics.md#health-checks) and
[Background work](../workers/background-work.md#observe-background-work) for those checks.

The `worker_profile` row compares this process's deployment profile with the one
the active worker boot was started with. It reads `FAIL` when they differ, for
example when web was configured for 8 video slots and the workers for 4, and lists
each disagreeing boot with its profile fingerprint. It reads `OK` when they match,
and `N/A` when no worker boot holds the deployment lease or the boot did not record
its profile. [app:deployment:profile:show](app-deployment-profile-show.md) prints a
profile's fingerprint.

The exit status describes this container, so it leaves out the background worker, its deployment profile and memory; the web server alerts administrators about those instead. If no other component is unhealthy, the command prints `This container is healthy.` and exits with code 0. If the worker, its profile or memory is unhealthy, it also prints a warning that names them as unhealthy but not counted toward this container. If any other component is unhealthy, it exits with code 1.

The command runs in its own PHP process, so the `memory` row measures that process against its `memory_limit`, not the web server's workers. The [`/health` endpoint](../operations/health-and-metrics.md#health-checks) reports the workers.

This is the same check used by the Docker healthcheck, which the web image defines in `Dockerfile` and the development stack in `docker-compose.yml`.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | No component other than the background worker, its deployment profile and memory is unhealthy |
| 1 | One or more components other than the background worker, its deployment profile and memory are unhealthy |

## Tips

- Use this as a quick diagnostic when something seems off — it checks connectivity to database and Redis in seconds.
- The Docker container healthcheck runs this command every 30 seconds.
- Use [app:config:validate](app-config-validate.md) for additional configuration
  checks; neither command establishes complete background delivery.
