# System Architecture

Bånder is organized into Domain-Driven Design bounded contexts backed by a shared kernel, asynchronous Messenger transports, WebSocket real-time channels, and explicit security boundaries.

## Core Concepts

| Guide | Scope |
|-------|-------|
| [Architecture Overview](overview.md) | DDD bounded contexts, four-layer structure (`Domain`, `Application`, `Infrastructure`, `Interface`), shared kernel, and anti-corruption layers |
| [Communication Map](communication-map.md) | How browsers, the web server, PgBouncer, PostgreSQL, Redis, the worker pair, and external services communicate, and when work is a message |
| [Shared Kernel](shared-kernel.md) | Cross-cutting building blocks: UUID v7, `PublicId`, cursor pagination, PGroonga search, Swoole coroutines, caching, and WebSocket support |
| [Glossary](glossary.md) | Shared DDD, infrastructure, and data-type terminology |

## Real-Time & State

| Guide | Scope |
|-------|-------|
| [Real-Time Patterns](real-time-patterns.md) | WebSocket connection registry, handshake tickets, message protocol, and watch-party synchronization |
| [Live Updates](live-updates.md) | Committed change capture, revision projection, WebSocket `live.changed` hints, browser tab coordination, and draft preservation |

## Security & Threat Models

| Guide | Scope |
|-------|-------|
| [Application Threat Model](application-threat-model.md) | Trust boundaries, DPoP/WebSocket/media controls, rate limiters, proxy identity, and outbound destination policies |
| [Registry Threat Model](registry-threat-model.md) | Security boundaries, transport controls, and verification for the C++ server-discovery registry |
