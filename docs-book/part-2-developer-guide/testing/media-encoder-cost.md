# Media encoder cost

Measure individual software and hardware encoders with the media-control runner. The recorded results below describe their stated hosts and sources; use the [capacity drill](media-capacity.md) for deployment load.

## Per-job media cost

Cost mode measures each media job kind without the control drill's CPU
pressure. Add `BAANDER_TEST_MEDIA_COST=1` to the
[shared media control command](media-capacity.md#shared-media-control-qualification),
and set
`BAANDER_TEST_MEDIA_COST_ENCODER` to `libx264` (the shipped default) or
`libx265`. The runner writes that encoder into the fixture image's
`.env.prod.local`. The execution unit has no limits; the preflight needs ten
permitted CPUs and 5 GiB of host memory beyond the drill's 15 GiB for eight
1080p encoders.

Preparation generates a 120-second 1920x1080 30 fps H.264/AAC source with mild
temporal noise and three 600-second 44.1 kHz stereo FLAC tracks before the
measured window. The client then runs these phases in order:

1. An idle baseline.
2. One 1080p encode, one stream copy, and one Opus 128k, AAC 192k and MP3 192k
   rendition, each alone.
3. Three 1080p encodes with queued renditions and a fourth video waiting.
4. Four copy requests at once.

The drill's profile admits only three video jobs, so the checkpoint then replays
the recorded 1080p encoder command eight times at once inside the execution unit.

Samplers in both worker units record each process's peak RSS (`VmHWM`), thread
count and per-thread CPU ticks. A host sampler records `cpu.stat` and
`memory.stat` for web, PostgreSQL, Redis, control and execution. The FFmpeg
wrapper adds `-progress` output, so speed is output seconds per wall second over
the window in which output advances. The window starts after three seconds of
output and ends before the worker's 72-second buffer-ahead throttle stops an
encode. `media-cost.json` holds per-phase figures, a summary and the host's load
average at each phase boundary.

The recorded run on 2026-11-03 used an AMD Ryzen 7 5800X3D (8 cores, 16 threads,
`performance` governor, boost on, 3.44 GHz scaling maximum, 62.7 GiB) and
FFmpeg 5.1 in the Debian 12 worker image, before the move to Debian 13 and
FFmpeg 7.1. Other heavy host work was paused, and the one-minute load average was
1.4 before each run. The run predates the removal of unit limits: the execution
unit was confined to eight logical CPUs and 9216 MiB, on CPUs 1–8, which are seven
cores plus the SMT sibling of the control unit's CPU 0. One H.264 run and one HEVC
run each measured every phase; the table gives the range across both runs:

| Job (1080p30 source) | Peak RSS | Threads | CPU-s per output s: alone / 3 slots / 8 replayed | Speed: alone / 3 slots / 8 replayed |
|---|---|---|---|---|
| H.264 encode (`libx264`) | 531 MiB | 8 | 1.49 / 1.65 / 1.72–1.74 | 1.71x / 1.32–1.33x / 0.55–0.58x |
| HEVC encode (`libx265`) | 535 MiB | 9 | 3.04 / 3.29–3.31 / 3.33–3.56 | 0.70x / 0.62–0.63x / 0.28–0.30x |
| Video stream copy | 72 MiB | 1 | under 0.001 | over 1000x |
| Opus 128k rendition | 53 MiB | 1 | 0.008 / 0.009 | 119x / 103–114x |
| AAC 192k rendition | 56 MiB | 1 | 0.019 / 0.020 | 52x / 47–50x |
| MP3 192k rendition | 52 MiB | 1 | 0.014–0.015 / 0.015–0.016 | 69–70x / 56–66x |

In this run SegmentEncoder capped the encoder's own threads at two; the FFmpeg
process as a whole used more CPU. An H.264 job used 2.56 CPUs alone. Its two x264
threads used 0.9 CPU each, one thread 0.43, and decoding, audio and muxing threads
0.08–0.13 each. An HEVC job used 2.13 CPUs: its two x265 pool threads used 0.99
CPU each and every other thread 0.05 or less. Under that cap, HEVC at 1080p
stayed below real time.

A libx265 thread sweep ran alone on the same CPUs, outside the drill, with the
recorded command and `-threads:v` set to the pool size. It gave 0.72x real time
with `pools=2:frame-threads=2`, 1.02x with `pools=3`, 1.06x with
`pools=3:frame-threads=3`, 1.29–1.31x with `pools=4` and frame threads 2–4
(3.93–3.95 CPUs), and 1.72x with `pools=6:frame-threads=3` (5.38 CPUs). Every
setting cost 2.96–3.13 CPU-s per output second; four pool threads is the
smallest setting above 1.2x. The one-minute load average was 2.8–4.8 during
the sweep.

That source was synthetic. On 4 November 2026 the same sweep ran on real film
content, on an otherwise idle host, in the Debian 13 worker image with FFmpeg 7.1.
It encoded 60-second excerpts at 60, 300 and 480 seconds into the 1080p30 Big Buck
Bunny file, scaled to 1080p at 6 Mb/s with two frame threads and the production
GOP of 180 frames. With four pool threads, every excerpt missed real time, at
0.76–0.87x. Six pool threads ran at 1.07–1.21x for 4.7–5.5 CPU-s per output
second, and eight at 1.18–1.34x for 5.1–6.0 CPU-s per output second. SegmentEncoder
now gives `libx265` six pool threads, the smallest setting ahead of real time on
every excerpt, with two frame threads; libx264 keeps its two encoder threads.

Each media job child in the control unit peaked at 92 MiB with two threads for
video and 66 MiB with one thread for audio. No separate helper process appeared.
The 1080p admission limits also held: a fourth video stayed pending with no
native launch until its startup request returned 503, then ended as `refused`.
A fourth copy request returned 503 with `Retry-After: 2`. Four queued renditions
ran one at a time, and all four completed within the 60-second start wait. The
QoL StreamGovernor reported `learning` with no samples and no active streams in
every phase, so it admitted everything.

Service cgroups, as mean / p95 CPUs and anonymous memory (page cache in brackets):

| Service | Idle | Three 1080p encodes and one rendition |
|---|---|---|
| Web | 0.003 / 0.004, 323 MiB (38 MiB) | 0.023–0.025 / 0.039–0.041, 450 MiB (39 MiB) |
| PostgreSQL | 0.081 / 0.13–0.14, 1.19 GiB (248 MiB) | 0.20 / 0.34–0.37, 2.06–2.39 GiB (251 MiB) |
| Redis | 0.003 / 0.003, 11 MiB | 0.004 / 0.005, 11 MiB |
| Control unit | 0.036 / 0.036–0.089, 336 MiB (16 MiB) | 0.19–0.24 / 0.47–0.56, 483–489 MiB (51 MiB) |
| Execution unit | 0.12 / 0.19, 355 MiB (16 MiB) | 6.89–7.15 / 7.44–7.68, 1.62–1.72 GiB (274–344 MiB) |

These are measurements of one host and one synthetic source, not derived limits.
Real film content, other frame rates and other CPUs change the figures.

## Hardware encoder cost

Hardware mode measures the application's 1080p hardware encode command on a GPU,
without the worker pair. It needs a worker image built from the checkout and a
render node on the host:

```bash
php scripts/build-application.php --target worker --tag baander-worker:hw-cost
BAANDER_TEST_MEDIA_COST=hardware BAANDER_TEST_IMAGE=baander-worker:hw-cost \
  bash scripts/test-worker-media-control-container.sh
```

`BAANDER_TEST_MEDIA_COST_DEVICE` selects the device (default
`/dev/dri/renderD128`), `BAANDER_TEST_MEDIA_COST_ENCODERS` the encoders (default
`h264_vaapi,hevc_vaapi`), `BAANDER_TEST_MEDIA_COST_STEPS` the concurrency steps
(default `1,2,4,8,12,16,24`) and `BAANDER_TEST_MEDIA_COST_STEP_SECONDS` how long a
step may run (default 150). The runner starts one disposable worker-image
container with no network, no capabilities and Docker's default seccomp profile,
and gives it the device and the group that owns it, as worker creation does for
the execution unit. Inside, the fixture generates the same 120-second 1080p30
H.264/AAC source as cost mode, builds the encode command with `SegmentEncoder` for
each hardware encoder, and starts each step's encodes together through the native
daemon's launcher. Every encode decodes,
scales and encodes on the device and encodes AAC audio on the CPU.

Every half second the fixture records each encode's CPU ticks, `VmHWM`, threads,
`-progress` frame count and the per-client DRM engine time and VRAM from
`/proc/<pid>/fdinfo`, and the device's `gpu_busy_percent`, `vcn_busy_percent` and
`mem_info_vram_used`. `media-cost-hardware.json` reports each step over the window
in which every encode had passed three seconds of output and none had stopped
advancing. A step is real time when its slowest stream runs faster than 1.0x, and
the receipt records each encoder's largest real-time step.

The recorded run on 2026-11-04 used an AMD Radeon RX 9070 XT (16 GiB) through VAAPI
with Mesa radeonsi and FFmpeg 7.1.5 on Debian 13, on the Ryzen 7 5800X3D host above
with other work running (one-minute load average 1.5–6.2). The run added steps 10
and 14 to the default steps:

| Encoder | Streams | Slowest / aggregate speed | CPU-s per output s | Peak RSS | Device VRAM used |
|---|---|---|---|---|---|
| `h264_vaapi` | 1 | 8.98x / 8.98x | 0.031 | 164 MiB | 2.2 GiB |
| `h264_vaapi` | 4 | 2.25x / 9.05x | 0.033 | 164 MiB | 5.4 GiB |
| `h264_vaapi` | 8 | 1.12x / 8.98x | 0.033 | 170 MiB | 9.6 GiB |
| `h264_vaapi` | 10 | 0.91x / 9.14x | 0.033 | 169 MiB | 11.7 GiB |
| `h264_vaapi` | 16 | 0.57x / 9.27x | 0.063 | 163 MiB | 12.7 GiB |
| `hevc_vaapi` | 1 | 13.3x / 13.3x | 0.029 | 185 MiB | 1.1 GiB |
| `hevc_vaapi` | 8 | 1.67x / 13.4x | 0.030 | 192 MiB | 8.5 GiB |
| `hevc_vaapi` | 12 | 1.12x / 13.5x | 0.031 | 195 MiB | 12.7 GiB |
| `hevc_vaapi` | 14 | 1.08x / 15.2x | 0.055 | 196 MiB | 12.7 GiB |
| `hevc_vaapi` | 16 | 0.96x / 15.4x | 0.053 | 200 MiB | 12.7 GiB |

`vcn_busy_percent` read 100 at every step, `gpu_busy_percent` stayed low, and the
encodes' summed engine time equalled the wall time: the video engine bounds the
aggregate rate, about 9x real time for H.264 and 13–15x for HEVC. Device VRAM grew
by about 1.1 GiB per stream and levelled off near 12.7 GiB above twelve streams
without errors. Every encode exited cleanly or ran until the step's deadline. H.264
is real time up to 8 streams and HEVC up to 14. The hardware video default of 12
follows HEVC, the encoder media control selects by default. It sits at the step
that kept a 12% margin; 14 streams left only 8%. These figures are for one GPU, one
synthetic source and one run.

