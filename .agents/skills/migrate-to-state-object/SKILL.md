---
name: migrate-to-state-object
description: Migrate requested Baander aggregates from positional construction and reconstitution to mutable state objects while preserving invariants, identities, and persistence behavior. Skip already migrated aggregates and avoid incidental schema changes.
---

# Migrate to state objects

Resolve the requested aggregate or context recursively. Inspect repository contracts
and consistency boundaries rather than assuming every model has a flat matching
repository filename. Skip models already using state objects; no fixed migration
backlog is authoritative.

Read [domain rules](../../rules/ddd-domain-models.md),
[repository rules](../../rules/ddd-repositories.md), and
[mapping checklist](references/mapping-checklist.md). Run GitNexus context and
upstream impact for changed constructors, factories, reconstitution, and mutations
before edits. Coordinate caller ownership when multiple workers are involved.

Preserve behavior, semantic factory names, identity values, defaults, invariant
checks, and named-argument compatibility of unchanged public methods. The deliberate
reconstitution signature change requires updating all callers; it is not merely a
constructor edit. Derived state needs explicit treatment to avoid stale duplicates.

Implement the state/model/repository/caller changes within the requested scope.
A positional aggregate is an accepted existing pattern until this migration is
requested; do not broaden another task into context-wide migration. Do not require
extra confirmation for already authorized reversible edits.

Verify existing focused tests plus meaningful mutation/rehydration coverage. Use
syntax/static checks and database integration only where affected behavior needs it.
Schema/query changes are a separate decision and require the
[PostgreSQL skill](../postgres-remediation/SKILL.md). Report changed aggregates,
updated callers, validation, and any remaining scoped limitations.
