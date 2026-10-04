# Backend architecture

These rules describe the target architecture. Existing violations and exceptions
must be identified separately; their presence does not authorize new violations.
Read the relevant companion rules before changing backend code. These files are
explicit references from `AGENTS.md`; directory placement does not auto-load them.

## Dependencies and context boundaries

- Domain depends on its own domain and Shared domain contracts and value types.
  Keep persistence, container configuration, and transport adapters outside Domain.
- Application depends on Domain and Application ports. Framework registration such
  as Messenger handler attributes is permitted; it does not permit importing
  infrastructure implementations into business orchestration.
- Infrastructure implements domain repositories and application ports.
- Interface coordinates HTTP or console input through application ports and command
  dispatch. Resources may read domain models to map output; this is not permission
  to inject domain repositories or infrastructure implementations into controllers.
- Cross-context collaboration uses application contracts or domain events, not a
  foreign repository. Doctrine relationships to foreign infrastructure entities are
  existing persistence coupling; report them as debt when relevant.
- Shared is a kernel, not a layer exemption. Shared Domain imports are permitted
  where domain contracts are permitted; Shared Infrastructure remains infrastructure.
  The UUID value type's Symfony UID wrapper is an explicit existing primitive
  dependency. Shared's DBAL outbox repository and compiler pass in Domain remain
  placement debt, not templates for new domain services.

## Evidence and enforcement

`deptrac.yaml` defines internal dependency rules and imports a baseline of existing
violations. A baseline is recorded debt, not approval. Dedicated resource layers
allow mapping their own domain models while controller layers remain restricted.
Some cross-context ports still have policy mismatches; report them rather than
broadening dependencies during an unrelated task.
Radio, Scheduler, QoL, Session, Lyrics, and Filesystem are covered by collectors.
External framework dependencies also require review beyond its internal layers.

Use GitNexus as required by `AGENTS.md`; inspect actual contracts, imports, service
resolution, and callers. Distinguish a naming heuristic from a proven violation.
Do not assume every context has four layers or every port has an HTTP endpoint.

## Persistence and asynchronous work

Use the [PostgreSQL remediation skill](../skills/postgres-remediation/SKILL.md)
for PostgreSQL work, including generated mappings and corrective migrations.
Generate domain aggregate identifiers with the shared UUID v7 default. Token IDs,
public IDs, imported identifiers, and internal persistence sequence keys have their
own contracts; do not reinterpret them as UUID v7. Internal integer keys need a
justified purpose and PostgreSQL identity guidance for new schema.

Prefer `text`, `jsonb`, and timezone-aware instants according to that skill. Real
bounded-length constraints may justify bounded strings. Existing timezone-free
instant mappings, serial keys, and explicit timestamp precision are remediation
candidates; do not copy them as approved defaults or rewrite migration history.

Domain writes and their outbox insertion share a connection and commit boundary.
Do not add a blanket transaction around the whole Messenger bus or outbox relay.
Cache writes and external sends require deliberate commit/failure semantics.

Prefer `App\Shared\Infrastructure\Swoole\Async::sleep()` in shared application
runtime paths. Prevent process work from blocking the request/coroutine event loop:
use the established CPU process pool or a verified coroutine-aware adapter there.
CLI commands and isolated child processes may use blocking process APIs deliberately.
Native coroutine execution still needs bounded execution and cleanup; do not infer
that all standard I/O or process execution is safe under every runtime mode.

## Companion rules

- [Domain models](ddd-domain-models.md)
- [Repositories](ddd-repositories.md)
- [Ports and HTTP](ddd-ports.md)
- [Commands and handlers](ddd-cqrs.md)
