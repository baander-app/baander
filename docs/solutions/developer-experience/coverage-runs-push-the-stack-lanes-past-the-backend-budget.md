---
title: Coverage runs push the stack lanes past the backend budget
date: 2026-11-20
category: developer-experience
module: Backend test coordinator (make ci, make phpunit, test-functional-container.sh)
problem_type: developer_experience
component: testing_framework
related_components: [development_workflow]
severity: medium
applies_when:
  - "make ci or make phpunit ends with Backend exit 124 and \"docker timed out\" for a stack lane"
  - A backend run reports passing lanes but one or more stack lanes never finished
  - Judging whether a branch that adds functional or integration tests is ready to merge
tags: [make-ci, coverage, xdebug, stack-lane, backend-timeout, exit-124, test-functional-container, test-isolation]
---

# Coverage runs push the stack lanes past the backend budget

## Context

On 2026-11-20, two `make ci` runs of the auth hardening, at `e7ec54519` and at the merge `3473a20c3`, ended with `Backend exit 124` after about 3,660 seconds. The ordinary and native lanes passed. One stack lane timed out in the first run and both in the second, with tests still running: the PostgreSQL log showed statements up to the cutoff.

The first diagnosis was wrong. Each stack lane's manifest (`var/test-runs/<run>/run/stack-N.json`) records `"processes": 1`, and that looked like the cause. It is not: `Coordinator::partition` gives every stack lane one process by design (`packages/baander-tooling/src/Backend/Coordinator.php:66-76`), and `scripts/test-functional-container.sh` uses the same coordinator and the same split.

The difference is coverage. `make ci` runs `make phpunit`, which passes `--coverage-text` and `--coverage-clover` (`Makefile:133-134`), and its lane logs print `Runtime: PHP 8.5.12 with Xdebug 3.5.0`. The functional runner runs without coverage and prints `Runtime: PHP 8.5.12`. With the same 174 and 173 files per lane and two workers, the coverage run passed `stack-2` in 1,995 seconds in the first run and lost `stack-1` to the budget. In the second it lost both. The same files run without coverage at `233446bc5` finished the lanes in 1,643 and 1,577 seconds.

The budget is `BAANDER_TEST_TIMEOUT`, 3,600 seconds by default for the whole run including setup (`packages/baander-tooling/resources/tooling-defaults.json:74`; `docs-book/part-2-developer-guide/testing/backend-runners.md`). The CI workflow's "PHPUnit with coverage" step runs the same coverage command under the same default (`.github/workflows/ci.yaml:329-335`). Functional and integration test files grew from 330 at `44e315ee3` to 351 at `43e0bb7cd`. CI run 43826408058 on `44e315ee3` passed its quality gate on 2026-11-16, its step 41 taking 2,276 of 3,600 seconds (per the "Master CI" note in `ROADMAP.md`); whether the coverage step still fits has not been checked.

## Guidance

1. **Treat a lane that timed out as not run.** Exit 124 says nothing about its tests. Check the lane's runtime line before blaming the process count: `grep Runtime: var/test-runs/<run>/stack-1.log`.
2. **Rerun the lost lanes' files without coverage.** Each lane's file list is in `var/test-runs/<run>/run/stack-N.json`:

   ```bash
   python3 -c "import json,sys
   for lane in sys.argv[2:]:
       for f in json.load(open(f'{sys.argv[1]}/run/{lane}.json'))['files']:
           print(f.replace('/var/www/html/', ''))" var/test-runs/<run> stack-1 stack-2 > /tmp/stack-files.txt
   BAANDER_TEST_IMAGE=baander-ci:local bash scripts/test-functional-container.sh $(cat /tmp/stack-files.txt)
   ```

   Report the gate as the passing lanes plus this rerun, and say that coverage was not measured for those files.
3. **For a coverage figure, give the run more room.** Raise `BAANDER_TEST_TIMEOUT`. `BAANDER_TEST_PROCESSES` (default 2, at most 8) also sets the number of stack lanes, so a higher value spreads the same files over more lanes; this was not tried on 2026-11-20.

## Why This Matters

Lanes that time out hide failures. Both reruns found real defects that the timed-out lanes had not reported:

- `TranscodeCleanupConsoleOutputTest` booted a console under `APP_ENV=prod` with the committed default secrets, which production now refuses (fixed in `26a7fd0cb`).
- `LoginAccountThrottleTest` logged in from client addresses that `ApiRateLimitTest` expects unused in the shared anonymous API limiter, so the two failed whenever they shared a lane (fixed in `233446bc5` by moving the throttle test to `198.18.10.x`). Tests that charge a shared limiter need addresses no other test uses.

## When to Apply

- Any local `make ci` or `make phpunit` that ends with exit 124.
- A CI coverage step that times out after a branch adds functional or integration tests.

## Examples

The rerun without coverage at `233446bc5` passed 3,950 tests in 347 files in 1,720 seconds, with `LoginAccountThrottleTest` and `ApiRateLimitTest` in the same lane. The `make ci` run before it had lost both stack lanes at 3,668 seconds.

## Related

- [Rebuild the local CI image and run WebKit harnesses in the Playwright image](local-gate-images-and-webkit-harness-runs.md)
- `docs-book/part-2-developer-guide/testing/backend-runners.md` documents the budget, the worker count and `make phpunit` coverage.
