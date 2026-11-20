# Application Development

For a first backend change, follow [Adding a Feature](adding-a-feature.md).
Use the references below when working on a specific layer, an API client or the
web frontend. They explain the conventions the walkthrough uses.

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
| [API Authentication](api-authentication.md) | DPoP proofs, password and passkey login, token refresh, fingerprint binding and revocation |
| [OAuth Flows](api-oauth.md) | Client-facing authorization-code, PKCE and device flows |
| [OAuth Client Authentication](api-oauth-client-authentication.md) | Client authentication methods and administration requests |
| [Session and Access Behavior](session-and-access-behavior.md) | Session revocation, proof validation and access checks behind operator actions |

## Frontend & Clients

| Guide | Scope |
|-------|-------|
| [Frontend Development](frontend-development.md) | React 19, TypeScript, Vite, styled-components, TanStack Query, Orval client generation, Zustand stores, Electron's native Bånder Audio Core binding, and UI primitives |
| [Video Playback Controls](video-playback-controls.md) | Seek commands, playback generations and client recovery |
