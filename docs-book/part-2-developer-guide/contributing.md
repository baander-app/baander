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

When you change code, update the corresponding documentation:

| Code Change | Documentation to Update |
|-------------|------------------------|
| Add or modify a CLI command | Run `/update-command-docs` to regenerate [CLI Reference](../part-1-operator-guide/commands/README.md) |
| Change a DDD convention | Update `.claude/rules/ddd-*.md`, then regenerate [Coding Conventions](coding-conventions.md) |
| Add or change an environment variable | Update `.env.example`, then update [Configuration](../part-1-operator-guide/configuration.md) |
| Add or change an API endpoint | Update [API Reference](api-reference.md) |
| Add a new bounded context | Update [Architecture Overview](architecture.md) and [Shared Kernel](shared-kernel.md) |
| Change the build process | Update [Development Environment](development-environment.md) and [Getting Started](../part-1-operator-guide/getting-started.md) |
| Add a CLI command | Run `/update-command-docs` to regenerate the [CLI Reference](../part-1-operator-guide/commands/README.md) |

### Convention Synchronization

`.claude/rules/ddd-*.md` files are the authoritative source for coding conventions. When conventions change:

1. Update the relevant rules file
2. Regenerate `coding-conventions.md` from the rules files
3. Both the rules file and the human-readable doc should reflect the same conventions

## Running Tests

See the [Testing](testing.md) page for the full test suite guide.
