# Contributing

Contributions are welcome. This page covers the workflow and conventions for contributing code to Baander.

## Getting Started

1. Set up the [development environment](development-environment.md)
2. Run tests to verify everything works: `make phpunit`
3. Run static analysis: `make phpstan`

## Branch Naming

Use conventional prefixes:

| Prefix | Purpose | Example |
|--------|---------|---------|
| `feat/` | New feature | `feat/transcode-cpu-pool` |
| `fix/` | Bug fix | `fix/auth-token-refresh` |
| `refactor/` | Code restructuring | `refactor/party-cqrs` |
| `docs/` | Documentation | `docs/security-guide` |
| `chore/` | Maintenance | `chore/update-deps` |
| `test/` | Test additions | `test/transcode-encoding` |

## Commit Messages

Follow [conventional commits](https://www.conventionalcommits.org/) with an optional scope:

```
feat(context): description
fix(context): description
refactor(context): description
chore(context): description
docs: description
test: description
```

Examples from the project's history:

```
feat(shared,infra): add Swoole DX overhaul — health probes, WebProfiler, Prometheus, WebSocket
fix(transcode): resolve 14 bugs in CPU process pool and encoding loop
refactor(party): replace WebSocket handler with CQRS playback commands
chore(infra): update Docker build, supervisord, FFmpeg, and email templates
```

## Pull Request Process

1. Create a feature branch from the default branch
2. Make your changes with tests
3. Run `make phpunit` and `make phpstan` — both must pass
4. Push and open a pull request
5. Address review feedback

## Code Review Expectations

Reviewers check for:

- **DDD conventions** — domain models use state objects, ports for dependencies, repositories implement domain interfaces
- **Test coverage** — new behavior has tests; domain logic is tested without mocks
- **No breaking changes** — API changes are additive; database migrations don't drop columns
- **Code style** — matches existing patterns in the codebase

## Documentation Maintenance

When you change code, update the corresponding documentation. The
[operator manual](../part-1-operator-guide/README.md) explains installation,
routine operation, monitoring and recovery. Update the affected
chapter when a change alters those tasks; keep argument and option detail in the
CLI reference.

| Code Change | Documentation to Update |
|-------------|------------------------|
| Add or modify a CLI command | Use the [documentation-maintainer](../../.agents/skills/documentation-maintainer/SKILL.md) CLI mode to update [CLI Reference](../part-1-operator-guide/commands/README.md) |
| Change a DDD convention | Update `.agents/rules/ddd-*.md`, then update [Coding Conventions](coding-conventions.md) |
| Add or change an environment variable | Update `.env.example`, then update [Configuration](../part-1-operator-guide/configuration.md) |
| Add or change an API endpoint | Update [API Reference](api-reference.md) |
| Add a new bounded context | Update [Architecture Overview](architecture.md) and [Shared Kernel](shared-kernel.md) |
| Change the build process | Update [Development Environment](development-environment.md) and [Getting Started](../part-1-operator-guide/getting-started.md) |

### Convention Synchronization

The files in [`.agents/rules/`](../../.agents/rules/) are the authoritative source for coding conventions. [AGENTS.md](../../AGENTS.md) points agents to the applicable rules. When conventions change:

1. Update the relevant rules file
2. Regenerate `coding-conventions.md` from the rules files
3. Both the rules file and the human-readable doc should reflect the same conventions

## Running Tests

See the [Testing](testing.md) page for the full test suite guide.

## JavaScript Dependency Maintenance

After changing a web dependency, inspect its manifest and owned lock with the
read-only checker:

```bash
php scripts/check-js-dependencies.php --local-only --format json
php scripts/check-js-dependencies.php --online --format json
```

Local mode is the default and makes no requests. The
[JavaScript policy](../../packages/baander-tooling/resources/javascript-dependencies.json)
classifies eight manifests: web, player, site and shared own independent Yarn
locks; `tests/e2e` owns its npm lock. The DPoP and BlurHash library manifests have
no independent installs, and design tokens use Node builtins. Discovery reports
unclassified manifests within those source trees. Local inspection checks lock
presence, npm root requirements, browser versions, resolutions and authored patch
hashes. It leaves Yarn lock semantics and resolved versions to the package manager
in online mode.

Online mode requires Yarn and npm on `PATH`, registry access and the applicable
Yarn project state. It collects resolved versions through `yarn info` or npm's
lock-only dependency tree, requests advisory evidence, and compares direct public
dependencies with the registry's latest stable tag. It reports declared and
resolved versions alongside retained-version reasons. The checker does not solve
version ranges or prove compatibility; run each project's typecheck, tests and
build after an upgrade. Internal packages and local references are excluded from
public registry requests.

Exit status `0` means the requested checks completed without findings, `1` means
findings remain, and `2` means evidence is incomplete. A failed request leaves
other evidence and findings in the report. Compatibility exceptions explain a
retained release without suppressing its newer version. TypeScript remains on
6.0.3 where typescript-eslint requires a compiler below 6.1 and for shared source
consumers. Node types follow the supported Node 24 runtime.

Authored patches require a path, SHA-256 hash and rationale in the policy. Yarn
builtin compatibility patches belong to the package manager and are reported
separately from authored files. The site's exact transitive resolutions have
matching policy rationales; they reduce advisory exposure within existing major
versions. Remaining advisories stay visible. Neither mode installs dependencies,
changes locks or runs application lifecycle scripts. Online checks are an explicit
maintenance action; CI uses local inspection.
