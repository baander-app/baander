# Test & Fix Skill Requirements

## Problem

After refactors or signature changes, PHPUnit and PHPStan failures are often mechanical (type mismatches, wrong constructor args, missing imports). Fixing them manually is repetitive — paste output, read error, edit file, re-run. A skill can automate the diagnosis-and-fix loop.

## Skill Behavior

### Trigger

`/test-fix` — no arguments. Runs the full PHPUnit suite, then PHPStan on changed files.

### Flow

1. **Run PHPUnit** — `docker compose exec -T app ./vendor/bin/phpunit` (no coverage flags)
2. **Analyze failures** — parse output, group by root cause, identify high-confidence fixes
3. **Fix** — apply high-confidence fixes automatically; for ambiguous failures, present diagnosis and ask how to proceed
4. **Verify** — re-run the full PHPUnit suite. If still failing, repeat steps 2–4. Cap at 3 iterations
5. **PHPStan pass** — once PHPUnit passes (or exhausts retries), run `docker compose exec -T app ./vendor/bin/phpstan analyse` scoped to files changed during fix iterations
6. **Fix PHPStan findings** — same autonomy model: auto-fix confident, ask on ambiguous. Re-run PHPStan to verify. Cap at 3 iterations
7. **Report** — summarize what was fixed, what remains, and what was uncertain

### Autonomy Model

| Confidence | Action |
|---|---|
| High (mechanical: type mismatch, wrong args, missing import) | Fix automatically, no confirmation |
| Low (ambiguous: logic error, missing test setup, domain-specific) | Present diagnosis and options, wait for direction |

### Iteration Cap

Both the PHPUnit and PHPStan passes cap at 3 fix-and-verify iterations. If failures remain after 3 attempts, stop and report what's left.

## Scope

**In scope:**
- PHPUnit test failures across Unit, Functional, and Integration suites
- PHPStan errors on changed files
- Mechanical fixes to both source code and test code
- Regression checking via full-suite re-runs

**Out of scope:**
- Coverage analysis or reporting
- Filter-based / targeted test runs
- Test generation (use `/test-scaffold` instead)
- Fixing integration tests that need database or service orchestration beyond container setup

## Success Criteria

- High-confidence mechanical failures are fixed without human intervention
- The skill never makes a change that causes previously-passing tests to fail (verified by full-suite re-run)
- Ambiguous failures are surfaced with enough context for the user to decide
- The skill completes (or stops with a clear report) rather than looping indefinitely
