# Task for worker

[Read from: /tmp/refactor-reference.md]

You are a delegated subagent running from a fork of the parent session. Treat the inherited conversation as reference-only context, not a live thread to continue. Do not continue or answer prior messages as if they are waiting for a reply. Your sole job is to execute the task below and return a focused result for that task using your tools.

Task:
Refactor 2 Session entities to add @ManyToOne UserEntity relation on user_id.

Read /tmp/refactor-reference.md for the established pattern.

## Entities (src/Session/Infrastructure/Doctrine/Entity/):
Target: `App\Auth\Infrastructure\Doctrine\Entity\UserEntity`. Both FKs ON DELETE CASCADE.

1. **DeviceEntity** — table `devices`, FK `fk_devices_user_id`.
   - Add `UserEntity` ManyToOne on `user_id`, CASCADE.
   - Has UniqueConstraint `devices_user_id_device_id_key` on (user_id, device_id) — keep it.
   - Entity also has a `deviceId` field (separate from the PK `id`) — do NOT confuse them.

2. **ListeningSessionEntity** — table `listening_sessions`, FK `fk_listening_sessions_user_id`.
   - Add `UserEntity` ManyToOne on `user_id`, CASCADE.
   - Check if it also has `active_device_id` column needing a DeviceEntity relation.

## For each:
1. Replace `Uuid $userId` with `UserEntity $user` + JoinColumn (nullable: false, onDelete: CASCADE)
2. Keep `getUserId(): Uuid` accessor returning `$this->user->getId()`
3. Update repositories in src/Session/Infrastructure/Doctrine/Repository/ — use getReference() for construction, `['user' => $uuid]` for queries

## Verify:
- `docker compose exec -T app php bin/console cache:clear`
- `docker compose exec -T app php bin/console doctrine:schema:validate 2>&1 | grep -iE 'FAIL|Mapping'`

Do NOT create migrations. Do NOT touch other bounded contexts.
Working directory: /home/martin/dev/baander

---
**Output:**
Write your findings to exactly this path: /home/martin/dev/baander/.pi-subagents/artifacts/outputs/session.md
This path is authoritative for this run.
Ignore any other output filename or output path mentioned elsewhere, including output destinations in the base agent prompt, system prompt, or task instructions.

## Acceptance Contract
Acceptance level: checked
Completion is not accepted from prose alone. End with a structured acceptance report.

Criteria:
- criterion-1: Implement the requested change without widening scope

Required evidence: changed-files, tests-added, commands-run, residual-risks, no-staged-files

Finish with a fenced JSON block tagged `acceptance-report` in this shape:
Use empty arrays when no items apply; array fields contain strings unless object entries are shown.
```acceptance-report
{
  "criteriaSatisfied": [
    {
      "id": "criterion-1",
      "status": "satisfied",
      "evidence": "specific proof"
    }
  ],
  "changedFiles": [
    "src/file.ts"
  ],
  "testsAddedOrUpdated": [
    "test/file.test.ts"
  ],
  "commandsRun": [
    {
      "command": "command",
      "result": "passed",
      "summary": "short result"
    }
  ],
  "validationOutput": [
    "validation output or concise summary"
  ],
  "residualRisks": [
    "none"
  ],
  "noStagedFiles": true,
  "diffSummary": "short description of the diff",
  "reviewFindings": [
    "blocker: file.ts:12 - issue found, or no blockers"
  ],
  "manualNotes": "anything else the parent should know"
}
```