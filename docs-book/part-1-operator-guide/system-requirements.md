# System Requirements

This page lists what a Bånder host needs for each deployment profile. It separates
two kinds of figures. Configured reservations are the limits Bånder admits and
enforces. Observed consumption is what a measured workload used. A host must cover
the configured reservations; the observed figures show how much of that headroom
a household workload used.

## Host software

- A Linux Docker host with cgroup v2 and a local Docker Unix socket for the trusted
  worker controller.
- Docker with Compose and Buildx.
- Host PHP 8.5 with Swoole, Composer, and the `posix` and `pdo_pgsql` extensions for
  image-build tooling and the worker controller.

[Getting Started](getting-started.md#prerequisites) lists the complete
prerequisites and the installation order.

## Choose a profile

The profile is selected before startup and applies to web and workers together.
[Deployment profiles](configuration.md#deployment-profiles) describes each setting.

| Capability | `household` | `full` |
| --- | --- | --- |
| Original-file and cached playback | Unlimited by job slots | Unlimited by job slots |
| Stream copy (remux) | 2 shared audio/video jobs | Uses the encoding slots |
| Video encoding | None | 3 concurrent jobs |
| Audio encoding | None | 1 concurrent job |
| Web workers | 2 | 4 |
| Resident background consumers | 1 | Up to 4 |

Only playback that needs a live copy or conversion occupies a job slot. In
`household`, a destination that would need re-encoding receives a compatibility
explanation instead; see [Transcoding](transcoding.md). Neither profile yet lets
the operator raise the number of concurrent conversion jobs. A household that
needs many simultaneous conversions, such as 8 video and 12 audio streams, is not
covered by either profile today.

## Memory

### Configured reservations

The [household deployment preset](../../deploy/household/README.md) renders these
limits. The deployment refuses to start on a Docker host whose total memory is
below the reported reservation.

| Component | `household` (MiB) | `full` (MiB) |
| --- | ---: | ---: |
| Web server | 1,536 | 1,536 |
| PostgreSQL | 1,152 | 2,816 |
| Redis container (dataset limit 192 MiB) | 384 | 384 |
| Gateway | 64 | 64 |
| Worker control unit | 2,304 | 2,944 |
| Worker execution unit | 1,856 | 4,480 |
| Operator helper during lifecycle actions | 512 | 512 |
| Host headroom | 512 | 512 |
| **Total reservation** | **8,320** | **13,248** |

The worker values include each unit's management, media and scheduled-console
reservations. The `full` execution value is the preset's rendering; a manifest
that allows more resident consumers reserves more. Configured reservations are
ceilings, not a minimum host size.

### PostgreSQL sizing

Each database connection that searches or writes a full-text indexed title loads
PGroonga and keeps about 22 MiB of private memory until the connection closes. The
preset therefore sizes PostgreSQL from its configuration file:

```text
shared_buffers + 128 MiB + 24 MiB × client connections
```

The household file allows 33 client connections with 128 MiB of shared buffers.
The full file allows 97 with 256 MiB. If you lower `postgresMemoryMiB`, also lower
`max_connections` in the selected PostgreSQL file, or PostgreSQL can be stopped by
the kernel when the connection pool fills.

The Bånder database image starts PostgreSQL with transparent huge pages disabled.
With the host mode `always`, huge pages could otherwise inflate each such
connection to about 95 MiB and exhaust the container unpredictably. Keep the
image's entrypoint when you override the PostgreSQL command.

### Observed consumption

These peaks come from the qualification on 3 November 2026. Each deployment
served two simultaneous players for at least 30 minutes while a library scan and
metadata synchronisation ran. In every completed scenario both players advanced
without a stall, and no service was killed for lack of memory.

| Component | `household` peak (MiB) | `full` peak (MiB) |
| --- | ---: | ---: |
| Web server | 1,080–1,426 | 1,006–1,536 |
| PostgreSQL | 462–768 | 472–844 |
| Redis | 16–24 | 17–25 |
| Gateway | 15–20 | 18–23 |
| Worker control unit | 388–464 | 393–467 |
| Worker execution unit | 645–730 | 630 |
| **Simultaneous deployment total** | **2,149–3,029** | **2,208–3,286** |

The ranges cover the cold import and the original-file and stream-copy scenarios
of three household deployments and one full deployment. A container's peak
includes reclaimable page cache from media files and database pages. The web
server's peak therefore rises toward its limit during streaming without
indicating memory exhaustion.

Under this two-player workload, both profiles consumed similar memory. The
household profile's lower reservation comes from its smaller worker and
database envelopes, not from lower use at this load.

### Not yet established

- **Smallest host size.** The measurements ran on a workstation with 62.7 GiB of
  RAM and no whole-host memory cap. They show per-service consumption, not the
  smallest host on which a complete deployment fits.
- **4 GB hosts and Raspberry Pi.** Neither a 4 GB host nor ARM hardware has been
  qualified.
- **Shared host figures.** The measured deployments ran concurrently on one host,
  so host-wide memory pressure and swap describe several stacks at once.
- **The complete workload.** The mixed music-and-video scenario and three
  complete household repetitions did not finish before the measurements ended.
  [The roadmap](../../ROADMAP.md) lists the open qualification work.

## CPU

Each worker unit runs on its own configured CPU set; control and execution must
not share a CPU. The qualification pinned each unit to one physical core and ran
web, PostgreSQL and Redis on the remaining cores. Original playback and stream
copy need little CPU. Video and audio encoding in the `full` profile need
substantially more, and no CPU minimum has been measured for them.

## Storage

The media volume is mounted read-only for playback and workers. Stream copies,
encoded renditions and transcode state are written to separate derived volumes,
which grow with the cached presentations an instance keeps. See
[Storage and Data](storage-and-data.md) for each volume, its writers and what to
back up.
