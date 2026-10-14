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
