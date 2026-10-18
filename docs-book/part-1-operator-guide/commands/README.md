# CLI Commands

Baander ships console commands for managing users, libraries, the catalog, metadata, background jobs and the server. The console commands below run inside the app container. Worker deployment uses a separate host entrypoint.

```bash
# Run any command
make exec cmd="php bin/console <command>"

# Example
make exec cmd="php bin/console app:user:create --help"
```

## Worker deployment

Run `bin/worker-deployment.php` from a trusted host checkout with Composer dependencies,
PHP 8.5 with extensions `posix` and `pdo_pgsql`, and an absolute Docker executable connected
to a local Unix socket. The application container receives no Docker authority.
Provision the migrated database, Redis and a private Docker network first; use a
locally available immutable image ID. The version 3 recipe admits a fixed pair
of control and execution containers,
with separate resource ceilings and disjoint CPU sets. It requires explicitly
authorized shared media volumes and a private named IPC volume; devices and bind
mounts are refused.

Build the worker target locally before selecting its immutable image ID:

```bash
docker buildx build --target worker --load -t baander-worker:local .
```

The manifest is a JSON object of at most 8192 bytes. Version 3 requires exactly
these fields, with JSON integers for version and resource values:

```json
{
  "version": 3,
  "namespace": "worker.baander.app",
  "bootId": "0123456789abcdef0123456789abcdef",
  "daemonId": "replace-with-docker-info-ID",
  "imageId": "sha256:0000000000000000000000000000000000000000000000000000000000000000",
  "network": "baander-workers",
  "dockerBinary": "/usr/bin/docker",
  "dockerEndpoint": "unix:///var/run/docker.sock",
  "controlMemoryMiB": 2624,
  "executionMemoryMiB": 4480,
  "controlNanoCpus": 1000000000,
  "executionNanoCpus": 2000000000,
  "controlPidsLimit": 128,
  "executionPidsLimit": 256,
  "controlCpuSet": [0],
  "executionCpuSet": [1, 2],
  "scheduledConsoleMiB": 320,
  "ipcVolume": "baander-worker-ipc",
  "mediaMounts": [
    {"volume": "baander-source", "target": "/media", "readOnly": true, "role": "source"},
    {"volume": "baander-conversions", "target": "/storage/conversions", "readOnly": false, "role": "derived"},
    {"volume": "baander-storage", "target": "/var/www/html/storage", "readOnly": false, "role": "derived"},
    {"volume": "baander-transcode-state", "target": "/var/www/html/var/transcode_state", "readOnly": false, "role": "derived"}
  ]
}
```

Replace the daemon/image examples with `docker info --format '{{.ID}}'` and the
local image's `docker image inspect --format '{{.Id}}'` values on the same endpoint.
Generate a fresh 32-character lowercase hexadecimal boot ID for each deployment
attempt. Control requires at least 2624 MiB and 128 PID slots; execution requires
at least 4160 MiB plus its scheduled console reservation and 256 PID slots.
Scheduled console execution is disabled at `0` or reserves at least 320 MiB in
execution. The example enables it with a 4480 MiB execution ceiling. CPU sets must
be nonempty and disjoint; select available host CPUs explicitly. The example's
CPU placement and quotas are operator choices, not universal defaults or measured
capacity recommendations. Docker counts threads toward each unit's PID ceiling.
Creation also requires host memory for both ceilings plus 1 GiB and verifies that
the declared CPU identifiers are available on that daemon.
The fixed workload admits three video jobs, one audio rendition and no devices.
The worker argv,
PHP management limit, identity and lock directory are fixed by the manifest parser;
arbitrary commands, environment fields and secret paths are rejected.

Provision the named media and IPC volumes before creation, with the local Docker
volume driver and no driver options. The IPC volume is private to the pair and
mounted at `/run/baander-worker`; do not share it with another boot or web. Before
`create`, set the IPC volume root owner and group to the runtime UID/GID of the
selected worker image, with mode `0700`. Docker creates a new volume root owned
by root, and the admitted mount uses `volume-nocopy`; an unprepared root prevents
the worker from creating its private boot directory. Read the image's runtime
identity rather than assuming a numeric UID/GID. Runtime creates boot directories
with mode `0700` and the native socket with mode `0600`.

Source volumes are read-only in worker; derived volumes are writable. The
declarations must cover `/var/www/html/storage`,
`/var/www/html/var/transcode_state` and the configured `CONVERT_STORAGE_PATH`.
At least one source volume is required. Set `MEDIA_STORAGE_PATH` explicitly to the
source root inside that volume, for example `/media/library`; never point it at
derived output. Its canonical absolute path must be covered by a read-only source
declaration. Use the same `MEDIA_STORAGE_PATH` in web. Mount the same volumes at the same paths
in web, giving web writable access where authorized library deletion requires it.
Copy existing media into these volumes explicitly; the development Compose bind
mounts do not provide this shared topology. Do not reset or replace existing media.

Mount targets cannot overlap or replace code, secrets, operating-system paths or
worker-private paths. Bind mounts, devices, extra mounts and source volumes
aliased as writable derived storage are refused. Creation/start/recovery checks
verify declared names, concrete local-volume mountpoints and access modes. A
changed recipe requires a fresh admitted deployment.

Keep credentials in a separate canonical absolute regular file owned by the
invoking user, with mode `0600`, no symlink components and at most 8192 bytes:

```json
{
  "controllerDatabaseUrl": "postgresql://worker:replace-password@db-control.baander.app/baander?serverVersion=18&charset=utf8",
  "runtimeEnvironment": {
    "APP_ENV": "prod",
    "APP_DEBUG": "0",
    "APP_SECRET": "replace-with-runtime-secret",
    "DATABASE_URL": "postgresql://worker:replace-password@db-worker.baander.app/baander?serverVersion=18&charset=utf8",
    "REDIS_URL": "redis://:replace-password@redis.baander.app:6379",
    "MESSENGER_TRANSPORT_DSN": "redis://:replace-password@redis.baander.app:6379/messages",
    "CONVERT_STORAGE_PATH": "/storage/conversions",
    "MEDIA_STORAGE_PATH": "/media/library"
  }
}
```

The two database addresses must reach the same database from the host and worker
network respectively. The controller URL and credentials file stay on the host.
Docker receives the admitted runtime values through a temporary private env-file;
trusted daemon operators can inspect the resulting container environment.
Additional runtime keys are `CONVERT_STORAGE_PATH`, `MEDIA_STORAGE_PATH`,
`REDIS_PASSWORD`, `MAILER_DSN`,
`MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`; without the last two, email a worker sends comes from
`noreply@localhost`. Values cannot contain
line breaks and their complete env-file is limited to 4 KiB.

Worker credentials admit only canonical Redis URL forms that preserve the
`messages` and `scheduler_occurrences` streams, the `baander` group and the
generated consumer identity. Invalid forms fail before container creation;
`app:worker` checks again before child launch. Errors identify the setting without
printing its credential-bearing value. See [Messenger configuration](../configuration.md#messenger)
for accepted paths and query options. These checks do not establish connectivity
or Redis durability.

Apply an external deadline to every action. DBAL does not enforce a hard connection
deadline through `connect_timeout`; that query option is rejected here.

```bash
php bin/worker-deployment.php --help
timeout --kill-after=5s 60s php bin/worker-deployment.php create /etc/baander/worker-manifest.json /etc/baander/worker-credentials.json
timeout --kill-after=5s 60s php bin/worker-deployment.php start /etc/baander/worker-manifest.json /etc/baander/worker-credentials.json
timeout --kill-after=5s 60s php bin/worker-deployment.php status /etc/baander/worker-manifest.json /etc/baander/worker-credentials.json
```

`create` reserves the boot and both unit intents before Docker work. If its reply
is uncertain, use `reconcile-create` with the identical manifest and credentials;
it verifies and registers existing never-started units without recreating a
claimed unit. `start` consumes a one-shot claim for each unit before issuing Docker
start. An uncertain start requires
explicit `recover`, never another start. Recovery retires that immutable boot,
requires confirmed retirement of both units before releasing the joint reservation.
An unclaimed unit can be cancelled; a claimed unit requires containment evidence.
Keep the old manifest for
recovery; a replacement needs a fresh boot ID. `status` reports committed
observations and always returns `readiness: "not_checked"`; it does not certify
application readiness. Control owns and renews the deployment lease; execution
observes it. Both units must exchange matching boot/epoch evidence before business
admission, within 30 seconds of startup. Peer loss, stale evidence or authority
loss closes admission and drains the pair. Each supervisor has a 35-second drain
deadline; give external stop handling a longer grace period. Timeout or
interruption does not cancel an operation
already submitted to Docker or authorize a retry.

Actions return bounded JSON on stdout. Exit codes are `0` for a confirmed result,
`1` for `operation_unconfirmed`, `2` for `invalid_configuration`, and `3` for a
denied or unregistered operation. The external `timeout` command may return its
own exit code without JSON. Error output contains no credentials or raw diagnostics.

## Auth & Users

| Command | Description |
|---------|-------------|
| [app:auth:rotate-secrets](app-auth-rotate-secrets.md) | Prepare an OAuth bundle and invalidate grants during offline cutover |
| [app:auth:setup-clients](app-auth-setup-clients.md) | Create the first-party OAuth client that password and passkey login issue tokens to |
| [app:login-block:delete](app-login-block-delete.md) | Remove one login block, or every block with `--all` |
| [app:login-block:list](app-login-block-list.md) | List the login honeypot's blocks, newest first |
| [app:oauth:client:create](app-oauth-client-create.md) | Register a device, public or confidential OAuth client |
| [app:oauth:client:list](app-oauth-client-list.md) | List OAuth clients other than personal access clients |
| [app:oauth:client:revoke](app-oauth-client-revoke.md) | Revoke an OAuth client and every token issued to it |
| [app:oauth:client:rotate-secret](app-oauth-client-rotate-secret.md) | Give a confidential OAuth client a new secret |
| [app:oauth:generate-keys](app-oauth-generate-keys.md) | Generate OAuth2 private and public keys for JWT signing |
| [app:oauth:purge-codes](app-oauth-purge-codes.md) | Delete authorization and device codes that expired more than an hour ago |
| [app:user:change-email](app-user-change-email.md) | Change a user's email address and send a verification link to it |
| [app:user:create](app-user-create.md) | Create a new user account |
| [app:user:delete](app-user-delete.md) | Delete a user account |
| [app:user:disable](app-user-disable.md) | Disable a user account and end their sessions |
| [app:user:enable](app-user-enable.md) | Enable a previously disabled user account |
| [app:user:list](app-user-list.md) | List users, newest first |
| [app:user:rename](app-user-rename.md) | Change a user's display name |
| [app:user:reset-password](app-user-reset-password.md) | Set a new password for a user and sign them out everywhere |
| [app:user:roles](app-user-roles.md) | Replace a user's roles |
| [app:user:setting](app-user-setting.md) | Show, set or reset a user's setting, such as their email language |

## Server Settings

| Command | Description |
|---------|-------------|
| [app:settings:definitions](app-settings-definitions.md) | List the definition of every setting, server-wide and per-user |
| [app:settings:get](app-settings.md#appsettingsget) | Show a server setting: its value, default and stored value |
| [app:settings:list](app-settings.md#appsettingslist) | List every server setting with its value, default and enforcement |
| [app:settings:reset](app-settings.md#appsettingsreset) | Reset a server setting to its default |
| [app:settings:set](app-settings.md#appsettingsset) | Set a server setting |

## Libraries & Catalog

| Command | Description |
|---------|-------------|
| [app:album:cover:remove](app-album-cover-remove.md) | Remove an album cover and delete its image files |
| [app:album:cover:set](app-album-cover-set.md) | Set or replace an album cover from an image file in the container |
| [app:album:delete](app-album-delete.md) | Delete an album and every song on it, optionally with the audio files |
| [app:album:duplicates](app-album-duplicates.md) | List the groups of albums in a library that look like duplicates |
| [app:album:extract-covers](app-album-extract-covers.md) | Queue embedded cover art extraction for every album without a cover |
| [app:album:merge](app-album-merge.md) | Merge a source album into a target album and delete the source |
| [app:album:update](app-album-update.md) | Change an album's metadata, or lock and unlock its fields |
| [app:artist:album:add](app-artist-album-add.md) | Credit an artist on an album with a role |
| [app:artist:album:remove](app-artist-album-remove.md) | Remove every one of an artist's credits on an album |
| [app:artist:album:role](app-artist-album-role.md) | Change the role of an artist's credit on an album |
| [app:artist:cover:remove](app-artist-cover-remove.md) | Remove an artist cover and delete its image files |
| [app:artist:cover:set](app-artist-cover-set.md) | Set or replace an artist cover from an image file in the container |
| [app:artist:create](app-artist-create.md) | Create an artist, such as a performer to credit by hand |
| [app:artist:delete](app-artist-delete.md) | Delete an artist, its credits and its cover image |
| [app:artist:song:add](app-artist-song-add.md) | Credit an artist on a song with a role |
| [app:artist:song:remove](app-artist-song-remove.md) | Remove every one of an artist's credits on a song |
| [app:artist:song:role](app-artist-song-role.md) | Change the role of an artist's credit on a song |
| [app:artist:update](app-artist-update.md) | Change an artist's metadata, or lock and unlock its fields |
| [app:genre:album:add](app-genre-album-add.md) | Assign a genre to an album |
| [app:genre:album:remove](app-genre-album-remove.md) | Remove a genre from an album |
| [app:genre:create](app-genre-create.md) | Create a genre, optionally below a parent genre |
| [app:genre:delete](app-genre-delete.md) | Delete a genre; its child genres become root genres |
| [app:genre:list](app-genre-list.md) | List every genre, or show the genre hierarchy with `--tree` |
| [app:genre:song:add](app-genre-song-add.md) | Assign a genre to a song |
| [app:genre:song:remove](app-genre-song-remove.md) | Remove a genre from a song |
| [app:genre:update](app-genre-update.md) | Rename a genre, change its slug or MusicBrainz ID, or move it below another parent |
| [app:library:create](app-library-create.md) | Register a new media library |
| [app:library:delete](app-library-delete.md) | Delete a media library |
| [app:library:list](app-library-list.md) | List every media library |
| [app:library:member:grant](app-library-member-grant.md) | Let a user see a library |
| [app:library:member:list](app-library-member-list.md) | List every library with whether a user may see it |
| [app:library:member:revoke](app-library-member-revoke.md) | Stop a user seeing a library |
| [app:library:scan](app-library-scan.md) | Scan a media library, or every library, in this process |
| [app:library:show](app-library-show.md) | Show one media library |
| [app:library:stats](app-library-stats.md) | Show the content counts of one media library |
| [app:library:update](app-library-update.md) | Rename a media library or change its sort order |
| [app:library:validate-path](app-library-validate-path.md) | Check that a directory can serve as a media library |
| [app:movie:delete](app-movie-delete.md) | Delete a movie and the videos no other movie uses |
| [app:movie:update](app-movie-update.md) | Change a movie's title, year or summary |
| [app:song:delete](app-song-delete.md) | Delete a song, optionally with its audio file |
| [app:song:update](app-song-update.md) | Change a song's metadata, or lock and unlock its fields |
| [app:watch-files](app-watch-files.md) | Watch directories for filesystem changes |

## Media & Lyrics

| Command | Description |
|---------|-------------|
| [app:image:prune-missing](app-image-prune-missing.md) | Delete image records whose files no longer exist in storage |
| [app:image:stats](app-image-stats.md) | Show how many images are stored and how much space they use, by type |
| [app:lyrics:apply](app-lyrics-apply.md) | Store an LRCLIB search result as the lyrics of a song that has none |
| [app:lyrics:coverage](app-lyrics-coverage.md) | Show how many tracks have lyrics, and the lyrics by source |
| [app:lyrics:fetch](app-lyrics-fetch.md) | Queue a lyrics fetch from LRCLIB for songs without lyrics |
| [app:lyrics:search](app-lyrics-search.md) | Search LRCLIB for lyrics and list the results with their IDs |
| [app:lyrics:status](app-lyrics-status.md) | Show the lyrics fetch jobs of the past 7 days and how many finished or failed |
| [app:song:lyrics:fetch](app-song-lyrics-fetch.md) | Fetch the lyrics of one song from LRCLIB and store them |

## Metadata

| Command | Description |
|---------|-------------|
| [app:metadata:providers](app-metadata-providers.md) | List the metadata providers and whether each one is configured |
| [app:metadata:status](app-metadata-status.md) | Show track coverage, the last sync and the sync jobs by type |
| [app:metadata:sync](app-metadata-sync.md) | Queue a metadata sync for every library, or only the genre sync |

## Listening Activity

| Command | Description |
|---------|-------------|
| [app:activity:engagement](app-activity-engagement.md) | Show the active users and their average plays and listening time over a range of days |
| [app:activity:summary](app-activity-summary.md) | Show the plays, distinct tracks and artists, and listening time over a range of days |
| [app:activity:top-artists](app-activity-top-artists.md) | List the most played artists over a range of days |
| [app:activity:top-tracks](app-activity-top-tracks.md) | List the most played tracks over a range of days |

## Radio

| Command | Description |
|---------|-------------|
| [app:radio:country:list](app-radio-country-list.md) | List the countries the station directory offers, with their station counts |
| [app:radio:source:create](app-radio-source-create.md) | Create a radio source that station data is synced from |
| [app:radio:station:list](app-radio-station-list.md) | List the synced stations, optionally by country or search text |
| [app:radio:sync](app-radio-sync.md) | Sync stations for every country a user subscribes to |

## Recommendations

| Command | Description |
|---------|-------------|
| [app:recommendation:generate](app-recommendation-generate.md) | Generate music recommendations with every strategy, in this process |
| [app:recommendation:job:cancel](app-recommendation-job-cancel.md) | Cancel a pending or running recommendation job |
| [app:recommendation:job:list](app-recommendation-job-list.md) | List the most recent recommendation jobs, newest first |
| [app:recommendation:job:requeue](app-recommendation-job-requeue.md) | Run a failed or cancelled recommendation job again as a new job |
| [app:recommendation:job:show](app-recommendation-job-show.md) | Show one recommendation job: status, progress, counts and metadata |
| [app:recommendation:stats](app-recommendation-stats.md) | Show recommendation coverage, source quality and freshness |

## Transcoding & Streaming

| Command | Description |
|---------|-------------|
| [app:qol:profile](app-qol-profile.md) | Set the stream governor's algorithm profile in every web server worker |
| [app:qol:reset](app-qol-reset.md) | Discard the stream governor's learning data in every web server worker |
| [app:qol:status](app-qol-status.md) | Show the stream governor status of every web server worker |
| [app:qol:streams](app-qol-streams.md) | List the streams every web server worker has admitted, with their predicted cost |
| [app:transcode:cache-sweep](app-transcode-cache-sweep.md) | Delete old transcode cache directories by age and size budget |
| [app:transcode:job:cleanup](app-transcode-job-cleanup.md) | Remove transcode jobs that no session uses, with their output |
| [app:transcode:session:list](app-transcode-session-list.md) | List the active transcode sessions of every user, or of one user |
| [app:transcode:session:show](app-transcode-session-show.md) | Show one transcode session |

## Scheduler

| Command | Description |
|---------|-------------|
| [app:scheduler:commands](app-scheduler-commands.md) | List the commands a scheduled job can run, with their parameters |
| [app:scheduler:create](app-scheduler-create.md) | Create a scheduled job that runs a schedulable command on a cron schedule |
| [app:scheduler:delete](app-scheduler-delete.md) | Delete a scheduled job |
| [app:scheduler:disable](app-scheduler-disable.md) | Disable a scheduled job until it is enabled |
| [app:scheduler:enable](app-scheduler-enable.md) | Enable a disabled scheduled job |
| [app:scheduler:list](app-scheduler-list.md) | List all scheduled jobs |
| [app:scheduler:pause](app-scheduler-pause.md) | Pause a scheduled job until it is resumed |
| [app:scheduler:resume](app-scheduler-resume.md) | Resume a paused scheduled job |
| [app:scheduler:run](app-scheduler-run.md) | Record a durable manual request for a scheduled job |
| [app:scheduler:show](app-scheduler-show.md) | Show a scheduled job with its schedule, parameters and last run |
| [app:scheduler:update](app-scheduler-update.md) | Change a scheduled job's name, schedule, command, description or parameters |

## Notifications

| Command | Description |
|---------|-------------|
| [app:generate-vapid-keys](app-generate-vapid-keys.md) | Generate VAPID keys for push notifications |
| [app:webhook:create](app-webhook-create.md) | Add a webhook and print its signing secret, which is shown only once |
| [app:webhook:delete](app-webhook-delete.md) | Delete a webhook |
| [app:webhook:list](app-webhook-list.md) | List the configured webhooks, without their secrets |
| [app:webhook:rotate-secret](app-webhook-rotate-secret.md) | Replace a webhook's signing secret and print the new one once |
| [app:webhook:update](app-webhook-update.md) | Change a webhook's URL or the notification categories it receives |

## Web Server & Workers

| Command | Description |
|---------|-------------|
| [app:outbox:consume](app-outbox-consume.md) | Relay durable notification events and delivery intents |
| [app:server:coroutines](app-server-coroutines.md) | Show coroutine and channel statistics of every web server worker |
| [app:server:drain:watch](app-server-drain-watch.md) | Kill the web server processes still running at the drain deadline (started by app:serve) |
| [app:server:spans](app-server-spans.md) | List the web server's recent request spans, or empty the span buffer |
| [app:server:stats](app-server-stats.md) | Show memory, process and coroutine figures of every web server worker |
| [app:server:workers](app-server-workers.md) | Show the web server's HTTP and user worker statistics |
| [app:worker](app-worker.md) | Run one unit of the paired worker deployment |

## System & Diagnostics

| Command | Description |
|---------|-------------|
| [app:cli:manifest](app-cli-manifest.md) | Output a JSON manifest of all CLI commands and tooling metadata |
| [app:config:validate](app-config-validate.md) | Validate application configuration and check for misconfigurations |
| [app:discovery:register](app-discovery-register.md) | Register a self-hosted server for discovery and pairing |
| [app:health:check](app-health-check.md) | Check the health of all system components |
| [app:monitor:analytics](app-monitor-analytics.md) | Show background job analytics for a time range: summary, timing or failures |
| [app:monitor:job:cancel](app-monitor-job-cancel.md) | Cancel a running background job, or the work a finished job queued |
| [app:monitor:job:retry](app-monitor-job-retry.md) | Dispatch a failed background job's message again under a new job ID |
| [app:monitor:job:show](app-monitor-job-show.md) | Show one background job with its error, stored message and run time |
| [app:monitor:jobs](app-monitor-jobs.md) | List background jobs with the job monitor's filters, sorting and pages |
| [app:monitor:prune](app-monitor-prune.md) | Prune completed job monitors older than a given age |
| [app:monitor:status](app-monitor-status.md) | Show background job counts by status and the jobs running now |
| [app:monitor:transport](app-monitor-transport.md) | Show the async queue depth, the failed message count and the consumer's registration |
| [app:rate-limiter:clear](app-rate-limiter-clear.md) | Clear the stored state of one rate limiter, or of all of them |
| [app:rate-limiter:list](app-rate-limiter-list.md) | List every configured rate limiter with its effective configuration |
| [debug:hw-transcode](debug-hw-transcode.md) | Show resolved hardware encoder profile and sample FFmpeg commands |

### Failed messages

Messages that exhaust their retries land in the failure transport, a PostgreSQL table. Two Baander commands work on the whole transport, including messages waiting out a retry delay, as the admin endpoints under `/api/monitor/transport/failed` do (see [Monitoring](../monitoring.md#failed-messages)):

| Command | Description |
|---------|-------------|
| [app:failed-message:flush](app-failed-message-flush.md) | Remove every message from the failure transport |
| [app:failed-message:list](app-failed-message-list.md) | List the failed messages, newest first |

Symfony's built-in commands handle one message by ID. Pass `--force` to skip the confirmation prompt, which non-interactive runs require.

| Command | Description |
|---------|-------------|
| `messenger:failed:show <id>` | Show one failed message |
| `messenger:failed:retry <id> --force` | Handle a failed message again; one that fails again returns under a new ID and stays listed |
| `messenger:failed:remove <id> --force` | Remove one failed message |

## Development & Docs

| Command | Description |
|---------|-------------|
| [app:dev:create-users](app-dev-create-users.md) | Create the standard development users |
| [app:dev:setup](app-dev-setup.md) | Bootstrap the dev environment (migrations, keys, clients, users) |
| [app:e2e:ingest-video](app-e2e-ingest-video.md) | Ingest a directory of video files as a movie library, without workers |
| [app:export-openapi-spec](app-export-openapi-spec.md) | Export the OpenAPI spec to a file |
| [app:generate-docs](app-generate-docs.md) | Generate the documentation site from source code and docs-book/ |
