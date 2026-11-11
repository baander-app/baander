---
title: Doctrine refuses a flush after the database cascaded a delete under managed children
date: 2026-11-11
last_updated: 2026-11-11
category: runtime-errors
module: Transcode job cleanup (TranscodeJobRepository, StaleTranscodeSessionExpiry)
problem_type: runtime_error
component: background_job
related_components: [database]
symptoms:
  - "app:transcode:job:cleanup expires stale sessions and then fails with ORMInvalidArgumentException: A new entity was found through the relationship 'TranscodeSessionEntity#job' that was not configured to cascade persist operations"
  - "A second run of the same command succeeds and removes the jobs"
  - "The first job of a run is removed; the failure comes with the second"
  - "With two cleanup runs at once, one run reports 'removed 2 orphaned jobs and could not remove 1' for a job nothing was wrong with"
root_cause: wrong_api
resolution_type: code_fix
severity: medium
framework_version: doctrine orm 3.7.6
tags: [doctrine, unit-of-work, identity-map, on-delete-cascade, flush, transcode, cleanup, detach]
---

# Doctrine refuses a flush after the database cascaded a delete under managed children

## Problem

The first job cleanup on the persistent instance after the movie playback merge expired
eight stale sessions and then stopped with:

```text
A new entity was found through the relationship 'TranscodeSessionEntity#job' that was
not configured to cascade persist operations
```

Nothing in a cleanup creates an entity, so the message points the wrong way. A second
run removed seven jobs without complaint.

The cause is a unit of work that still manages child entities after the database has
deleted their rows. `TranscodeSessionEntity::$job` is a to-one association whose column
carries `onDelete: 'CASCADE'`, so PostgreSQL deletes a job's sessions with the job. The
ORM is not told. The fix is pending on the branch `playback-session-entities` and is not
merged yet.

## Symptoms

- The cleanup fails only when it both expires sessions and removes more than one job in
  the same process.
- The first job goes; the flush for the second job throws.
- The next run succeeds, because the sessions are expired by then and nothing loads them.
- A test that removes a single job, or that removes jobs without loading their sessions
  first, passes.

## What Didn't Work

- **Reading the message literally.** It suggests a missing `cascade: ['persist']` or an
  entity constructed and never persisted. Neither exists here. The "new" entity is a job
  the same unit of work removed one flush earlier.
- **Removing the children found by a query.** The first fix had
  `TranscodeJobRepository::delete()` call `findBy(['job' => $entity])` and remove those
  sessions in the job's flush. That closed the single-process case. Code review then
  pointed out that a query returns only rows that still exist. When another process (the
  admin endpoint, the console command and the scheduler can each start a cleanup) has
  already deleted a session row that this unit of work still manages, `findBy` does not
  return it and the refusal comes back one job later. A test with a second connection
  reproduced it in both variants, the session row deleted and the whole job deleted, with
  "removed 2 orphaned jobs and could not remove 1".

## Solution

The sequence that fails:

1. `StaleTranscodeSessionExpiry::expire()` reads every active session through the
   repository, which leaves the session entities managed.
2. Each expiry and each job removal runs in its own `DoctrineTransaction::run`. That port
   flushes on success and clears the entity manager only on failure, so the sessions stay
   managed through the whole removal loop.
3. The first job is removed and flushed. The database deletes the job's session rows by
   cascade. In the unit of work the job is no longer managed, and its sessions still are,
   each still pointing at it.
4. The flush for the second job computes change sets for every managed entity. It walks a
   session's `job` association, finds an entity it does not manage, and refuses it as new.

Three conditions have to meet in one entity-manager lifetime: children loaded and still
managed, the parent leaving the unit of work, and a later flush that has something to
write. Reading `UnitOfWork` in Doctrine ORM 3.7.6 narrows each of them:

- A flush with nothing scheduled returns before the check that throws, so a port's closing
  flush after a repository has already flushed is harmless.
- A bulk DQL or SQL `DELETE` of the parent does not raise this error. The parent object
  stays in the unit of work's state map, so the association walk still sees it as managed.
  The error needs `remove` with a flush, a `detach`, or a partial clear.
- An uninitialized lazy object is skipped, both as an entity and as an association value.

`delete()` now lets go of the job's sessions that the unit of work itself holds, read from
the identity map and not from the database:

```php
$unitOfWork = $this->entityManager->getUnitOfWork();
foreach ($unitOfWork->getIdentityMap()[TranscodeSessionEntity::class] ?? [] as $session) {
    if ($session instanceof TranscodeSessionEntity && !$unitOfWork->isUninitializedObject($session) && $session->getJob() === $entity) {
        $this->entityManager->detach($session);
    }
}
$this->entityManager->remove($entity);
$this->entityManager->flush();
```

The database cascade still deletes the rows. Around it, the cleanup removes each job in
its own transaction, logs a failure with the job and the step, and ends a run that left
jobs behind with `TranscodeCleanupIncompleteException`: exit 1 on the console, 503 with
the expired, cleaned and failed counts from the admin endpoint.

Verified at the fix: `tests/Integration/TranscodeCleanupOverlapTest.php` fails against the
query-based code in both variants and passes now; a test that runs the real command over
eight stale sessions, two with a live lease, sees eight expire, six jobs go and two stay;
the Transcode unit suite with the Shared exception-subscriber and console tests passes
1,591 tests and thirteen PostgreSQL integration files pass 96.

## Why This Works

The refusal is about the unit of work's own bookkeeping, so the remedy has to read that
bookkeeping. The identity map lists exactly the entities a later flush will walk, whether
or not their rows still exist. A query answers a different question: which rows exist
now. The two agree only while this process is the only writer.

Detaching is enough because the database does the deleting. Removing the sessions through
the ORM as well would issue a DELETE per session for rows the cascade is about to take.

## Prevention

- When a table deletes rows by `ON DELETE CASCADE` and the unit of work may hold the
  children, release them from the identity map before the parent's flush. Do not look
  them up with a query.
- The failure needs managed children and a second flush. Test a per-item removal loop
  with at least two parents, after the code path that loads the children, and once with
  a second connection deleting a child row in between.
- `DoctrineTransaction::run` clears the entity manager on failure only. Entities loaded
  before a loop of successful per-item transactions stay managed across all of them.
- "A new entity was found through the relationship" in code that creates nothing means an
  entity left the unit of work while something managed still points at it. Look for a
  removal, a `detach` or a `clear` earlier in the same process before looking for a
  missing cascade.
- Detaching a child is only safe when nothing managed points at that child. A detached
  entity counts as new, so detaching a song while one of its credit, genre-link or activity
  entities is managed moves the same refusal one level down. `TranscodeSessionEntity` is
  the target of no association, which is why detaching is enough here.
- An audit on 2026-11-11 read all 51 to-one join columns with `onDelete` (39 `CASCADE`,
  12 `SET NULL`, in 33 entity files) and every ORM removal of their parents. None meets the
  three conditions today. The closest is `PruneMissingImagesHandler`, which removes images
  in a loop with a flush each: it is safe only because the owning albums and artists are
  uninitialized references at that point. `ROADMAP.md` lists the three places that are one
  change away.

## Related

- `docs/solutions/runtime-errors/fenced-completion-retry-must-not-redo-one-shot-steps.md`:
  another transcode step that had to tolerate work already done by someone else.
- `docs/solutions/runtime-errors/console-json-output-needs-real-logging-handlers.md`: the
  cleanup command's `--json` output is tested with real log handlers for this reason.
