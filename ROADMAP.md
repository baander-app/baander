# Baander roadmap

Updated: 2026-10-04. This is the working delivery record for the remediation,
registry, and web-state plans. Update it when scope changes or a stage is verified.
Completed code is not proof of production or performance qualification.

## Latest verified backend batches

Signed delivery now shares full-query signature validation across manifests and
segments. The production-firewall matrix passes 25 cases (313 assertions), with
182 related unit tests (797 assertions). It covers seven delivery routes, five
actor types, expiry/tampering, revoked credentials, and cross-library signing.
Valid signed URLs remain bearer capabilities until expiry.

Webhook delivery uses encrypted original secrets only. Hash-only signing and its
persisted selector are removed. Disposable PostgreSQL tests verify fresh installation,
preserved encrypted secrets, transactional refusal of unrecoverable local rows,
and rotation against receiver-side HMAC verification. Webhook/handler units pass
79 tests; functional/schema checks pass 21 tests, with independent-connection
migration checks. No production deployment or local database reset was performed.

Deptrac resource layers now enforce the documented resource-to-model exception
without allowing controllers to depend on domain models. A real Deptrac fixture
tests both sides. Removed 23 obsolete resource baseline pairs and the removed
webhook signer dependency. The scan reports 238 active violations, 676 skipped
occurrences, and zero configuration errors. Uncovered contexts remain work.
The full configured PHPStan scan remains at zero errors after these batches.

## Delivery horizons

The destination is a reliable private self-hosted backend/web application, a small
strongly consistent global registry, and secondary clients whose native media paths
are qualified. The current store refactor is one stage in that programme, not a
replacement for it. Horizons express dependencies, not promised calendar dates.

| Horizon | Deliverable | Exit condition |
| --- | --- | --- |
| Now | Web-state architecture, render/persistence budgets, optional developer tracing | Behavior and performance regressions pass; disabled tracing has no runtime instrumentation |
| Before application release | Backend/web security, asynchronous recovery, complete contracts and enforceable gates | Fresh install and critical workflows pass real integration tests; deliberately failing checks block publication |
| Before registry rollout | C++ registry and five-voter deployment qualification | Consistency, constrained capacity, partition/failover, leak and restore gates pass |
| Before secondary-client certification | Shared/mobile/desktop correctness and reproducible native media | Affected client CI and native reference-vector/browser tests pass |
| After these foundations | Replayable developer timelines, measured API expansion, sustained operational qualification | Explicit replay semantics; twenty-API topology retains five voters; recurring release gates remain enforced |

Backend/web remediation remains ahead of secondary-client enhancements. Reliable
delivery and recovery precede new workflow expansion or more ambitious autoscaling.
No milestone is complete merely because a command, schema, test, or deployment file
exists; its stated acceptance checks must have evidence.

## Current work: web stores and developer tooling

- [x] Refactor web stores into cohesive modules with explicit, typed public actions.
  Use slices for substantial shared state; keep small feature stores simple.
  Preserve atomic transitions across queue, selection, and playback ownership.
- [x] Separate the playback clock from persisted state. Time-only changes must
  cause zero player-store notifications and zero persistence writes.
- [x] Write persisted projections only when durable fields change. Keep credentials,
  DOM objects, transient playback state, and action functions out of persistence.
- [x] Prevent unnecessary renders with narrow selectors, stable action identities,
  idempotent updates, and stable derived values. Verify with render-count tests.
- [x] Give session synchronization a bounded cadence under continuous playback;
  test latest-state delivery, device ownership, transport fallback, and teardown.
- [x] Refactor EQ, auth, radio, catalog, layout, notification, and visualizer stores
  and their consumers. Replace external mutation that bypasses feature invariants
  with explicit APIs; preserve authentication and native-media guarantees.
- [x] Add an opt-in store debugger. Disabled stores must retain their normal
  implementations, without tracing wrappers, subscriptions, snapshots, or timers.
  Enabling/disabling instrumentation may require reloading the application.
- [x] Provide developer UI for live state, named actions, state changes, call
  stacks, and cross-store call flows. Bound retained history and redact secrets.
- [x] Prepare a versioned, serializable timeline for future replay: ordered event
  IDs, parent-call links, immutable state captures, and explicit redaction and
  truncation metadata. Actual replay is future work; never reissue network/media
  side effects merely to inspect recorded state.
- [x] Verify unit/integration tests, render and persistence budgets, TypeScript,
  lint, production build, and disposable-browser workflows before completion.

The lead owns shared persistence/debugger infrastructure, developer UI, integration,
and this roadmap. One worker owns player/session state; another owns the remaining
feature stores and consumers. Workers do not commit or refresh the shared index.

Verified on 2026-10-04: 1,588 web tests, 46 native audio browser tests,
11 browser authentication tests, and the HTTPS store-debugger browser workflow.
TypeScript, changed-file ESLint, and the production build pass. Render and
persistence tests cover unchanged actions and clock-only updates. Manual browser
inspection confirms the timeline and inspector UI. These are local correctness
and count-based performance gates, not full application deployment certification.
Duration formatting also supports hours and zero-pads each field (`00:09`,
`01:05`, `01:00:00`), with boundary and rounding coverage.

## Follow-up: correctness review and remaining web gates

The full web lint audit on 2026-10-04 reported 134 errors and 14 warnings before
this follow-up, across the application, tests, and embedded player package.
Changed-file lint passing does not satisfy this release gate. Resolve the errors
at their source; do not broaden exclusions or suppressions to obtain a green run.
After centralizing the image lifecycle, the rerun reports 130 errors and 14 warnings.

- [x] Verify canceled image requests cannot publish stale covers or allocate leaked
  blob URLs; share one loader across album cards and retain unchanged-render budgets.
- [x] Verify starting music cancels radio fallback intent even while native radio is
  paused, and late radio failures cannot take playback ownership back.
- [x] Verify shared store helpers preserve symbol-key updates and action tracing
  after reset, and oversized property names cannot bypass trace export bounds.
- [x] Rerun combined tests, typechecks, changed-file lint, and debugger browser flow.
- [x] Bring full-project web lint to zero errors before the application release.

Verification: 1,602 web tests across 138 files, 46 native audio browser tests,
11 browser authentication tests, the HTTPS debugger browser flow, TypeScript,
changed-file ESLint, and the production build pass. New regressions
failed before their fixes. Disabled debugger instrumentation remains unchanged;
retained exports are bounded, but arbitrary JavaScript object enumeration is not
a bounded-time operation.

## Web quality gates restored

Full-project web lint now passes with zero errors. Seven warnings remain: four
React Compiler notices for TanStack virtualizers and three notices in generated
coverage assets. No lint rules, discovery paths, or baselines were weakened.

Corrections include typed API contracts and player inference outputs, isolated
component exports for hot reload, request/form lifetime ownership, and a PEQ graph
that preserves presets and responds to resizing. Playlist creation uses the actual
resource response. Named inference outputs are supported and unused tensors disposed.
The embedded player now has blocking typecheck and test steps in frontend CI.
New code follows the project's manual formatting style; Prettier is not introduced.

Validation: 1,618 web tests, 159 embedded-player tests, three performance tests,
58 browser checks, web and player typechecks, and the production build pass.
The player install was verified with the pinned Yarn version and immutable lockfile.
The broader backend,
security, native-media, and registry release gates remain separate milestones.

## Backend authorization and publication follow-up

EQ profile reads, updates, deletes, and activation now enforce ownership through
the application port. Foreign, missing, and malformed IDs return 404, including for
unrelated administrators. Owner deletion of a default profile remains 422. The old
cross-user read returned 200 in the regression reproduction; the corrected disposable
functional suite passes 33 tests with 133 assertions. It uses Symfony's firewall with
a test authenticator, so production OAuth/DPoP acceptance remains separate.

The application publisher now requires embedded-player, browser, and pinned DSP
qualification as well as backend and web checks. Application/frontend/DSP checkouts
verify the event SHA instead of fetching a moving branch. Local workflow regressions
exercise failing qualification commands, branch advancement, and missing/mismatched
commits. Live Forgejo scheduling and standalone CLI/base-image publication policies
remain to be verified.

Backend checks run in a PHP 8.5.2 container satisfying the Composer lockfile's
extension requirements, including Swoole 6.2.0. The host PHP runtime is unsuitable.
The PHPStan audit completed with 1,708 diagnostics (1,058 source and 650 test diagnostics),
without runtime/bootstrap errors. Concrete undefined methods and missing classes
precede annotation cleanup. Deptrac reports 259 violations; ten obsolete baseline
exclusions were removed. Neither baseline was expanded. These failures still block
application release; passing unit tests does not resolve them. The four annotation
diagnostics in the touched functional test were subsequently corrected.

Final combined backend unit verification passes 4,386 tests with 18,452 assertions,
including 14 publication regressions and 11 EQ adapter tests. Deptrac verification
confirms zero stale-exclusion errors after cleanup and 259 remaining violations.

## Runtime defects found by static analysis

- Genre resources now serialize their parent UUID directly. Root, child, and
  reconstituted hierarchy regressions cover the existing response contract.
- Metadata-match responses now map the actual candidate data instead of calling
  nonexistent model getters. Tests use the real tag reader and matching strategy,
  covering candidate identity, missing fields, ranking, and filtering. Optional
  source identifiers are nullable in the specification and generated client.
- Party WebSocket join, leave, play, pause, and seek failures now read Messenger's
  original exception through its standard previous-exception chain. Regressions
  reproduce the old fatal error and verify error delivery plus a usable connection.

All three defects were reproduced before their fixes. Combined backend unit
verification passes 4,397 tests with 18,509 assertions in the qualified PHP runtime.
PHPStan now reports 1,693 diagnostics, down from 1,708, with no new diagnostics in
the changed files. These are bounded runtime repairs; the remaining PHPStan and
architecture findings still require remediation. The party-sync command mismatch was carried into the next checkpoint below.
Deptrac remains at 259
violations with zero configuration errors. OpenAPI and client regeneration are
limited to the two optional candidate identifiers; the web typecheck passes.

## Party synchronization repair

Party WebSocket synchronization now supplies the authenticated connection user to
the command and reads the handled numeric position from Messenger's envelope.
Payload user IDs cannot substitute another member. Invalid/nonfinite positions,
invalid latency, missing handler results, and handler denial produce an error without
session data; a rejected message leaves the connection usable.

The shared playback synchronizer checks membership before reading the session or
updating drift. Non-members are denied, while member jitter/EMA behavior is retained.
Regression tests exercise real Messenger dispatch/result middleware and the shared
synchronizer/handler boundary. Full HTTP OAuth/DPoP and real WebSocket network
acceptance remain separate from these in-process tests.

Verification: 4,411 backend unit tests with 18,575 assertions pass in the qualified
PHP runtime. The final handler-test mock cleanup also passes the 115-test Party
suite. PHPStan reports 1,690 diagnostics, down from 1,693, with no new diagnostics
in the changed files. Deptrac remains at 259 violations and zero configuration
errors; both release gates remain blocked.

## Worker monitoring and manual retry repair

Worker job monitoring now uses the receiving worker event's queue name. Manual
retry uses Messenger's transport-selection stamp, assigns a fresh job ID before
sending, and records the authenticated administrator in its audit entry. The
response, queued message, and audit record share that new ID. Both Symfony workers
and the Swoole task-monitor decorator preserve it through success or failure. Dispatch failure
leaves the original job unmarked so it can be retried later.

Tests exercise real Messenger sender routing and worker events with the independent
JSON message codec. This repairs runtime behavior without claiming atomic retry
claims: concurrent administrative retries and send-success/audit-failure recovery
still need an explicit durable transaction/delivery design.

Verification: 4,424 backend unit tests with 18,758 assertions pass in the qualified
PHP runtime. The final explicit stamp-check cleanup also passes all five Swoole
transport tests. PHPStan reports 1,683 diagnostics, down from 1,690; the initial
changed production and regression files have no diagnostics. Final scoped analysis
also passes for the Swoole decorator and transport tests. Production container
warmup passes without wiring errors; external Redis availability was not tested in
the no-network container. Deptrac remains at 259 violations.

## PHPStan remediation completed

The full configured level-6 scan of `src/` and `tests/` now passes with zero
errors. The sustained cleanup resolved the 1,683 reported diagnostics and the
18 EQ-profile diagnostics previously hidden in `phpstan-baseline.neon`; that
baseline and its configuration include have been removed. No new suppressions
or baseline entries were added. CI and Makefile analysis use a 1 GiB budget after
512 MiB full scans intermittently exhausted memory. Deployment budgets are unchanged.

Runtime fixes include secure public/token ID generation, UUID timestamp semantics,
OAuth identity reconstruction, Messenger result propagation, OpenTelemetry span
attributes, service and identity contracts, metadata response serialization,
immutable persistence dates, transcode process handling, lyrics pagination, and
reference-compatible BlurHash encoding. Native inotify exhaustion now leaves the
watcher retryable. Regression tests verify the changed behavior; accurate collection,
payload, and test-double contracts account for the remaining annotation work.

The combined Unit and StaticAnalysisRules suites pass 4,520 tests with 19,001
assertions in the qualified PHP 8.5 container, with notices and skips treated as
failures. Container wiring also passes. The final disposable functional batch
passes 92 tests with 911 assertions against fresh PostgreSQL migrations; it uses
the real firewall with TestAuthenticator and does not replace full OAuth/DPoP
qualification. Focused transport and runtime gates are recorded in the preceding
checkpoints. This completes PHPStan remediation, not the remaining integration,
Deptrac, deployment, or registry acceptance work.

The Deptrac comparison against the cleanup's starting commit has no new violation
pairs: 257 violations remain, down from 259, with zero configuration/baseline
errors after removing four obsolete entries. The public authenticated-identity
contract has an exact layer allowance for its consuming controllers; Auth internals
remain isolated. Catalog's playlist-impact helper now accepts only song UUIDs.
Lyrics Application is still missing from Deptrac's collectors, an enforcement gap
to address during the separate architecture remediation.

## Production authentication and contract drift gates

DPoP validation now preserves the actual HTTP/HTTPS scheme. Signed unit tests and
an isolated production-firewall test reproduced HTTPS proofs being accepted for
HTTP requests before the fix. The corrected OAuth security suite passes 85 tests
with 426 assertions; the production firewall passes seven cases with 100 assertions
using real RS256 access tokens, ES256 proofs, PostgreSQL records, and Redis replay
storage. This does not yet qualify the complete signed-delivery or browser matrix.

OpenAPI export now offers a non-writing `--check` gate and reports write failures
instead of returning success. The current 228 paths match runtime routing; tests
check both missing operations and documented operations without routes. Configuration
map defaults are JSON objects, and the three changed request schemas and generated
web client are regenerated together. Backend publication requires the specification
check; frontend publication requires regenerated-client parity. Failure-injection
workflow tests verify both checks prevent publication from proceeding. Export/workflow
tests pass 26 cases with 161 assertions; schema tests pass 20 cases with 169 assertions,
and the route-coverage test adds 1,164 assertions. Web typechecking and both drift
checks pass. The full configured PHPStan scan remains clean.

The full disposable functional suite passes 987 tests with 7,688 assertions after
replacing eight passive mocks with stubs. Fresh migrations and a second no-op
migration run pass; PHPUnit notices and skipped tests fail the runner.

## Application boundary remediation

Party playback synchronization now uses an application port. Activity, Library,
and Party session controllers consume the authenticated-identity contract rather
than a concrete security adapter. Owner/admin/member regressions and container
wiring pass. Review also found Library creation saving a record before rejecting an
unsupported principal; identity and UUID validation now precede every lookup/save.
Four regressions reproduced that unauthorized side effect before the fix.

Deptrac is down to 240 active violations from 257, with no added violations or stale
baseline entries. The initial combined Party/Activity/Library suite passes 295 tests
with 917 assertions; the final Library suite passes 138 tests with 301 assertions.
Scoped PHPStan remains clean. Existing architecture debt and uncovered contexts are
still release work; no suppressions or baseline entries were added.

## First-party license alignment

The root license, first-party package manifests, and current project/package
license summaries now declare Apache-2.0. The Composer lock metadata for the local
PHPStan package was regenerated without dependency version changes. Swoole Bundle's
MIT license and the TSDuck binding's BSD-2-Clause declaration remain intact;
`THIRD_PARTY.md` records those boundaries. Registry files are being handled with its
replacement. A complete packaged transitive-dependency inventory and redistribution
check remain release work; this metadata alignment does not certify those artifacts.

## Recent verified checkpoints

| Commit | Result | Verification |
| --- | --- | --- |
| `9dfd2292` | Ignore stale native play/ended notifications | 1,520 web tests; 44 browser tests; typecheck, scoped lint, build |
| `cb18f7cb` | Bind deferred resume results to playback generation | 1,528 web tests; 45 browser tests; typecheck, scoped lint, build |
| `784c8cc7` | Stop owned media errors, cancel handoffs/fades, allow reselection | 1,534 web tests; 46 browser tests; typecheck, scoped lint, build |

The media-error browser case forces an active source reload to a local 404 and
observes a real Chromium MediaError. It does not certify midstream transport
recovery. Earlier player work includes native source ownership, activity per
playback iteration, stereo metering/phase, and normalization. Complete native
codec/DSP qualification remains a separate gate.

## Original remediation programme

These packages remain tracked until their full acceptance criteria are verified.
Prior commits contain substantial work; audit the current implementation and
record evidence before marking an entire package complete.

1. **Quality gates:** separate unit/browser discovery; correct fixtures and runtime
   requirements; shared typechecks; actual Vite output checks; isolated CI services;
   blocking PHPUnit, PHPStan, Deptrac, lint, typechecks, and frontend tests. Fix
   defects instead of expanding suppressions. Prove failed tests block releases.
2. **Authentication and authorization:** real-firewall DPoP/audience checks;
   signature-only delivery exemptions; library-scoped reads and streaming;
   owner/admin session control; administrator catalog mutation and cleanup;
   authorized signing with a maximum/default 24-hour lifetime; authenticated pairing.
3. **Tokens and browser credentials:** atomic refresh rotation, replay detection,
   real-database concurrency/rollback tests, bounded retries and nonce handling,
   API-origin credential scoping, requesting-client ownership, and worker lifecycle.
4. **Async work:** two deployment entrypoints (web server and worker supervisor);
   supervised queue consumers and scaling; readiness and queue age; durable outbox
   claims/recovery, correct routing, poison-message handling, stable event IDs,
   idempotent consumers, and transactional domain/outbox writes. Backend message
   formats must be independent of Symfony serialization. No exactly-once claim.
5. **Storage, webhooks, and contracts:** root/symlink boundaries on every operation;
   safe DNS/destination validation and redirects; operator LAN allowlist; documented
   encrypted-secret signing; configuration validation; working cover extraction and
   key rotation/recovery; JSON seeking; generated OpenAPI/client drift checks.
6. **Operations and clients:** fresh PostgreSQL install/upgrade and restore tests;
   correct image/startup/health configuration; shared/mobile/desktop checks;
   Electron origin/IPC validation; reproducible native media builds/reference tests;
   Apache-2.0 first-party licensing while retaining third-party licenses.

Use the PostgreSQL specialist and remediation skill for Doctrine/PostgreSQL work,
including the project's extensions and PGroonga 2+. Follow the PostgreSQL
“Don't Do This” guidance. There are no production deployments: prefer the intended
design over compatibility layers. The temporary AGENTS.md policy remains until
the user explicitly announces the first production release.

## C++ registry

- [ ] Complete/verify C++20 registry with Beast/Asio, OpenSSL, nlohmann/json,
  pinned dependencies, CMake/Ninja, GoogleTest, and bounded async rqlite pools.
  Replace the PHP registry; no Symfony serialization, Redis, Sentinel, or Electric.
- [ ] Verify atomic credential-owned registration, digest-only credentials,
  idempotent uncertain-commit retries, reserved offline identities, revisioning,
  parameterized SQL and statement errors, bounded input/rate limits, private TLS
  database endpoints, and liveness/readiness behavior.
- [ ] Require explicit linearizable reads and committed writes. Return 503 with
  Retry-After when quorum cannot provide an authoritative result; never downgrade.
- [ ] Qualify concurrency, partitions, leader loss, stale replicas, disk/certificate
  failures, shutdown, sanitizers, fuzzing, backups, and 24-hour soak/leak gates.
- [ ] Run constrained optimized acceptance on five hosts and actual regional links.
- [ ] Configure and rehearse encrypted, versioned five-minute S3-compatible backups
  and isolated restoration (five-minute RPO; rehearsed 30-minute RTO).
- [ ] Deploy and validate five voters, then upgrade voters one at a time. Preserve
  volumes and prevent accidental competing-cluster bootstrap after quorum loss.

Deployment names are configurable: currently `de`, `ca`, `sg`, `au`, `fi`, using
`<region>.registry.baander.app`. Regions may change. Target three to five online
hosts, with one/two-host critical/emergency visibility; a five-voter cluster still
requires three voters for authoritative operations. One or two surviving voters
must fail closed. Expansion to twenty API deployments does not expand the voter set.
Host access credentials and backup destination still need verified availability;
region/domain choices alone do not supply deployment access.

Acceptance budget: each whole host gets 1 CPU and 512 MiB; total used memory must
stay at or below 448 MiB, API peak RSS at or below 64 MiB, and normal busiest-host
CPU at or below 70%. Load: 100 servers, 60-second staggered heartbeats, 20 lookups/s;
fivefold bursts for five minutes. Normal p95/p99 must be <=1s/2s; burst p99 <=3s.
Test 200ms inter-node RTT, 25ms jitter, 0.1% loss, <=30s leader-loss recovery, and
no acknowledged registration loss after any two voter failures. Failed gates block
release, without weakening consistency or increasing the agreed host budget.

## Long-horizon acceptance backlog

### Application security and reliability

- [ ] Exercise anonymous, owner, unrelated user, library member, administrator,
  and revoked-token cases through the real firewall. Include missing proofs,
  wrong keys, replayed proofs, audience variations, expired signatures, and
  cross-library requests. Cover master/rendition/DASH/subtitle manifests and segments.
- [ ] Check every catalog mutation and library-scoped query, including search,
  counts, and related resources. Eliminate fallback administrator identities.
  Signing accepts only authorized media paths and valid bounded durations.
- [ ] Verify concurrent refresh against PostgreSQL: one usable replacement,
  rollback recoverability on persistence/signing failure, and replay detection.
  Exercise expiry, logout, multiple tabs, concurrent requests, service-worker
  restart, nonce challenges, and foreign-origin credential exclusion together.
- [ ] Verify actual Messenger middleware/Redis delivery with Swoole unavailable.
  Replayed outbox events reach concrete consumers without being re-enqueued.
  Durable expiring leases survive worker crashes; unsupported payloads enter
  retry/dead-letter handling. External delivery is idempotent where supported,
  with uncertain outcomes documented rather than an exactly-once guarantee.
- [ ] Test storage directory-boundary comparisons, validation before mkdir,
  traversal and symlink escapes across read/write/delete/derived-file resolution.
- [ ] Test webhook DNS and connection-time destination checks, corrected link-local
  ranges, disabled redirects, empty-by-default LAN allowlist, category validation,
  and original-secret signature verification. Since there is no production install,
  remove obsolete legacy formats rather than inventing compatibility obligations.
- [ ] Complete bounded cover-extraction dispatch and safe secret/key replacement,
  including file validation and a documented recovery path. Verify JSON seeking.
- [ ] Reconcile documented routes, OpenAPI schemas, generated clients, and identifier
  formats together. The initial review found 24 documented paths missing from the
  checked-in specification; keep automated drift checks blocking thereafter.
- [x] Eliminate the configured PHPStan diagnostics, including the old baseline,
  without new suppressions; verify the full scan and combined unit/rule suites.
- [ ] Eliminate remaining Deptrac boundary violations using application ports.
  No blanket suppressions or inflated baselines. Verify the
  correct Symfony/Vite artifacts, Composer extensions, isolated CI networks,
  matching Redis credentials, and explicitly failing readiness timeouts.

### Worker architecture and resource control

The detailed design is [Web and worker runtime redesign](docs/plans/2026-07-17-001-feat-messenger-enterprise-hardening-plan.md).
Its historical baseline is not a current completion report; reconcile against source
and passing integration gates as each item is closed.

- [ ] Verify the two-command deployment contract end to end: web owns delivery and
  client connections; worker owns consumers, scheduler, outbox relay, media execution,
  control services, and child cleanup. No competing supervisor or hidden web pool.
- [ ] Use validated routes/resource classes with a handler, independent JSON codec,
  retry policy, and unique consumer identity for every asynchronous message type.
- [ ] Reserve responsive control capacity independently of catalog/metadata/media
  backlog. Bound CPU, RAM, device slots, restarts, and shutdown for entire child trees.
- [ ] Make autoscaling use measured backlog age, job duration, utilization, and
  admission budgets with stable scale-up/down behavior and measured default profiles.
- [ ] Exercise leases, scheduler occurrences, process retirement, orphan cleanup,
  deployment recovery, and transport outages. Do not inherit live Doctrine
  connections across forks or depend on a running HTTP server to supervise workers.
- [ ] Qualify shared web/worker media control: leased ownership, ordered revisions,
  session generations, stale-segment exclusion, cancellation under saturation,
  seek/pause/resume/reconnect, and no duplicate encoders on client reattachment.

Related detailed media plans remain part of the roadmap until reconciled:
[long-process segmenter](docs/plans/2026-07-16-001-refactor-long-process-segmenter-plan.md),
[transcode cache/sweep end-to-end](docs/plans/2026-07-16-002-feat-transcode-cache-sweep-e2e-plan.md),
and [FLAC/DSP CPU work](docs/plans/2026-07-08-001-perf-flac-dsp-cpu-plan.md).
Existing implementation must be evaluated against their behavioral and resource
contracts; neither old prose nor the current branch name establishes completion.

### Registry release and growth

- [ ] Public API retains registration fields `publicId`, `url`, `name`, `version`,
  `apiKey` and recognizable success envelopes. Enforce HTTPS hostname URLs without
  embedded credentials; 8 KiB bodies, depth eight, IDs/names 128 characters,
  versions 64, URLs 2,048. Verify malformed/invalid/forbidden/oversize/throttled/
  unavailable statuses 400/422/403/413/429/503 respectively.
- [ ] Use `schema_migrations` checksums and one credential-owned `registries`
  table. Store only SHA-256 credential digests for random 256-bit credentials;
  never log/return credentials. Offline after ten minutes never releases ownership.
- [ ] Test simultaneous claims, wrong credentials, retry after uncertain commit,
  3–2 partitions, total quorum loss, stale followers, full disks, invalid TLS,
  graceful shutdown, and convergence after rejoin without manual data merging.
- [ ] Every PR: release build with warnings as errors, unit/API and three-node
  integration tests, static analysis, dependency/license checks, ASan/LSan/UBSan,
  separate TSan, malformed-input/disconnect/deadline/retry/reconnection/shutdown
  coverage, and fixed-runner performance smoke tests. Confirmed regression above
  10% against the accepted baseline blocks release.
- [ ] Nightly and pre-release: five-node partition/failover, parser fuzzing, isolated
  backup restoration, and a 24-hour workload soak. After warm-up compare equivalent
  quiescent windows; fail sustained RSS growth over 1 MiB/hour or 10% of baseline.
- [ ] Test snapshots, compaction, node catch-up, and backup upload inside the host
  resource limit. Sanitizer workers may be larger; optimized deployment binaries
  must still meet the agreed whole-host 512 MiB constraint.
- [ ] Rehearse loss of any two voters without acknowledged-data loss, leader recovery
  within 30 seconds, and cluster-wide restore from encrypted/versioned backups.
  Measure the actual regional topology before rollout, not only emulated latency.
- [ ] Expand APIs from five toward twenty only after measurements justify it;
  retain five voters, optionally use non-voting replicas, and route authoritative
  reads to the leader. Region changes require membership/runbook updates; emergency
  one/two-host operation never implies unsafe automatic quorum reduction.

### Secondary clients, native qualification, and release operations

- [ ] Remove shared compilation blockers and require dependent shared/mobile/desktop
  checks. Tighten Electron navigation/origin and IPC validation and test failures.
- [ ] Reconcile the [React Native styling plan](docs/plans/2026-07-15-001-refactor-rn-styling-pattern-plan.md)
  and [React Native/TV tooling plan](docs/plans/2026-07-15-002-chore-rn-tv-dx-tooling-plan.md)
  against current code after backend/web priorities are satisfied.
- [ ] Make codec/DSP builds reproducible and run reference vectors before claiming
  native paths release-certified. Track remaining loudness/codec qualification in
  [DSP qualification](packages/dsp/README.md); browser graph tests alone are insufficient.
- [ ] Verify clean application installs/upgrades on disposable PostgreSQL, restore
  backups before migration rollout, correct production dependency-copy ordering,
  environment defaults/startup behavior, and meaningful health/readiness probes.
- [ ] Align all first-party manifests/documentation to Apache-2.0; retain all vendored
  third-party notices/licenses and verify dependency-license checks in CI.
- [ ] Cut the first production release only after its acceptance evidence is recorded.
  Remove the temporary pre-release AGENTS.md policy only on the user's explicit
  announcement, then establish real compatibility/migration commitments from there.

### Developer tooling after the first debugger

- [ ] Keep trace records versioned and exportable, with bounded recording and visible
  gaps/truncation/redaction. Keep state snapshots detached from live references.
- [ ] Define replay scope explicitly: pure state transitions versus asynchronous
  completion, external input, network effects, native media, and nonserializable
  objects. Record enough provenance for deterministic supported cases.
- [ ] Add timeline navigation and isolated state reconstruction before any live replay.
  Never execute stored functions or silently replay external effects from imported traces.
- [ ] Add deterministic replay/reference tests and a version-upgrade policy when actual
  replay is implemented. A diagnostic timeline is not yet an event-sourced application.
- [ ] Retain disabled instrumentation and unrelated-render performance gates as the
  debugger and store APIs evolve; enabled tracing overhead is explicitly permitted.

## Completion and next updates

Before edits, refresh GitNexus and assess symbol impact. Before commits, run
`detect_changes` and inspect the actual scope, including unindexed test callbacks.
Keep independent changes reviewable and record checks without implying broader
coverage. The complete programme is done only when blocking checks, security and
workflow integrations, and registry consistency/resource/failover/restore gates pass.
