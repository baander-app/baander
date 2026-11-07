# Messaging and Worker Qualification

Verification of Symfony Messenger workload routing, Redis fallback without Swoole, worker deployment lifecycle and containment runners, autoscaling qualification, and scheduler occurrence persistence.

### Validated asynchronous workloads

`config/packages/messenger.php` declares seventeen production workloads: fifteen
default routes and explicit-only `FetchLyricsCommand` and `SendWebhookCommand`
entries. The test configuration adds `FailureTransportProbe` and uses in-memory
transports for `async` and `scheduler`. Resource classes `io`, `cpu` and `coordination` describe
work; they do not allocate capacity.

Catalog and compiler-pass unit tests cover invalid workload declarations, codec
registrations, effective default routes, transport-eligible handlers, JSON
serialization and bounded retry policies. `AsyncWorkloadWiringTest` boots the real
container to check the compiled catalog and sender-locator decorator.
`ValidatedSendersLocatorTest` checks actual sender selection, including explicit
transport stamps, interface/wildcard routes, rejected fan-out and unrouted inline
commands. Received deliveries retain normal handling and manual failed-message
retry behavior. Lyrics remain synchronous unless explicitly selected for `async`.
The notification delivery relay decodes committed intents, resolves their channel
and selects `async` explicitly, including webhook delivery. Relay-intent coverage
checks that these produced commands belong to the admitted catalog.

`WorkerTransportConfigurationTest` checks canonical Redis URL forms and rejects
stream, group and consumer overrides, ambiguous query options and alternate
transports. Controller and supervisor tests verify admission before Docker
creation or child launch, preserved catalog receiver order and generated consumer
identity. These checks establish configuration behavior rather than connectivity,
network discovery or measured capacity. The messaging and worker-command runners
below exercise the corresponding container and runtime paths.

### Messenger without Swoole

To check Redis fallback with the Swoole extension unavailable, use the same CI
image and disposable coordinator. Set `BAANDER_TEST_IMAGE` to the application CI
image and `BAANDER_TEST_POSTGRES_IMAGE` to the project's PostgreSQL image before
running:

```bash
BAANDER_TEST_DISABLE_SWOOLE=1 bash scripts/test-backend-container.sh \
  tests/Fixtures/MessengerWithoutSwooleTest.php \
  tests/Unit/Shared/Infrastructure/Messenger/SwooleFallbackHandlerRegistrationTest.php \
  tests/Integration/MessengerJsonDeliveryTest.php \
  tests/Integration/MessengerAdmissionLifecycleTest.php
```

The coordinator removes the dedicated Swoole loader inside each disposable app
container before discovery or console boot. It compares PHP configuration hashes
and loaded extensions before and after removal, requiring every other extension
and setting to remain unchanged. Runtime evidence stays beside the lane logs as
`<lane>.runtime-before.json`, `<lane>.runtime-after.json` and `<lane>.runtime.log`.
The option accepts `0` or `1` and defaults to `0`.

The dedicated fixture verifies absence in the ParaTest worker and a nested PHP
process, and checks synchronous execution identity, recursive locking and service
pool reuse without the extension. It lives outside the default suites because it
deliberately fails when Swoole is loaded. The selected tests exercise transport-restricted handler
registration, real Redis delivery, retry and dead-letter handling. Service setup
also clears the Symfony cache and runs migrations twice without Swoole. CI runs
this selection as a blocking step. This qualifies the console and Messenger
paths exercised here; the web server still requires Swoole, and the Composer
extension requirement remains unchanged.

Run the backend runner regressions before changing orchestration or cleanup:

```bash
composer run-script --timeout=7200 test:tooling
bash scripts/test-worker-operator-cleanup.sh
```

CI runs the PHP tooling suite and the operator cleanup script as separate steps.
The operator cleanup script uses a disposable Docker CLI stub to check enumeration
failures, removal failures, retained resources, browser-hook cleanup and preservation
of an earlier failure exit code. It does not prove cleanup against a real daemon; the production
operator drill must still verify absence of its containers, networks and volumes.

For strict unit and messaging checks, run from the checkout:

```bash
bash scripts/test-unit-container.sh
bash scripts/test-messaging-container.sh
bash scripts/test-worker-retirement-container.sh
bash scripts/test-worker-recovery-container.sh
bash scripts/test-worker-command-container.sh
bash scripts/test-worker-operator-container.sh
BAANDER_TEST_DOCKER_SOCKET_PATH=/var/run/docker.sock \
  bash scripts/test-worker-lifecycle-container.sh
bash scripts/test-docker-context.sh
bash scripts/test-worker-containment-container.sh
bash scripts/test-functional-container.sh tests/Functional/Controller/FavoritesControllerTest.php
bash scripts/test-functional-container.sh tests/Integration/CoverExtractionPersistenceTest.php
bash scripts/test-functional-container.sh tests/Integration/CoverlessAlbumKeysetTest.php
bash scripts/test-functional-container.sh tests/Integration/WorkerDeploymentLeaseSchemaTest.php
```

Both unit and functional wrappers invoke the shared backend coordinator. They
require the host tooling baseline above, Docker, the Docker Compose plugin and
installed application Composer dependencies. The unit wrapper defaults to
`Unit,StaticAnalysisRules`; filters keep that selection, a later explicit
`--testsuite` overrides it, and file paths follow
PHPUnit's selection rules. The functional wrapper defaults to `tests/Functional`
only when called without arguments; otherwise it forwards the supplied selection,
including integration paths.

Both wrappers use 512 MiB test workers, 2 GiB coordinator/reporting PHP processes
and the default 3600-second overall timeout. Ordinary and native unit lanes have
networking disabled; functional and integration selections use disposable Compose
stacks. `phpunit.xml.dist` randomizes test order within dependency constraints and
treats unexpected output as risky.

The operator runner requires the host tooling baseline above and Docker. Its
default host mode also requires the operator PHP runtime with `posix` and
`pdo_pgsql` (`BAANDER_TEST_PHP_BINARY` selects its executable) and a local Unix
target selected through the
[shared Docker configuration](../../../packages/baander-tooling/README.md#configuration-and-image-roles).
The host controller reaches a loopback-published database port; the worker reaches
the same database through its private network. Its outer 240-second timeout bounds
native database waits; DBAL `connect_timeout` is not a hard connection deadline.

`BAANDER_TEST_OPERATOR_IN_CONTAINER=1` runs the entrypoint in a separate trusted
controller container with PHP and a verified static Docker CLI. The CLI comes from
the catalog's `operatorHelper` role (`BAANDER_TEST_DOCKER_CLI_IMAGE` overrides it).
Container mode requires `BAANDER_TEST_DOCKER_SOCKET_PATH`: the Unix socket path on
the selected Docker daemon's host, used as the source of the controller's bind
mount. The examples use `/var/run/docker.sock` for a standard rootful daemon.
For rootless Docker or a forwarded socket, replace that value with the actual
daemon-host source path. A socket path visible inside the outer runner may differ
from that source path.

Only the controller receives the daemon socket mount. Its SELinux process label is
disabled to access that socket without relabeling it; this exception applies only
to the trusted test controller. Credentials are streamed into private files owned
by its user, and it reaches PostgreSQL on a separate controller network with
no published database port. The controller verifies that its socket reaches the
same daemon before issuing an operator action. The worker keeps its private
network and receives only its admitted named media volumes, with no Docker
authority. Version 8 manifests require complete deployment and autoscaling
profiles, read-only source and writable derived volumes, and a private IPC volume.
Both units run without CPU, memory or PID limits and without CPU sets; the fixture
checks that Docker reports no limit on either unit and that each unit starts at
its out-of-memory score, −500 for control and 300 for execution. See the
[operator recipe](../../part-1-operator-guide/worker-deployment.md).
With no explicit override in the shared Docker configuration, setup captures the
runner's ambient Docker connection. The explicit socket mapping supplies the
controller's daemon-host bind source when the runner is itself a container.

The paired operator fixture requires at least four host CPUs and 13 GiB of host
memory. Combined web/media and control-capacity runs require 15 GiB, and an
ordinary maximum of four adds 1 GiB. The worker units have no limits, so these
are host preflight minimums rather than unit ceilings. The disposable PostgreSQL,
Redis and web containers keep their own fixture limits of 4 GiB, 256 MiB and
2 GiB. None of these figures is a measured capacity default.

Both modes exercise `bin/worker-deployment.php` create, reconciliation, start,
status and recovery against disposable PostgreSQL/Redis with canonical private
credentials. They verify real outbox delivery, scheduler work, lease/child
identities and retirement of the immutable boot. Earlier qualification passed both
modes, including container mode from a workspace with only the former runner script
and no host PHP or Composer dependencies. The PHP orchestration now requires the
host tooling baseline in both modes. The fixture image does not certify the
production build.
CI now includes a
blocking operator lifecycle step using container mode and
`BAANDER_TEST_CHECKOUT_IN_IMAGE=1` to copy source and dependencies from the built
image. Its `quality-gate` job sets `BAANDER_TEST_DOCKER_SOCKET_PATH` from the
repository variable of the same name, defaulting to `/var/run/docker.sock`. Set
that variable for a runner with a different daemon-host socket path. Adding that
gate does not establish a passing CI run on either forge. Status does not certify
readiness.

The standalone lifecycle runner pauses Redis and PostgreSQL in separate disposable
operator projects, with no HTTP container and an ordinary maximum of two:

```bash
BAANDER_TEST_IMAGE=baander-ci:test BAANDER_TEST_OPERATOR_IN_CONTAINER=1 \
  BAANDER_TEST_DOCKER_SOCKET_PATH=/var/run/docker.sock \
  bash scripts/test-worker-lifecycle-container.sh
```

Use the application CI image built for the current checkout. The runner takes no
arguments, defaults to container controller mode and uses the operator runner's
Docker, image and PostgreSQL settings. `BAANDER_TEST_CHECKOUT_IN_IMAGE=1` selects
source and dependencies from the image. `BAANDER_TEST_ARTIFACTS` selects the evidence
directory; otherwise it creates `var/test-runs/worker-lifecycle-*`. Each phase
writes `qualification.json`, `cleanup.json`, exact lease/work receipts and process
observations beneath its own subdirectory, with a sibling phase log. The outer
timeout is 1300 seconds, followed by a 40-second kill grace.

With Redis paused, status must report unavailable queue measurements while
controller evidence and deployment authority remain fresh. The fixture commits
an identified outbox event and manual scheduler occurrence, observes their
unfinished state, then sends TERM to the control unit's PID 1. Both units must
stop within 50 seconds of TERM, before Redis is restored. This checks explicit
shutdown during blocked transport I/O; Redis observation loss alone does not
revoke PostgreSQL lease authority. The Redis server identity must survive the
pause. With PostgreSQL paused, the fixture sends no stop signal: failed lease
renewal must drain both units automatically within 50 seconds of the fault.
Neither phase permits a restart or OOM kill as its shutdown evidence.

After restoration, the predecessor's exact boot and epoch still reserve ownership.
A different boot cannot replace it until recovery records removal of both captured
immutable unit IDs. Repeated recovery must remain idempotent. The successor uses a
fresh boot and epoch two; delayed predecessor recovery, renewal and start must
leave that successor intact. The Redis phase checks the exact work retained before
shutdown. The PostgreSQL phase seeds work after successor admission, because it
cannot assume earlier work remained unconsumed. After the stale predecessor
operations, both phases seed a fresh outbox event and manual occurrence and require
their completion under the successor. Each work batch has a 90-second deadline.

Completion requires the identified outbox event, one notification projection,
both intended channel deliveries and one returned scheduler execution under the
successor's exact authority, with no failed messages or residual stream, pending
or delayed entries. The scheduler publication receipt `dispatched_at` can remain
null when Redis accepted a send whose reply was lost before the old worker stopped;
`dispatch_token` and the exact returned effect establish this check's completion.
An execution already consumed without a return receipt remains uncertain and
cannot be replayed merely to obtain a completion marker. This drill does not
establish exactly-once delivery, broker crash durability, automatic replacement,
unconditional transport I/O deadlines or new autoscaling performance results.
Cleanup restores any paused dependency and verifies absence of run-owned resources;
cleanup cannot substitute for the earlier shutdown and retirement observations.
CI runs this same container controller path as a blocking step; the configuration
alone is not evidence of a completed remote run.

To check the actual production worker target, build it separately and pass its
local image reference to the production runner:

```bash
php scripts/build-application.php --buildx --target worker --tag baander-worker:test
BAANDER_TEST_IMAGE=baander-worker:test BAANDER_TEST_DOCKER_SOCKET_PATH=/var/run/docker.sock \
  bash scripts/test-worker-production-container.sh
```

The runner has a 300-second outer deadline; image build time is outside that bound.
It verifies the worker user, entrypoint, disabled web health check and production
environment and disabled PHP error display; checks that development dependencies,
baked OAuth keys and agent artifacts are absent; and runs Composer's production
platform checks. A real coroutine file read/write smoke test runs against the
immutable image with default Docker seccomp, all capabilities dropped and
`no-new-privileges`. This catches an io_uring-enabled Swoole build that cannot use
its native file backend under those restrictions. The supported image builds
Swoole without io_uring; no runtime INI switch substitutes for that build choice.
It then uses
`BAANDER_TEST_USE_IMAGE=1` with container controller mode to run the lifecycle
against that exact immutable image. This skips the fixture rebuild and injects no
source, dependencies, keys or cache into the worker. Test preparation and Docker
authority remain in separate containers. The scope is the worker lifecycle,
outbox and scheduler, without OAuth certification. CI now includes blocking
context-exclusion, production-worker build and production-lifecycle steps. Their
configuration does not establish a passing CI run on either forge. The recorded
immutable-image lifecycle and combined Chromium playback/recovery gates passed
before the paired deployment change. Retain image identity, phase, containment and cleanup evidence
with the runner's artifacts before claiming a result for a newer deployment.
The production recipes select PHP 8.5.12 and Composer 2.11.1 on the Debian 13
(trixie) PHP ZTS base. Native Swoole is pinned to Composer's required 6.2.4. PIE
installation failures fail the image build.


The reserved-control pressure drill runs separately from the full backend suite:

```bash
php scripts/build-application.php --buildx --target worker --tag baander-worker:test
BAANDER_TEST_IMAGE=baander-worker:test BAANDER_TEST_OPERATOR_IN_CONTAINER=1 \
  BAANDER_TEST_DOCKER_SOCKET_PATH=/var/run/docker.sock \
  bash scripts/test-worker-control-capacity-container.sh
```

It uses disposable services and a version 8 paired deployment whose units carry
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

Build the current worker image as above. The bounded comparison runner creates
separate disposable pairs for resident
ordinary maxima 1, 2 and 4, using the operator runner's dependency, lifecycle and
cleanup ownership. Each profile derives a disposable fixture from the selected
production image so it can inject scenario services. `BAANDER_TEST_USE_IMAGE=1`
is rejected before provisioning. Fixture development classmaps expose workload
classes without installing development dependencies or PHPUnit:

```bash
BAANDER_TEST_IMAGE=baander-worker:test BAANDER_TEST_OPERATOR_IN_CONTAINER=1 \
  BAANDER_TEST_DOCKER_SOCKET_PATH=/var/run/docker.sock \
  BAANDER_TEST_CHECKOUT_IN_IMAGE=1 BAANDER_TEST_USE_IMAGE=0 \
  bash scripts/test-worker-autoscaling-container.sh
```

Each profile has a 900-second bound, including setup and workflow completion. Its
observation callback has a separate 480-second deadline and a 470-second
evidence-wait budget. Individual
Docker observations have 12-second bounds. The runner also enforces a 2000-second
deadline for the complete three-profile comparison. The shared `workerCapacity`
default is 900 seconds; cleanup retains its separate 120-second budget. Capacity,
media and autoscaling callbacks retain their 240-, 300- and 480-second bounds,
respectively. A profile whose outer deadline expires fails even if its measurements
and cleanup later report success. Image acquisition/build time depends on
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
throughput during pressure. Desired-state controls
must finish within three seconds, and admission must not grow on high CPU
evidence. The execution unit has no CPU quota, so media load measured against
every host CPU rarely crosses the CPU ceiling; the policy tests cover blocked
growth. The drill instead requires each known resource observation to be measured
against the host's CPUs, memory and task limit, with a utilization that matches
the execution cgroup's own CPU time over all host CPUs. Native commands sampled
under load must run at nice 10 and score 600. Sampling
gaps must stay within five seconds, deployment authority must remain continuous,
and both units must remain running at the final inspection.

Artifacts include declared thresholds, daemon/container details, manifest
fingerprints, startup timing, timestamped slot/queue/resource/status observations,
completion receipts and media results, including each unit's absent limits and
the host-relative measurement. Every resident consumer remains resident even when
parked. The preflight requires four available CPUs and 15 GiB of host memory, or
16 GiB for maximum four; these are host minimums, not measured RSS or throughput.

The recorded qualification below ran before the worker units lost their limits;
its CPU placement, quotas and ceilings describe that run, not the current fixture.
Local qualification used an AMD Ryzen 7 5800X3D host with eight cores, sixteen threads
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

The messaging runner waits for PostgreSQL TCP readiness (not its temporary
initialization socket) and prints service logs on readiness timeout. It also
explicitly selects `phpunit.xml.dist` and provisions
disposable PostgreSQL and Redis for its selected transport, outbox, access-token
cache transaction, deployment lease, and PGroonga compatibility tests. The lease
tests use the actual migration and independent PostgreSQL connections to exercise
ownership, expiry, lock contention, stale epochs and uncertain commit recovery.
The runner includes `bin/worker-lease-agent.php` and exercises fresh helper processes
against PostgreSQL, including blocked calls, parent deadlines, cancellation,
malformed responses and output limits. Unit tests separately exercise monotonic
lease authority and the runtime coordinator with controlled helper responses;
they verify that pending renewal cannot block worker shutdown at lease expiry.
`WorkerLeaseAgentTest` also keeps a parent transaction open while the real
fresh-exec helper writes through a distinct PostgreSQL backend. An independent
observer cannot see the parent's uncommitted writes; the parent's backend and
transaction remain usable, and rollback leaves the helper's committed lease intact.
`NativeExecutionDaemonTest` captures a live FFmpeg process identity and its owned
process group before daemon TERM, then requires daemon exit, no live group member
and removal of the native socket within the fixture deadline.

To run these checks with the real transport response-loss cases:

```bash
bash scripts/test-backend-container.sh \
  tests/Integration/WorkerLeaseAgentTest.php \
  tests/Integration/NativeExecutionDaemonTest.php \
  tests/Integration/MessengerAdmissionLifecycleTest.php
BAANDER_TEST_DISABLE_SWOOLE=1 bash scripts/test-backend-container.sh \
  tests/Integration/WorkerLeaseAgentTest.php \
  tests/Integration/MessengerAdmissionLifecycleTest.php
```

The response-loss proxy forwards the selected Redis command and captures its
successful server response before dropping only that response. Accepted
`XREADGROUP` must leave the exact stream ID owned by the named consumer in the
pending-entry list, without a handler effect. Accepted `XACK` must remove that
pending entry after one handler effect. In both cases, the admission ledger keeps
the same request uncertain and occupied, faults admission and refuses another
receive. An ordinary disconnect without a confirmed accepted command does not
satisfy this test. Existing retry-publication checks remain in the same file.


The worker containment runner creates dedicated, disposable containers with one
CPU, 256 MiB of memory, no networking, no added capabilities, and no host mounts.
It runs the supervisor core as PID 1 with a child and a TERM-ignoring descendant.
Both graceful TERM and supervisor SIGKILL must stop the container and descendant
activity within the test deadline. The fixture distinguishes direct-child drain
from verified containment: it exits as PID 1 without acknowledging containment or
releasing a deployment lease, then the external runner checks namespace shutdown.
The runner fails on unexpected exit status or
OOM and removes its containers on exit. It tests the proposed container boundary;
it does not certify per-child descendant cleanup on restart, host systemd behavior,
deployment lease takeover, or measured production resource defaults.

The retirement runner additionally needs host PHP and Docker CLI access to a local
Unix daemon endpoint. It starts a restricted restart-always deployment fixture,
rejects an incorrect boot identity, then exercises the real external retirement
adapter. Confirmed force-removal must prevent restart of the old full container ID;
a subsequent absent-container request must fail closed. PostgreSQL controller tests
in the messaging runner separately verify that retirement failures preserve the
lease and concurrent recovery cannot release a newer epoch. The recovery runner combines the real Docker adapter with PostgreSQL lease
coordination. It needs host PHP with `pdo_pgsql`, publishes only an ephemeral
loopback database port, and destroys its disposable resources. It verifies
wrong-owner and wrong-label rejection, expiry without takeover, confirmed removal,
new epoch acquisition and stale-token rejection. The fixture exercises external
controller startup and recovery, not a complete `LeasedWorkerRuntime` deployment.
The predecessor is created with restart disabled and remains unstarted until its
inventory binding and one-shot start claim commit. The fixture checks visibility
through an independent PostgreSQL connection at the real Docker start call, then
verifies that a repeated controller start cannot restart the predecessor. A second
fixture makes Docker create a real container and then discards its acknowledgment.
It verifies durable creation admission, refusal to recreate, exact-recipe
reconciliation without process activity, and registered startup/recovery.

The worker-command runner boots the actual `app:worker` as PID 1 against disposable
production-mode PostgreSQL and Redis after fresh and repeat migrations.
It also supplies Redis consumer overrides through path and query forms and a
conflicting scheduler path, requiring rejection before locks, lease authority or
child launch, with credential values absent from output. Accepted startup checks
both catalog receivers in separate execution consumers and their generated
identities. It verifies
the control and execution roles, peer/native admission before business launch,
their exact committed deployment tuple (overriding a bogus
inherited epoch), and the namespace/boot-qualified Redis consumer. It kills the
consumer and scheduler separately to require whole-container draining, tests TERM and forced database lease
expiry, and confirms a duplicate
lease claim exits with zero launch attempts. It checks that ownership remains
reserved after shutdown. It also seeds a real registration event before startup,
requires the supervised relay to commit the notification, receipt and channel
handoffs, requires Redis acknowledgment with no pending, delayed or failed work,
and repeats the checks after clearing the event acknowledgment. Outbound
preferences are disabled, the disposable user is unverified and no webhook
endpoints exist; no external delivery is requested. A paused manual console request
runs the registered cache sweep in dry-run mode through the dedicated scheduler
stream; duplicate delivery must not invoke it twice. These checks do not establish
complete application readiness, autoscaling, measured production capacity or
end-to-end media playback and recovery.
CI runs this as a blocking step using `BAANDER_TEST_CHECKOUT_IN_IMAGE=1`, which
copies source and Composer dependencies from the built application image.
The functional `Console/ServeCommandTest` verifies that `app:serve` and the legacy
server name resolve to the same real command and preserve its options.


Scheduler occurrence persistence can be checked with:

```bash
bash scripts/test-functional-container.sh tests/Integration/SchedulerOccurrenceStoreTest.php tests/Integration/SchedulerOccurrenceExecutionStoreTest.php tests/Integration/SchedulerOccurrenceGuardTest.php tests/Integration/SchedulerOccurrenceDispatchStoreTest.php tests/Integration/SchedulerOccurrenceRelayTest.php tests/Integration/WorkerDeploymentLeaseSchemaTest.php
```

These checks cover immutable snapshots, independent-connection visibility,
competing inserts, rollback and a lost commit acknowledgment. The actual migration
also enforces command/JSON bounds and minute-aligned instants; Doctrine's schema
filter preserves the tables. Execution checks require a committed attempt before
the nested effect, verify the configured Kernel services, and force a failure
after an effect through actual Redis/Messenger retries. Redelivery must leave the
consumed attempt intact without repeating the effect. Missing/future occurrences,
lost commit acknowledgments and wrong-owner return markers cannot grant execution.
Admission also rejects absent, malformed, expired or replaced deployment authority
without consuming an attempt. Tests cover lease-row contention, authority lost
during insertion with transaction rollback, and exact historical-owner receipts
after expiry or replacement. The initial schema requires the deployment tuple;
initializing it does not upgrade or reset an existing local database.
Dispatch tests exercise committed reservations, skipped locked rows, token replacement
and idempotent acceptance receipts on PostgreSQL. Real Redis tests send through the
explicit JSON serializer and reproduce acceptance followed by a lost acknowledgment;
recovery republishes the same occurrence identity without granting a second attempt.
A recorded acceptance stops repeated publication while consumers are stopped. Unit
tests require the rest of a reserved batch to be attempted after a failed send or
receipt. The configured Kernel resolves the relay and its explicit async sender.
This does not test automatic polling, fencing effects after lease expiry, broker
crash durability or native console/media execution. No occurrence relay loop is
enabled yet.

Scheduled-job source parameters can be checked against the real ORM and PostgreSQL:

```bash
bash scripts/test-functional-container.sh tests/Integration/ScheduledJobParameterPersistenceTest.php
```

These checks verify the physical JSON column, fresh ORM reads, parameter order and
numeric representation before materializing an occurrence, plus the relevant schema
comparison. Native JSON preserves the encoded values; it does not solve stale
schedule writes or Doctrine's signed-zero-only dirty-checking limitation.

The occurrence console adapter and worker-stop policy can be checked with:

```bash
bash scripts/test-functional-container.sh tests/Integration/ScheduledConsoleExecutorTest.php tests/Integration/ScheduledConsoleWorkerStopTest.php tests/Integration/SchedulerOccurrenceGuardTest.php
```

Real CLI children verify literal argument handling, PHP heap settings, concurrent
stdout/stderr draining, output limits, deadlines, ignored TERM followed by KILL,
unexpected signals and exact direct-child reaping. The actual Messenger worker
must stop before its next queued job after an uncertain result, including nested
handler exceptions; ordinary known failures may continue. Kernel checks verify the
executor port and stop subscriber registration. These fixtures do not certify
business-command side effects, descendant containment, kernel-stalled processes or
total deployment memory. The occurrence handler's unit tests also require the
paused state and uncertainty to survive persistence failures without issuing a
return receipt or granting a second execution.

