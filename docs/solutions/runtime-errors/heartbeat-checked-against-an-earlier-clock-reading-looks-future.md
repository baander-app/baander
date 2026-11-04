---
title: A heartbeat checked against a clock read before it looks as if it came from the future
date: 2026-11-04
category: runtime-errors
module: Shared worker infrastructure (worker peer handshake and admission)
problem_type: runtime_error
component: background_job
symptoms:
  - "About 140 s into the 8 video + 12 audio load proof, the control unit's supervisor stopped with reason management_failed, source WorkerPeerHandshake.php, and the execution unit followed"
  - "Containment failed every running audio rendition and stopped the native daemon; the load proof receipt failed on the contained units"
  - "Unit samplers showed both peer heartbeats at most 0.02 s old up to the failure, neither unit draining and the lease epoch unchanged"
  - "The samplers read heartbeats up to 0.14 s newer than the clock reading taken at the start of the same sample"
root_cause: async_timing
resolution_type: code_fix
severity: high
tags: [worker, heartbeat, monotonic-clock, clock-skew, future-timestamp, management-failed, containment, load-proof]
---

# A heartbeat checked against a clock read before it looks as if it came from the future

## Problem

The worker supervisor compares its peer's heartbeat time with a monotonic clock reading it took earlier in the same tick. Under heavy media load, the gap between that reading and the file read grew past the 0.25 s allowance for a heartbeat ahead of the reader's clock. The peer's fresh heartbeat was then rejected as a future one, and the rejection drained the whole worker deployment pair.

## Symptoms

- In the software load proof (8 video and 12 audio streams, the default slots), the control unit's supervisor stopped about 140 s into the run. Its recorded cause was `management_failed` with the source `WorkerPeerHandshake.php` and the handshake's exception, `Worker peer identity, authority or heartbeat lost.` (`src/Shared/Infrastructure/Worker/WorkerPeerHandshake.php:89`). The execution unit followed.
- Containment failed every running audio rendition and stopped the native daemon.
- Up to the failure, both units' samplers saw every peer heartbeat at most 0.02 s old. Neither unit was draining and the lease epoch never changed.

## What Didn't Work

- **Reading the failure as a lost peer.** The exception message names identity, authority and heartbeat loss together, and the check behind it has many conditions (`WorkerPeerHandshake.php:82-88`). The sampler evidence ruled most of them out: the heartbeats were fresh, the epoch was unchanged, no unit was draining, and the namespace, boot identity and deployment profile stay fixed for the life of a boot. The only condition left was the upper bound, `$value['time'] > $now + 0.25`, a heartbeat from the future.
- **An earlier run that left nothing to diagnose.** A drill run on this branch had already lost its deployment under load with no recorded cause. That run is the reason the supervisor now records the first drain cause, with the exception class and source location (commit 6e4259aa8, "fix(worker): record why a unit drained and keep kill-order samples small (U5)"; `WorkerSupervisorRunner.php:154`). This failure was diagnosable only because of that record. By this session's account, that earlier loss came at about 185 s into the run. It was plausibly the same defect, but that is not proven: the run recorded no cause.

## Solution

The supervisor's admission gate takes its clock reading first, then may initialize the admission ledger, sample and publish resources, start or poll the native daemon (execution only), publish its own heartbeat, and only then read the peer's (`src/Shared/Infrastructure/Worker/WorkerSupervisorRunner.php:92-126`). Every one of those steps can stall under CPU contention or a slow filesystem. Containers carry no CPU limits and the native daemon starts media commands at niceness 10 (`NativeExecutionDaemon::COMMAND_NICENESS`), so stalls of a few tenths of a second happen at full load. The samplers themselves read heartbeats up to 0.14 s newer than the clock reading they took at the start of the same sample.

The fix (commit 6b64ff013, "fix(worker): judge peer heartbeats against a clock read after the heartbeat (U10)", on `feat/configurable-media-capacity`; local and unmerged as of 2026-11-04, so search by subject if the SHA changes on merge) gives `WorkerPeerHandshake` an optional clock and reads it again after the heartbeat file is read:

```php
// WorkerPeerHandshake::ready(), after the heartbeat file is read
$now = $this->clock === null ? $now : max($now, ($this->clock)());
```

`WorkerSupervisorRunner` passes the host's monotonic clock, the same clock the caller's `$now` came from (`WorkerSupervisorRunner.php:62-63`):

```php
$peer = new WorkerPeerHandshake(/* ... */, $configuration->deploymentProfile,
    static fn (): float => hrtime(true) / 1e9);
```

A heartbeat ahead of the later reading is still rejected, and a heartbeat two seconds old still fails as stale. In `tests/Unit/Shared/Infrastructure/Worker/WorkerPeerHandshakeTest.php`, `testHeartbeatPublishedWhileTheObserverStalledIsFresh` covers a heartbeat published during the stall (accepted) and one ahead of the later reading (rejected). `testStaleObservationWithoutAClockRejectsAFresherHeartbeat` shows that a reader with no clock still rejects the fresh heartbeat, which is the failure the fix addresses. The existing `testPeerRequiresMatchingEpochAndLiveHeartbeat` keeps the two-second staleness case. After the fix, the 8 + 12 load proof passed with no containment.

## Why This Works

A check of the form "an observed timestamp must not exceed now plus a skew allowance" is only sound when "now" was read after the observation. If "now" was read before it, any delay in between lets the writer publish a legitimate timestamp later than "now", and the check reads that as a clock fault. The allowance then has to cover the reader's worst stall, not clock skew. Both units read the same host monotonic clock, so the only real skew is the delay between the two reads.

Taking `max($now, clock())` after the file read restores the intended meaning. A real future timestamp still fails against the later reading, and the reading cannot move backwards because the clock is monotonic. Judging staleness against the later reading is also correct, because it measures the heartbeat's true age at the moment it is used.

## Prevention

- **Read "now" after the observation it bounds, or bound with a second reading taken after it.** Do this whenever a check compares a timestamp written by another process with a clock reading. A caller-supplied `$now` is stale by the time a later step reads a file, takes a lock or waits on a socket.
- **Know where the same pattern still lives.** Every other `$now + 0.25` bound in `src/` is in the autoscaling code and judges a timestamp against a `$now` read earlier. At the time of writing each of them fails soft, but none is free:

  | Site | Bound on | What a false "future" reading does |
  |---|---|---|
  | `AdmissionLedger.php:96` (`tryAcquire`) | The controller's last decision time | Parks the slot and returns `false`. `OrdinaryAdmissionTransport::get()` returns no message for that poll. `$now` is read before waiting for the admission lock (at most 50 ms), so lock contention widens the gap. |
  | `AdmissionLedger.php:421` (`peersReady`) | Both units' heartbeats | Returns `false`, so the slot parks for that poll. A foreign heartbeat identity still throws. `peersReady()` also backs `authorityValid()` (`AdmissionLedger.php:163-167`). In `AutoscalingRunner::tick` a false result makes the policy degrade the target to zero, `authority_unavailable` (`AutoscalingPolicy.php:42-43`), and `slotSummary()` counts no slot as known; `AutoscalingStatus::snapshot()` reports `unavailable`. |
  | `ResourceChannel.php:69-70` (`read`) | The resource sample time | Throws `Future resource sample.` inside a `try`; the `catch` at line 73 returns an `unavailable` observation with the fault `cgroup_unavailable`. |
  | `AutoscalingPolicy.php:149` (`fresh`) and `:195` (evidence ages) | Queue, resource and pool sample times | `:195` only reports the age as null in the decision evidence. A sample that fails `fresh()` skips the severe-pressure check and fails `validEvidence()`. The policy then degrades the ordinary admission target to at most one, `measurements_unavailable`, and resets its recovery count. It needs two new valid samples before it scales again (`AutoscalingPolicy.php:50-61`). An unavailable resource reading from `ResourceChannel` takes the same path. |
  | `AutoscalingStatus.php:31` (`snapshot`) and `:54` (`slotSummary`) | The controller's decision time and each slot's last-seen time | `snapshot()`, used by `bin/worker-autoscaling-status.php`, reports the state as `unavailable`. `slotSummary()` also feeds the autoscaler's pool observation (`AutoscalingRunner.php:82-83`). A free slot seen "in the future" counts as disconnected, not known, so the known slot count falls short and the policy degrades the same way. |

  So a stall in these readers costs a poll, or a temporary drop of the ordinary admission target to one, or to zero when the autoscaler's peer check misreads a heartbeat. It does not cost a deployment. A change that makes any of them throw out of the supervisor or the autoscaler loop, or treats a future timestamp as fatal, brings back the outage. Apply the read-after rule before tightening any of these checks.
- **A unit's own heartbeat carries the stale reading too.** The admission gate publishes its heartbeat with the same early `$now` (`WorkerSupervisorRunner.php:125`). The heartbeat therefore looks older to the peer by the length of the stall, which uses up part of the peer's two-second staleness budget. This is safe while stalls stay well under two seconds. Keep it in mind if the staleness bound shrinks.
- **Test the stall, not just the bound.** The handshake test injects a clock that moves between the caller's reading and the read inside `ready()`. A reader without a clock injection point cannot express that case. Give any reader that gets the same fix an injectable clock and the same pair of tests: a heartbeat published during the stall is fresh, and one ahead of the later reading is still rejected.
- **Exercise the bounds under real load.** Before the fix, the unit suite had no case with a stall between the two reads. Only the 8 + 12 load proof, with encoders saturating the host, produced a stall long enough to trip the bound (see the load proof in `docs-book/part-2-developer-guide/testing.md`).

## Related Issues

- [Work queued on a Swoole task must expire](../architecture-patterns/state-set-when-work-is-queued-on-swoole-task-must-expire.md): a different clock lesson. Lease expiry there is measured on one shared database clock so every process agrees.
- [Health checks run in a Messenger consumer cannot see outages that stop it](../architecture-patterns/health-checks-run-in-a-messenger-consumer-cannot-see-outages-that-stop-it.md): the consumer heartbeat, a separate heartbeat with past-staleness bounds only.
- [Background work, operator guide](../../../docs-book/part-1-operator-guide/background-work.md): a peer failure starts a worker drain.
- [Load proof at the default slots](../../../docs-book/part-2-developer-guide/testing.md#load-proof-at-the-default-slots): the run that exposed the defect.
