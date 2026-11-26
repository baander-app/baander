# Worker Lifecycle and Production Image Qualification

The standalone lifecycle runner pauses Redis and PostgreSQL in separate disposable
operator projects, with no HTTP container and an ordinary maximum of two:

```bash
BAANDER_TEST_IMAGE=baander-ci:test BAANDER_TEST_OPERATOR_IN_CONTAINER=1 \
  BAANDER_TEST_DOCKER_SOCKET_PATH=/var/run/docker.sock \
  bash scripts/test-worker-lifecycle-container.sh
```

Use the application CI image built for the current checkout. The runner takes no
arguments, defaults to container controller mode and uses the operator runner's
Docker, image and PostgreSQL settings described in
[Worker Runner Setup and Operator Qualification](worker-runner-setup.md).
`BAANDER_TEST_CHECKOUT_IN_IMAGE=1` selects
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

The runner has a 600-second outer deadline; image build time is outside that bound.
It verifies the worker user, entrypoint, disabled web health check and production
environment and disabled PHP error display; checks that development dependencies,
baked OAuth keys and agent artifacts are absent; and runs Composer's production
platform checks. A real coroutine file read/write smoke test runs against the
immutable image with default Docker seccomp, all capabilities dropped and
`no-new-privileges`. This catches an io_uring-enabled Swoole build that cannot use
its native file backend under those restrictions. The supported image builds
Swoole without io_uring; no runtime INI switch substitutes for that build choice.
The runner then uses `BAANDER_TEST_USE_IMAGE=1` with container controller mode to
run the lifecycle against that exact immutable image. This skips the fixture rebuild and injects no
source, dependencies, keys or cache into the worker. Test preparation and Docker
authority remain in separate containers. The scope is the worker lifecycle,
outbox and scheduler, without OAuth certification. CI now includes blocking
context-exclusion, production-worker build and production-lifecycle steps. Their
configuration does not establish a passing CI run on either forge. The recorded
immutable-image lifecycle and combined Chromium playback/recovery gates passed
before the paired deployment change. Retain image identity, phase, containment and
cleanup evidence with the runner's artifacts before claiming a result for a newer
deployment.
The production recipes select PHP 8.5.13 and Composer 2.11.1 on the Debian 13
(trixie) PHP ZTS base. Native Swoole is pinned to Composer's required 6.2.4. PIE
installation failures fail the image build.
