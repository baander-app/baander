# Deployment Capability, Size and Runtime

Deployment capability and size, media slots, the PgBouncer connection pooler, and Swoole runtime settings bound web and worker capacity across a Bånder deployment.

## Deployment capability and size

A deployment is described by two independent choices, both made before it starts:
its capability, which decides what media work the server may run, and its size,
which decides how much it runs. Any capability combines with any size within the
bounds below. Neither is a live server setting: changing either one replaces the
deployment.

### Capability

| Capability | `transcode` | `remux` |
|------------|-------------|---------|
| Original-file and compatible cached playback | Yes | Yes |
| Stream copy | In the video or audio slot of its kind | In shared copy slots |
| Audio and video re-encoding | In video and audio slots | Never |
| Video and audio slots | Accepted, bounded by the pooler | Refused |
| Copy slots | Refused | Required, 1–8 |
| Hardware encoder | Accepted with video slots above zero | Refused |

The `remux` capability permits original-file delivery, compatible completed cache
entries and stream copy, without audio or video re-encoding on any path. It
retains ordinary library maintenance, scheduling, relay and media coordination.
When original, cached and copied media cannot meet the receiving browser's
requirements, playback returns a compatibility explanation. Subtitle burn-in
requires re-encoding. Changing the audio transcoding setting does not enable an
encoder under `remux`. See [Transcoding](../media/transcoding.md#how-it-works) for delivery
selection.

### Size

Each size setting has a lower and an upper bound. A value outside its bound is
refused before anything starts, with the setting and its bound named.

| Setting | Preset `size` key | Profile field | Bounds |
|---------|-------------------|---------------|--------|
| Web workers | `webWorkers` | `webWorkers` | 1–16 |
| Database connection slots per web worker | `databaseSlots` | `doctrinePoolSlots` | 1–16 |
| Resident ordinary consumers | `ordinaryConsumers` | `ordinaryConsumerLimit` | 1–4 |
| Copy slots, `remux` only | `copySlots` | `sharedCopySlots` | 1–8 |
| Video slots, `transcode` only | `videoSlots` | `videoEncoderSlots` | 0 to the [pooler maximum](#media-slots); 8 by default, 12 with a hardware encoder |
| Audio slots, `transcode` only | `audioSlots` | `audioEncoderSlots` | 0 to the [pooler maximum](#media-slots); 12 by default |
| PostgreSQL shared buffers | `sharedBuffersMiB` | — | 32–16,384 MiB |
| PostgreSQL work memory per operation | `workMemMiB` | — | 1–1,024 MiB |

The two PostgreSQL settings exist only in the managed preset's configuration; no
PHP process reads them, so they are not part of the startup profile. The preset
derives the remaining database settings from the size:

| Derived setting | Value |
|-----------------|-------|
| Application server pool | 5 per web worker |
| Control server pool | The larger of 4 and 2 × media slots ÷ 5, rounded up, where media slots are the copy, video and audio slots together |
| Direct connections | 4 |
| `max_connections` | Both pools, the direct connections and 3 superuser slots |
| `maintenance_work_mem` | Half of shared buffers, at most 256 MiB |
| `autovacuum_work_mem` | Half of `maintenance_work_mem` |
| `effective_cache_size` | Four times shared buffers |

The rendered PostgreSQL file keeps parallel query off, so a query's memory stays
within one backend's work memory, and sets `timezone = 'UTC'`.

### Size starting points

Bånder ships one example preset configuration per starting point and capability
in [`deploy/preset/`](../../../deploy/preset/README.md). Start from the point nearest
the host's memory and adjust within the bounds. The process and PostgreSQL values
of a starting point are the same for both capabilities; only the media slots
differ.

| Starting point | Web workers / database slots / consumers | Shared buffers / work memory | `transcode` media | `remux` media | Example files |
|----------------|------------------------------------------|------------------------------|-------------------|---------------|---------------|
| 16 GB | 4 / 10 / 4 | 256 MiB / 16 MiB | 8 video, 12 audio | 8 copy | `example-16gb-transcode.json`, `example-16gb-remux.json` |
| 8 GB | 2 / 6 / 2 | 192 MiB / 8 MiB | 3 video, 6 audio | 4 copy | `example-8gb-transcode.json`, `example-8gb-remux.json` |
| 4 GB | 2 / 4 / 1 | 128 MiB / 4 MiB | 1 video, 2 audio | 2 copy | `example-4gb-transcode.json`, `example-4gb-remux.json` |

None of these starting points is qualified yet. The 8 and 16 GB points are
unqualified guidance. The 4 GB `remux` point is meant to carry a claim: one
stream while a library scan and metadata updates run, on an x86 host with 4 GiB of
memory and swap off. Its qualification run is still pending, and that run may
reduce its values. A second simultaneous stream at 4 GB is not
refused, but it is outside that claim. The 4 GB `transcode` point is accepted and
unqualified. ARM hosts and the Raspberry Pi remain unqualified.
[System Requirements](../getting-started/system-requirements.md) records what has been measured.

### Startup profile

Web and worker processes read their capability and process size from one
environment variable, `BAANDER_DEPLOYMENT_PROFILE`, which holds the profile as
JSON:

```json
{"capability":"transcode","webWorkers":4,"doctrinePoolSlots":10,"ordinaryConsumerLimit":4,"sharedCopySlots":0,"videoEncoderSlots":8,"audioEncoderSlots":12,"hardware":null}
```

An unset or empty variable means this default: `transcode` at the 16 GB starting
point. Development instances and the source installation run it; the managed
preset, the worker controller and the qualification harness always write the
profile explicitly. Web, workers and the version 9 worker manifest must agree;
conflicting settings fail startup, and web health reports a profile that differs
from the active worker boot's. Print the effective profile, or one built from other
settings, with
[`app:deployment:profile:show`](../commands/app-deployment-profile-show.md) instead
of writing the JSON by hand.

The source installation's PostgreSQL loads `docker/postgres/conf/postgresql.conf`,
which is the file rendered for the 16 GB starting point, and its Compose file sets
the pooler pools of that default profile.

### Managed preset

For the managed complete-stack preset, set `capability` and `size` in its private
configuration and run the host controller:

```bash
php scripts/deploy-preset.php render --config /srv/baander/preset.json --state /srv/baander/preset-state
php scripts/deploy-preset.php start --config /srv/baander/preset.json --state /srv/baander/preset-state
php scripts/deploy-preset.php status --state /srv/baander/preset-state
php scripts/deploy-preset.php stop --state /srv/baander/preset-state
```

The controller resolves available images to immutable IDs and starts the gateway,
web, PostgreSQL, the connection pooler, Redis and worker pair. It does not build or
pull images. A later `start` retires the saved workers and stops the saved services
before installing the selected configuration. `stop` retains named data volumes.
Preserve the private state directory to recover an interrupted operation. See
[Getting started](../getting-started/installation.md) for image preparation and account setup.

The preset turns OpenTelemetry export off for web and workers. To export traces,
set `OTEL_ENABLED` and `OTEL_EXPORTER_OTLP_ENDPOINT` in the configuration's
`environment` object.

No rendered service or worker unit carries a CPU, memory or PID limit or a CPU
set. The only limit the preset keeps is Redis's own `maxmemory`, set by
`resources.redisMaxmemoryMiB` (default 192). Media work is bounded by the media
slots instead; [System Requirements](../getting-started/system-requirements.md) explains how to choose
them and the kill order that applies under host memory pressure.

### Media slots

A `transcode` configuration sets its slots in the `size` object:

```json
{
    "capability": "transcode",
    "size": {"webWorkers": 4, "databaseSlots": 10, "ordinaryConsumers": 4, "sharedBuffersMiB": 256, "workMemMiB": 16, "videoSlots": 8, "audioSlots": 12}
}
```

`videoSlots` and `audioSlots` are each optional. An unset count takes its default,
8 video or 12 audio. Zero disables that kind: instead of waiting for a slot, its
conversions and stream copies are refused with the compatibility explanation
described above. A `remux` configuration requires `copySlots` and refuses
`videoSlots` and `audioSlots`. A source installation sets the same counts through
`BAANDER_DEPLOYMENT_PROFILE` and the worker manifest's `deploymentProfile`;
`app:deployment:profile:show` prints both.

A top-level `hardware` entry in a `transcode` configuration names a hardware
encoder, for example
`"hardware": {"accelerator": "vaapi", "device": "/dev/dri/renderD128"}`. When a
hardware encoder is named, unset video slots default to 12 instead of 8. [Hardware
Acceleration](../media/transcoding.md#hardware-acceleration) describes the fields, the
device pass-through and the startup check.

The maximums come from the pooler, the only fixed resource bound left. PgBouncer
admits 500 client connections. The web workers, worker roles, scheduler, lease
agents, and an operator's console command and deployment controller hold a number
that follows the size; at the 16 GB starting point they hold 65, which leaves 435
for media jobs. A video job opens 3 pooled connections and an audio job 2, so at
that size a profile accepts at most 145 video slots or 217 audio slots, and both
kinds together must satisfy `3 × videoSlots + 2 × audioSlots ≤ 435`. A smaller
size leaves more connections for media jobs.
[`app:deployment:profile:show`](../commands/app-deployment-profile-show.md) prints the
maximums for any size. Rendering the preset and building the profile both reject a
larger value and name the bound it exceeds.

### Connection pooler

Every deployment reaches PostgreSQL through PgBouncer 1.26.1, run from the database
image as its own `pooler` service on port 6432. It runs in transaction mode: a
client holds a server connection only for the length of a transaction, so
PostgreSQL's backend count follows the pooler's server pools instead of the number
of clients. The pooler starts once PostgreSQL is healthy and runs without resource
limits.

The pooler exposes two aliases, each with its own server pool:

| Alias | Clients | Role | Server pool |
|-------|---------|------|-------------|
| Application, named like the database | Web, ordinary workers, media jobs, console commands and migrations | The database user | 5 per web worker |
| Control, the database name with `_control` appended | Worker lease agents, media authority checks and the scheduler | The database user's name with `_control` appended | The larger of 4 and 2 × media slots ÷ 5, rounded up |

Both pools follow the [deployment size](#size). At the 16 GB `transcode` starting
point the pools are 20 and 8; at the 4 GB `remux` starting point they are 10 and 4.
The source installation's Compose file uses the 16 GB pool sizes. The control
alias keeps its own server connections, so lease renewals and authority checks do
not wait behind web traffic. PgBouncer sets its query wait per user, so control
traffic logs in as its own role, a member of the application role. A control query that finds no free server connection fails after one second,
before the lease agent's 1.5-second deadline; application clients keep
PgBouncer's two-minute wait.

`DATABASE_URL` points at the application alias and `DATABASE_CONTROL_URL` at the
control alias and role. Web and workers require both; the preset derives them
from `DB_USERNAME`, `DB_PASSWORD` and `DB_DATABASE`. Work that needs session
state or the server itself bypasses the pooler and connects to PostgreSQL
directly; no URL setting names that path. The direct path serves the pooler
credentials step, the preset's PostgreSQL settings verification, development
checkpoints, `pg_dump` backups run inside the PostgreSQL container and an
operator's `psql` session. The test configuration also points both URLs directly
at PostgreSQL.

The pooler authenticates with SCRAM-SHA-256 from an auth file. Before the pooler
starts, a one-off credentials step derives one verifier per role from
`DB_PASSWORD`, applies it with `ALTER ROLE` over a direct connection and writes
the same verifiers to `userlist.txt` in a dedicated pooler credentials volume.
Only the pooler mounts that volume, read-only. The file holds no plain-text or
MD5 password, and the pooler does not mount the application secrets volume. An
unchanged password keeps its verifiers, so rerunning the step leaves a running
pooler working.

PostgreSQL's `max_connections` is both pools plus a direct reserve of four plus
three superuser slots: 20 + 8 + 4 + 3 = 35 at the 16 GB `transcode` starting point
and 10 + 4 + 4 + 3 = 21 at the 4 GB `remux` starting point. The direct reserve
serves the direct connections above.

Pooled connections disable named server-side prepared statements. The image's
PostgreSQL driver frees them with SQL `DEALLOCATE`, and PgBouncer does not map that
statement to its own renamed statements, so the transaction would abort.

PostgreSQL runs in UTC. Every rendered PostgreSQL file sets `timezone = 'UTC'`, a
migration sets the database's default zone, and web and each worker supervisor
refuse to start when the server's default zone is not UTC.


## Server

| Variable | Default | Description |
|----------|---------|-------------|
| `DEFAULT_URI` | `https://localhost` | Base URL used by the router for generating absolute URLs. Should match `APP_URL` in production. |
| `HEALTH_MONITOR_INTERVAL_SECONDS` | `60` | Seconds between the web server's health checks, which raise [health alerts](../administration/notifications.md#health-alerts). |
| `WEB_DRAIN_SECONDS` | `30` | The web server's drain deadline: how many seconds a stop or restart of the web server may take at most. At least `8`; a smaller value stops the server from starting. Give the container a stop timeout of this value plus 5 seconds; see [Stopping and restarting the web server](../maintenance/upgrading.md#stopping-and-restarting-the-web-server). |

### Swoole

Configured in `config/packages/swoole.yaml`:

| Setting | Default | Description |
|---------|---------|-------------|
| `swoole.http_server.host` | `0.0.0.0` | Address to bind the HTTP server. |
| `swoole.http_server.port` | `9501` | HTTP server port. |
| `swoole.http_server.shutdown_timeout` | `WEB_DRAIN_SECONDS` | Seconds `swoole:server:stop` expects a stop to take; it waits 5 seconds longer for the server to exit. When the server is its container's PID 1, as in the shipped images, the command does not wait: it exits once the stop is requested. |
| `swoole.http_server.settings.heartbeat_check_interval` | `1` | Seconds between Swoole's checks for idle connections. A stop waits for the current interval to end before any worker stops, so keep it short. |

Swoole's `max_wait_time` is not set in this file. When the server starts, it derives the whole drain budget from the deadline D, `WEB_DRAIN_SECONDS`:

- the drain window, during which requests finish and clients are told to reconnect, is `floor((D - 4) / 2)`: 13 seconds at the default of 30;
- `max_wait_time` is `floor((D - 4 - window) / 2)`: 6 seconds at the default. After the window, Swoole stops the HTTP workers. The formula retains room for two Swoole stop phases;
- 4 seconds remain reserved as shutdown margin. Web starts no CPU process pool; encoders belong to the independent worker deployment.

The window, twice `max_wait_time` and those 4 seconds never add up to more than D.

The startup table of `app:serve` shows the derived `max_wait_time`, and the line the server logs when a drain starts names the deadline, the window and `max_wait_time`.

### Coroutine file I/O

The image builds Swoole 6.2 with its asynchronous thread backend for coroutine
file I/O. An io_uring-enabled build cannot read coroutine files under Docker's
default seccomp profile, and this Swoole version has no supported runtime INI
switch to select another backend. Rebuild the image with the shipped flags;
changing PHP INI or relaxing the worker's security profile is not the deployment
recipe.

Web configures `aio_core_worker_num: 1` and `aio_worker_num: 4`. Each private media
job sets both limits to `1` before its helper forks or a reactor starts. These
finite thread limits apply independently of the host CPU count. Include the AIO
and encoder threads in container PID accounting.

### PHP memory limit

The production image sets `memory_limit` to `512M` in
`/usr/local/etc/php/conf.d/zz-baander-production-memory.ini`. Processes use that
image default unless their launch command supplies an override. The
`app:health:check` HEALTHCHECK reads this default too. To change the image default,
override the file or add a later one in `conf.d`; explicit launch limits still take
precedence. The managed preset starts web with the selected profile's PHP heap limit; the worker supervisor
(`app:worker`) sets its children's limits with `-d`. Every capability and size uses a 256 MiB
PHP heap while reserving additional native and management memory. The web server's
[memory check](../operations/health-and-metrics.md#what-is-checked) measures each HTTP worker against its
effective limit. Changing PHP's heap limit alone does not change container ceilings
or the worker's admission reservation.

