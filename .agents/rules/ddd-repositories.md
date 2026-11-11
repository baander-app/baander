# Repositories

Domain repository contracts live under `Domain/Repository/`, including feature
subfolders. They describe aggregate persistence and lookup, without requiring every
repository to share the same delete signature, flush controls, or search methods.
Use existing contracts as the authority when extending a repository.

Infrastructure implements those contracts. Prefer the context's established
`Infrastructure/Doctrine/Repository/` layout for new Doctrine repositories.
Existing paths also include `Auth/Infrastructure/Repository/OAuth/` and
`Auth/Infrastructure/Doctrine/UserRepository.php`; discover implementations by their
fully qualified contract rather than inferring them from paths or short names.
Path differences are migration information, not proof of broken wiring.

## Mapping and writes

Keep entity/model separation. Typical helpers map Doctrine entity to domain state,
synchronize model fields to the entity, and find or create an entity. Their names
are conventions, not requirements for every storage adapter.

Check every persisted field, null value, collection, identity, and timestamp in both
mapping directions. Preserve constructor and reconstitution semantics. Extend
`Searchable` or use `PgroongaSearchTrait` only when that repository's actual search
contract and implementation need them.

Define save/persist/flush behavior from the existing contract. A repository flush
inside an outer transaction does not commit independently. Related producer writes
and outbox insertion must use the same connection and commit boundary; use the
[PostgreSQL skill](../skills/postgres-remediation/SKILL.md) for affected persistence.

## Tables and statements outside the ORM

This section governs `PlaybackStore` and every table or store added after it. The
tables still listed in `schema_filter` (eleven on 2026-11-11) and the existing
stores that run DBAL statements are an unreviewed baseline: this rule neither
approves them nor requires converting them, and they are no precedent for new code.

- A table has a Doctrine entity unless schema comparison cannot represent it. A new
  `schema_filter` entry carries its reason beside it.
- A statement stays SQL only when DQL has no form for it and no DQL statement or
  unit-of-work insert gives the same result without breaking a single-statement
  fence, losing a clock reading or adding a statement to a per-second path. Name
  each such statement with its reason where it stands.
- Rows whose updates are guarded statements map to a `readOnly` entity. The unit of
  work inserts such a row and never computes changes for it; each update is one
  statement whose predicate is the guard; reads return plain rows, not managed
  entities. `PlaybackStore` and the four playback coordination entities are the
  worked example.

## Service resolution

Clients depend on repository interfaces. Verify the resolved service, including
cache decorators and explicit aliases, in the actual container configuration.
Resource loading with autowiring enabled does not by itself guarantee that an
interface resolves. Symfony may infer a unique implementation; multiple candidates
or decoration need deliberate selection. Prefer explicit aliases for new contracts
and decorators, but do not report a working inferred alias as a runtime defect.
Do not generate an alias to a nonexistent implementation.

Reference contracts: `src/Catalog/Domain/Repository/AlbumRepositoryInterface.php`
and `src/Auth/Domain/Repository/UserRepositoryInterface.php`. The latter deletes by
UUID; do not replace that contract with a generic entity-argument template.
