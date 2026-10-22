# Baander roadmap

Updated: 2026-10-22. This roadmap selects useful future work and records unresolved
release acceptance. Current behavior and operating contracts belong in the linked
guides. Git history retains completed milestones and their verification evidence.

## Resume here

Baander's current direction is a reliable private self-hosted backend and web
player, supported by the strongly consistent Registry. Select the next change for
an actual user workflow: a concrete failure, requested behavior with an existing
runtime consumer and a demonstrated gap, or a named release decision that existing
evidence cannot answer. A shipped binary alone does not establish a consumer.

No further implementation task is selected here. Historical unchecked items,
old plan limitations and incomplete experiments do not authorize work. The retained
[plans](docs/plans/) supply design context; reconcile them with current callers and
behavior before proposing a change. This applies to older cache/sweep, segmenter,
FLAC/DSP and secondary-client plans as well as developer tooling.

Plan cleanup, 2026-10-21: removed 33 completed plans and retained six with
unfinished or partly superseded commitments. Completed plans remain in Git
history. Reference, link and whitespace checks passed; no application files
changed or application suites ran.

Use the existing checks for the selected change; add a regression for a specific
uncovered failure. Broader replay, asynchronous trace provenance, hypothetical API
growth and qualification of unused native algorithms have no active roadmap task.
A release gate below becomes actionable when its named environment, access or
release-path decision is available. Its absence does not select more local drills.

Read [AGENTS.md](AGENTS.md) for workflow and
[CONCEPTS.md](CONCEPTS.md) for shared terms. The
[testing guide](docs-book/part-2-developer-guide/testing.md) identifies current
runners and verification boundaries. Completed suite counts describe their own
historical trees; they are not current acceptance evidence for a new change.

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
  Checkpoint, 2026-10-21: production and worker images omit 14 unused
  documentation UI files (11,515,432 bytes). Both inventories passed, and real
  JSON/YAML exports and the configured JSON controller stayed byte-identical
  across 245 API paths. The existing isolated worker smoke passed in 36.71 seconds;
  shell syntax and whitespace checks passed. The review record retains measurements,
  notice resolutions and remaining gaps. Release qualification remains open.
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

React Native and Android remain deferred at the user's request (2026-10-05).
Resume their feature, API and tooling reconciliation only when the user resumes
that work. Historical mobile failures do not select a task or a new CI lane.

## Source-installation documentation

Checkpoint, 2026-10-21: the README introduces browsing, metadata, search, playlists
and playback, links the source-installation path and developer resources, and
retains package licensing exceptions. The getting-started guide now orders
configuration, shared storage, dependency startup, one-off Composer installation,
main-only migrations, OAuth/admin setup, frontend build and separate version-4
worker deployment. Messaging contracts, retry/replay limits, heartbeat metrics and
upgrade precautions live in the existing developer/operator guides. The testing
guide already covers the removed README runner descriptions and remains unchanged.

Disposable bootstrap verification used a fresh local clone, private generated
credentials, unique image/network/volume names and isolated port mappings. Compose
configuration validation passed. The image build failed at `Dockerfile:497`: the
dev target's `pecl bundle -d /tmp/pecl xdebug` reported `unable to unpack xdebug`.
No services started; no existing application database, Redis or media was reused
or reset. The host PHP 8.5 installation also lacks the worker controller's required
`posix` extension. Frontend loading, administrator login, worker startup and a
sample-library scan remain unverified through this procedure. Fix the build and
provide the host prerequisite before repeating those checks; deployment code was
not changed by this documentation work.

Documentation verification: 86 local links and heading anchors resolved; command
signatures and configuration order were checked against source. Preservation audits
covered all eight edited documents and the new plan; removals and structural changes
match the requested rewrite and relocation. Whitespace checks passed. Independent
plan and final documentation reviews found no defects. No application test suite
was run for this documentation-only change; the runtime build failure above is a
verification limit, not a passing bootstrap.

Repair checkpoint, 2026-10-21: the dev image now bundles the explicit archive for
`XDEBUG_VERSION`. PEAR 1.10.16's REST client passed an HTTP chunk-size marker into
its XML parser, so package lookup failed before unpacking. The archive URL bypasses
that lookup and fixes the previously ignored version selection. The existing
extension installation, startup controls and INI paths remain unchanged.
The new `scripts/test-dev-xdebug-container.sh` checks the installed version,
normal startup disabling Xdebug, actual coverage collection and both `www-data`
debug aliases. CI runs it immediately after building the CI image.

Verification: real dev builds passed for default Xdebug 3.5.0 and override 3.5.3.
A forced dev-stage rebuild (`--no-cache-filter dev`) restored 3.5.0 with the cache
populated by 3.5.3; its runtime checks passed. The actual CI target also built and
passed all four container scenarios. The final smoke script additionally checks
that `debug-on` activates both debug and develop modes; all four scenarios passed
on each of the three resulting image tags (12 checks). Docker context checks
passed with 34 host artifacts excluded and 24 source, asset and license markers
preserved. Shell syntax, workflow YAML parsing and whitespace checks passed.
Three simplification reviews found no changes needed. Independent code review's
mode-assertion suggestion was applied. Prose preservation audits retain the
historical checkpoint and account for the new repair evidence. No application
services started, and no database, Redis or media storage was reused or reset.

The README and getting-started guide now report the repaired build. The historical
bootstrap failure above remains recorded; frontend loading, administrator login,
worker startup and a sample-library scan are still unverified through that
procedure, and the verification host still needs PHP's `posix` extension.

Bootstrap checkpoint, 2026-10-21: repeated the installation from `d35c74e5` with
fresh credentials, services, storage and ports. Pinned Yarn 4.14.1 rejected the
lockfile's metadata version 10; changing only that metadata to 9 made immutable
installation pass without changing dependency resolution. Frontend type-checking
and the production asset build passed.

Browser login exposed two proxy configuration errors. The guide omitted explicit
proxy trust, so the application generated an HTTP API origin behind nginx TLS.
The development nginx template also discarded the published port through `$host`.
The template now preserves `$http_host` and clears incoming `X-Forwarded-Port` in
all three forwarding blocks. The guide configures only nginx's actual address in
`TRUSTED_PROXIES` and requires updating it after nginx recreation. Default proxy
trust remains empty.

Verification passed: dev, nginx, PostgreSQL and worker image builds; Composer
installation and runtime autoloader generation; authenticated Redis `PONG`; six
main-database migrations with 494 SQL queries; initial OAuth keys, first-party
client and administrator creation. In a real browser, the frontend loaded over
HTTPS on port 18443, the administrator logged in, and authenticated catalog API
requests returned 200. The generated API origin matched the browser origin.
A three-second synthetic FLAC scan discovered one file. The admitted worker pair
acknowledged its ingestion, creating one song and one album; the administrator's
Songs page displayed Bootstrap Tone from Bootstrap Sample. The separate production
worker harness also passed admission, outbox/scheduler activity and retirement.

Regression evidence: the new nginx check failed against the old template because
it lost the public port and forwarded a spoofed port header. With the fix, all six
origin cases passed across ordinary, WebSocket and streaming routes, alongside the
existing log checks for all three nginx configurations. Proxy trust and DPoP tests
passed in the CI image: 19 tests, 39 assertions. Three simplification reviews
found no changes needed. Independent final review returned no findings. Shell
syntax, prose preservation audits, 34 local links/anchors and whitespace checks
passed. Worker recovery reached the retired state; successful resource inventories
confirmed removal of every run-owned container, network and volume. Disposable
build images and private diagnostic logs remain available for investigation.

Verification adaptations: the Docker daemon could not bind the tool's checkout,
so the disposable source was copied to a named volume. The existing supported
trusted controller container supplied PHP extensions missing from the tool host;
this does not verify the literal host-PHP procedure. Browser automation accepted
the isolated self-signed certificate. Workers and application services used an
internal network, so external metadata providers were not exercised. No existing
application database, Redis or media storage was reused or reset.

Remaining playback defect: an additional browser play attempt returned HTTP 500
for both the original FLAC and the Opus fallback. Ingestion stored the absolute
path `/media/library/music/bootstrap-tone.flac`; streaming passed it to
`LocalFilesystem::resolve()`, which rejects absolute paths. Catalog visibility is
verified, but this sample's playback is not working. Repair the path contract and
repeat the browser playback check before treating a scanned music library as
playback-qualified; do not weaken the filesystem boundary to accept arbitrary
absolute paths.

Playback repair checkpoint, 2026-10-21: streaming now resolves the catalog's
absolute source path against its owning library through a narrow Library
application contract. The shared canonical boundary rejects traversal and symlink
escapes; the relative-only filesystem contract remains unchanged. Missing or
rejected sources return 404 after authorization. The regression scans a generated
FLAC, delivers the scanner's actual message to catalog ingestion, and requests the
persisted track through production streaming services. It checks original bytes,
byte ranges and access revocation with the library outside `MEDIA_STORAGE_PATH`.

A fresh disposable installation from `2ab80b39` plus the repair passed frontend
loading, administrator login, worker admission and ingestion of Playback Tone.
Original playback returned 206 and advanced to the end of the 12-second track.
With audio transcoding explicitly enabled, Opus returned 200 `audio/ogg` and played
to completion at 11.9935 seconds without a browser media error. Original and cached
Opus range requests returned 206 with exact 16-byte slices and the expected FLAC
and Ogg signatures.

This fresh setup exposed three required encoder settings missing from the example
configuration. The installation guide now sets `VIDEO_ENCODER=libx264`, an empty
`HW_ACCEL_DEVICE` and `HW_BITRATE_MULTIPLIER=1.0` in the nonsecret `.env` before the
worker image build. The worker credentials allowlist does not accept these keys.
The initial worker failed before these values were supplied; the rebuilt worker
then admitted and ingested the sample successfully.

The browser used `https://playback.baander.app:18444` and accepted the disposable
certificate. As in the previous check, a named checkout volume accommodated the
remote Docker daemon, and the supported trusted controller container supplied the
host PHP extensions. The worker image contained the baseline plus the explicit
encoder settings; the five changed web service/configuration files were copied
into the disposable checkout and their hashes checked against the repair. Internal
application networks prevented external metadata requests. No existing database,
Redis instance or media storage was reused or reset. This closes the sample music
playback defect above; it does not qualify other media workflows or host hardware.

Verification: 307 focused unit/native tests passed with 1,175 assertions in the CI
image. After correcting test-container access and PHPUnit stub declarations,
the affected service tests and all selected media functional/firewall tests passed:
53 tests, 632 assertions, including 35 functional/integration tests with 479
assertions. The latter cover real OAuth/DPoP authorization and original, range and
transcoded responses. Restoring only the old stream consumer made the new
scan-to-playback test fail at its first response assertion (404 instead of 200);
with the repair, it passed. Targeted PHPStan passed; Deptrac reported zero violations,
zero warnings and zero errors, with 484 existing skipped violations. Documentation
preservation audits, all 35 local links and anchors, and whitespace checks passed; the current summaries
now report verified playback while historical failures remain recorded. Three
simplification reviews and five independent code-review lenses found no defects.
The disposable worker reached the retired state; successful inventories confirmed
removal of all run-owned containers, networks and volumes. Generated credentials
and keys were removed, with sanitized evidence and build images retained.

## Expanded project introduction

Checkpoint, 2026-10-21: expanded the README into a project overview covering library
scanning, browsing and search, metadata and lyrics, music and movie playback,
playlists, account access, instance components and development. Descriptions were
checked against the current web routes, feature implementations and owning guides.
The README retains source-build and separate-worker requirements, package license
exceptions and the earlier bootstrap verification limits. This follow-up changes
no application behavior or installation commands; no application suites or runtime
walkthrough were run again.

Verification: independent plan and final documentation reviews returned no
findings. All 31 local links and heading anchors across the changed documents
resolved. Prose preservation audits and whitespace checks passed; the added
headings and capability descriptions are intentional expansion, and the existing
licensing qualifications remain intact.

## Missing media and renamed tracks

Checkpoint, 2026-10-21: scans retain file-index records when an individual source,
an entire library root or a mounted subtree disappears. Music and movie discovery
mark missing sources unavailable; returning unchanged files are queued again,
including files that disappeared before their first import. Explicit deletion
keeps its existing behavior. Failed traversal, cancellation, publication failure
and a lost scan claim do not mark unvisited paths unavailable.

Music responses expose observed availability through batched Library and Catalog
ports. Catalog lists, albums, playlists, search, recommendations and recent activity
retain unavailable songs with a label and disabled playback actions. Bulk playback
skips them, and mixed queues preserve the selected available song. Restored queues
keep their order, selection and playback position while refreshing availability
before resume. Radio playback remains separate.

Ingestion repairs a content-matched rename within the same album and parent
directory when the old source is missing and the new source is inside the available
library root. It updates the freshly read song inside the guarded transaction,
retaining IDs, metadata and relationships without new-song enrichment requests.
Cross-directory and cross-album moves remain outside this repair. Availability is
an observation, not a diagnosis of a failed mount or a live filesystem guarantee;
tracks without an index observation remain unavailable until scanned.

The new regression checks failed against the previous code: a missing root and
missing-before-import source lost index rows, a full rescan did not republish
unchanged returning files, and eligible renamed tracks retained their old paths.
The repaired scan-to-playback regression covers disappearance, restoration, two
physical renames, preserved metadata and IDs, original bytes, byte ranges and
access revocation. A real Spotlight component test also exposed its missing
command-menu provider; a local wrapper now lets that playback surface open.

Backend verification passed in the CI image: 940 tests with 5,004 assertions,
including 34 functional/integration tests with 363 assertions. The isolated
PostgreSQL runs applied seven migrations, checked the new column against ORM
mapping, preserved existing index data, and covered restoration, library isolation,
bounded batches, explicit deletion and rollback. Targeted PHPStan passed. Full
Deptrac reported zero violations, warnings and errors with 484 existing skipped
violations. OpenAPI export and client generation include song, playlist,
recommendation, activity and recent-item availability.

Review verification passed another 97 focused backend tests with 700 assertions:
76 Activity tests and 21 fresh-install/migration tests. Activity availability now
respects the viewer's current library access while retaining historical metadata.
The revoked-library regression failed before the filter was added. Migration
history assertions include the new version, and downgrade refusal retains the
existing diagnostic convention. Final PHPStan and full Deptrac checks passed.

The complete web suite passed: 190 files and 2,194 tests. Main and worker TypeScript
checks and ESLint passed, with zero lint errors and eight existing warnings.
Regression coverage includes unavailable playback controls, mixed-queue selection,
restored availability refresh, stale responses, large queues, radio handoff,
removing a playing queue entry, and a real DOM audio element's empty source URL.

Playback test documentation and runner startup messages now warn that tests can
produce audible tones or media, advise lowering or muting system output, and note
that tests may change player volume. Playwright discovery printed the warning for
both the audio-graph runner (54 listed tests) and the general browser runner (389
listed tests); these checks used `--list` and played no audio. The two-command
browser checkpoint's warning passed shell syntax checks. Remaining manual browser
verification used Chromium's `--mute-audio` output suppression after the user's
request; decoding and playback-clock checks remain distinct from listening tests.

A fresh isolated instance at `https://availability.baander.app:18445` passed
immutable frontend dependency installation, production build, administrator login,
worker admission and ingestion of two generated FLAC tracks. Hiding one file and
then the whole library root retained song and index IDs, metadata and counts.
The web app showed unavailable rows and disabled playback controls. A saved mixed
queue retained its order, current selection and preferences without starting a
stream for the missing selection; mixed-album bulk playback chose the available
track. Restoring the source and rescanning restored the exact baseline catalog and
index snapshot. Reloading that saved queue and pressing Play refreshed availability
and started the original selected track.

Incremental and forced scans repaired two subsequent physical renames while
preserving the song identity. Earlier path records remained unavailable and the
latest path was available. With browser output muted, both renamed sources decoded
and advanced without a media error; the second reached its 12-second end.
Authenticated range requests returned 206 and the requested 128-byte slices.

The browser used explicit loopback routing and a disposable self-signed certificate.
A named checkout volume accommodated the Docker daemon, and the supported trusted
controller container supplied the host PHP extensions. Internal application
networks kept external metadata providers outside this verification. Existing
databases, Redis instances and media storage were not reused or reset. Cleanup
verified worker retirement and removal of the run's five service containers,
seven volumes, two networks and two derived images, plus its browser session,
credentials and OAuth keys. Sanitized evidence remains available locally.

Documentation preservation checks retained historical roadmap text, code blocks
and tables; all 17 local link destinations resolved. Added concepts, behavior
sections, sound warnings and measured checkpoints account for the audit deltas.
Whitespace checks passed.
Independent simplification reviews and 11 final code-review lenses completed.
The three confirmed review findings—restored audio source handling, activity
availability after access revocation, and migration-history assertions—were fixed
and verified; no actionable findings remain.

## Directory publication and missing-file observations

Checkpoint, 2026-10-21: music and movie scanners now commit each directory's
file-index updates with a `LibraryDirectoryIndexed` outbox event. The relay sends
the existing `FilesDiscovered` message only after that commit. This closes the
race in which a fast import marked a source missing before the scanner inserted
or restored its index row, leaving an unchanged returning source permanently
skipped by incremental scans.

Traversal and hashing remain outside the directory transaction. Index or event
capture failure rolls back that directory; previously committed directories remain
accepted. Scan cancellation, lease renewal and missing-path reconciliation retain
their existing behavior. The summary's directory count now means durable
acceptance. Redis send failures use the existing outbox retry and dead-letter
policy, and a lost acknowledgement can still produce duplicate delivery. After
exhausted retries, an operator must repair the cause and use `--rescan` to queue
unchanged indexed files again. Other events keep their notification replay path.
This change adds no schema, transport or worker process, and does not complete
the audit of all event producers.

Verification in the CI image passed 549 tests with 4,199 assertions across the
focused Library selection and the expanded Catalog, relay, scan-claim, command,
workload-wiring and scan-to-streaming checks. The original interleaving failed for
both new and previously indexed sources before the repair. Eleven new integration
cases use two PostgreSQL connections and the migrated schema to verify commit
visibility, partial-write rollback, prior-directory progress, failed handoff retry,
and duplicate delivery after lost acknowledgement. Music recovery imports real
generated FLAC files; movie recovery exercises discovery, relay and the missing
source branch without claiming full video playback coverage. These tests play no
audio and use only disposable services and media. The relay tests control the
transport boundary; they do not simulate an outage of a live Redis server.

PHPStan passed for every changed PHP file. Full Deptrac reported zero violations,
warnings and errors, with 484 existing skipped violations. The final callback
typing adjustment also passed the 21 media-file integration tests with 258
assertions already included in the totals above. Three simplification reviews and
nine code-review lenses found no defects. Prose preservation audits retained the
historical entries, code blocks and tables; all 27 local links and anchors resolved,
and whitespace checks passed. The runners removed their containers and networks;
existing application services, databases and media were untouched.

## Scan ownership at the write boundary

Checkpoint, 2026-10-21: music and movie scanners now check their claim inside each
short directory or missing-path transaction. The check bypasses the heartbeat's
60-second throttle and holds the library row until commit or rollback. A scan
whose claim was released or replaced cannot overwrite a successor's index,
ingestion intent or availability observations. This closes the case where an old
empty snapshot could mark restored tracks unavailable and block their playback.

Release and takeover wait for an already guarded batch to finish. An ordinary
takeover then respects the renewed lease; a forced release prevents the old scan's
next batch. Missing paths remain bounded to 1000 per transaction. Traversal and
hashing stay outside these transactions. The existing 15-minute lease,
expired-but-unreplaced renewal, cancellation, completion checks and claim-free
movie provisioning retain their behavior. No schema or transport changed.

Verification in the CI image passed 477 distinct tests with 2,484 assertions:
368 Library unit tests, 54 compatibility tests and 55 claim/publication integration
tests. Sixteen frozen-clock release/replacement cases failed before the repair.
Twenty-four new two-connection scanner cases verify stale-owner rejection,
successor availability, release waiting through commit and rollback, and ordinary
takeover refusal after renewal. The database tests drive actual directory index
and outbox writes and missing-path updates; separate checks enforce the repository
transaction requirement and preserve claim-kind, expiry and timestamp semantics.
Compatibility checks cover missing-source recovery, explicit deletion, ingestion,
cancellation, relay behavior and scan-to-streaming responses.

Targeted PHPStan passed for every changed PHP file. Full Deptrac reported zero
violations, warnings and errors, with 484 existing skipped violations. Tests used
isolated services and temporary media and played no audio. Existing application
databases, Redis and media storage were not reused or reset.

Simplification reviews completed at both implementation boundaries; no code change
was needed. Final correctness and independent adversarial review found no
actionable defects. Prose preservation checks retained historical entries, fenced
code and tables; all 17 local links and anchors resolved, and whitespace checks
passed. The runners removed their containers and networks.

## Superseded discovery imports

Checkpoint, 2026-10-22: queued imports compare each file's recorded hash with the
latest available index observation for its library and path. A superseded,
unindexed or unavailable snapshot is skipped before metadata preparation and
checked again under shared row locks before catalog writes. Album/movie parents,
songs/videos and source renames use that write boundary. A stale album metadata
candidate is discarded so another current file in the message can still import.
Current duplicate messages, hash reversion and unchanged restored sources remain
eligible. This checks committed observations; it does not provide a snapshot of
files changing on disk between scans.

Scanner upserts use a sorted copy of their files while keeping event/result order.
Bulk unavailable and removal operations lock existing paths in the same order and
mutate only that fixed set. A later insertion is excluded from the older bulk
operation. Metadata reads and FFprobe stay outside the write locks; the existing
import/delete guard remains first. No schema or message payload changed.

Verification in the CI image passed 982 distinct tests with 6,084 assertions across
Library/Catalog units, index/media-file/publication integration, compatibility and
new observation-concurrency cases. The original real-FLAC regression failed with
two songs after B imported and A replayed; the repaired path retains only B.
Fifteen new PostgreSQL cases exercise real music/movie imports, reversed scanner
and bulk-writer inputs, commit/rollback ordering, more than 1000 target paths,
phantom exclusion and current-message replay. They also cover actual FFprobe
failure/retry and a metadata read exception followed by real-reader recovery.

Final review caught a new same-batch duplicate-video regression: preparation
could classify two identical new files as separate videos, then roll back on the
unique hash constraint. The guarded write now rechecks and reuses the persisted
video. Its real regression failed before the correction and now verifies one
video, one movie link and stable redelivery.

All changed PHP files pass PHPStan. Full Deptrac reports zero violations, warnings
and errors with 484 existing skipped violations. Tests generate temporary media
without playing audio and use disposable services; existing application databases,
Redis and media storage were not reused or reset.

Simplification at both implementation boundaries removed an unnecessary movie
return wrapper and grouped concurrency-fixture setup in one transaction. Eight
final review lenses completed; the duplicate-video finding was applied and
independently verified, with no open findings. Documentation preservation and
local-link checks passed, as did whitespace checks. Disposable test containers,
networks and volumes were removed.

## Audio renditions after a source rename

Checkpoint, 2026-10-22: audio rendition signatures include the resolved source
path alongside modification time, size, format and bitrate. A repaired track
keeps its ID while its renamed source receives an independent durable runtime
key and output directory. Cache admission and worker validation share that
signature. Repeated requests for the unchanged source reuse its completed output;
older output remains intact until the existing cache sweep removes it. No schema,
HTTP contract or compatibility fallback changed. A same-path replacement that
preserves both size and modification time remains outside this fingerprint.

The real PostgreSQL/FFmpeg regression first failed at immutable-input admission
after a physical rename that preserved file metadata. It now verifies two
completed first-generation renditions with distinct keys and output paths, intact
original output and repeated-request reuse without another generation.

Verification in the CI image passed 994 distinct tests with 4,280 assertions
across rendition and surrounding Media/Transcode units, durable runtime
integration and audio-stream functional coverage. Changed PHP files pass PHPStan;
full Deptrac reports zero violations, warnings and errors, with 484 existing
skipped violations. Three simplification reviews found no changes needed.
Tests generate media without playing audio and use disposable services; existing
application databases, Redis and media storage were not reused or reset.

The real Swoole WebRuntime drill passed progressive delivery, completed byte
ranges, the Safari request baseline, PostgreSQL outage/reload alerts and Redis
outage/session recovery. Its first run exposed an older fixture storing a relative
song path, which playback rejected with HTTP 404 before rendition admission. The
fixture now stores its generated file's absolute path, matching the existing
resolver contract. PHPStan also passes for that correction.

The reviewed plan and final code review returned no findings; the fixture repair
was checked in the same review. Prose preservation checks retained tables, fenced
code and historical checkpoints; all 13 local links and anchors resolved, and
whitespace checks passed. The runners removed their disposable services.

## AAC codec rewrite and desktop binding

Checkpoint, 2026-10-22: `packages/codecs/baander-aac` produced streams no
other decoder accepted and misdecoded every external stream. Its Huffman
codebooks 6, 7, 9 and 11, every scalefactor band table and the TNS limits
differed from ISO/IEC 14496-3; the MDCT and windows did not satisfy time-domain
aliasing cancellation; the decoder ignored short windows, grouping, KBD windows,
TNS, intensity stereo and noise substitution; and the encoder inverted the
scalefactor convention. The tables were regenerated and verified entry by entry
against FFmpeg's, and the decoder, MDCT and encoder were rewritten. The decoder
now implements every AAC-LC tool and follows the ADTS stream parameters; the
encoder implements block switching with lookahead, M/S, TNS and the
threshold-adjustment and reservoir design of the Fraunhofer FDK encoder, with
an encoder delay of 2048 samples and a flush that drains it. HE-AAC decoding
remains out of scope: SBR streams decode as their AAC-LC core and the API
reports the SBR presence; HE-AAC encoding is refused at creation. The
placeholder SBR/PS/spectral sources and the stale scratch programs were removed.

The Electron app ships the codec natively as `@baander/aac-native`, an
ESM-only Node-API package rooted in the codec directory, built by node-gyp
during `yarn install` and rebuilt for Electron by `electron-rebuild`.
`AacService` loads it lazily in the main process; an IPC module with payload
validation and a preload bridge expose one-shot and chunked decoding and
encoding to the renderer. Node 26 and Electron 45 are the baseline for the
desktop toolchain. The decoder-only WebAssembly build under
`packages/dsp/baander-aac` was removed instead of repaired: its loader handed
linear-memory pointers and `malloc`/`free` to JavaScript, so a host bug could
free or reuse codec memory, and no browser code consumed it. The Emscripten
platform and SIMD128 paths left the codec with it.

Verification: the codec's seven CTest suites pass (table consistency, MDCT,
bitstream, decoder compliance, roundtrips at all twelve sample rates and four
rate-control modes, rate control, FFmpeg interoperability). Against FFmpeg 6.1.1
our decoder reproduces FFmpeg's decode of FFmpeg-encoded streams to 135 dB
difference SNR at 8 to 96 kHz, mono and stereo, 32 to 320 kbit/s, including
short windows, TNS, intensity stereo, noise substitution and CRC-protected
ADTS; FFmpeg decodes our streams identically to our decoder in every case.
At equal bitrate the encoder's SNR matches or exceeds FFmpeg's native encoder
on tonal, harmonic, transient and mono material and trails it only on pink
noise at 32 and 64 kbit/s stereo; CBR and ABR land within 1 percent of the
target. The binding's smoke test passes on Node 22, 24 and 26 and inside the
Electron 45.0.0 runtime (Node 24.22, Node-API 10); the Electron suite passes
66 tests under Node 26.12 with the binding built; `yarn install` builds the
binding through node-gyp; the app type-checks and bundles; and an
electron-builder `dir` package for Linux carries only the binding's entry,
manifest and compiled module in `app.asar.unpacked`, from which the packaged
app encodes and decodes. The Electron header host is unreachable from the
build environment, so the Electron-ABI rebuild ran against Node headers; a
Node-API module is ABI-stable across both, which the in-Electron runs
confirm. Not possible here: listening tests; the encoder's quality claims
rest on SNR and the FDK-derived allocation design. electron-builder's checked-in
configurations still reference `ui/electron/build/afterPack.cjs` and
application icons that are not in the repository; packaging needs those
before a release build.

Checkpoint, 2026-10-22, encoder tools: the encoder gained the coding tools
of the FDK encoder as kept in AOSP `external/aac` (read from the
`android17-release` state of the `mstorsjo/fdk-aac` mirror; the AOSP host
itself is unreachable from the build environment). TNS now follows
`aacenc_tns.cpp`: lag-windowed, energy-normalized autocorrelation over
quarters of the range, an upper and a lower filter on long windows that
merge when they agree, one filter per short window with 3-bit
coefficients, and filters shared across a channel pair when they nearly
coincide; the filter lengths count down from `num_swb` as the decoder
expects. Perceptual noise substitution uses the AAC-LC tuning rows
(28 to 48 kbit/s per channel, constant-rate modes, bands above 4.1 kHz,
quarter-power and unpredictability tests, TNS gating, gap and isolation
rules) and never signals correlated noise, because FFmpeg's decoder draws
independent noise regardless and would then run ahead of a conforming
decoder's random sequence. Intensity stereo follows `intensity.cpp`
(correlation above 0.95, regions of at least six bands, direction and
loudness limits, left-channel replacement at the left level) and is
allowed when the bitrate is below five times the lowpass. The AOSP
history's own quality commits (VBR bandwidths, reduced bit reservoir for
low-delay, dead-zone quantization) apply to configurations this encoder
does not have and were not ported.

Verification: eight CTest suites pass, including the new tools suite
(noise substitution in 130 of 132 frames of white noise at 40 kbit/s mono
with the substituted region within 0.1 dB of the source; intensity stereo
in 131 of 132 frames of a panned harmonic series at 48 kbit/s with the
balance within 0.1 dB and 30.8 dB SNR; TNS on short windows and with two
filters on the transient signal) and the interop suite's new intensity and
noise-substitution cases, where FFmpeg decodes our streams identically to
our decoder (134.8 and 136.3 dB). Against FFmpeg 6.1.1 the pink-noise case
at 64 kbit/s stereo moved from 12.9/11.0 dB to 14.0/13.5 dB, now above
FFmpeg's 13.6/13.2 dB; the two-tone signal at 48 kHz gained 0.8/1.3 dB;
the click signal at 96 kbit/s lost 0.5 dB, where substitution now covers
the noise bursts; the other rows of the reference comparison are unchanged
within 0.2 dB. The binding's smoke test passes after the rebuild. The
block-switching detector then took FDK's second-order high-pass in place
of the first difference; it changes no decision on the reference signals
(every row identical) and stays for fidelity to the reference. The
remaining FDK elements were compared and not ported: the rate loop here
bisects on bits where FDK estimates from perceptual entropy, and FDK's
scalefactor assimilation is a broader form of the smoothing here.

## Concurrent playlist appends

Checkpoint, 2026-10-22: playlist additions carry append intent and the actor's
identity rather than a position calculated during the controller's first read.
The handler refreshes the complete aggregate, checks current edit authority and
song visibility, then appends after the highest stored position. Catalog deletion
can leave position gaps; survivors retain their positions and the append follows
them. The response reports the handled membership's actual position, including
an existing position when an authorized duplicate add succeeds without a write.

The controller/handler regression first produced positions `0, 1, 1` after an
intervening addition. The real PostgreSQL regression separately exposed stale
parent metadata retained by Doctrine's identity map. Both now pass. Eight new
PostgreSQL cases retain that identity map while testing an independent committed
addition, revoked collaborator authority, collaboration being disabled, duplicate
requests after revocation, a later write conflict, a catalog deletion cascade and
a deleted append target.
The existing compare-and-swap save still rejects changes committed after the
mutation read. No persistence SQL, schema, lock or retry mechanism changed.

Verification in the CI image passed 342 distinct tests with 4,284 assertions across
Playlist units, concurrency, read/mutation firewall integration and controller
functional coverage. PHPStan passes for the Playlist source and unit tests plus
the changed concurrency integration file. Full Deptrac reports zero violations,
warnings and errors with 484 existing skipped violations. Creation, update and
append share handled-result validation while retaining their existing error
messages.
The three simplification reviews found no other worthwhile changes.

An earlier broad run encountered a missing PGroonga internal source table during
an album fixture insert, before playlist creation or the existing test body. The
final isolated concurrency rerun passed all 26 cases. The setup failure's cause
remains unconfirmed; no dependency, application or schema workaround was added.
The final HTTP/controller rerun also passed. Checks used disposable services and
did not reuse or reset application databases, Redis or media storage. No audio
tests ran.

Final review confirmed that deletion between the controller and handler reads
could reach a generic exception through the refreshed lookup. That path now uses
the standard not-found exception and answers HTTP 404 without recreating the
playlist. Its real PostgreSQL regression failed before the correction and passed
afterward. Seven code-review lenses completed; the confirmed finding was applied
and the affected checks passed. Independent follow-up verified the correction;
no actionable findings remain. Prose preservation checks retained historical
checkpoints, fenced code and tables; all 13 local links and anchors resolved, and
whitespace checks passed. The runners removed their disposable containers,
networks and volumes.
