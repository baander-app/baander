# API endpoint

Read [ports and HTTP](../../../rules/ddd-ports.md) and the target context's controller,
request, resource, ports, and implementations. Current TOTP reference:
`src/Auth/Interface/Controller/Totp/TotpController.php`. A missing Port directory
does not prove the context still uses ApplicationService classes.

Establish the route, identity/ownership and authorization checks, input validation,
use-case contract, persistence/event behavior, result mapping, and errors. Preserve
existing subresource routes and route names unless changing them is requested.
Reuse an appropriate port/handler rather than making every endpoint a new interface.

Create request DTOs when the input contract needs them. For `MapRequestPayload`, use
a concrete type and meaningful validation; do not use bare `object`. Public immutable
promoted fields fit current DTOs. Domain logic still enforces its own invariants.

Controllers coordinate through ports or command dispatch and established response
helpers. Create the implementation and service alias together. Do not ship an alias
to a nonexistent service or return static data to conceal missing orchestration.
Check synchronous routing and result stamps before reading a command's result.

Resources implement `public static function from(mixed $source): array` using the
project's `AbstractResource` contract. Map selected fields and exclude persistence
entities and mutable state objects. Do not generate pseudo-generic PHP syntax.

Add OpenAPI attributes matching effective routes, request fields, and responses.
Keep repeated tag descriptions consistent. Respect the current Nelmio restriction
on combining `properties:` and `type: 'object'` in `OA\JsonContent` or `OA\Items`.

Verify authorization, validation, missing-resource/errors, response fields, route
registration, and actual service resolution. New write producers need deliberate
transaction/outbox and failure semantics; the existence of a save-and-dispatch
example does not prove those semantics. Run focused functional/integration checks
when mocks cannot exercise the changed boundary.
