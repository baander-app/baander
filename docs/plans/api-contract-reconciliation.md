# Reconcile API documentation, identifiers and clients

## Scope and evidence

The roadmap's original missing-route finding predates the delivered bidirectional
route coverage and export/client drift gates. Current source inspection finds
244 exported paths and 313 operations, with no missing API operation. Preserve
those gates and qualify them again in the CI image.

Remaining defects are semantic: fourteen exported component fields and three
Discovery inline/path schemas label NanoIDs as UUIDs. The canonical developer
guides contain obsolete routes and identifier examples, and deny the existence
of the checked-in specification. The embedded player's remix consumer expects a
`data` wrapper around a transcode creation response that returns the resource
directly.

## Changes

- Correct verified `PublicId` schemas to strings of exactly 21 characters matching
  `^[0-9a-zA-Z_-]{21}$`. Cover Activity, Auth, Discovery, Party and Transcode resource
  and request schemas, including the unused TranscodeJobResource declaration.
  Preserve nullable fields and genuine UUID contracts, particularly passkey IDs
  and UUID route parameters. Add focused schema regressions tied to the runtime
  value object and explicit UUID counterexamples.
- Correct the additional source-verified identifier contracts: song `albumId`
  accepts the public ID or the existing UUID fallback; playlist add/reorder inputs
  require song UUIDs; lyrics application takes a song public ID. Replace invalid
  identifier examples and cover the unused PartyMemberResource declaration too.
- Regenerate `openapi.json` in the CI image and regenerate the web Orval client
  from that output. Keep the existing routes and response shapes unchanged.
- Correct the embedded remix consumer to read the actual creation response and
  add a regression using that response shape. Preserve its request contract and
  HTTP-error handling.
- Correct the canonical API reference, glossary/shared-kernel identifier guidance,
  Party and Shared endpoint tables, and export-command guide from current source.
  Distinguish public IDs, UUIDs, integer route IDs and protocol identifiers. Keep
  external-service examples, tutorials and historical audit records distinct from
  application endpoint contracts. Preserve valid pagination examples and existing
  security requirements.
- Reconcile the roadmap's historical route gap with the delivered checks, record
  this verification, and update the current handoff after the previous merge.

React Native and Android remain deferred by the user's standing instruction.
Record their observed hand-written client drift as deferred work. This task does
not change schemas in PostgreSQL, runtime identifier generation, API routes,
authentication policy or dependencies.

## Verification and delivery

Review this plan independently before implementation. Run the relevant identifier,
Discovery, OpenAPI export and route/schema tests in disposable CI-image stacks;
verify platform requirements, PHPStan, Deptrac, container lint and export parity.
Run web generation parity, typechecking and tests, plus embedded player checks for
the changed consumer. Keep backend service checks serial and disposable.

Simplify and independently review the final code and documentation. Apply justified
findings and prose-fix, record actual counts and limitations in ROADMAP.md, and
commit locally. The unavailable compound-engineering skills use the documented
manual workflow. Publishing remains a separate request.
