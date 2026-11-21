---
title: Native Spec OpenAPI - Plan
type: refactor
date: 2026-11-21
topic: native-spec-openapi
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Native Spec OpenAPI - Plan

## Goal Capsule

**Objective:** Maintain and publish Baander's API documentation through swagger-php's native Spec API, with the existing published contract intact.

**Means:** Convert application declarations to `OpenApi\Spec`, assemble and resolve native declarations through public APIs, then run the native augmenters and compiler, and replace the Nelmio ApiDoc exporter with a native Symfony integration.

**Authority:** The user's explicit rejection of deprecated OpenAPI classes overrides the earlier cinema-plan U13 bridge decision. This is the replacement plan for that work. The completed cinema and director behavior remains accepted groundwork.

**Execution profile:** One coordinated delivery on `refactor/native-spec-openapi`, based on `08aa25b431a850b89096102ec3c481c3a1b048bd`. Workers own separate conversion areas; the lead owns shared configuration, generated artifacts, authoritative checks and local commits. Push only when requested. Source implementation begins after `ce-doc-review` passes this plan.

**Stop conditions:** Stop cutover on any unexplained contract difference or use of deprecated OpenAPI classes in application declarations or the actual export process. Investigate and correct within this contract; do not retain the old pipeline as a fallback.

## Product Contract

### Problem Frame

The application currently declares most documentation through deprecated `OpenApi\Attributes`. The recent Spec parameter adapter still converts native parameters into deprecated annotations. Installed Nelmio ApiDoc and its latest stable release both require that legacy object graph. Moving deprecated calls into another adapter would retain the problem the user asked to remove.

### Requirements

- R1. Application documentation declarations use supported native Spec classes. Remove direct application use of deprecated OpenAPI Attributes, Annotations, Context, Serializer, Analysis and Generator APIs, including reflection helpers and test fixtures.
- R2. Actual CLI and HTTP document generation runs exclusively through the native Spec pipeline. It must not autoload the deprecated OpenAPI pipeline or consume the previous JSON document as generation input.
- R3. Preserve the published document: all 279 paths and 172 component schemas, operation identifiers, request and response shapes, constraints, defaults, nullability, references, descriptions, tags, security, binary/SSE content and component names. Preserve generated client behavior and types. A migration is not authorization to strengthen or weaken the API contract.
- R4. Preserve `GET /api/doc.json`, the existing export command and its options, export/check behavior and existing development generation commands. The active documentation entrypoint is JSON; no documentation UI or new external asset dependency is required.
- R5. Remove obsolete Nelmio ApiDoc services, bundle registration, dependency, configuration and the Spec-to-legacy adapter after replacement works. Retain independent Nelmio Cors functionality.
- R6. Update authoritative development guidance and static checks so future declarations use the native integration. Keep movie, director, authentication, scheduler and other application behavior unchanged.

### Acceptance Examples

- AE1. Exporting in a fresh process yields the same normalized document as the pre-migration baseline, including director paths and movie timeline JPEG metadata; no deprecated OpenAPI class is loaded.
- AE2. The document retains authentication request requirements, anonymous operation security, nested playback command arrays, nullable media fields, preference object envelopes and backed-enum string values.
- AE3. `GET /api/doc.json` and the CLI return equivalent documents through the same native exporter; generation does not require an old JSON file to exist.
- AE4. A new request/resource is documented with native class references and explicit schemas, and the Tag rule recognizes native Tag attributes.

### Scope and Exclusions

The scope includes every application declaration needed by the native document, the exporter and its callers, affected tests, the Tag rule and authoritative documentation. It excludes endpoint behavior changes, new API fields, a generic replacement for all Nelmio inference features, documentation UI work, PostgreSQL changes, `ui/rn`, Essentia and unrelated dependency upgrades.

### Settled Decisions

- Use native Spec end to end, including a nondeprecated pipeline entrypoint. The earlier U13 Serializer/Annotations boundary is superseded by the user's explicit instruction; it is removed rather than relocated.
- Replace Nelmio ApiDoc rather than upgrade it: latest stable v5.14.0 still exposes deprecated annotation types throughout its public generator/model interfaces.
- Supply explicit native schemas for the 21 ordinary request DTOs whose schemas Nelmio currently infers. Their current fields and published constraints are bounded; no new general validator inference framework is needed.
- Preserve existing explicit preference and envelope schemas as authoritative. Removing Nelmio Ignore must not expose constructor implementation details.

## Planning Contract

### Verified Baseline and Evidence

The baseline is tracked `openapi.json` at `08aa25b431a850b89096102ec3c481c3a1b048bd`, preserving the verified cinema/director checkpoint. Its saved comparison copy is 1,412,062 bytes with SHA-256 `f81aabd1a57aa3153dd0fcacaeac18975fc88474551e0d39c0435d71650d5683`; preserve it outside the worktree before conversion and record comparison results here when implemented. This artifact is a test oracle only and never a runtime source.

Read-only source inventory on 2026-11-21 found 264 application files using deprecated Attributes: 93 controllers, 83 resources, 81 requests, five DTOs and two other files. The bridge adds one more source file. Fourteen additional source files need native schema coverage, giving 279 application source files before replacement integration files. Counts describe source scope, not expected new abstractions.

There are 863 `Nelmio\ApiDocBundle\Attribute\Model` constructions across 97 files, all supplying only `type`. The model/request closure contains 172 distinct classes/enums; their short names exactly match the 172 published component names without collisions. There are no application-specific Nelmio model describers, alternate model names or serialization groups to reproduce. Preserve these names and test the complete set; do not build speculative naming machinery.

The 21 request DTOs missing class-level schemas contain 100 constructor fields: 49 without defaults, 51 with defaults and 29 nullable. Published required lists match the 49 fields without defaults. Preserve published behavior explicitly, including optional `SubscribeCountryRequest::countryCode` despite NotBlank, nullable fields, and object-versus-array defaults. `LiveFamily` is the remaining unannotated component; its native schema must retain its 21 backing strings rather than enum case names.

Twenty-two Nelmio Ignore attributes occur in six classes: ApiError, PaginatedResponse, CursorPaginatedResponse and the three Save*PreferencesRequest classes. Their explicit schemas describe the public envelope/payload. Constructor fields must not be inferred into those schemas during removal.

Installed swagger-php is 7.0.0; installed Nelmio ApiDoc is 5.13.2. Nelmio's generator, model/type describers, route describers and renderer all hard-type deprecated annotations. The same restriction remains in [v5.14.0](https://github.com/nelmio/NelmioApiDocBundle/releases/tag/v5.14.0), released 2026-11-17; see its [generator](https://github.com/nelmio/NelmioApiDocBundle/blob/v5.14.0/src/ApiDocGenerator.php) and [model interface](https://github.com/nelmio/NelmioApiDocBundle/blob/v5.14.0/src/ModelDescriber/ModelDescriberInterface.php). Installed `Builder::setMode()` and `Builder\Mode` are themselves marked deprecated since 7.0, despite the modes guide recommending them. The replacement therefore composes public Assembler, Resolver, native augmenters and OpenApi30Compiler directly, without subclassing Builder or invoking a legacy build mode. The official [migration guide](https://zircote.com/swagger-php/guide/migrating-to-v7.html) and [modes guide](https://zircote.com/swagger-php/guide/modes.html) establish the native API and separate legacy modes.

An off-checkout PHP probe used Builder, Mode::SPEC, native root/operation/parameter/JSON/schema classes and a class reference to DirectorResource. It validated successfully, matched the baseline DirectorResource schema and response exactly, adopted the referenced resource without supplying it as a source, emitted no warnings and loaded no deprecated OpenAPI classes. A second off-checkout probe composed public Assembler, Resolver, native augmenters and OpenApi30Compiler directly, without Builder/Mode. It produced the same successful equality results, no diagnostics and no deprecated classes loaded. This establishes native class-reference and nondeprecated pipeline feasibility; it does not establish full route or request parity. U1 supplies those proofs before mass conversion.

`config/routes/nelmio_api_doc.yaml` activates only `/api/doc.json`; UI routing is commented. `src/Command/ExportOpenApiSpecCommand.php` and three existing test areas depend on Nelmio RenderOpenApi. `config/packages/nelmio_api_doc.yaml` owns the API title/version, bearer/OAuth security, Accept-Language component and area selection. These declarations move into the native exporter without semantic changes.

### Technical Decisions

- KTD1. Build with public `OpenApi\Assembler`, `OpenApi\Resolver`, the native `Utils\Pipeline` augmenters and `Compiler\OpenApi30Compiler`, native root metadata and Spec attributes/class references. Configure the native augmenter/merger sequence used by the installed Builder through their public constructors; reuse their implementations. Do not invoke deprecated `Builder::setMode`, `Builder\Mode`, legacy Result accessors or a protected Builder subclass. Use public Specification/assembler/compiler extension points for Symfony route augmentation. The runtime reads source/reflection and the router, never a baseline artifact.
- KTD2. Preserve source-owned operation declarations. Replace old operation attributes with `Spec\Operation\Get` and peers; JsonContent becomes `Spec\MediaType\Json`; flat Property/Items/AdditionalProperties declarations become native schema structures. Replace `new Model(type: X::class)` with native class references. Use current native constructor names, including `component`, rather than deprecated alias arguments.
- KTD3. Add explicit native schemas to the ordinary gap DTOs and the backed enum. Preserve current required lists, defaults, array item schemas, constraints and nullable composition from the actual published contract and source validation. Existing explicit metadata takes precedence. Do not add constraints merely because source validation could describe them more strictly.
- KTD4. A small Symfony integration selects documented routes using existing area semantics and supplies actual route paths/methods and derived path parameters where currently inferred. It preserves existing operation IDs, explicit parameter overrides, class-level tags, operation security and request-payload references. Explicit source schemas cover request fields; this integration does not recreate Nelmio's validator/model engine.
- KTD5. Use a single native exporter service for the command and JSON endpoint. Keep current command options, formatting, failure status and output checks; preserve endpoint content type and routing/security behavior. Remove the old exporter, adapter and ApiDoc bundle after the native service passes equivalence.
- KTD6. Fail verification on any deprecated API import/attribute/reflection usage in owned application/test code and on any deprecated OpenAPI class autoload in a fresh real export. Vendor files may contain legacy code, but the exported application document must not invoke it.

### Ownership and Sequencing

U1 establishes concrete native declaration patterns and route augmentation interfaces. U2, U3 and U4 then run in parallel with non-overlapping file ownership. The integration owner coordinates references and owns U5. U6 follows declaration conversion; U7 validates the combined result. All units form one delivery. The lead serializes CI/preview lifecycle and owns shared configuration and generated artifacts.

The exact new integration class names may be selected in U1 to fit existing Shared Infrastructure conventions. Keep native machinery in Infrastructure. Define a small framework-free export port under `src/Shared/Application/Port/` for the command and new Interface JSON controller; the controller must not import the Infrastructure implementation. The lead wires its service alias. Application/domain code receives no OpenAPI framework dependency merely to generate documentation. Existing scheduler schema reflection is updated in place because it has a real runtime consumer.

## Implementation Units

### U1. Establish Native Export and Representative Proofs

**Owner:** Integration worker owns `src/Shared/Infrastructure/OpenApi/`, the application export port and focused new tests. Shared configuration remains lead-owned; representative proof fixtures do not edit bulk conversion files. **Dependencies:** Reviewed plan.

Create the framework-free application export port and native exporter/root metadata and bounded Symfony route augmentation under `src/Shared/Infrastructure/OpenApi/`. Establish native declaration examples before parallel conversion. Preserve title/version/security schemes, default bearer security, OAuth flow/scopes, Accept-Language component and area selection from current configuration. Root metadata must come from maintained source/config, not generated JSON.

Prove representative real source conversions: DirectorResource and its list response; authentication request/anonymous security; DeliveryRequest nullability/defaults; ControlPlaybackRequest nested arrays/constraints; LogSqlRequest nested object default; preference/envelope Ignore removal; LiveFamily backing values. Include a Symfony route with derived required path parameter, explicit parameter override and request-payload schema reference. Compare their complete native serialized contracts with the pinned baseline.

**Verification:** Focused native tests and fresh-process deprecated-class check pass; proof export has no warnings. Record actual supported extension points and declaration examples in the unit handoff. If a public API assumption fails, correct this design before mass conversion.

### U2. Convert Controller Declarations

**Owner:** Controller worker. **Dependencies:** U1 patterns.

Own the 93 controller files identified by deprecated Attributes inventory, including MovieController and MovieDirectorController. Convert operations, parameters, responses, content, headers, tags, schema composition and model references. Preserve parameter order and overrides, operation IDs, anonymous security, binary/SSE responses and request-body required flags. Do not change controller execution. Remove remaining legacy Model imports.

**Verification:** Syntax/scoped formatter and focused declaration tests; integration owner checks complete paths/operations against baseline. Report any actual contract discrepancy before changing it.

### U3. Convert Resources and DTO Envelopes

**Owner:** Resource worker. **Dependencies:** U1 patterns.

Own the 83 resource files and five existing DTO files identified by deprecated Attributes inventory. Convert nested schemas, items, additionalProperties and class references. Keep the existing public envelopes authoritative. Remove their Nelmio Ignore attributes while proving no constructor fields enter the document. Preserve public-ID constraints, required fields, nullable fields and component names.

**Verification:** Native resource reflection tests and complete component comparisons for converted resources; no dangling class references or duplicate component names.

### U4. Convert Requests and Runtime Schema Reflection

**Owner:** Request worker. **Dependencies:** U1 patterns.

Own the 81 existing request files plus the 13 additional ordinary request files, `src/Shared/Application/LiveUpdates/LiveFamily.php`, `src/Lyrics/Application/Command/BulkFetchLyricsSchedulerParams.php` and `src/Scheduler/Domain/Model/SchedulerParameterSchema.php`. Coordinate exact DTO ownership with U3 before edits; existing DTO envelopes remain U3-owned.

Add complete explicit schemas to all 21 gap request DTOs, covering their 100 fields from published behavior and current validation/defaults. Preserve the three explicit preference schemas while removing Ignore attributes. Update scheduler reflection from legacy Property/Generator helpers to native property/schema metadata and native undefined semantics, retaining runtime scheduler parameter output.

**Verification:** Focused request/enum/scheduler tests, exact gap-schema comparisons, explicit default/required/nullability assertions and no deprecated reflection helpers.

### U5. Cut Over CLI and HTTP Export

**Owner:** Integration worker plus lead for shared files. **Dependencies:** U1–U4.

Wire the native service into `src/Command/ExportOpenApiSpecCommand.php` and a native JSON controller for the existing `app.swagger` route. Replace RenderOpenApi dependencies in `tests/Unit/Command/ExportOpenApiSpecCommandTest.php`, `tests/Integration/OpenApiSpecTest.php` and `tests/Integration/TranscodeStartupUnavailableTest.php`. Remove `SpecParameterRouteDescriber.php` and its legacy-focused unit test. Remove its service tag, obsolete Nelmio ApiDoc service aliases/configuration, bundle registration and Composer dependency; update the lockfile with only the required dependency resolution. Remove the obsolete ApiDoc recipe entry from `symfony.lock` and regenerate tracked `config/reference.php` after bundle removal. Preserve Nelmio Cors and unrelated configuration. Keep generation command names and options stable.

**Verification:** Actual container service compilation, CLI export/check tests and HTTP JSON route tests pass with old artifacts unavailable as runtime input. Fresh-process export loads no deprecated OpenAPI pipeline. The old adapter and obsolete ApiDoc wiring are absent.

### U6. Update Rules, Guidance and Regression Guards

**Owner:** Integration worker or a separately assigned documentation worker after conversion. **Dependencies:** U2–U5.

Update `packages/baander-phpstan-rules/src/Rules/ConsistentOpenApiTagDescriptionRule.php` and its tests to native Tag attributes. Convert legacy schema reflection in both PublicIdSchemaTest files, MatchComparisonResourceTest and `tests/Unit/UserPreference/Interface/Request/SidebarSchemaTest.php`, including its nested legacy Items declarations. Update `.agents/rules/ddd-ports.md` and directly affected README, CLI/API-generation developer documentation to native class references, explicit DTO schemas and the actual exporter. Preserve historical plan evidence; link the superseded cinema U13 decision to this plan. Add scoped static usage and runtime autoload regression checks for the user's deprecation requirement.

**Verification:** Rule tests and scoped formatting pass; prose-fix checks documentation. Guidance contains no instruction to add Nelmio Model or deprecated OA declarations. Record delivery and authoritative gate counts in ROADMAP.md through the lead.

### U7. Verify the Full Contract and Complete Review

**Owner:** Lead. **Dependencies:** U1–U6.

Run full normalized document comparison against the pinned baseline and retain readable differences for diagnosis. Compare all document content, not only path/schema counts. Regenerate OpenAPI and client through normal commands; investigate every generated type/operation change. Verify HTTP and CLI agreement, clean-checkout service wiring and absence of runtime dependence on old generated artifacts. Run ce-simplify-code on converted patterns/integration and ce-code-review on the complete change; apply justified findings, then repeat only affected checks.

**Verification:** Authoritative CI image checks, PHPStan and Deptrac are green; all published contract assertions pass; final generated artifacts and docs match implementation. Lead records exact suite counts and any justified mechanical JSON ordering differences inline.

## Verification Contract

The baseline comparison recursively normalizes object-key order only. Array order, required lists, parameter order, enum values, scalar types, nulls and default object/array distinctions remain observable. Preserve the original document separately while generating the candidate; never overwrite the comparison source. No ignored schema differences or output postprocessor may conceal a contract change.

Run focused host tests for pure native assembly, resolution, augmentation, compilation and reflection; run container/service, HTTP, CLI, full OpenApiSpecTest and static gates in the authoritative CI image coordinated by the lead. Existing exporter command tests retain failure behavior assertions. Extend integration coverage to route-derived parameters, request payloads, nullable/defaulted fields, nested collections, enum backing values, anonymous security, explicit envelopes and the JPEG/SSE response exemptions already required by those actual endpoints.

A fresh PHP process performs a real export and inspects declared classes for deprecated OpenAPI families, including Annotations, Attributes, Context, Serializer, Generator, Analysis and Builder\Mode. The direct-use gate also rejects deprecated methods such as Builder::setMode. Pair this with a direct-use source test so unused deprecated declarations cannot escape the runtime check. Test that the native exporter works when the old baseline file is unavailable as generation input. Keep test comparisons on disposable fixtures/local services; no external production requests are needed.

Before completion, run scoped PHP formatting, `git diff --check`, relevant custom-rule tests, all affected OpenAPI/export/request/resource/scheduler tests, PHPStan and Deptrac. Serialize expensive checks and preview lifecycle. Browser retesting is only needed if actual frontend behavior or generated client changes; this plan requires neither to change.

## Definition of Done

- All six product requirements and four acceptance examples have implementation and verification evidence.
- Native export reproduces all 279 paths and 172 schemas and the complete baseline contract; any nonsemantic object-key ordering change is documented.
- Application code, affected tests and actual export use no deprecated OpenAPI APIs. The ApiDoc dependency and bridge are removed; Cors remains functional.
- CLI and JSON entrypoint behavior, fresh-install wiring and generated client remain correct.
- Guidance and static checks describe the native mechanism; ROADMAP.md records the delivered scope and exact verification checkpoint.
- Simplification and independent code review are complete, justified findings are fixed, and the lead owns the final local commits. No push is authorized.
