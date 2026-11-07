# Part II — Developer's Guide

Understand the architecture, write code following project conventions, and contribute to Baander.

## Architecture

| Page | Description |
|------|-------------|
| [Architecture Overview](architecture.md) | DDD bounded contexts, four-layer structure, and shared kernel |
| [Communication Map](communication-map.md) | How clients, the web application, transports, stores, the worker pair and external services talk, and when work is a message |
| [Shared Kernel](shared-kernel.md) | UUID v7, cursor pagination, search, Swoole async, caching, WebSocket |
| [Real-Time Patterns](real-time-patterns.md) | WebSocket connection registry, message protocol, party sync |
| [Live Updates](live-updates.md) | Committed change capture, shared query refresh, ownership, and recovery |
| [API Reference](api-reference.md) | OpenAPI spec, REST patterns, and authentication flows |
| [Application Threat Model](application-threat-model.md) | Trust boundaries, DPoP/WebSocket/media controls, rate limiters, and outbound destination policies |
| [Registry Threat Model](registry-threat-model.md) | Security boundaries, transport controls, and verification for the C++ server-discovery registry |

## Development

| Page | Description |
|------|-------------|
| [Development Environment](development-environment.md) | Docker setup, Makefile commands, IDE config, and Xdebug |
| [PHP Dependencies](php-dependencies.md) | Runtime selections, Composer updates, patch reviews and maintenance checks |
| [Coding Conventions](coding-conventions.md) | Domain models, value objects, repositories, CQRS, ports, and anti-corruption |
| [CQRS and Messaging](cqrs-and-messaging.md) | Commands, handlers, domain events, and async processing |
| [Search](search.md) | PGroonga full-text search, making a context searchable |
| [Database Naming](database-naming.md) | Index, unique-constraint and foreign-key names in migrations and mappings |
| [Testing](testing/README.md) | Disposable PHPUnit runners, browser qualification, live-update capacity and evidence boundaries |
| [Continuous Integration](continuous-integration.md) | Shared workflows on Forgejo and GitHub, caches, image visibility and the operator runbook |
| [Frontend Development](frontend-development.md) | React + TypeScript + Vite + styled-components, TanStack Query, Zustand, Electron, and bAAC |

## Bounded Contexts

| Page | Description |
|------|-------------|
| [Bounded Contexts](contexts/README.md) | Domain models, ports, API endpoints and cross-context relationships for each context and the Shared kernel |

## Contributing

| Page | Description |
|------|-------------|
| [Adding a New Feature](adding-a-feature.md) | Step-by-step walkthrough for adding an API endpoint |
| [Contributing](contributing.md) | Branch naming, commit style, PR process, and documentation maintenance |
| [Glossary](glossary.md) | DDD, infrastructure, and data type terminology |

## See Also

- [Part I — Operator's Guide](../part-1-operator-guide/README.md) — Prerequisite reading for developers (you need to run the app before you can develop on it).
- [AGENTS.md](../../AGENTS.md) — Agent instructions and pointers to canonical rules and repository skills.
