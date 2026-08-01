# Task for worker

[Read from: /tmp/refactor-reference.md]

You are a delegated subagent running from a fork of the parent session. Treat the inherited conversation as reference-only context, not a live thread to continue. Do not continue or answer prior messages as if they are waiting for a reply. Your sole job is to execute the task below and return a focused result for that task using your tools.

Task:
Refactor 4 Radio entities to add @ManyToOne relations and indexes.

Read /tmp/refactor-reference.md for the established pattern.

## Entities (src/Radio/Infrastructure/Doctrine/Entity/):
Target entities: `App\Auth\Infrastructure\Doctrine\Entity\UserEntity`, `App\Radio\Infrastructure\Doctrine\Entity\RadioSourceEntity`, `App\Radio\Infrastructure\Doctrine\Entity\RadioStationEntity`.

1. **CountrySubscriptionEntity** — table `country_subscriptions`.
   - Add `UserEntity` ManyToOne on `user_id`, FK `fk_country_subscriptions_user_id`, CASCADE.
   - Add `RadioSourceEntity` ManyToOne on `source_id`, FK `country_subscriptions_source_id_fkey`, NO onDelete (restrict).

2. **RadioSessionEntity** — table `radio_sessions`.
   - Add `UserEntity` ManyToOne on `user_id`, FK `fk_radio_sessions_user_id`, CASCADE.
   - Add `RadioStationEntity` ManyToOne on `active_station_id`, FK `radio_sessions_active_station_id_fkey`, NO onDelete. May need nullable: true.

3. **RadioStationEntity** — table `radio_stations`.
   - Add `RadioSourceEntity` ManyToOne on `source_id`, FK `radio_stations_source_id_fkey`, NO onDelete.
   - Add `#[ORM\Index(name: 'idx_radio_stations_country', columns: ['country'])]`
   - Add `#[ORM\Index(name: 'idx_radio_stations_source_country', columns: ['source_id', 'country'])]`

4. **StarredStationEntity** — table `starred_stations`.
   - Add `UserEntity` ManyToOne on `user_id`, FK `fk_starred_stations_user_id`, CASCADE.
   - Add `RadioStationEntity` ManyToOne on `station_id`, FK `starred_stations_station_id_fkey`, NO onDelete.
   - Add `#[ORM\Index(name: 'idx_starred_stations_user', columns: ['user_id'])]`

## For each:
1. Replace `Uuid $xxxId` with entity relation + JoinColumn
2. Keep `getXxxId(): Uuid` accessor returning `$this->xxx->getId()`
3. Update repositories in src/Radio/Infrastructure/Doctrine/Repository/

## Verify:
- `docker compose exec -T app php bin/console cache:clear`
- `docker compose exec -T app php bin/console doctrine:schema:validate 2>&1 | grep -iE 'FAIL|Mapping'`

Do NOT create migrations. Do NOT touch other bounded contexts.
Working directory: /home/martin/dev/baander

---
**Output:**
Write your findings to exactly this path: /home/martin/dev/baander/.pi-subagents/artifacts/outputs/radio.md
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