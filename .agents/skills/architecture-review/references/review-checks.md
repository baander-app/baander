# Backend review checks

## Scope and structural evidence

For changed-file review, include relevant unstaged, staged, untracked, and branch
changes according to the request; `git diff --name-only` alone covers only unstaged
tracked changes. Inspect impacted callers and neighboring contracts rather than
scanning the entire repository and filtering after the work is done.

Use [architecture](../../../rules/architecture-rules.md),
[model](../../../rules/ddd-domain-models.md),
[repository](../../../rules/ddd-repositories.md),
[port](../../../rules/ddd-ports.md),
[CQRS](../../../rules/ddd-cqrs.md), and
[admin and console](../../../rules/admin-cli-parity.md) rules for the relevant roles.

- Trace dependencies by layer. Shared Domain access does not exempt Shared
  Infrastructure access. Identify cross-context ports/events separately from direct
  foreign repository access and persistence relationships.
- Read `deptrac.yaml` and relevant baseline entries. Report target-policy mismatches
  and uncovered contexts; do not expand its allowlist to suppress a finding.
- Check model invariants and mapping completeness, preserving semantic factories,
  readonly identity fields, and accepted positional aggregates. Do not require
  factories/repositories for every value type or child entity.
- Inspect entity/model separation and actual repository signatures and paths.
  Helper names and directory preferences do not prove behavioral correctness.
- Verify service aliases, decorators, and registration in effective configuration.
  Resource loading alone does not prove interface resolution. A unique inferred
  alias may work; an ambiguous or missing service needs concrete evidence.
- For admin routes and console commands, check that the controller and command reach
  the same Application use case, that outcomes use the shared not-found, conflict and
  invalid-input exceptions, and that each admin route carries `#[CliCounterpart]` or
  `#[CliParityExemption]`. `AdminCliParityTest` proves only the markings, not that
  both paths share their rules.
- Check that state set when work is queued on `swoole_task`, such as a claim or a
  running status, expires or is fenced, because that transport loses queued messages
  on restart.
- Check command immutability and callable registration across all current layouts.
  Class-level versus method-level valid framework registration is not an import
  violation merely because a scanner only recognizes one syntax.

## Semantic review

Trace a representative input through authorization, validation, orchestration,
mutation, persistence, event/command dispatch, and response mapping. Focus on actual
failure and retry behavior rather than demanding an endpoint for every port.

Check null handling, invariant enforcement, collection transitions, ID conversions,
rehydration round trips, and consistent error propagation. Verify cross-context
contracts and synchronous/asynchronous routing. Look for domain-write/outbox splits,
cache state published before commit, and external effects that survive rollback.
Use the [PostgreSQL skill](../../postgres-remediation/SKILL.md) for affected database
work; rollback claims need a disposable database and an independent connection.

Evaluate tests for observable behavior and meaningful failures. Heavy mocking is a
limitation when it prevents proving integration; it is not automatically a defect.
Recommend focused checks and report which were run. Avoid forcing a full suite for
an inventory or repeating a worker's passing checks without a relevant change.

Current enforcement has limits: Deptrac omits some context layers and external
framework dependencies; the custom PHPStan rules cover payload typing and OpenAPI
tag descriptions, not all architecture conventions. Parsed-source tests now cover
missing payload types, `object`, `?object`, and `object|null`, and Symfony attribute
identity through imports and fully qualified names with PHP's case-insensitive
class matching. Typed-array payloads remain supported;
the rule does not enforce every scalar, union, or DTO validation requirement.

## Findings

For each finding, give path/line, violated contract or target, observed consequence,
classification (defect, debt, exception, or enforcement mismatch), and a bounded
remedy. Confirm inferred orphans through service/event/handler registration before
calling them dead code. Combine duplicate findings and preserve uncertainty when
static analysis cannot resolve a dynamic entrypoint.
