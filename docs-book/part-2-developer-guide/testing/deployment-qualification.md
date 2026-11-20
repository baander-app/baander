# Deployment qualification

Measure the complete disposable deployment on a constrained host or VM. Keep the observer outside that host so the measurement includes the deployment alone.

## Deployment qualification

`scripts/qualify-deployment.php` measures a complete disposable deployment on a
constrained Linux host or VM. The browser driver runs on the invoking machine, so
run that machine outside the measured host. Limiting one container on a larger host
does not test whether the complete deployment fits that memory size.

The private JSON configuration has these fields:

| Field | Meaning |
| --- | --- |
| `version` | `1`. |
| `name` | The disposable deployment name; it must match `deployment.name`. |
| `deployment` | A complete deployment configuration in the format of the `deploy/preset/example-*.json` files, including `capability` and `size`, with private secrets and selected production images. |
| `replacementSize` | Optional. A `size` object for the same capability that differs from `deployment.size`; the `deployment-replacement` scenario requires it. |
| `datasetRun` | A stable lowercase fixture identifier, reused for comparable datasets. |
| `host.constraintBytes` | The actual memory constraint of the complete target host or VM. |
| `host.description`, `host.storage` | Hardware, operating-system and storage details for the receipt. |
| `durationSeconds` | Playback observation length; at least `1800`. |
| `fixtureTimeoutSeconds`, `maintenanceDeadlineSeconds` | Preparation and maintenance deadlines; each is at least 3,600 seconds and defaults to `7200`. Maintenance must also cover `durationSeconds`. |
| `observerMemoryMiB` | The measured observer's memory limit; at least 64 MiB, default `128`. |
| `nodeExecutable` | Absolute path to the invoking machine's Node executable. |
| `chromiumExecutablePath` | Optional absolute path to its compatible Chromium executable. |

Runs are keyed on the deployment's capability and size. The run contract records
both, and a run under `remux` fails when its native launch journal contains any
encoder launch.

Keep the selected base images available by a verified tag or repository digest.
Temporary build aliases must not become their only references. Configure the
Docker target through the normal tooling settings; subsequent stages use the
captured target. The target needs usable host `/proc` and cgroup v2 mounts, and
the browser needs access to the configured application origin.

The target host must run with swap off. Before `start`, `scenario` or a
deployment replacement starts or changes anything, a short container reads
`MemTotal` and `SwapTotal` from the target host's `/proc/meminfo`. The command
refuses a host whose total memory exceeds `host.constraintBytes` or whose swap
is not zero, and leaves the qualification state unchanged.

Preparation creates 2,000 songs, 50 movies and 250 episode-shaped video files
before measurement. The episode files use a separate movie-type library; this
does not qualify a TV-library feature. The first scan starts with an empty
catalog, but generation may leave a warm filesystem cache. Provider fixtures
exercise music metadata adapters without external HTTP requests. MusicBrainz
rate limiting remains active, so metadata work can outlast the playback window.
Video coverage includes probing and catalog ingestion. The current normal sync
does not enrich movies through TMDB; the report retains those counts without
claiming that movie metadata enrichment was exercised.

```bash
fixture_state=/absolute/private/qualification-fixtures
fixture_config=/absolute/private/qualification-fixtures.json
qualification_state=/absolute/private/qualification-run
qualification_config=/absolute/private/qualification.json
php scripts/qualify-deployment.php prepare --config "$fixture_config" --state "$fixture_state"
php scripts/qualify-deployment.php prepare --from "$fixture_state" --config "$qualification_config" --state "$qualification_state"
php scripts/qualify-deployment.php start --state "$qualification_state"
php scripts/qualify-deployment.php scenario --state "$qualification_state" --scenario original-overlap --repeat 1 --players 1
php scripts/qualify-deployment.php scenario --state "$qualification_state" --scenario fresh-copy-overlap --repeat 1 --players 1
php scripts/qualify-deployment.php scenario --state "$qualification_state" --scenario mixed-overlap --repeat 1 --players 1
php scripts/qualify-deployment.php stop --state "$qualification_state"
php scripts/qualify-deployment.php start --state "$qualification_state"
php scripts/qualify-deployment.php stop --state "$qualification_state" --cleanup
php scripts/qualify-deployment.php report --state "$qualification_state"
```

The scenarios are:

| Scenario | What it does | It passes when |
| --- | --- | --- |
| `original-overlap`, `fresh-copy-overlap`, `mixed-overlap` | Plays original or freshly copied media while a normal scan and metadata sync run. | Every player progresses for the whole window, background work completes, and no OOM kill, prohibited launch or unexpected exit occurs. |
| `recovery` | Plays original media, waits until the maintenance commands have queued their work, stops PostgreSQL for 30 seconds, starts it again and waits up to 300 seconds for worker readiness and web statistics. | The deployment recovers and all background work completes. Playback is recorded but may stall during the outage. |
| `deployment-replacement` | Restarts the deployment with `replacementSize`; a second replacement switches back to `deployment.size`. | The new boot has a different deployment fingerprint, size and worker boot, its workers report ready, `/health` answers healthy through the gateway, and the imported catalog is intact. |
| `insufficient-memory` | Runs the `original-overlap` workload on a host smaller than the claimed size. | The run records an OOM kill of a deployment process or a stalled player. It fails when the deployment completes cleanly. |

`--players 1|2` sets the number of real players for every scenario except
`deployment-replacement`; the default is `2`. The run contract, browser
summary and report record the count. A one-player `mixed-overlap` plays the
song only. A 4 GB claim runs one player. A second player at that size is
allowed but outside the claim.

`deployment-replacement` runs no players and is not a measured run; its
receipt and report record the old and new boot identities and the check
result. The replaced deployment keeps the new size for later scenarios and
restarts, so run it after the overlap scenarios of the same state.

Each overlap observes its real players while normal scan and metadata work runs.
A non-playing song and its lyrics file are renamed before the scan, preserving
their bytes and catalog identity while exercising source-path reconciliation.
The old unavailable index entry remains visible in the evidence; it is not a
missing import. Fresh-copy sources are consumed once per deployment. Use fresh
deployment names and state directories for repetitions two and three, retaining
the image, dataset and workload timing needed to compare them. Prepare each run
with `--from` pointing to the same fixture state. This copies and verifies the
media in a fresh owned volume and borrows the exact images; it does not regenerate
the fixtures. The fixture and run configurations need distinct deployment names
and the same selected Docker daemon. Record resource changes as separate candidates.

A fixture source must have no attempted overlap or pending source rename. Once
it has borrowers, run the source-changing scenarios only in those child deployments.
Each child's `stop --cleanup` removes its own data while retaining the shared
images. After every child has completed cleanup, remove the fixture source:

```bash
php scripts/qualify-deployment.php stop --state "$fixture_state" --cleanup
```

Reports retain host and cgroup memory, swap, pressure, process samples, database
and Redis observations, browser progress, native launch records and Docker OOM
events for the deployment's containers. Native concurrency samples are a lower
bound because a short process can run between samples; cgroup memory peaks and the
launch journal provide separate evidence.
Short-lived controller actions record their own cgroup peaks and Docker exit
state before cleanup. The report lists those lifetime peaks separately from
simultaneous samples; their sum does not measure concurrent consumption.
Missing observations, prohibited encoding, unfinished maintenance and failed
cleanup prevent a passing report. Growing deployment swap prevents a memory-fit
claim even when total host swap remains stable.

Plain `stop` retains data for restart checks. `stop --cleanup` verifies retirement
and retention, then removes only the owned fixture resources. Keep the private
receipts, including failed runs. A published capacity claim needs three
comparable successful repetitions, the restart, `recovery` and
`deployment-replacement` checks, and an `insufficient-memory` run that shows the
failure below the claimed size. Setup success alone does not establish a minimum
memory requirement or Raspberry Pi support.

## Qualification VMs

The harness does not create VMs. Provision each guest by hand with libvirt on an
x86_64 workstation:

- A 4,096 MiB guest for the claim runs and a 3,072 MiB guest for the
  insufficient-memory run. If the deployment completes cleanly on the
  3,072 MiB guest, repeat on a guest 512 MiB smaller until a failure is recorded.
- No swap device, swap file or zram: `/proc/swaps` lists no entries and
  `SwapTotal` in `/proc/meminfo` is `0 kB`.
- No memory balloon, so the guest's total memory stays fixed during a run.
- A Linux distribution that mounts cgroup v2 only, with rootful Docker Engine
  and the Docker Compose plugin.

```bash
virt-install --name baander-qualify-4096 --memory 4096 --vcpus 4 \
  --cpu host-passthrough --memballoon none \
  --disk size=80,bus=virtio --network network=default \
  --os-variant debian12 --graphics none --console pty,target_type=serial \
  --location https://deb.debian.org/debian/dists/bookworm/main/installer-amd64/ \
  --extra-args 'console=ttyS0'
```

Partition the guest disk without a swap partition. For the smaller guest, use
`--name baander-qualify-3072 --memory 3072`. Inside each guest, confirm the
conditions before preparing a run:

```bash
swapon --show                      # prints nothing
grep -E 'MemTotal|SwapTotal' /proc/meminfo
stat -fc %T /sys/fs/cgroup         # prints cgroup2fs
docker info --format '{{.CgroupVersion}} {{.SecurityOptions}}'
```

`docker info` must report cgroup version `2` and no `name=rootless` security
option. Set `host.constraintBytes` to the guest's configured memory in bytes:
`4294967296` for the 4,096 MiB guest and `3221225472` for the 3,072 MiB guest.
The guest's `MemTotal` is slightly lower because the kernel reserves memory.
Each guest needs its own qualification state, prepared from a fixture state on
that guest's Docker daemon.

