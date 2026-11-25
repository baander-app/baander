# Contributing

Contributions are welcome. This page covers the workflow and conventions for contributing code to Baander.

## Getting Started

1. Set up the [development environment](../getting-started/development-environment.md) and install standalone tooling loaders with `composer tooling:autoload`
2. Select a local CI test container (`BAANDER_TEST_IMAGE`) and run the test suite: `make phpunit`
3. Run static analysis (`make phpstan`) or the full local gate (`make ci`, which runs `composer validate --no-check-version`, `composer normalize --dry-run`, `phpstan`, `deptrac`, `composer check:imports`, and `phpunit`)

## Branch Naming

Planned feature work uses a branch named after the plan file under `docs/plans/` (see [AGENTS.md](../../../AGENTS.md)). Other changes use conventional prefixes:

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
3. Run `make ci` (or `make phpunit` and `make phpstan` in the `BAANDER_TEST_IMAGE` container) — all gates must pass
4. Push and open a pull request
5. Address review feedback

## Code Review Expectations

Reviewers check for:

- **DDD conventions** — new aggregate roots use state objects, dependencies go through ports, and repositories implement domain interfaces
- **Test coverage** — new behavior has tests; domain logic is tested without mocks
- **Pre-release design policy** — while there are no production deployments ([AGENTS.md](../../../AGENTS.md)), favor the intended future design over backward compatibility, remove obsolete paths, and add no compatibility layers or migration fallbacks for hypothetical installations
- **Code style** — matches existing patterns in the codebase

## Documentation Maintenance

When you change code, update the corresponding documentation. The
[operator manual](../../part-1-operator-guide/README.md) explains installation,
routine operation, monitoring and recovery. Update the affected
chapter when a change alters those tasks; keep argument and option detail in the
CLI reference.

| Code Change | Documentation to Update |
|-------------|------------------------|
| Add or modify a CLI command | Use the [documentation-maintainer](../../../.agents/skills/documentation-maintainer/SKILL.md) CLI mode to update [CLI Reference](../../part-1-operator-guide/commands/README.md) (`CommandDocsCoverageTest` checks every `#[AsCommand]` command under `src/`) |
| Add or modify an admin endpoint | Declare `#[CliCounterpart]` or `#[CliParityExemption]` on the admin route (`AdminCliParityTest`) and document the counterpart command in `docs-book/part-1-operator-guide/commands/` |
| Introduce a new entity, named process, or status concept | Add an entry to [CONCEPTS.md](../../../CONCEPTS.md) |
| Deliver a planned item or verification checkpoint | Record the delivered scope, suite counts, and static gates in [ROADMAP.md](../../../ROADMAP.md) |
| Change a DDD convention | Update `.agents/rules/ddd-*.md`, then update [Coding Conventions](../development/coding-conventions.md) |
| Add or change an environment variable | Update `.env.example`, then update [Configuration](../../part-1-operator-guide/configuration/README.md) |
| Add or change an API endpoint | Update [API Reference](../development/api-reference.md) |
| Add a new bounded context | Update [Architecture Overview](../architecture/overview.md) and [Shared Kernel](../architecture/shared-kernel.md) |
| Change the build process | Update [Development Environment](../getting-started/development-environment.md) and [Getting Started](../../part-1-operator-guide/getting-started/installation.md) |

### Convention Synchronization

The files in [`.agents/rules/`](../../../.agents/rules/) are the authoritative source for coding conventions. [AGENTS.md](../../../AGENTS.md) points agents to the applicable rules. When conventions change:

1. Update the relevant rules file
2. Update `coding-conventions.md` by hand to match the rules files
3. Both the rules file and the human-readable doc should reflect the same conventions

## Running Tests

See the [Testing](../testing/README.md) page for the full test suite guide.

## JavaScript Dependency Maintenance

After changing a web dependency, inspect its manifest and owned lock with the
read-only checker or its Composer script aliases (`composer check:js-dependencies:local`,
`composer check:js-dependencies` and `composer check:js-dependencies:advisories`):

```bash
php scripts/check-js-dependencies.php --local-only --format json
php scripts/check-js-dependencies.php --online --format json
php scripts/check-js-dependencies.php --advisories-only --format json
```

Local mode is the default and makes no requests. The
[JavaScript policy](../../../packages/baander-dev-dependencies/resources/javascript-dependencies.json)
classifies ten manifests inside its `scopeDirectories` (`ui/web`, `ui/site`,
`ui/shared`, `ui/design-tokens`, `ui/electron`, `packages/baander-audio-core`, and
`tests/e2e`): web, player, site, shared and the desktop app own independent Yarn
locks; `packages/baander-audio-core` and `tests/e2e` own npm locks. The DPoP and
BlurHash library manifests have no independent installs, and design tokens use Node
builtins. Discovery reports unclassified manifests within those source trees;
`ui/rn/package.json` sits outside `scopeDirectories`. Local
inspection checks lock presence, npm root requirements, browser versions,
resolutions and authored patch hashes. It leaves Yarn lock semantics and
resolved versions to the package manager in online mode.

Online mode requires Yarn and npm on `PATH`, registry access and the applicable
Yarn project state. It collects resolved versions through `yarn info` or npm's
lock-only dependency tree, requests advisory evidence, and compares direct public
dependencies with the registry's latest stable tag. It reports declared and
resolved versions alongside retained-version reasons. The checker does not solve
version ranges or prove compatibility; run each project's typecheck, tests and
build after an upgrade. Internal packages and local references are excluded from
public registry requests.

Advisories-only mode runs the local inspection and then one request per owned lock:
`yarn npm audit --all --recursive --json` for a Yarn lock and
`npm audit --package-lock-only --json --ignore-scripts` for an npm lock. It collects
no resolved versions and compares no releases, so an outdated dependency does not
fail it. It needs registry access and the package managers, but no install: on
2026-11-24, Yarn 4.17.1 audited a copy of `ui/shared` that held only the manifests,
`yarn.lock` and `.yarnrc.yml`. Yarn's deprecation notices appear in the report
without failing it.

An advisory fails advisories-only mode unless the policy's `advisoryExceptions` list
names the same project, package and advisory and gives a reason. An entry has this
shape:

```json
{
    "projects": ["ui/web/package.json"],
    "package": "minimatch",
    "advisory": "GHSA-g3hv-75wg-m249",
    "reason": "Reaches only the linter, which reads repository files."
}
```

`advisory` is the identifier the report prints for the advisory: the GitHub advisory
identifier from the registry's link, or the registry's numeric identifier when it
links none. An excepted advisory stays in the report with its reason. The policy is
rejected, with status `2`, when an entry lacks a reason or names an unclassified
project.

A failed, empty or unrecognized audit response is incomplete evidence, so the mode
exits `2` when the registry is unreachable. The same holds when the package manager
cannot read the lock. A Yarn release refuses a lock in an older lockfile format: on
2026-11-24, Yarn 4.17.1, which writes format version 10, answered the `ui/electron`
lock in version 9 with "This package doesn't seem to be present in your lockfile",
and gave the same answer for a copy of the `ui/shared` lock once its version line
read 9. Run each project with the Yarn release its `packageManager` field pins;
Corepack selects it.

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
versions. Remaining advisories stay visible. No mode installs dependencies,
changes locks or runs application lifecycle scripts. The full online check is an
explicit maintenance action. CI uses local inspection, and the
[Dependency advisories workflow](continuous-integration.md#dependency-advisories)
runs advisories-only mode every day.
