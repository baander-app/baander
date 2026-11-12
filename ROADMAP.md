# Baander roadmap

Updated: 2026-11-09. This roadmap selects future work and records unresolved
acceptance. Current operating contracts belong in the linked guides; completed
milestones and their verification evidence remain in Git history.

## Forge-neutral CI

Forgejo and GitHub run the same workflows from `.github/workflows`; each workflow
selects its runner, registry, cache store and artifact action from
`github.server_url`. On GitHub, the runner, PostgreSQL and FFmpeg images are public
GHCR packages, and the application and Nginx images are private ones that a probe
checks after each push. The
[CI guide](docs-book/part-2-developer-guide/contributing/continuous-integration.md) describes the
setup and the operator runbook.

Open items:

- Dual-forge acceptance is partial: no `master` commit has yet run every gate green
  on both forges, the gate workflows have not run through a pull request on each,
  and no scheduled nightly has run on each.
- The two-command drill passes locally in Chromium and Firefox, but no hosted run has
  executed its proofs since the owned-copy path landed. The gate's "Selected video
  endpoint through movie completion and replay" step failed locally at its exact-end
  seek from 2026-11-06; it passes on the branch `movie-playback-growing-presentations`
  (see "Movie playback on growing presentations") and on `master` once that merges.
- Forgejo acceptance is deferred (user decision, 2026-11-05). Every job of the oldest
  queued run that ran inside the Forgejo registry's `baander-runner:latest` (built
  2026-10-15) failed within a minute, and Forgejo 15's API serves no job logs or
  cancellation. Resuming needs: cancel the stale runs in the web UI, read one failed
  job's log there, and rebuild the Forgejo runner image from the current tree.
- Unverified on GitHub: whether Forgejo 15 scopes its workflow token by
  `permissions`; whether socket-launched test containers bind-mount workspace paths
  on the hosted runner; and whether the backend gate fits a hosted runner's memory
  and disk.
- The frame observer's lagged tolerance measures the snapshotted frame's duration,
  which equals the callback frame's for the constant-rate fixtures; a variable-rate
  source would misclassify. The lagged rate has no ceiling of its own beyond the 60
  percent coverage thresholds.
- The copy's lead after the first throttle pause is unmeasured. FFmpeg's read-rate
  clock keeps running while the producer is stopped, so a resumed copy may publish
  its accrued allowance in one burst; measure the published end across a pause and
  resume cycle before changing the rate.
- A native pause reaches the playback session only with the next presence
  observation, up to ten seconds later, so a reload inside that window recovers an
  older cursor.
- The held-frame transcode fixtures publish the frames generated after the source's
  last packet about ten seconds late, so playback stalls for about ten seconds in
  every held-frame run; the runs pass.
- `uniq_transcode_jobs_owner_user_id_video_id_quality_tier_name` names three of its
  five columns.
- `ui/web/src/features/session/live-coverage.ts` lists only `listening_sessions` and
  `devices` as the sources of `session.sessions`, although the snapshot also reads
  the four playback tables; whether that capture should stay is undecided.
- A cancelled Forgejo run may skip the step that removes its per-run BuildKit builder
  and leave that builder's container on the shared daemon.
- `PublicationWorkflowTest` is about 1,400 lines, including a workflow-expression
  evaluator and a tree linter that could move into test-support classes.
- The coverage comment steps still interpolate the stored baseline into `node -e` as
  code.
- `PackageStore` error paths without tests: restore into a non-empty target,
  constructor validation, and saving a missing directory.

## System overview pages

Delivered and published on 2026-11-05: the operator guide's
[Deployment Topology](docs-book/part-1-operator-guide/getting-started/deployment-topology.md) and the
developer guide's [Communication Map](docs-book/part-2-developer-guide/architecture/communication-map.md).

Open items:

- The Mermaid helper's own tests (`ui/site/scripts/render-blocks.test.mjs`) render
  only the three original flowchart pages, so the book's first sequence diagram is
  checked by the website build and the browser spec but not by the helper's font,
  marker and no-foreignObject assertions.
- The browser spec's diagram page list is kept by hand; nothing asserts that every
  docs-book page with a Mermaid fence is listed.
- The Auth context guide still names a task transport that no longer exists.
- Two entries under `docs/solutions` name a transport that no longer exists and a
  single combined consumer; they are candidates for `ce-compound-refresh`.

## Interactive manual diagrams

Delivered and published on 2026-11-05: every Mermaid diagram in the published manual
is interactive, through `ui/site/assets/diagram-explore.js`, without a change to its
source.

Open items:

- No built page carries two diagram figures, so the shared document listener, the
  hint ids and cross-figure pin release are untested in a browser; the spec also
  lacks an OS-level theme change without the toggle and a wrapped (`<br/>`) sequence
  message.
- The render helper's `describeDiagram` option exists only so a test can force an
  unmatched edge; it should not grow further test-only hooks.
- The inlined SVG carries its own embedded font subset, so page font slicing never
  sees diagram labels; a diagram with non-Latin labels has not been checked.
- The diagram fade uses the fast duration token with the browser's default easing;
  `ui/DESIGN.md` states ease-out for the app shell and is silent for the static site.

## Small-host household deployment

The user confirmed the
[small-host household deployment plan](docs/plans/2026-11-02-1819-feat-small-host-household-deployment-plan.md).
It defines a separate profile for 2,000 songs, 50 movies and 250 TV episodes, with 1–2 simultaneous streams alongside normal scanning and metadata updates.
[System Requirements](docs-book/part-1-operator-guide/getting-started/system-requirements.md) publishes the measured figures and their limits.

Open items for this plan:

- Qualification is incomplete. No deployment finished the mixed music-and-video scenario, the household profile lacks three complete repetitions, and the restart and cleanup steps of a complete chain, profile replacement and recovery checks were not run. The smallest host size, a 4 GB fit and Raspberry Pi support remain unqualified.
- The web control socket twice closed an `app:server:stats` request without answering under load (household repeats 1 and 2, 2026-11-03); the cause is unknown. The harness now retries and records such samples.
- The preset inherits `OTEL_ENABLED=true` with an unreachable `host.docker.internal` endpoint from `.env`, so web workers spend time on failing span exports. The preset should disable export unless the operator configures a collector.
- After a PostgreSQL crash the worker pair stops ("deployment reconciliation is required") and stays stopped until an operator intervenes.
- `ce-simplify-code` ran (4c24a9bb5); its affected tooling, backend and web tests passed. Shipping gates not yet run: `ce-code-review`, the full backend suites, worker runtime container suites, affected browser playback specs and a documentation build. `baander-database:latest` has not been rebuilt, so existing instances still run PostgreSQL without the THP launcher.
- The household profile has not been requalified since the configurable media capacity work added the connection pooler and removed its container limits; its published measurements predate both changes.

## Configurable media capacity

Delivered on 2026-11-04: full-profile operators set the video and audio job slots, 8
and 12 by default or 12 video slots with a configured hardware encoder; no container
carries a CPU, memory or PID limit; all database traffic goes through PgBouncer with
separate application and control pools.
[System Requirements](docs-book/part-1-operator-guide/getting-started/system-requirements.md)
publishes the per-job costs and the reference margins.

Open items:

- At the default 8 software video slots, the reference host keeps about two 1080p
  `libx264` encodes in real time, not eight, so operators who convert video in
  software must lower the slots. The default was the user's choice and is unchanged.
- Not run: the controls drill at 8 + 12 (the default drills keep the 3 + 1 fixture),
  and NVENC, QSV and AMF on real hardware. The NVENC device-request shape that
  containment verification compares was not checked against a Docker NVIDIA runtime.
- Existing PostgreSQL volumes need `REINDEX` and
  `ALTER DATABASE … REFRESH COLLATION VERSION` on the Debian 13 database image;
  `baander-database:latest` and the persistent instance were not touched.
- Transcode execution ownership renewal runs on the application pool, where a client
  can wait up to PgBouncer's default 120 s; the ownership lease is 15 s. A burst of
  long application transactions could delay renewals past the lease.
- The control pool fails a media authority query that waits more than one second;
  slot counts above 8 + 12 are unproven against it.
- The pooler's `admin_users` is the application role, so web and worker processes
  could pause or stop the pooler; the development checks need only `stats_users`.
- When a progress report races the write that records a failed encode, that write is
  refused and the failure stays unrecorded on that path.
- The governor's warm-up encodes predict about 40% of the host per 1080p stream,
  against about 21% measured for full encodes.
- Test gaps: the functional suite connects to PostgreSQL directly, so
  transaction-pooling incompatibilities surface only in the worker drills, and
  `HostCpus` and the boot-row fingerprint check constraint have no direct tests.
- The development `docker-compose.yml` sets no `oom_score_adj`.

## Playback destinations and notifications

The user confirmed the
[playback destinations and notifications plan](docs/plans/2026-11-01-1840-feat-playback-destinations-and-notifications-plan.md).
It covers player-owned destination selection, sidebar notifications, music and video transfer, and AirPlay, Chromecast and DLNA alongside Bånder devices.
Local and remotely hosted servers are included, with a local casting companion where needed.

Receiver grants, the companion, protocol adapters and physical receiver qualification remain open,
as do Cast registration and hosting.

Available hardware is now confirmed as an iPhone 13 and Apple TV 4K, alongside
Linux, with no Android device. AirPlay hardware qualification remains outstanding; physical Chromecast
and DLNA receiver checks remain open because suitable receivers have not been identified.

Queue authorization currently checks tracks individually; the receiver
authorization work will assess a bounded Catalog batch contract without weakening
per-item access checks.

Safari automatic local recovery remains unavailable; ordinary playback is retained.

The browser owns its local music/video
execution independently of companion presence, pairing or connectivity. The companion
plan explicitly prohibits claiming or acknowledging browser endpoints.

The first reload-recovery run retained the correct duration but did not resume
after Play. One instrumented rerun passed reload, tab-close and renderer-crash
recovery with the duration assertions. The first failure remains an unexplained
intermittent resume issue; the rerun does not establish its cause.

Follow-up: the separate retry path can retain a pending control after a network
failure followed by a rejected retry. Initial rejected requests are cleared; the
retry-after-network-failure case remains outside this recovery check.

## bAAC in the server transcoder

Delivered and merged on 2026-11-07: the project's static FFmpeg 8.1 build carries
bAAC as the `libbaac` encoder and decoder beside fdk-aac and FFmpeg's built-in AAC
codec, and the application image runs it at `/usr/bin`. The
`transcode.aac_implementation` setting selects the implementation (see the
[transcoding guide](docs-book/part-1-operator-guide/media/transcoding.md#aac-implementation)).
The `ffmpeg` role of the tooling image catalog pins the published image by digest.
VAAPI is qualified on the RX 9070 XT.

Open items:

- Confirm that master's CI passes on `7cf6db42b`, the first run with the pinned
  FFmpeg image; the two runs before it failed at "Build CI image" because no FFmpeg
  image was published yet.
- Decide whether `ffmpeg-baander-static` stays public. The image is built with
  fdk-aac under `--enable-nonfree`, which FFmpeg marks as not redistributable, and
  GHCR serves it anonymously. The user allowed the public package (2026-11-07) before
  this was raised. The alternatives are making the package private again (CI pulls it
  with its workflow token) or dropping fdk-aac, which removes the `fdk_aac` position
  of the setting.
- Check that `ffmpeg.yaml` ran and published on Forgejo. Forgejo CI reads
  `ffmpeg-baander-static:latest` from the Forgejo registry and fails at
  "Build CI image" without it.
- NVENC and QSV were not run on hardware; the capability gate only proves their
  components are compiled in.
- Not run: the stream-copy pacing re-check that
  `docs/solutions/performance-issues/pace-a-stream-copy-with-a-read-rate-burst-counted-from-the-seek-boundary.md`
  asks for at the next FFmpeg major. The testing guide's statement that `-shortest`
  buffers up to ten seconds is unverified on FFmpeg 8.
- Found and not fixed: the NVENC HDR path emits `tonemap_cuda`, a filter neither
  Debian's FFmpeg nor this build has;
  `PlaybackTransferPersistenceTest::testReconnectUsesExplicitOldEndpointStopProofWithoutInheritingItsAuthority`
  fails on master code on both FFmpeg builds; `packages/baander-aac`'s
  `test_usac_enc` byte-identical fixture fails on this host's compiler;
  `MediaWorkerRunnerTest::testSerialAudioJobsReuseOneSlotWithIncreasingLaunchGenerations`
  failed once under CPU load and passes alone; GitHub reports 178 Dependabot alerts
  on the default branch (3 critical, 78 high).
- Review advisories left for a decision: a rendition whose source codec ffprobe
  cannot report decodes with FFmpeg's default decoder instead of the selected one;
  the libbaac decoder labels 7- and 8-channel output with an unspecified layout unless
  the container gives one; it neither trims nor drains decoder delay, which matters
  only for MPEG Surround sources; the unused `FFmpegAdapter` per-segment encoders read
  the live setting rather than a session's.

## Learning and plan maintenance

The shared durable-consumer heartbeat can mask an individual consumer's outage;
that limitation is recorded for follow-up, with no runtime change in the 2026-10-31 learnings refresh.

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
and the [testing guide](docs-book/part-2-developer-guide/testing/README.md) for current
runners and verification boundaries. Historical suite counts apply to their own
trees; they are not acceptance evidence for a new change.

## Web dependency refresh

Checkpoint, 2026-10-31: refreshed the web application, embedded player, public
website, shared packages and test/build tooling. TypeScript 6.0.3 remains in the
web and shared packages because typescript-eslint requires a compiler below 6.1;
the site and embedded player use 7.0.2.

The [maintenance guide](docs-book/part-2-developer-guide/contributing/contributing.md#javascript-dependency-maintenance)
documents offline ownership checks and explicit online dependency, advisory and
patch inspection. Nine exact site resolutions reduce the Vercel dependency tree
from 44 advisory records to 22, removing its critical finding. Remaining fixes
require incompatible transitive releases. The web lint tree retains one moderate
minimatch advisory requiring version 11; its compatible Babel security update is
applied. These findings remain visible in online output. Yarn still reports three
upstream dash.js peer requirements in the `@svta/cml-*` packages; the real DASH
playback check passes for the tested path.

## PHP dependency and runtime refresh

Implementation is retained at the user's wrap-up checkpoint on 2026-10-31.
Qualification is partial; the remaining checks below are recorded follow-up work,
not authorization to continue expanding this upgrade.

Swoole bundle reconciliation: unconfigured session defects are recorded as
deferred defects; readonly proxy support remains unselected.

Five root packages remain behind stable because the latest reli-prof 0.12.0 pins
their shared dependencies: Monolog, Webmozart Assert, Amp, Parallel and
MonologBundle. Exact versions, upstream references and solver blockers are
recorded in the
[dependency guide](docs-book/part-2-developer-guide/getting-started/php-dependencies.md) and tooling
policy.

TSDuck native qualification is blocked, as explicitly accepted by the user on
2026-10-31. Its required PHP 8.5/FFI/PHPUnit 13 probe fails during bootstrap because
the bindings declare `tspyphp*` polling bridge functions absent from upstream
TSDuck v3.43-4549 and the Docker build. Repository history contains no bridge
implementation to recover. Native qualification and automatic CI wiring remain
open for separately scoped C++ bridge work. The probe fails rather than skipping;
no native callback or lifetime pass is claimed.

Six operator-dependent tooling cases and the remaining registry modes still
require their separate qualification. The registry release and sanitizer gates
pass on the final qualification derivative; the thread gate was interrupted at
the user's request.

The complete backend result of 10,966 tests and 120,760 assertions applies to its
recorded earlier tree. No final full backend or reduced-tooling rerun is claimed.
The remaining media matrix and registry thread/fuzz/container/backup gates were
not completed at the wrap-up checkpoint. GitHub `master` at `7aa0172fb` was merged
into the upgrade branch; no full application or AAC suite was rerun for this merge.

## Cohesive live updates

All nine units and the five independently confirmed review fixes were delivered
on 2026-10-31; the recorded qualification leaves these limits open.
Memory reclamation was observed; warm byte counts exceeded cold baselines. This
finite workload does not establish indefinite memory stability. Remote metadata
conflict/deletion and preference conflicts use component/coordinator and hook
proofs; these are not browser conflict tests. The 10,000-album Timeline fixture
has no covers, so it does not measure image fanout. The earlier exited
scan-worker pair's cause remains unidentified; fresh worker health and the
subsequent real scan pass are the recorded qualification.

## Cohesive operator manual

Delivered, 2026-10-30: the [operator manual](docs-book/part-1-operator-guide/README.md)
now connects component responsibilities, storage, setup, daily tasks, monitoring
and recovery.

Qualification limits remain explicit: its verification did not deploy a new
instance, scan user media, rotate secrets, replace workers or restore data. The
current worker credentials contract cannot provision private Discogs tokens for
queued enrichment; documentation now identifies that runtime gap. Coordinated
application restore, actual registry destination/RPO/RTO and whole-host capacity
remain unqualified. The revised source-build procedure is source-verified, without
a new bootstrap run.

## Local development workflow

The implementation plan
defines a persistent development instance at `baander.test` that updates
automatically when master advances, with isolated worktree previews for feature
verification. Make remains the entry point. Libraries, accounts, settings and
source media survive ordinary updates; incompatible database changes stop without
an automatic reset.

The controller, command workflow and integrated runtime acceptance are complete
on the supported Linux host; all nine acceptance examples passed. Evidence is
retained under
`~/.local/state/baander/qualification/delivery-20261026-05/workspace`, including
`final-runtime-evidence.json`, the backend results and each reviewed continuation.
The pinned history under [Completed work and evidence](#completed-work-and-evidence)
retains the checkpoint narratives and suite counts.

The final runtime receipt preserves the interrupted run's strict cleanup failure
rather than claiming an uninterrupted pass. Seven older anonymous volumes remain
untouched; six could not be attributed to a deleted container.

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
24.60-second callback remains unexplained. Failure diagnostics are delivered and
the mismatch did not recur in the prescribed runs; these results do not prove its
cause or universal capture stability. The
[sparse playback guide](docs-book/part-2-developer-guide/testing/media-and-playback.md#sparse-movie-playback)
retains the contract and evidence. No tolerance change or further implementation
is selected by this historical failure.

**Qualification boundaries:** bounded adaptation and endpoint proofs do not
establish uninterrupted full-source playback or universal format compatibility.
Keep these limits separate from selected work:

- Safari/native HLS, DASH browser playback and subtitles, full live FFmpeg DASH
  EOF, formal CMAF conformance, interactive audio switching, native caption-menu
  interaction and caption pixels remain unqualified. The
  [testing guide](docs-book/part-2-developer-guide/testing/media-and-playback.md) distinguishes tested
  HLS/Chromium/Firefox workflows from these paths. The separate native pressure
  observer still expects the old HLS muxer output and is not qualified for the
  current demuxed output.
- General variable-rate gaps, timestamp discontinuities, additional origins,
  arbitrary containers, hardware and untested browser/format combinations remain
  outside the measured cases. A [Selected video endpoint](docs-book/part-2-developer-guide/testing/media-and-playback.md#selected-video-endpoint-playback)
  is qualified only for its calibrated metadata paths; missing or stale metadata
  still uses an estimate. Existing checks do not establish general cache or
  performance qualification.
- Software colour checks qualify BT.2020 SDR handling and PQ/HLG tone mapping;
  they do not establish general HDR browser acceptance, complete reference-vector
  coverage, display-gamut certification or Dolby Vision support. See the
  [testing guide](docs-book/part-2-developer-guide/testing/media-and-playback.md) for the measured cases.
- Native codec acceptance remains conditional on the selected release path.
  [AAC reference coverage](packages/baander-aac/docs/README.md) does not
  exercise the 34-band and phase decoder paths; listening tests and the HE-AAC
  IPC/bridge suites were not run in that checkpoint. The original 5% HE-AAC CBR
  target was not established by the delivered 6% acceptance gate. These limits
  do not select unused codec work or reopen intentional encoder design decisions.
- Other existing limits belong in the [scan guide](docs-book/part-1-operator-guide/commands/app-library-scan.md),
  [Transcode context](docs-book/part-2-developer-guide/contexts/transcode.md) and
  [messaging guide](docs-book/part-2-developer-guide/development/cqrs-and-messaging.md).
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
  Follow the [upgrade guide](docs-book/part-1-operator-guide/maintenance/upgrading.md); this
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

The roadmap before the 2026-11-03 cleanup is preserved at commit `5b74a1f44`.
Read it with `git show 5b74a1f44:ROADMAP.md` for the delivered checkpoints that
cleanup removed, including the fixed product name, local development workflow,
outage status reconciliation and AAC codec checkpoints.

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

The roadmap and plans before the 2026-11-07 cleanup are preserved at
`b9a67726f`. Read the delivered checkpoints of forge-neutral CI, the system overview
pages, interactive diagrams, configurable media capacity, bAAC and the website
typography work with `git show b9a67726f:ROADMAP.md`, and the plans retired then with
`git show b9a67726f:docs/plans/<file>`.

The entries below preserve the installation guides' linked checkpoint and the
open items left by delivered work.

### Geist and Noto typography

Geist and Geist Mono with self-hosted Noto fallbacks are delivered in the web app,
Electron and the public site. Open:

- The real-screen styling specs (`tests/e2e/styling`) and a first-paint measurement
  have not run. The main stylesheet measures 456 kB raw and 157 kB compressed, of
  which the `@font-face` rules take about 412 kB raw.
- Considered and not done: song resources still split the album title and language
  into two maps; radio genre chips render genre names without a content language;
  `input` and `textarea` use a 2.0 line-height inside the fixed 2rem field height,
  which WebKit may render with the caret off-centre.

### Website typography page

The `/typography/` page is delivered and published. Open: the Korean samples need a
native-speaker check, and the header's `↗` renders from Noto Sans SC because Geist's
Latin slice lacks it.

### Website layout stability

The public website no longer shifts its layout after first paint; the layout shift
browser spec guards it. Open: one scaling factor per weight range cannot match every
line, so a long page dense with inline code still wraps a few paragraphs differently
in the fallback, and headings that fill their column to the pixel can wrap in the
bold fallback; the medium weight renders in the regular face and runs 1.5 to 2.4
percent narrow. Linux Chromium with full font hinting can still gain a line in the
fallback on the swap. Diagram pages are not in the shift check, and Firefox is not
measured.

### Source-installation documentation

Disposable installation verified frontend loading, administrator login, worker
admission, sample music ingestion and catalog display. Original FLAC and transcoded
Opus played to completion, with exact byte-range delivery. These are historical
results, not new checks.

The installation used a named checkout volume for the remote Docker daemon,
a supported controller container for host PHP extensions, an accepted disposable
self-signed certificate and internal networks. It did not qualify the literal
host-PHP procedure, external metadata providers, other media workflows or host
hardware. The [source-installation guide](docs-book/part-1-operator-guide/getting-started/installation.md)
contains the current procedure; the pinned history above retains detailed evidence,
suite counts and environment adaptations.

### AAC codec gap closure against the fdk-aac upstream

The transport, low-delay, USAC, MPEG Surround, xHE-AAC encoder, player-control
and encoder-tuning tiers are delivered in `packages/baander-aac`
(2026-10-23 to 2026-10-31). These items remain open:

- **Low delay and dynamics:** the LD-SBR windows and frame grid, the RVLC escape
  codebook and the HCR reordering rules were written without the standard's
  tables and round-trip within the codec only; the DRC metadata path is not
  available for the low-delay types. Not run: interop for ELD with LD-SBR and
  for the RVLC and HCR tools.
- **USAC decoder:** the harmonic transposer, PVC and inter-TES are implemented
  but unverified, since the FDK encoder emits none and no stream at hand uses
  them; stereoConfigIndex 2 is verified by construction only.
- **MPEG Surround decoder:** TTT prediction mode 0 and the centre-subtraction
  modes 2 and 3 are verified by construction only, since libxaac's own output
  breaks there; energy modes whose two level differences exceed about 48 dB
  cannot be matched against libxaac's fixed-point weights; the object type 30
  form is checked against the in-band form only (40 dB or better); SBR input
  (HE-AAC plus surround) is wired but exercised by no stream; LD-SBR with the
  spatial payload, temporal shaping in the low-delay path and ELD frame lengths
  other than 512 are not covered; the 7.1 trees, the HRTF and binaural modes
  and the 3D mode are refused. Streams from a third-party MPEG Surround encoder
  were not run; none exists as free software.
- **xHE-AAC encoder:** the LPD core, the live loudness leveler, the 8:3 and 4:1
  SBR ratios, residual and phase coding in MPS 212 and surround input to the
  USAC encoder are out of scope as planned; the one-pass figures cannot enter
  the stream they were measured from, since a configuration cannot change
  mid-stream; the meter takes up to six channels, so 6.1 and 7.1 input has no
  in-encoder measurement; FFmpeg master's LATM path refuses USAC, so its gate
  runs on the MP4 form.
- **Player-grade controls:** the night set's gain codes stop at -31.875 dB, so
  a programme anchored near -47 LUFS or lower cannot hold -1 dBTP; the FDK
  decoder's front end still loses frames where a chunk boundary lands just
  after a configuration (its own fault, fed frame by frame it decodes whole);
  on corrupt speech frames the stabilised synthesis filters keep the output
  finite, but it can still peak near 1e13. Not run: the ten-minute fuzz runs
  per harness under libFuzzer (no clang runtime here).
- **Encoder quality and speed:** encode speed with the afterburner on (36×
  realtime against FDK's 83× and Apple's 79× in the same run), xHE-AAC at 24 to
  32 kbit/s, Apple's true VBR (0.17 to 0.19 PEAQ ahead at 128 to 160 kbit/s;
  true VBR on sparse tonal items such as glockenspiel), the TVBR quality scale
  (quality 50 gives about 230 kbit/s), bandwidth at 192 kbit/s and up (PEAQ and
  ViSQOL disagree), Apple's coupled stereo SBR and additional sinusoids,
  xHE-AAC rate accounting and the louder low band of low-rate xHE-AAC decodes,
  and the decoder's loudness default. The
  [codec comparison](packages/baander-aac/docs/codec-comparison.md) keeps the
  prioritized list and the [Apple probe note](packages/baander-aac/docs/apple-aac-probe.md)
  the remaining gaps.
- **Not run across the tiers:** listening tests, the ISO conformance suite, and
  speech, 44.1 kHz, multichannel and low-delay codec comparisons.

Figures and limits are in the
[reference comparison](packages/baander-aac/docs/reference-comparison.md) and the
[codec README](packages/baander-aac/docs/README.md).

### Development controller image identities

Two image-identity defects are fixed and verified on the branch
`fix/database-image-double-export`; both trace to the pooler change
(`d13b4ba77`, 2026-11-03) combined with the daemon's containerd image store,
which gives every `docker build` export a fresh attestation index digest.
Preparations failed from 2026-11-07: the worktree preview stayed in
`preparation_failed` and the persistent instance's first start failed the same
way. The persistent instance reached readiness at `https://baander.test` on
2026-11-09 (web and workers ready, revision
`4ef7de4a3d0581f4e970e433ec6c3768b5edeaa8`, gateway answering HTTP 200).

- The postgres and pooler roles built the shared database tag twice; the second
  export re-pointed the tag and orphaned the identity recorded first. One
  export now serves both roles (`788bc012d`).
- The shared gateway reused its recorded nginx digest after the daemon lost it,
  because a later preparation pulled the floating `nginx:stable-alpine` tag
  newer. The gateway re-pins to the release image when the recorded identity no
  longer resolves (`bd7c8f926`).

Each fix has a regression test that failed before and passes after. The
affected tooling suites pass: 102 tests/679 assertions (build, activation,
retained state, database, release-input integrity, libraries) and 52
tests/436 assertions (host gateway, activation, topology, retained state,
image pins).

Open:

- The full tooling suite completed green on 2026-11-09 (1525 tests, 13,063
  assertions, 9 environment skips, 50 minutes) with the gateway fix landing
  mid-run; a clean full re-run on the merged master remains pending.
- **Retained identities, delivered with the movie playback merge (`5957de556`):** a retained postgres or redis identity is kept only while the daemon
  holds it; otherwise the role is built or pulled as for a new instance. A stopped
  instance restarts from its saved release only while all its images resolve. Only the
  daemon's own "No such image" answer counts as absent.
- **Correction to the account above:** the 2026-11-07 failures were two defects. The
  persistent instance failed on the double export. The worktree preview
  `preview-fd790a887b6899cb` failed while pinning a postgres identity the daemon no
  longer held: between 2026-11-02 and 2026-11-07 every image without a container was
  removed from the daemon from outside, with the footprint of `docker system prune -a`.
  The tooling has no path that removes release pins.
- **Rules added for a pruned release, accepted by the user on 2026-11-11:** the
  preflight runs from the prepared release when the previous release's images are gone;
  a worker boot whose operator image is gone is recorded retired without the operator
  when none of its containers exists, and refused before anything starts while
  containers of it survive or the database still registers it.
- **Verified live, 2026-11-10:** with the fix, `make preview-up` on
  `preview-fd790a887b6899cb` prepared its release (postgres, pooler, redis and nginx
  recorded as not retained), started postgres healthy on the retained volume and
  stopped at the preflight's next guard, "An applied migration changed; reconcile the
  saved release before continuing." `make preview-down` then stopped it, which it
  could not do before because the worker's operator image was gone.
- **Still blocked:** that preview's database applied five migrations that `de7942d73`
  later rewrote, and the preflight refuses a changed applied migration. An instance
  that was running when its release was pruned also stops at a named refusal, because
  only the lost operator image could retire its worker registration. Both need a data
  reset, which needs the owner's approval.
- **Reset, 2026-11-11, approved by the owner:** the preview's 14 volumes, its private
  network and its saved state (21 GB, 17 GB of it checkpoints) were removed after
  checking that no container used them. `make preview-status` answers "No configured
  instance", so the next `make preview-up` initializes a new instance. The tooling has
  no reset command; this was done with `docker volume rm`, `docker network rm` and by
  removing the instance's directory under the controller's state root.

### Movie playback on growing presentations

Delivered from the
[plan](docs/plans/2026-11-09-1635-fix-movie-playback-growing-presentations-plan.md)
on the branch `movie-playback-growing-presentations`, merged to `master` as
`5957de556` on 2026-11-10 and pushed to both remotes. The website was built from
that revision and published to `baander.app` the same day: 237 manual pages; on
the live site the home page, the Session context page, the media and playback
testing page, the continuous integration page, `sitemap.xml` and
`pagefind/pagefind.js` answered 200 with the expected content and an unknown path
answered 404. The visual checks were not repeated. On the persistent instance a 1080p movie cut forward every few
seconds while its encode ladder ran ahead of playback, wedged after a failed
transfer and left eight sessions holding jobs, slots and 361 MB of output.

- **Clock (U1, U3):** the hls.js live-edge disable is pinned by a test of the
  constructed configuration (`7723c16b9`), and a player-internal seek is
  recorded as a `baander:native-seek` mark and no longer sent to the server,
  where it restarted every encoder (`99ad11a3e`).
- **Session cursor (U2):** no code. The server already reported the session
  cursor; the drift came from the client's own edge seeks. The plan's decision
  is corrected (`76b4b1e6b`).
- **Transfers (U4):** a rejected transfer command settles the transfer as
  failed under its own fence, and the coordinator's refusals raise an error
  and a mark instead of returning silently (`dee8bdea4`).
- **Stopped runner:** a browser that acknowledged an explicit Stop kept its
  place after it had gone, so every other tab was refused with "Previous
  playback is unconfirmed" for good; reproduced on a preview in Chromium. A
  snapshot now releases it once its registration has expired or been retired
  (`056ba961d`). The cause was `7cf695486`, which also left
  `PlaybackTransferPersistenceTest`'s reconnect test failing on `master`; the
  test now asserts the real sequence.
- **Overlay (U5):** the volume slider is bounded at 5 rem, so the seek slider
  is the only full-width slider (`f2cd12d83`).
- **Stale sessions (U6):** a session that no playback report and no stream
  signing has touched for 24 hours expires at the start of job cleanup, unless
  its presentation is finished. Expiry cancels the execution so the worker
  frees its slot, and cleanup removes the job once the worker has let go
  (`adf06fedb`). The plan's condition "no runnable job" could never be met and
  was dropped: all eight stale jobs on the instance still had a worker
  renewing its lease 20 hours later. The cleanup endpoint and
  `app:transcode:job:cleanup --json` now return `{expired, cleaned}`. After
  review, a signing renews a session only when its last write is over an hour
  old, and one failing expiry no longer blocks the rest (`b26455a68`).
- **Gate (U7):** the two-command checkpoint ends with a 24-second window on
  the replay after movie completion: the media clock may gain at most one
  second on the wall clock, no generation may change and no seek may reach
  the server, and the producer must publish at least 1.5 times real time
  (`0a76ee3a2`). The drill's outer bound moves from 600 to the documented 900
  seconds.

Follow-up, 2026-11-10. The first phase's verification left defects open; each was
diagnosed from a run or a PostgreSQL test and fixed on the same branch (units U8–U17
of the plan).

- **Rendition set (U8):** the signed set was rebuilt on every signing from "the
  sibling covers the cursor", so a sibling encoding near real time left and rejoined
  as the cursor moved, and every change replaced the player's attachment: 35
  attachments and 100 seconds of starvation in one cold play on a loaded preview. A
  growing sibling now joins with an 18-second lead and an attached one stays until its
  lead shrinks to one segment; the primary alone decides readiness; the group's
  runtime rows are read together (`602c10412`, `c422a05ff`, `d74134856`).
- **Gone runners (U9):** `playback_commands` records when a command was first shown
  to its endpoint. A control the runner was never shown settles as never effective;
  a shown play, load or seek keeps the unknown path. The release of a confirmed
  paused or stopped browser runner runs from the snapshot, the registration, a
  control and a transfer. A transfer whose target was never shown its start fails at
  expiry, and the overlay closes during an uncertain transfer (`ddac83bbe`,
  `f47131281`). A tab that took over a paused session is released on its `started`
  answer when the start asked for paused (`4d14e32f5`).
- **Finished movie, closed tab:** the final preview run found that letting a movie
  finish, closing the tab and opening the movie elsewhere refused Play. At a natural
  end the player had paused only its transcode session; the playback session saw a
  `paused` observation while it still wanted playing, which is no proof of silence for
  a tab that has gone. The player now pauses through the session at its end, as music
  playback does, and the acknowledged pause lets the release work (`79a7cf3c4`).
- **Ended sessions (U10):** a refresh signing that names only ended sessions answers
  410 with its reason. After an expiry the player continues at its position on a new
  session, paused if it was paused; after a failed or cancelled job it shows the
  error (`602c10412`, `d74134856`).
- **Cursor and replay (U14, U15):** a different movie starts at zero instead of the
  previous movie's cursor. Play at the source end starts from zero on any player. A
  restore into a presentation's last second lands one second early first, because
  hls.js ends the media source only after the element has settled and the tail
  decodes only after the source has ended (`ddac83bbe`, `602c10412`, `7cc63771c`).
- **Gate (U13):** the checkpoint asserts that the registration answer after a reload
  already shows the runner released, and two of its steps no longer depend on
  reconnect timing or on where the paused cursor stands (`c27da2847`).
- **Tooling and cleanup result (U11, U12):** see "Development controller image
  identities" (`cb103bed1`, `4706716e2`); the cleanup counts are a typed result
  (`602c10412`).
- **Correction:** the Firefox failure recorded here earlier as "a second `pause`
  command" was wrong. The second request re-reads the result of the same request. The
  cause was the release running only on the relay's one-second tick, so the gate
  passed about one run in six.

Verification, 2026-11-10, in `baander-ci-prewarm:local` at the final code `79a7cf3c4`,
each gate run alone on a quiet host.

- The Transcode and Session unit suites pass 1,609 tests with 33,344 assertions. Twelve
  PostgreSQL integration files for the session and transcode contexts pass 354 tests
  with 11,480 assertions.
- PHPStan, Deptrac, `lint:container` and the OpenAPI check pass. The web typecheck
  passes, and the session and catalog suites pass 493 tests.
- The tooling files touched pass on host PHP 8.5.12: 26 files with 416 tests after the
  fallbacks, then `DevelopmentWorkersTest` 63 tests, `WorkerLifecycleTest` 16,
  `DevelopmentActivationTest` 23 and `DevelopmentUpdatesTest` 12 after the review
  follow-ups; `phpstan-tooling` and `deptrac-tooling` report nothing.
- The two-command gate with client controls passes all eight phases in Firefox 157 and
  Chromium 156. The registration answer after the reload arrived 37 ms and 39 ms after
  registration with no active runner; the producer ran at 17.0 and 19.1 times real
  time. With the seek forwarding that `99ad11a3e` removed restored in the image, the
  gate fails in `worker-recovered`: "Only a committed seek may reach the server; the
  player sent 1 without one".
- The movie-endpoint step passes: after the exact-end seek the element stands at 24 of
  24 with `ended` true, `readyState` 4 and not seeking; the natural end sends exactly
  one ending pause, and replay sends one seek to zero. It had failed since 2026-11-06.
- The media-control gate passes. Run beside image builds earlier in the day it failed
  with exit 137; it is a CPU pressure drill.
- The adaptive gate passes in Chromium 156.
- The reported movie (1920x1080 H.264/Opus, 223.59 s) played from a cold start to its
  end on a fresh preview at `7cc63771c`, before the two last session fixes, in Chromium
  with host load 2.3 at the start. The
  player attached twice (360p, then 360p with 720p at 65 s, when 720p led by 19.8 s),
  against 35 attachments before; no rendition left and rejoined, against 17 times
  before. It spent 2.0 s not ready after the first frame, against 100 s, and took
  225.7 s of wall time, against 331.6 s. The media clock never gained more than
  0.017 s on the wall clock between samples and never moved back; no native seek was
  recorded and no seek was sent; one generation, three jobs and three sessions served
  the play. The encoders ran at 4.51, 1.30 and 0.65 times real time for 360p, 720p and
  1080p, so 1080p was never offered on this host. A second movie then started at
  zero, and Play after a reload of the finished movie played from zero in 1.6 s; that
  replay was served as a cached delivery without a transcode session. The seek slider
  measured 1,408 px and the volume slider 80 px at a 1,440 px viewport.
- Reviews: `ce-doc-review` of the follow-up plan with four reviewers, findings applied;
  `ce-simplify-code`, three findings applied; `ce-code-review` over the follow-up with
  eleven reviewers returned two confirmed P2 findings on the paused-runner release
  proof, both fixed in `f47131281`, and one P1 claim that validation rejected. The first
  phase's review returned two confirmed P2 findings, fixed in `b26455a68`. The
  cross-model pass is disabled by the checkout configuration.

The two user decisions of 2026-11-11 about a runner that went away (a same-tab
registration as proof, "Play here anyway", no timer; queue edits and volume changes
passing the release proof) are delivered; see
[Releasing a browser runner that went away](#releasing-a-browser-runner-that-went-away).

The two tooling rules named under "Development controller image identities" were
accepted by the user on 2026-11-11.

Open, known limits:

- **Stop, close, play elsewhere:** a closing tab now reports its close and the next
  tab plays at once. A tab that crashed, was killed or lost its network sends
  nothing: the session stays held until that tab's 30-second registration lapses,
  and then "Play here anyway" ends it.
- **Join lead:** a sibling that encodes slower than playback is not offered, so on a
  host where 1080p encodes at about real time it never appears; each join still
  replaces the attachment once.
- **Ended-session reason:** it is inferred from job, execution and session state. A
  cancel that leaves an in-progress job still referenced by another session reads as
  expired, and the player restarts it.
- **End-of-stream landing:** the restore holds 250 ms on the landing and keeps a
  one-second backstop, because the hook cannot see hls.js end the stream.
- **Registration proof in Chromium:** local recovery releases the runner before
  registration, so only the Firefox run binds the proof to the server change. The
  page identity now also releases a reloaded page's runner in the registration
  itself, in every engine.
- **Command disclosure by another incarnation:** a WebSocket transfer or registration
  payload carrying another of the same user's incarnation ids is returned that
  incarnation's commands and marks them shown. It can only block a settlement.
- **Player hook size:** `useBaanderPlayer.ts` passed 1,100 lines; its session restart
  resets about twenty closure variables by hand.
- **Finished presentations:** these keep their sessions and are reclaimed by the
  cache sweep's 24-hour TTL, not by job cleanup.
- **Not run:** the gate on a hosted runner (the 1.5 rate is unproven on a slower
  host), the full tooling suite, and the persistent instance's cleanup run, which
  needs the branch on `master`.
- **Planned next:** Doctrine entities for the four SQL-owned playback tables, on
  their own plan and branch. Delivered since; see
  [Playback session entities](#playback-session-entities).

### Releasing a browser runner that went away

Delivered on the branch `session-runner-fence-release` from the
[plan](docs/plans/2026-11-11-1737-feat-session-runner-fence-release-plan.md). A tab
that was the runner and then reloaded, closed or lost its network left the session
unknown, and every other tab was refused with "Previous playback is unconfirmed"
until it came back. Nothing is released on a timer.

- **Queue edits and volume changes (`d6ead4875`):** the paused-runner proof passes
  over them, still in one SQL statement; a queue edit's answer records cursor and
  presence only. The store keeps five SQL statements; the statement ceilings stay 6,
  9, 10 and 21.
- **Page identity and succession (`4e06eff9a`, `d6ead4875`):** every registration
  carries one identity per loaded document (column
  `playback_destination_incarnations.page_id`, migration `Version20261111180000`).
  Another page of the same tab releases the earlier incarnation in the registration's
  own transaction; the same page succeeds it and keeps playing through a silent socket
  replacement. A duplicated tab gets a new tab identity through its Web Lock, so it is
  never taken for a reload.
- **Close report (`4e06eff9a`, `ac718191a`):** `POST /api/session/close`, sent at
  `pagehide` from a request prepared ahead (a DPoP proof cannot be signed while the
  page goes away) and after a socket close when nothing plays.
- **"Play here anyway" (`8c7252fb2`, `ac718191a`):** `POST /api/session/override`,
  accepted only when the runner, or an uncertain transfer's target, is a browser
  whose registration lapsed; idempotent by request identity. An accepted override
  re-issues the refused play or song selection only when the refusal is at most two
  minutes old.
- **Beside it:** a stopped video player keeps a volume change for its next load; a
  disconnected playing music runner no longer rejects with "Playback execution was
  cancelled."; the runtime test under `ui/web/tests` that had failed since
  `79a7cf3c4` runs again.
- **Simplification and documentation (`83b8dc307`, `d20d17ecc`).**

Code review, 2026-11-11, six reviewers against `c643dcb4f`: no P0 or P1, and nothing
against the property that two devices never play at once unless a user chose the
override. Confirmed: this entry was missing, and the repeated-close test could not
see a cursor rewrite. Fixed after review, each with a test that failed first:

- A returning overridden tab is silenced by its own registration answer; before, it
  could play on for up to one 10-second presence interval.
- A close report that crosses its page's re-registration also releases the
  successor while the session still stands at the reported revision; before, the
  successor stayed runner of a session nothing played.
- A transfer source succeeded after answering its quiesce keeps that proof; before,
  a target's close then left the session unknown and owed a stop to a paused
  player.
- A close report on an incarnation already retired as stopped changes nothing.
- "Play here anyway" is offered only for browser destinations; an override from
  another account is refused (tested); the offline-return browser case can no
  longer pass when the song ends by itself.

Fixed during the gates:

- **A resume that seeks (`7a650b42f`):** in Chromium the reloaded page's local
  recovery gave a cursor 0.2 seconds off, so the resume sought; the old
  generation's subtitle segment then answered 404, hls.js treats that as fatal, and
  in the 13 ms before the new generation was signed the player gave up the resume.
  A failing attachment is now retiring once its seek was accepted into another
  generation. This is older player code that the new gate step exposed.
- **Play right after a page load (`6b5644f83`):** every control was refused until
  the registration was answered, so a press within about half a second of loading
  showed "Playback connection is unavailable." (also on master). A control issued
  while a registration is in flight now waits for the answer, for at most 15
  seconds.
- **Specs:** the two new specs had four test defects (a layout-preference conflict
  between two tabs of one account, a check that counted the helper's own recovery,
  an ambiguous locator, a following tab that offers Pause); the video transfer spec
  now asserts the close report's cursor, which is more recent than the recovery
  checkpoint.

Open, known limits:

- **Stale close-report proof:** the prepared proof is renewed with each presence
  report; a hidden tab whose timers are throttled to about once a minute can close
  with a proof older than the server's 60 seconds, which is refused, and the session
  stays held until "Play here anyway".
- **Crash, killed browser, discarded mobile page:** no close report is sent; the
  override after the 30-second lapse is the way back.
- **Browser proof:** the close report and the override are proved in Chromium only.
  In Firefox, 2 of the 5 cases pass (closing a tab released the session in 74 ms);
  after navigating away, the session was not released within 8 seconds, and whether
  Firefox did not send the keepalive report or the request was lost is not
  established (the next step is to log it on the server). No browser spec drives a
  duplicated tab or a back/forward-cache restore; the unit and PostgreSQL tests
  cover them.
- **Settings conflict between tabs (older, separate defect):** every tab that shows
  the session's track writes the layout preference, so a second tab of the same
  account gets a 409 and a "Settings conflict" dialog when the first plays. The new
  specs answer those writes themselves; `browser-transfer.spec.ts` does not and can
  fail on it.

### Playback session entities

Delivered from the
[plan](docs/plans/2026-11-10-2252-refactor-playback-session-entities-plan.md) on the
branch `playback-session-entities` and merged to `master` on 2026-11-11.
The four playback coordination tables had no entities, the ORM `schema_filter` hid
them from schema comparison, and `PlaybackStore` read and wrote them with 38
hand-written statements.

- **Contract and baseline (U1, `772f5a085`):** `PlaybackStoreContractTest` pins what
  the coordinator's suites do not: how a repeated write resolves, which clock
  stamps a row, and the keys and scalar types of every returned row.
  `PlaybackSnapshotCostTest` counts the statements of a relayed snapshot and of a
  control that issues a command.
- **Entities (U2, `deb220984`):** four `readOnly` entities match the migrated
  catalog, the tables left `schema_filter`, and schema comparison is clean with no
  migration changed. `PlaybackSessionSchemaListener` declares eight composite
  foreign keys.
- **Reads (U3, `0be4eaafd`):** every read is DQL that returns plain rows.
- **Writes (U4, `715c37523`):** an insert goes through the unit of work, flushed and
  detached in its method; an update is one DQL statement with its predicate. Five
  statements stay SQL: the account advisory lock, the insert of a missing session
  row, the two retire proofs and the recovery expiry check against
  `clock_timestamp()`.
- **Cost (U5, `eb9c4c7bf`):** measured against the SQL store with the same program
  on the same host, 2,000 iterations after 200 warm-up, PostgreSQL 18.4, four runs
  before and five after, load average between 2 and 7.
- **Documentation and rule (U6, `e937e01de`):** the Session context page, the Session README,
  the database naming page and the Doctrine reference describe the mapping, and
  `.agents/rules/ddd-repositories.md` states when a table or a statement may stay
  outside the ORM.

Measured:

| Scenario | Statements, before | Statements, now | Median before | Median now | Ratio | Bound |
|---|---|---|---|---|---|---|
| Snapshot, unbound socket, runner playing | 9 | 6 | 2.29 ms | 2.31 ms | 0.96 | 1.15 |
| Snapshot, bound socket, runner resting paused | 12 | 9 | 4.07 ms | 3.91 ms | 0.96 | 1.15 |
| Snapshot that first shows a command | 12 | 10 | not timed | not timed | | |
| Control that issues one command | 19 | 21 | 6.45 ms | 8.25 ms | 1.26 | 1.5 |

The medians are from the pass with `synchronous_commit` off for the session, which
is the pass the bounds are judged on. With commits waiting for the flush every
snapshot stays at about 17 ms before and after, because the flush outweighs the
statements. Serving 50 accounts in turn through one entity manager costs 3.87 ms
per snapshot against 3.91 ms for one account, with the same number of statements
and an empty unit of work after each round. No fallback to SQL was applied.

These figures are from the second measurement, six alternating pairs against the SQL
store after a simplification pass (`7c93212ca`) resolved the snapshot's selected
destination and active incarnation from rows it had already read; the ratio is the
median of the six paired ratios, and the medians shown are from the pair run on a
quiet host. The first measurement, before that change, gave 8, 11, 12 and 23
statements and ratios of 1.13, 1.10 and 1.27.

Checkpoint, 2026-11-11: the Session unit suite passes with 143 tests, unchanged;
ten PostgreSQL integration files pass with 157 tests, the six playback files among
them with no changed assertion; PHPStan, Deptrac, the container lint and the
OpenAPI drift check are clean.

Accepted differences:

- A number in a stored JSON document keeps a zero fraction (`30.0` where the SQL
  store wrote `30`).
- A session without media stores SQL NULL where the SQL store wrote the JSON value
  `null`. Both read back as no media.
- A control that issues a command sends four more statements: one read that carries
  the transaction clock and the replay-key check, a savepoint and its release
  around the insert, and the mark that the control's own answer shows the command.

Open:

- **Unreviewed baseline:** the eleven tables still in `schema_filter` and the
  existing stores that run DBAL statements have not been reviewed against the new
  rule, which neither approves them nor requires converting them.
- **Query cache:** the measurement's entity manager uses an in-memory query cache;
  production configures `cache.system`. The cost of a DQL statement there is
  unmeasured.
- **Control cost:** a control that issues a command costs two more statements than
  the SQL store and 1.26 times its time, inside the bound of 1.5.
- **Associations with a database cascade, audited 2026-11-11 by reading:** 51 to-one
  join columns carry `onDelete` (39 `CASCADE`, 12 `SET NULL`) in 33 entity files; the
  earlier count of 29 files left out four that carry only `SET NULL`. No schema
  listener adds one on an association column, and no mapping uses `cascade` or
  `orphanRemoval`. None is reachable today: the failure needs managed children, the
  parent removed through the ORM, and a later flush with something to write, and for
  every pair at least one is missing. Three places are one change away and have no
  test that would catch it:
  - `PruneMissingImagesHandler` removes images in a loop with a flush each, and is
    safe only while the owning albums and artists are uninitialized references;
  - `AlbumRepository::delete` detaches songs without skipping uninitialized ones, and
    covers neither credits, genre links nor activities (`MovieRepository::delete` has
    the same gaps);
  - `UserRepository::delete` has eleven child classes, and an administrator deleting
    their own account already holds managed token entities, so any write added after
    the delete in that request would fail.
  Not covered: `tests/`, and the long-lived runners were not traced line by line.
- **Not run:** the gate on a hosted runner, and the adaptive and media-control gates,
  whose code this branch does not touch.
- **Store preconditions:** `register()`, `saveTransfer()` and `insertCommand()` look
  a row up and then insert it, which is safe only under the account lock that
  `lockSession()` takes inside the transaction port. The coordinator is their only
  caller. The store states this in docblocks and does not assert it: the contract
  tests call the three outside a transaction, and an open transaction would not prove
  the lock.
- **A job another run removed:** when two cleanup runs overlap and the other one
  deleted a whole job first, this run still counts that job as cleaned.

Gates, 2026-11-11, on an image built from the branch, client controls on: the
two-command gate passes all eight phases in Chromium 156 and in Firefox 157 (two of
three Firefox runs; see the drill fix below), and the movie-endpoint step passes. The
Transcode and Session unit suites pass 1,615 tests, sixteen PostgreSQL integration
files pass 402 tests, PHPStan, Deptrac, `lint:container` and the OpenAPI check are
clean, and the web typecheck and 493 session and catalog tests pass.

Code review, 2026-11-11, eight reviewers over the branch against `e839b7986`: no
defect in the store, one confirmed P2 in the cleanup fix and one unproven risk that a
test then proved, both fixed in `b364cd7f3`. The tests the review asked for are in
`d8a39c6cc` (two connections registering one endpoint, a refused insert, a
token-level check of the five SQL statements) and `02b8fa12a` (a worker delivery test
that raced its own timeout). The cross-model pass is disabled by the checkout
configuration.

Final checkpoint, 2026-11-11, at `975e8ca51` (the image was built at `b364cd7f3`; the
one later source change renames a bind type that maps to the same instant type):
the two-command gate passes all eight phases in Firefox 157 and in Chromium 156 on
the first run of each, client controls on, and the movie-endpoint step passes. The
whole unit directory passes 8,551 tests in 803 files, eighteen PostgreSQL integration
files pass 409 tests, PHPStan, Deptrac, `lint:container` and the OpenAPI check are
clean, and the web typecheck and 493 session and catalog tests pass. The full unit
run was the first on this line of work since the movie playback branch: it found
`InstantBindConventionTest` failing on a bind that `b26455a68` introduced and that
reached `master` with the movie playback merge, fixed in `975e8ca51`. Earlier
checkpoints ran the Transcode and Session unit directories only.

Fixed on the same branch, found on the persistent instance after the movie playback
merge:

- **Cleanup after an expiry (`e7420b119`, `101cc31e4`, `3ef843e27`):** the first job
  cleanup on the instance expired eight stale sessions and then failed with Doctrine's
  "a new entity was found through the relationship `TranscodeSessionEntity#job`"; a
  second run removed seven jobs. The expiry keeps the sessions managed, and each job
  was removed with its own flush, so the second removal walked a session whose job
  was gone. Deleting a job now removes its sessions in the same flush. Each job is
  removed in its own transaction, a failure is logged with the job and the step, and a
  run that could not remove every job exits 1, or answers 503 from the admin endpoint,
  with the expired, removed and failed counts. A test runs the real command over eight
  stale sessions, two with a live lease: eight expire, six jobs go, two stay.
  After code review (`b364cd7f3`) the removal no longer looks the sessions up with a
  query. It detaches the ones the unit of work holds, read from its identity map,
  because a second cleanup run can delete a session row this run still manages; a
  two-connection test failed against the query in both variants with "removed 2
  orphaned jobs and could not remove 1". The incomplete outcome is a
  `ServiceUnavailableException`, so the exception subscriber builds the 503 and the
  controller no longer catches it. The learning is in
  `docs/solutions/runtime-errors/doctrine-refuses-a-flush-after-the-database-cascaded-a-delete-under-managed-children.md`.
- **Two-command audio step (`c4f169142`):** one Firefox run failed in `attach` because
  the drill waited 55 seconds for a single-thread MP3 encode of a 30-minute track that
  takes about 48 seconds here, while the host was under heavy concurrent load. The
  session store answered on its cadence throughout and the database log was clean. The
  wait is 110 seconds, a timeout names bytes received and seconds elapsed, and every
  phase receipt records load average and CPU pressure. A hosted runner slower than
  about 0.87 of this host on one thread would have failed the old wait every time;
  hosted results were not checked for that.


## Roadmap maintenance

Cleanup, 2026-11-07: removed the delivered checkpoints and resolved items of
forge-neutral CI, the system overview pages, interactive diagrams, configurable media
capacity, bAAC in the server transcoder, web dependency refresh and the website
typography, font stack and layout stability work; open items, limits and release
gates stay. Twelve delivered plans were retired and the two solution docs that cited
them now point at the pinned revision. Eight plans remain, each with undelivered
work. The roadmap and plans before this cleanup are preserved at `b9a67726f`. The four
incoming anchors (`#application-release-acceptance`, `#completed-work-and-evidence`,
`#local-development-workflow`, `#source-installation-documentation`) are kept.

Cleanup, 2026-11-03: removed implemented work from every section, reducing the
roadmap from 3,789 to 432 lines. Fully delivered sections were removed; partly
open sections keep only their open items, release gates, deferred scope and limits
still in force. The roadmap before this cleanup is preserved at `5b74a1f44`. All 34
local links resolve, and the three incoming anchors (`#source-installation-documentation`,
`#local-development-workflow`, `#completed-work-and-evidence`) are kept.

Cleanup, 2026-10-26: removed completed checkpoint narratives and superseded
follow-ups while preserving unresolved scope, release budgets and incoming links.
The full history remains at the pinned revision above. Link, incoming-anchor,
prose and whitespace checks passed.

Plan cleanup, 2026-10-26: retired 36 delivered plans, including stale completion
labels and this cleanup's own plan. Six unfinished or partly superseded July plans
were retained byte-identical. Repaired all five incoming references to retired plans and
retained the AAC bitrate acceptance gap. Completed history remains recoverable
from the pinned revisions; no surviving file references a deleted plan. No
application code changed or application suites ran.

Android app removal, 2026-10-26: removed the standalone `ui/android` application
and its 52 tracked files, and dropped `ui/android/**` from both frontend CI event
filters. The older styling plan now treats the removed app and its proposed
shared-theme adoption as historical scope. All 1,394 tracked files in `ui/rn`,
`ui/shared`, `ui/web` and `ui/electron` were preserved, including the surviving
Android and Android TV targets, and no active consumer of the removed package
remained. React Native work remains deferred.
