# Ports, controllers, and HTTP output

Application ports define use-case and outbound dependency contracts. Controllers
invoke use cases through those contracts or dispatch commands. Infrastructure
implements outbound ports and context-specific use-case adapters. Discover existing
implementations rather than insisting all port names end in `PortInterface`.

Ports may be consumed by handlers, console commands, or internal services; they do
not each need an HTTP endpoint. Keep domain logic and persistence out of controllers.
Direct repository and infrastructure injection in existing controllers is relevant
architectural debt, not the pattern to follow for new endpoints.

## HTTP contracts

Follow the context's route naming, authentication/authorization, response helpers,
and identifier conventions. Use a concrete request DTO for `MapRequestPayload`
and validate its inputs. Do not use bare `object` or leave payload types implicit.
Validation in a request DTO does not replace domain invariant enforcement.

Request DTOs can expose immutable promoted fields. Add request DTOs when the input
contract needs them, not solely because of the HTTP verb. Resources map domain data
to explicit response fields using the existing `AbstractResource::from()` contract.
Do not expose mutable state objects or persistence entities as response contracts.

Use OpenAPI attributes consistent with current routes and response schemas. Shared
`OA\Tag` names must have consistent descriptions. Follow the current Nelmio model
references; do not mechanically combine `properties:` with `type: 'object'` on
`OA\JsonContent` or `OA\Items`, which this project's integration does not handle.
Keep serialized payload shapes explicit and version-compatible; controller DTOs
are not permission to place framework objects in persisted event payloads.

## Wiring and verification

Verify interface resolution, concrete implementation, authorization, error mapping,
and resource output together. Resource/model dependencies are intentional data
mapping, while controller repository/infrastructure shortcuts remain debt. Deptrac
currently baselines both; do not treat its baseline as a universal permission.

The project's custom PHPStan rules check OpenAPI tag descriptions and request-payload
typing, not complete DDD conformance. The payload rule currently checks the wrong
parser node type for bare `object`; a clean PHPStan result alone does not prove this
contract. Keep that checker defect as separate follow-up work.

Current references: `src/Auth/Interface/Controller/Totp/TotpController.php`,
`src/Auth/Application/Port/TotpVerifierInterface.php`,
`src/Playlist/Interface/Request/CreatePlaylistRequest.php`, and
`src/Playlist/Interface/Resource/PlaylistResource.php`.
