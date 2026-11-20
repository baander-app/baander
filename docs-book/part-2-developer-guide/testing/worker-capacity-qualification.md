# Worker Capacity and Autoscaling Qualification

## Reserved-control pressure drill

The reserved-control pressure drill runs separately from the full backend suite:

```bash
php scripts/build-application.php --buildx --target worker --tag baander-worker:test
BAANDER_TEST_IMAGE=baander-worker:test BAANDER_TEST_OPERATOR_IN_CONTAINER=1 \
  BAANDER_TEST_DOCKER_SOCKET_PATH=/var/run/docker.sock \
  bash scripts/test-worker-control-capacity-container.sh
```

It uses disposable services and a version 9 paired deployment whose units carry
no limits or CPU sets, with 320 MiB for scheduled console execution. Its host
preflight requires four CPUs and 15 GiB. The drill runs two phases:

1. **`cpu`** runs media CPU load beside control traffic. Its CPU burners start at
   nice 10 and out-of-memory score 600, as real encodes do. The verifier requires
   FFmpeg commands at nice 10 and score 600, above the native daemon and execution
   supervisor at 300, and PHP media children at 600, above the control supervisor
   at −500.
2. **`native-loss`** ends the exact native role with `SIGKILL`, as the kernel's
   out-of-memory killer would end a victim. It requires the execution supervisor
   and native role at the execution unit's score just before the kill, an
   automatic joint drain and no control OOM event.

The former memory and PID exhaustion phases needed unit limits; without them they
would exhaust the host, so the native-loss phase replaces them. Inspect the
resource, kill-order and progress receipts before claiming pressure
qualification. Separate peer-loss and containment results from ordinary backend
correctness results. Before the limits were removed, a version 4 maximum-one run
passed the former CPU, memory and PID phases in 281.436 seconds, with verified
cleanup and joint retirement; execution OOM/PID exhaustion left control alive
with no control OOM event. Those results do not qualify the unlimited units or
maxima two and four.


### Ordinary worker autoscaling qualification

Build the current worker image with the command in the pressure drill above.
The bounded comparison runner creates separate disposable pairs for resident ordinary maxima 1, 2 and 4, using the
operator runner's dependency, lifecycle and cleanup ownership. Each profile derives a disposable fixture from the selected
production image so it can inject scenario services. `BAANDER_TEST_USE_IMAGE=1`
is rejected before provisioning. Fixture development classmaps expose workload
classes without installing development dependencies or PHPUnit:

```bash
BAANDER_TEST_IMAGE=baander-worker:test BAANDER_TEST_OPERATOR_IN_CONTAINER=1 \
  BAANDER_TEST_DOCKER_SOCKET_PATH=/var/run/docker.sock \
  BAANDER_TEST_CHECKOUT_IN_IMAGE=1 BAANDER_TEST_USE_IMAGE=0 \
  bash scripts/test-worker-autoscaling-container.sh
```

The runner bounds each stage separately:

- Each profile has a 900-second bound, including setup and workflow completion.
- Its observation callback has a separate 480-second deadline and a 470-second
  evidence-wait budget. Individual Docker observations have 12-second bounds.
- The complete three-profile comparison has a 2000-second deadline.
- The shared `workerCapacity` default is 900 seconds; cleanup retains its separate
  120-second budget. Capacity, media and autoscaling callbacks retain their 240-,
  300- and 480-second bounds, respectively.

A profile whose outer deadline expires fails even if its measurements and cleanup
later report success. Image acquisition/build time depends on
the selected runner mode. Retain the printed artifact directory after a failure;
a skipped profile or scenario is an acceptance gap.

The fixture writes thresholds before dispatch, then runs first and repeated bursts
of 48 synthetic ordinary messages with two-second I/O waits. Each burst must finish
within 150 seconds and return to target one within 110 seconds. Maxima 2 and 4 must
reach their admitted maximum and improve burst throughput by at least 1.15 and 1.4
times the one-slot baseline respectively. The pressure phase combines three video
jobs and one audio rendition with execution CPU pressure. The audio encoder is
deliberately stopped after initial output to retain its occupied slot, while the
three video encoders remain active; this does not measure uninterrupted audio
throughput during pressure. Desired-state controls must finish within three
seconds, and admission must not grow on high CPU evidence. The execution unit has no CPU quota, so media load measured against
every host CPU rarely crosses the CPU ceiling; the policy tests cover blocked
growth. The drill instead requires each known resource observation to be measured
against the host's CPUs, memory and task limit, with a utilization that matches
the execution cgroup's own CPU time over all host CPUs. Native commands sampled
under load must run at nice 10 and score 600. Sampling gaps must stay within five
seconds, deployment authority must remain continuous,
and both units must remain running at the final inspection.

Artifacts include declared thresholds, daemon/container details, manifest
fingerprints, startup timing, timestamped slot/queue/resource/status observations,
completion receipts and media results, including each unit's absent limits and
the host-relative measurement. Every resident consumer remains resident even when
parked. The preflight requires four available CPUs and 15 GiB of host memory, or
16 GiB for maximum four; these are host minimums, not measured RSS or throughput.

The recorded qualification below ran before the worker units lost their limits;
its CPU placement, quotas and ceilings describe that run, not the current fixture.
It used an AMD Ryzen 7 5800X3D host with eight cores, sixteen threads
and 64 GiB of physical RAM (Docker reported 67337879552 bytes), Docker
29.7.2-ce and kernel 7.2.10-1-default. Control used CPU 0 with a one-CPU quota;
execution used CPUs 1–2 with a two-CPU quota; dependencies and web shared CPU 3.
The operator example recommends maximum two for this measured envelope. The
internal constructor and unmodified fixture profile retain maximum one, and
production manifests require every field explicitly.

| Resident maximum | First/repeat I/O burst (seconds) | Speedup over maximum one | Execution peak cgroup memory (MiB) | Execution peak PIDs | Peak database connections | Checkpoint (seconds) |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | 97.42 / 97.43 | 1.00× | 878.5 | 31 | 35 | 358 |
| 2 | 56.99 / 56.97 | 1.71× / 1.71× | 973.6 | 32 | 37 | 336 |
| 4 | 39.21 / 38.48 | 2.48× / 2.53× | 1021.4 | 34 | 39 | 422 |

Maximum four is a measured option for I/O-heavy work within this envelope, with
5440 MiB/259 PID slots for execution including scheduled console. Its ordinary
CPU workload took 27.87 seconds, compared with 26.50 seconds at maximum two;
its CPU target reached only two before pressure returned it to one. The example
therefore retains maximum two as the general starting profile, with fewer resident
processes and connections.

Maximum two grew to two for each burst and returned to one between bursts. During
ordinary CPU work it grew to two, then resource pressure lowered the target to one.
Its execution cgroup recorded 35.22 CPU seconds for that phase. Media-control
p99/max were 0.198/0.250 seconds and heartbeat p99/max were 0.0103/0.0163 seconds.
All five scheduled occurrences returned; ordinary pressure work acknowledged
1369 deliveries. A paused-controller probe left one message unread while the
controller was stale and completed it after recovery, which took 2.83 seconds.

The first execution checkpoint arrived 17.38 seconds after container start for
maximum two, with 379887616 bytes of cgroup memory and seven observed processes.
That interval includes readiness, harness checks and the observation-gap probe;
it excludes dependency/media preparation. Process counts and summed resident RSS
include the qualification observer and shared pages; they are not idle application
RSS. The first burst is labeled `cold` in artifacts but follows initialized
duration history. Blocking waits model I/O rather than a provider latency
distribution. Retry races are covered separately through real Messenger tests.

Full harness startup to first checkpoint took 77.25, 106.28 and 66.33 seconds
for maxima one, two and four, with one-second precision in the harness start clock.
These intervals include fixture-image preparation, dependencies/media, web and
pair readiness and the gap probe; they do not isolate production startup latency.
PostgreSQL, Redis and web each had a 0.5-CPU quota on CPU 3, with configured
memory/PID ceilings of 4096 MiB/64, 256 MiB/64 and 2048 MiB/128 respectively.
Together with the pair, the five containers' memory ceilings sum to 13.5,
13.8125 and 14.4375 GiB. These are configured limits, not measured dependency
utilization; operator helper overhead is outside those sums.

Artifacts are `var/test-runs/autoscaling-qualified-v3/{1,2,4}/qualification.json`,
`startup-accounting.json` in each profile directory and `comparison.json` in the
parent. The one-slot baseline was retained from the valid earlier run and accepted
by the current stricter verifier; maxima two and four are fresh runs. All three
profiles passed their checkpoints, comparison thresholds and cleanup. Earlier
failed attempts remain failed evidence. The fresh production-image contract,
maximum-two production media drill, real worker-command lifecycle and all twenty
backend isolation checks pass. The version 4 reserved-control CPU/memory/PID drill
also passes at maximum one; its exhaustion results do not qualify maxima two or
four. Final independent review is complete with no remaining blockers for the
recorded hardware, placement and workload. Smaller-host, live-provider, regional
and longer failure/load qualification remain separate. This ordinary gate does
not replace backend, native, retry, media, lease-loss or joint-retirement gates.
