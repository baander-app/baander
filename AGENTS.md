# Project instructions

## Delegation

Use subagents proactively for substantial, separable work; no further user request
is needed. Keep small or tightly coupled tasks local. Start useful workers early,
with at most eight active workers across the team; the lead continues independent
work.

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

## Temporary pre-release policy

There are no production deployments (reconfirmed 2026-10-03). Favor the intended
future design over backward compatibility. Remove obsolete paths when appropriate;
do not add compatibility layers or migration fallbacks for hypothetical production
installations. Schema redesigns and migration-history rewrites are allowed when
justified by the task. Keep fresh-install tests and protect local user data unless
its reset is authorized.

Remove this entire temporary policy when the user explicitly says the first
production release has been cut. Do not infer that milestone from a commit, tag,
build, or deployment preparation.

## PostgreSQL delegation

Delegate substantial PostgreSQL work to the [postgres specialist](.agents/agents/postgres.md).
If named roles are unavailable, give a worker that profile's instructions. It
maintains the skill's extension inventory from verified discoveries during tasks;
coordinate a single writer and review those updates with the implementation.

## Coding guidance

Do not prefix function or method calls with `void`. Keep asynchronous error
handling explicit.
Prefix Baander-defined HTTP headers with `X-Baander-`; preserve generic protocol,
proxy, and security header names.

For backend changes, read [architecture rules](.agents/rules/architecture-rules.md)
and the relevant `ddd-*.md` reference in `.agents/rules/`. For admin routes, console
commands, long-running jobs, or state inside the web server, also read
[admin and console rules](.agents/rules/admin-cli-parity.md). For web changes, read
[frontend rules](.agents/rules/frontend.md) and `ui/DESIGN.md`. Use the
[testing guide](docs-book/part-2-developer-guide/testing.md) for current runners.
Treat documented exceptions narrowly; existing violations and baselines do not
authorize new ones. Project skills live in `.agents/skills/`.
Edit skills, rules, and agents only under `.agents/`.

Documented solutions to past problems (bugs, best practices, workflow patterns)
live in `docs/solutions/`, organized by category with YAML frontmatter (`module`,
`tags`, `problem_type`); consult them when implementing or debugging in a
documented area.

After a solved, verified problem, offer once to invoke the `ce-compound` skill at
the completion checkpoint only when the work produced durable project reasoning
that is not readily recoverable from the final code, tests, types, comments, or
existing documentation, and losing it would plausibly cause recurrence, material
risk, or substantial rediscovery. Apply this counterfactual: if the learning
document disappeared, would a future engineer reading the final implementation
still be likely to repeat the mistake or redo substantial investigation? If not,
do not offer. Completion, effort, and diff size alone are not enough. Offer at the
checkpoint so a qualifying learning can ship in the PR that produced it, and only
where the repository treats captured learnings as tracked, committed knowledge.

Write every report, summary, or handoff to the user through the `ce-noslop`
skill. This applies when you are the top-level agent writing to the user, not when
you are a subagent reporting to its caller. Do not apply it to code, config,
verbatim quotes, or text the user asked to post as written.
