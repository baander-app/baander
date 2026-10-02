# Review and migration notes

## Source checklist

Source: [PostgreSQL wiki: Don't Do This](https://wiki.postgresql.org/wiki/Don%27t_Do_This),
reviewed 2026-10-02. This is a concise checklist; consult the source for exceptions.

- Choose UTF-8 rather than `SQL_ASCII`.
- Let `psql` request authentication when needed; omit `-W`/`--password`.
- Prefer supported constraints, triggers, and native partitioning to rules or table inheritance.
- Review `NOT IN` for NULL behavior; prefer `NOT EXISTS` for anti-joins.
- Use lowercase, underscore-separated identifiers.
- Use half-open time ranges rather than `BETWEEN`.
- Store instants as `timestamptz`, including UTC instants.
- Avoid `timetz`, `CURRENT_TIME`, and explicit timestamp precision.
- Avoid textual numeric timezone names; check offset interpretation.
- Prefer `text`; avoid padded `char(n)`. Justify bounded `varchar(n)` with a real constraint.
- Use `numeric` or a suitable integer representation rather than `money`.
- Use identity columns for new generated integer keys, rather than serial types.
- Avoid production `trust` authentication; prefer authenticated connections such as SCRAM.

## Baander remediation decisions

An audit should identify the runtime schema as well as its source. Historical
migrations, new-install scripts, Doctrine's generated SQL, and hand-written tests
can disagree. A fix must not pass only because a fixture hides that disagreement.

**Generated IDs.** Converting an existing serial column is not a text substitution.
Inspect its sequence ownership, dependencies, privileges, existing values, and next
value. Preserve keys and foreign keys. Use a forward migration if the original has
been applied, prevent races with writers while changing allocation, and verify
the next insert cannot reuse an existing ID. Do not reset a shared sequence without
accounting for every consumer.

**Instants.** Establish the timezone represented by old timezone-free values from
the producer code and deployment configuration. Do not assume UTC merely because
the column lacks a timezone. Make the conversion zone explicit once established;
if the historical zone is unknown, report the ambiguity rather than inventing it.
Check Doctrine-generated precision as well as the mapping type.

**Text.** Keep limits that enforce an actual contract. If replacing an arbitrary
limit, review indexes, validation, API schemas, and clients. Use explicit checks
when a format or exact length is required. Do not remove a security-related input
bound merely to eliminate a catalog finding.

**Transactions.** Domain writes and outbox insertion must use the same connection
and commit boundary. Doctrine flushes inside an outer transaction are not independent
commits. On rollback, discard stale managed state; reset a closed manager before
reuse. Do not put a blanket transaction around the relay: successful events and
retry records must survive an aggregate error from another event in the batch.
External sends and session-local effects need explicit after-commit handling.

**Authentication.** Inspect the effective server configuration and first matching
host rule. Avoid assuming a container environment variable proves authentication
is enabled. Keep credentials out of reports. Preserve explicitly isolated test
configuration rather than copying it into production.
