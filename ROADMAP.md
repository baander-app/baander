# Baander roadmap

Updated: 2026-10-30. This roadmap selects future work and records unresolved
acceptance. Current operating contracts belong in the linked guides; completed
milestones and their verification evidence remain in Git history.

## Resume here

Continue the Back-to-next-movie investigation through a newly reviewed, bounded
scope. Capture the browser failure and the next movie's actual startup outcome,
including its mounted/retry state, then complete authenticated cancellation and
recovery controls. The evidence below confirms retained producer ownership but
does not yet select a production remedy or another automatic test run.

Select other work for a concrete user workflow, demonstrated runtime gap or named
release decision. Historical unchecked items, old plan limitations and incomplete
experiments do not authorize implementation or indefinite reruns. Reconcile the
[retained plans](docs/plans/) with current callers before using them. Release gates
become actionable when their named environment, access or decision is available.

Read [AGENTS.md](AGENTS.md) for workflow, [CONCEPTS.md](CONCEPTS.md) for shared terms
and the [testing guide](docs-book/part-2-developer-guide/testing.md) for current
runners and verification boundaries. Historical suite counts apply to their own
trees; they are not acceptance evidence for a new change.

## Cohesive live updates

Implementation plan delivered, 2026-10-30, in the
[live update architecture plan](docs/plans/2026-10-30-2030-feat-cohesive-live-updates-plan.md).
The confirmed scope covers all React Query server state and related readers such
as sidebar Recents. Nine units in three dependent review groups cover transactional
change capture, scoped revisions, shared WebSocket delivery, targeted query
refresh, recovery, and authorization. Unsaved edits and active playback remain
protected. Runtime implementation and acceptance are still open.

Verification checkpoint: an independent source check confirmed six grounding
claims with their scope limits. Repository, PostgreSQL, framework, and flow
research grounds the implementation decisions. All seven document-review lenses
returned without findings; the confidence, contract-preservation, prose, and
whitespace checks pass. The plan assigns transaction races, multi-worker delivery,
access revocation, drafts, pagination, and capacity tests to implementation.
No application tests ran for this documentation change; planning evidence does
not establish working live updates.

U1 delivered locally, 2026-10-30: shared PHP/TypeScript protocol validation and
51 feature-owned policy descriptors classify 207 consuming call sites (206 unique
classifications) across 111 files. The storage catalog covers 84 tables, including
65 capture sources and 19 explicit exclusions, and 91 cascade relationships.
Coverage checks link frontend table references to the backend catalog and detect
unclassified readers. The developer guide explains the extension contract.
Runtime capture, delivery, adoption, and behavior verification remain open.

U1 verification checkpoint: the disposable backend CI runner passes 10 tests with
1,522 assertions. All 14 frontend protocol and coverage tests, both TypeScript projects,
scoped ESLint, scoped PHPStan, PHP formatting, prose preservation, and whitespace
checks pass. No application subscription has been activated.

U2 delivered locally, 2026-10-30: migration-owned triggers capture the 65 declared
sources in their writer transactions. The projector folds committed pending rows
into durable scope revisions without a commit-order cursor. Reads use bounded
scope batches; failed projection discards the connection. Private dependency
recipients, delete cascades, bulk changes, heartbeat exclusions, and TOTP status
transitions have explicit capture behavior. The source catalog now includes both
freshness tables as exclusions (86 tables total).

U2 verification checkpoint: the disposable CI runner passes 39 tests with 2,070
assertions, including 29 PostgreSQL tests with 545 assertions. These cover rollback,
late lower-ID commit, crash/retry, real transaction timeout, installed trigger
definitions, old/new ownership, delayed notification projection, and review
regressions for TOTP, playlist visibility, and file availability. All 14 frontend
contract tests and scoped PHPStan pass; Deptrac reports zero violations without
baseline changes. Formatting and whitespace checks pass. Full browser delivery
and capacity qualification remain open.

U3 delivered locally, 2026-10-30: the web runtime projects changes on worker zero
and reconciles authorized subscriptions on each HTTP worker. Current account,
library, and playlist authority is read with revisions in one bounded database
snapshot. Recovery uses the normal DPoP route; reset messages identify data to
purge without granting access. Delivery coalesces pending frames, closes blocked
peers, and releases database services before sending. Existing server diagnostics
now expose publication checks, reads, observed backlog, and delivery counts.

U3 verification checkpoint: the disposable CI image passes 171 tests with 6,259
assertions across ordinary, native, and PostgreSQL lanes. The two-worker probe
covers console-origin writes, failed reads and recovery, worker reload, idle
projection, and drain cleanup. Its real coroutine wrapper uses a test connection
pool; full application pool baselines and capacity remain acceptance work.
The slow-peer probe checks native send deadlines and healthy peer progress.
All 14 frontend contract tests, both TypeScript projects, scoped CI PHPStan,
OpenAPI generation, and Orval generation pass. Deptrac reports zero violations
with the existing 484-entry baseline unchanged. ESLint has zero errors and eight
pre-existing warnings. New PHP files pass formatting; prose and whitespace checks
pass. Independent simplification retained all proof tests. Browser ownership,
query coordination, feature adoption, and full-system qualification remain open;
general browser subscriptions are not activated.

Backend review follow-up: send and watchdog coroutines now release pooled
logging services. Both regression cases failed before the fix as assigned slots
accumulated across repeated closures. A new dependency-policy test proves the
audience contracts remain unavailable to unrelated Shared adapters and domain
code. The disposable CI runner passes 65 architecture, API, wiring, native, and
runtime tests with 2,260 assertions; full-project CI PHPStan passes. The native
lane has no skips. The compiled production pool's concurrent baseline remains
part of full-system qualification.

U4 delivered locally, 2026-10-30: the authenticated application owns the shared
socket, and session sync requires that transport. Principal sessions own query
caches and observers independently of token rotation. Queries, mutations, HTTP
responses, tickets, and delayed session restores reject obsolete ownership.
Confirmed access changes clear rendered and inactive query data and account
mirrors while preserving the mounted player. Transient outages retain readable
data and loaded pages; recovery must establish authority before new protected
reads proceed. Recovery controls have an explicit inventory classification
outside resource refresh scheduling.

U4 verification checkpoint: the full frontend suite passed 2,596 tests across
208 files. After the final outage-preservation correction, the affected suites
pass 800 tests across 49 files, including a ten-page recovery case and delayed
denial responses. Both TypeScript projects pass. Full ESLint reports zero errors
and the same eight pre-existing warnings; the final recovery changes pass scoped
ESLint. Prose and whitespace checks pass. Query scheduling, feature adoption,
general subscription activation, and browser/capacity qualification remain open.

U5 delivered locally, 2026-10-30: a shared dirty-generation coordinator matches
feature-owned query families, coalesces hints over 100 milliseconds and spaces
family refresh passes by at least 500 milliseconds. Reads acknowledge only the
generation captured when they start, so a later hint requires another read.
Actual HTTP attempts share admission capped at four background requests in
flight and twenty starts per second. Logical reads and HTTP work each have a
256-item bound, with logical capacity reserved for foreground work. Shared
sampling and a recovery watchdog retain retry work after failed reads or stalled
recovery. General application freshness remains disabled pending U6–U8 adoption.

U5 verification checkpoint: the stable full frontend suite passes 2,700 tests
across 215 files. Both TypeScript projects pass; full ESLint reports zero errors
and eight existing warnings. The focused group passes 141 tests across eleven
files, and the additional capacity group passes all four tests. Its real
QueryClient and Axios path converges 300 active variants with at most four
background HTTP attempts in flight, twenty starts per second, bounded pending
work and at most two timers. Four retained ten-page chains preserve their pages
and cursors while admitting a later ordinary read before the chains finish.
Three reviewed regressions fail before their fixes and pass afterward. Final
receipts are `/tmp/live-u5-frontend-stable.log`,
`/tmp/live-u5-typecheck-final.log` and `/tmp/live-u5-lint-final.log`.
Prose audits and whitespace checks pass. U6–U9 remain open for feature adoption,
subscription activation, browser acceptance and full-system capacity qualification.

U6 implementation checkpoint, 2026-10-30: sidebar Recents now uses a shared
query and refreshes after local playback while retaining readable data during
outages. Timeline serializes signalled page walks and preserves loaded data.
Library list, detail and statistics readers share canonical query keys and
signals; scan status uses a shared observer. Metadata editing uses the reusable
draft mechanism, with independent server baselines and local revisions, explicit
conflict and unavailable states, and access-revocation clearing. A completed save
clears only submitted edits that have not changed while it was pending.

U6 focused verification: metadata passes 25 tests across three files: seven
rendered InfoPanel cases, eight form-hook cases and ten shared-draft cases.
The rendered cases cover holding a remote update, newer input during save,
401/403 responses, deletion, access reset and switching resources. Lyrics passes
14 tests across two files, including reset during a pending save and release in
the `finally` block; activity passes six tests. The scheduler and library
regression group passes 27 tests across four files. Its second-library-observer
regression fails before the fix and passes afterward: nested cache events had
caused duplicate fetches, and invalidation now accepts only authoritative query
updates.

Focused review found two defects: pending draft reverts lost intent after access
revocation or a conflict, and path validation could remain at Checking after an
authentication change within the same account. Both regressions fail before
their fixes in `/tmp/u6-draft-revert-red.log` and
`/tmp/u6-path-review-red.log`. The repaired group passes 29 tests across four
files in `/tmp/u6-review-fixes-green.log`, including the 25 metadata/draft tests
and four library cases. The original full run had eleven sidebar fixture failures
and two new regression failures. Adding the query provider to the two sidebar
fixtures passes all eleven focused cases; that failed aggregate remains recorded.

The coverage inventory records these unit-proof paths while retaining pending
end-to-end acceptance. The stable full frontend suite passes 2,734 tests across
219 files in `/tmp/live-u6-frontend-stable.log`. Both TypeScript projects pass;
full ESLint reports zero errors and eight existing warnings. Their receipts are
`/tmp/live-u6-typecheck-final.log` and `/tmp/live-u6-lint-final.log`. Prose audits
and whitespace checks pass. Final code review remains open. General subscription
activation remains disabled. U7–U8 adoption remains open, including
draft protection in `EditLibraryDialog`; U9 still owns full producer, browser and
capacity acceptance.

U7 implementation checkpoint, 2026-10-30: personal collections, notifications,
preferences, sidebar configuration, EQ profiles and devices now use the shared
refresh lifecycle. Device registration and session claiming remain explicit
commands; refreshing a session does not replay its queue. Remote preferences
retain local edits and expose version conflicts. Transient autosave failures
retry the latest unsaved state with one bounded timer, while successful reads
held for a draft acknowledge freshness without repeatedly fetching it.
Unchanged sidebar and theme snapshots avoid redundant store and DOM updates.

U7 focused verification: the final preference group passes 424 tests across
14 files. Collections and radio pass 54 tests across eight files; personal,
account and device consumers pass 63 tests across eleven files. Session checks
pass 27 tests across four files, including explicit claiming and one-time
hydration. These focused groups overlap and are not summed. The failed autosave
regression fails before its fix and passes afterward in
`/tmp/u7-preference-retry-red.log` and `/tmp/u7-preference-retry-green.log`.
Scoped ESLint and whitespace checks pass. Full producer and browser acceptance
remains assigned to U9.

Integrated radio checks exposed a starred-stations action that still bypassed
the shared mutation hook. The action now refreshes the authoritative radio and
catalog families. Its regression fails against the old action and passes after
the change; the final radio group passes thirteen tests across two files.
StrictMode checks distinguish a canceled initial read from the successful read,
and the mutation executes once. The playlist dialog's six tests pass with the
required query provider added to its fixture; their behavior assertions remain.

U8 implementation checkpoint, 2026-10-30: administrative readers now share
mutation holds, cancellation and freshness scheduling. Persisted job, user,
library-access, scheduler, media and settings changes refresh their declared
families. Runtime gauges use the coordinator's visible sampling policy. Provider
credentials come from deployment configuration; configuration checks sample
mutable key-file state. Admin editors preserve drafts and newer pending edits,
and access loss clears protected snapshots and one-time OAuth secrets.

Focused admin verification passes 114 distinct tests across sixteen files,
including rendered draft, role-loss, sampling and demand-driven checks. These
results were collected in focused groups. Library editing, user editing and
role editing each have a recorded failing regression followed by a passing fix.
The final inventory audit passes all 76 coverage and descriptor tests. It caught
and corrected two missing admin consumer entries and two obsolete family
expectations. Existing playlist and radio fixtures were also updated to exercise
the shared query lifecycle while preserving their behavior assertions.

The integrated frontend suite passes 2,850 tests across 229 files in
`/tmp/live-adoption-frontend-stable.log`. Both TypeScript projects and the
production frontend build pass in `/tmp/live-adoption-build-final.log`.
Full ESLint reports zero errors and eight existing warnings in
`/tmp/live-adoption-lint-stable.log`. Prose and whitespace audits pass; additions
record the delivered behavior and checks without changing earlier checkpoints.
The application now enables the shared freshness coordinator. U9 remains open
for real-browser and capacity qualification. Its asset audit also identified
stable cover URLs and retained palettes that need live refresh and access-reset
handling; those corrections remain required before completion.

The adoption-phase simplification review covered reuse, code quality,
efficiency, tests and comments. Both efficiency findings are fixed, and seven
redundant comments were removed. Behavioral proof tests were retained. The final
frontend results above cover the integrated changes; final code review remains
open after U9 qualification.

U9 fixture checkpoint, 2026-10-30: the managed preview executor now supports
isolated live-update qualification fixtures: 32 ordinary accounts and an admin
observer, owned media and catalog records, exact notification capture counts,
controlled projector pausing, and cleanup limited to the recorded run. Interrupted
cleanup retains its manifest. The fixture uses the configured password hasher
and preserves other runs and revision tombstones.

The authoritative backend group passes 75 tests with 2,244 assertions across
ordinary, native and PostgreSQL lanes in `/tmp/live-u9-backend-authoritative.log`.
The artifact is `var/test-runs/20261030-213032-f3d30cb6`. A subsequent preview
smoke check creates all accounts, commits exactly 32 notification captures and
cleans up with zero surviving owned resources. It also verifies the producer-start
timestamp added after the suite snapshot; its receipt is
`/tmp/live-u9-fixture-preview-smoke.log`. Scoped PHPStan, formatting and syntax
checks pass. Full browser, capacity, pool-release and recovery acceptance remain
open; these fixture results do not establish those outcomes.

The fixture's media source now uses the existing configured media volume, shared
between web and immutable workers. Private manifests remain under
`var/live-capacity`; only the recorded run's media leaf is removed during cleanup.
The focused CI check passes one test with 50 assertions, including separate paths,
permissions, missing-source cleanup and preservation of another run. The receipt
is `/tmp/live-u9-fixture-media-integration.log`, with artifact
`var/test-runs/20261030-213905-e7e9ecff`. Scoped PHPStan, formatting and syntax checks
pass. Actual worker processing and browser playback remain acceptance work.

U9 asset and terminal-read checkpoint, 2026-10-30: authenticated cover bytes now
use the shared query coordinator. Stable cover URLs refresh, missing artwork is
a successful empty result, and access resets discard image URLs and palette work.
Each renderer releases its own object URL. Palette extraction shares those bytes
and cannot restore results after ownership changes or unmounting.

Resource-level 403 and 404 responses now clear retained server data and finish the
attempted refresh generation. They preserve the real error without putting the
whole connection into recovery. A later change can retry the resource; transient
failures retain the existing retry behavior. Playlist readers no longer replace
these errors with fabricated empty successes. Dirty metadata text remains intact.

Characterization tests first reproduced stale artwork, delayed access removal,
ordinary artwork absence, and repeated terminal reads. The final frontend suite
passes 2,872 tests in 231 files (`/tmp/live-u9-frontend-stable.log`); the build,
both TypeScript projects and lint pass with eight existing warnings. Browser
capacity and recovery qualification remain open. The first capacity attempt
stopped before fixture creation because the CLI could not resolve its configured
media-storage path; this is a fixture setup failure, not a capacity measurement.

The media failure was traced to volume ownership: configuration resolved the
correct path, but the owned Docker volume was root-owned and unwritable by the
application user. Development initialization now prepares that writable volume
through its existing container routine, changing only the volume root's owner.
Read-only, imported and external media mounts are excluded. The regression test
first failed on the missing initialization; all 12 runtime tests now pass with
67 assertions, and scoped formatting and PHPStan pass. Receipts are
`/tmp/live-u9-media-volume-red.log`, `/tmp/live-u9-media-volume-green.log` and
`/tmp/live-u9-media-volume-phpstan.log`. The failed fixture left no database rows
or media files; its owned manifest was removed by scoped cleanup.

U9 browser qualification exposed an OAuth capture defect after repeated logins:
the generic SQL plan could cast an opaque token string on an unrelated route.
Capture now resolves UUID references inside explicit route checks before the
indexed lookup. Tests cover automatic, generic and custom plans, token CRUD,
metadata references, deletion cascades and tokens without users. The old function
failed two cases; the corrected integration group passes 42 tests with 961
assertions, with scoped PHPStan, formatting and syntax checks also passing.
Receipts are `/tmp/live-u9-token-capture-red.log` and
`/tmp/live-u9-token-capture-green.log`; the final artifact is
`var/test-runs/20261030-220059-822fe3fa`.

The preview received the corrected function in a bounded transaction, preserving
its identity and existing user/library counts. Its saved migration hash was
reconciled after verifying the installed body. Scoped cleanup then removed the
failed qualification run with zero surviving owned resources. The fixture also
now uses normal user creation's empty TOTP secret; its previous null value caused
a separate login failure. Real logins and the integration group above verify
that correction. Capacity targets remain unmeasured while the browser run resumes.

U9 query-startup checkpoint: React StrictMode replay could attach a discarded,
stopped coordinator to the selected query client. The provider now constructs
the client, recovery controller and coordinator together. A regression through
the actual provider first reproduced a mounted query that never read after
`live.ready`; it now verifies the initial read and a later hinted refresh.
All 40 focused lifecycle and scheduling tests pass, with scoped lint and whitespace
checks passing. Receipts are `/tmp/live-u9-query-startup-red.log` and
`/tmp/live-u9-query-startup-green.log`.

A real browser confirms that notification reads start and the unread count changes
from zero to one. Populated notification-list acceptance remains open: that run
exposed an invalid fixture category. Both diagnostic fixtures were cleaned with
zero survivors; `/tmp/live-u9-query-startup-browser.json` records those results.

U9 preference-absence checkpoint: new accounts legitimately have no stored audio,
layout or player preferences. Their initial 404 responses now finish the refresh
without marking the connection degraded or repeatedly issuing GET requests. This
applies only before a server version is known and while the request still belongs
to the current owner. It supplies no synthetic payload and preserves drafts,
pending writes, retries and conflicts; the first save still uses version zero.

The new tests first reproduced the failed-read classification for all three
endpoints. Focused checks cover first-save versioning, pending-write recovery,
authorization errors and stale reads. The complete frontend suite now passes
2,882 tests in 231 files (`/tmp/live-u9-frontend-after-startup.log`). Both TypeScript
projects, the build and lint pass; lint retains eight existing warnings. Capacity,
populated-view and recovery browser acceptance remain open.

U9 scan-fixture checkpoint: the owned fixture now prepares a tagged WAV and local
lyrics sidecars for a real console scan. It records the resulting catalog rows,
jobs, outbox events and queued deliveries. Cleanup waits for that owned work to
finish and locks terminal rows before deleting them; incomplete or unobservable
work retains its manifest. Metadata-provider and webhook preconditions are checked
without changing global settings. Notification fixtures now use a valid category.

The CI integration lane passes three tests with 116 assertions, including a real
second-connection lock conflict, Redis observation errors and preservation of
unrelated rows. Scoped PHPStan, formatting and syntax checks pass. The wrapper
exits zero; evidence is `/tmp/live-u9-scan-integration-final.log`. The real scan,
worker ingestion and mounted-browser refresh remain pending.

U9 capacity checkpoint: 32 authenticated principals passed the real HTTP,
WebSocket and rendered-view workload in the isolated preview. Across 320 ordinary
updates, p95 was 1,765ms and the worst principal's p95 was 2,146ms. The producer
committed 10,000 captured changes in 10,029.28ms; all final counts and visible
notification titles converged within a conservative 1,455.71ms after the final
commit. All 1,344 measured source reads completed within 956.83ms in Chromium.
Each client peaked at two concurrent GETs and four starts per second.

Worker identities remained stable, pool assignments returned to their prior quiet
envelopes, and sampled pending sends and projection/read errors remained zero.
Owned cleanup completed with zero survivors and an empty backlog. The passing
run is `/tmp/live-u9-capacity-sixth.log`; its timings and runtime identity are in
`/tmp/live-u9-capacity-sixth-receipts.json`. Chromium transport timings are recorded
separately from host callback timings after an earlier run exposed observer delay.
This measures the native PHP/Swoole preview with development/debug/Vite overhead;
it does not establish production-mode performance or long-duration soak behavior.
Recovery, populated-view and scanner browser qualification remain open.

## Baander loudness levels

Delivered, 2026-10-30, under the reviewed
[loudness target plan](docs/plans/2026-10-30-2007-feat-loudness-target-levels-plan.md).
Settings and Equalizer share Quiet (−23), Relaxed (−18), Balanced (−16) and
Louder (−14 LUFS), plus a custom numeric target. Fractional targets survive save,
fetch, local restoration and version rollback. Invalid input retains the last
valid target and shows a linked error message. The default remains −14 LUFS with
normalization off; the measurement algorithm and gain limits are unchanged.

Verification checkpoint: the controller suite passes 179 tests with 1,988
assertions in the CI image. Scoped PHPStan and PHP syntax checks pass. The
frontend passes 304 store/preference tests and 23 control/settings tests, both
TypeScript configurations and scoped ESLint. OpenAPI export and Orval drift
checks pass; regeneration also aligns the existing TOTP example with its current
source. The review's error-color finding is fixed and verified.

The real preview accepts −20.5 LUFS, shows it in Equalizer and restores it after
reload. Invalid input leaves the saved target unchanged, and choosing Balanced
saves −16. This browser check and 2 existing settings checks pass, including
narrow input containment, keyboard use and error contrast in all four moods.
The preview's original audio settings were restored. Prose and whitespace checks
pass. All planned units and the independent review's follow-up checks are closed.

## Recessed footer material

Delivered, 2026-10-30, under the reviewed
[recessed footer material plan](docs/plans/2026-10-30-1957-feat-recessed-footer-material-plan.md).
The selected recessed-display prototype A now supplies the music footer's shallow
inset shading and faint lower lip. The dark mood retains true black and distinct
near-black layers. Secondary text and the seek track receive local contrast
adjustments against the painted surface. The design guide reserves material
shadows for this one focal surface while it is visible; surrounding panels and
controls remain flat.

The user's later height and alignment requests reduce the rendered footer from
115 to 97 pixels on desktop and from 171 to 145 pixels on mobile, approximately
15%. Controls and artwork retain their sizes. Unequal top/bottom padding balances
the footer's top border and the display's lower lip: the visible desktop gaps are
both 7 pixels. The browser regression checks painted edges as well as element
bounds, so geometric centering alone cannot conceal this mismatch.

The additional song-list request removes artwork from `/music/songs` table rows
while retaining the album column's artwork. Its existing availability/playback
suite passes all 4 tests. A browser check confirms artwork is absent from idle
and playing rows while the album column retains its cover.

Verification checkpoint: all 4 focused cases pass in Chromium, Firefox and
WebKit. Each engine covers the 32 mood/accent combinations at 1280 pixels, plus
responsive and focus checks at 390, 768 and 1280 pixels. They check painted text
and control contrast, compact height, vertical alignment, containment and keyboard
focus. The 13 focused playback/layout cases also passed before the final one-pixel
optical adjustment. Both TypeScript configurations, scoped ESLint and whitespace
checks pass. Keyboard checks enter the seek control with Tab before testing its
focus ring and Arrow-key seeking. WebKit ran in the Playwright 1.60.0 Noble image;
the host lacks its required browser libraries. Design and testing guidance record
the painted-contrast and optical-centering rules. Browser qualification does not
establish physical OLED performance.

## Cohesive web styling

Delivered, 2026-10-30, under the reviewed
[styling repair plan](docs/plans/2026-10-30-1843-fix-cohesive-web-styling-plan.md).
The six units repair shared controls and web consumers, sizing, semantic color
roles and interaction states. The required checks and final screenshot review
are complete within the qualification scope recorded below.

- **U1 — Browser entry points:** real login reaches the current music route;
  route assertions and deterministic empty/populated catalog GET fixtures replace
  silent skips and stale navigation assumptions. Active-player checks use a local
  silent WAV without library scans or injected authentication-store state.
- **U2 — Token authority:** one generated table supplies web CSS and runtime theme
  data for all 32 mood/accent combinations. Explicit action, foreground, text,
  focus, input-boundary and status roles replace competing definitions and obsolete
  aliases. The existing React Native outputs are unchanged.
- **U3 — Shared controls:** global border-box sizing and inherited control styling
  fix the reproduced input overflow. Shared primitives use the intended radii,
  focus and validation states, readable hover/pressed fills, and reduced motion.
  Tooltips share the popup foreground contract; keyboard activation preserves
  native Space behavior and modified transport shortcuts.
- **U4 — Browsing and playback:** catalog, sidebar, queue, player and equalizer
  consumers use the shared roles and containment rules. Artwork decoration retains
  its identity while text receives measured contrast correction. Home navigation
  no longer marks every nested music route active.
- **U5 — Forms and administration:** authentication, appearance settings, account
  dialogs, library/user forms, administrative status and notifications use the
  shared controls and semantic roles. Appearance previews consume generated data;
  persistence remains a separate real-settings flow with preference restoration.
- **U6 — Integrated verification and guidance:** styling specs cover appearance,
  containment, focus, validation, overlays, resizing and selected feature states.
  The design and testing guides document ownership, historical HIG references,
  reproducible preview commands and the boundary between render and persistence
  checks. Documentation prose audits and whitespace checks pass.

The CI image `baander-runner:desktop-verification`, using Node 24.22.0, passes
66 token tests and 2,484 frontend unit tests across 196 files. Generated-output
checks pass, including unchanged native outputs. Final-source lint, both
TypeScript configurations, production build and asset verification pass under
`/tmp/styling-ci-final-source.log`; lint reports zero errors and eight existing
warnings. The React Native diff against the starting revision is empty.

Chromium's initial integrated run passed 97 of 100 tests. Three command-palette
cases failed because fixture URLs omitted the API's trailing slash; the corrected
15-case replay passes. The initial run includes all 32 appearance combinations
and the opacity, keyboard and resize assertions. The final 13-case replay passes:
eight refreshed characterizations, two Home/Albums selection assertions and three
command-palette cases with the final service-worker setting. Its receipt is
`/tmp/styling-navigation-evidence.log`. These follow-ups are separate evidence,
not a passing rerun of the original aggregate.

Firefox passes all six selected engine checks and its final command-palette
recheck. WebKit passed five initially and its corrected command-palette case
passes separately, covering the same six required checks. Only that mocked search group disables service workers so WebKit
can intercept catalog fixtures; real HTTP login remains, and playback retains
its stream worker. Playwright is 1.60.0: Chromium 148.0.7778.96, Firefox 150.0.2
and WebKit 26.4. Chromium and Firefox run on Linux; WebKit uses the matching
`mcr.microsoft.com/playwright:v1.60.0-noble` image for its system libraries.

All 34 final screenshots have been reviewed without remaining defects: fourteen
browsing/form images, twelve appearance images and eight refreshed navigation
images. Browser receipts are retained under `/tmp/styling-integrated`,
`/tmp/styling-appearance-evidence` and their sibling run logs. Detailed
movie-overlay and live scan-dialog states were source-inspected rather than
browser-qualified. The selected route/state checks do not establish visual or
accessibility coverage of every application state, native media quality or
general playback qualification.

Simplification and independent review are complete. Missing tooltips,
opacity-aware contrast evidence and modified transport shortcuts were corrected;
no actionable findings remain in
`/tmp/compound-engineering-1000/ce-code-review/20261030-190250-f115f518/review.json`.
The refreshed review is complete and ready to merge, with zero findings and no
pending verification. Documentation prose audits and whitespace checks pass.

Discovery checkpoint: Playwright reproduced 406-pixel login inputs inside
384-pixel containers, sidebar search overflow and black labels on dark controls.
Inspection covered login, empty music home and settings, including four theme
palettes with the violet accent. Independent source verification confirmed the
shared sizing and foreground omissions, accent/foreground mismatch and radius
drift. This was defect evidence; all 32 theme/accent combinations and responsive
interaction states still needed verification at that checkpoint.
After the preview refresh completed, a follow-up login probe covered four palettes
at 1280- and 390-pixel viewport widths. All eight observations retained the same
22-pixel field overflow; rendered light-theme login buttons also confirmed the
pale foreground/accent pairing.

Plan verification: all nine requirements have stable identifiers; the nine cited
repository paths exist. Completeness, consistency, scope and planning-readiness
checks passed. Prose audits accounted for the new roadmap section and retained
the measured dimensions as evidence rather than fixing future form widths.
Whitespace checks passed. No application code changed at this checkpoint.

Implementation-planning checkpoint: all nine requirements, three user flows and
five acceptance examples remain intact. Coherence, feasibility, design and scope
reviewers returned no findings. The plan names the 32-combination appearance
matrix, responsive and interaction coverage, current browser entry points and
repository verification gates. No application tests were run during planning.

Inactive-tab follow-up, 2026-10-30: Admin → Library tabs reproduced 1.6126:1
contrast in the dark theme because the shared tab trigger inherited the browser's
`ButtonFace` background. An explicit transparent baseline restores the intended
surface. Chromium passes five focused cases: all five tabs in each of four moods,
before and after keyboard selection, plus the existing selected-context-tab
regression. Firefox and WebKit each pass the dark-theme case. Scoped CI-image
ESLint and both TypeScript configurations pass; four Chromium screenshots and
the focused diff were reviewed without remaining issues. Receipts are retained
in `/tmp/admin-tabs-after.log`, `/tmp/admin-tabs-firefox.log`,
`/tmp/admin-tabs-webkit.log`, `/tmp/admin-tabs-ci.log` and
`/tmp/admin-tabs-review.png`. This focused follow-up does not rerun the earlier
integrated suite.

Footer-volume follow-up, 2026-10-30: the native range's `appearance: none`
removed WebKit's thumb without drawing a replacement; Chromium reproduced a
4-pixel control. The footer now uses the shared Slider with a 12-pixel handle,
retaining the 0–100 range, step one and mute/unmute behavior. Accessible labels
are forwarded to the actual slider thumbs.

Focused checks cover four moods at 390- and 1280-pixel widths, keyboard endpoints,
thumb movement and dragging, and unmuting. Chromium's initial run passed four of
five cases: two new volume cases and two existing playback cases. The remaining
768-pixel playback case timed out at the login heading before reaching the control;
its focused replay passes. Firefox and WebKit each pass both new cases. The
Firefox geometry assertion rounds subpixel measurements, including the observed
11.999992-pixel handle. Final scoped CI-image ESLint and both TypeScript
configurations pass. Focused review found no issues, and all sixteen theme
screenshots were visually reviewed across the three engines. Receipts are under
`/tmp/volume-{before,after,replay,firefox-final,webkit,ci-final}.log`; review images
are `/tmp/volume-review-mobile.png`, `/tmp/volume-review-desktop.png` and
`/tmp/volume-review-engines.png`. The full suite was not rerun for this follow-up.

Context-player follow-up, 2026-10-30: Chromium reproduced two Pause controls at
1280 pixels. The context panel now hides its player and separator while a track
or radio station is present, including paused tracks. Queue, Lyrics and Details
remain accessible; the idle player returns when neither source exists.

Six distinct Chromium checks cover playback at 390, 768 and 1280 pixels, both
footer-volume widths, and radio start/stop. Playback assertions require one
Pause/Seek control, one Seek control while paused, and an accessible Queue.
The initial run passed
four of five cases; the 1280-pixel case timed out at the login heading before
reaching the player. Its replay and the radio case pass in
`/tmp/context-player-final.log`. Final scoped CI-image lint and both TypeScript
configurations pass in `/tmp/context-player-ci-final.log`. Focused review found
no issues; mobile music, desktop music and radio screenshots were visually
reviewed. This conditional-rendering change alters no CSS; the full suite and
cross-browser checks were not rerun.

Footer-console refinement, 2026-10-30: the
[focused plan](docs/plans/2026-10-30-refine-footer-player.md) brings artwork,
metadata and timeline into one central display, with transport and volume beside
it on desktop and below it on mobile. Existing black, card and muted roles give
the dark mood distinct surface levels without a palette change. The seek control
now uses the shared Slider; clock updates remain outside the parent footer, and
recovery announcements, volume behavior and context-player exclusivity remain
intact.

Nine unit tests across two files pass. Final Chromium checks pass six cases;
Firefox and WebKit each pass two, including native playback advancing after a
pointer seek. The silent WAV fixture now serves byte ranges: its earlier response
left the native seekable range at zero. These fixture-backed playback checks
block service workers for cross-browser interception and retain real login; they
do not qualify production service-worker transport. Twenty theme screenshots
were reviewed: twelve Chromium images at 390, 768 and 1280 pixels across all four
moods, plus eight Firefox/WebKit mobile images. Browser appearance checks do not
measure physical OLED behavior.

Final CI-image scoped ESLint and both TypeScript configurations pass, as do the
production build and asset verification. Five review lenses found no issues;
the review receipt is
`/tmp/compound-engineering-1000/ce-code-review/20261030-193315-555e4fd5/review.json`.
Final logs are `/tmp/footer-console-accepted.log`,
`/tmp/footer-console-firefox-accepted.log`,
`/tmp/footer-console-webkit-accepted.log`, `/tmp/footer-console-unit-final.log`
and `/tmp/footer-console-ci-final.log`; build evidence is in
`/tmp/footer-console-ci.log`. The full frontend suite was not rerun.

## Equalizer visualization calibration

Delivered, 2026-10-30, under the
[calibration plan](docs/plans/2026-10-30-calibrate-equalizer-visualizations.md).
All seven modes now consume a documented input-spectrum contract, render the
selected mode visibly and clear stale readings. The changes are committed
locally; nothing has been pushed.

U1 adds the periodic-Blackman, 1/N display FFT with −100 to 0 dB bytes and actual
sample-rate/FFT metadata; the linear utility FFT remains unchanged. Spectral
features decode into the float-input API. U2 shares logarithmic peak-band mapping
from 20 Hz to the lower of 20 kHz and Nyquist, removes global-mean blanking, and
repairs renderer ownership, pause clearing and elapsed-time/DPR handling. U3
labels input analysis and RMS dBFS, marks loudness unavailable without a fresh
measured report, and qualifies the integrated Equalizer. The pre-EQ/gain tap,
normalization and output graph remain unchanged.

The complete DSP gate passes 155 tests, exits zero and verifies reproducible
builds and shipped-artifact parity. Native calibration passes two Chromium cases
with 32 signals across 44.1/48 kHz and four actual worklet spectra. Native and
WASM peak bins match; maximum level error is 0.254181 dB against a 0.4 dB bound,
and maximum off-bin displacement is 0.4 bin. The full-scale reference follows
the windowed magnitude convention, not 0 dBFS.

All 56 audio-graph cases pass across recorded runs: 36 initially, one corrected
neutral-analysis fixture, seventeen playback/stream cases after the harness's
package-resolution repair, and two WebKit cases in the matching Playwright image.
This is combined evidence, not a fresh uninterrupted aggregate. The fixture
repairs preserve existing assertions and resolve required Axios/TanStack packages.

Final CI-image verification passes 2,515 unit tests across 202 files, scoped
ESLint, both TypeScript configurations, production build and asset verification.
The earlier full-project lint run has zero errors and eight existing warnings.
The final focused renderer group passes 26 tests, including the actual Particles
StrictMode ownership race. Focused review found no remaining issues.

The two Chromium UI cases pass all seven modes in four moods and the quiet
1008 Hz, 0.001-amplitude signal in the four engine modes. All 32 screenshots
were visually reviewed, including the small Circular response. Pause/resume and
390/1200-pixel resizing pass without page errors. Chromium is 148.0.7778.96;
receipts are `/tmp/visualizer-browser-complete.log` and
`/tmp/visualizer-final-qualified.log`.

The UI fixture retains real authentication and preference reads but acknowledges
preference writes locally and blocks service workers for deterministic signal
interception. It does not qualify preference synchronization or production
service-worker transport. These checks establish input analysis and renderer
behavior, not acoustic response, post-EQ measurement or audible output quality.
Documentation prose audits and whitespace checks pass.

## Cohesive operator manual

Delivered, 2026-10-30: the [operator manual](docs-book/part-1-operator-guide/README.md)
now connects component responsibilities, storage, setup, daily tasks, monitoring
and recovery. Five new explanatory chapters join the twelve existing narrative
chapters. Task, symptom and component indexes provide direct entry; 41 command
reference pages, including their index, preserve the installed/development
execution distinction. Adjacent developer and registry documentation links back
to the manual. The change covers 66 documentation files and changes no runtime code.

Unit checkpoints: U1 explains components and data ownership; U2 updates the source
build recipe and connects queues, outbox, scheduling and worker lifecycle; U3
separates discovery, ingestion, enrichment and playback; U4 corrects credential
revocation and notification delivery; U5 connects diagnostics, upgrades, backups
and the independent registry; U6 reconciles command references; U7 integrates
navigation and reader acceptance. Source checks corrected unsupported ingestion
and watcher claims, stale storage descriptions and misleading health/recovery
advice. Existing useful procedure details and command family headings remain.

Verification: the public backend runner provisioned the current documentation
into the CI image and passed command coverage with 4 tests, 4 assertions and zero
skips. Source discovery matched 146 command declarations to index entries and
pages. All 66 documentation files received prose audits with intentional factual
and structural changes accounted for. Shell syntax checks passed for 166 fenced
examples; 805 local destinations and fragments passed, including 43 inbound links
from untouched documentation. Whitespace checks passed. The simplification preflight
found no executable code in scope.

Independent reader walkthroughs covered all five acceptance examples from the
index and direct task/symptom entries. They found and resolved an omitted frontend
upgrade build and a diagnostic that pointed at the web image instead of the worker
image. The focused code review, including an independent local adversarial read,
found two further corrections: disclose the unsupported worker provider-secret
handoff and keep installed media paths consistent in command references. Both
fixes were verified with no remaining findings. Cross-model review was disabled
by checkout configuration.

Qualification limits remain explicit: these checks did not deploy a new instance,
scan user media, rotate secrets, replace workers or restore data. The current
worker credentials contract cannot provision private Discogs tokens for queued
enrichment; documentation now identifies that runtime gap. Coordinated application
restore, actual registry destination/RPO/RTO and whole-host capacity remain
unqualified. The revised source-build procedure is source-verified, without a new
bootstrap run. Work is committed locally on `docs/cohesive-operator-manual`;
publishing remains separate.

Requirements checkpoint, 2026-10-30: the user confirmed an easy-to-follow manual
built from the existing operator book, covering understanding Baander, setup,
daily operation, maintenance and recovery. The
[implementation plan](docs/plans/2026-10-30-1817-docs-cohesive-operator-manual-plan.md)
defines 11 requirements and five acceptance examples. Operational facts and command
examples must be verified against source before prose is revised with `prose-fix`.

Independent grounding verified four repository claims with no refutations. All
four Ready for Planning checks pass: complete, consistent, focused and usable.
Source paths, sequential IDs, prose preservation and whitespace checks pass.
That checkpoint delivered requirements only.

Planning checkpoint, 2026-10-30: the knowledge-work plan now defines seven
implementation units and six technical decisions. All 11 requirements and five
acceptance examples are preserved, and both deferred planning questions are
resolved. Research identified corrections to command execution contexts, supported
ingestion and watcher behavior, and health/recovery limits for source verification
during the rewrite.

Confidence checks pass. Six document reviewers covered coherence, feasibility,
scope, design, security and adversarial review, with zero findings, fixes or open
decisions; cross-model review was disabled by checkout configuration. Plan prose
preservation, requirement/example preservation and whitespace checks pass. Manual
implementation remains open. Planning ran source and document checks only, with
no tests or runtime commands.

## Configurable development libraries

Delivered, 2026-10-29: the managed preview has one Movies library backed by the
directory in the ignored local configuration. Its application path is
`/media/movies`; the app, execution worker and control worker all mount it read-only.
Path validation reports an existing, readable directory. Repeated startup preserved
the same library ID, and the browser administration page shows "Last scan: Never".
No scan or data reset ran. The preview is ready on code commit `ba575053`;
configuration, startup, privacy and runtime delivery are complete.

Runtime integration correction, 2026-10-29: the preview exposed Messenger logs
on stdout before the library command's JSON. Six development and production
Monolog stream paths now use stderr, preserving their handler names and logging
levels. Real stream-handler probes confirmed Messenger INFO and general/security
WARNING records reach stderr while stdout remains valid JSON in both environments.
This corrects the existing console output contract required by startup provisioning.

Implementation checkpoint, 2026-10-29 (U3): local configuration is excluded from
Git, Docker contexts and preview snapshots, including accidentally tracked files.
The tracked example and development guide describe precedence and manual scanning.
The Docker context probe excluded 35 host artifacts and retained 25 source markers.
The CI image passed the full tooling run (676 tests, 2,924 assertions, seven existing
skips). After review fixes, 71 affected tests and 291 assertions passed, PHPStan
reported no errors, and all 280 tooling PHP files passed formatting. The seven-lens
code review found one argument-parsing bug; an option separator and Symfony parsing
regression fix it. A mixed-catalog test also covers validation before creation.
The runtime delivery checkpoint above completes acceptance.

Startup checkpoint, 2026-10-29 (U2): after runtime readiness, startup lists
libraries and creates missing configured records. Matching records remain intact;
conflicts and command failures prevent readiness from being saved. Scanning stays
manual. Reconciliation, activation and update checks passed in the CI image:
28 tests and 121 assertions. The four changed PHP files passed the formatter;
targeted PHPStan found no errors.

Configuration checkpoint, 2026-10-29 (U1): local definitions now produce saved
library settings and read-only media mounts. Worktree files override the primary
checkout; invalid definitions fail before state creation. Mount changes require a
stopped instance. The authoritative CI image passed 28 tests and 102 assertions;
the four changed PHP files passed the tooling formatter. Separate Git directories
without a recorded primary checkout require a worktree-local configuration file.

The [implementation plan](docs/plans/2026-10-29-1726-feat-configurable-development-libraries-plan.md)
adds local library definitions in `dev.local.json`, read-only media mounts and
idempotent library creation during preview startup. The requested Movies library
uses the developer's video directory; scanning remains manual.

Planning checkpoint, 2026-10-29: ten requirements and three implementation units
cover configuration, startup reconciliation, privacy and actual preview setup.
Four document-review personas completed; one conflicting optional-field description
was corrected, with no remaining findings. Prose and whitespace checks passed.
Implementation and runtime verification remain open.

## Paused seek readiness

Delivered, 2026-10-30: the user authorized the application correction after
merging tooling into master and pushing both `origin/master` and
`baander-github/master` at `5fc3f901`. The correction is implemented on
`fix/paused-seek-readiness` under the reviewed
[implementation plan](docs/plans/2026-10-30-1141-fix-paused-seek-readiness-plan.md),
committed as `b31baff1`.

The two-file application patch removes both extra 21-second paused lookahead
gates. It retains closed paired-track cursor coverage, subtitle completion,
execution fences and running-playback throttling. Before the fix, the cursor group
ran two tests and 11 assertions with one failure; the running-pause control ran
one test and two assertions with one failure. Host verification now passes 32 tests
and 259 assertions. The initial CI backend run passes 117 tests and 5,040
assertions under `var/test-runs/20261030-105007-4701e8e4`. The final rerun passes
117 tests and 5,039 assertions: ordinary 102/553, native 1/4,367 and services
14/119, with cleanup exiting zero. Its evidence is retained under
`var/test-runs/20261030-110149-359dc578`. Three player
test files pass 245 tests. The two changed PHP files pass CI static analysis and
basic whitespace checks. Application Deptrac reports zero violations, errors or warnings,
with 484 existing baseline skips, 17,169 allowed and 18,283 uncovered dependencies.

Independent code review completed with zero findings under
`/tmp/compound-engineering-1000/ce-code-review/20261030-114820-paused-seek`;
its frozen verdict was not ready because CI, consumer, live and preview
acceptance were pending at that snapshot, with no source findings. Caller
verification has now closed those gates without changing the frozen review.
Five simplification lenses found no changes. The CI image and worker image were built from verified
bases plus exactly the two frozen source files, with hash proof. The final CI
image is `sha256:024ebca6614343faadbd29a253181b9e71b63a81fe854fcaa91f8602d5e6c777`
and the worker is
`sha256:cf6eb035fd2b9d427b3691c265d7f878afec62b340820dc363e230a5414c45ce`.
The temporary image helper now copies files without inheriting private staging
directory modes. A real loader probe with UID zero and dropped capabilities
reproduces permission-denied exit two before the fix and exits zero afterward;
application source is unchanged.
The full root-image build was intentionally cancelled while recompiling unchanged
TSDuck; that cancellation is not application-failure evidence.

The public live media runner passes with original status zero, cleanup status zero
and removal verified under `var/test-runs/media-control-1793358242-2704041`.
Seek readiness is 17.679436945 seconds within the unchanged 30-second limit.
All 162 control acknowledgements pass, with maximum 0.239333687 seconds and p99
0.22219033 seconds. The run records 1,226 heartbeats with maximum gap 0.029245592
seconds, 778 full-occupancy samples and cancellation retirement in 0.223762875
seconds. Byte, status and ownership checks all pass.

`make preview-up` completed with exit zero after the full controller runtime and
worker build. The preview at
[preview-fd790a887b6899cb.baander.test](https://preview-fd790a887b6899cb.baander.test)
is ready, with web and workers ready. Running, target and worker-snapshot identities
all match `a9c02556`. Decoded `libraries-after.json` exactly matches
`/tmp/baander-paused-seek-work/libraries-before.json`: Movies/Music IDs, paths,
creation/update timestamps and null scan timestamps are retained. The local
configuration hash is unchanged (`7803eaff`); no reset or scan ran. All acceptance
for the tooling plan's U11 and this plan's U1 is complete.

## Cohesive project tooling

Requirements checkpoint, 2026-10-29: the user confirmed the scope in the
[implementation plan](docs/plans/2026-10-29-1854-refactor-cohesive-project-tooling-plan.md)
after the whole-package audit of `packages/baander-tooling`. The intended outcome
is shared configuration, image selection, application layout, Docker targeting,
process completion, cleanup and evidence contracts, with every retained correctness
finding repaired. Necessary caller, build and CI wiring belongs in scope.
Commands, flags, configuration and interfaces may change incompatibly when all
consumers migrate in the same delivery, including installed or generated controllers
and retained-state readers. Preserve local data and remove obsolete interfaces
without speculative compatibility layers.

Planning checkpoint, 2026-10-29: the plan defines 11 ordered implementation units
and 11 technical decisions, preserving all 27 requirements and 17 acceptance
examples. Mandatory document review completed through five personas: coherence,
feasibility, scope, security and adversarial. One P2 finding was corrected: ordinary
and nested Docker operations use immutable connection transport even if the
original named context changes. The plan includes a verification case for that
boundary. No unresolved findings or product questions remain. Implementation and
runtime qualification remain open; no implementation tests or runtime probes ran
during planning.

Foundation checkpoint (U1–U2), 2026-10-29: local commits `593ac7af` and
`3305507a` add shared typed configuration, image and application-layout contracts,
checked process completion and Docker connection ownership. Explicit stop/drain
operations retain their observed outcomes, requested SIGINT reaches the child,
and completed output is released. Private TLS snapshots and recorded daemon
identity preserve the selected connection across context changes. Combined host
verification passes 108 tests and 419 assertions. Full tooling PHPStan reports no
errors, and formatting passes across all 304 tooling PHP files.

Five-persona phase simplification completed with two quality extractions, one
bounded-diagnostic allocation fix and two redundant comments removed; all proof
tests remain unchanged. A reproduced exit 139 occurred when a retained timeout
exception kept SignalScope alive until late destruction. That failure is fixed;
the subprocess regression covering shutdown-callback execution now exits 0.
Implementation units U3–U11 and authoritative CI-image and live qualification
remain open.

Resource and controller checkpoint (U3, U5), 2026-10-29: local commits
`a852c3d6` and `20819351` add durable resource ownership and complete controller
publication. Cleanup uses its own execution budget, retains unresolved receipts
and preserves the primary failure alongside cleanup diagnostics. Inventory runs
through a package-owned entrypoint, uses saved layout evidence offline and accounts
for special-file metadata without opening those files. U3 includes the prerequisite
ApplicationImage contract from U4; the remaining build/layout work in U4 is active.

Controller generations retain their lease through shutdown. Uncertain publication
keeps the affected generation available for inspection and recovery. Root
integration passes 108 tests and 404 assertions; the inventory CLI-close follow-up
passes one test and four assertions. Full tooling PHPStan passes, and formatting
checks 320 PHP files with zero fixes. U4, U6–U11, authoritative CI-image and live
qualification, and final code review remain open.

Build/layout checkpoint (U4), 2026-10-29: commit `fea50d77` delivers the shared
build contract and CLI, which supply the required `APP_HOME` to
the Dockerfile; environment-aware entrypoints replace their process and preserve
PID 1 behavior. CI and Compose callers use that contract. The image-catalog helper
preserves retained storage-image pins, and the default PostgreSQL image build
continues to use tracked configuration.

Host verification passes 29 tests and 147 assertions for build, topology and
initialization; 31 tests and 157 assertions for lifecycle and ledger behavior;
nine tests and 38 assertions for final CLI/status boundaries; and two Compose
tests and 15 assertions. Two initialization-cleanup regressions fail before their
fixes and pass in the 29-test group. U3/U5 simplification applies two quality
changes and removes two redundant comments while retaining all proof tests.
Full tooling static analysis passes; formatting checks 327 PHP files with zero
fixes.

The shared Docker client accepts Compose execution options without changing its
completion policy. Its 12 existing tests pass with 49 assertions; the added
expected-exit and timeout control passes with three assertions. Scoped static
analysis and formatting pass.

The default CI image builds successfully as `baander-ci-cohesive-tooling:local`
with PHP 8.5.2 and Swoole. An alternate-layout worker image builds successfully
at `/srv/baander` as `baander-worker-cohesive-alternate:local`. Real entrypoint
probes verify PID 1 behavior and the absence of Xdebug and PHPUnit; ledger-owned
resource cleanup succeeds. A direct build without `APP_HOME` fails and names
the supported build command. U6–U11, final workflow gates, the authoritative
CI-image suites on the current tree, live preview qualification and final code
review remain open.

Migration foundation checkpoint, 2026-10-29: shared RepositoryConfiguration
selects the local configuration file and ignores developer overrides in CI.
Inventory receives the resolved configuration snapshot. Neutral Source and Paths
helpers provide the shared ownership needed for the pending workflow migrations.
Resource cleanup uses immutable container IDs, detects renamed resources and
retains unresolved receipts. DisposableContainer also removes by immutable ID
with an independent cleanup context.

Current-tree integration passes 20 configuration, fixture and startup tests with
54 assertions; startup-fixture changes remain part of U7. Ledger identity checks
pass ten tests and 41 assertions, and disposable-container checks pass four tests
and 19 assertions. Scoped static analysis passes across 37 files; formatting
checks 14 files with zero fixes. This checkpoint covers the shared configuration,
Docker client, process specification and resource foundation. U6–U10 delivery,
family integration, authoritative CI-image and live qualification, and final code
review remain open.

Registry implementation checkpoint (U9), 2026-10-29: package-owned static
analysis helpers and injected storage validation remove incidental test-support
dependencies while preserving the self-contained relay qualification build
context. Nested ownership ledgers retain the saved Docker target and primary
failure through cleanup. Image cleanup uses immutable IDs across lost tags,
foreign retagging and uncertain build outcomes.

Root integration passes 50 registry tests and 251 assertions, with one skip for
unavailable host clang-tidy 19. The separate close-failure control passes one test
and six assertions. Full tooling PHPStan passes across 373 files before the final
three-file U9 refactor; the focused follow-up also passes for all three files.
These are host-only checks. U6–U8, U10–U11, final CI-image and live qualification,
and final code review remain open.

Backend implementation checkpoint (U10), 2026-10-29: backend workflows use
shared checked execution, finite budgets, canonical layout and catalog image IDs.
Compose recovery retains immutable Docker connection ownership and resource
receipts; named-resource guards prevent cleanup of unrelated resources. A child
readiness handshake replaces the 100 ms startup assumption. Later-lane failures
remain visible, and coverage, source-archive and exit-status contracts are
preserved. Shared target resolution uses the saved invocation target even when
ambient Docker settings change.

Root event evidence records 103 passing backend and Compose cases: 22 comparison,
26 coordinator, 36 coverage, 11 static-check and eight Compose cases. The combined
309-test run remains nonpassing with 28 errors in worker classes that loaded the
old target resolver before the runtime edit. Full integration acceptance remains
open. The separate neutral Source suite passes 13 tests and 58 assertions,
and Docker-client checks pass 14 tests and 56 assertions. Scoped static analysis
passes across 16 files. Formatting processed 18 files and fixed Backend/Runtime.php.
U6–U8, U11, final CI-image and live qualification, and final code review remain
open.

Phase simplification checkpoint (U9–U10), 2026-10-29: five reviewers examined
`a2253e72..e05fad63`. Reuse, efficiency and test review returned no findings.
Quality review removed the unused backend Runtime volumes property and its
append; comment review removed three redundant production comments. All proof
tests remain unchanged. The canonical BackendCoordinator rerun passes 26 tests
and 469 assertions; scoped static analysis and formatting pass across four files.

A fresh WorkerCleanup and WorkerOperatorResources run passes 41 tests and 263
assertions, resolving the 28 mixed-loaded-class errors recorded above. The earlier
combined run remains nonpassing evidence; full CI acceptance remains open.

Development migration checkpoint (U6), 2026-10-29: implementation is prepared
for saved versioned release contracts carrying configuration, layout, image IDs,
Docker target and source inputs. Mount validation precedes mutation; disposable
helpers have independent cleanup, and historical image retirement works without
current media paths. CLI composition captures environment, local settings and
budgets. Worker-domain consumers receive explicit `APP_HOME` and distinguish
admission checks from retirement checks.

The final affected development group passes 61 tests and 242 assertions. An
earlier 280-test integration run had 12 fixture/configuration failures: nine are
covered by that final group, while separate bootstrap and database controls pass
one test/four assertions and two tests/six assertions. Local/invocation checks
under `CI=1` pass 13 tests and 39 assertions. These follow-ups repair the named
failures without establishing a passing 280-test run. Worker-domain checks pass
327 tests and 534 assertions; focused domain static analysis passes nine files.
Full tooling static analysis passes across 376 files, and formatting fixes 12
files. Six generated-manifest Docker cases, actual preview state conversion,
U7–U8/U11 delivery, final CI-image and live qualification, and final code review
remain open.

Acceptance and fixture migration checkpoint (U7), 2026-10-29: acceptance,
HTTP, web, messaging and startup workflows use shared configuration, checked
execution and the Docker client, with catalog-pinned images, canonical layout,
named finite budgets and durable scoped cleanup. Browser preparation checks the
installed Playwright version; recovery uses its saved target receipt. JSON stdout
and diagnostic separation remain intact. The raw Support::run helper and obsolete
HTTP drill are removed.

The initial affected run reached 86 tests and 361 assertions with eight fixture
failures. All eight are repaired; the focused identity/fixture run passes 14 tests
and 71 assertions, and the separate cancellation control passes one test and four
assertions. Configuration/runtime/startup integration passes 20 tests and 54
assertions. These follow-ups do not establish a passing 86-test aggregate. The
full 376-file static-analysis result and 12-file formatting normalization are
recorded in U6 above. U8/U11 delivery, final CI-image and live qualification, and
final code review remain open.

Worker migration checkpoint (U8), 2026-10-29: worker workflows use shared
transport, image catalog, layout and named budgets. Typed operator run, topology,
provisioning, scenario, command and resource collaborators replace the untyped
parameter bag. Explicit container provisioning preserves PHP `-d` arguments and
literal `--rm` arguments.

Live and completed evidence modes reject corrupt complete records; only live
mode tolerates an incomplete trailing record. A bounded completion cursor avoids
replaying historical output. Measurements stop cooperatively and join/drain the
producer before final verification; reports publish atomically. PostgreSQL
sampling releases completed captures.

The final worker, drill, sampler, legacy and resource group passes 116 tests and
532 assertions. The earlier canonical cleanup/resource group passes 41 tests and
263 assertions. Full 376-file static analysis and the 12-file formatting
normalization are recorded above. U11 delivery, live worker/cgroup/operator
qualification, final CI-image and preview acceptance, and final code review remain
open.

Boundary and integration checkpoint (U11), 2026-10-29: the tooling Deptrac
and source-policy gates now run through Composer and CI. The policy checks raw
process/Docker calls, manual loaders, image/layout literals and obsolete helpers;
callsite exceptions have reasons and fail when their source anchors become stale.
Composer consumers replace manual loading, and the obsolete Development Runner
and Docker helpers are removed. Standalone relay inventory verification owns its
Docker resources and cleanup receipts; the public registry-image wrapper supplies
resolved transport and finite budgets. Guides and CI use the current contracts.

Host gate/inventory verification passes 54 tests and 384 assertions; source policy
passes 32 tests and 100 assertions; the public CLI passes two tests and 28
assertions. Full tooling static analysis passes across 380 files. Deptrac reports
zero violations, errors or warnings, with 4,029 allowed and 321 external uncovered
dependencies. Loader integration initially had five failures across 73 tests and
490 assertions. After executable fixture copies were repaired, the web follow-up
passes eight tests and 41 assertions; the full loader aggregate remains unverified.
The application-build contract passes eight host tests and 32 assertions.

Five-lens simplification of U6–U8 applied one quality change and five comment
changes; follow-up verification passes 10 tests and 176 assertions and three-file
static analysis. U11 simplification applied one efficiency change and seven comment
changes in two groups; source policy, two-file static analysis and style checks
pass. The full 942-test CI-image run exceeded Composer's 300-second timeout before
completion and reported two PHP-path fixture failures, since repaired on the host.
Its retry, final CI-image and live preview acceptance, worker and relay
qualification, and independent code review remain open.

Live acceptance follow-up: DNS and web runtime qualification pass in the rebuilt CI
image. Retained-state recovery exposed a valid historical Dockerfile form that the
converter rejected: a literal APP_HOME environment value followed by a variable
WORKDIR. The converter now accepts that observed form while rejecting ambiguous
declarations; 17 retained-state tests and 190 assertions, scoped static analysis
and formatting pass. Worker scenario deadlines now use their selected catalog
budget, with a separate 2,000-second autoscaling aggregate and configurable profile
budgets. The budget regression group passes 14 tests and 42 assertions, and the combined
configuration/resources/cleanup group passes 55 tests and 305 assertions. Integrated
host checks pass 76 tests and 372 assertions, full tooling static analysis, and
formatting across 383 files. Deptrac reports 4,066 allowed dependencies, 326
external uncovered dependencies and zero violations, warnings or errors. Full CI
and live acceptance remain open.

Integration repair checkpoint, 2026-10-29: acceptance polling now uses the shared
signal owner. Its eight tests and 53 assertions pass with displayed warnings
enabled. Composer fixture helpers now load lazily as classes; vendor-only consumers
and the affected web/worker fixtures pass 30 tests and 105 assertions. A missed
worker-recipe test consumer now supplies its explicit application root. Docker
client fixtures use the executing PHP binary; 14 tests and 56 assertions pass.

Recovery failures return accepted attempts to blocked state without replacing the
primary error; 22 activation/update tests and 142 assertions pass. Worker validation
runs as the image user, while the trusted controller has read access to retained
Composer files. Validation precedes resource creation; bounded inputs and creation
intent precede admission, and IPC cleanup uses the shared ledger. The worker group
passes 49 cases and 141 assertions with six live-image cases still gated; a loader
follow-up passes 13 tests and 43 assertions.

Cleanup now enumerates resource identities without asking Docker for filesystem
sizes of unrelated containers. Both ownership implementations pass 18 tests and
101 assertions; the new relay success case also passes with the shared executor.
Three retained cleanup receipts from live checks were reconciled after this fix.
The CI-image repair subset passes 53 tests and 396 assertions, and the unchanged
Python-subject boundary passes two tests and two assertions. These are focused
results; the full CI aggregate remains open.

Live DNS, web runtime, security, shutdown, outbox, producer and worker-containment
checks pass. Messaging passes 165 tests and 2,488 assertions in the CI image. The
retained blocked preview recovered to ready with its workers ready and data kept.
Its current-source refresh, configured-library/browser checks, worker and registry
qualification, alternate-layout checks and final independent review remain open.

Qualification repair checkpoint, 2026-10-29: source discovery now works from
source archives and linked worktrees; 32 tests and 73 assertions pass, with one
skip requiring clang-tidy 19. Private operator diagnostics pass five tests and
41 assertions. Real read-only filesystem probes confirm that temporary-file and
directory creation fail without writable temporary storage and succeed with a
private 16 MiB tmpfs at mode 0700; the operator regression passes two tests and
17 assertions. Generated worker manifests pass the real production-recipe check:
six tests and 78 assertions, with cleanup verified.

The previous full CI run failed with 942 tests, 4,604 assertions, 16 errors, two
failures, one warning and seven skips. Its three root causes have scoped fixes;
those follow-ups do not establish a passing aggregate. The v5 CI image
`sha256:76ae97dc04286e70bbbf28eab8755fdf51438b584c07d8b1d92719e6e5b3ae09`
was running the full suite at this checkpoint. Tooling static analysis passes
across 384 files, and formatting passes across 385 files. Five-lens simplification of the latest
diff applied two comment changes; reuse, quality, efficiency and test review
required no changes.

Preview shutdown now succeeds, but its saved blocked phase still requires
recovery before changing media configuration. Final CI results, preview and
remaining live qualification, and independent code review remain open.

Build and live qualification checkpoint, 2026-10-29: builds use the captured
daemon's default BuildKit builder and verified temporary aliases for selected local
image IDs. Shared bases retain an independent verified reference; dangling inputs
fail before mutation, and cleanup owns no shared base. CI v6 build-contract checks
pass 58 tests and 347 assertions without skips. Full tooling static analysis
passes across 387 files and formatting across 388. Deptrac reports 4,246 allowed
and 357 external uncovered dependencies, with zero violations, warnings or errors;
source policy passes. Five-lens simplification applied two comment changes and
required no reuse, quality, efficiency or test changes.

The real v9 worker operator and Redis/PostgreSQL lifecycle checks pass with
cleanup. The backend native lane passes 16 tests; the service lane passes 14 tests
and 8,983 assertions. All three websocket proxy variants pass. Relay API/rqlite
inventories and the TLS cluster contract pass, as do 89 encrypted-backup CLI
contract checks, restore and the 600-second cadence check. Relay cleanup passes.
The current alternate-root worker runs at `/srv/baander` as PID 1 without
development tools; inventory integrity passes for 52,535 files. Redistribution
review remains not qualified with 5,404 gaps, a separate existing classification.

Host Playwright passes preview login and Library UI checks with normal TLS
verification and zero page errors. Repeated startup preserves the same two library
records with null scan timestamps; no reset ran. The v5 full CI run failed with
984 tests, 4,660 assertions, five failures and seven skips; its failures concern
stale identity-format assertions and fakes. The registry inventory regression
passes one test and 199 assertions. The complete worker cleanup class passes
41 tests and 273 assertions without failures or skips; scoped static analysis,
style and source policy pass. The subsequent v7 run was interrupted; final CI
acceptance continues below. Build ownership landed in local commit `7a22c44d`;
subsequent repairs remain under qualification.

Callback and fixture checkpoint, 2026-10-30: worker callbacks receive the
actual randomized web-container name instead of deriving it from the PostgreSQL
name. The checkpoint regression passes one test and five assertions. Fixture
warmup now waits for acknowledged pause and resume before publishing capacity
readiness; measurement thresholds and samples are unchanged. The worker-fixture
subset passes 20 tests and 31 assertions. These focused results do not establish
live pressure qualification.

The full v8 CI suite ran against image
`sha256:87ccc6f4031ba50beb3262845c8cca4c7be2da5d03f4df823eb014f75b3d4bee`;
its source proof is retained under
`var/test-runs/ci-checkpoint-overlay-1793315529-1565964/source-proof.json`.
Capacity v8 uses current host source with `BAANDER_TEST_CHECKOUT_IN_IMAGE=0`.
The fixture difference from that image is deletion of a redundant warmup comment;
executable source is unchanged. Later sampler, backend and controller repairs need
focused CI proof from an updated image. The acceptance results below remain
incomplete.

Final review and sampler checkpoint, 2026-10-30: independent code review
completed eight lenses and confirmed two P2 defects: nested backend cleanup owners
received fresh budgets, and a superseded installed controller could wait on its own
generation lease during conversion. Both findings and a follow-up prepared-checkout
error-handling correction are now fixed and verified. The source-proven v10 CI
image passes 116 tests and 1,031 assertions; the review findings are closed.

The five-file sampler repair admits interrupted completion only with typed proof
of the exact contained worker pair, exhaustion and continuous drain evidence.
Implementation and independent focused review are complete. Verification passes
130 tests and 204 assertions, followed by 27 tests and 33 assertions; source policy
passes 32 tests and 100 assertions, with scoped static analysis and style clean.
Live retry was pending at this checkpoint.

CI v8 gates pass static analysis across 388 files and formatting across 389.
Deptrac reports 4,262 allowed and 360 external uncovered dependencies, with zero
violations, errors or warnings; source policy passes. Registry subjects pass two
tests and two assertions. Docker-context checks exclude 35 artifacts and preserve
29 markers; all four Xdebug scenarios pass. The full v8 suite finished with
1,022 tests, 5,052 assertions, one failure and seven skips. Its only failure was a
network fake, now corrected; this is failed aggregate evidence.

Capacity CPU passes with 160 acknowledgements (maximum 0.369 seconds) and 1,451
heartbeats (maximum gap 0.0227 seconds). Memory qualification failed on sampler
exit 137 before the typed-proof repair; cleanup was verified, but live repair
acceptance was pending. PID, media and container browser qualification were also
pending at this checkpoint. These results do not establish full pressure acceptance.

Observer and review-fix follow-up, 2026-10-30: CI v11 passes 13 observer tests
and 351 assertions, static analysis across 390 files and formatting across 391.
Deptrac reports 4,332 allowed and 361 external uncovered dependencies, with zero
violations, warnings or errors. Autoscaling profile one passed its 369-second
callback and cleanup. Profile two failed on a transient admission read-lock timeout
of 50 milliseconds. The fixture now retries within the existing 250-millisecond
bound; independent focused review is complete. Live retry was pending at this
checkpoint.
Production behavior and measured thresholds are unchanged.

Capacity v10 CPU passes again, but its memory pressure probe exits 137 after
`oomTargetDied`; cleanup succeeds. Extending contained-probe completion with owned
failure identity and terminal proof was added in the follow-up below. These
results do not establish passing memory or PID qualification.

Final integration follow-up, 2026-10-30: contained-probe completion now checks
exit status before sampler evidence. Exit 137 is accepted only with strict proof
of the terminal event, native process identity, exhaustion and heartbeat/drain
continuity for the exact worker pair. Unexpected statuses remain failures, and
only the main qualification path publishes the final result. Focused review of
these deltas has no outstanding findings after the test correction; final CI and
live acceptance were still pending at this checkpoint.

The autoscaling fixture retries only the exact admission lock-deadline error
within its existing 250-millisecond observation budget. Callback identity and
acknowledged pause/resume warmup remain as recorded above. The DASH native observer
now matches the actual `manifest.mpd`; the network fake models temporary aliases
and independent images, and production validation checks `start-worker.sh` against
`APP_HOME` and the saved layout. The earlier review-fix CI group passes 116 tests
and 1,031 assertions. CI v11 checks 390 static-analysis files and 391 formatting
files, with 4,332 allowed and 361 external uncovered dependencies and zero Deptrac
violations, errors or warnings. Later acceptance evidence follows.

Persisted-drain and observer checkpoint, 2026-10-30: memory v14 failed when
the last 50-millisecond sample preceded control drain and exit by 10 milliseconds;
cleanup was verified. The verifier now captures the actual persisted `control.json`
from the exact stopped container. It checks the saved IPC volume and source, boot,
epoch, namespace, not-ready/draining flags and time within the existing two-second
continuity bound. No samples are fabricated and thresholds are unchanged. The
stopped-volume copy proof passed with cleanup; independent focused review found
no outstanding issue. Final memory and PID qualification were pending at this
checkpoint.

Media v15 exposed an observer-only `APP_HOME` assumption: the native execution
daemon intentionally supplies only `PATH`, `LANG` and `LC_ALL`. The observer now
resolves the root from its file location. An alternate-root/minimal-environment
regression failed before the fix and passes afterward; focused review found no
outstanding issue. CI v16 passes 180 worker-drill/fixture tests and 302 assertions,
plus 12 media-observer tests and 22 assertions. Static analysis passes 390 files,
formatting passes 391, and source policy passes. Deptrac reports 4,367 allowed and
365 external uncovered dependencies, with zero violations, errors or warnings.
The v16 source proof is retained under
`var/test-runs/ci-checkpoint-overlay-1793319476-1880270/source-proof.json` for image
`sha256:314c6da932a397d5f8ed329ab17881248f9b2688f58212d0a7a80b63ea405941`.

Container browser v13 passed with hostile ambient Docker settings and verified
cleanup; its run is `baander-worker-operator-1793318503-1777841`. The earlier full
v13 attempt was invalidated by temporary-helper mode loss and Composer's default
300-second timeout, rather than an application failure. The helper now preserves
modes, and Composer has an explicit 6,900-second budget inside the 7,000-second
wrapper. Full CI v15 passes 1,092 tests and 5,335 assertions in 38:24.368, with
seven known skips: six development-worker cases qualified separately by the live
six-test/78-assertion run, and one unavailable optional clang-tidy 19 check. The
aggregate predates the persisted-frame and later fixture/observer changes; their
focused CI evidence follows.

Capacity and final-source checkpoint, 2026-10-30: CPU v14 passed 162
acknowledgements with a 0.400-second maximum and heartbeat gaps no greater than
0.0218 seconds, with cleanup verified. Final memory and PID v17 passed under
`contained-capacity-final-1793320101-1912574`, both with verified cleanup. Memory
drained in six seconds with 125 heartbeats and a 0.00997-second maximum gap; PID
drained in one second with 34 heartbeats and a 0.00958-second maximum gap.

Autoscaling v15 profile one passed with cleanup. Profile two failed initial
readiness without retaining its exact cause. The confirmed readiness defect was
an any-consumer check where all expected Redis consumers were required. The
three-file repair adds a bounded barrier and safe result diagnostics while
preserving the original failure. Its actual-child regression fails before the fix
and passes afterward: four tests and 16 assertions; focused review has no
outstanding findings. CI v19 passes 18 readiness tests and 190 assertions, and the
v17 pipe follow-up passes 15 tests and 18 assertions. Profiles two and four plus
the comparison were launched under `autoscaling-final-1793320743-1962832`, using
the verified profile-one baseline with recorded hash provenance.

Media v18 passed the observer checks but exceeded the 30-second paused-seek
acknowledgement watchdog at 30.39 seconds; cleanup was verified. The domain waits
for cursor readiness and 21 seconds of lookahead. Bounded private publication
diagnostics now retain at most four generations with capped metadata and no media
bytes; tests and focused review pass. Behavior and thresholds are unchanged. Media
will run alone after autoscaling. Final-source CI v20 passes 13 media-observer
tests and 37 assertions, static analysis across 390 files and formatting across
391. Source policy passes; Deptrac reports 4,368 allowed and 366 external uncovered
dependencies, with zero violations, errors or warnings. The image is
`sha256:e44bb9893b30052599d618d47f0b7fd4b0bf960578ef0e149d146138df8d4358`,
with source proof under
`var/test-runs/ci-checkpoint-overlay-1793321003-1976225/source-proof.json`.
These scoped results supplement the passing v15 aggregate. U11 remains open for
the autoscaling comparison and isolated media qualification.

Worker budget and loader checkpoint, 2026-10-30: autoscaling profile four
reported passing measurements after 564 seconds and verified cleanup at 606
seconds, but its 600-second parent correctly timed out. The profile remains failed
and the comparison did not run. The shared worker-capacity default is now 900
seconds to cover setup and completion overhead; autoscaling's 2,000-second aggregate,
callback deadlines, 470-second evidence wait, performance thresholds and
120-second cleanup budget are unchanged. Configuration checks pass 27 tests and
82 assertions. Profile four will rerun with the new production image and settings,
using preserved profile-one/two baselines with recorded hash proof.

Production builds now install the standalone scripts Composer loader without
development dependencies; the application loader still excludes development
packages and PHPUnit. Worker and HTTP fixtures use the matching loader, and
host-source archives retain it. Host verification passes 52 tests and 275 assertions
with two posix-related skips; CI verification of the actual build was pending at
this checkpoint.

Isolated media v20 failed its total watchdog after valid publication: the worker
acknowledged paused state in 28.965 seconds, while decode and verification took
35.5 seconds overall. Private diagnostics retained 19 KiB. This result establishes
neither corrupt publication nor a definitive application defect. The measurement
cause and correction are recorded below; final live media acceptance remains open.

Media readiness measurement checkpoint, 2026-10-30: investigation confirmed
a tooling clock error. The old seek timer included ffprobe, six signature-tampering
checks, decoding and subtitle certification after media was available; it even
fetched audio after certifying video. `TrackDelivery` now fetches paired bytes first
and records monotonic availability before certification. The same 30-second seek
readiness limit applies to that availability time. Every certification remains
mandatory: slow fragments, corrupt media and accepted tampering still fail.
Real HTTP/FFmpeg regression evidence reproduced 1.414 seconds of verification
contamination and the wrong fetch order. CI v23 passes four tests and 24 assertions.
The earlier application-scope question does not apply to this fixture correction.

Production profile four v21 failed before pressure because `WorkloadCodec` could
not load its fixture workload classes; cleanup was verified. Autoscaling now rejects
immutable-image mode before provisioning because its scenario requires injected
fixture services. The disposable image generates development classmaps without
installing dependencies; root production dependencies still exclude PHPUnit, and
host-source archives carry the matching classmap sources. CI v22 passes 79 tests
and 381 assertions without skips, including the host posix cases. CI v23 passes
18 fixture/configuration tests and 65 assertions. Static analysis passes 390 files,
formatting passes 391, and source policy passes; Deptrac reports 4,387 allowed and
366 external uncovered dependencies, with zero violations, errors or warnings.

The final-source worker image is built. Production loader and sampler smoke checks
passed on the preceding image; subsequent worker changes affect fixture measurement
source. Profile four was launched from production-sourced disposable fixtures under
`autoscaling-production-final-1793323460-2059006`, using verified profile-one/two
baseline hashes. Its final acceptance result follows.

Autoscaling acceptance checkpoint, 2026-10-30: profile four and the final
comparison pass under `var/test-runs/autoscaling-production-final-1793323460-2059006`,
with cleanup verified. Profile four used a production-sourced disposable fixture;
profile-one/two baselines were copied with source-hash receipts. The two measured
speedups are 1.712244 and 1.732753 at maximum two, and 2.464552 and 2.388083 at
maximum four. Profile four's maximum heartbeat gap is 0.011047 seconds and maximum
media acknowledgement is 0.208403 seconds. The parent completed with exit zero
within its 900-second bound; no timed-out result was reclassified.

Current acceptance: full CI, completed focused gates and autoscaling comparison
pass. Isolated media v23 failed with original status one at the combined
paused-seek readiness-or-intent assertion; cleanup exited zero with removal
verified. Evidence is retained under `var/test-runs/media-control-1793324122-2083047`.
Diagnostic v24 confirmed paused state and position were retained: acknowledgement
took 29.299 seconds, while paired bytes arrived after 31.504826 seconds, beyond
the 30-second limit. Cleanup passed. The client made eight serial HTTP requests;
it now fetches two playlists concurrently, then four resources concurrently, with
bounded waits. The 30-second limit and every certification remain unchanged.
Real HTTP regression evidence reproduced 1.403 seconds for two serial 0.7-second
responses; nine tests and 61 assertions now pass, including timeout, transport,
status, stream, slow-fragment, corruption and tampering cases. Focused review and
five simplification lenses found no outstanding issue. CI v25 passes nine tests
and 61 assertions, static analysis across 390 files and formatting across 391.
Source policy passes; Deptrac reports 4,387 allowed and 366 external uncovered
dependencies, with zero violations, errors or warnings. All 48 source-proof files
match its CI image. These focused results supplement the passing full v15 suite.

Live media v25 failed the 30-second worker-paused acknowledgement before byte
retrieval, under `var/test-runs/media-control-1793325242-2126320`. Desired revision
five remained ahead of observed revision four. The record still carried outcome
ready, while generation three had desired state paused and no current output.
Cleanup was verified with exit zero. The
concurrent retrieval fix is verified, but live application pause/seek acceptance
still fails. Application readiness requires the 18-second cursor plus 21 seconds
of lookahead, requiring seven six-second segments to cover second 39. The readiness SIGSTOP
arrived 30.582 seconds after replacement start, beyond the unchanged 30-second
watchdog. The user authorized the application correction recorded in the paused-seek
readiness checkpoint above.

The final worker image is
`sha256:b3d2616a66ae9c3e3e0747376ccba61e92d6a3bcf373ea02a58dee03e7861dd9`;
its inventory completed with exit zero under
`var/tooling-audit/2026-10-29/integration/worker-final-v25-inventory`.
Integrity verification passes for 77,048 files. Redistribution review remains not
qualified with 5,464 gaps, a separate release classification. The paused-seek
checkpoint above records the passing media and preview follow-up that completes
U11 acceptance.

Audit checkpoint, 2026-10-29: reviewers read all 92 PHP source files and four
package metadata/resource files. Independent source verification confirmed all
14 core factual claims with their stated limits. Safe host-only probes reproduced
behavioral defects without contacting Docker. Detailed evidence remains under
`var/tooling-audit/2026-10-29`, including `review.md` and `claim-verification.md`.

The targeted host suite ran 238 tests and 1,073 assertions, with one failure and
one skip. The failure assumes configured PHP startup and output within 100 ms;
measured startup took about 149 ms. The skip requires unavailable clang-tidy 19.
Neither the authoritative CI image suite nor live Docker qualification ran for
this audit. These results establish the audit baseline, not remediation acceptance.

## PHP-first testing and development tooling

The [implementation plan](docs/plans/2026-10-27-1812-refactor-php-first-development-tooling-plan.md)
defines complete Python removal from repository-owned testing and development
workflows, using PHP for workflow logic and Bash for short launchers and shell
operations. Hosts require PHP 8.5+ with Swoole. Shipped backup and operator
implementations and third-party toolchain internals remain outside scope.
All eight workflow ports are implemented. The checkpoints below record verified
behavior and remaining runtime qualification limits.

Requirements checkpoint, 2026-10-27: all four Ready for Planning checks passed
for 13 requirements and six acceptance examples. Independent source verification
confirmed eight grounding claims, and a separate readiness check found no material
issues. All three plan links and 15 source references resolve; prose preservation
and whitespace checks passed. This checkpoint delivered requirements only;
no application tests, static-analysis suites, builds or environment operations ran.

Planning checkpoint, 2026-10-27: added eight implementation units and nine technical
decisions, preserving all 13 requirements and six acceptance examples. The plan
specifies standalone bootstrap, process and state ownership, execution runtimes,
copied-controller replacement, and separate verification of excluded Python
subjects. Confidence checks passed, and all five document-review lenses returned
no findings. Contract preservation, local links, source references, prose and
whitespace checks passed. No application tests, static-analysis suites, builds or
environment operations ran.

Foundation checkpoint (U1), 2026-10-27: added standalone PHP runtime checks,
command parsing, durable state helpers and process ownership. The runner image
now builds pinned PHP 8.5.2, Swoole 6.2.0 and YAML 2.3.0 on Debian trixie.
All 48 tooling tests and 137 assertions pass in both the application CI image
and the new tooling image, which contains no Python, pcntl or posix. The CI-image
tooling PHPStan gate passes. Real child processes verify signals, deadlines,
concurrent streams, descendant cleanup and bounded-memory logs; native directory
synchronization and lock contention pass. PHP 8.3 rejection was checked separately.
PHP and Bash syntax, prose and whitespace checks pass. The full runner's unchanged
Emscripten stages were not rebuilt. Workflow migration (U2–U8) remains open.

Foundation simplification, 2026-10-27: five review lenses retained all proof tests
and safety checks. Named the per-turn pipe-read bound and removed redundant build
comments. All 48 tests and 137 assertions, focused PHPStan, and whitespace checks
pass after the change.

Development ownership checkpoint (U2), 2026-10-27: ported saved-state identity,
host setup, Docker targeting, gateway ownership and controller supervision.
Publication preserves saved JSON shapes and updater intent, including a concurrent
disable. The combined foundation and ownership suites pass in the application CI
image: 78 tests and 229 assertions. The new tooling runtime also passes the 30
ownership tests and 92 assertions without Python; focused CI-image PHPStan passes.
Existing Python characterization passed 17 tests before cutover. All supervision
checks used disposable state and fake services. The public controller and its
Python modules remain until the complete lifecycle switches in U3.

Tooling package and readability checkpoint, 2026-10-27: moved reusable PHP into
`packages/baander-tooling/src` as the local `baander/tooling` Composer package.
The application and standalone scripts projects use Composer path repositories;
command entrypoints remain under `scripts/`. A fresh scripts-only installation
loads the package after relocation without application dependencies. Copied
controllers preserve the package link and execute without the source checkout.
The PHP 8.5 pass expands compressed code and uses pipes for sequential
transformations. Scoped PHP-CS-Fixer commands make the formatting reproducible.
The foundation and ownership suite passes in the Python-free tooling image:
91 tests and 297 assertions, including separate stderr logs and bounded
cancellation grace. Focused development checks pass 160 tests and 661
assertions, with six Docker qualification cases still pending; inventory checks
pass 76 tests and 281 assertions. Tooling PHPStan passes. These checks do not
complete the remaining workflow migration or integrated acceptance.

Workflow implementation checkpoint (U3–U8), 2026-10-27: replaced the development
controller, backend coordination, inventory tools, runtime drills, registry
harnesses, DSP servers and prose audit with PHP entrypoints and short Bash
launchers. The registry has its own Composer project. Shipped backup and enrollment
implementations remain byte-identical. Source snapshots and static-check archives
retain the standalone Composer loaders and prose helper while excluding host
secrets and other agent files; the packaging suite passes 20 tests and 96 assertions.

Backend qualification passes 17 tests and 52 assertions across ordinary, native
and service lanes, plus 20 stack-isolation checks. Its before/after inventory
retains all 16 unrelated containers, four networks and 1,350 volumes. Inventory
checks pass 76 tests and 281 assertions. Registry qualification passes 32 release,
32 sanitizer, 33 thread-sanitizer and 32 fuzz CTests, the real protocol and recovery
contracts, 10,000 parser fuzz runs and the use-after-free negative control. The
encrypted backup/restore gate completes in 46 seconds; the separate cadence gate
completes in 631 seconds. Web workflow contracts pass 27 tests and 80 assertions;
startup and messaging workflow contracts pass eight tests and 43 assertions.

Readability verification covers expanded calls, arrays and control blocks, plus
PHP 8.5 pipes for sequential transformations. The 69 tooling test files retain
identical parsed structure after formatting. The runtime and registry
simplification passes preserve proof tests and resource protections. Dedicated
tooling PHPStan and all three standalone Composer manifest checks pass. CI now
includes an additional harness suite in a runtime without Python, pcntl or posix.
Integrated verification remains open: the host package update interrupted the
Python-free run, and the refreshed-image runtime drills, host lifecycle, final
inventory checks and independent code review still need their final receipts.

Integration repairs, 2026-10-27: restored the declared development dependency for
the existing `composer normalize` gate. Normalization preserves the Symfony 8.0
constraint range; every existing production and development lock entry remains
unchanged. The manifest and normalization checks pass. The complete runtime fixture
suite passes 169 tests and 678 assertions; all 272 scoped PHP files pass formatting.
The Python-free test image now includes FFmpeg for the audio verifier, and the proxy
test build includes the OpenSSL headers required to compile Swoole.

Integration contract repairs, 2026-10-27: added the missing root Composer
description and updated the surviving publication workflow tests to execute the
PHP launcher. The publication suite passes 42 tests and 338 assertions. Sampler
checks now assert the exact immutable database and clock container identifiers;
all seven sampler tests and 36 assertions pass. Source snapshots retain Go license
notices under upstream `vendor` directories while excluding Composer dependencies;
the source suite passes 11 tests and 41 assertions, with formatting and PHPStan clean.
The full Python-free run exposed the same missing notices in Docker build contexts:
638 tests, 2,759 assertions, one failure and seven environment-specific skips.
The Docker exclusion is repaired; refreshed-image verification remains pending.

Tooling acceptance checkpoint, 2026-10-27: the refreshed application CI image and
the image without Python, pcntl or posix both pass 639 tests and 2,770 assertions.
Seven cases are skipped in each: six development Docker cases await their live
run, and the clang-19 case is covered by the separate native static qualification.
That static gate passes all three translation units and both negative controls,
with 371 components and seven upstream notices verified. Refreshed production
and worker inventories both pass integrity verification: each contains 76,906
files, 636 Debian packages, 152 Composer packages, 14 native components and 2,067
evidence records. Redistribution remains unqualified with 5,443 review gaps; the
additional gap concerns ownership of the retained upstream `vendor/xdg-open` file.
The independent review's publication-contract finding is repaired, and its
focused follow-up review reports no further findings. Runtime and host lifecycle
qualification, the complete backend coverage run and final cleanup remain open.

Runtime fixture repairs, 2026-10-27: proxy workspaces and supervisor logs now use
private directories. The legacy worker drill restores application ownership of
its disposable staged checkout and collects supervisor logs before cleanup,
including cancellation and deadline paths. Docker-context, DNS, proxy,
containment, retirement, recovery, runtime and command drills all pass; the runtime
check observes the full 90-second stale-heartbeat window and recovery. Owned
resources are removed. Focused checks pass 38 tests and 163 assertions, including
a real SIGTERM regression that fails when cleanup-mode entry is removed. That
regression also passes without Python, pcntl or posix: one test, eight assertions.
Formatting and PHPStan pass; focused independent review has no open findings.
The longer worker, web and development lifecycle gates remain pending.

Source audit checkpoint, 2026-10-27: only the two excluded shipped Python files
remain, with unchanged bytes. Active workflow callers and dependency declarations
have no unclassified Python dependency. Composer loads the tooling package from
`packages/baander-tooling/src`; the inventory guide now names its PHP and Composer
prerequisites. All 57 checked relative links in changed active documentation resolve.
Tooling PHPStan, formatting across 274 files, syntax checks across 278 changed PHP
files, changed Bash syntax, prose preservation and whitespace checks pass.
Runtime, host lifecycle and full backend acceptance remain open.

Development runtime checkpoint, 2026-10-27: the disposable database drill passes,
and all six live Docker recipe cases pass with 64 assertions and no skips.
Worker-source qualification exposed a missing `postgres` alias on the controller's
network. Restoring the original alias fixes database resolution; the uninstrumented
drill then passes worker creation, outbox and scheduler effects, exact retirement
and refusal to reuse the same boot. Both drills restore their container, network
and volume inventories. The new command-boundary regression fails without the
alias and passes with it: one test, five assertions. Focused formatting, PHPStan
and independent review pass. The combined cleanup and network suite passes 38
tests and 380 assertions. Longer runtime, host lifecycle and backend gates remain open.

Runtime continuation, 2026-10-28: the source operator drill passes in 83 seconds,
the image-backed operator drill in 79 seconds, and baked worker recovery in 123
seconds. The lifecycle drill passes both Redis and PostgreSQL outage scenarios in
311 seconds, including retained ownership, draining, successor recovery and exact
resource cleanup. A host shutdown interrupted the following capacity drill before
it could finish or clean up. Its retained receipt records an interrupted run,
not a code failure or a passing result. Remaining runtime, host lifecycle and
backend acceptance continue after recovery of the disposable resources.

Runtime recovery checkpoint, 2026-10-28: removed only the interrupted drill's six
containers, two networks, six volumes and four fixture tags; unrelated resource
identities were preserved. Memory and PID capacity cases pass in 122 and 109
seconds, including the selected OOM death, control survival, fork-pressure behavior
and exact cleanup. The actual Docker startup checks also pass.

CPU qualification remains nonpassing: the first desired-state acknowledgement
takes 7.11 seconds against the unchanged two-second limit. A second faithful run
of the historical Bash/Python launcher reaches CPU pressure and records a 10.5285
second first acknowledgement. Its verifier fails the ten-second consumer progress
window: the blocking acknowledgement leaves 10.8985 seconds between observations,
although the acknowledged count rises from five to 328. This reproduces the slow
first acknowledgement before the port; it does not prove that processing stopped.
Both historical runs restore their resource inventories. The media-control gate
hits the documented HLS-only observer limitation with current DASH output. Those
assertions and the application behavior remain unchanged.

Reader repair, 2026-10-28: autoscaling verification exposed a PHP memory failure
while loading and rereading its JSONL evidence. The reader now consumes lines
incrementally, and verification reuses decoded samples. The retained run's peak
falls from 181.63 MiB to 56 MiB under a 128 MiB limit, reaching the unchanged media
deadline assertion. A separate 70.23 MiB, 4,608-record regression fails with the old
reader and passes with the fix at 78 MiB, preserving record count and payloads.
The worker-fixture suite passes 89 tests and 120 assertions; the lead's focused
checks pass three tests and nine assertions. Tooling PHPStan and formatting across
276 files pass. Five simplification reviews and the independent code review have
no findings; comparison with the old parser passes all 46 semantic cases.
Autoscaling profiles one, two and four complete their workloads, but retain first
acknowledgement times of 12.1503, 11.2652 and 12.9059 seconds against the unchanged
three-second limit. Profiles two and four use the repaired reader without memory
failures and restore their resource inventories. Later assertions remain unqualified
because verification stops at that deadline.

Web cleanup checkpoint, 2026-10-28: shutdown scenarios pass in 155 seconds, but
expose an anonymous PostgreSQL volume left by the inherited cleanup command.
Both web harnesses now remove anonymous volumes with their owned containers.
The workflow suite passes 19 tests and 41 assertions; the lead's focused check
passes one test and five assertions. A live PostgreSQL cleanup probe and the
65-second web runtime drill restore exact container, network and volume inventories.
Focused PHPStan, formatting and independent review pass. The production worker
drill also passes in 65 seconds.

Messaging checkpoint, 2026-10-28: the messaging harness now provisions the existing
PostgreSQL extension fixture before starting its workload, matching the producer
and outbox harnesses. The regression fails without provisioning; all six workflow
tests and 42 assertions pass with the repair. The live Docker run passes 165 tests
and 2,488 assertions in 122 seconds and restores exact resource inventories.
Focused PHPStan, formatting and independent review pass. Host lifecycle and full
backend acceptance remain open.

Host acceptance interruption, 2026-10-28: fresh and repeated startup preserve
the configured account, library and secrets, but worker-control failures prevent
accepting the lifecycle run. Diagnosis remains open. Stopping the run exposed a
separate cancellation bug: exceptions thrown directly from Swoole signal callbacks
bypass the entrypoint's failure handler. Polling now captures the exception and
throws it on the normal PHP stack. Real SIGTERM and SIGINT regressions fail with
the old code and pass with the repair, including retained evidence, exit status
and timer cleanup. The acceptance-log suite passes six tests and 40 assertions;
focused PHPStan, formatting, five simplification reviews and independent code
review pass. Full lifecycle acceptance remains open.

Backend acceptance, 2026-10-28: full `make ci` passes 10,762 tests and 117,039
assertions across four lanes, without failures, errors or skips. Composer validation
and normalization, application PHPStan across 3,241 files, and Deptrac pass;
Deptrac retains 484 baseline skips. Validated coverage is 60.93% of classes,
77.43% of methods and 83.78% of lines. The representative 17-test, 52-assertion
run matches the retained test identities, seed and coverage mode. Two actual
coverage runs each pass ten tests and 24 assertions and match all executable and
covered lines and denominators. Removing only the child Swoole loader produces
the expected missing-native-constant failure during discovery; no selected native
tests execute in that control. Host configuration, user-staged deletions and exact
container, network and volume inventories remain unchanged.

Tooling recovery, 2026-10-29: the host shutdown interrupts the final CI tooling run
before its summary; its partial output is not a passing result. The stopped owned
container is removed after preserving its logs. A focused run reproduces two stale
web-runtime cleanup expectations. They now verify anonymous-volume removal and
the complete owned container list; all eight tests and 38 assertions pass, with
focused PHPStan, formatting and independent review clean. Final tooling suites
and the actual worktree preview/browser check remain pending.

Snapshot permission repair, 2026-10-29: the normal preview attempt exposes source
directories copied with mode `0700`. Docker assigns them to `www-data`; the worker
validator runs as root without capabilities and cannot traverse Composer's package
paths. An exact container probe confirms permission denial during autoloading,
before the reported storage validation. Newly created snapshot child directories
now use `0755`, even under a restrictive umask; the outer snapshot remains private,
and source files, existing directory modes and caller umask remain unchanged.
The regression fails before the repair; all 12 source-build tests and 52 assertions
pass afterward. Full tooling PHPStan and formatting across 276 files pass; four
simplification lenses and independent code review have no findings. The failed preview and its data remain
preserved: recovery reuses its defective pinned image. Container and browser
verification of the fixed code will use a fresh isolated worktree.

Final tooling checkpoint, 2026-10-29: the CI runtime and the runtime without
Python, pcntl or posix each pass 646 tests and 2,816 assertions. Both report seven
skips: the six opt-in development Docker cases separately pass six tests and 64
assertions, and the clang-19 case is covered by native static qualification.
All three Composer loaders and every overlaid source hash are verified; both runs
restore exact container, network and volume inventories. The snapshot permission
change also passes the representative backend selection: 17 tests and 52
assertions across three lanes, with unchanged test identities, seed and coverage
mode. The earlier complete backend and native qualification results remain valid
for their recorded source revisions.

Actual preview checkpoint, 2026-10-29: a fresh managed worktree starts through
`make preview-up` after documented SELinux path sharing, without application
dependencies installed on the host. The restricted-root operator validator loads
Composer successfully; web and both worker roles become ready. Browser checks
against the explicit preview URL pass trusted HTTPS, login, navigation to music,
Vite asset loading and HMR connection, with no page errors or failed requests.
The original blocked preview and its data remain retained, and the persistent
instance remains untouched. Full isolated lifecycle acceptance is still running.

Development entrypoint repair, 2026-10-29: isolated lifecycle acceptance passes
fresh/repeated startup, serialized remote updates, failed-build retention and
incompatible-history refusal. The first disposable preview then fails before
console startup. Its private source checkout supplies the development entrypoint
as `0700`; Docker copies it as root, and adding execute bits leaves `0711`, which
the application user cannot read. The exact failed image reproduces permission
denial and exit 2 even for `php --version`.

The Dockerfile now installs that entrypoint explicitly as `0755`. Building from
the same unmodified restrictive source snapshot passes; the resulting image
reports `0755` with root ownership and runs its default entrypoint successfully.
Both disposable probes use no mounts or network and leave the original container
inventory intact. Independent review has no findings; whitespace checks pass.
The failed lifecycle's logs, state and data remain retained, and the managed
preview is restored with trusted HTTPS, login and HMR verified again. Remaining
lifecycle scenarios await continuation; the original failure is not a full pass.

Snapshot file access, 2026-10-29: a check before continuing exposes the same
private clone's unreadable PHP files in the worker operator image. With root
capabilities removed, Composer cannot read package files owned by `www-data` with
mode `0600`. Snapshots now copy regular files as `0644` and executable files as
`0755`. Original source modes, the private outer directory, caller umask, digest
inputs and secret exclusions remain unchanged.

The restrictive-mode regression fails before the repair. Source and backend
archive checks pass 22 tests and 114 assertions; full tooling PHPStan and formatting
across 276 files pass. An operator image built from the actual private checkout's
normalized snapshot loads Composer successfully as root with all capabilities
removed, without mounts or network access. The probe preserves the container
inventory. Independent review's stale executable-mode expectation is corrected;
no source findings remain. The lifecycle continuation stays held before its
preview loop until the repaired source is committed to its disposable remote.

Lifecycle acceptance, 2026-10-29: the reviewed continuation passes all six
remaining behavior and media checkpoints, then completes scoped cleanup. Two
simultaneous previews retain isolated scans, accounts, resources and stop/resume
state. Queued ingestion, original and transcoded playback, trusted HTTPS browser
login and playback, and preview HMR pass. Shutdown during preparation cancels
activation; the timer preserves stopped intent through its full observation
window, and explicit startup retains identity and media. Shutdown also waits for
a live migration; the deliberate partial commit blocks readiness, preserves a
verified checkpoint and refuses unsafe recovery.

This evidence combines four earlier passed checkpoints with one held and resumed
continuation; it is not an uninterrupted full run. Media hashes are compared from
the continuation's captured baseline. Cleanup preserves unrelated inventories and
the earlier failed preview's retained network, volumes and evidence. The original
failure remains recorded. CPU acknowledgement timing, autoscaling media deadlines
and the HLS-only observer remain the runtime qualification limits described above;
their assertions and application behavior have not been weakened.

Final preview verification, 2026-10-29: the managed worktree runs the final code
revision through normal startup, with web and both worker roles ready. Its explicit
preview URL passes trusted HTTPS, login, music navigation, Vite assets and HMR
without browser errors. The final before/after comparison shows unchanged host
files, CA and TLS material, and retained blocked-preview state; the user's
persistent instance is untouched.
The final review reconciliation has no unresolved source findings. Code and
verification records are committed locally; nothing has been pushed.

## Fixed product name

The [implementation plan](docs/plans/2026-10-27-1741-fix-fixed-product-name-plan.md)
defines Bånder as the fixed app display name, removes operator naming overrides,
and aligns email, passkey, TOTP and native app labels while preserving technical
identifiers and credential data. The three implementation units are complete.

Planning checkpoint, 2026-10-27: five requirements and three implementation units
specify the affected files, dependencies and verification scenarios. Source paths
and plan structure were checked; the new integration test is explicitly planned.
All six document-review lenses returned no findings. Prose and whitespace checks
passed. No application tests or builds ran.

Delivery checkpoint, 2026-10-27: the CI-image backend selection passed 46 tests
and 234 assertions: 36 unit tests, three production-configuration cases and seven
TOTP controller cases. Missing, empty and conflicting naming variables were first
shown to fail against the old wiring, then passed with fixed branding. The final
three-case configuration run also verified fixed sender names through the mail
listeners while preserving the configured sender address. PHPStan
reported no errors; Deptrac reported zero violations, warnings or errors, with 484
existing baseline skips. Native JSON, YAML, plist and storyboard checks passed and
confirmed unchanged technical identifiers. React Native typechecking reports the
same 278 diagnostics before and after the label edits; no new diagnostics were
introduced. Native builds were not run, and the tvOS target remains an unverified
starter template. Documentation prose, naming-reference and whitespace checks
passed. Simplification and independent code review are complete with no unresolved
findings.

## Local development workflow

The [implementation plan](docs/plans/2026-10-26-1949-feat-local-development-workflow-plan.md)
defines a persistent development instance at `baander.test` that updates
automatically when master advances, with isolated worktree previews for feature
verification. Make remains the entry point. Libraries, accounts, settings and
source media survive ordinary updates; incompatible database changes stop without
an automatic reset.

The controller, command workflow and integrated runtime acceptance are complete
on the supported Linux host. All required delivery gates passed.
Acceptance covers first and repeat startup, automatic updates and concurrent
merges, failed updates, database incompatibility, preview isolation, and stopping
and resuming each environment. The plan's acceptance examples define those cases.

Verification, 2026-10-26: all four Ready for Planning checks passed for 16
requirements and nine acceptance examples. Five document-review lenses returned
no findings. All 24 local Markdown links and seven source-file references resolve;
prose and whitespace checks passed. This checkpoint delivered requirements only;
no application tests ran and no running environment changed.

Planning checkpoint, 2026-10-26: added seven implementation units and eleven
technical decisions while preserving all 16 requirements, four product decisions,
five flows and nine acceptance examples. The plan covers host supervision, worker
admission, data adoption, automatic updates, previews and runtime qualification.
All units name dependencies, files, test scenarios and verification outcomes.
Five implementation-plan review lenses returned no findings; confidence, contract
preservation, local-link, source-reference, prose and whitespace checks passed.
No application tests, builds or deployment operations ran. Host qualification and
the existing database's adoption eligibility remain execution-time checks.

Instance ownership, 2026-10-26: added private atomic state, process-owned lifecycle
locks, independent updater intent, stable worktree identities and explicit command
targeting. Status omits saved credentials; a missing preview never selects the
persistent instance. Nine focused Python tests passed, including cross-process
locking and sanitized child environments; whitespace checks passed. Runtime setup,
updates, worker admission, adoption and integrated qualification remain open.

Runtime foundation, 2026-10-26: added isolated Compose models, shared HTTPS routing,
inspectable host setup, daemon-visible path checks, live preview mounts and private
writable volumes. Release preparation uses an independent master clone or a
snapshot of worktree edits; image targets include build tools and the trusted
worker controller. Twenty Python tests pass. Build output is retained in private
files. Host DNS, TLS, Docker runtime and application qualification remain open;
these configuration checks do not establish that an instance is usable.

Worker admission, 2026-10-26: manifest version 5 distinguishes production volume
mounts from explicit local development read-only source binds. Readiness observes
the admitted processes and lease without submitting work. Focused worker checks
passed (320 tests, 762 assertions), as did targeted PHPStan. The disposable Docker
drill verified source write refusal, immutable pair creation, real readiness,
outbox and scheduler effects, retirement and refusal to reuse the same boot.
Production regression, full backend and integrated workflow gates remain open.

Initialization and database checkpoints, 2026-10-26: added catalog-only history
inspection, preserved configuration and key resume, explicit adoption inventory,
and isolated restore/rehearsal helpers. The CI image passed 50 focused tests with
404 assertions and no skips; 12 Python bootstrap tests and targeted PHPStan passed.
The disposable PostgreSQL drill verified history refusals, dump/restore, PGroonga
search, sequence preservation and corrupt archive refusal. Complete adoption and
update orchestration still require integrated qualification.

Automatic updates and recovery, 2026-10-26 (U5): added the independent systemd
user controller, serialized revision updates, bounded source-download retries,
maintenance routing, worker retirement and explicit recovery. Stopping retains
unresolved mutation evidence. Recovery restores recorded dependencies and retains
confirmed migration hashes; it does not retry uncertain pending migrations.
Redis confirms persistence inside its recorded mount and stops before its archive
is captured. Ordinary updates retain the recorded PostgreSQL and Redis images.
Adoption verifies existing application secrets before saving configuration and preserves separate transcode
mounts. All nine final-review findings and both follow-up findings were applied.

Preview and command workflow, 2026-10-26 (U6): added preview HTTPS/HMR origins,
explicit browser targets, current-source static checks and Make command targeting.
Quoted command arguments reach the selected container without host evaluation.
Private controller state stays outside worktrees and media mounts. The tools,
development runtime and operator image builds passed; the frontend build passed
with 2,919 modules, and focused ESLint passed. Browser integration remains open.

Verification checkpoint, 2026-10-26: Python discovered 187 tests; 181 passed and
six opt-in Docker cases were skipped in that run. The separate generated-worker
Docker run passed all 11 cases, including the real parser, both recipes and status
from private host-owned files. The final PHP environment checks passed 40 tests
with 62 assertions; focused PHPStan passed. A real isolated identity probe verified
secret matching and refusal without changing its source configuration. The actual
checkpoint and rehearsal helpers applied a pending migration on a disposable
restore and passed a second migration run, leaving source history unchanged.
The drill also verified PGroonga, sequence state, six writable archives, private
keys, archive-integrity refusal and Redis AOF recovery with unchanged absolute
expiry times.

The initial full backend gate ran 10,754 tests with 117,102 assertions and remained
nonpassing: five failures reproduced on starting revision `5ca62e5f`; four errors
passed isolated reruns, but their full-suite causes were still undiagnosed at that
checkpoint. PHPStan passed with valid disposable OAuth keys. Deptrac passed with zero violations,
warnings or errors and 484 skipped violations. These results do not claim a green
full backend gate.

Delivery repairs, 2026-10-26 (U7): host setup now passes on the supported desktop
with wildcard loopback DNS, trusted mkcert certificates and SELinux enforcing.
Explicit path sharing preserves the private CA. The trusted worker controller can
use the Docker socket, and fresh IPC volumes belong to the worker user. Unique
build and retention tags keep immutable image IDs available across repeated builds.
The user timer retains absolute tool paths so Docker credential helpers remain
available without an interactive shell.

Verification: Python discovered 200 tests; 194 passed and six opt-in Docker cases
were skipped. The supported-host prerequisite check passed. Actual disposable
startup and repeat startup reached web and worker readiness with unchanged account,
library and secret fingerprints. The systemd timer applied two successive master
commits in order; an intentionally broken build left the previous release serving.
The independent delivery review returned no code findings. The repaired backend
fixtures passed 75 focused tests with 2,421 assertions in the CI image.

Restart and preview repairs, 2026-10-27 (U7): the updater checks exact owned
container state before skipping an unchanged release. Stopped instances restore
retained artifacts through worker retirement and fresh admission. A transient
restoration preflight failure remains retryable. Functional readiness fluctuations
do not replace running worker boots. Fresh previews create empty checkout mount
points before mounting their private writable volumes; symlinks and parent-path
escapes are refused before directory creation.

Verification: all 211 Python tests completed; 205 passed and six opt-in Docker
cases were skipped. Actual timer recovery preserved the revision, account/library
fingerprints and source media while replacing the old worker boot. Two simultaneous
previews passed isolated scans and retained stop/resume. Queued ingestion and exact
original plus Opus responses reached decoder EOF at eight seconds. Both browser
origins passed trusted HTTPS, login, UI navigation and native playback completion;
the preview also passed Vite and HMR connection checks. Both review findings were
fixed and independently closed; final source reviews and simplification returned
no findings.

Runtime acceptance, 2026-10-27 (U7): all nine acceptance examples passed across
recorded continuations. Stopping during preparation cancelled activation; the
disabled timer stayed stopped, and startup resumed the latest master with retained
state. Shutdown waited for a live migration. A partially committed migration blocked
readiness and unsafe recovery while preserving the checkpoint and existing identity.
Source media hashes remained unchanged.

PostgreSQL 18's image declares a parent volume above the retained database mount.
The controller now covers that unused parent with bounded tmpfs while preserving
the existing database path and adopted parent mounts. Actual recreation retained
the same database identity and data without allocating anonymous volumes. Worker
fixture cleanup also removes anonymous volumes attached to its exact owned
containers. The existing cleanup safety checks passed all 23 cases.

The interrupted run's strict cleanup check failed after a network ID changed. Its
original containers, volumes and application network were preserved; the missing
network's name and cause were not archived. A separate fresh activation with the
final controller passed HTTPS and worker readiness, then the unchanged cleanup
method restored exact container, network and volume inventories. Seven older
anonymous volumes remain untouched; six could not be attributed to a deleted
container. Fresh activation and cleanup created no residual volumes. The final
runtime receipt preserves the interrupted check's failure rather than claiming an
uninterrupted pass.

The full CI-image backend rerun passed 10,755 tests with 116,924 assertions, no
failures, errors or skips, using the original random seed. All four lanes passed,
and their disposable resources were removed. Python discovered 212 tests; 206
passed and six opt-in Docker cases were skipped. The retained frontend build passed
manifest, asset and authentication-worker verification. The prior immutable Yarn
install, type checks, build, PHPStan and Deptrac results remain applicable. Final
runtime and helper reviews returned no findings; all earlier findings were closed.
Worker operator, production, retirement and recovery drills all passed. Each
restored exact container, network and volume inventories. The production worker
image matched all 2,186 current source, configuration, command and migration files.
Shell syntax, documentation links, prose preservation and whitespace checks passed.

The integrated harness uses a disposable Git remote, separate instance state and
generated media; CI selects its component-only mode. Human and agent instructions
use the same development guide. Evidence is retained under
`~/.local/state/baander/qualification/delivery-20261026-05/workspace`, including
`final-runtime-evidence.json`, the backend results and each reviewed continuation.

## Playback investigation and evidence limits

**Movie departure:** Back closed the player, but three original producers retained
live ownership and renewed leases at a fixed cursor across 31 database samples
spanning 30.111 seconds. Native snapshots before and after that window showed the same three
stopped encoders. The next movie began admission, but the browser test exited
before its startup outcome, cancellation of the first movie or recovery could be
captured. Both diagnostic attempts remain nonpassing; both cleaned up successfully.

The detailed investigation remains in [Git history](#completed-work-and-evidence),
including the observer-timeout hypothesis and missing browser error evidence.
Receipts are under `var/test-runs/movie-departure-investigation`. Any later design
must resolve departure ownership, in-flight startup, rapid reopening and shared
sessions. Unconditional cancellation from React effect cleanup is not prescribed.

**Frame capture:** the historical native timestamp of 24.64 seconds versus a
24.60-second callback remains unexplained. Failure diagnostics are delivered:
63 Node tests and the prescribed two ordinary browser runs plus one sparse control
passed. The mismatch did not recur; these results do not prove its cause or
universal capture stability. The
[sparse playback guide](docs-book/part-2-developer-guide/testing.md#sparse-movie-playback)
retains the contract and evidence. No tolerance change or further implementation
is selected by this historical failure.

**Qualification boundaries:** bounded adaptation and endpoint proofs do not
establish uninterrupted full-source playback or universal format compatibility.
Keep these limits separate from selected work:

- Safari/native HLS, DASH browser playback and subtitles, full live FFmpeg DASH
  EOF, formal CMAF conformance, interactive audio switching, native caption-menu
  interaction and caption pixels remain unqualified. The
  [testing guide](docs-book/part-2-developer-guide/testing.md) distinguishes tested
  HLS/Chromium/Firefox workflows from these paths. The separate native pressure
  observer still expects the old HLS muxer output and is not qualified for the
  current demuxed output.
- General variable-rate gaps, timestamp discontinuities, additional origins,
  arbitrary containers, hardware and untested browser/format combinations remain
  outside the measured cases. A [Selected video endpoint](docs-book/part-2-developer-guide/testing.md#selected-video-endpoint-playback)
  is qualified only for its calibrated metadata paths; missing or stale metadata
  still uses an estimate. Existing checks do not establish general cache or
  performance qualification.
- Software colour checks qualify BT.2020 SDR handling and PQ/HLG tone mapping;
  they do not establish general HDR browser acceptance, complete reference-vector
  coverage, display-gamut certification or Dolby Vision support. See the
  [testing guide](docs-book/part-2-developer-guide/testing.md) for the measured cases.
- Native codec acceptance remains conditional on the selected release path.
  [AAC reference coverage](packages/codecs/baander-aac/docs/README.md) does not
  exercise the 34-band and phase decoder paths; listening tests and the HE-AAC
  IPC/bridge suites were not run in that checkpoint. The original 5% HE-AAC CBR
  target was not established by the delivered 6% acceptance gate. These limits
  do not select unused codec work or reopen intentional encoder design decisions.
- Other existing limits belong in the [scan guide](docs-book/part-1-operator-guide/commands/app-library-scan.md),
  [Transcode context](docs-book/part-2-developer-guide/contexts/transcode.md) and
  [messaging guide](docs-book/part-2-developer-guide/cqrs-and-messaging.md).
  Delivered directory publication does not complete the broader event-producer
  transaction-boundary audit. None of these boundaries selects a new task here.

## Registry release acceptance

The [Registry guide](relay/README.md) documents implemented contracts, deployment,
encrypted backup and recovery, packaged dependency inventory, and existing
qualification runners. Its [release acceptance](relay/README.md#release-acceptance)
section retains the full whole-host budgets and workload thresholds.

- **Constrained deployment:** qualify the agreed five-host optimized profile,
  including snapshots, compaction, catch-up and backup upload. Each whole host has
  1 CPU and 512 MiB; API-container limits and short local load smoke do not establish
  whole-host acceptance. Supply target hosts or an explicitly agreed equivalent
  measurement environment before selecting this work. Sanitizer workers may be larger.
- **Performance baseline:** select a stable runner and accepted baseline, then add
  comparison enforcing confirmed regressions above 10%. Existing bounded smoke
  measurements do not establish that baseline.
- **Long soak:** reserve a 24-hour workload window on the intended deployment
  profile. After warm-up, compare equivalent quiescent windows; fail sustained RSS
  growth over 1 MiB/hour or 10% of baseline.
- **Destination recovery:** qualify encrypted, versioned five-minute S3-compatible
  backups at the actual destination and rehearse isolated restoration with a
  five-minute RPO and 30-minute RTO. Disposable TLS S3 upload and offline restore
  are covered; actual-destination access is unavailable.
- **Regional deployment:** obtain access and inventory for `de`, `ca`, `sg`, `au`
  and `fi` (unavailable, user confirmed 2026-10-04). Validate the actual five-voter
  topology, rolling upgrades preserving volumes and bootstrap guards, no
  acknowledged-data loss after two-voter failure, and leader recovery within
  30 seconds. Region names alone do not supply deployment access.

The nightly workflow is on the default branch and locally verified. Hosted
scheduler activation and a scheduled run are not verified by the evidence here.
Use the documented
[nightly procedure](relay/README.md#nightly-qualification-and-retained-diagnostics)
when checking that deployment decision.

## Application release acceptance

- **Recovery:** rehearse application backup restoration before migration rollout.
  Follow the [upgrade guide](docs-book/part-1-operator-guide/upgrading.md); this
  prerequisite does not reopen covered fresh-install or migration behavior.
- **Redistribution:** resolve the application's observed notice, provenance and
  embedded-dependency gaps before qualifying a release image. The
  [application review record](third_party/inventory/application-review.md) retains
  the inventory evidence and verification checkpoint; follow the
  [collection procedure](dev-docs/application-packaged-inventory.md) for each new
  artifact. Preserve the boundaries in [THIRD_PARTY.md](THIRD_PARTY.md).
  The separate Registry inventory is outside this remainder.
- **Selected native media path:** when a release explicitly includes an active
  codec/DSP path, resolve its remaining reproducibility and reference-vector
  acceptance before claiming certification. Use the
  [DSP qualification guide](packages/dsp/README.md) to identify the current evidence
  and limits. Browser graph checks alone do not certify native codec behavior;
  an unwired module's incomplete qualification does not create a release task.
- **First production release:** record acceptance evidence before cutting it.
  There are no production deployments. Remove the temporary pre-release policy in
  [AGENTS.md](AGENTS.md) only when the user explicitly announces the first release;
  a tag, build or deployment preparation does not make that announcement.

## Deferred scope

React Native work, including the Android and Android TV targets in `ui/rn`,
remains deferred at the user's request (2026-10-05). The separate `ui/android`
application was removed at the user's request on 2026-10-26. Resume feature, API
and tooling reconciliation for `ui/rn` only when the user resumes that work.
Historical mobile failures do not select a task or a new CI lane.

## Completed work and evidence

The complete pre-cleanup roadmap is preserved at
`f31c3ef3ff10fc9f7fe9b7c472bf1c9e4a3ad2e0`. Read it with
`git show f31c3ef3ff10fc9f7fe9b7c472bf1c9e4a3ad2e0:ROADMAP.md` for historical
suite counts, failed attempts, source revisions and receipt paths. Completed
checkpoints are omitted here; their earlier failed stages do not reopen work
closed by later evidence.

Implemented plans remain recoverable at
`ef388275de15f5d63af5a7bb1a056ccf20183841`. List them with
`git ls-tree --name-only ef388275de15f5d63af5a7bb1a056ccf20183841 docs/plans/`,
then read one with `git show ef388275de15f5d63af5a7bb1a056ccf20183841:<path>`.
The six plans retained by that cleanup contain unfinished or partly superseded commitments;
their retention does not resume deferred work or validate historical assumptions.

The entries below preserve the installation guides' linked checkpoint and a
compact record of delivered status reconciliation.

### Source-installation documentation

Disposable installation verified frontend loading, administrator login, worker
admission, sample music ingestion and catalog display. Original FLAC and transcoded
Opus played to completion, with exact byte-range delivery. The final repair passed
307 focused unit/native tests and 53 affected service/functional tests; PHPStan,
Deptrac and cleanup checks passed. These are historical results, not new checks.

The installation used a named checkout volume for the remote Docker daemon,
a supported controller container for host PHP extensions, an accepted disposable
self-signed certificate and internal networks. It did not qualify the literal
host-PHP procedure, external metadata providers, other media workflows or host
hardware. The [source-installation guide](docs-book/part-1-operator-guide/getting-started.md)
contains the current procedure; the pinned history above retains detailed evidence
and environment adaptations.

### Pause and resume after uncertain delivery — 2026-10-24

Status reconciliation was delivered with 169 tests across four frontend suites
and all eight production browser phases passing. The
[testing guide](docs-book/part-2-developer-guide/testing.md) describes current
verification; the historical contract remains in Git. Evidence remains under
`var/test-runs/player-status-outage-gates` and
`var/test-runs/player-status-outage-production-accepted`; the pinned history
retains failed attempts and acceptance limits. This completed checkpoint does
not select more outage work.

## Roadmap maintenance

Cleanup, 2026-10-26: removed completed checkpoint narratives and superseded
follow-ups while preserving unresolved scope, release budgets and incoming links.
The full history remains at the pinned revision above. The first cleanup verified
26 local links and three incoming anchor references; release gates and deferred
scope were preserved, and prose audits and whitespace checks passed.

Plan cleanup, 2026-10-26: retired 36 delivered plans, including stale completion
labels and this cleanup's own plan. Six unfinished or partly superseded July plans
were retained byte-identical. Repaired all five incoming references to retired plans and
retained the AAC bitrate acceptance gap. Completed history remains recoverable
from the pinned revisions. All 36 local links in the two edited documents and
three incoming roadmap anchors resolve; no surviving file references a deleted
plan. Hash checks, prose audits, independent review and whitespace checks passed.
No application code changed or application suites ran.

Android app removal, 2026-10-26: removed the standalone `ui/android` application
and its 52 tracked files, including its package manifest and lockfile. The known
ignored Yarn install state was removed with the directory. Both frontend CI event
filters dropped `ui/android/**`; the parsed workflow and all other workflow bytes
are unchanged. The older styling plan now treats the removed app and its proposed
shared-theme adoption as historical scope.

Verification preserved the paths, contents and modes of all 1,394 tracked files
in `ui/rn`, `ui/shared`, `ui/web` and `ui/electron`, including the surviving Android
and Android TV targets. The reference audit found no active consumer of the removed
package. All 24 local Markdown links resolve; prose, independent review and
whitespace checks passed.
No application tests or builds ran because surviving executable consumers and CI
jobs were unchanged. React Native work remains deferred.
