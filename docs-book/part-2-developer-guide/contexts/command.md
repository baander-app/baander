# Command

The Command context is a utility context that provides CLI commands not owned by any specific bounded context. It currently contains only the OpenAPI spec export command.

## Domain Models

None — this context has no domain layer.

## Commands & Handlers

| Command | Handler | Purpose |
|---------|---------|---------|
| `ExportOpenApiSpecCommand` | Self-handling | Exports the OpenAPI specification |

This command handles itself — there is no separate handler class.

## Ports

None.

## API Endpoints

None — this context is CLI only.

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | Shared | Kernel infrastructure for spec generation |
| Depended on by | None | |

## Infrastructure

None — the command delegates to Nelmio ApiDoc for spec generation.

The CLI command is documented in the [CLI Reference](../../part-1-operator-guide/commands/app-export-openapi-spec.md).
