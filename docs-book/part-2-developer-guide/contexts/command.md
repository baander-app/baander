# Command

The Command context is a utility namespace (`src/Command/`) for console commands that do not belong to a single bounded context's `Interface/Console/` layer: OpenAPI specification export, HTML documentation generation, development environment bootstrap, and initial OAuth key generation.

## Domain Models

None — this context has no domain layer.

## Commands & Handlers

Each console command in `src/Command/` is a self-contained Symfony `Command` class with no separate Messenger command or handler.

| Command | Console Name | Purpose |
|---------|--------------|---------|
| `ExportOpenApiSpecCommand` | `app:export-openapi-spec` | Exports or checks (`--check`) the Nelmio-generated OpenAPI specification (`json` or `yaml`) |
| `GenerateDocsCommand` | `app:generate-docs` | Scans `src/` and `docs-book/` to generate the HTML documentation site |
| `SetupCommand` (`src/Command/Dev/SetupCommand.php`) | `app:dev:setup` | Bootstraps the development environment by running migrations, `app:oauth:generate-keys`, `app:auth:setup-clients`, and `app:dev:create-users` in isolated subprocesses |
| `GenerateKeysCommand` (`src/Command/OAuth/GenerateKeysCommand.php`) | `app:oauth:generate-keys` | Generates the initial 2048-bit RSA key pair for OAuth 2.0 JWT signing (`app:auth:rotate-secrets` replaces existing keys) |

## Ports

None.

## API Endpoints

None — this context is CLI only.

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | None | `src/Command/` has no `App\Shared` or other context imports; `SetupCommand` invokes other console commands (`app:oauth:generate-keys`, `app:auth:setup-clients`, `app:dev:create-users`) as subprocesses |
| Depended on by | None | |

## Infrastructure

| Component | Type | Purpose |
|-----------|------|---------|
| `CodebaseScanner`, `HtmlRenderer`, `MarkdownConverter`, `PhpDocLinkBuilder` | `src/Command/DocGenerator/` | Scan bounded contexts, convert `docs-book/` Markdown, build phpDocumentor links, and render HTML for `app:generate-docs` |
| `BoundedContext`, `ClassInfo`, `HandlerInfo`, `RouteInfo` | `src/Command/DocGenerator/Model/` | Data models used by `CodebaseScanner` and `HtmlRenderer` |

`ExportOpenApiSpecCommand` delegates to Nelmio ApiDoc (`RenderOpenApi`) for specification rendering.

The OpenAPI export command is documented in the [CLI Reference](../../part-1-operator-guide/commands/app-export-openapi-spec.md).
