# Upgrading

## Standard Update

Before pulling this update, retire any singleton worker deployment with the old
checkout, controller, manifest and credentials. Stop controller activity before
applying migrations. The paired-deployment migration refuses active leases,
unresolved creations, incomplete retirements or inconsistent singleton history;
lease expiry is insufficient. Resolve these through the old controller while its
schema is still installed. Preserve the database, pending work and media; no reset
is required or authorized by this upgrade.

Then run these commands in order:

```bash
git pull
make build
make start
make composer-install
make migrate
```

1. **`git pull`** fetches the latest code.
2. **`make build`** rebuilds the Docker images if the Dockerfile or installed packages changed.
3. **`make start`** recreates containers with the new images.
4. **`make composer-install`** updates PHP dependencies inside the app container.
5. **`make migrate`** runs pending database migrations.

These commands update the web/dependency stack. The separately admitted worker
uses an immutable image and boot identity. Before changing its image or storage
recipe, use the host [deployment controller](commands/README.md#worker-deployment)
to recover and retire the old deployment, then create/start a new boot with the
updated image and manifest. Never enable automatic worker restart or place its
children under another supervisor. Prepare shared media volumes before starting
either role, and preserve pending work and existing media.

Check the commit log for notable changes between your current version and the one you are upgrading to:

```bash
git log --oneline <current-tag>..HEAD
```

## Database Migrations

`make migrate` runs all pending migrations on both the main database and the test database. Always run it after pulling new code, even if you do not expect schema changes -- migrations may contain data transformations or index additions that are not obvious from the file list alone.

If you only need to migrate the main database (for example, on a production server without a test database), use `make migrate-no-test` instead.

Fresh databases start with the transactional migration `Version20261017000000`,
then apply forward migrations through `Version20261018130000`. The initial
migration creates the schema, including microsecond `timestamptz` columns, and
cannot be downgraded. Forward migrations add playlist persistence versions and
video/audio execution authority. `Version20261017160000` preserves durable
session/request control receipts; `Version20261017170000` scopes video jobs to
playback owners without rewriting the initial migration or existing histories.
`Version20261018130000` imports confirmed singleton retirement history and adds
the control/execution journals and joint retirement barrier. It is irreversible.
New deployments require a version 3 manifest with separate budgets, disjoint CPU
sets and a private IPC volume; version 2 manifests are refused.

Ownership is derived from the recorded runtime session or an unambiguous session
owner. Ambiguous or unattached historical jobs retain a null owner. Existing
accepted, ready and complete execution generations are fenced and queued for
re-encoding into job-specific output paths; old output does not become current
through the migration. Preserve the shared source and derived volumes during this
update.

A database already initialized with `Version20261017000000` applies the forward
migrations through the normal update commands; no database reset is required.
Migrations run in timestamp order, and a repeat run leaves no pending work.

While the initial migration is pending, Doctrine refuses a target containing user
tables, views, sequences, types or routines. A table counts even without rows.
System objects, extension-owned objects and an empty Doctrine migration metadata
table are excluded. The refusal identifies the database without printing
credentials and occurs before application DDL. Non-interactive execution and
Doctrine's ordinary migration confirmation do not bypass this check.

Executed migrations absent from `migrations/`, including the former migration
chain, also cause refusal. Do not mark the new initial migration as executed or
edit migration metadata to bypass either guard.

### Manual reset of an existing database

The consolidated migration provides no automatic upgrade from the former chain.
Resetting an existing database requires its owner's explicit approval for that
specific target and a separate manual operation outside Doctrine migrations.

1. **Identify the target and obtain approval.** Confirm the database and server
   without exposing connection credentials. Agree which data must be exported or
   backed up before the owner approves deleting that database.
2. **Stop its writers.** Record which application services are running and stop
   the processes that can write to the approved target.
3. **Recreate only the approved database manually.** Preserve its database creation
   settings. Leave other databases, PostgreSQL volumes, Redis, registry storage
   and media files untouched. Do not use `make drop-migrate`: it drops both the
   main and test databases.
4. **Initialize and verify.** Run Doctrine migrations against that empty target,
   then run them again and verify there is no pending work. For the configured
   main database alone, use `make migrate-no-test` on both runs.
5. **Restore service state.** Restart the services recorded in step 2 and complete
   the [initial setup](getting-started.md#initial-setup) required by the empty
   application database.

## When to Rebuild Docker Images

`make build` is necessary when any of the following have changed:

- The `Dockerfile` or `.dockerignore`
- PHP extensions (added or removed in the Dockerfile)
- System packages in the Dockerfile (e.g., `apt-get install`)
- The base image tag

`make build` uses Docker layer caching, so it is safe to run on every update. It only rebuilds layers that have actually changed.

## Docker Compose Changes

When `docker-compose.yml` changes (new services, new volume mounts, network changes, environment variable additions), a simple `make restart` is not enough -- Docker Compose needs to recreate the affected containers from scratch.

```bash
make down && make start
```

This stops all containers, removes their network configuration, and starts them fresh with the updated compose file.

## Stopping and Restarting the Web Server

An update that replaces the app container stops the web server in it, and so do `make stop`, `make restart`, `docker stop` and `docker compose restart app`. Docker sends `SIGTERM` to `app:serve`, and the server drains: it finishes what it can, tells clients to come back, and exits on its own. `SIGINT`, `swoole:server:stop` and `DELETE /api/server` on the internal API server start the same drain. A second stop request during a drain changes nothing, and a worker reload (`PATCH /api/server`) never starts one.

### What the drain does

The drain ends within the drain deadline `WEB_DRAIN_SECONDS` (D, 30 seconds by default; see [Configuration](configuration.md#server)):

1. The server accepts no new connections. Connections already open keep working. Requests that still arrive on them are answered with `Connection: close`, `/ready` answers `503`, and `/live` answers `200` with `"draining": true`.
2. Every WebSocket receives a close frame with code `1012` ("service restart"). Session-sync clients reconnect once the server is back, with a current access token.
3. Requests in progress finish with complete responses. Background commands use Redis `async`; web has no task workers, CPU pool or media executor. The worker deployment keeps its consumers and encoders while web drains.
4. When the drain window ends (13 seconds at the default), the server closes every response it is still sending, such as an audio stream, without finishing it. The client sees a transfer error, not the end of the track. The server then resets every connection still open.
5. Swoole stops the HTTP workers. Video and audio execution remain in the independent worker deployment; reconnecting clients read the persisted media state and current generation.
6. The server exits with status `0`. A drain that had nothing to wait for takes under a second.

If the server's manager process is still running at the deadline, the drain watchdog, [`app:server:drain:watch`](commands/app-server-drain-watch.md), kills it with `SIGKILL`, and the server exits with status `1`. The server logs a line when the drain starts, with the deadline, the window and Swoole's `max_wait_time`, and another when the window closes. A planned stop sends no [health alert](monitoring.md#what-is-checked).

### Stop timeout

Give the container a stop timeout of at least D + 5 seconds, 35 seconds at the default, before the runtime sends `SIGKILL`:

- Docker Compose: `stop_grace_period: 35s` on the app service. The development `docker-compose.yml` sets this.
- `docker run --stop-timeout 35`, or `docker stop -t 35` for one stop.
- Kubernetes: `terminationGracePeriodSeconds: 35`. On other orchestrators, set the equivalent grace period or kill timeout.

When you change `WEB_DRAIN_SECONDS`, change the stop timeout with it.

Docker's default stop timeout is 10 seconds, shorter than even the smallest drain deadline needs (8 + 5 seconds). With it, Docker kills the container 10 seconds into the drain, before the drain window ends. Requests still running then fail, and streams end when their processes die. The web player resumes those streams too, but slow requests lose their responses.

### Listeners during a restart

When its stream fails, or stalls for 10 seconds, the web player asks the server's `/live` endpoint whether the server answers. No answer, a timeout, an answer other than `2xx` or an answer with `"draining": true` means the server is unreachable; nginx answers `502` while the app container restarts, and a draining server can still answer on a connection nginx kept alive. The player then:

- shows "Reconnecting…" in place of the time and keeps the position it had reached;
- retries with a growing, randomised delay, from 1 second up to 15 seconds, and honours a `Retry-After` header on a `503`;
- once `/live` answers, requests the same track again and continues at that position. The resume records no second play.

A transcoded track resumes once its accepted rendition is complete; until then the player shows "Reconnecting…". When the browser will not start playback without a user gesture, the player shows a "Tap to resume" button. After about two minutes without success, the player pauses with its usual failure notice and keeps the position, so pressing play tries again.

A token refresh that fails because the server is restarting, with a network error or a `5xx` answer, keeps the user signed in. Only a `400` or `401` from the refresh signs the user out. Video execution stays with the worker during a web restart; see [Transcoding](transcoding.md#restarts).

### nginx and a replaced app container

The bundled nginx resolves the `app` host name once, when it starts. A restarted container normally keeps its address. A recreated container, for example after `make build` and `make start`, can come back with a new one. nginx then keeps sending requests to the old address and answers `502`, and the player gives up after two minutes. After the app container has been replaced, reload nginx so it resolves `app` again:

```bash
docker exec baander-nginx nginx -s reload
```

### A proxy in front of nginx

The production nginx expects a reverse proxy in front of it to terminate TLS. When the web server cuts a stream, that proxy must pass the cut on to the client as an error. Over HTTP/2 a proxy can answer an upstream reset by ending the client's stream normally. The player then takes the cut for the end of the track and skips to the next one.

To check your proxy, copy a stream request from the browser's network tab as a curl command and run it through the proxy with `--limit-rate 32k`, so that it is still downloading when you restart the app container. curl must fail with exit code `18` over HTTP/1.1 or `92` over HTTP/2. Exit code `0` means the proxy ended the stream cleanly.

## FFmpeg Updates

FFmpeg is installed via apt inside the app image (the static-build step is currently disabled). To pick up a new FFmpeg version, rebuild the app image:

```bash
make build-clean
make start
```

Verify the version after rebuilding:

```bash
make exec cmd="ffmpeg -version"
```

## Version Compatibility

Baander does not have a formal breaking change policy yet. The current approach is migration-first: always run migrations after pulling, and check the commit log for any notable changes between versions.

When environment variables are added or changed between versions, see [Configuration](configuration.md) for the current list and descriptions.

## Rollback

To revert to a previous version:

1. Check out the previous tag or commit:

   ```bash
   git checkout <previous-tag>
   ```

2. Rebuild and restart:

   ```bash
   make build
   make start
   make composer-install
   ```

Changing the code does not revert the database schema. The initial migration is
irreversible, and older code with the former migration chain cannot migrate a
database initialized by it. Restore a backup compatible with the selected code
when a database rollback is required. Any replacement of an existing database
requires its owner's explicit approval for that target; preserve the backup and
follow the [manual reset precautions](#manual-reset-of-an-existing-database).

## Tracking Changes

Baander does not have a formal release notes page yet. To see what changed between versions:

```bash
# View commits since a specific tag
git log --oneline <current-tag>..HEAD

# View recent commits
git log --oneline -20

# View changes to a specific area
git log --oneline -- src/Transcode/
```

Check the commit log for notable changes before upgrading — particularly look for new environment variables in `config/` or schema changes in `migrations/`.

## See Also

- [CLI Reference](commands/README.md) -- all available console commands
- [Configuration](configuration.md) -- environment variables that may change between versions
