# Deployment Profiles and Runtime

Startup profiles, media slots, the PgBouncer connection pooler, and Swoole runtime settings bound web and worker capacity across a Bånder deployment.

## Deployment profiles

Choose `full` or `household` before starting the deployment. The default is `full`.
The profile sets web and worker capacity together; it is not a live server setting.

| Startup capability | `full` | `household` |
|--------------------|--------|-------------|
| Web workers | 4 | 2 |
| Doctrine connection slots per web worker | 10 | 4 |
| Maximum resident ordinary consumers | 4 | 1 |
| Shared audio/video copy slots | 0 | 2 |
| Video slots | 8 by default, 0–145 | 0 |
| Audio slots | 12 by default, 0–217 | 0 |

In `full`, stream copy uses the slots of its kind: a video copy takes a video
slot and an audio copy an audio slot; there is no separate shared pool. The
household profile permits original-file delivery, compatible completed cache
entries and stream copy without audio or video re-encoding. It retains ordinary
library maintenance, scheduling, relay and media coordination. When original,
cached and copied media cannot meet the receiving browser's requirements, playback
returns a compatibility explanation. Subtitle burn-in requires re-encoding.
Changing the audio transcoding setting does not enable an encoder in this profile.
See [Transcoding](../media/transcoding.md#how-it-works) for delivery selection.

`BAANDER_DEPLOYMENT_PROFILE` selects the profile. The generated
`BAANDER_DEPLOYMENT_PROFILE_JSON` records its complete effective settings,
including the slot counts. If that JSON is supplied, it must agree with the
selected identifier. Web, workers and the version 8 worker manifest must agree;
conflicting settings fail startup, and web health reports a profile that differs
from the active worker boot's. Print the effective profile, or one built from
other slot counts, with
[`app:deployment:profile:show`](../commands/app-deployment-profile-show.md).

For the managed complete-stack preset, set `profile` in its private configuration
and run the host controller:

```bash
php scripts/deploy-household.php render --config /srv/baander/household.json --state /srv/baander/household-state
php scripts/deploy-household.php start --config /srv/baander/household.json --state /srv/baander/household-state
php scripts/deploy-household.php status --state /srv/baander/household-state
php scripts/deploy-household.php stop --state /srv/baander/household-state
```

The controller resolves available images to immutable IDs and starts the gateway,
web, PostgreSQL, the connection pooler, Redis and worker pair. It does not build or
pull images. A later `start` retires the saved workers and stops the saved services
before installing the selected configuration. `stop` retains named data volumes.
Preserve the private state directory to recover an interrupted operation. See
[Getting started](../getting-started/installation.md) for image preparation and account setup.

No rendered service or worker unit carries a CPU, memory or PID limit or a CPU
set. The only limit the preset keeps is Redis's own `maxmemory`, set by
`resources.redisMaxmemoryMiB` (default 192). Media work is bounded by the media
slots instead; [System Requirements](../getting-started/system-requirements.md) explains how to choose
them and the kill order that applies under host memory pressure. A 4 GB host and Raspberry Pi
support remain unqualified.

### Media slots

In the `full` preset, set the slots with an optional `media` object:

```json
{
    "profile": "full",
    "media": {"videoSlots": 8, "audioSlots": 12}
}
```

`videoSlots` and `audioSlots` are each optional. An unset count takes its default,
8 video or 12 audio, and an absent `media` object takes both defaults. Zero
disables that kind: instead of waiting for a slot, its conversions and stream
copies are refused with the compatibility explanation described above. The
`household` preset rejects a `media` object because its two shared copy slots are
fixed. A source installation sets the same counts through
`BAANDER_DEPLOYMENT_PROFILE_JSON` and the worker manifest's `deploymentProfile`;
`app:deployment:profile:show` prints both.

A `hardware` entry in the same object names a hardware encoder, for example
`"hardware": {"accelerator": "vaapi", "device": "/dev/dri/renderD128"}`. When a
hardware encoder is named, unset video slots default to 12 instead of 8. [Hardware
Acceleration](../media/transcoding.md#hardware-acceleration) describes the fields, the
device pass-through and the startup check.

The maximums come from the pooler, the only fixed resource bound left. PgBouncer
admits 500 client connections. In `full`, 65 of them are held by the web workers,
worker roles, scheduler, lease agents, and an operator's console command and
deployment controller, which leaves 435 for media jobs. A video job opens 3 pooled
connections and an audio job 2, so a profile accepts at most 145 video slots or 217
audio slots, and both kinds together must satisfy `3 × videoSlots + 2 × audioSlots
≤ 435`. Rendering the preset and building the profile both reject a larger value
and name the bound it exceeds.

### Connection pooler

Every deployment reaches PostgreSQL through PgBouncer 1.26.1, run from the database
image as its own `pooler` service on port 6432. It runs in transaction mode: a
client holds a server connection only for the length of a transaction, so
PostgreSQL's backend count follows the pooler's server pools instead of the number
of clients. The pooler starts once PostgreSQL is healthy and runs without resource
limits.

The pooler exposes two aliases, each with its own server pool:

| Alias | Clients | Role | Server pool `household` / `full` |
|-------|---------|------|-----------------------------------|
| Application, named like the database | Web, ordinary workers, media jobs, console commands and migrations | The database user | 10 / 20 |
| Control, the database name with `_control` appended | Worker lease agents, media authority checks and the scheduler | The database user's name with `_control` appended | 4 / 8 |

The source installation's Compose file uses the `full` pool sizes. The control
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

PostgreSQL's `max_connections` is both pools plus a direct reserve plus three
superuser slots: 10 + 4 + 3 + 3 = 20 for `household` and 20 + 8 + 4 + 3 = 35 for
`full`. The direct reserve serves the direct connections above.

Pooled connections disable named server-side prepared statements. The image's
PostgreSQL driver frees them with SQL `DEALLOCATE`, and PgBouncer does not map that
statement to its own renamed statements, so the transaction would abort.

PostgreSQL runs in UTC. Both preset PostgreSQL files set `timezone = 'UTC'`, a
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
(`app:worker`) sets its children's limits with `-d`. Current profiles use a 256 MiB
PHP heap while reserving additional native and management memory. The web server's
[memory check](../operations/health-and-metrics.md#what-is-checked) measures each HTTP worker against its
effective limit. Changing PHP's heap limit alone does not change container ceilings
or the worker's admission reservation.

