# Agent skills migration and code audit

The October 2026 review replaces 32 local Claude skill entrypoints with nine
maintained project skills, alongside `postgres-remediation` and `prose-fix`.
The source tree was ignored by Git. Before retirement, it was archived with a
SHA-256 file manifest under the operator's
`~/.local/state/baander/retired-agent-config/` directory. Local permissions and
vendored browser dependencies are not part of the migrated library.
The initially migrated `sync-github` skill was subsequently retired at the user's
request; the maintained library contains 11 skills.

This is a bounded review of instructions against representative code and checks,
not certification of the entire application. Existing violations and baseline
entries do not authorize new violations. The recommendations below do not claim
that application defects were repaired by this documentation migration.

## Skill disposition

Source names refer to the retired `.claude/skills` tree. Destinations are relative
to `.agents/skills`; detailed procedures are loaded only when relevant.

| Source entrypoint | Disposition and destination | Reason |
|---|---|---|
| architecture-guardian | Merge into architecture-review | Duplicate review flow; stale context inventory and wiring assumptions. |
| boundary-review | Merge into architecture-review | Preserve boundary analysis; remove false Shared-layer assumptions. |
| context-review-backend | Merge into architecture-review | Preserve semantic review; remove mandatory four-worker and obsolete tool flow. |
| dddlint | Merge into architecture-review references | Heuristics need semantic verification, not automatic violations. |
| context-analyzer | Merge into documentation-maintainer references | Preserve read-only inventory; discover nested layouts. |
| entity-scaffold | Merge into backend-scaffold | Retain aggregate/mapping workflow with current persistence rules. |
| endpoint-scaffold | Merge into backend-scaffold | Correct broken examples; preserve authorization and contract checks. |
| cached-repository | Update, same name | Cache contracts and failure semantics need explicit verification. |
| migrate-to-state-object | Update, same name | Useful migration; fixed class inventory is obsolete. |
| migrate-application-to-port | Retire entrypoint; retain architecture-review reference | Named ApplicationService backlog is completed. |
| context-review-frontend | Update as frontend-review | Actual styling, native worker requests, tests, and tooling differ. |
| documentation-maintainer | Update, same name | Preserve code-derived documentation and handwritten sections. |
| phpstorm-config-keeper | Merge into documentation-maintainer references | Overlaps IDE documentation refresh. |
| update-command-docs | Merge into documentation-maintainer references | Preserve operator command documentation and read-only check mode. |
| forgejo | Update, same name | Retain project integration; remove credential sourcing and fixed host assumptions. |
| reli | Update, same name | Retain profiler workflow; accurately describe wrapper limitations. |
| sync-github | Retire at user request | Initially migrated with a tested helper; the user no longer needs this publishing workflow. |
| feature-pipeline | Retire; replace companion delivery guide | Obsolete pi tools, CI bypass, branch-only checks, and local merge fallback. |
| test-fix | Retire; preserve project details in testing guide | Generic loop duplicates debugging guidance; changed-file bookkeeping was incorrect. |
| test-scaffold | Retire; preserve project details in testing guide | Generic recipes and confirmation gates add little; retain real fixture contracts. |
| playwright-skill | Retire; retain project E2E guidance in testing guide | Use maintained browser tooling and project Playwright; old runner raced, installed dependencies, and swallowed failures. |
| gitnexus-guide | Merge into gitnexus references | Preserve tool/schema guidance and actual CLI alternatives. |
| gitnexus-cli | Merge into gitnexus references | Refresh must use index-only mode. |
| gitnexus-exploring | Merge into gitnexus references | Preserve repository binding and incomplete-index caveats. |
| gitnexus-debugging | Merge into gitnexus references | Preserve evidence-led flow tracing. |
| gitnexus-impact-analysis | Merge into gitnexus references | Preserve UNKNOWN/partial-result handling and required impact reporting. |
| gitnexus-refactoring | Merge into gitnexus references | Preserve semantic rename and change-scope review. |
| gitnexus/gitnexus-cli | Retire duplicate | Older than top-level source. |
| gitnexus/gitnexus-exploring | Retire duplicate | Older than top-level source. |
| gitnexus/gitnexus-debugging | Retire duplicate | Older than top-level source. |
| gitnexus/gitnexus-impact-analysis | Retire duplicate | Older than top-level source. |
| gitnexus/gitnexus-refactoring | Retire duplicate | Older than top-level source. |

The six coding-rule documents now live in `.agents/rules`. `AGENTS.md` supplies
explicit loading pointers; directory placement does not imply automatic loading.
The existing prose and PostgreSQL skills remain authoritative in their scopes.

## Rule and code evidence

Paths below are repository-relative. “Debt” means recommended application or
checker work, not permission to copy the implementation into new scaffolds.

| Finding and evidence | Classification | Adopted guidance or recommended remedy |
|---|---|---|
| `src/Playlist/Domain/Model/Playlist.php` and NotificationPreference retain positional reconstruction; User uses `register()`. | Intended migration exception | Prefer state objects for new aggregates; preserve legacy behavior and semantic factories. |
| Notification commands/handlers use `Application/DTO` and `Application/Handler`. | Supported layout | Discover contracts recursively; do not require directory-only migrations. |
| `PlaylistResource` maps a domain model, as the old resource rule prescribed, but Deptrac baselines that dependency. | Rule/check mismatch | Permit narrow presentation mapping; recommend narrow enforcement alignment. Keep repository injection prohibited. |
| Catalog AlbumService consumes Media ports, while Deptrac rejects even these contracts. | Rule/check mismatch | Preserve ports/events as cross-context boundaries; recommend dedicated port allowances rather than entire-context exemptions. |
| Auth repositories use multiple infrastructure paths and explicit cache aliases. | Stale instruction | Resolve full interface contracts and container wiring; resource registration does not ensure an unambiguous alias. |
| Shared OutboxRepository imports DBAL; OutboxSubscriberPass is a compiler adapter in Domain. | Placement debt | Remove blanket Shared exemptions; propose moving adapters through ports in separate work. |
| Shared Uuid wraps Symfony UID. | Narrow existing primitive boundary | Do not confuse use of an immutable primitive with permission for persistence, container, or serializer dependencies. |
| Outbox uses internal integer identifiers; authentication entities retain timezone-free datetime mappings. | Overbroad policy plus persistence debt | Scope UUID defaults to domain identities; apply PostgreSQL remediation and forward migrations to actual defects. |
| Web Button and NowPlayingBar use styled-components; web dependencies contain no Tailwind. | Stale instruction | Follow actual styling primitives and `ui/DESIGN.md`; do not adopt arbitrary existing design violations. |
| Auth streaming worker forwards native requests; WASM loader fetches binaries. | Narrow platform exception | Shared Axios for ordinary API calls; explicit worker/asset request handling with credential-origin boundaries. |
| Web tests exist under both source and test trees; Vite excludes E2E suites. | Stale instruction | Discover current tests/configuration; do not claim an empty test suite. |
| Props-free components and React.ComponentProps intersections are correctly typed without named Props interfaces. | False-positive heuristic | Review actual types and behavior; do not require decorative interfaces or automatic memoization. |
| Async::sleep selects coroutine/blocking behavior; child-process loops and CLI consumers use blocking sleeps. | Execution-context exception | Avoid blocking request coroutine work; allow deliberate CLI/child-process behavior. |
| Unit/runtime container scripts isolate resources differently and have different timeout policies. | Overstated testing guarantees | Name the actual runner and its limits; independent-connection drills prove persistence visibility. |
| Swoole task workers share a queue; comments do not implement workload affinity. | Stale operational explanation | Describe actual routing and verify production lifecycle settings separately. |

## Resolved checker finding

The review found that
`packages/baander-phpstan-rules/src/Rules/MapRequestPayloadObjectTypeRule.php`
checked `Node\Name` for bare `object`. A parsed-source probe returned
`Node\Identifier` and zero violations; the original tests manufactured `Name`
nodes. This was an enforcement defect, not permission for untyped payloads.

The subsequent checker fix uses the parsed identifier type, unwraps nullable types,
checks union members for generic `object`, and resolves the attribute's fully
qualified Symfony name through PHPStan's scope with case-insensitive class matching.
The complete `StaticAnalysisRules` suite passed 11 tests with 13 assertions under
strict settings; source PHPStan also passed. The payload fixtures reject missing
types and `object`, `?object`, or `object|null` through ordinary imports, aliases,
and fully qualified attributes, including mixed-case class names. They accept
concrete and nullable DTOs and Symfony's typed-array payload contract, and ignore
unrelated attributes with the same short
name. This resolves the recorded parser-node and attribute-identity defects; it
does not establish general scalar/union restrictions or validate DTO contents.

The tracked PHPUnit configuration now includes `StaticAnalysisRules`; the strict
unit container runner selects it alongside `Unit`. This checker fix is separate
from the documentation migration and from the remaining application findings below.

## Access-token cache correction

The audit found that `setRevoked()` used cache `get()` as an overwrite, so a warm
null entry prevented its callback from publishing the new status. Investigation
also found that refresh rotation calls `save(false)` before commit: even a correct
cache overwrite could leave a revocation marker after the database rolled back.
Active-token cache deletion could throw after persistence had succeeded.

The decorator now delegates reads to its inner repository and only invalidates
legacy cache entries after writes. It publishes no status before commit and ignores
legacy revocation markers on reads. Inner repository failures propagate unchanged;
cache and logging failures do not turn successful persistence into failure.
The OAuth adapter still checks the returned token's revocation and expiry.
This removes the revoked-token shortcut; active-token reads already used the database.

Real cache-adapter regressions failed before the correction and pass afterward.
The combined unit/rule run passes 2,909 tests with 8,426 assertions. The disposable
PostgreSQL/Messenger run passes 17 tests with 261 assertions, including three new
token-cache transaction tests using the real Doctrine repository and an independent
connection. They cover deferred flush, outer rollback, and committed revocation
with stale cache entries. Older workers still use their old cache semantics until
upgraded; these checks do not certify a mixed-version rollout.

## Favorite validation and installation corrections

`AddFavoriteRequest` now validates the documented song, album, and artist types
before dispatch. Real-validator tests first reproduced unsupported values being
accepted. All 11 Favorites controller tests now pass, including HTTP 422 for an
unsupported type and an empty favorites list afterward. The test firewall uses its
test authenticator; production bearer-token/DPoP behavior is outside this check.

Preparing the disposable database exposed alphabetical migration ordering that
placed alterations before legacy table creation. `MigrationVersionComparator`
orders the existing identities without modifying historical SQL or names. Plan
regressions cover all 14 migrations, applied-version skipping, and a completed
history. The disposable PostgreSQL run executes all 14 migrations; a second run
reports no pending work. This is fresh-install evidence and plan-level upgrade
coverage, not a production backup/restore rehearsal or full downgrade certification.

The functional runner uses a fresh checkout directory and explicit disposable
service settings. PHPUnit database/Redis defaults now allow environment overrides.
The root OpenAPI export remains stale: Favorites routes are absent, and the existing
201 response documentation/client envelope disagrees with the flat API response.
The source annotation now documents 422; full contract regeneration remains open.

## Notification preference correction

`UpdatePreferenceRequest` now accepts both JSON boolean values while rejecting
missing, null, and non-boolean values. Its OpenAPI array item uses the correct
`Items` attribute. Real-validator regressions reproduced the false-value rejection
before the fix; all 13 focused tests pass afterward.

The GET defect concerns missing stored preferences, not optional query filters:
this endpoint has no filters. Its timestamp placeholder read an absent array key
before returning null. The HTTP regression triggered 16 warnings before the fix.
Initializing the placeholder directly to null preserves the later stored timestamp
merge. Seven functional tests now pass with 608 assertions, covering all 16 default
rows, partial stored preferences, enable/disable persistence, the 12 writable
combinations, authentication, and user isolation. The tests use the test firewall.
Focused PHPStan for both changed production files passes. The full unit/rule suite
passes 2,939 tests with 8,487 assertions. That run exposed a rate-limit test whose
exact assertion crossed a wall-clock second; controlled-clock cases now verify
both the original deadline and one second of elapsed time without changing
production behavior.

## Recommended follow-up changes

These are separate reviewable changes. No baseline expansion or suppression is a
remedy. Priorities order investigation; they do not imply every static finding has
been reproduced end to end.

| Priority | Evidence | Proposed change and acceptance |
|---|---|---|
| High | NotificationPreferenceRepository::isEnabled defaults missing rows to true, while GET displays most missing preferences as disabled; email and push handlers use the repository result. | Define one default policy and verify display and delivery agree for unseeded and partially seeded users. |
| High | Existing NotificationController functional tests characterize cross-user mark-read and delete operations without ownership checks. | Reproduce through the firewall and enforce owner/admin policy with negative regressions. |
| Medium | Notification preference GET includes admin_operations, while writes/seeding support three categories; PUT also manually decodes input and documents a different response envelope. | Resolve category policy and contract drift; verify malformed JSON and wrong-shaped preference input return client errors before persistence. |
| Medium | PlaylistResource documents publicId as UUID, while the shared PublicId generates a 21-character NanoID. | Correct identifier schemas and regenerate affected clients together; verify actual identifier formats in contract tests. |
| Medium | useAudioPlayback destroys its service during cleanup but keeps an initialized guard; the app uses StrictMode. | Add a StrictMode setup/cleanup/replay regression, reproduce the lifecycle failure, and restore symmetric ownership. Static finding pending runtime reproduction. |
| Medium | Independent trial of the actual player-store setters reproduced slider volume 40% but audio volume 80% after changing volume while muted and unmuting. | Add a store/audio integration regression and synchronize volume on unmute. This setter-level reproduction is not a browser playback test. |
| Medium | The streaming worker emits SW_AUTH_EXPIRED; the bridge has no matching handling branch. | Trace expired streaming credentials end to end and add a browser regression before choosing the refresh/logout behavior. Static integration finding. |
| Medium | ProcessExecutor calls Swoole before checking extension availability, omits the timeout in its coroutine branch, and omits proc_close on normal fallback completion. | Reproduce extension-absent, deadline, and normal cleanup behavior in isolated process tests; fix without weakening execution limits. |
| Medium | Deptrac omits QoL, Radio, Scheduler, Session and ignores uncovered internal classes. | Add scoped collectors and allowed port/presentation edges; expose real violations and resolve them without expanding baselines. |
| Medium | Shared adapter placement and controller cross-context repository dependencies remain. | Refactor one boundary at a time with impact analysis and integration coverage. Do not mass-move classes during skill migration. |
| Medium | Functional fixtures use placeholder email domains; E2E defaults to baander.test. | Use baander.app names with mocked HTTP/DNS or explicitly routed disposable services; verify tests cannot contact production. |
| Medium | App TypeScript configuration is not strict; Playwright is not in the web CI step. | Propose a scoped strictness migration and isolated browser CI gate; do not describe current checks as enforcing either. |
| Medium | Existing timezone/identity mappings and production Swoole lifecycle settings need review. | Apply the PostgreSQL skill and measured runtime tests; do not rewrite applied migrations or promise bounded memory from comments. |
| Low | Documentation check mode found no operator page/index entry for app:outbox:consume. | Document both relay stages, once/continuous modes, time-limit bounds, signals, and exit behavior from the command source. |

## Verification and maintenance

Migration verification completed:

- The initial 12 skills passed the skill validator and appeared in fresh Codex discovery
  after Claude retirement; 11 remain after the requested `sync-github` removal.
  Local Markdown links in the skills, rules, and changed guides
  resolved; diff whitespace checks passed.
- Before its removal, the snapshot helper's reproducible suite passed 12 tests
  against temporary local Git repositories.
- Independent snapshot review/trial verified preview and export preservation and
  identified the missing source-commit guard. The helper was corrected to require
  both the previewed source commit and remote SHA; its suite verified rejection
  before writes when the source changed or the source pin was omitted.
- Independent backend review/scaffold trials accepted resource mapping and internal
  ports while reporting repository coupling and identifier-contract defects.
- An independent frontend trial accepted native worker fetches and stable selectors,
  passed targeted ESLint and 11 worker/bridge tests, and reported the separate
  playback findings above. It did not run a browser or contact a live application.
- Documentation check mode reported the missing outbox command page without changing
  reviewed command documentation. Concurrent documentation edits by another worker
  were accounted for rather than claiming the entire working tree was unchanged.
- Index-only GitNexus analysis passed in a disposable fixture checkout without
  recreating retired files or changing maintained instructions and a sentinel skill.

Validate skill frontmatter and relative references, then run realistic independent
trials against representative code. Skill reviews must distinguish an intentional
exception from a defect, and report incomplete evidence rather than manufacture a
passing result. Syntax validation alone is not behavioral verification.

Refresh GitNexus with `analyze --index-only`. Verify in a disposable checkout that
it neither recreates the retired Claude files nor overwrites maintained skills or
instructions. Keep ignore entries for retired local artifacts; they are defensive
exclusions, not runtime dependencies.

Use the [testing guide](../docs-book/part-2-developer-guide/testing.md),
[architecture rules](../.agents/rules/architecture-rules.md), and
[frontend rules](../.agents/rules/frontend.md) as maintained entrypoints. Update this
report's findings when the corresponding code fixes are independently verified.
