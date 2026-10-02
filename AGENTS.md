# Project instructions

## Delegation

Use subagents proactively for substantial, separable work; no further user request
is needed. Keep small or tightly coupled tasks local. Start useful workers early,
with at most three active workers across the team; the lead continues independent
work. `.codex/config.toml` enables delegation and inherits user model defaults.

- Give concise briefs: objective, owned files or read-only scope, dependencies,
  checks, and deliverable. Fork full history only when needed. All workers follow
  these instructions and applicable skills.
- Delegate exploration, implementation, and independent review as useful. Assign
  non-overlapping edits and agree on interfaces first. Coordinate scope changes
  and nested delegation; never overwrite others' work. Workers do not stage or
  commit. The lead owns shared configuration, index refreshes, and integration.
- Reuse workers, resolve dependencies by message, and avoid duplicate work.
  Workers report files, evidence, check results, and blockers. The lead reviews
  diffs and runs combined checks; repeat checks only for changes or unresolved
  failures. Serialize expensive/shared-state tests or isolate their resources.
- Report assignments and meaningful results in progress updates. If delegation
  is unavailable, explain briefly and continue locally.

## Tests and PostgreSQL

Use `baander.app` or its subdomains in test code, fixtures, and email addresses;
no placeholder domains. Mock HTTP/DNS or route to disposable local services,
never production. Preserve literal IP cases for network/security tests.

For PostgreSQL schema, migration, query, and connection work, follow
[Don't Do This](https://wiki.postgresql.org/wiki/Don%27t_Do_This) and use the
[postgres-remediation skill](.agents/skills/postgres-remediation/SKILL.md).
Audit, remedy, and verify affected code; explain applicable exceptions. Do not
ignore findings or expand into unrelated schema rewrites. This does not change
the registry's SQLite/rqlite architecture.

Delegate substantial PostgreSQL work to the [postgres specialist](.codex/agents/postgres.toml).
If named roles are unavailable, give a worker that profile's instructions. It
maintains the skill's extension inventory from verified discoveries during tasks;
coordinate a single writer and review those updates with the implementation.

## Coding guidance

For backend changes, read [architecture rules](.agents/rules/architecture-rules.md)
and the relevant `ddd-*.md` reference in `.agents/rules/`. For web changes, read
[frontend rules](.agents/rules/frontend.md) and `ui/DESIGN.md`. Use the
[testing guide](docs-book/part-2-developer-guide/testing.md) for current runners.
Treat documented exceptions narrowly; existing violations and baselines do not
authorize new ones. Project skills live in `.agents/skills/`.

## Other local sessions

When installed, use `/skill:pi-intercom` to coordinate relevant parallel or related-repository
sessions. Prefer `send`; use `ask` only when blocked. Skip unrelated work,
trivial questions, and tasks you can proceed with independently.

<!-- gitnexus:start -->
## GitNexus

Repository: `baander`. Refresh with `node .gitnexus/run.cjs analyze --index-only`.
Use the [GitNexus skill](.agents/skills/gitnexus/SKILL.md) for runner fallback and
CLI equivalents. Index-only refresh preserves maintained instructions and skills.

- Before editing any function, class, or method, **must** run
  `impact({target: "symbolName", direction: "upstream"})`. Report callers,
  affected processes, and risk; warn before HIGH/CRITICAL changes. Never ignore
  these warnings.
- Before committing, **must** run `detect_changes()` and verify expected scope.
  For regression review: `detect_changes({scope: "compare", base_ref: "master"})`.
- Explore unfamiliar flows with `query({search_query: "concept"})`; use
  `context({name: "symbolName"})` for callers/callees. For security review use
  `explain({target: "fileOrSymbol"})` (requires `analyze --pdg`).
- Rename symbols with `rename`, never find-and-replace.

<!-- gitnexus:end -->
