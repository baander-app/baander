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
