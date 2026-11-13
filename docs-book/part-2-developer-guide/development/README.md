# Application Development

Write backend domain logic, CQRS handlers, Doctrine mappings, PGroonga search queries, OpenAPI-documented HTTP endpoints, and React/TypeScript frontend features following Bånder's conventions.

## Backend & Persistence

| Guide | Scope |
|-------|-------|
| [Coding Conventions](coding-conventions.md) | State-object aggregates, value objects, repositories, CQRS commands/handlers, application ports, request DTOs, and admin/CLI parity |
| [CQRS and Messaging](cqrs-and-messaging.md) | Command DTOs, `#[AsMessageHandler]` handlers, domain events, transactional outbox relay, job monitoring, and durable event replay |
| [Database Naming](database-naming.md) | Naming rules for PostgreSQL indexes, unique constraints, foreign keys, and PGroonga indexes in migrations and entities |
| [Search](search.md) | PGroonga full-text search architecture and making a bounded context searchable |
| [Adding a Feature](adding-a-feature.md) | End-to-end walkthrough for adding a new feature across all four DDD layers, migrations, OpenAPI resources, console commands, and tests |

## HTTP API & Authentication

| Guide | Scope |
|-------|-------|
| [API Reference](api-reference.md) | OpenAPI specification export, REST URL conventions, `PublicId` and playback identities, cursor/offset pagination, response envelopes, and resources |
| [API Authentication and OAuth 2.0](api-authentication.md) | DPoP proof handshakes, password and passkey login, token refresh grace, client fingerprint binding, OAuth 2.0 PKCE/device flows, and revocation |

## Frontend & Clients

| Guide | Scope |
|-------|-------|
| [Frontend Development](frontend-development.md) | React 19, TypeScript, Vite, styled-components, TanStack Query, Orval client generation, Zustand stores, Electron native bAAC, and UI primitives |
