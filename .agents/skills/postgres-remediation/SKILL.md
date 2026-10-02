---
name: postgres-remediation
description: Audit and remedy PostgreSQL schema, migration, query, and connection anti-patterns using the PostgreSQL Don't Do This guidance. Use when changing or reviewing Baander PostgreSQL persistence or preparing corrective migrations; not for SQLite/rqlite registry storage.
---

# PostgreSQL remediation

Apply the [PostgreSQL guidance](https://wiki.postgresql.org/wiki/Don%27t_Do_This)
to the affected persistence path. Read [review and migration notes](references/remediation.md)
before proposing a remedy. Check the linked source when an exception or current
PostgreSQL behavior is uncertain. If it is unavailable, use the local checklist
and disclose that the source could not be refreshed.

## Establish the actual contract

Read the current SQL, migrations, ORM mapping, and consumers together. Confirm the
server version, whether a migration has already been applied, and the intended
data semantics. Keep parameterized SQL and the existing application port boundaries.
Run the repository-required GitNexus impact analysis before editing existing symbols.

Use targeted searches in the affected paths for candidate anti-patterns. For an
authorized database, run the read-only [catalog audit](scripts/audit_schema.sql),
for example with `psql -X --set=ON_ERROR_STOP=1 --file=<skill-dir>/scripts/audit_schema.sql`
using the configured connection environment. Do not put credentials in command
arguments or logs. Use a disposable database for migration experiments.

The audit reports candidates, not an automatic verdict. It covers physical column
types, defaults, names, inheritance, rules, and encoding. It does not parse queries,
follow types hidden behind domains, assess business constraints, or inspect host
authentication. Review those separately. Never treat an empty report as full compliance.

## Decide and implement

For each relevant finding, record the location, data meaning, observed risk,
recommended change, and verification. Distinguish an actual defect from a documented
exception. A deliberate field-length constraint is different from an arbitrary ORM
default. Read-only review does not authorize changing an external database.

Implement the requested scope in reviewable changes. For already committed or
deployed schemas, prefer a forward migration; do not rewrite history and leave
installed databases inconsistent. Synchronize ORM metadata, SQL, fixtures, and
affected contracts. Never mass-replace type names or silently reinterpret data.

Plan locks, concurrent writers, backfill size, index dependencies, and rollback or
roll-forward recovery. Avoid destructive down migrations that pretend lost precision
or deleted data can be recovered. Preserve existing identifiers and relationships.
Keep database changes local or on disposable instances unless deployment is authorized.

## Verify and report

Test on the project's actual PostgreSQL version with representative existing data,
not just an empty schema. Check generated defaults, constraints, sequence state,
and schema introspection after upgrading. Test transaction failures using a second
connection so visibility and rollback claims come from the database.

For timestamp changes, test fractional seconds and relevant timezone boundaries.
For query changes, include NULL cases and boundary values; inspect query plans when
performance motivates the change. For identity changes, insert after seeded IDs.
Run affected application tests and required quality checks without suppressions.

Report what was changed, what passed, applicable exceptions, and any unresolved
findings. Do not claim database-wide compliance from a targeted audit. Follow the
repository's normal change-scope check before a commit.
