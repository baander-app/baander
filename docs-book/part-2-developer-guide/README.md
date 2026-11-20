# Part II — Developer's Guide

Start with the [Quick Start](getting-started/quick-start.md) to run an isolated
preview. Then choose the guide for the change you are making. You do not need to
read the manual from beginning to end.

| I need to… | Start here |
| --- | --- |
| Run or configure a local preview | [Developer Setup](getting-started/README.md) |
| Find the code that owns a feature | [Bounded Contexts](contexts/README.md) |
| Follow a request or background job through the system | [Communication Map](architecture/communication-map.md) |
| Add an endpoint or application feature | [Adding a Feature](development/adding-a-feature.md) |
| Write an API client or understand authentication | [API Reference](development/api-reference.md) and [API Authentication](development/api-authentication.md) |
| Change the web client | [Frontend Development](development/frontend-development.md) |
| Choose tests for a change | [Testing](testing/README.md) |
| Prepare a contribution or diagnose CI | [Contributing and CI](contributing/README.md) |

## Architecture

[System Architecture](architecture/README.md) explains the four layers and shared
kernel, then follows communication, real-time state and security boundaries.
Use the [Architecture Overview](architecture/overview.md) for the structure and
[Communication Map](architecture/communication-map.md) for execution flow.

## Development

[Application Development](development/README.md) links the coding conventions,
CQRS and messaging, database naming, PGroonga search, HTTP API and frontend guides.
The [feature walkthrough](development/adding-a-feature.md) connects those topics
in one change. For dependency work, use
[PHP Dependency Maintenance](getting-started/php-dependencies.md).

## Testing & Bounded Contexts

The [Testing guide](testing/README.md) helps select a runner and explains what its
results establish. [Testing Pyramid and E2E Layers](testing/testing-pyramid.md)
describes the boundaries between suites. Test commands use disposable services;
read the runner's prerequisites before starting it.

[Bounded Contexts](contexts/README.md) is the code reference: responsibilities,
domain models, ports, endpoints and relationships. Start there when changing an
existing feature.

## Contributing

[Contributing](contributing/contributing.md) covers branches, commits, review and
documentation maintenance. [Continuous Integration](contributing/continuous-integration.md)
explains the shared Forgejo and GitHub workflows and their failure checks.

## Chapters

| Chapter | Purpose |
| --- | --- |
| [Developer Setup](getting-started/README.md) | Prepare the host, run previews and maintain PHP dependencies |
| [System Architecture](architecture/README.md) | Understand boundaries, communication and shared mechanisms |
| [Application Development](development/README.md) | Implement backend, API and frontend changes |
| [Testing](testing/README.md) | Select and run verification |
| [Bounded Contexts](contexts/README.md) | Find each context's models and interfaces |
| [Contributing and CI](contributing/README.md) | Prepare changes and interpret CI results |

## See Also

- [All manuals](../README.md) links the operator and developer guides.
- [Part I — Operator's Guide](../part-1-operator-guide/README.md) explains running
  an installed instance. Local development uses the preview workflow above.
- [AGENTS.md](../../AGENTS.md) gives agent instructions and links to the canonical
  rules and repository skills.
