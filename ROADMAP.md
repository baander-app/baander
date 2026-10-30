# Baander roadmap

Updated: 2026-10-29. This roadmap selects future work and records unresolved
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

### AAC codec gap closure against the fdk-aac upstream

Checkpoint, 2026-10-23, transport and robustness: the first tier of the gap
list against the AOSP fdk-aac upstream, per the
[plan](docs/plans/2026-10-23-002-feat-aac-codec-transport-robustness-plan.md),
is delivered in `packages/codecs/baander-aac`. Decoder: streaming input with
ADTS and LOAS resync, ADTS CRC verification, an AudioSpecificConfig reader
with implicit, backward compatible and hierarchical SBR/PS signalling, LATM
and LOAS demux, raw access units, error concealment on by default with
counters, a look-ahead peak limiter, 960-sample frames, channel configuration
14 and the PCE height extension with the 7.1 modes. Encoder: ADTS with CRC,
LOAS, LATM, raw access units, 960-sample frames, the four 7.1 layouts and the
MPEG-2 ADTS variant. The binding decodes through the streaming API and takes
every new option; the Electron service, IPC validation and bridge types
follow. Code review found eleven defects, all corrected. Verification: CTest
14/14 in the release and sanitizer builds, FFmpeg interop over LATM and LOAS
in both directions (131 to 137 dB), the binding's smoke test (7 tests) and the
Electron service suite (5 tests). Listening tests and the Electron IPC and
bridge suites did not run in that environment. Deviations by design are
recorded in the [codec README](packages/codecs/baander-aac/docs/README.md).

Checkpoint, 2026-10-24, low delay and dynamics: the second tier, per the
[plan](docs/plans/2026-10-23-003-feat-aac-codec-low-delay-and-drc-plan.md),
is delivered. Decoder: the error resilient object types with virtual
codebook 11, RVLC and HCR, AAC-LD at 512 and 480, AAC-ELD with downscaled
playout, LD-SBR as a self-contained module, MPEG-4 dynamic range control
applied on the spectrum or the QMF bands, and the object type, program
reference level and DRC presence reported. Encoder: AAC-LD and AAC-ELD over
every transport, ELD with LD-SBR, DRC metadata from five compression
profiles, and the afterburner. Performance: table-driven Huffman reading,
FFT-form QMF banks, SIMD kernels (SSE2, AVX2, NEON) behind a dispatch table
and a `baac bench` command. Binding, Electron service, IPC validation, bridge
types and CONCEPTS.md follow. Limits: the LD-SBR windows and frame grid, the
RVLC escape codebook and the HCR reordering rules were written without the
standard's tables and round-trip within the codec only; the DRC metadata
path is not available for the low-delay types. Verification: CTest 28/28 in
the release and sanitizer builds, FFmpeg decoding the LD, ELD and DRC streams
at 135 to 137 dB, afterburner gains of 0.6 to 1.0 dB segmental SNR, encode
1.3 to 2 times and decode 1.1 to 1.9 times faster with byte-identical
encoder output, the binding's smoke test (10 tests) and the Electron service
suite (5 tests). A read-only review found fourteen correctness and contract
findings, all applied. Not run: listening tests, interop for ELD with LD-SBR
and for the RVLC and HCR tools.

Checkpoint, 2026-10-29, USAC decoder: the third tier's first plan, per the
[plan](docs/plans/2026-10-24-005-feat-aac-codec-usac-decoder-plan.md), is
delivered. Decoder: USAC (xHE-AAC, object type 42) over LOAS/LATM and raw
access units with the UsacConfig, extension elements, pre-roll and
independent frames; the frequency-domain core with the context arithmetic
coder, noise filling, TNS, M/S and complex prediction; the linear prediction
core with ACELP, TCX, forward aliasing cancellation and the LSF and vector
quantisers; enhanced SBR at 2:1, 8:3 and 4:1 with pre-flattening; MPS 212
at stereoConfigIndex 1, 2 and 3, with residual coding at 2 and 3; MPEG-D DRC and
loudness with the selection process; concealment; the streaming API, `baac`
and the Node binding with the loudness and DRC options. The normative
tables are ISO/IEC 23003-3 and 23003-1 data transcribed from libxaac
(Apache-2.0) and verified entry by entry; libxaac is the bit-level oracle,
FDK the behavioural one through its binaries only, and FFmpeg master is no
oracle for the 2:1 and 8:3 streams (its output is wrong). Verification
against the FDK decoder (loudness normalisation off, delay 0 throughout):
81 to 84 dB on the FD core, 80 dB with 2:1 SBR and residual-coded MPS 212
and on mono 2:1 SBR, 61 dB on LPD speech at 4:1 and 52 dB at 2:1, 24 and
26 dB on LPD music at 8:3 and 4:1 where libxaac itself reaches 20 and 27 dB
(the references disagree by 38 to 50 dB in the SBR bands); parsing
bit-exact with libxaac on every stream; the DRC normalisation gain exact
against FDK. CTest 34/34 (`test_usac_config`, `test_usac_fd`,
`test_usac_lpd`, `test_usac_sbr`, `test_mps212` and `test_unidrc` among
them) and the binding's smoke test (11 tests). Open: the harmonic
transposer, PVC and inter-TES are implemented but unverified, since the FDK
encoder emits none and no stream at hand uses them; stereoConfigIndex 2 is
verified by construction only; per-platform prebuilt binaries for the
Electron client are pending. Not run: listening tests and the ISO
conformance suite. Figures and limits are in the
[reference comparison](packages/codecs/baander-aac/docs/reference-comparison.md).

Checkpoint, 2026-10-30, MPEG Surround decoder: the third tier's second
plan, per the
[plan](docs/plans/2026-10-29-006-feat-aac-codec-mpeg-surround-decoder-plan.md),
is delivered. Decoder: MPEG Surround (ISO/IEC 23003-1) 5.1 in AAC-LC
streams from a mono downmix (trees 5151 and 5152, five OTT boxes) or a
stereo downmix (tree 525, a TTT box with three OTT boxes), the payload as
EXT_SAC_DATA fill elements with the in-band header or signalled by an
object type 30 configuration in a two-layer LATM stream; the baseline
tools (OTT and TTT boxes with the TTT prediction modes 0 and 1, energy
modes 4 and 5 and centre-subtraction modes 2 and 3, decorrelators with
ducking, residual coding, smoothing, STP and GES temporal shaping,
arbitrary downmix gains, fixed gains and the matrix mode); six channels
in the canonical order, the existing stereo downmix when two are
requested; the surround flag (`aac_decoder_has_surround`, `hasSurround`
in the binding) and the added delay reported (1521 samples for AAC-LC
plus surround, 256 for ELD v2); AAC-ELD v2 (an ELD 512-frame core with
the low-delay 2-1-2 payload in the ELD extension) decoding to stereo. The
normative tables are ISO/IEC 23003-1 data transcribed from libxaac
(Apache-2.0) with attribution, as the USAC ones. Oracles: no free encoder
writes MPEG Surround 5.1, so the test tool `mps-enc` (`tools/mps-enc.cpp`,
a CMake target) writes streams from 5.1 or stereo WAV with the codec's
own AAC-LC or ELD encoder; libxaac is the reference decoder (its
parameter mapping equals ours bit for bit; its decodes of the first
second are committed as `tests/data/mps_*_xaac.wav`); the FDK decoder
binary pads the AAC-LC core to six channels without running its surround
decoder, so it is no oracle for 5.1, but it decodes ELD v2. Verification
against libxaac (delay 0, first second, per channel L R C LFE Ls Rs):
62 to 72 dB on the 5151 tree, 64 to 72 dB on 5152, 63 to 73 dB on 525 in
TTT prediction mode 1 and 47 to 66 dB in energy mode 5 on a broadband
programme; ELD v2 at 77 dB against libxaac and 77 dB against the FDK
decoder. CTest 37/37 (`test_mps`, `test_mps_signal` and
`test_mps_stream` added) and the binding's smoke test (12 tests,
`hasSurround` among them). Open: TTT prediction mode 0 and the
centre-subtraction modes 2 and 3 are verified by construction only, since
libxaac's own output breaks there; energy modes whose two level
differences exceed about 48 dB cannot be matched against libxaac's
fixed-point weights; the object type 30 form is checked against the
in-band form only (40 dB or better); SBR input (HE-AAC plus surround) is
wired but exercised by no stream; LD-SBR with the spatial payload,
temporal shaping in the low-delay path and ELD frame lengths other than
512 are not covered; the 7.1 trees, the HRTF and binaural modes and the
3D mode are refused; per-platform prebuilt binaries for the Electron
client remain pending. Not run: listening tests, the ISO conformance
suite and streams from a third-party MPEG Surround encoder, which does
not exist as free software. Figures and limits are in the
[reference comparison](packages/codecs/baander-aac/docs/reference-comparison.md).

Checkpoint, 2026-10-30, xHE-AAC encoder: the third tier's third plan, per
the
[plan](docs/plans/2026-10-30-007-feat-aac-codec-xhe-aac-encoder-plan.md),
is delivered. Encoder: USAC (xHE-AAC, object type 42) in compatible mode
on the AAC-LC analysis and rate loop: the frequency-domain core with the
context arithmetic coder (bit-exact against the decoder's), noise filling,
TNS, per-band M/S, independent frames with the audio pre-roll, the
UsacConfig with the stream id and loudness info extensions, LOAS/LATM and
raw access units for MP4, never ADTS; enhanced SBR at 2:1 and MPS 212 at
stereoConfigIndex 1, opted into by the caller (FFmpeg's USAC decoder rejects every such stream, so never chosen by bitrate); loudness
measurement per ITU-R BS.1770-4 and EBU Tech 3341/3342 (the K-weighting
derived per sample rate, the gated integrated loudness, the range, the
maxima, the 4x oversampled true peak) with the loudnessInfoSet() writer,
in a two-pass form written into the stream and a one-pass form metered
inside the encoder; the API (`aac_encoder_set_usac`,
`aac_encoder_set_loudness_info`, `aac_encoder_set_loudness_measurement`,
`aac_encoder_loudness`, the meter in `aac.h`), `baac enc ... xhe` with
`--loudness` and the USAC options and `baac loudness`, the binding's `xhe`
profile with `usac`, `loudnessInfo`, `measureLoudness` and the exported
`measureLoudness()`, the Electron service, bridge and IPC validation with
`measureLoudness` and `encoderLoudness`; per-platform prebuilds of the
binding through a GitHub Actions workflow, fetched by the package's
install script and tried by the loader before a local build. Oracles:
FFmpeg master (the MP4 form; its LATM demuxer refuses the USAC
configuration), the FDK decoder, libxaac and ours on every stream;
FFmpeg's `ebur128` and the FDK encoder's own loudness bytes for the meter
and the writer; the FDK encoder as the behavioural reference for stream
layout and bitrates. Verification: the two committed FD streams decode in
all four decoders at 22.7 dB (mono 64 kbit/s) and 20.6 dB (stereo
96 kbit/s) against the source, FDK and libxaac agreeing with ours at 81 to
82 dB and FFmpeg at 33 to 37 dB with noise filling (its random signs
differ) and 136 dB without; raw access units at 44.6, 60.3, 92.9 and
125.6 kbit/s for 48, 64, 96 and 128 kbit/s; the loudness meter within
0.05 LU, the range to the figure and the true peak within 0.04 dB of
`ebur128`; a four-second stereo programme at 96 kbit/s carrying the
loudness set decodes in all four decoders at 20.8 dB against the source,
and FFmpeg normalises it by the set (`-target_level -24`) with the same
-9.0 dB gain as our decoder. SBR at 2:1: pending (the lead fills the
figures). MPS 212: pending (the lead fills the figures). CTest N/N
(`test_loudness`, `test_usac_enc` and `test_encoder_loudness` among them)
and the binding's smoke test (13 tests); the Electron service test (7
tests) passes under Node's type stripping with a local build.
[PLACEHOLDER FOR THE LEAD: final CTest count and the SBR/MPS 212 rows.]
Open: the LPD core, the live loudness leveler and DRC gain sets, the 8:3
and 4:1 SBR ratios, residual and phase coding in MPS 212 and surround
input to the USAC encoder are out of scope as planned; the one-pass
figures cannot enter the stream they were measured from, since a
configuration cannot change mid-stream; the meter takes up to six
channels, so 6.1 and 7.1 input has no in-encoder measurement; FFmpeg
master's LATM path refuses USAC, so its gate runs on the MP4 form; the
prebuild workflow runs only once pushed. Not run: listening tests and the
ISO conformance suite. Figures and limits are in the
[reference comparison](packages/codecs/baander-aac/docs/reference-comparison.md).

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
