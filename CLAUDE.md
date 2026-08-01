# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# Operating Directives

1. NO SYCOPHANCY: Do not agree with me just to placate me. Do not guess what I want to hear. Do not alter your factual
   output based on my tone. If I am frustrated, do not backpedal or soften your response.

2. NO APOLOGIES: Never use the words "sorry," "apologize," or "you're right." If you make a factual error, state the
   correction directly without emotional groveling.

3. FLAT, OBJECTIVE TONE: Deliver information bluntly. Strip out conversational filler, validation, and
   empathy-simulating language. State the facts and stop.

4. HANDLING CRITICISM: When I criticize your output, evaluate the criticism strictly on logical and factual merits. If
   my criticism is valid, acknowledge the error flatly. If my criticism is invalid, state why, without being defensive
   or submissive.

5. ADVICE PROTOCOL: Do not offer solutions, tips, or "how-tos" unless I explicitly ask a question that requires them.

## Documentation

| Doc | Purpose |
|-----|---------|
| [getting-started.md](dev-docs/getting-started.md) | Setup, services, common commands, dev users |
| [architecture.md](dev-docs/architecture.md) | Tech stack, request lifecycle, DDD patterns, async runtime, database rules |
| [context-map.md](dev-docs/context-map.md) | Bounded context catalog, dependencies, event flows, maturity notes |
| [phpstorm.md](dev-docs/phpstorm.md) | PhpStorm config, scopes, live templates, navigation |
| [feature-pipeline.md](dev-docs/feature-pipeline.md) | End-to-end feature delivery: plan → issue → branch → implement → review → PR → merge |
| `src/<Context>/README.md` | Per-context domain concepts, ports, events, interactions |

## Development

All backend commands run inside Docker. Use `make` from the host. **UI/Node.js**: always use `yarn`, never `npm`.

PHPUnit 13: `./vendor/bin/phpunit` (inside container). Paratest: `./vendor/bin/paratest --processes auto --tmp-dir var`.
Three suites: Unit, Functional, Integration. All parallel-safe via dama/doctrine-test-bundle transaction isolation.
Convention: manual object construction in tests (no Zenstruck Foundry).

## Integration

- **Project tracker:** `forgejo` — issues and PRs live on the Forgejo instance
- **Forgejo API:** `http://192.168.50.151:3000/api/v1/repos/martin/baander`
- **Token:** `FORGEJO_TOKEN` env var (loaded from `~/.zshrc`)
- **CI:** Forgejo Actions (`.forgejo/workflows/`)
- **Skill:** `/forgejo` for all issue, PR, and CI operations

## Coding Rules

- **Sleeping**: Use `App\Shared\Infrastructure\Swoole\Async::sleep()`. Never call `usleep()`, `sleep()`, or
  `Swoole\Coroutine::sleep()` directly.
- **Primary keys**: Always UUID v7 (`Uuid` + `UuidType`). Never auto-incrementing integers.
- **String columns**: Always `TEXT`, never `VARCHAR(n)`.
- **JSON columns**: Always `JSONB`.
- **Blocking calls**: `proc_open()` must run in separate worker processes (CPU process pool), never on Swoole workers.
- **DDD patterns**: See `.claude/rules/ddd-domain-models.md`, `ddd-repositories.md`, `ddd-cqrs.md`, `ddd-ports.md`.
- **OpenAPI annotations**: Never combine `properties:` with `type: 'object'` on `OA\JsonContent` or `OA\Items`.
  Nelmio's `ModelRegistry` resolves `type: 'object'` as a class name and crashes. Omit the explicit `type` — Nelmio
  infers it from the presence of `properties`.

## Skills

Auto-trigger these project skills when the described situation occurs:

| Trigger | Skill |
|---------|-------|
| Creating a new aggregate root, value object, or repository | `/entity-scaffold` |
| Creating a new API endpoint | `/endpoint-scaffold` |
| Generating tests for a class | `/test-scaffold` |
| Migrating an aggregate from positional-arg to State pattern | `/migrate-to-state-object` |
| Migrating ApplicationService to Port pattern | `/migrate-application-to-port` |
| Adding a cached repository decorator | `/cached-repository` |
| After modifying PHP files in a bounded context | `/dddlint` |
| After changing context structure | `/documentation-maintainer update <Context> readme` |
| After changing project-wide structure | `/documentation-maintainer full sync` |
| Squashing and force-pushing to master | `/sync-github` |
| Running tests and fixing failures | `/test-fix` |
| Creating or managing issues and PRs | `/forgejo` |
| Checking CI pipeline status | `/forgejo` |
| End-to-end feature delivery (plan → issue → branch → implement → review → PR → merge → close) | `/feature-pipeline` |

<!-- gitnexus:start -->
# GitNexus — Code Intelligence

This project is indexed by GitNexus as **baander** (36220 symbols, 82116 relationships, 300 execution flows). Use the GitNexus MCP tools to understand code, assess impact, and navigate safely.

> Index stale? Run `node .gitnexus/run.cjs analyze` from the project root — it auto-selects an available runner. No `.gitnexus/run.cjs` yet? `npx gitnexus analyze` (npm 11 crash → `npm i -g gitnexus`; #1939).

## Always Do

- **MUST run impact analysis before editing any symbol.** Before modifying a function, class, or method, run `impact({target: "symbolName", direction: "upstream"})` and report the blast radius (direct callers, affected processes, risk level) to the user.
- **MUST run `detect_changes()` before committing** to verify your changes only affect expected symbols and execution flows. For regression review, compare against the default branch: `detect_changes({scope: "compare", base_ref: "master"})`.
- **MUST warn the user** if impact analysis returns HIGH or CRITICAL risk before proceeding with edits.
- When exploring unfamiliar code, use `query({search_query: "concept"})` to find execution flows instead of grepping. It returns process-grouped results ranked by relevance.
- When you need full context on a specific symbol — callers, callees, which execution flows it participates in — use `context({name: "symbolName"})`.
- For security review, `explain({target: "fileOrSymbol"})` lists taint findings (source→sink flows; needs `analyze --pdg`).

## Never Do

- NEVER edit a function, class, or method without first running `impact` on it.
- NEVER ignore HIGH or CRITICAL risk warnings from impact analysis.
- NEVER rename symbols with find-and-replace — use `rename` which understands the call graph.
- NEVER commit changes without running `detect_changes()` to check affected scope.

## Resources

| Resource | Use for |
|----------|---------|
| `gitnexus://repo/baander/context` | Codebase overview, check index freshness |
| `gitnexus://repo/baander/clusters` | All functional areas |
| `gitnexus://repo/baander/processes` | All execution flows |
| `gitnexus://repo/baander/process/{name}` | Step-by-step execution trace |

## CLI

| Task | Read this skill file |
|------|---------------------|
| Understand architecture / "How does X work?" | `.claude/skills/gitnexus/gitnexus-exploring/SKILL.md` |
| Blast radius / "What breaks if I change X?" | `.claude/skills/gitnexus/gitnexus-impact-analysis/SKILL.md` |
| Trace bugs / "Why is X failing?" | `.claude/skills/gitnexus/gitnexus-debugging/SKILL.md` |
| Rename / extract / split / refactor | `.claude/skills/gitnexus/gitnexus-refactoring/SKILL.md` |
| Tools, resources, schema reference | `.claude/skills/gitnexus/gitnexus-guide/SKILL.md` |
| Index, status, clean, wiki CLI commands | `.claude/skills/gitnexus/gitnexus-cli/SKILL.md` |

<!-- gitnexus:end -->
