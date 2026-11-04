---
title: A fenced retry that repeats completion's one-shot steps fails a finished transcode
date: 2026-11-04
category: runtime-errors
module: Transcode video execution (Swoole session subscriber)
problem_type: runtime_error
component: background_job
symptoms:
  - "Under the 8 video + 12 audio load proof, job 01a1a5b5-732a produced 127.97 s of output, the full 128 s source, and was then marked failed"
  - "The worker stopped the stream and probed the output for completion; 0.4 s later the job failed with 'Encoder exited before publishing complete playable tracks.'"
  - "The client was reporting playback progress every two seconds while the job completed; the other seven video jobs in the run completed"
root_cause: concurrency
resolution_type: code_fix
severity: high
tags: [transcode, swoole, fencing, control-revision, retry, idempotency, completion, load-proof]
---

# A fenced retry that repeats completion's one-shot steps fails a finished transcode

## Problem

When a video encode finishes, the worker validates the publication, stops the stream, seals the generation and then writes the job's completion under a fence on the job's control revision. A playback progress report that commits a new revision during that window refuses the write, and the worker retries. Before the fix, the retry ran the whole completion again, including the validation, and that validation fails once the stream has been stopped. A video that had encoded completely was recorded as failed.

## Symptoms

- In the software load proof (8 video and 12 audio streams), job `01a1a5b5-732a` encoded its whole 128-second excerpt. Its cost sample recorded 127.97 output seconds.
- The worker stopped the stream and probed the output for completion. About 0.4 s later the job failed with `Encoder exited before publishing complete playable tracks.` (`src/Transcode/Infrastructure/Swoole/TranscodeSessionSubscriber.php:324`).
- The client reported progress every two seconds. Seven other video jobs in the same run completed.

Real players report progress every few seconds, so this hits ordinary playback of any video that finishes encoding while it is watched, not only the load proof.

## What Didn't Work

- **Taking the error message at its word.** The message says the encoder exited before it published complete tracks, which reads as a crashed or truncated encode. The cost sample contradicted that: the output covered the full source. The encode had succeeded, and the error came from a second look at a publication the worker had already consumed.

## Solution

Four pieces of the current tree explain the failure:

- A progress report is a control with no seek. When it moves the desired position, it commits a new revision (`desired_revision = desired_revision + 1`) without a new generation, which needs a seek or a profile change. A control that leaves the desired state unchanged commits nothing (`src/Transcode/Infrastructure/Doctrine/TranscodeRuntimeState.php:89-90`, `:95`, `:102`; a `progress` action reaches `control()` with `seek: false`, `src/Transcode/Application/CommandHandler/UpdateTranscodePositionHandler.php:23-24`, `src/Transcode/Application/Service/TranscodePlaybackControl.php:54`).
- Every worker write goes through `persist()`, which runs only when the claim still matches the row's owner, generation **and** desired revision (`TranscodeRuntimeState.php:245-248`, `withOwnership` at `:182`). A refusal throws `TranscodeStreamReplaced` (`TranscodeSessionSubscriber.php:503-514`).
- The execution loop catches `TranscodeStreamReplaced` and continues from the top with a refreshed claim (`TranscodeSessionSubscriber.php:354`). That `continue` is the retry. There is no separate retry loop.
- Each pass reads the publication again from the stream manager (`TranscodeSessionSubscriber.php:267`). After `stopStream()` the stream entry is gone, so `publication()` returns `null` (`src/Transcode/Infrastructure/Swoole/TranscodeStreamManager.php:247`, `:367-370`).

Before the fix, the finished branch ran every step on every pass:

```php
if (!$ready || $publication === null || !$publication->complete || $videoTrack === null
    || ($hasAudio && $publication->track(MediaTrack::Audio) === null)) {
    throw new RuntimeException('Encoder exited before publishing complete playable tracks.');
}
$this->assertCurrent($active, $local, $stopRequested);
$this->streamManager->stopStream($jobId);
// with a presentation selection, probe the output and publish its metadata ...
$this->sealCompletedGeneration($output);
$this->persist($active, static function (TranscodeJob $fresh) use ($videoTrack): void {
    $fresh->setTotalSegments(count($videoTrack->segments));
    // markCompleted() ...
});
```

On the first pass the checks passed, the stream stopped, the output probe ran and the generation was sealed. A progress report committed during the probe or the seal, so `persist()` was refused. On the retry `$publication` was `null`, and the check threw.

The fix (commit 7ab7cb2b1, "fix(transcode): complete a sealed generation when a control revision races its completion (U10)", on `feat/configurable-media-capacity`; local and unmerged as of 2026-11-04, so search by subject if the SHA changes on merge) runs the one-shot steps once and records the result:

```php
if ($sealedSegments === null) {
    // validate the publication, assertCurrent, stopStream, probe and publish metadata
    $this->sealCompletedGeneration($output);
    $sealedSegments = count($videoTrack->segments);
}
$this->persist($active, static function (TranscodeJob $fresh) use ($sealedSegments): void {
    $fresh->setTotalSegments($sealedSegments);
    if ($fresh->getStatus() === TranscodeStatus::InProgress) {
        $fresh->markCompleted();
    }
});
if ($this->ownership->observe($active, 'complete', $output)) {
    return;
}
continue;
```

(`TranscodeSessionSubscriber.php:321-345`.) `$sealedSegments` starts as `null` (`:172`) and is reset when a new generation starts (`:238`). A retry now repeats only the persist and the `complete` observation under the refreshed claim. The persist is safe to repeat: it loads a fresh job, sets an absolute segment count, and calls `markCompleted()` only while the job is still in progress, because a second call would throw (`src/Transcode/Domain/Model/TranscodeJob.php:192-196`).

`testControlRevisionDuringCompletionStillCompletesTheSealedGeneration` in `tests/Unit/Transcode/Infrastructure/Swoole/TranscodeWorkerExecutionTest.php` commits a new revision at the fence once `generation.json` is sealed. It asserts that no `failed` outcome is observed, that `complete` is the last one, and that the job ends `Completed`. It failed before the fix and passes after it. After the fix, the 8 + 12 load proof completed every job.

## Why This Works

The revision fence is optimistic concurrency. A refused write means that another writer committed first, not that the worker lost the job (losing the job is a separate failure, `TranscodeLoopOwnershipLost`). The fence protects the job record, so the right answer to a refusal is to take the new revision and write again. Only the write needs to repeat.

The completion's other steps change state outside the fenced row. Stopping the stream removes the publication, sealing marks `generation.json` complete, and the probe publishes metadata. None of them is undone by a refused write, and the validation reads the very state the stop removed. Repeating them turns a harmless retry into a failure. Recording "sealed, with this many segments" lets the retry skip work that already happened and keep the one value it needs from the publication that is now gone.

## Prevention

- **In a loop guarded by a revision fence that other writers bump often, run each one-shot step once and record that it ran; retry only the idempotent persist.** One-shot steps include stopping a stream, sealing output and publishing metadata. A retry must not validate state that the first attempt consumed. Keep what the persist needs, such as a count, in a variable set by the one-shot step, not read again from the consumed source.
- **Expect the fence to fail during normal use.** A progress report commits a revision every few seconds while a client plays, so a fenced write that spans a slow step (an output probe, a helper round trip) will sometimes be refused. A unit test that never moves the revision mid-step does not exercise this.
- **Test the race at the fence.** The test injects a new claim from the fence hook after the side effect it races with, here once `generation.json` reads `complete: true`. Do the same for any new fenced step: move the revision at the fence after the one-shot work, then assert the outcome the user expects.

The other fenced `persist(...)` sites in `TranscodeSessionSubscriber::run()` were read at the current tree. None repeats a one-shot step in a way that breaks the next attempt:

| Site | What a refused write repeats | Verdict |
|---|---|---|
| Generation start (`:190-242`): `persist` at `:215`, `assertCurrent` at `:233` and `:235` around `startStream` | The whole block, because `$generation` is set only at `:236`. The block first stops any stream and shuts the helper pool (`:191-193`), then rebuilds output metadata, job state and the start position from the refreshed claim. | Safe. Each repeat undoes the previous attempt before redoing it. A revision committed just after `startStream` costs one encoder restart, not a failure. No test covers a revision at this fence. |
| Segment progress (`:279-297`) | The newly published segments, recomputed from the live publication minus `$seen`. `$seen` is updated only after `persist` returns (`:296`). | Safe: record-after-success. `markSegmentCompleted` writes by index (`TranscodeJob.php:176-190`). Covered by `testControlRevisionRaceDuringProgressReconcilesWithoutLosingSegments` (test line 618). |
| Readiness (`:299-312`): `observe('ready')`, then `persistSessionReady` | A refusal leaves `$appliedRevision` behind the new revision, so the next pass observes `ready` and persists the session again. The pause is guarded by `$paused`. `persistSessionReady` changes the session only from pending or preparing (`:517-537`). | Safe: every repeated step is idempotent. |
| `observe('complete')` returns false (`:342-345`) | The completion persist, now guarded by `$sealedSegments`. | Safe after the fix. |
| Failure path (`:360-371`) | Nothing. The `markFailed` persist runs once, and a refusal (`TranscodeStreamReplaced`) or lost ownership is swallowed. | Not a retry loop. A progress report racing this write leaves the failure unrecorded by this path. What happens to such a job afterwards was not examined. |

The loop that waits for the `accepted` observation (`:106-118`) observes only and has no side effects before it.

## Related Issues

- [A heartbeat checked against a clock read before it looks as if it came from the future](heartbeat-checked-against-an-earlier-clock-reading-looks-future.md): another defect the same load proof found on the same branch, with a different mechanism.
- [Work queued on a Swoole task must expire](../architecture-patterns/state-set-when-work-is-queued-on-swoole-task-must-expire.md): claim fencing from the holder's side, where a refused write means the claim was taken over. Here the fence also refuses writes for an ordinary concurrent revision.
- [Load proof at the default slots](../../../docs-book/part-2-developer-guide/testing.md#load-proof-at-the-default-slots): the run that exposed the defect.
