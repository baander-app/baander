# Task for worker

[Read from: /tmp/refactor-reference.md]

You are a delegated subagent running from a fork of the parent session. Treat the inherited conversation as reference-only context, not a live thread to continue. Do not continue or answer prior messages as if they are waiting for a reply. Your sole job is to execute the task below and return a focused result for that task using your tools.

Task:
Refactor 3 Notification entities to add @ManyToOne relations.

Read /tmp/refactor-reference.md for the established pattern.

## Entities (src/Notification/Infrastructure/Doctrine/Entity/):

1. **NotificationEntity** — table `notifications`. Add `UserEntity` ManyToOne on `user_id`, FK `fk_notifications_user_id`, ON DELETE CASCADE. Target: `App\Auth\Infrastructure\Doctrine\Entity\UserEntity`. Entity already has indexes `idx_notifications_user_created` and `idx_notifications_user_read` — keep them.
2. **NotificationPreferenceEntity** — table `notification_preferences`. Add `UserEntity` ManyToOne on `user_id`, FK `fk_notification_preferences_user_id`, CASCADE. Has UniqueConstraint `notification_preferences_user_id_key`.
3. **PushSubscriptionEntity** — table `push_subscriptions`. Add `UserEntity` ManyToOne on `user_id`, FK constraint name in DB is `_fkpush_subscriptions_user_id` (leading underscore!), CASCADE.
4. **WebhookDeliveryLogEntity** — table `webhook_delivery_logs`. Add `WebhookEntity` ManyToOne on `webhook_id`, FK `fk_2afcb9d15c9ba60b`, CASCADE. Target: `App\Notification\Infrastructure\Doctrine\Entity\WebhookEntity`.

## For each:
1. Replace `Uuid $xxxId` field with entity relation + JoinColumn
2. Keep `getXxxId(): Uuid` accessor returning `$this->xxx->getId()`
3. Update repositories in src/Notification/Infrastructure/Doctrine/Repository/ — use `getReference()` for construction, `['xxx' => $uuid]` for queries

## Verify:
- `docker compose exec -T app php bin/console cache:clear` (no errors)
- `docker compose exec -T app php bin/console doctrine:schema:validate 2>&1 | grep -iE 'FAIL|Mapping'` (mapping OK)

Do NOT create migrations. Do NOT touch other bounded contexts.
Working directory: /home/martin/dev/baander

---
**Output:**
Write your findings to exactly this path: /home/martin/dev/baander/.pi-subagents/artifacts/outputs/notif.md
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