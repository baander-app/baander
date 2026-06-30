---
date: 2026-05-23
topic: central-audit-log
---

# Central Audit Log System

## Summary

A standalone audit log service (built with Bun) that receives audit events from the PHP application via Unix socket and persists them to PostgreSQL. The service runs as a separate container in docker-compose, providing a decoupled audit trail for all user actions, system jobs, admin operations, and database changes.

---

## Problem Frame

Currently, tracking "who did what" requires adding `user_id` columns to individual tables (`job_monitors`, `system_settings`, `webhooks`, etc.). This scatters audit concerns across the schema, complicates queries, and misses non-database events (API calls, job executions, external interactions). Without a centralized audit trail, debugging production issues, answering compliance questions, and investigating user actions require piecing together data from multiple sources or admitting the information is unavailable.

---

## Requirements

**Event model**
- R1. The system provides an `AuditLog` domain event carrying: actor (user or system), action type, target entity (table name, record ID), timestamp, and optional metadata (old/new values, context)
- R2. Any component can publish audit events by calling `AuditLog::emit()` without coupling to storage details
- R3. Audit events persist to a single `audit_logs` table with foreign key to `users` table (nullable for system-triggered events)

**Capture mechanism**
- R4. PHP components send audit events via Unix socket to the Bun audit service (client library provided)
- R5. The Bun service runs as a separate Docker container with its own lifecycle and health checks
- R6. Communication is fire-and-forget: PHP writes to socket, continues without waiting for acknowledgment
- R7. The Bun service batches writes to PostgreSQL for efficiency while maintaining ordering guarantees

**Query and retention**
- R8. An `AuditLogRepository` provides lookup by user, entity, date range, and action type
- R9. Audit logs support archive-and-tier retention: recent records in hot storage, old data moved to cold storage (implementation deferred to planning)
- R10. An admin endpoint returns audit log entries with filtering support

**Integration**
- R11. Critical user actions emit audit events: login, password change, settings modifications, data deletions
- R12. Background jobs emit audit events when triggered by users (including job UUID for correlation)
- R13. Admin operations emit audit events: user management, system setting changes, webhook configuration

---

## Acceptance Examples

- AE1. **Covers R1, R4.** Given a user changes their password, when the `PasswordChanged` domain event is handled, an audit event is sent via Unix socket to the Bun service with actor=user, action=PASSWORD_CHANGED, target=users:{id}, and metadata including the change timestamp.
- AE2. **Covers R2, R12.** Given a user triggers a transcode job, when the job starts, an audit event is sent to the Bun service with actor=user, action=JOB_TRIGGERED, target=transcode_jobs:{id}, and metadata including job UUID.
- AE3. **Covers R8.** Given an admin queries the audit log for a specific user, when the Bun service's query endpoint is called, it returns all audit entries where `actor_id` matches the user, ordered by timestamp descending.
- AE4. **Covers R3, R5.** Given a system-triggered job (no user actor), when it emits an audit event, the `user_id` column is NULL and the Bun service persists it without blocking the PHP application.

---

## Success Criteria

- Developers can emit audit events from anywhere in the codebase with a single method call
- Audit queries answer "who did what to this record" without joining multiple tables
- Non-database actions (API calls, job triggers) are captured alongside database changes
- The audit queue does not block application workflows under normal load

---

## Scope Boundaries

- **Out of scope:** Automated migration of existing `user_id` columns to audit-based tracking (tables keep existing columns, new tables use audit)
- **Out of scope:** Admin UI for viewing audit logs (API endpoint only)
- **Out of scope:** Real-time audit streaming or webhooks (cold storage archive implementation deferred to planning)
- **Out of scope:** Fine-grained field-level diffing (metadata structure deferred to planning)

---

## Key Decisions

- **Separate Bun service:** Audit log is a standalone package at `packages/baander-audit` (Bun + TypeScript), enabling independent deployment and scaling
- **Unix socket communication:** PHP → Bun communication via Unix socket on a shared Docker volume for low-latency, local-only communication
- **Shared PostgreSQL storage:** The Bun service writes to the same PostgreSQL database as the PHP app, using the `audit_logs` table with foreign key to `users`
- **Explicit event emission:** Components explicitly send audit events (not automatic database triggers), capturing non-database events naturally
- **Nullable user_id:** System-triggered events (jobs, scheduled tasks) have no user actor; NULL allows the same table for both cases

---

## Dependencies / Assumptions

- Docker Compose is used for deployment; shared volumes can be mounted for Unix socket communication
- The `users` table has a primary key on `id` (UUID) for foreign key constraint
- Bun runtime is available or can be added to the project
- The PostgreSQL database accepts connections from multiple containers

---

## Outstanding Questions

### Deferred to Planning

- [R9][Needs research] What retention period defines "recent" vs "cold" storage, and what mechanism moves data between tiers?
- [R4][Technical] What is the wire protocol for Unix socket communication (JSON lines, msgpack, custom)?
- [R11, R12, R13][Technical] Which specific domain events need audit wrappers — should we emit on all domain events or a curated list?
- [R5][Technical] Should the Bun service provide a health check endpoint for Docker compose health checks?
