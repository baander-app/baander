# Worker Deployment

Baander runs background work in a fixed pair of control and execution containers
provisioned from the host by `bin/worker-deployment.php`. This page defines how
the worker pair functions, the version 8 host manifest and credentials format,
and the host commands that create, start, inspect and recover the pair. Read
[Background Work](background-work.md) for queue flow, observation and recovery
procedures. The complete-stack controller, `php scripts/deploy-household.php`,
uses this worker lifecycle for both startup profiles; its `render`, `start`,
`status` and `stop` actions manage the surrounding services too (see
[Deployment profiles](configuration.md#deployment-profiles)).

## How workers function

Run `bin/worker-deployment.php` from a trusted host checkout with Composer dependencies,
PHP 8.5 with extensions `posix` and `pdo_pgsql`, and an absolute Docker executable connected
to a local Unix socket. The application container receives no Docker authority.
Provision the migrated database, Redis and a private Docker network first; use a
locally available immutable image ID. The version 8 recipe admits a fixed pair
of control and execution containers without CPU, memory or PID limits and without
CPU sets. It requires explicitly authorized shared media volumes and a private
named IPC volume. The only device it admits is the profile's hardware encoder, in
execution; the production source policy also refuses bind mounts. The Docker daemon
must run rootful: creation refuses a rootless daemon, which cannot start the control
unit at its negative out-of-memory score.

Inside the admitted pair, each container runs [`app:worker`](commands/app-worker.md)
as PID 1 under one deployment namespace and boot identity:

- **Control** renews the database deployment lease and supervises the outbox relay,
  scheduler poller, autoscaler and media coordinator.
- **Execution** observes the lease and runs the ordinary `async` consumers, one
  separate `scheduler` consumer and the native execution daemon that launches
  probes, FFmpeg encoders, media CPU helpers and scheduled console children.

Creation starts both units without container limits and verifies that Docker
reports no limit on either unit and each unit's out-of-memory score: −500 for
control and 300 for execution. The native daemon raises each FFmpeg command it
launches to 600, and each PHP media child raises its own score to 600; see
[Kill order](system-requirements.md#kill-order).

## Building the worker image

Build the worker image with the same layout-aware builder used in
[Getting started](getting-started.md#3-build-images-and-start-dependencies).
Run on the host after installing its standalone tooling dependencies:

```bash
php scripts/build-application.php --target worker --tag baander-worker:local --compose-env .env.worker-image.local
. "$(pwd)/.env.worker-image.local"
export APP_HOME
```

The generated file contains `BAANDER_APPLICATION_IMAGE` and `APP_HOME`, without
application credentials. Put that immutable image ID in the manifest's `imageId`;
keep the corresponding `APP_HOME` exported for each controller invocation. The
controller rejects an unset `APP_HOME`. The storage targets below illustrate the
current `/var/www/html` image layout; align them with the selected image's layout.

## Manifest format

The manifest is a JSON object of at most 8192 bytes. Version 8 requires exactly
these fields, with JSON integers for `version` and `scheduledConsoleMiB`:

```json
{
  "version": 8,
  "sourceMountPolicy": "production",
  "namespace": "worker.baander.app",
  "bootId": "0123456789abcdef0123456789abcdef",
  "daemonId": "replace-with-docker-info-ID",
  "imageId": "sha256:0000000000000000000000000000000000000000000000000000000000000000",
  "network": "baander-workers",
  "dockerBinary": "/usr/bin/docker",
  "dockerEndpoint": "unix:///var/run/docker.sock",
  "scheduledConsoleMiB": 320,
  "deploymentProfile": {
    "identifier": "full",
    "hardware": null,
    "webWorkers": 4,
    "doctrinePoolSlots": 10,
    "ordinaryConsumerLimit": 4,
    "sharedCopySlots": 0,
    "videoEncoderSlots": 8,
    "audioEncoderSlots": 12
  },
  "deviceGroup": null,
  "autoscaling": {
    "minimumConcurrency": 1,
    "maximumConcurrency": 2,
    "sampleIntervalMs": 1000,
    "maxSampleAgeMs": 3000,
    "targetBacklogAgeMs": 10000,
    "scaleUpSustainMs": 3000,
    "scaleDownSustainMs": 30000,
    "changeCooldownMs": 5000,
    "cpuCeilingPercent": 85,
    "memoryCeilingPercent": 85,
    "pidsCeilingPercent": 85,
    "memoryStopPercent": 95,
    "pidsStopPercent": 95,
    "coldDurationMs": 1000
  },
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
attempt. Scheduled console execution is disabled at `0` and enabled by a value of
at least 320; the example enables it. The manifest declares no memory, CPU, PID or
CPU-set values.

### Deployment profile and media slots

The `deploymentProfile` object is required. Its complete settings must form a
valid profile and match the worker's runtime environment. `full` permits up to
four resident ordinary consumers and the configured video and audio slots; the
example carries the defaults of 8 and 12. `household` permits one ordinary
consumer and two shared audio/video copy jobs; it does not permit encoding. Print
the object for chosen slots with
[`app:deployment:profile:show`](commands/app-deployment-profile-show.md) instead of writing
it by hand; [Media slots](configuration.md#media-slots) gives the maximums.
These settings do not establish measured memory use or a minimum host size.

### Autoscaling and ordinary consumers

The `autoscaling` object is required. Every field must be a JSON integer; missing
or unknown fields are rejected. All fields contribute to both unit recipe
fingerprints, including timing and pressure thresholds. There is no compatibility
parser for older manifests. Changing any profile field requires retirement and a
fresh boot; see [Upgrading](upgrading.md#standard-update).

The `full` example recommends minimum one and maximum two for ordinary work on the
measured envelope: an AMD Ryzen 7 5800X3D host with eight cores, sixteen threads
and 64 GiB of physical RAM. The measurement predates the removal of container
limits: it reserved one CPU for control, two disjoint CPUs for execution, and
another CPU for dependencies and web.
Two 48-message bursts of two-second simulated I/O completed in 56.99 and
56.97 seconds, compared with 97.42 and 97.43 seconds at maximum one, about 1.71×
faster. The maximum-two run returned to target one between bursts and reduced the
target under CPU pressure. Maximum four completed the bursts in 39.21 and
38.48 seconds, 2.48× and 2.53× faster than maximum one. It is an option for this
I/O-heavy envelope. Maximum two is the general starting recommendation: it uses
fewer resident processes and connections, and maximum four did not improve the
measured CPU-work completion time.

This recommendation covers the recorded synthetic workload and placement; it does
not establish external-provider latency, unrestricted concurrency or smaller-host
capacity. The first burst follows harness work and already initialized duration
history. During media pressure, three video encoders were active and one audio
encoder was deliberately stopped after initial output to retain its occupied slot;
uninterrupted audio throughput during pressure was not measured. See the
[qualification record](../part-2-developer-guide/testing.md#ordinary-worker-autoscaling-qualification)
for results and limits. The internal profile constructor remains conservative at
maximum one; every production manifest must supply all fourteen fields explicitly.

Concurrency bounds must satisfy `1 <= minimumConcurrency <= maximumConcurrency`,
with a maximum of 4 for `full` and 1 for `household`. Durations are 100 through 3600000 milliseconds. Sample age must cover at least two intervals;
scale-up sustain and cooldown must cover an interval; scale-down sustain must
exceed scale-up sustain. Percentages are 1 through 100, with memory/PID stop
thresholds strictly above their growth ceilings. The units have no limits, so the
autoscaler measures the execution unit's CPU, memory and processes against the
host's CPUs, memory and kernel task limit.

Execution starts all `consumer-0` through `consumer-(max - 1)` ordinary consumers
at boot. A lower admission target parks free consumers before transport receive;
it does not stop them or reclaim their RSS or connections. Occupied permits remain
held through handling and completion. After a shrink, new receives wait until
occupied permits fall below the new target. The scheduler consumer and media slots
are admitted separately.

### Hardware encoder and device group

The media workload follows `deploymentProfile`. Print it with
[app:deployment:profile:show](commands/app-deployment-profile-show.md) rather than writing it
by hand. Its `hardware` entry is `null` for software encoding or names the hardware
encoder; see [Hardware acceleration](transcoding.md#hardware-acceleration).
`deviceGroup` is the numeric group the execution container joins to open that
encoder's device. Leave it `null` and `bin/worker-deployment.php` reads the group that
owns the device node on the host where it runs; set it when a different group opens
the device. The controller refuses a `deviceGroup` without a device, and a device
whose group it cannot read. Execution then receives the device at
the same path with that group; control receives neither. An `nvenc` encoder takes no
device or group and receives the host's NVIDIA GPUs through the NVIDIA container
runtime. The worker argv,
PHP management limit, identity and lock directory are fixed by the manifest parser;
arbitrary commands, environment fields and secret paths are rejected.

### IPC and media volumes

Provision the named media and IPC volumes before creation, with the local Docker
volume driver and no driver options. The IPC volume is private to the pair and
mounted at `/run/baander-worker`; do not share it with another boot or web. Before
`create`, set the IPC volume root owner and group to the runtime UID/GID of the
selected worker image, with mode `0700`. Docker creates a new volume root owned
by root, and the admitted mount uses `volume-nocopy`; an unprepared root prevents
the worker from creating its private boot directory. Read the image's runtime
identity rather than assuming a numeric UID/GID. Runtime creates boot directories
with mode `0700` and the native socket with mode `0600`.

`sourceMountPolicy` is required. Use `production` for the named-volume recipe
shown here. `local-development` also admits canonical, read-only host bind sources
for the development dispatcher. Bind sources must be visible to the Docker daemon
and readable under the host's SELinux policy; admission does not relabel paths.
Derived storage remains named-volume storage under either policy.

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
worker-private paths. Production bind mounts, devices other than the hardware
encoder's, extra mounts and source volumes aliased as writable derived storage are
refused. Creation/start/recovery checks
verify declared names, concrete local-volume mountpoints and access modes. A
changed recipe requires a fresh admitted deployment.

## Credentials format

Keep credentials in a separate canonical absolute regular file owned by the
invoking user, with mode `0600`, no symlink components and at most 8192 bytes:

```json
{
  "controllerDatabaseUrl": "postgresql://worker:replace-password@db-control.baander.app/baander?serverVersion=18&charset=utf8",
  "runtimeEnvironment": {
    "APP_ENV": "prod",
    "APP_DEBUG": "0",
    "BAANDER_DEPLOYMENT_PROFILE": "full",
    "APP_SECRET": "replace-with-runtime-secret",
    "DATABASE_URL": "postgresql://worker:replace-password@db-worker.baander.app:6432/baander?serverVersion=18&charset=utf8",
    "DATABASE_CONTROL_URL": "postgresql://worker_control:replace-password@db-worker.baander.app:6432/baander_control?serverVersion=18&charset=utf8",
    "REDIS_URL": "redis://:replace-password@redis.baander.app:6379",
    "MESSENGER_TRANSPORT_DSN": "redis://:replace-password@redis.baander.app:6379/messages",
    "CONVERT_STORAGE_PATH": "/storage/conversions",
    "MEDIA_STORAGE_PATH": "/media/library"
  }
}
```

The runtime `BAANDER_DEPLOYMENT_PROFILE` must agree with the manifest's
`deploymentProfile`. `BAANDER_DEPLOYMENT_PROFILE_JSON`, when supplied, must contain
that same complete profile. The controller supplies each profile variable once,
using the admitted environment or a generated default. Switching profiles requires
a fresh boot after retirement, not an edit to a running container.

`DATABASE_URL` and `DATABASE_CONTROL_URL` are required and name the pooler's
application and control aliases; see [Connection pooler](configuration.md#connection-pooler).
The controller URL and the worker URLs must reach the same database from the host
and worker network respectively. The controller URL and credentials file stay on the host.
Docker receives the admitted runtime values through a temporary private env-file;
trusted daemon operators can inspect the resulting container environment.
Additional runtime keys are `CONVERT_STORAGE_PATH`, `MEDIA_STORAGE_PATH`,
`REDIS_PASSWORD`, `MAILER_DSN` and `MAIL_FROM_ADDRESS`. Without `MAIL_FROM_ADDRESS`,
email a worker sends comes from `noreply@localhost`. The sender name is always
Bånder. Values cannot contain
line breaks and their complete env-file is limited to 4 KiB.

Worker credentials admit only canonical Redis URL forms that preserve the
`messages` and `scheduler_occurrences` streams, the `baander` group and the
generated consumer identity. Invalid forms fail before container creation;
`app:worker` checks again before child launch. Errors identify the setting without
printing its credential-bearing value. See [Messenger configuration](configuration.md#messenger)
for accepted paths and query options. These checks do not establish connectivity
or Redis durability.

## Operating the workers

Apply an external deadline to every action. DBAL does not enforce a hard connection
deadline through `connect_timeout`; that query option is rejected here.

```bash
php bin/worker-deployment.php --help
timeout --kill-after=5s 60s php bin/worker-deployment.php create /etc/baander/worker-manifest.json /etc/baander/worker-credentials.json
timeout --kill-after=5s 60s php bin/worker-deployment.php start /etc/baander/worker-manifest.json /etc/baander/worker-credentials.json
timeout --kill-after=5s 60s php bin/worker-deployment.php status /etc/baander/worker-manifest.json /etc/baander/worker-credentials.json
timeout --kill-after=5s 60s php bin/worker-deployment.php readiness /etc/baander/worker-manifest.json /etc/baander/worker-credentials.json
```

The host actions are `create`, `reconcile-create`, `start`, `status`, `readiness`
and `recover`. Follow [Stop, replace or recover workers](background-work.md#stop-replace-or-recover-workers)
for uncertain outcomes and replacement; timeout or interruption does not cancel
a Docker operation already submitted. Keep the exact admitted manifest and
credentials for reconciliation.

`status` reports committed observations and always returns
`readiness: "not_checked"`. The separate read-only `readiness` action verifies the
exact active manifest, lease, admitted running containers, expected direct-child
roles, Redis connectivity and execution consumer registrations. Success is a
point-in-time observation, not proof of completed work or future availability.
See [Observe background work](background-work.md#observe-background-work) for
the distinction between deployment, heartbeat, queue and delivery evidence.

Autoscaling status reports desired, occupied, busy, idle, parked, missing, uncertain
and disconnected slots, together with controller/resource freshness, sample ages,
pressure and the decision reason. Unavailable evidence remains unknown; it is not
an empty queue or an idle slot. See
[Admission and native work](background-work.md#admission-and-native-work) for
the admission and drain policy.

Actions return bounded JSON on stdout. Exit codes are `0` for a confirmed result,
`1` for `operation_unconfirmed`, `2` for `invalid_configuration`, and `3` for a
denied or unregistered operation. The external `timeout` command may return its
own exit code without JSON. Error output contains no credentials or raw diagnostics.
