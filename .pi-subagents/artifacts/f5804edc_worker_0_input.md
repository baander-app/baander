# Task for worker

[Read from: /tmp/refactor-reference.md]

You are a delegated subagent running from a fork of the parent session. Treat the inherited conversation as reference-only context, not a live thread to continue. Do not continue or answer prior messages as if they are waiting for a reply. Your sole job is to execute the task below and return a focused result for that task using your tools.

Task:
Refactor 7 UserPreference entities to add @ManyToOne UserEntity relation on user_id.

Read /tmp/refactor-reference.md for the established pattern (LyricsEntity is the reference implementation already done).

## Entities to refactor (all in src/UserPreference/Infrastructure/Doctrine/Entity/):
All FKs: `user_id` → `App\Auth\Infrastructure\Doctrine\Entity\UserEntity`, constraint ON DELETE CASCADE.

1. **AudioPreferencesEntity** — table `audio_preferences`, FK `fk_audio_preferences_user_id`. Has UniqueConstraint `audio_preferences_user_id_key` on user_id (so NO separate Index needed — redundant).
2. **EqDeviceProfileEntity** — table `eq_device_profiles`, FK `fk_eq_device_profiles_user_id`. Has UniqueConstraint `eq_device_profiles_user_id_key`.
3. **LayoutPreferencesEntity** — table `layout_preferences`, FK `fk_layout_preferences_user_id`. Has UniqueConstraint `layout_preferences_user_id_key`.
4. **PlayerPreferencesEntity** — table `player_preferences`, FK `fk_player_preferences_user_id`. Has UniqueConstraint `player_preferences_user_id_key`.
5. **PreferenceHistoryEntity** — table `preference_history`, FK `fk_preference_history_user_id`. NO unique constraint on user_id.
6. **SidebarConfigEntity** — table `user_sidebar_configs`, FK `fk_user_sidebar_configs_user_id`. Has UniqueConstraint `user_sidebar_configs_user_id_key`.
7. **UserAccentColorEntity** — table `user_accent_colors`, FK `fk_user_accent_colors_user_id`. Has UniqueConstraint `user_accent_colors_user_id_key`.

## For each entity:
1. Replace `private Uuid $userId` (or promoted constructor param) with `private UserEntity $user` + `#[ORM\ManyToOne(targetEntity: UserEntity::class)]` + `#[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]`
2. Keep a `getUserId(): Uuid` accessor returning `$this->user->getId()` (domain code depends on it)
3. Add/remove `setUser(UserEntity $user)` or adjust constructor as needed
4. Update ALL repositories in src/UserPreference/Infrastructure/Doctrine/Repository/ to use `getReference(UserEntity::class, $uuid)` when constructing entities and `['user' => $uuid]` in queries

## Important:
- Read each entity FULLY before editing. Some use constructor property promotion.
- Run `docker compose exec -T app php bin/console cache:clear` after ALL edits to verify no PHP errors.
- Run `docker compose exec -T app php bin/console doctrine:schema:validate 2>&1 | grep -iE 'FAIL|Mapping'` to verify mapping is OK.
- Do NOT create migrations. Do NOT touch other bounded contexts.

Working directory: /home/martin/dev/baander

---
**Output:**
Write your findings to exactly this path: /home/martin/dev/baander/.pi-subagents/artifacts/outputs/userpref.md
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