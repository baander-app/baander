# Aggregate and repository stack

Read an existing aggregate/state, repository contract, implementation, and persistence
mapping in the target context. `Catalog/Domain/Model/Album.php` is a reference shape,
not a mandatory schema for every entity. Read
[domain models](../../../rules/ddd-domain-models.md) and
[repositories](../../../rules/ddd-repositories.md).

Establish the consistency boundary, creation invariants, mutable fields, collections,
identity contract, timestamps, and required lookup/write operations. Do not generate
a repository or Doctrine entity merely because a new value type is requested.

For a new aggregate, create a final mutable state class and final aggregate with
private state constructor, semantic creation factory, reconstitution, getters, and
actual domain mutations. Keep never-reassigned identity fields readonly. Persisted
state includes all rehydrated fields; derived fields need deliberate consistency.
Do not invent mutations solely to satisfy a template or expose state as an API DTO.

Define repository operations from consumer needs and context conventions. Preserve
existing delete signatures and flush controls. Add search contracts/traits only when
search is required, not automatically. Prefer current canonical repository placement
for new Doctrine adapters; do not move legacy files as an incidental cleanup.

Map entity fields and relationships using current custom UUID/public-ID types and
the PostgreSQL guidance. Check both mapping directions, constructor defaults, nulls,
collections, and identifier preservation. Existing timezone-free instant types are
not approved defaults for new persisted instants.

Wire the actual repository implementation, including decoration if needed. Verify
container resolution instead of assuming wildcard loading selects the correct
interface implementation. Include a migration only within the requested persistence
scope; otherwise state precisely what remains necessary to install the mapping.

Use focused tests for creation invariants, mutations, rehydration, and meaningful
repository behavior. Database integration tests must use disposable local services
and the project test-domain rules. Syntax checks alone do not prove persistence or
field completeness.
