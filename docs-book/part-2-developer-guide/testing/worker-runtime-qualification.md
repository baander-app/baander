# Worker Transport and Runtime Qualification

## Messaging, lease helpers and response loss

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


## Containment, retirement and recovery

The worker containment runner creates dedicated, disposable containers with one
CPU, 256 MiB of memory, no networking, no added capabilities, and no host mounts.
It runs the supervisor core as PID 1 with a child and a TERM-ignoring descendant.
Both graceful TERM and supervisor SIGKILL must stop the container and descendant
activity within the test deadline. The fixture distinguishes direct-child drain
from verified containment: it exits as PID 1 without acknowledging containment or
releasing a deployment lease, then the external runner checks namespace shutdown.
The runner fails on an unexpected exit status or OOM and removes its containers on
exit. It tests the proposed container boundary;
it does not certify per-child descendant cleanup on restart, host systemd behavior,
deployment lease takeover, or measured production resource defaults.

The retirement runner additionally needs host PHP and Docker CLI access to a local
Unix daemon endpoint. It starts a restricted restart-always deployment fixture,
rejects an incorrect boot identity, then exercises the real external retirement
adapter. Confirmed force-removal must prevent restart of the old full container ID;
a subsequent absent-container request must fail closed. PostgreSQL controller tests
in the messaging runner separately verify that retirement failures preserve the
lease and concurrent recovery cannot release a newer epoch.

The recovery runner combines the real Docker adapter with PostgreSQL lease
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

## Worker command lifecycle

The worker-command runner boots the actual `app:worker` as PID 1 against disposable
production-mode PostgreSQL and Redis after fresh and repeat migrations. It also
supplies Redis consumer overrides through path and query forms and a conflicting
scheduler path, and requires their rejection before locks, lease authority or child
launch, with credential values absent from output. Accepted startup checks both
catalog receivers in separate execution consumers and their generated identities.

The runner verifies the control and execution roles, peer/native admission before
business launch, their exact committed deployment tuple (overriding a bogus
inherited epoch), and the namespace/boot-qualified Redis consumer. It kills the
consumer and the scheduler separately to require whole-container draining, tests
TERM and forced database lease expiry, and confirms that a duplicate lease claim
exits with zero launch attempts. It checks that ownership remains reserved after
shutdown. It also seeds a real registration event before startup,
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
