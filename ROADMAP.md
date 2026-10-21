# Baander roadmap

Updated: 2026-10-21. This roadmap selects useful future work and records unresolved
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
