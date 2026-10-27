# Baander roadmap

Updated: 2026-10-27. This roadmap selects future work and records unresolved
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

## PHP-first testing and development tooling

The [implementation plan](docs/plans/2026-10-27-1812-refactor-php-first-development-tooling-plan.md)
defines complete Python removal from repository-owned testing and development
workflows, using PHP for workflow logic and Bash for short launchers and shell
operations. Hosts require PHP 8.5+ with Swoole. Shipped backup and operator
implementations and third-party toolchain internals remain outside scope.
Implementation planning and document review are complete; implementation remains open.

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
