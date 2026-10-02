---
name: backend-scaffold
description: Create or extend Baander backend aggregate/repository stacks and API endpoints using current context contracts and working service implementations. Use for requested new backend features, not reviews or unrelated schema rewrites.
---

# Backend scaffold

Discover the target context's current code before generating files. Reuse existing
models, ports, handlers, resources, and wiring where they fit. Read the relevant
[architecture rules](../../rules/architecture-rules.md) and companion rules.
Run required GitNexus impact analysis before changing existing symbols.

Choose the relevant recipe; a complete feature may need both:

- [Aggregate and repository](references/entity-stack.md): new domain/persistence stack.
- [Endpoint](references/endpoint.md): HTTP input, application contract, implementation,
  output mapping, and OpenAPI integration.

Use the [PostgreSQL skill](../postgres-remediation/SKILL.md) for mappings, queries,
connection changes, or migrations. Database delivery scope must be explicit: generated
mappings alone do not install a schema. Do not copy existing persistence debt into
new output or rewrite applied migrations.

Infer fields and behavior from the request and existing contracts. Ask only when
missing semantics matter; preserve authorization for reversible local implementation.
Do not impose a confirmation step when the user already requested the concrete work.
Coordinate ownership of shared configuration and interfaces with the lead.

Deliver working implementations and real service resolution, not aliases pointing
to placeholders. Run syntax/static checks and focused behavior tests appropriate to
the change. Verify container/routes when wiring changes. Report changed paths,
validation, and any explicit boundary of the deliverable.
