# Task for worker

[Read from: /tmp/refactor-reference.md]

You are a delegated subagent running from a fork of the parent session. Treat the inherited conversation as reference-only context, not a live thread to continue. Do not continue or answer prior messages as if they are waiting for a reply. Your sole job is to execute the task below and return a focused result for that task using your tools.

Task:
Refactor 3 Party entities to add @ManyToOne relations and indexes.

Read /tmp/refactor-reference.md for the established pattern.

## Entities (src/Party/Infrastructure/Doctrine/Entity/):

1. **PartyMemberEntity** — table `party_members`.
   - Add `UserEntity` ManyToOne on `user_id`, FK `fk_party_members_user_id`, CASCADE. Target: `App\Auth\Infrastructure\Doctrine\Entity\UserEntity`.
   - Add `SyncedPartySessionEntity` ManyToOne on `session_id`, CASCADE. Target: `App\Party\Infrastructure\Doctrine\Entity\SyncedPartySessionEntity`.
   - Keep UniqueConstraints `party_members_public_id_key` and `party_members_user_id_session_id_key`.

2. **SyncedPartySessionEntity** — table `party_sessions`.
   - Add `UserEntity` ManyToOne on `host_user_id`, FK `fk_party_sessions_host_user_id`, CASCADE.
   - Entity already has VideoEntity and TranscodeJobEntity relations — check those exist.
   - Add `#[ORM\Index(name: 'idx_party_sessions_is_active', columns: ['is_active'])]`
   - Add `#[ORM\Index(name: 'idx_party_sessions_video_id', columns: ['video_id'])]`

3. **PartyEventEntity** — table `party_events`. Just add indexes (check if ManyToOne needed):
   - `#[ORM\Index(name: 'idx_party_events_session_id', columns: ['session_id'])]`
   - `#[ORM\Index(name: 'idx_party_events_occurred_at', columns: ['occurred_at'])]`

## For relation entities:
1. Replace `Uuid $xxxId` with entity relation + JoinColumn (nullable: false, onDelete: CASCADE)
2. Keep `getXxxId(): Uuid` accessor
3. Update repositories in src/Party/Infrastructure/Doctrine/Repository/

## Verify:
- `docker compose exec -T app php bin/console cache:clear`
- `docker compose exec -T app php bin/console doctrine:schema:validate 2>&1 | grep -iE 'FAIL|Mapping'`

Do NOT create migrations. Do NOT touch other bounded contexts.
Working directory: /home/martin/dev/baander

---
**Output:**
Write your findings to exactly this path: /home/martin/dev/baander/.pi-subagents/artifacts/outputs/party.md
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