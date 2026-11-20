# Scheduler Occurrence Qualification

## Persistence and dispatch

To check scheduler occurrence persistence, run:

```bash
bash scripts/test-functional-container.sh tests/Integration/SchedulerOccurrenceStoreTest.php tests/Integration/SchedulerOccurrenceExecutionStoreTest.php tests/Integration/SchedulerOccurrenceGuardTest.php tests/Integration/SchedulerOccurrenceDispatchStoreTest.php tests/Integration/SchedulerOccurrenceRelayTest.php tests/Integration/WorkerDeploymentLeaseSchemaTest.php
```

Persistence checks cover immutable snapshots, independent-connection visibility,
competing inserts, rollback and a lost commit acknowledgment. The actual migration
also enforces command/JSON bounds and minute-aligned instants; Doctrine's schema
filter preserves the tables.

Execution checks first require a committed attempt before the nested effect. They
verify the configured Kernel services, then force a failure after an effect through
actual Redis/Messenger retries. Redelivery must leave the
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

## Scheduled-job parameters

To check scheduled-job source parameters against the real ORM and PostgreSQL, run:

```bash
bash scripts/test-functional-container.sh tests/Integration/ScheduledJobParameterPersistenceTest.php
```

These checks verify the physical JSON column, fresh ORM reads, parameter order and
numeric representation before materializing an occurrence, plus the relevant schema
comparison. Native JSON preserves the encoded values; it does not solve stale
schedule writes or Doctrine's signed-zero-only dirty-checking limitation.

## Console execution and worker-stop policy

To check the occurrence console adapter and the worker-stop policy, run:

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
