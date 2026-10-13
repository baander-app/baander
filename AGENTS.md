# Project instructions

## Workflow

Work runs through the compound-engineering skills. Plan with `ce-plan`: it writes a
unified plan under `docs/plans/`, and the plan passes `ce-doc-review` before any
implementation. Implement with `ce-work` on a branch named after the plan: units land
as local commits, `ce-simplify-code` runs at phase boundaries, `ce-code-review` runs
before shipping and its justified findings are applied. Push only when asked. Record
every delivered item and its verification checkpoint (suite counts, static gates) in
`ROADMAP.md`; the roadmap is the record of scope, open items and acceptance.

`CONCEPTS.md` is the shared vocabulary (entities, named processes, status concepts);
use its terms and add an entry when a plan introduces a new one. Documented solutions
to past problems live in `docs/solutions/`, by category with YAML frontmatter
(`module`, `tags`, `problem_type`); consult them before implementing or debugging in
a documented area. After a solved, verified problem, offer `ce-compound` once, at the
completion checkpoint, only when the reasoning would not be recoverable from the
final code, tests and docs and losing it would plausibly cause recurrence or
substantial rediscovery; completion, effort and diff size alone never qualify.

Write every report, summary or handoff to the user through `ce-noslop` (top-level
agent only; not code, config, verbatim quotes or text the user asked to post as
written). Run `prose-fix` on documentation prose you write or change.

## Delegation

Use subagents for substantial, separable work without waiting to be asked; keep
small or tightly coupled tasks local. Start workers early, at most eight at once,
while the lead continues independent work. A brief names the objective, the owned
files or read-only scope, dependencies, checks and the deliverable; workers follow
these instructions and the applicable skills. Assign non-overlapping edits, agree on
interfaces first, and never overwrite others' work. Workers do not stage or commit;
they report files, evidence, check results and blockers. The lead owns shared
configuration, integration, the authoritative checks and the commits; repeat checks
only for changes or unresolved failures, and serialize expensive or shared-state
tests. Delegate substantial PostgreSQL work to the
[postgres specialist](.agents/agents/postgres.md), or give a worker that profile.
It maintains the skill's extension inventory from verified discoveries; keep one
writer and review those updates with the implementation.

## Tests and PostgreSQL

Use `baander.app` or its subdomains in test code, fixtures and email addresses; no
placeholder domains. Mock HTTP and DNS or route to disposable local services, never
production. Preserve literal IP cases for network and security tests. The
[testing guide](docs-book/part-2-developer-guide/testing.md) names the current
runners; the authoritative gates run in the CI image.

For PostgreSQL schema, migration, query and connection work, follow
[Don't Do This](https://wiki.postgresql.org/wiki/Don%27t_Do_This) and the
[postgres-remediation skill](.agents/skills/postgres-remediation/SKILL.md): audit,
remedy and verify the affected code, explain applicable exceptions, and do not expand
into unrelated schema rewrites. The registry keeps its SQLite/rqlite architecture.

## Temporary pre-release policy

There are no production deployments (reconfirmed 2026-10-03). Favor the intended
future design over backward compatibility: remove obsolete paths, add no
compatibility layers or migration fallbacks for hypothetical installations, and
redesign schemas or rewrite migration history when the task justifies it. Keep
fresh-install tests and protect local user data unless its reset is authorized.

Remove this policy only when the user explicitly says the first production release
has been cut; never infer that from a commit, tag, build or deployment preparation.

## Coding guidance

Read [architecture rules](.agents/rules/architecture-rules.md) and the relevant
`ddd-*.md` reference in `.agents/rules/` for backend changes; add
[admin and console rules](.agents/rules/admin-cli-parity.md) for admin routes,
console commands, long-running jobs or state inside the web server; read
[frontend rules](.agents/rules/frontend.md) and `ui/DESIGN.md` for web changes.
Treat documented exceptions narrowly; existing violations and baselines authorize no
new ones. Project skills live in `.agents/skills/`; edit skills, rules and agents only
under `.agents/`.

- Do not prefix function or method calls with `void`; keep asynchronous error
  handling explicit.
- Prefix Baander-defined HTTP headers with `X-Baander-`; keep generic protocol,
  proxy and security header names.
- Code stands on its own: source, tests, comments and docblocks never cite planning
  artifacts (requirement, acceptance-example or decision ids such as `R5`, `AE4`,
  `KTD2`, roadmap item numbers, plan file paths). State the behaviour in plain
  words. Plan ids belong in plans, the roadmap, commit messages and reports.
