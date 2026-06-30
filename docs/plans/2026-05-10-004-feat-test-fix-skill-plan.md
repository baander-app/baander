---
title: "feat: Test & Fix Skill"
type: feat
status: active
date: 2026-05-10
origin: docs/brainstorms/test-fix-skill-requirements.md
---

# Test & Fix Skill

## Summary

Create a `/test-fix` Claude skill that runs the full PHPUnit suite in the Docker container, analyzes failures, fixes high-confidence issues automatically (asking on ambiguous ones), re-runs the full suite to verify, then runs PHPStan on changed files with the same fix-and-verify loop. Both passes cap at 3 iterations.

---

## Requirements

- R1. Run full PHPUnit suite without coverage flags
- R2. Parse failures and classify as high-confidence or ambiguous
- R3. Auto-fix high-confidence failures without confirmation
- R4. Present diagnosis and options for ambiguous failures
- R5. Re-run full suite after each fix round to catch regressions
- R6. Cap PHPUnit fix loop at 3 iterations
- R7. After PHPUnit passes (or exhausts retries), run PHPStan on changed files
- R8. Apply same autonomy model to PHPStan findings (auto-fix confident, ask on ambiguous)
- R9. Re-run PHPStan after fixes to verify, cap at 3 iterations
- R10. Produce a summary report of what was fixed, what remains, and what was uncertain

---

## Scope Boundaries

- No coverage analysis or reporting
- No filter-based or targeted test runs
- No test generation (that's `/test-scaffold`)
- No fixing integration tests that need database/service orchestration beyond container setup

---

## Key Technical Decisions

- **PHPUnit invocation**: `docker compose exec -e XDEBUG_MODE=off -T app php vendor/bin/phpunit` — matches CI pattern, disables xdebug for speed
- **PHPStan scope**: Run on files changed during fix iterations (via `git diff --name-only`), not full `src/` + `tests/`
- **Skill format**: Single SKILL.md file following the project's established skill pattern (frontmatter description, Input section, numbered Steps, Conventions)
- **High-confidence classification**: Type mismatches, wrong constructor args, missing imports, wrong method signatures — errors where the message directly maps to a single unambiguous fix

---

## Implementation Units

### U1. Create the test-fix skill

**Goal:** Implement the complete `/test-fix` skill as a single SKILL.md file.

**Requirements:** R1–R10

**Dependencies:** None

**Files:**
- Create: `.claude/skills/test-fix/SKILL.md`

**Approach:**

The skill is structured as a procedural guide for the agent, following the pattern from `.claude/skills/test-scaffold/SKILL.md` and `.claude/skills/dddlint/SKILL.md`. Steps are numbered and each tells the agent what to do, not how to do it at the code level.

Steps in the skill:

1. **Run PHPUnit** — Execute the full suite via Docker without coverage. Capture the output.
2. **Analyze failures** — If all tests pass, skip to step 5. Otherwise, parse the output and group failures by root cause (e.g., all failures from the same constructor signature change count as one root cause).
3. **Fix loop** (up to 3 iterations):
   - For each failure group, classify as high-confidence or ambiguous
   - Auto-fix high-confidence failures (type mismatches, wrong args, missing imports)
   - For ambiguous failures, present the diagnosis with file path, error, and surrounding code context, then ask the user how to proceed
   - After fixes applied, re-run the full PHPUnit suite
   - If green, proceed to step 5. If still failing, continue loop. If 3 iterations exhausted, report remaining failures and continue to step 5
4. **Report PHPUnit results** — Summarize what was fixed, what remains
5. **Detect changed files** — Run `git diff --name-only` to find files modified during fix iterations
6. **Run PHPStan** — Execute `docker compose exec -e XDEBUG_MODE=off -T app php vendor/bin/phpstan analyse` scoped to the changed files
7. **PHPStan fix loop** (up to 3 iterations) — Same pattern as step 3: auto-fix confident findings, ask on ambiguous, re-run to verify
8. **Final report** — Combined summary of both passes: fixed, remaining, uncertain

**Patterns to follow:**
- `.claude/skills/test-scaffold/SKILL.md` — skill structure, frontmatter, step format
- `.claude/skills/dddlint/SKILL.md` — file discovery patterns, severity classification

**Test scenarios:**

No automated tests for the skill itself — it's a markdown instruction file. Verification is manual:

- Run `/test-fix` on a branch with known mechanical failures (e.g., constructor signature mismatch). Confirm it fixes them and re-runs until green.
- Run `/test-fix` on a branch with ambiguous failures. Confirm it pauses and asks.
- Run `/test-fix` on a clean branch (all tests passing). Confirm it runs PHPUnit, sees green, then runs PHPStan on any dirty files.

**Verification:**
- The skill file exists at `.claude/skills/test-fix/SKILL.md`
- The skill is listed in the skills table in `CLAUDE.md`
- Manual invocation of `/test-fix` produces the expected behavior described above

---

## Risks & Dependencies

| Risk | Mitigation |
|------|------------|
| PHPUnit output parsing varies by failure type | The agent reads raw output rather than relying on structured parsing — it can handle any format PHPUnit produces |
| PHPStan baseline hides real findings | The skill runs PHPStan on changed files only; baseline errors in unchanged files are irrelevant |
| Fix introduces regressions in unrelated tests | Full-suite re-run after each fix iteration catches this |
| 3-iteration cap too low for complex failures | Ambiguous failures that exceed the cap are reported with context for manual resolution |

---

## Sources & References

- **Origin document:** [docs/brainstorms/test-fix-skill-requirements.md](docs/brainstorms/test-fix-skill-requirements.md)
- Related skills: `.claude/skills/test-scaffold/SKILL.md`, `.claude/skills/dddlint/SKILL.md`
- PHPUnit config: `phpunit.xml.dist`
- PHPStan config: `phpstan.dist.neon`
