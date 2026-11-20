# Media control and capacity qualification

Use these drills to check controls under pressure and a deployment at its default slots. For individual encoder measurements, see [media encoder cost](media-encoder-cost.md).

## Shared media control qualification

To exercise controls while native capacity is occupied, build the current worker
image and run the dedicated drill:

```bash
php scripts/build-application.php --buildx --target worker --tag baander-worker:test
BAANDER_TEST_IMAGE=baander-worker:test BAANDER_TEST_OPERATOR_IN_CONTAINER=1 \
  BAANDER_TEST_DOCKER_SOCKET_PATH=/var/run/docker.sock \
  BAANDER_TEST_CHECKOUT_IN_IMAGE=1 BAANDER_TEST_USE_IMAGE=0 \
  bash scripts/test-worker-media-control-container.sh
```

The runner selects ordinary maximum two, the combined web/worker fixture and
`BAANDER_TEST_MEDIA_CONTROL_OBSERVATION=1`. It inherits the operator runner's
900-second deadline, resource preflight and cleanup. Set `BAANDER_TEST_ARTIFACTS`
to retain evidence at a chosen path; the default is
`var/test-runs/media-control-*`. Previously recorded pressure, browser and cleanup
evidence covers single-intention pause/seek/reconnect and reattachment with the
earlier encoder output. Rapid native scrubbing also retains the latest queued
target across an in-flight seek, with pause/resume ordering and generation
authority preserved; see
[Playback controls and generations](../development/video-playback-controls.md#playback-controls-and-generations).
That interaction contract has focused backend and player-hook regressions; the
recorded single-intention drill does not establish browser pressure coverage of
rapid scrubbing. The launch observer matches the current DASH `manifest.mpd`
output. A new pressure result still requires the complete live drill.

Observation mode builds a disposable fixture derived from the production image.
Its test-only FFmpeg wrapper records each video encoder launch before executing
the original native binary with the same PID, start ticks, arguments and streams.
This is an instrumented runtime test; the immutable production-image contract
remains a separate gate. Observation defaults to zero and is incompatible with
`BAANDER_TEST_USE_IMAGE=1`, which forbids rebuilding the worker fixture.

The fixture has three video slots and one audio slot. The drill fills them,
queues ordinary work and applies execution CPU pressure for at least 60 seconds.
Pressure processes run at nice 10 and score 600, as encodes do. The audio encoder
is held after its initial output to keep the slot occupied; this does not measure
sustained audio throughput.

Under that pressure, the drill exercises running and paused attachment, ticketed
socket reconnect and replay, stale generation refusal, paused seek, active and
pending cancellation, and deliberate admission after cancellation. Retired init
and media requests must fail even while their old files remain present. Subtitle
authority and readiness-wait races still need their separate deterministic coverage.

`qualification.json` records control acknowledgement and heartbeat p99 and maximum,
seek readiness, cancellation retirement, occupancy, sampling gaps and ordinary
progress, together with each unit's absent limits and the sampled kill order. Both
p99 limits are one second and both maximum limits are two seconds; seek and cancel
request handling also have two-second limits. Seek readiness and
exact encoder retirement after cancellation each have a 30-second watchdog.
Seek readiness ends when both audio and video init/fragment bytes are available,
recorded with a monotonic clock before certification. HTTP retrieval uses bounded
concurrent batches: two playlists, then four init/fragment resources. The fixture
fetches both tracks before running ffprobe, decoding, signature-tampering and
subtitle checks. All those checks remain mandatory: late bytes, corrupt media or
accepted tampering fail the qualification. Certification time does not extend the
availability limit.
`media.json` holds the scenario receipts; `native-launches.jsonl` records each job,
generation, output directory, process identity and live predecessors at launch.
Process identity includes PID, start ticks, PID namespace and hostname. The ledger
checks duplicate attempts and overlapping predecessors, and attachment compares the
launch records before and after each concurrent attachment batch. Periodic process
counts alone cannot prove those properties. Keep the container inspections, native
verification, control/execution samples, backlog observations, operator log and
cleanup receipts with these results, including failed runs.

## Load proof at the default slots

Load mode runs every video and audio job of a full deployment's default slots at
once, through the pooler and with no limits on any container. Set
`BAANDER_TEST_MEDIA_LOAD` to `software` for 8 video and 12 audio slots, or to
`hardware` for the hardware encoder at `/dev/dri/renderD128` with its default of 12
video and 12 audio slots. `BAANDER_TEST_MEDIA_LOAD_SOURCE` names a 1080p video on
the host, such as a 1080p30 Big Buck Bunny file:

```bash
BAANDER_TEST_IMAGE=baander-worker:test BAANDER_TEST_POSTGRES_IMAGE=baander-database:test \
  BAANDER_TEST_OPERATOR_IN_CONTAINER=1 BAANDER_TEST_DOCKER_SOCKET_PATH=/var/run/docker.sock \
  BAANDER_TEST_CHECKOUT_IN_IMAGE=0 BAANDER_TEST_USE_IMAGE=0 \
  BAANDER_TEST_MEDIA_LOAD=software BAANDER_TEST_MEDIA_LOAD_SOURCE=/path/to/1080p.mp4 \
  bash scripts/test-worker-media-control-container.sh
```

`BAANDER_TEST_DEPLOYMENT_PROFILE` selects another profile file; every worker drill
reads its slot counts from it, and without it they keep the fixture's three video
and one audio slot. The load proof needs twelve permitted CPUs and 25 GiB of host
memory. It starts PostgreSQL with the database image's own configuration and the
pooler from the same image, with the default deployment's pools.

Preparation copies a 120-second excerpt of the source's picture unchanged, with
AAC audio, and cuts 600-second FLAC tracks from its soundtrack. The client then
warms the playback governor: rounds of every video slot at 1080p, each cancelled
after ten seconds of output, until the saved learning state holds at least the
governor's 50 learning samples and is active. Next, 22 clients hold
50-millisecond transactions on the 20-connection application pool, so no server
connection is idle, and 8 signed-in clients request the album listing without
pause. The run admits every video slot at 1080p and keeps every audio slot busy
with one rendition after another of each lane's track until every video has
completed or the lane has used each of its 15 distinct renditions; a lane never
repeats one, because the cache would answer it without encoding. Each video session
reports real-time playback progress, capped at its encoded output, every two
seconds. Finally, with the load stopped, one encode of
each codec runs alone: `libx264` and `libx265` in the software pass, `h264_vaapi` and
`hevc_vaapi` in the hardware pass.

`media-load.json` records each stream's output against wall time and its encode
path, the governor's decisions and their state, native launches, the native
daemon's answer time to a new peer, OOM events, unit containment, PostgreSQL
backends by pool, pooler queues, lease helper lifetimes, web latency, the kill
order and the single encodes. A stream's real-time margin is its steady speed
minus one, measured from three seconds of output to its completion. The verifier
fails the receipt when any of these holds:

- a configured job is missing, failed or did not complete, or no sample saw every
  slot busy at once;
- the governor had not learned before the run, or a run admission was decided
  while it was learning, downgraded or vetoed;
- a unit was contained, drained, restarted or OOM-killed, or native execution
  failed;
- PostgreSQL backends exceeded 20 application, 8 control or 32 in total, or the
  application pool was saturated in fewer than half of the run's samples;
- a lease helper outlived its 1.5-second deadline, or the native daemon took a
  second or more to answer a new peer;
- a stream lacks its margin or encoded on the other path.

Real time under load is recorded, not judged. Check a retained receipt again with
`php scripts/test-worker-media-control-container.php verify-load media-load.json`.

The recorded passes on 2026-11-04 ran on the Ryzen 7 5800X3D and RX 9070 XT
[described in the encoder measurements](media-encoder-cost.md), with the Debian 13 worker image, FFmpeg 7.1, `libx265` at six pool threads
and a 128-second 1080p30 excerpt of Big Buck Bunny. No other heavy work ran on the
host during either pass. The single encodes ran while the one-minute load average
was still falling from the load run before them: from 33 to 7 after the software
pass and from 18 to 13 after the hardware pass. Both receipts passed every
criterion:

| Pass | Video streams, steady speed | Audio first wave | Peak backends (application / control / direct) | Lease helper maximum, control / execution | Native answer maximum | Web p50 / p95 |
|---|---|---|---|---|---|---|
| Software, 8 + 12 | 8 `libx264`, 0.251–0.256x | 12.0–20.7x | 30 (20 / 8 / 2) | 0.40 / 0.24 s | 9.3 ms | 0.68 / 1.24 s |
| Hardware, 12 + 12 | 12 `hevc_vaapi`, 1.26–1.28x | 31.4–41.8x | 30 (20 / 8 / 2) | 0.19 / 0.18 s | 8.0 ms | 0.41 / 4.45 s |

An active governor decided every run admission, after learning 56 samples in
the software pass and 60 in the hardware pass. It downgraded or vetoed none, and
no unit drained, restarted or recorded an OOM kill. No run sample found an idle
server in the application pool, and pooler waits stayed at or below 31 ms in the
software pass and 36 ms in the hardware pass. In the software pass, each audio
lane encoded 14 or 15 distinct renditions and stayed busy until 420–521 seconds
in, and the videos completed after 503–515 seconds. In the hardware pass, each
lane encoded 6 to 8 renditions and the videos completed after 100–103 seconds.
The web clients completed 5,859 requests in the software pass and 738 in the
hardware pass, and none failed. In the single encodes after the load, `libx264`
ran at 1.08x, `libx265` at 1.30x, `h264_vaapi` at 9.4x and `hevc_vaapi` at
13.7x: every codec ran ahead of real time alone.

An earlier software run on the same day lost its deployment about nine minutes
in. The control unit's autoscaler exited while a backend test suite loaded the
host, and the execution unit followed when it read the control unit's draining
heartbeat. The autoscaler's report named no cause. It now reports its exception
class and location, and a tick that finds its admission lock contended publishes
nothing instead of ending the autoscaler. The passes above ran after both
changes.

On GitHub on 2026-11-12 the Firefox two-command gate lost its deployment in the
same way during the attached phase: an empty Messenger poll ended with "Ordinary
delivery ownership is uncertain", and both units drained. Returning the permit of
an empty receive now retries for up to two seconds when the lock is contended,
because such a receive owns no message, and the error names its fault code. The
run recorded no fault code, so lock contention is the likely cause but not an
established one. A unit test reproduces the failure with another process holding
the lock for 200 ms.

