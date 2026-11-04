# Baander roadmap

Updated: 2026-11-04. This roadmap selects future work and records unresolved
acceptance. Current operating contracts belong in the linked guides; completed
milestones and their verification evidence remain in Git history.

## Small-host household deployment

The user confirmed the
[small-host household deployment plan](docs/plans/2026-11-02-1819-feat-small-host-household-deployment-plan.md).
It defines a separate profile for 2,000 songs, 50 movies and 250 TV episodes, with 1–2 simultaneous streams alongside normal scanning and metadata updates.
[System Requirements](docs-book/part-1-operator-guide/system-requirements.md) publishes the measured figures and their limits.

Open items for this plan:

- Qualification is incomplete. No deployment finished the mixed music-and-video scenario, the household profile lacks three complete repetitions, and the restart and cleanup steps of a complete chain, profile replacement and recovery checks were not run. The smallest host size, a 4 GB fit and Raspberry Pi support remain unqualified.
- The web control socket twice closed an `app:server:stats` request without answering under load (household repeats 1 and 2, 2026-11-03); the cause is unknown. The harness now retries and records such samples.
- The preset inherits `OTEL_ENABLED=true` with an unreachable `host.docker.internal` endpoint from `.env`, so web workers spend time on failing span exports. The preset should disable export unless the operator configures a collector.
- After a PostgreSQL crash the worker pair stops ("deployment reconciliation is required") and stays stopped until an operator intervenes.
- `CaptureCoverageTest` still fails on master's playback-destination tables and six `listening_sessions` columns; whether to capture or exclude each needs a decision.
- `ce-simplify-code` ran (4c24a9bb5); its affected tooling, backend and web tests passed. Shipping gates not yet run: `ce-code-review`, the full backend suites, worker runtime container suites, affected browser playback specs and a documentation build. `baander-database:latest` has not been rebuilt, so existing instances still run PostgreSQL without the THP launcher.
- The household profile has not been requalified since the configurable media capacity work added the connection pooler and removed its container limits; its published measurements predate both changes.

## Configurable media capacity

The user confirmed the
[configurable media capacity plan](docs/plans/2026-11-03-1905-feat-configurable-media-capacity-plan.md),
delivered on branch `feat/configurable-media-capacity` (local, not pushed).
Full-profile operators set the video and audio job slots: 8 and 12 by default, or 12 video slots with a configured hardware encoder. The slots are Bånder's only bound on media work.
No container carries a CPU, memory or PID limit, and an `oom_score_adj` kill order protects the stateful services.
All database traffic goes through PgBouncer 1.26.1 with separate application and control pools, and the images moved to Debian 13 with FFmpeg 7.1.
[System Requirements](docs-book/part-1-operator-guide/system-requirements.md) publishes the per-job costs and the reference margins.

Checkpoint, 2026-11-04:

- The 8 + 12 software load proof (`var/u10/software/media-load.json`) and the 12 + 12 VAAPI load proof (`var/u10/hardware/media-load.json`) passed every criterion on the Ryzen 7 5800X3D and RX 9070 XT. Every job completed with no containment, restart or OOM kill, lease helpers stayed at or below 0.40 s against a 1.5 s deadline, and PostgreSQL backends peaked at 30 of 32. Software video ran at 0.251–0.256x real time and hardware video at 1.26–1.28x. Alone, `libx264` ran at 1.08x, `libx265` at 1.30x, `h264_vaapi` at 9.4x and `hevc_vaapi` at 13.7x.
- Full backend suite on `baander-ci:capacity` and `baander-database:capacity` (`var/test-runs/20261104-122123-1848a3a1`, 11,568 tests): the native lane passed 913 tests, and the only failures are the 14 that also fail on master (listed below).
- Tooling suite: 1,455 tests, 9 skipped, no failures. Static gates pass: PHPStan, Deptrac (0 violations) and `lint:container` in the CI image, plus the tooling analysis, format and architecture checks.
- `ce-simplify-code` (a007a8f63) and `ce-code-review` ran. The review's one code finding was applied (5bb051540), and this entry closes its roadmap finding. Four learnings were recorded under `docs/solutions/`.

Open items for this plan:

- At the default 8 software video slots, the reference host keeps about two 1080p `libx264` encodes in real time, not eight, so operators who convert video in software must lower the slots. The default was the user's choice and is unchanged.
- Backend failures that also occur on master: `SignedDeliveryFirewallTest` (8), `OpenApiSpecTest` (2), `CoveredIndexPersistenceTest` (1), `DatabaseNamingConventionTest` (1) and `CaptureCoverageTest` (2).
- Not run: the static FFmpeg image's full build, the controls drill at 8 + 12 (the default drills keep the 3 + 1 fixture), and NVENC, QSV and AMF on real hardware. The NVENC device-request shape that containment verification compares was not checked against a Docker NVIDIA runtime.
- Existing PostgreSQL volumes need `REINDEX` and `ALTER DATABASE … REFRESH COLLATION VERSION` on the Debian 13 database image; `baander-database:latest` and the persistent instance were not touched.
- Transcode execution ownership renewal runs on the application pool, where a client can wait up to PgBouncer's default 120 s; the ownership lease is 15 s. The load proofs saturated that pool without a missed renewal, but a burst of long application transactions could delay renewals past the lease.
- The control pool fails a media authority query that waits more than one second. In the 8 + 12 software pass it peaked at 8 of 8 server connections with no wait; larger slot counts are unproven.
- The pooler's `admin_users` is the application role, so web and worker processes could pause or stop the pooler; the development checks need only `stats_users`.
- When a progress report races the write that records a failed encode, that write is refused and the failure stays unrecorded on that path (behaviour that predates this plan).
- The governor's warm-up encodes predict about 40% of the host per 1080p stream, against about 21% measured for full encodes. Nothing is vetoed, because each media child budgets only its own stream.
- Test gaps: the functional suite connects to PostgreSQL directly, so transaction-pooling incompatibilities surface only in the worker drills, and `HostCpus` and the boot-row fingerprint check constraint have no direct tests.
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
and the [testing guide](docs-book/part-2-developer-guide/testing.md) for current
runners and verification boundaries. Historical suite counts apply to their own
trees; they are not acceptance evidence for a new change.

## Web dependency refresh

Checkpoint, 2026-10-31: refreshed the web application, embedded player, public
website, shared packages and test/build tooling. TypeScript 6.0.3 remains in the
web and shared packages because typescript-eslint requires a compiler below 6.1;
the site and embedded player use 7.0.2.

The [maintenance guide](docs-book/part-2-developer-guide/contributing.md#javascript-dependency-maintenance)
documents offline ownership checks and explicit online dependency, advisory and
patch inspection. Nine exact site resolutions reduce the Vercel dependency tree
from 44 advisory records to 22, removing its critical finding. Remaining fixes
require incompatible transitive releases. The web lint tree retains one moderate
minimatch advisory requiring version 11; its compatible Babel security update is
applied. These findings remain visible in online output. Yarn still reports three
upstream dash.js peer requirements in the `@svta/cml-*` packages; the real DASH
playback check passes for the tested path.

Native audio qualification remains partial. Four worklet meter assertions produce
the same numerical failures on the previous Chromium. The interruption suite
passes five of six cases; current WebKit fails the prerequisite that a held MP3
advance at least one second before the cut. The original HTTP fixture passes with
exact Playwright 1.60.0/WebKit, so this is a new browser qualification gap. WebKit
retains its original HTTP fixture, and the assertions remain unchanged. These
observations do not establish an application audio regression or authorize a playback redesign.

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
[dependency guide](docs-book/part-2-developer-guide/php-dependencies.md) and tooling
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
  [AAC reference coverage](packages/baander-aac/docs/README.md) does not
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

The entries below preserve the installation guides' linked checkpoint and the
open items left by the AAC codec work.

### Geist and Noto typography

Delivered on branch `feat/geist-noto-typography` from the
[typography plan](docs/plans/2026-11-04-1910-feat-geist-noto-typography-plan.md):
Geist and Geist Mono with self-hosted Noto fallbacks in the web app, Electron and
the public site; ten type roles with phone sizes below 768px; the 12px floor with
11px only in admin tables, badges and chips; per-element script rules; per-item
content language from the stored album or movie language, then script detection;
album and song API language fields; and a one-year immutable cache header for
`/assets`.

Verification checkpoint at the review-fix commits on that branch:

- Web: typecheck and lint clean (0 errors), 269 Vitest files with 3,283 tests
  passing, the typography census passing, the generated API client current, and
  the production build verified.
- Typography harness: 24 of 24 checks in Chromium, Firefox and WebKit (WebKit in
  the `mcr.microsoft.com/playwright:v1.64.1-noble` image).
- Design tokens: build check current and 76 tests passing. Website: typecheck, 4
  node tests, 75 tooling PHPUnit tests and 47 Chromium browser checks. Electron:
  typecheck, 75 tests, build, and the runtime smoke test in built and development
  modes.
- Backend in the CI image: PHPStan, Deptrac, container lint and the OpenAPI check
  clean; the Catalog and Playlist functional suites passing; the unit suite at
  7,434 tests with 2 failures in `CaptureCoverageTest`, which fail identically on
  `master` and predate this work.
- Code review: full multi-agent review, verdict ready with fixes; both confirmed
  findings fixed.

Open items:

- The real-screen styling specs (`tests/e2e/styling`) and a first-paint
  measurement have not run, because the preview instance cannot start: its saved
  release references a data-store image that is no longer on the host. The main
  stylesheet measures 456 kB raw and 157 kB compressed, of which the 489
  `@font-face` rules take about 412 kB raw.
- Considered and not done: song resources still split the album title and
  language into two maps; radio genre chips render genre names without a content
  language; `input` and `textarea` use a 2.0 line-height inside the fixed 2rem
  field height, which WebKit may render with the caret off-centre.

### Website typography page

Delivered on branch `feat/typography-page` from the
[typography page plan](docs/plans/2026-11-04-2140-feat-typography-page-plan.md):
a `/typography/` page linked from the header and footer, with live specimens of
every supported script, Japanese, Simplified and Traditional Han forms, the script
rules with and without each rule, and the ten type roles read from the design
tokens. The build ships only the 36 Geist and Noto slices (1,493,344 bytes) that the
page's text reaches first along its font stack; no other page declares a Noto face.

Verification checkpoint: 81 tooling PHPUnit tests, PHPStan and php-cs-fixer clean
on the changed PHP, site typecheck, 4 node tests, a fresh `make website-build`, and
56 Chromium browser checks, including glyph order, the script rules, bundled faces
and the absence of Noto requests on other pages. The user previewed the page
before publication. Open: the Korean samples need a native-speaker check, and the
header's `↗` renders from Noto Sans SC on this page because Geist's Latin slice
lacks it.

### Source-installation documentation

Disposable installation verified frontend loading, administrator login, worker
admission, sample music ingestion and catalog display. Original FLAC and transcoded
Opus played to completion, with exact byte-range delivery. These are historical
results, not new checks.

The installation used a named checkout volume for the remote Docker daemon,
a supported controller container for host PHP extensions, an accepted disposable
self-signed certificate and internal networks. It did not qualify the literal
host-PHP procedure, external metadata providers, other media workflows or host
hardware. The [source-installation guide](docs-book/part-1-operator-guide/getting-started.md)
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

## Roadmap maintenance

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
