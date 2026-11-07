# System Requirements

This page describes what a Bånder host needs and how to size media work for it.
Bånder bounds media work with its own job slots. No container carries a CPU,
memory or PID limit or a CPU set, so the host's real capacity, not a configured
reservation, decides how much it can run. You find that capacity by trial: start
from the default slots, watch playback, and lower the slots when encodes fall
behind. The measured figures below show what each job costs on one reference
host; they are a starting point, not a guarantee for yours.

## Host software

- A Linux Docker host with cgroup v2 and a local Docker Unix socket for the trusted
  worker controller. The Docker daemon must run rootful: rendering the managed
  preset and creating worker units both refuse a rootless daemon, which cannot
  apply the negative out-of-memory scores described under [Kill order](#kill-order).
- Docker with Compose and Buildx.
- Host PHP 8.5 with Swoole, Composer, and the `posix` and `pdo_pgsql` extensions for
  image-build tooling and the worker controller.

[Getting Started](installation.md#prerequisites) lists the complete
prerequisites and the installation order.

## Choose a profile

The profile is selected before startup and applies to web and workers together.
[Deployment profiles](../configuration/deployment-profiles.md#deployment-profiles) describes each setting.

| Capability | `household` | `full` |
| --- | --- | --- |
| Original-file and cached playback | Unlimited by job slots | Unlimited by job slots |
| Stream copy (remux) | 2 shared audio/video jobs | Uses the slots of its kind |
| Video conversion | None | 8 concurrent jobs by default |
| Audio conversion | None | 12 concurrent jobs by default |
| Web workers | 2 | 4 |
| Resident background consumers | 1 | Up to 4 |

Only playback that needs a live copy or conversion occupies a job slot. In
`household`, a destination that would need re-encoding receives a compatibility
explanation instead; see [Transcoding](../media/transcoding.md). The household slots are
fixed. In `full`, the operator sets the video and audio slot counts; zero slots
disable that kind of conversion and stream copy.

## Choose media slots

A video slot runs one video conversion or video stream copy; an audio slot runs
one audio rendition or audio stream copy. The slots are the only bound on media
work: Bånder admits at most that many jobs of each kind and queues the rest. It
derives the native limits from the slots, and
[`app:deployment:profile:show`](../commands/app-deployment-profile-show.md) prints
them for any slot pair before you deploy it.

Choose the slots by trial and error:

1. Start from the default of 8 video and 12 audio slots.
2. Load the host with the playback your household actually runs at once, and watch
   whether converted streams keep playing in real time. A stream whose encode
   falls behind stalls while the player waits for the next segment.
3. If encodes fall behind, lower the slots of that kind and try again. Further
   requests then wait for a free slot instead of slowing every running encode.
4. If every slot stays busy while the host still has CPU and memory to spare, you
   can raise the slots, up to the [maximums](../configuration/deployment-profiles.md#media-slots).

Changing the slots replaces the deployment; it is not a live setting.
[Configuration](../configuration/deployment-profiles.md#media-slots) shows where to set them.

### Reference margins at the default slots

On 4 November 2026, a full deployment ran every default slot at once on the
reference host described below: an AMD Ryzen 7 5800X3D with an AMD Radeon RX 9070
XT. Each video slot converted a 128-second 1080p30 excerpt of Big Buck Bunny to
1080p, and each audio slot converted 600-second tracks one after another, while
signed-in web clients browsed the library. A stream's real-time margin is its
steady speed minus one; a negative margin means the encode fell behind playback.

| Encode path | Slots | Video speed | Video margin | Audio speed |
| --- | --- | ---: | ---: | ---: |
| Software, `libx264` | 8 video + 12 audio | 0.251–0.256× | −0.749 to −0.744 | 12.0–20.7× |
| Hardware, `hevc_vaapi` | 12 video + 12 audio | 1.26–1.28× | +0.26 to +0.28 | 31.4–41.8× |

Both runs completed every job. No service was killed for lack of memory, no
worker unit restarted, and web requests were answered throughout. The two encode
paths differ in what they sustain. With a hardware encoder, the default 12 video
slots stay ahead of real time on this GPU. In software, the eight 1080p encodes
share the host's CPUs and together produced about 2 seconds of video per second,
so this host keeps about two software 1080p encodes in real time, not eight. The
default software slots therefore suit a host that mostly copies streams or converts audio; a host that
converts video in software should lower its video slots as described above.

Alone on the same host after each run, every codec ran ahead of real time on this
content: `libx264` at 1.08×, `libx265` at 1.30×, `h264_vaapi` at 9.4× and
`hevc_vaapi` at 13.7×.

### Measured software cost per job

These costs were measured on 3 November 2026 with FFmpeg 5.1 in the Debian 12
worker image, before the images moved to Debian 13 and FFmpeg 7.1. The images
have since moved to the project's own FFmpeg 8.1 build; neither these costs nor
the hardware costs below have been measured on it. The host was an AMD Ryzen 7
5800X3D (8 cores, 16 threads) with other heavy work paused. The
execution unit was confined to eight logical CPUs for the measurement, so the
eight-at-once figures describe eight encodes sharing eight CPUs. Each video job
encoded a synthetic 1080p30 H.264/AAC source.

| Job | CPU-seconds per output second, alone | With 8 at once | Speed alone | Speed with 8 at once | Peak memory |
| --- | ---: | ---: | ---: | ---: | ---: |
| H.264 encode (`libx264`) | 1.49 | 1.72 | 1.71× | 0.55–0.58× | 531 MiB |
| HEVC encode (`libx265`, two pool threads) | 3.04 | 3.3–3.56 | 0.70× | 0.28–0.30× | 535 MiB |
| Video stream copy | under 0.001 | — | — | — | 72 MiB |
| Opus rendition | 0.008 | — | — | — | about 55 MiB |
| AAC rendition | 0.019 | — | — | — | about 55 MiB |
| MP3 rendition | 0.015 | — | — | — | about 55 MiB |

Speed is output seconds per wall-clock second; below 1×, an encode cannot keep
up with playback. The HEVC row used two pool threads, which stays below real time.
Four pool threads reached 1.29–1.31× real time on this synthetic source but only
0.76–0.87× on film content, and peaked at 557 MiB. Production therefore gives
`libx265` six pool threads, which reached 1.07–1.21× real time at 4.7–5.5
CPU-seconds per output second when run alone on 60-second excerpts of 1080p30 Big
Buck Bunny.

An encode that keeps pace with playback occupies about 1.5 to 1.7 CPUs for H.264
on the synthetic source and about 5 for HEVC on film content. Each media job also
runs a PHP child in the control unit, which peaked at 92 MiB for a video job and
66 MiB for an audio job.

The CPU figures apply to software encoding only. A codec handled by a hardware
encoder, such as a GPU, QuickSync or another video engine, costs almost no CPU.
A deployment encodes in software unless its profile names a hardware encoder; see
[Hardware Acceleration](../media/transcoding.md#hardware-acceleration).

### Measured hardware cost per job

These costs were measured on 4 November 2026 on the same host, with FFmpeg 7.1.5
in the Debian 13 worker image and an AMD Radeon RX 9070 XT (16 GiB) through VAAPI
and Mesa radeonsi. Each stream decoded, scaled and encoded a 1080p30 H.264/AAC
source on the GPU. The speed column gives the slowest stream at each concurrency.

| Encoder | Streams | Slowest stream speed | CPU-seconds per output second | Peak memory per stream |
| --- | ---: | ---: | ---: | ---: |
| `h264_vaapi` | 1 | 8.98× | 0.031 | 164 MiB |
| `h264_vaapi` | 8 | 1.12× | 0.033 | 170 MiB |
| `h264_vaapi` | 10 | 0.91× | 0.033 | 169 MiB |
| `hevc_vaapi` | 1 | 13.3× | 0.029 | 185 MiB |
| `hevc_vaapi` | 12 | 1.12× | 0.031 | 195 MiB |
| `hevc_vaapi` | 14 | 1.08× | 0.055 | 196 MiB |
| `hevc_vaapi` | 16 | 0.96× | 0.053 | 200 MiB |

The GPU's video engine, not the CPU, sets the limit: it was fully busy at every
step. Video memory grew by about 1.1 GiB per stream and levelled off near 12.7 GiB of
the card's 16 GiB above twelve streams.
With a hardware encoder, unset video slots default to 12, which follows the HEVC
rows; a deployment that forces H.264 should set 8. Other GPUs sustain different
stream counts.

## Memory

Containers have no memory limits, so the host's memory is shared by every service
and every running job. Estimate the media share from the per-job peaks above: at
the default 8 + 12 with every slot encoding, the media processes alone peak near
6.5 GiB (8 × (557 + 92) MiB for HEVC video jobs, plus 12 × (56 + 66) MiB for audio
renditions). The 557 MiB HEVC peak was measured with four pool threads; the
six-thread peak was not measured. Web, PostgreSQL, Redis and the worker units come on top of that; the
[household measurements](#household-measurements) show their consumption under a
two-player workload. Lower the slots if the host cannot hold that sum with room
for the page cache.

### Kill order

Without container limits, the kernel's out-of-memory killer picks victims across
the whole host. Bånder gives each process an `oom_score_adj` so that, under host
memory pressure, web workers and media work go before the services that hold
state. The kernel adds the adjustment to each process's share of memory when it
picks a victim, so a higher score goes first unless a lower-scored process holds
far more memory.

| Process | `oom_score_adj` | Set by |
| --- | ---: | --- |
| Web server container | 900 | Managed preset |
| FFmpeg commands in the execution unit | 600 | Native daemon, at launch |
| PHP media job children in the control unit | 600 | Each child, as it starts |
| Execution unit (supervisor, native daemon, consumers) | 300 | Worker creation |
| Redis | 100 | Managed preset |
| Gateway | 0 | Managed preset |
| PostgreSQL | −500 | Managed preset |
| Connection pooler | −500 | Managed preset |
| Control unit (supervisor, lease agent, scheduler, relay, autoscaler, media manager) | −500 | Worker creation |

A process may raise its own score without privilege, which is how FFmpeg commands
and media children reach 600. Lowering a score needs a rootful daemon, so the
preset and worker creation refuse a rootless one. The worker scores apply to every
deployment the worker controller creates. The Compose file of the source
installation sets no scores for web, PostgreSQL, the pooler or Redis.

### PostgreSQL sizing

Each database connection that searches or writes a full-text indexed title loads
PGroonga and keeps about 22 MiB of private memory until the connection closes.
Clients therefore reach PostgreSQL through a transaction-mode
[connection pooler](../configuration/deployment-profiles.md#connection-pooler), which holds a server
connection only for the length of a transaction. PostgreSQL's connection count
follows the pooler's server pools rather than the number of web, worker and media
clients: the preset's PostgreSQL files set `max_connections` to 20 for
`household` and 35 for `full`.
More slots add pooler clients, not PostgreSQL backends.

The Bånder database image starts PostgreSQL with transparent huge pages disabled.
With the host mode `always`, huge pages could otherwise inflate each such
connection to about 95 MiB and exhaust host memory unpredictably. Keep the
image's entrypoint when you override the PostgreSQL command.

### Household measurements

These peaks come from the qualification on 3 November 2026, taken before the
connection pooler was added and before container limits were removed. Each
deployment served two simultaneous players for at least 30 minutes while a library
scan and metadata synchronisation ran. In every completed scenario both players
advanced without a stall, and no service was killed for lack of memory.

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
server's peak therefore rose toward its former 1,536 MiB limit during streaming
without indicating memory exhaustion. Under this two-player workload, both
profiles consumed similar memory. The household profile has not been requalified
with the pooler and without container limits; [the roadmap](../../../ROADMAP.md)
records that open work.

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
  [The roadmap](../../../ROADMAP.md) lists the open qualification work.
- **Slot counts above the defaults.** Media authority checks use the pooler's
  control pool, whose size was chosen before the slots grew. Counts above 8 video
  and 12 audio rely on that pool's one-second wait and are unproven.

## CPU

Original playback and stream copy need little CPU; conversion needs the CPU in
the [cost table](#measured-software-cost-per-job). No container is pinned to a CPU
set or capped by a CPU quota. Under contention, each container receives CPU
through its own cgroup at Docker's default weight, so web, PostgreSQL, the pooler
and the control unit keep their share while the execution unit encodes.

Inside the execution unit, the native daemon starts every FFmpeg encode, copy and
helper command at nice 10. Encodes therefore yield to the unit's own lease
renewal, supervisor, native daemon and consumers. Nice ranks processes only within
one cgroup; it has no effect between containers. On an otherwise idle host,
encodes still receive all the CPU they can use.

## Storage

The media volume is mounted read-only for playback and workers. Stream copies,
encoded renditions and transcode state are written to separate derived volumes,
which grow with the cached presentations an instance keeps. See
[Storage and Data](storage-and-data.md) for each volume, its writers and what to
back up.
