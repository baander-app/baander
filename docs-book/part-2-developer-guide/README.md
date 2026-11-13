# Part II — Developer's Guide

Understand the architecture, write code following project conventions, and contribute to Baander.

## Chapters

| Chapter | Description |
|---------|-------------|
| [Developer Setup](getting-started/README.md) | Local DNS, trusted CA, isolated worktree previews, Makefile workflow, and PHP dependency maintenance |
| [System Architecture](architecture/README.md) | DDD bounded contexts, communication map, shared kernel, WebSocket real-time patterns, live updates, and threat models |
| [Application Development](development/README.md) | Coding conventions, CQRS and messaging, database naming, PGroonga search, HTTP API and DPoP/OAuth 2.0, frontend development, and feature walkthroughs |
| [Testing](testing/README.md) | Disposable PHPUnit runners, browser qualification, live-update capacity, and evidence boundaries |
| [Bounded Contexts](contexts/README.md) | Domain models, ports, API endpoints, and cross-context relationships for each context and the Shared kernel |
| [Contributing and CI](contributing/README.md) | Branch naming, commit style, PR process, documentation maintenance, and shared CI workflows |

## Architecture

| Page | Description |
|------|-------------|
| [Architecture Overview](architecture/overview.md) | DDD bounded contexts, four-layer structure, and shared kernel |
| [Communication Map](architecture/communication-map.md) | How clients, the web application, transports, stores, the worker pair and external services talk, and when work is a message |
| [Shared Kernel](architecture/shared-kernel.md) | UUID v7, cursor pagination, search, Swoole async, caching, WebSocket |
| [Real-Time Patterns](architecture/real-time-patterns.md) | WebSocket connection registry, message protocol, party sync |
| [Live Updates](architecture/live-updates.md) | Committed change capture, shared query refresh, ownership, and recovery |
| [Application Threat Model](architecture/application-threat-model.md) | Trust boundaries, DPoP/WebSocket/media controls, rate limiters, and outbound destination policies |
| [Registry Threat Model](architecture/registry-threat-model.md) | Security boundaries, transport controls, and verification for the C++ server-discovery registry |
| [Glossary](architecture/glossary.md) | DDD, infrastructure, and data type terminology |

## Development

| Page | Description |
|------|-------------|
| [Quick Start](getting-started/quick-start.md) | Path of least resistance for local DNS, a trusted CA, and a running preview |
| [Development Environment](getting-started/development-environment.md) | Docker setup, Makefile commands, IDE config, and Xdebug |
| [PHP Dependencies](getting-started/php-dependencies.md) | Runtime selections, Composer updates, patch reviews and maintenance checks |
| [Coding Conventions](development/coding-conventions.md) | Domain models, value objects, repositories, CQRS, ports, and anti-corruption |
| [CQRS and Messaging](development/cqrs-and-messaging.md) | Commands, handlers, domain events, and async processing |
| [Database Naming](development/database-naming.md) | Index, unique-constraint and foreign-key names in migrations and mappings |
| [Search](development/search.md) | PGroonga full-text search, making a context searchable |
| [Adding a New Feature](development/adding-a-feature.md) | Step-by-step walkthrough for adding an API endpoint |
| [API Reference](development/api-reference.md) | OpenAPI spec, REST patterns, pagination, and resource serialization |
| [API Authentication and OAuth 2.0](development/api-authentication.md) | DPoP proofs, password/passkey login, refresh grace, and OAuth 2.0 PKCE and device flows |
| [Frontend Development](development/frontend-development.md) | React + TypeScript + Vite + styled-components, TanStack Query, Zustand, Electron, and Bånder Audio Core |

## Testing & Bounded Contexts

| Page | Description |
|------|-------------|
| [Testing](testing/README.md) | Disposable PHPUnit runners, browser qualification, live-update capacity and evidence boundaries |
| [Testing Pyramid and E2E Layers](testing/testing-pyramid.md) | Testing pyramid, unit vs functional vs integration boundaries, and the four E2E verification layers |
| [Bounded Contexts](contexts/README.md) | Domain models, ports, API endpoints and cross-context relationships for each context and the Shared kernel |

## Contributing

| Page | Description |
|------|-------------|
| [Contributing](contributing/contributing.md) | Branch naming, commit style, PR process, and documentation maintenance |
| [Continuous Integration](contributing/continuous-integration.md) | Shared workflows on Forgejo and GitHub, caches, image visibility and the operator runbook |

## See Also

- [Part I — Operator's Guide](../part-1-operator-guide/README.md) — Prerequisite reading for developers (you need to run the app before you can develop on it).
- [AGENTS.md](../../AGENTS.md) — Agent instructions and pointers to canonical rules and repository skills.
