# Baander roadmap

Updated: 2026-11-15. This roadmap selects future work and records unresolved
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

The profile split is delivered and on `master`: the
[capability and size deployment settings plan](docs/plans/2026-11-07-1804-feat-capability-and-size-deployment-settings-plan.md)
(U1–U8) replaced the `full` and `household` profiles with a `transcode` or `remux`
capability and bounded size settings, and the operator manifest is version 9. By the
user's decision of 2026-11-12 it landed before the 4 GB qualification, so no host size
is qualified: System Requirements and the configuration guide say so, and the 4 GB
`remux` starting point keeps its starting values until U9 measures it.

Open items:

- The 4 GB `remux` qualification run (U9) has not run; the user deferred it on
  2026-11-12 so that U1–U8 could land first. A first attempt on 2026-11-07 was
  stopped during setup. It reuses the QEMU guest in `~/.cache/baander-household-vm/`
  (start it with `-m 4096`, no libvirtd needed). The images `baander-app:local`,
  `baander-worker:local` and `baander-operator:local` were built from fa240b3d0,
  before the merge with `master`; rebuild all three from the merge commit first.
  It needs three comparable repetitions of the one-player scenarios, restart,
  recovery and deployment replacement on 4 GiB with swap off, then the
  insufficient-memory run on 3 GiB; KTD8 governs any size reduction. Afterwards,
  publish the values in `deploy/preset/example-4gb-remux.json` and the measured host
  size in System Requirements. Until then no host size is qualified, and the
  published measurements are those of the previous household profile from
  3 November 2026.
- Blocker for U9: the local `baander-database:latest` image lacks the
  `baander-pooler-credentials` executable the preset runs, so the database image must
  be rebuilt from `docker/postgres/Dockerfile` before the preset can start.
- The 8 and 16 GB starting points are unqualified guidance; their qualification is
  deferred. ARM64 images and Raspberry Pi hardware are outside this plan.
- Qualification harness follow-ups from review: the deployment-replacement scenario
  does not check fixture borrowers; recovery fails if web or worker processes restart
  during the PostgreSQL outage; `Qualification.php` grew to about 1,900 lines and its
  recovery and replacement logic could move into its own class.
- `docker-compose.yml` now loads the rendered 16 GB PostgreSQL file, so the source
  installation's `max_connections` is 35 instead of PostgreSQL's default 100; check
  that host tools connecting directly still fit the four direct connections.
- `make docs` is shadowed by the `docs/` directory and needs a running preview;
  declare it `.PHONY` or document `php scripts/build-website.php`.
- The web control socket twice closed an `app:server:stats` request without answering under load (household repeats 1 and 2, 2026-11-03); the cause is unknown. The harness now retries and records such samples.
- After a PostgreSQL crash the worker pair stops ("deployment reconciliation is required") and stays stopped until an operator intervenes.
- Affected browser playback specs and the worker runtime container suites have not run against the new profile.

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

## Bånder Audio Core in the server transcoder

Delivered and merged on 2026-11-07: the project's static FFmpeg 8.1 build carries
Bånder Audio Core as the `libbac` encoder and decoder (`libbaac` until the rename)
beside fdk-aac and FFmpeg's built-in AAC codec, and the application image runs it
at `/usr/bin`. The
`transcode.aac_implementation` setting selects the implementation (see the
[transcoding guide](docs-book/part-1-operator-guide/media/transcoding.md#aac-implementation)).
The `ffmpeg` role of the tooling image catalog pins the published image by digest.
VAAPI is qualified on the RX 9070 XT.

Open items:

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
  `MediaWorkerRunnerTest::testSerialAudioJobsReuseOneSlotWithIncreasingLaunchGenerations`
  failed once under CPU load and passes alone; GitHub reports 178 Dependabot alerts
  on the default branch (3 critical, 78 high).
- Review advisories left for a decision: a rendition whose source codec ffprobe
  cannot report decodes with FFmpeg's default decoder instead of the selected one;
  the libbac decoder labels 7- and 8-channel output with an unspecified layout unless
  the container gives one; it neither trims nor drains decoder delay, which matters
  only for MPEG Surround sources; the unused `FFmpegAdapter` per-segment encoders read
  the live setting rather than a session's.

## Learning and plan maintenance

The shared durable-consumer heartbeat can mask an individual consumer's outage;
that limitation is recorded for follow-up, with no runtime change in the 2026-10-31 learnings refresh.

## Resume here

### State on 2026-11-16

`master` on GitHub and Forgejo carries, on top of `6912eaaf6`, the work pushed on
2026-11-15: the CI fixes (`d1504daa3` to `77bb3c8e4`), two web fixes (`9ecf84a3d`,
`052e8684d`) and the roadmap cleanup with its plan retirement. Landed on 2026-11-14:
scan artist credits and MusicBrainz matching. The persistent instance ran `6912eaaf6`
before that push and updates itself from `master`. The last recorded website
publication is the local build of `57fc9e1e3` from 2026-11-13, before all of these.
Pushed later on 2026-11-15, on top of `23f899976`: the matching follow-ups
(`a7d1c7466` to `6991a9f9a` and their roadmap record), with the fix for the CI cleanup
limit. Pushed on 2026-11-16, on top of `8d119f5df`: the matching leftovers
(`a822bf362` to `98a89db8a`). Pushed later on 2026-11-16, on top of `44e315ee3`: the
PGroonga cleanup race fix, `7c2deaa24` first and the rest up to this record once
GitHub had published the PGroonga 4.1.0 database image.

- **Master CI.** No `CI` run on `master` has been green since 2026-11-05. Run
  43826408058 on `44e315ee3` passed the whole quality gate on 2026-11-16, step 41 in
  2,276 of its 3,600 seconds, and failed the `build-and-push` job at its check that
  the application image cannot be pulled anonymously. The user decided the same day
  that every image is public, and `9e7d164bd` removes that check. Run 43826369096 on
  `98a89db8a` failed step 39 on a DPoP key with a short coordinate, fixed by
  `41bc3d439`. The runs on the PGroonga push have not been checked yet; the user
  checks in on CI from time to time rather than waiting for it. See
  [Master CI repair](#master-ci-repair). The first green run is the point to write the
  CI-repair learning with `ce-compound`, which the user confirmed on 2026-11-13.
- **Web fixes.** With the same push, a click on the active lyrics effect turns effects
  off, and the songs view's genre, artist and album panes scroll; see
  [Lyrics effect toggle and song browser panes](#lyrics-effect-toggle-and-song-browser-panes).
- **Matching follow-ups.** On `master` since 2026-11-15: the twelve units of the plan,
  the code review's fixes and the fix for the CI cleanup limit; see
  [Matching follow-ups](#matching-follow-ups). The persistent instance takes them
  with its next update. A lyrics fetch queued before that update fails to decode, and
  "Ten Thousand Fists" stays on its review list until an admin applies a candidate
  from the match dialog.
- **Matching leftovers.** On `master` since 2026-11-16: hand changes to credits and the album merge under the catalog
  lock order, the duplicate fold, artist sync by stored MusicBrainz ID and the fill of
  an artist a match or an import identifies; see
  [Matching leftovers](#matching-leftovers).
- **Rename landing, remaining steps.** Done: `master`, the FFmpeg pin, the website,
  the tag `audio-core-v0.2.0` and the persistent instance. Remaining, each checked
  again on 2026-11-14:
  1. The `audio-core-v0.2.0` release does not exist yet. In its run (43478502093) the
     Linux x64, Windows x64 and macOS x64 prebuilds passed three times, and GitHub
     never assigned a macOS arm64 runner ("The job was not acquired by Runner of type
     hosted even after multiple attempts"), so the release job was skipped. Re-run the
     failed job, then check the four prebuilds, `SHA256SUMS` and a clean desktop
     install that fetches its prebuild.
  2. Move `packages/baander-aac/bench/work/` from
     `/home/martin/dev/baander-baac-lossless` into `packages/baander-audio-core/bench/`.
     Copy `work/heldout/runs.json` first, then run
     `./rename_ledger.py work/heldout/runs.json` and `./setup-refs.sh` without
     `--lossless-only`, so `env.sh` loses the old absolute paths.
  3. Forgejo CI is set aside by the user; re-run its `ci.yaml` once Forgejo's FFmpeg
     image carries `libbac`.
- **Capability and size.** No host size is qualified yet (user decision,
  2026-11-12). Next: the 4 GB remux qualification in the VM (its plan's U9), after
  rebuilding the images from `master`. The qualification harness follows the album
  match queue since the matching follow-ups; see the qualification items under
  [Matching follow-ups](#matching-follow-ups).
- **Next plans.** Better compression of material like the failed held-out recording
  (lossless plan KD12) follows the rename. Its fourth held-out set may be read only
  after the benchmark data has moved and its ledger is rewritten.
- **PGroonga cleanup race.** On `master` since 2026-11-16: the item the user chose
  that day as "PGroonga isolation of throwaway test schemas". Its cause was PGroonga
  4.0.5, not the schema drops. The database image and the persistent instance run
  PGroonga 4.1.0. See [PGroonga cleanup race](#pgroonga-cleanup-race).
- **Cleanup.** GitHub and Forgejo carry only `master`. Left locally on 2026-11-16:
  - the merged branches `fix/scan-artist-credits`, `fix/matching-follow-ups` and
    `fix/matching-leftovers`;
  - the verification worktree `/home/martin/dev/baander-ml-verify` (detached at
    `0405c6142`);
  - two worktrees whose heads are in `master`, `/home/martin/dev/baander-verify`
    (`ff68e09f2`) and `~/.codex/worktrees/ba59/baander` (`ba5750533`).

  Removed on 2026-11-16 with the user's approval: the branch
  `fix/pgroonga-cleanup-race`, the PGroonga worktrees `baander-pgrn-diag` and
  `baander-pgrn-verify`, the images `baander-database:pgroonga-4.0.5` and
  `:pgroonga-4.1.0`, and the persistent instance's pre-move dump and saved 4.0.5
  image. The receipts of the six PGroonga full runs are in
  `~/.local/state/baander/receipts/2026-11-16-pgroonga.tar.zst`.

  `/home/martin/dev/baander-baac-lossless` stays, detached at `a97ae0058`, until its
  `bench/work/` has moved. The receipts of the 2026-11-13 cleanup are in
  `~/.local/state/baander/receipts/2026-11-13/`.

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

Open: in a linked worktree the PHP tooling suite fails up to three
`ApplicationInventoryTest` cases (the two `testInterruptedCollection…` cases always,
`testShellRecoversUncertainCreationWhenCollectorIsInterrupted` sometimes) because a
marker does not appear within its window. `RepositoryConfiguration::local` reads the
primary checkout's `dev.local.json`, which slows configuration loading there. All
three pass in a standalone clone and with `CI=1`.

Open: through `scripts/check-backend-container.php` the tooling suite fails four
`DevelopmentBrowserCleanupTest` cases. `StaticCheck::runCheck` runs the command with
`docker exec` under umask 0000, the test creates its `artifacts` directory with
`mkdir()` and no mode, and `PrivateDirectory::ensure` refuses the world-writable
result. The class passes under umask 022, which CI's `docker run` path uses. Set the
umask in `StaticCheck::runCheck` or create the test directory with mode 0700.

Open: automatic updates run the controller tooling installed by the last
`make dev-up`, which can be older than the images they deploy. On 2026-11-13 the
persistent instance's update to `60fc26b63` (the capability and size merge) started
the new application container, then failed while resolving the worker profile: the
installed tooling, from 2026-11-09, passed the settings to `DeploymentProfile` in the
constructor's old argument order, and the image rejected them ("Argument #2
($webWorkers) must be of type int, null given"). No database change had started.
The instance stayed blocked, refusing every later update, until `make dev-recover`
and `make dev-up` ran from a current checkout. A contract change between the tooling
and the image needs either a controller generation that updates with `master` or a
versioned profile request the image can refuse with a reason.

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
  [AAC reference coverage](packages/baander-audio-core/docs/README.md) does not
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

The roadmap before the 2026-11-15 cleanup is preserved at `d4a938b74`. Read the
delivered checkpoints of the capability and size settings, the development controller
image identities, movie playback on growing presentations, the browser runner release,
bAAC exact arithmetic and lossless mode, the master CI repair, the playback session
entities, the Bånder Audio Core rename, scan artist credits, MusicBrainz matching and
the web fixes of 2026-11-14 with `git show d4a938b74:ROADMAP.md`. The eight plans
retired with that cleanup are preserved there too: list them with
`git ls-tree --name-only d4a938b74 docs/plans/`, then read one with
`git show d4a938b74:docs/plans/<file>`.

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
  [codec comparison](packages/baander-audio-core/docs/codec-comparison.md) keeps the
  prioritized list and the [Apple probe note](packages/baander-audio-core/docs/apple-aac-probe.md)
  the remaining gaps.
- **Not run across the tiers:** listening tests, the ISO conformance suite, and
  speech, 44.1 kHz, multichannel and low-delay codec comparisons.

Figures and limits are in the
[reference comparison](packages/baander-audio-core/docs/reference-comparison.md) and the
[codec README](packages/baander-audio-core/docs/README.md).

### Development controller image identities

Two image-identity defects are fixed on the branch `fix/database-image-double-export`
(`788bc012d`, `bd7c8f926`); both trace to the pooler change (`d13b4ba77`, 2026-11-03)
combined with the daemon's containerd image store. The persistent instance reached
readiness at `https://baander.test` on 2026-11-09. The rules for retained identities
were delivered with the movie playback merge (`5957de556`), and the rules for a pruned
release were accepted by the user on 2026-11-11.

Open:

- **Instances that need a data reset:** the preflight refuses a changed applied
  migration; the preview `preview-fd790a887b6899cb` had applied five migrations that
  `de7942d73` later rewrote. An instance that was running when its release was pruned
  also stops at a named refusal, because only the lost operator image could retire
  its worker registration. Both need a data reset, which needs the owner's approval.
- **No reset command:** the tooling has none. The preview's reset on 2026-11-11,
  approved by the owner, was done with `docker volume rm`, `docker network rm` and by
  removing the instance's directory under the controller's state root.

### Movie playback on growing presentations

Delivered from the
retired plan `2026-11-09-1635-fix-movie-playback-growing-presentations-plan.md`
on the branch `movie-playback-growing-presentations`, merged to `master` as
`5957de556` on 2026-11-10 and pushed to both remotes. The defect: on the persistent
instance a 1080p movie cut forward every few seconds while its encode ladder ran ahead
of playback, wedged after a failed transfer and left eight sessions holding jobs,
slots and 361 MB of output. The website was built from that revision and published to
`baander.app` the same day; the visual checks were not repeated.

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

### Releasing a browser runner that went away

Delivered on the branch `session-runner-fence-release` from the
retired plan `2026-11-11-1737-feat-session-runner-fence-release-plan.md`, with its
checkpoint on 2026-11-12 at `956ddaa3c`. A tab that was the runner and then reloaded,
closed or lost its network left the session unknown, and every other tab was refused
with "Previous playback is unconfirmed" until it came back. The delivery adds one
identity per loaded document, a close report (`POST /api/session/close`) and "Play
here anyway" (`POST /api/session/override`), accepted only when the runner, or an
uncertain transfer's target, is a browser whose registration lapsed. Nothing is
released on a timer.

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

### bAAC exact arithmetic

Delivered from the retired plan `2026-11-11-1916-fix-baac-exact-arithmetic-plan.md`
on the branch `fix-usac-encoder-vector`, by the user's decision of 2026-11-11: every
compiler writes the same bytes, and nothing on the transcoder's path gets slower
(`bd175794f`, `4039dff18`, `768802054`, `5210f3e7c`). The Release build had put
`-fassociative-math`, `-freciprocal-math`, `-fno-signed-zeros` and
`-ffinite-math-only` on every file; it now keeps
`-O3 -fno-math-errno -fno-trapping-math`, `-ffp-contract=off` applies in every
configuration, and 32-bit x86 builds with GCC add `-mfpmath=sse`.

Open:

- CI writes the committed streams and checks against them with the same compiler; a
  second compiler in the Linux job is the requirement that catches a regression.
- The published speed tables and per-stream decoder figures in the package's
  documents were measured with the fast-math build; FDK and libxaac are not on this
  host to measure again.
- MSVC and toolchains other than GCC and Clang get none of the options, and other C
  libraries are unverified.

### Master CI repair

No `CI` run on `master` has been green on GitHub since 2026-11-05. The fixes up to
2026-11-13 are on `master`. Those of 2026-11-14, for the defects found at steps 28,
37, 45 and 53 of the quality gate, are commits `d1504daa3` to `77bb3c8e4`, pushed on
2026-11-15; the pinned revision above gives each cause and its evidence.

Open:

- Run 43695574134 on the pushed tip `23f899976`:
  - The first attempt passed steps 1 to 38, among them the repaired steps 28 and 37
    and the Firefox two-command gate (step 30). It failed step 39, "Development
    instance safety and source admission", after the step's 371 tests and both of its
    drills had passed: the operator drill's cleanup removed its work directory under
    a five-second limit, the log says `rm timed out.`, and a cleanup error turns a
    passing drill into exit 1. The same step passed in run 43584904626. The removal
    now has the configured cleanup budget (`5eb1de941`, pushed with the matching
    follow-ups; `WorkerCleanupTest`, 41 tests, passes with it). That the message belongs
    to this removal is read from the code, where it is the drill's only `rm` under a
    time limit; the log names no path.
  - The second attempt failed at "Build CI image" after 12 minutes with
    `docker exited with status 1`. Its build log stayed on the runner, so the cause
    is unknown; the first attempt had built the same revision.
  - The third attempt passed steps 1 to 40 and failed step 41, "PHPUnit with
    coverage": of 12,702 tests, one errored.
    `NewAlbumMetadataSyncTest::testIngestRequestsAMatchOfTheAlbumAScanCreatedOnceTheSettlingDelayPasses`
    got `pgroonga: PGrnLookupWithSize: object isn't found` while an import saved an
    album. The missing index was the one the test's own setup had just built, while
    replaying every migration in one transaction; the three migration tests that ran
    earlier in the lane (`AacImplementationValueMigrationTest`,
    `ArtistCreditedNameMigrationTest`, `JobMonitorInstantPrecisionMigrationTest`) did
    not cause it. See [PGroonga cleanup race](#pgroonga-cleanup-race).
- Run 43724380366 on `8d119f5df` passed steps 1 to 40, step 39 among them, and failed
  step 41 with exit 124: the step's time budget of 3,600 seconds ran out while lane
  `stack-1` was still running. Every lane ran slower than in the third attempt above
  (ordinary 248 s against 153, native 689 s against 496, `stack-2` 2,609 s against
  1,579), and `stack-1`'s output was lost with the lane. Its PostgreSQL log shows the
  same PGroonga error in an artist insert about a minute before the budget ran out,
  in the session of `MusicBrainzMatchApplyTest`, against the index its own setup had
  built.
- Run 43826408058 on `44e315ee3` (2026-11-16) passed the whole quality gate, the
  repaired steps 45 and 53 and steps 42 onward among them. Step 41 ran 13,028 tests in
  2,276 seconds: ordinary 167.6 s, native 489.7 s, `stack-1` 1,559.3 s, `stack-2`
  1,524.4 s, back to the third attempt's pace, so the slowdown on `8d119f5df` was the
  runner's. Its PostgreSQL logs hold no PGroonga error. The run failed in the
  `build-and-push` job at "Application image is not publicly pullable":
  `scripts/ghcr-private-probe.sh` fetched the manifest of
  `ghcr.io/baander-app/baander:latest` with an empty Docker configuration. A manifest
  fetch with an empty configuration from the development host succeeded for that
  image and for `ghcr.io/baander-app/baander-postgres:latest` the same day. The user
  decided on 2026-11-16 that every image is public, so the check had become wrong;
  `9e7d164bd` removes it from the application and Nginx workflows.
- Run 43826369096 on `98a89db8a` failed step 39 in
  `DevelopmentAcceptanceTest::testDpopProofHasVerifiableEs256SignatureAndBoundClaims`
  (`openssl_verify(): Supplied key param cannot be coerced into a public key`). The
  development acceptance client's DPoP key took OpenSSL's coordinates without their
  leading zero bytes, which about one key in 128 has; `41bc3d439` fixes it.
- Each run draws a new test-order seed, so another order-dependent test can still
  surface in step 41.
- The registry release job failed once on a load-smoke lookup that answered 503; it
  had passed on the two runs before.
- The first green run is the point to write the CI-repair learning with `ce-compound`,
  which the user confirmed on 2026-11-13.

### Playback session entities

Delivered from the
retired plan `2026-11-10-2252-refactor-playback-session-entities-plan.md` on the
branch `playback-session-entities` and merged to `master` on 2026-11-11. Four
`readOnly` entities map the four playback coordination tables, which left the ORM
`schema_filter`; `PlaybackStore` reads with DQL, and five statements stay SQL.
`.agents/rules/ddd-repositories.md` states when a table or a statement may stay
outside the ORM. The same branch fixed job cleanup after an expiry (`e7420b119`,
`101cc31e4`, `3ef843e27`, `b364cd7f3`).

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
- **Not run:** the media-control gate, whose code this branch does not touch.
- **Store preconditions:** `register()`, `saveTransfer()` and `insertCommand()` look
  a row up and then insert it, which is safe only under the account lock that
  `lockSession()` takes inside the transaction port. The coordinator is their only
  caller. The store states this in docblocks and does not assert it: the contract
  tests call the three outside a transaction, and an open transaction would not prove
  the lock.
- **A job another run removed:** when two cleanup runs overlap and the other one
  deleted a whole job first, this run still counts that job as cleaned.

### bAAC lossless mode

Delivered from the retired plan `2026-11-10-2313-feat-baac-lossless-flac-plan.md`
on the branch `baac-lossless`, by the user's request of 2026-11-10. bAAC writes and
reads standard FLAC with its own encoder and decoder, written from RFC 9639; the
package version is 0.2.0.

Known limits:

- **Failed gate:** in the recorded run (run 6, 2026-11-12, AMD Ryzen 7 5800X3D, GCC
  16.2.1, `flac` 1.4.3, FFmpeg 8.1.3) 37 of 38 gates passed and every stream is
  bit-exact. The failed gate is the held-out size gate on the 96 kHz 24-bit piano
  recording: 9,821,712 bytes against 9,795,735 for `flac -5`, 0.27 % larger. By the
  user's decision of 2026-11-12 the mode lands with the failure disclosed in the
  results, the comparison and the website (KD12).
- **Frame search behind damage:** the decoder's search has a work budget, which on
  input crafted to overdraw it may pass over a frame right behind the damage (KD10).

Open:

- Better compression of material like the failed held-out recording is its own plan
  (KD12). It counts as tuning in answer to the third set, so it ends with a fourth
  fresh held-out set and a new recorded run.
- The server's lossless renditions and a FLAC codec in the FFmpeg wrapper are their
  own plans (KD3). The wrapper plan decides how the C structs are versioned.
- No speed was measured on ARM or with MSVC, whose build writes Rice codes with the
  shared loop and not the AVX2 kernel.
- FLAC in fragmented MP4, the Electron bridge and a browser playback matrix belong to
  the feature that delivers FLAC to clients.
- The harness selftest runs in no CI job; it needs numpy on the runner.
- The library was renamed to Bånder Audio Core (`bac`) by the user's decision of
  2026-11-12 (KD13); "Bånder Audio Core rename" below records it. The
  `aac-native-v0.2.0` tag stayed held: the first release is `audio-core-v0.2.0`, and
  until it is published installs build the binding from source.

### Bånder Audio Core rename

Delivered from the retired plan `2026-11-12-1934-refactor-rename-baac-to-bac-plan.md`
on the branch `rename-audio-core`, by the user's decision of 2026-11-12, and landed
on `master` on 2026-11-13. bAAC is now Bånder Audio Core, short name `bac`, with no
aliases; the package lives at `packages/baander-audio-core`. The user chose to land
before the dispatched FFmpeg image was ready, so `master` moved without the pin at
`cbc5540f4`, and the next commit pins the image that the `ffmpeg.yaml` dispatch
published from `489926f68`. The `aac-native-v0.2.0` tag stayed held, so the first
release is `audio-core-v0.2.0`; the tag points at `cbc5540f4`.

Open:

- **Remote:** the release's four prebuilds and `SHA256SUMS`, a clean desktop install
  that fetches its prebuild, and Forgejo `ci.yaml` after Forgejo's FFmpeg image
  publishes (the user set Forgejo CI aside).
- **Not run anywhere before the landing:** the macOS and NEON kernel step, the
  Windows MSVC binding and the first CI run after the cache keys changed. Only the
  binding workflow covers them, and it first runs on `master`.
- **Desktop:** in clean copies, the immutable install, `tsc` and the tests passed
  (U2 to U5, U12), and the packaging check passed after U12. After U10, only the
  immutable install was rerun.
- **Website:** a local build of `57fc9e1e3` was deployed to production on 2026-11-13
  (`baander-nl52trcat`, aliased to `https://baander.app`). The visual checks were not
  repeated on the live site.
- **Held-out recordings disclosure (2026-11-13):** on `/bac/`, the long "Held-out
  recordings" text in the lossless section now sits in a disclosure that starts
  closed. Not yet published.

### Scan artist credits, song lengths and lyrics outcomes

Delivered from the retired plan `2026-11-13-2034-fix-scan-artist-credits-plan.md`
and on `master` since `a67ed57e4` (2026-11-14): the library scan credits songs and
albums to their tagged artists, song lengths come from the audio probe, and a lyrics
fetch reports a missing artist, a missing length and an LRCLIB rejection each as its
own outcome.

Open:

- A single artist value that names several artists stays whole in the scan (R7);
  matching the album splits it. On 2026-11-14 the user chose matching over splitting
  on an album-artist prefix in the scan.
- Deferred, each separate work:
  - album identity across directories: albums have no uniqueness on title, and
    duplicate detection with `app:album:merge` covers the outcome until album identity
    is planned;
  - a file's song found by hash across libraries (`findByHash` is catalog-wide): a
    file shared by two libraries needs its own identity design;
  - genre slugs collapsing non-ASCII names, and Unicode (NFC and NFD) spellings of one
    name becoming two artists: both predate the branch, and no real library has shown
    them yet;
  - M4A tags: the ID3 reader finds none, so an MP4 atom reader is needed; the
    persistent library has no M4A files;
  - a forced-sync rename followed by a wrong ID: no code path reaches it today.
- From review, not fixed: the import handler's size and its `PreparedSong` array shape
  (a split into a tag-credit reader and a rescan repair was suggested); genre creation
  by the metadata enrichers takes no import lock, so an enricher that creates a genre
  while a running import creates the same slug fails on `uniq_genres_slug`, and the
  album enricher logs that genre failure and goes on (locking it needs a transaction
  around the enricher's flush, which is separate work); a retag is any byte change at a
  song's path, so a library-wide ReplayGain pass replaces hand-made credits on the next
  scan (the settled retag decision).
- Housekeeping: the postgres-remediation `doctrine.md` version list is stale;
  `composer normalize` fails on `master`. The PGroonga part of this item is done; see
  [PGroonga cleanup race](#pgroonga-cleanup-race).

### MusicBrainz matching

Delivered from the retired plan `2026-11-14-1219-feat-musicbrainz-matching-plan.md`
and on `master` since `435a11639` (2026-11-14): an admin matches an album, song or
artist to MusicBrainz from a dialog or the console, and a queue matches confident
albums on its own. The plan's "Implementation Amendments" section records where the
build departs from the plan text.

Its review leftovers were delivered by [Matching follow-ups](#matching-follow-ups)
and [Matching leftovers](#matching-leftovers).

### Matching follow-ups

Delivered from
[the matching follow-ups plan](docs/plans/2026-11-15-1038-fix-matching-follow-ups-plan.md)
and on `master` since 2026-11-15 (`a7d1c7466` to `6991a9f9a` and this record, on top
of `23f899976`):

- **Lyrics** (U1 to U3). Every LRCLIB request, from any process, takes a turn under one
  shared pace of one request every two seconds. A 429, or a 503 with `Retry-After`,
  pauses every process for the time LRCLIB names, an hour at most, and the request is
  sent again afterwards. A call waits at most 10 seconds, a queued fetch 30. A fetch
  sends one request, where it sent two and the first went to a route LRCLIB no longer
  serves. Queued fetches are spaced 3,000 ms, which is also the minimum. A worker's
  waits last their full time although its keepalive alarm arrives. The lyrics dialog
  searches on submit, and a failed search is sent once.
- **Album match queue** (U4 to U7). A run's claim is an ID and a 15-minute expiry in
  columns of their own, so a request for an album that a run is matching starts no
  second run and takes effect when the run ends. A cover the Cover Art Archive refuses
  no longer pauses the queue: the match is applied without it, and a manual apply
  answers 409 `cover_refused`. An album search that finds nothing is repeated without
  a trailing comma-separated list in square brackets. A match's 404 names its cause,
  and the dialog says what to do for a 404 and a 422.
- **Smaller corrections** (U8, U9). A merge clears `match_checked_at` on the songs it
  moves and requests a match of the target while automatic matching is on; a song that
  joins a matched album takes a track's recording ID and credits only within the
  length tolerance. The per-user MusicBrainz allowance is keyed by user ID.
- **Qualification harness** (U10, U11). The metadata transport answers the queue's
  release search and lookup, queue runs count as metadata work, and a scenario's
  window requires song identities, with the count of 100 albums holding a MusicBrainz
  ID as the proof of the album matches (user decision, 2026-11-15).
- **Documentation** (U12). The operator pages, context guides, READMEs and
  `CONCEPTS.md` describe the above, and no page says an artist sync runs.
- **From the code review**, decided by the user on 2026-11-15: `GET /api/lyrics/search`
  requires the administrator role, because each search takes a turn of the shared
  pace, and a pause LRCLIB names is capped at one hour.

Verification on 2026-11-15:

- Full backend suite in disposable containers at `5eb1de941`: 12,822 tests in four
  lanes, 12,821 passed. The one failure was the referential integrity guard, which
  did not list the new `claim_id` column; `2f59f6ccf` lists it, and the guard's 10
  tests pass. The run at `85bfb293b` selected 12,833 tests and passed all but two,
  both in tests the branch does not touch. `StreamedResponseProcessorTest` once got
  curl's exit 52 where it expects 18, and passed three reruns on its own.
  `CoveredIndexPersistenceTest` failed on `pgroonga: failed to remove column` during
  an `ANALYZE` and passed when run directly after `AlbumMatchClaimMigrationTest`. That
  error is two PGroonga cleanups colliding over the leftover objects of dropped
  indexes, not the "object isn't found" failure; see
  [PGroonga cleanup race](#pgroonga-cleanup-race).
- Static gates at `85bfb293b`: PHPStan reports no errors, Deptrac 0 violations,
  `lint:container` passes, and the OpenAPI specification and the generated web client
  match the application.
- Tooling in a CI image built at `4077229f3`: 1,595 tests, 13,553 assertions, 7
  skipped, with the tooling analysis, architecture and format checks clean. After the
  later tooling change (`5eb1de941`) the three static checks pass on the host.
- Web at `6991a9f9a`: typecheck passes, lint reports 0 errors and 10 warnings, and
  all 3,580 tests in 285 files pass. The full run found four cases of a permissions
  test that pinned the lyrics search's query options; the test now expects the search
  without a retry.
- The match claim's race test fails with a `DELETE` and an `UPDATE` as parts of one
  statement and passes with the `MERGE` that was kept (two sessions, PostgreSQL 18.4).
- The preview, at `2f59f6ccf`, against LRCLIB and MusicBrainz themselves:
  - one `app:lyrics:fetch` with default settings queued 77 fetches and finished in
    about four minutes with 61 found, 16 without a match, none rejected and none
    unavailable. LRCLIB answered no 429, so no pause was stored;
  - in the lyrics dialog, typing 18 characters sent no request and Enter sent one;
  - `app:metadata:match:search` lists five releases of "Ten Thousand Fists" by
    Disturbed for "Ten Thousand Fists [Reprise Records,9362-49433-2,EU]", each scoring
    100, where the album found no candidate before;
  - a queue run claimed that album's request and within about ten seconds left the
    album for review with `close_runner_up` and a best score of 100. For this check
    the album's earlier review mark was turned into a due request by hand.
- Simplification pass: three reviewers, ten findings, none applied; the ones worth
  keeping are listed below.
- Code review: twelve reviewers, verdict "Ready with fixes", no finding above
  moderate. Applied: the two decisions above, this record, and the retry of a failed
  lyrics search.

Open:

- On an instance that updates: a lyrics fetch queued before the update carries no
  queued flag and fails to decode, and its song stays marked for a day. The
  persistent instance updates itself from `master`, so a bulk run in flight at that
  moment loses its remaining fetches until a later run. A match request a run held at
  the update keeps its due time and is matched within the hour.
- A metadata sync does not request again an album that is on the review list with an
  unchanged song count. "Ten Thousand Fists" therefore keeps its `no_candidates`
  review on the persistent instance until an admin opens its match dialog, which now
  lists candidates, and applies one. Several 14-track editions tie at score 100.
- The pause has met no real 429. A `Retry-After` in date form takes the fallback of 5
  and then 15 seconds, so the request could be sent again inside the time LRCLIB
  named; the warning logged with the first real pause shows which form LRCLIB sends.
  A stored pause ends early only when its Redis key `lyrics:lrclib:pause` is deleted.
- The claim's lease is not renewed. A match that takes longer than 15 minutes lets a
  second run take the album: MusicBrainz is asked twice, and the apply's row lock and
  release recheck keep a second write out.
- Not run on the preview: an album merge, because its five albums hold no pair to
  merge. `AlbumMergeTest` (14 tests) runs a merge through the real matcher and apply.
- Waits that a worker's keepalive alarm can still cut short: the webhook delivery
  back-off (`WebhookDeliveryService`), and the `Retry-After` waits of the Discogs,
  Spotify and TMDB adapters, which no worker reaches today.
- From the code review, not built: a test that fails when the MusicBrainz adapter
  goes back to the interruptible sleep. The other test leftovers were delivered by
  [Matching leftovers](#matching-leftovers).
- From the simplification pass, left as they are: the same User-Agent string in the
  LRCLIB, MusicBrainz and Cover Art Archive adapters; the ranking of candidates
  written twice, in the dialog's search handler and the matcher (predates the branch);
  `nextRequestDueAt()` reads every requested row since it counts a claimed row from
  its claim's expiry; `LrclibClient` takes a sleep closure where the Transcode context
  uses `SleeperInterface`.
- Qualification: the 4 GB run is still to do. Its cold start gets its album matches
  from the admin sync, since automatic matching is off by default. An album the queue
  leaves for review keeps the count below 100 until the two-hour maintenance deadline,
  and that failure names no album.
- The plan file stays until the next cleanup retires it.

### Matching leftovers

Delivered from
[the matching leftovers plan](docs/plans/2026-11-15-1624-fix-matching-leftovers-plan.md)
and on `master` since 2026-11-16 (`a822bf362` to `0405c6142` and this record, on top
of `8d119f5df`). The user chose this scope on 2026-11-15 and answered the
plan's questions the same day: plays as well as favorites follow a merged album, a
merge folds a duplicate song into the song it keeps, artist sync is rebuilt as a fill
by stored ID, an artist is filled as soon as it gets an ID, an asked artist is
remembered for 30 days, an import fills nothing while automatic matching is off, and
MusicBrainz's redirect for a merged artist ID is reported, not followed.

- **Hand changes** (U2). Adding, removing or changing an artist's credit runs in one
  transaction under the locks a match apply takes, in the same order: the album's row,
  the import keys of the artist's name and MusicBrainz ID, the artist's row, the song's
  row. It writes the credit and drops the song's `credits` mark together. An artist,
  song or album gone by then is "not found"; a deadlock, or an artist that took another
  name or ID while the change waited, answers 409 `credit_busy`, exit code 1 on the
  console. A song edit locks the song's row and edits the song as read under it.
- **Album merge** (U3, U4, U14). A merge runs in one transaction: the source row
  `FOR UPDATE`, the target `FOR NO KEY UPDATE`, then every song row of both albums. It
  moves every source song, so a song an import adds to the source meanwhile waits and
  fails instead of being deleted with it. The cover goes after the commit; a deadlock
  answers 409 `merge_busy`, and both web merge dialogs show the API's message. A
  user's favorite of the source and the source's plays follow the target. A duplicate
  (a source song whose file hash the target holds) is folded into the target's oldest
  song of that hash: its playlist entries, lyrics when the kept song has none, plays
  (counts added, the later play kept, loved when either was) and favorites move, and
  its row goes while its file stays on disk.
- **An apply beside the queue** (U5, U6). Each effect was reproduced by a failing test
  before its repair. An automatic match of a matched album's new songs no longer
  replaces the album's own credits (effect C), and it writes nothing when another
  match compared one of those songs (B) or a retag paired them with other tracks (D)
  since the matcher read the album. A manual album apply requests the album's match
  after its commit, so a song an import added meanwhile is matched (A).
- **Artist sync** (U11, U12, U15). An admin metadata sync also queues one
  `SyncArtistsMessage`, which queues a fill for every artist with a MusicBrainz ID and
  an empty detail field, two seconds apart on one pace shared with every other
  producer. A match apply that identifies an artist queues its fill after the commit,
  and so does an import batch while automatic matching is on. The fill looks the
  artist up by its stored ID only, fills empty unlocked fields under the artist's row
  lock, and is not asked again about that ID for 30 days. The name-search enricher,
  the `includeArtists` flag and the searched-ID assigner are removed.
- **Tests and harness** (U1, U7, U8, U9, U13). The in-memory match states and the SQL
  repository pass one set of 19 cases; a queue run of a replaced chain is pinned on
  every path; the match dialog's refused cover has its test; the qualification
  transport answers artist lookups and counts artist syncs as metadata work.
- **Documentation** (U10). The operator pages, context guides, READMEs, the context
  map, the worker inventory and `CONCEPTS.md` describe the above.
- **From the code review**: an artist whose fill the message bus refused is no longer
  left marked as queued for a day (`0405c6142`).

MusicBrainz and a merged artist ID: on 2026-11-15 at 18:28 UTC, one artist comparison
on the preview with an ID that Wikidata lists as a MusicBrainz redirect
(`3ec87112-9081-467b-8327-e18286f1b020`) answered "MusicBrainz is unavailable". The
MusicBrainz server answers a lookup of a merged ID with 301 to the surviving ID
(`Controller/Role/Load.pm`), and the HTTP client refused every redirect, so the adapter
reported an outage. `fetchArtist` now asks the client for the redirect and reports
`MusicBrainzMerged` with both IDs; the 301 and its `Location` were not observed live.

Verification on 2026-11-15:

- Each unit's race, functional and firewall tests ran in disposable containers before
  its commit; from U4 on, against the commit in a separate worktree. The race classes
  `HandChangeRaceTest` (9), `AlbumMergeRaceTest` (11), `QueueRunBesideWriterRaceTest`
  (4) and `ArtistSyncRaceTest` (3) ran, none skipped.
- The reproductions failed before their repairs: C with the album credits
  `Post Malone` where the admin had set `Austin Post`; B with the duet credited to Post
  Malone and Luke Combs although the admin left its credits unticked; D with each
  song taking the recording of the track it paired with before the retag; A with no
  match state for the album after the apply.
- Full backend suite in disposable containers at `e02d023de`: 13,027 tests in 1,179
  files and four lanes, all passed.
- PHPStan over the whole project in the CI image at `e02d023de`: no errors. Deptrac 0
  violations and `lint:container` pass at `b83a12da2`. The OpenAPI specification and
  the generated web client match the application.
- Web at `e02d023de`: typecheck passes, lint reports 0 errors and 10 warnings, and all
  3,583 tests in 286 files pass.
- Tooling: the qualification transport and qualification tests (12 and 38 cases) and
  the tooling format, analysis and architecture checks pass on the host. The full
  tooling suite was not run.
- Documentation: the command documentation coverage test passes and
  `make website-build` builds 243 pages.
- The preview at `afa740518`: `app:artist:song:add` credited a producer on a song
  whose credits a match had set, and the song's `matched_fields` went from
  `["credits"]` to `[]`; `app:artist:song:remove` removed the credit again. Not run on
  the preview: the API half, because the credit routes take a DPoP-bound token and no
  web screen calls them (the functional `ArtistCreditCommandsTest` runs both entry
  points), and an album merge, because the preview's five albums hold no pair.
- Simplification: two passes. Applied: one factory for a stored artist's identity and
  one source for the pairing tolerance of new songs (`b62a1cd22`), and one helper for
  releasing loaded holders before a delete (`e02d023de`).
- Code review: nine reviewers, verdict "Ready with fixes", one moderate finding,
  applied (`0405c6142`). At that commit the artist sync, merge and metadata admin
  tests (71, among them `ArtistSyncRaceTest`, `AlbumMergeRaceTest` and
  `ArtistMergeRaceTest`) pass in disposable containers, the Catalog and Metadata unit
  tests pass on the host apart from `VideoServiceTest`, which needs `finfo`, and
  PHPStan over the whole project reports no errors.

Open:

- Writes beside a merge: a play read before the merge and saved after it can
  overwrite the folded play count (`RecordPlayHandler` reads and writes without a
  lock), and an existing play saved after the merge loses its album
  (`MediaActivityRepository::syncToEntity` looks up the deleted source). A favorite of
  the target added after the merge's statement waits and then fails with 23505, which
  the add maps to nothing.
- Artist sync:
  - an artist whose only empty detail fields are locked is queued by every admin sync;
    it makes no request but takes a two-second step. The six fields are listed three
    times (`SyncArtistHandler::DETAILS`, its `missesDetails()` and the query in
    `ArtistRepository::identifiedWithMissingDetailsAfter()`);
  - an album artist that only an import's album credit creates is filled by the next
    admin sync, not by the import;
  - one outage moves the shared due time of every later fill by its pause of 5, 15 or
    60 minutes, and a 301 to anything but another artist lookup counts as an outage,
    tried again three times at every admin sync;
  - after a cancel, a new admin sync queues none of the cancelled run's artists until
    their cancelled fills fall due;
  - a fill whose requeue after an outage could not be sent keeps its queued mark for
    up to a day once its retries are spent;
  - `InMemoryQueuedArtistSyncs` shares no contract cases with
    `RedisQueuedArtistSyncs`;
  - release and recording lookups of a merged ID still answer as outages; the adapter
    can report them the same way.
- Deferred by the plan, each separate work: the other hand edits that save a copy read
  before a concurrent commit (`UpdateAlbumHandler`, `SetCoverHandler`,
  `RemoveCoverHandler`); the import's repair of a known song without a row lock;
  deleting an artist leaves the `credits` mark on the songs it uncredits; one failed
  match request ends the other requests of the same import; favorites of an album or
  song deleted outright dangle; `MovieRepository::delete` has the gaps the album
  delete no longer has.
- From the simplification passes, left as they are: the credit lock order in a static
  method of `ArtistCreditInput` with eight parameters; the release loop in each of the
  four album merge adapters; the job-run records of the artist sync store and the
  lyrics store; `ArtistMatchWriter::text()` and `ApplyMatchHandler::text()`.
- Point 2 of
  `docs/solutions/architecture-patterns/concurrent-imports-find-or-create-identities-under-sorted-advisory-locks.md`
  cites the removed assigner; it is due at the next learnings refresh.
- `dev-docs/worker-runtime-inventory.md` holds 15 links that were broken before this
  branch.
- The plan file stays until the next cleanup retires it.

### PGroonga cleanup race

Delivered from
[the PGroonga cleanup race plan](docs/plans/2026-11-16-0919-fix-pgroonga-cleanup-race-plan.md)
and on `master` since 2026-11-16 (`8a3cd708b` to this record, on top of `44e315ee3`).
`7c2deaa24` went first, so that GitHub published the 4.1.0 image as
`ghcr.io/baander-app/baander-postgres:latest` (16:36 UTC) before the CI run that
carries the new version check. The user chose the item on 2026-11-16 as "PGroonga
isolation of throwaway test schemas" and answered the plan's questions the same day:
the database image moves to PGroonga 4.1.0, and the persistent instance moves to it
with its data in this work, after the push.

The cause was not the schema drops. PGroonga keeps an index's data in Groonga objects
outside PostgreSQL's catalog, and its cleanup of objects whose index no longer exists
runs in every `VACUUM` and `ANALYZE` of a table with a PGroonga index, autovacuum's
included, and in `pgroonga_vacuum()`. In 4.0.5 that cleanup looked each index up
under the cleaning session's snapshot, so it deleted the objects of an index another
session had built and not yet committed (upstream GH-1013, fixed in 4.1.0 on 2026-10-09). A test that builds
its schema with `FinalSchemaBootstrap` replays all 28 migrations, five PGroonga indexes
among them, in one transaction; an autovacuum during the replay left the test with an
index that answered "object isn't found". Each of the six such failures on record,
from 2026-10-22 to 2026-11-15, hit the index the failing test had just built. The
"failed to remove column ... failed to stat" error during `ANALYZE` is a second
symptom: two cleanups colliding over the leftover objects of dropped indexes, which
4.1.0 still allows.

- **Image** (U2, `7c2deaa24`). `docker/postgres/Dockerfile` and `docker-compose.yml`
  pin `postgresql-18-pgdg-pgroonga` 4.1.0-1, and the developer guide names the
  version and why it is the minimum. No migration.
- **Leftovers before a test's cleanup** (U3, `ba62eb577`). `PgroongaLeftovers::clear()`
  takes SHARE UPDATE EXCLUSIVE on every table with a PGroonga index, which autovacuum
  skips rather than waits for, and runs `pgroonga_vacuum()` as the only cleanup in the
  database; inside a transaction the locks last until it ends.
  `CoveredIndexPersistenceTest` and the image read-scope plan fixture call it before
  their `ANALYZE`.
- **Regression test** (U4, `7c62a6a42`). `PgroongaUncommittedIndexTest` builds an index
  on a new table in one session's open transaction while another session runs
  `ANALYZE`, `VACUUM` or `pgroonga_vacuum()`, then commits and searches.
  `PgroongaSearchCompatibilityTest` requires PGroonga 4.1.0 in a case of its own, so
  the messaging gate, which runs it, needs 4.1.0 too.
- **Guard** (U5, `120088fe6`). `PgroongaCleanupConventionTest` fails when a file under
  `tests/` runs `ANALYZE`, `VACUUM` or `pgroonga_vacuum()` without calling the helper,
  unless the guard's reviewed list holds the file with its count and a reason.
- **Documentation** (U6). The extension inventory, the solution page, renamed to
  `docs/solutions/developer-experience/pgroonga-4-0-cleanup-deletes-indexes-of-uncommitted-transactions.md`, the testing conventions, the backend runner page and the
  development environment guide describe the above.
- **Beside the plan**, both on the user's word of 2026-11-16: the development
  acceptance client's DPoP key always carries 32-byte coordinates (`41bc3d439`), the
  cause of the step 39 failure in run 43826369096; and every image is public on GHCR,
  so the check that failed a push when the application or Nginx image could be pulled
  anonymously is removed, with its script and test cases, and the CI guide lists every
  image as public (`9e7d164bd`).

Verification on 2026-11-16:

- Two database images built the same day from the Dockerfile, differing only in the
  PGroonga package (Debian 13.7, PostgreSQL 18.7-1.pgdg13+2, Groonga 16.1.5):
  `baander-database:pgroonga-4.0.5` (`cbcbb462e4f1`) and
  `baander-database:pgroonga-4.1.0` (`181e00534c51`).
- Probes on disposable containers of both images, with PostgreSQL's default
  configuration:
  - dropping a schema with all five PGroonga tables broke no later insert or search,
    and `pgroonga_vacuum()` removed its leftovers;
  - with autovacuum off, a second session's `ANALYZE`, `VACUUM` or `pgroonga_vacuum()`
    during an uncommitted index build made the index fail after the commit on 4.0.5,
    in all three cases, with `22023 ... object isn't found`, and `REINDEX` repaired
    it; on 4.1.0 all three passed;
  - with autovacuum alone at a one-second naptime, a ten-second build lost its
    objects on 4.0.5 and kept them on 4.1.0 through ten autovacuum runs;
  - two cleanups released at once into 50 leftover indexes collided in 30 of 30 tries
    on both versions (one failed with "failed to remove column", or on 4.1.0 twice with
    "nonexistent object"); the other finished, and a cleanup run alone afterwards
    left no orphan;
  - on 4.1.0, autovacuum skipped every table held in SHARE UPDATE EXCLUSIVE and logged
    "skipping vacuum ... lock not available"; in 20 tries the helper's lock request
    cancelled the autovacuum worker that held `songs`, and its `pgroonga_vacuum()`
    raised no error and left no orphan.
- A full backend run on 4.0.5 with every autovacuum logged (seed 41160, master's code):
  autovacuum processed PGroonga-indexed tables 27 times in `stack-1` and 38 times in
  `stack-2`, each a PGroonga cleanup. No PGroonga error came up that run; its one
  failure is the audio rendition test below.
- The branch's tests: on 4.0.5 the three regression cases failed with
  `object isn't found` and the version floor with its 4.1.0 message, and the tests run
  after them in the same lane passed; on 4.1.0 all 19 tests of the targeted set passed, none
  skipped, with no PGroonga error in the PostgreSQL log.
- Five full backend runs on 4.1.0 at `dfbd5684d`, 13,028 tests in 1,179 files each, all
  passed with no `pgroonga:` error in any lane's PostgreSQL log:

  | Seed | ordinary | native | `stack-1` | `stack-2` |
  |---|---|---|---|---|
  | 20261116 | 98.7 s | 344.9 s | 1,420.0 s | 1,382.3 s |
  | 7341 | 89.6 s | 341.4 s | 1,408.3 s | 1,360.1 s |
  | 99881 | 94.8 s | 340.3 s | 1,409.3 s | 1,376.0 s |
  | 424242 | 95.9 s | 341.0 s | 1,390.3 s | 1,377.0 s |
  | 31337 | 93.2 s | 340.9 s | 1,403.3 s | 1,370.5 s |

  The run on 4.0.5 above took 1,444.0 s and 1,405.6 s in its stack lanes, so 4.1.0's
  extra catalog check showed no cost in lane time.
- PHPStan over the whole project reports no errors and Deptrac 0 violations at
  `dfbd5684d`. The tooling tests that read `docker/postgres` pass
  (`DevelopmentImagePinsTest` 11, `PresetDeploymentTest` 28,
  `PresetPostgresSettingsTest` 10, `PythonBoundaryTest` 8), and so do the 6 tests of
  `DevelopmentAcceptanceTest` at `41bc3d439`; its new case failed first on a key with a
  31-byte coordinate.
- Simplification: three reviewers; applied the removal of single-use connection
  aliases (`dfbd5684d`). Left as they are: the new scanner repeats
  `PoolSafeSqlScanner`'s literal reading and line counting, which only a shared helper
  that edits that scanner would remove; the two new integration tests each define the
  project's PGroonga index options and their own throwaway schema.
- Code review of `44e315ee3..9e7d164bd`, focused depth, the correctness pass and one
  adversarial reviewer (the checkout turns the cross-model pass off): verdict "Ready
  to merge", no findings. Its residual risks are under Open.
- The persistent instance (U7), 16:43 to 16:57 UTC: a dump taken in one snapshot
  restored with `--exit-on-error --single-transaction` into a scratch container of
  its 4.0.5 image, with the six row counts (users 2, libraries 2, artists 19, albums
  5, songs 77, playlists 0) equal to the snapshot's; the same update passed first on
  a copy of the stopped instance's volume. After `make dev-down`, removal of the
  retained 4.0.5 image (`c7edb4291502`) and `make dev-up`, the instance runs image
  `971daf64233b` (PGroonga 4.1.0-1, PostgreSQL 18.7) on its own cluster
  (`system_identifier` unchanged); `ALTER EXTENSION pgroonga UPDATE` moved
  `extversion` from 4.0.5 to 4.1.0, `pgroonga_list_broken_indexes()` returned no rows,
  so no `REINDEX` ran, and each of the five PGroonga indexes answered a search through
  the index. The server log shows no collation, PGroonga or other error. The new image
  was built by the updater's timer, which `make dev-up` enables before its own update.

Open:

- From the code review: `PgroongaLeftovers::clear()` sets no `lock_timeout`, so a
  lock holder that PostgreSQL does not cancel (an anti-wraparound autovacuum, or a
  connection of the same test process holding uncommitted DDL on a PGroonga table)
  would hang the lane until the step's budget instead of failing at once; and the
  guard exempts a whole file once it calls the helper, so a later cleanup in that file
  after new leftovers would pass it.
- `pgroonga_list_broken_indexes()` was not tried against a broken index on 4.1.0;
  on 4.0.5 it raises "object isn't found" instead of listing the index.
- `AudioRenditionWorkerHandoffTest` failed in the 4.0.5 run above with
  `Audio transcoding failed: Cannot safely resolve filesystem path` on the `.part` path
  of a rendition (`AudioRenditionCache::validatePaths`), as another of its tests did on
  2026-11-07. Separate work.
- Deferred by the plan, each separate work: a template database instead of about 500
  migration replays per run; the test stacks start PostgreSQL with its defaults, not
  `docker/postgres/conf/postgresql.conf`; nothing checks that the local default test
  image `baander-database:latest` is current, and on the development host it was a
  Debian 12 build until 2026-11-16; an upstream report of the cleanup collision.
- The plan file stays until the next cleanup retires it.

### Lyrics effect toggle and song browser panes

Fixed in `9ecf84a3d` and `052e8684d`, pushed on 2026-11-15: in the fullscreen lyrics
view a click on the active effect turns effects off, and the songs view's genre,
artist and album panes scroll.

Open: the pane fix has no automated test, because jsdom computes no layout; a
Playwright check of the songs view would cover it. Not run: `yarn build`, and Firefox
or Safari.

## Roadmap maintenance

Cleanup, 2026-11-15: removed the delivered checkpoints and resolved items of the
small-host deployment, the development controller image identities, movie playback,
the browser runner release, bAAC exact arithmetic and lossless mode, the master CI
repair, the playback session entities, the Bånder Audio Core rename, scan artist
credits, MusicBrainz matching and the web fixes of 2026-11-14, reducing the roadmap
from 2,098 to 1,105 lines; open items, limits and release gates stay. Later evidence
closed these items, which went with the rest: the hosted runs of the movie and
two-command gates, full-tree PHPStan and Deptrac (GitHub run 43638221630 passed the
quality gate's steps 14 to 36 on `435a11639`), the full tooling suite on `master`
(step 37 passed in run 43584904626 on `a67ed57e4`), the full backend suite after the
rename merge (12,700 tests at `bc10bbe05`), and the two tests the Audio Core section
listed as failing. The note that a review ran without a cross-model pass went from
each section, because the checkout configuration sets that. Eight delivered plans
were retired: movie playback, the playback session entities, the browser runner
release, bAAC exact arithmetic, bAAC lossless mode, the Audio Core rename, scan artist
credits and MusicBrainz matching; only the roadmap linked them, and it now names each
file. Nine plans remain: the six July plans and the three with open work (playback
destinations, the small-host deployment, and the capability and size settings). The
roadmap and the plans before this cleanup are preserved at `d4a938b74`. All 43 local
links resolve, and the four incoming anchors (`#application-release-acceptance`,
`#completed-work-and-evidence`, `#local-development-workflow`,
`#source-installation-documentation`) are kept.

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
