# Worker Runner Setup and Operator Qualification

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

Both wrappers invoke the shared backend coordinator and require the
[host tooling baseline](backend-runners.md), Docker, the Docker Compose plugin and
installed application Composer dependencies. Their default selections differ:

- The unit wrapper selects `Unit,StaticAnalysisRules`. Filters keep that selection;
  a later explicit `--testsuite` overrides it. File paths follow PHPUnit's
  selection rules.
- The functional wrapper selects `tests/Functional` only when called without
  arguments. Otherwise, it forwards the supplied selection, including integration
  paths.

Both wrappers use the coordinator's PHP memory limits (512 MiB for test workers,
2 GiB for coordinator and reporting processes) and the default 3600-second overall
timeout. Ordinary and native unit lanes have
networking disabled; functional and integration selections use disposable Compose
stacks. `phpunit.xml.dist` randomizes test order within dependency constraints and
treats unexpected output as risky.

The operator runner requires the [host tooling baseline](backend-runners.md) and Docker. Its
default host mode also requires the operator PHP runtime with `posix` and
`pdo_pgsql` (`BAANDER_TEST_PHP_BINARY` selects its executable) and a local Unix
target selected through the
[shared Docker configuration](../../../dev-docs/workflow-packages.md#configuration-and-image-roles).
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

Only the controller receives the daemon socket mount. Before issuing an operator
action, it verifies that the socket reaches the same daemon. Its SELinux process
label is disabled so it can access the socket without relabeling it; this exception
applies only to the trusted test controller. Credentials arrive as streams into
private files owned by its user. It reaches PostgreSQL on a separate controller
network with no published database port.

The worker keeps its private network and has no Docker authority. It receives
only its admitted named media volumes. Version 9 manifests require complete
deployment and autoscaling profiles, read-only source and writable derived
volumes, and a private IPC volume.
Both units run without CPU, memory or PID limits and without CPU sets; the fixture
checks that Docker reports no limit on either unit and that each unit starts at
its out-of-memory score, −500 for control and 300 for execution. See the
[operator recipe](../../part-1-operator-guide/workers/worker-deployment.md).
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

CI now includes a blocking operator lifecycle step that uses container mode and
`BAANDER_TEST_CHECKOUT_IN_IMAGE=1` to copy source and dependencies from the built
image. Its `quality-gate` job sets `BAANDER_TEST_DOCKER_SOCKET_PATH` from the
repository variable of the same name, defaulting to `/var/run/docker.sock`. Set
that variable for a runner with a different daemon-host socket path. Adding that
gate does not establish a passing CI run on either forge. Status does not certify
readiness.
