---
name: architecture-review
description: Review Baander backend context structure, dependency boundaries, wiring, conventions, and behavioral integration; also produce a context inventory for documentation. Use for requested architecture audits or substantial boundary changes, not every PHP edit.
---

# Architecture review

Choose the scope and depth from the request: changed files, a named context, a
repository-wide audit, or an inventory. Review is read-only unless fixes are
explicitly requested. Do not add an unrelated full audit to a narrow task.

Read [architecture rules](../../rules/architecture-rules.md) and the companion rules
for affected models, repositories, ports, or handlers. Use the repository's required
GitNexus query/context/impact workflow. Check index freshness and coordinate refreshes
with the lead; do not treat unresolved graph callers as proof of no impact.

Read only the relevant reference:

- [Inventory](references/inventory.md): context structure and documentation inputs.
- [Review checks](references/review-checks.md): dependencies, wiring, conventions,
  and deeper behavior/integration review.
- [Port extraction](references/port-extraction.md): only when a requested fix needs
  an application abstraction extracted from a concrete service.

Separate architecture targets, documented exceptions, enforcement gaps, and proven
implementation defects. Baseline entries and widespread patterns do not themselves
approve new violations. Verify findings against complete contracts and code paths;
filename or import matches are candidates, not verdicts.

For substantial semantic review, delegate independent concerns within the session's
available slots and project worker cap. Give bounded read-only scopes and avoid
multiple agents repeating the same structural inventory. The lead verifies evidence
and deduplicates findings before reporting.

Report actionable findings first with file/line evidence, consequence, classification,
and a scoped recommendation. Include scope, checks used, and coverage limits. Inventory
requests need data rather than a severity report. Save a report artifact only when
requested or part of the established task deliverable; findings do not authorize fixes.
