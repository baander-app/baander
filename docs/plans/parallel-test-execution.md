# Adopt ParaTest for backend verification

## Intent and execution

Adopt installed ParaTest 7.22.4 for Unit, StaticAnalysisRules, Functional and
Integration. Work on `plan/parallel-test-execution`, using the documented manual
compound-engineering workflow: independent plan review before implementation,
local commits, simplification, independent code review, and authoritative checks.
Push only on request. No application API, schema or dependency changes are planned.

## Coordinator and isolation

Share one coordinator between local scripts and Forgejo CI, preserving suite,
path, filter and image selection. Discover selected tests once and require disjoint,
collectively complete lane manifests. Run these phases sequentially:

1. Ordinary Unit and StaticAnalysisRules use two ParaTest workers, recycled after
   25 files.
2. An explicit manifest assigns native Swoole, fork, signal and fixed-resource
   tests to a separate privileged container without networking. Run one worker,
   with a fresh process per file.
3. Functional and Integration use two concurrent independent app/PostgreSQL/Redis
   stacks, round-robin file distribution, and one ParaTest worker per stack with
   a fresh process per file.

Keep file/class scheduling; do not use method-level `--functional`. Pass memory
limits explicitly to child PHP processes. `BAANDER_TEST_PROCESSES` defaults to 2
and accepts integers 1–8. Use fewer stacks when fewer files are selected. The
configurable overall timeout defaults to 3,600 seconds.

Each stack owns its database, Redis, cache, temporary files and sockets. Export
all database and Redis DSNs to nested processes. Retain isolated migrations and
OAuth keys. Use no shared writable mounts or published ports. Preserve logs and
reports on failure; terminate children and remove containers and networks after
completion, interruption or timeout.

## Result integrity

Match completed JUnit identities by class, method and exact dataset label;
normalize PHPUnit formatting without dropping labels. Fail on zero matches,
missing or duplicate identities, crashes, missing reports and existing strict
failure conditions. Skipped/incomplete cases count as observed identities while
retaining PHPUnit's configured failure semantics.

Merge serialized coverage with identical source paths and filters. Preserve
executable uncovered lines and exclusions. Reject missing, stale or corrupt
artifacts and publish final coverage only after successful completeness checks.
Keep `coverage/report.txt` and `coverage/clover.xml`, with blocking CI validation.
Keep runtime security drills serial. Remove the sequential sharder only after
qualification; the coordinator with one worker becomes the diagnostic runner.

## Delivery and qualification

Add focused runner regressions for filtered datasets, manifest completeness,
strict failures, crashes, timeouts, cleanup and coverage merging. Verify stack
isolation against database cleanup/outages, Redis pauses, cache files and sockets,
and confirm nested processes inherit their stack environment.

Compare the current fresh-process runner and new coordinator in the same CI image,
checkout and random seed, both with and without coverage. Require equal selected
and completed identities, coverage denominators and covered-line identities.
Require two successful full parallel runs and lower end-to-end elapsed time,
including setup and merging, before switching defaults. Investigate mismatches.

Run authoritative backend suites and required static gates. Record actual counts,
runtimes, timings and limitations in `ROADMAP.md`; update the testing guide and
apply prose-fix. Complete simplification and independent code review, apply
justified findings, and commit locally.

## Independent plan review

Reviewed before implementation on 2026-10-14. Approved with these requirements:
generate lane XML because ParaTest has no file-list option; preserve configuration
and remove original suite/path selectors after discovery; reject unexpected as well
as missing identities; use `/var/www/html` and the same seed in all containers;
validate artifact freshness and stage coverage publication; make metric extraction
blocking. The coordinator uses Python (already in the CI runner) and a PHP helper
for discovery, lane execution and report validation.

## Qualification addendum: user timestamps

The sequential reference run exposed an existing failure in
`AdminUserCliParityTest`: the CLI returns the aggregate's creation timestamps,
while `UserEntity` creates fresh timestamps during persistence. Crossing a second
boundary makes the stored resource differ from the command output. On 2026-10-14,
the user authorized a focused persistence fix as part of qualification.

Preserve the aggregate's creation and update instants when synchronizing it to the
entity, and add a deterministic regression using fixed instants. Keep the existing
CLI parity assertion. This addendum changes no API shape, schema or dependencies;
it makes the stored values agree with the use-case result. Review the bounded fix
before integration and rerun the affected functional/integration tests before full
runner equivalence qualification.

Independent addendum review approved the fix before implementation: synchronize
both timestamps after all entity setters and cover creation, update and unchanged
save while preserving `createdAt`. Retain the existing CLI resource comparison.

## Infrastructure addendum: disposable Compose projects

The user selected disposable infrastructure in a dedicated test Compose file.
Declare the application, PostgreSQL and Redis services there, and create a unique
Compose project for each service lane. Keep ordinary and native lanes isolated
without service networking. Preserve canonical source paths, per-stack environment,
root setup followed by unprivileged test execution, and the existing artifact and
completeness checks. No host ports or shared writable mounts are permitted.

The coordinator owns project lifecycle. Collect diagnostics before teardown and run
`docker compose down --volumes --remove-orphans` after completion, interruption or
timeout, with bounded cleanup independent of the test deadline. Requalify actual
Compose isolation, cleanup, full-suite results and timings before adoption. Prior
Docker CLI measurements remain historical evidence, not Compose qualification.

Independent review approved this addendum before implementation. Register projects
before startup, keep names project-scoped, and verify removal of anonymous image
volumes after partial startup as well as normal completion. The CI runner image
must include the Docker Compose plugin and verify it during its build.

## Delivery checkpoint

Implemented and verified locally on 2026-10-15. Local scripts, Makefile test targets
and Forgejo CI use the shared coordinator and disposable Compose stacks. The old
sequential runner was removed after exact identity and coverage qualification.
The qualification snapshot matched 9,098 test identities, 44,191 executable lines
and 35,385 covered lines. Final verification after adopting the reviewed runner
checks passed 9,103 tests / 74,465 assertions and all required static gates.

The [roadmap](../../ROADMAP.md#parallel-backend-verification) records individual
runs, isolation drills, corrected fixtures, timing evidence and the wall-clock
discrepancy. Independent plan, implementation and integration reviews are complete.
Changes are committed locally; remote CI and publishing the updated runner image
remain outside this delivery.
