# Application image inventory review

This review covers the root Dockerfile's `production` and `worker` targets on
Linux amd64, built from commit `1066d203`. It records packaged contents and
licensing evidence on 2026-10-20; redistribution qualification remains open.

The [collection procedure](../../dev-docs/application-packaged-inventory.md)
describes the commands and bundle format.
[THIRD_PARTY.md](../../THIRD_PARTY.md) defines the repository licensing boundaries.
The separate Registry inventory does not qualify the application image.

## Evidence boundary

The images were built from an extracted committed checkout passed to Docker as a
directory context. Docker applied `.dockerignore`. An earlier verification build
streamed an unfiltered Git archive into Docker, and the existing worker check
rejected its included development key. That build is excluded from the accepted
observations. Use a directory context when repeating the documented build.

The collector inspected stopped containers without mounting the checkout over
their files. Image identity, source revision, native artifact hashes, package
membership, filesystem hashes, and retained evidence were checked against each
export. These observations apply to the recorded images, not every future build
of the same revision: some upstream build inputs still resolve dynamically.

The full local bundles and logs are under
`var/test-runs/application-inventory-20261020/`. The accepted build logs are
`production-build-filtered.log` and `worker-build-filtered.log`, with adjacent
`.time` files; the accepted bundles are `production/` and `worker/`.
CI is configured to retain worker and production bundles with its existing
14-day artifact retention. No hosted
workflow or registry publication was performed for this review.

## Observed coverage

Both inventories passed generation and verification. Package, native-component,
file, evidence and gap observations are identical across the two targets; their
image identities differ. The [machine-readable review record](application-review.json)
contains the full image IDs, source revision, bundle hashes and count breakdowns.
This review document was added after the measured source snapshot.

| Observation | Production | Worker |
|---|---:|---:|
| Files and symlinks | 52,278 | 52,278 |
| Installed Debian packages | 636 | 636 |
| Installed Composer entries | 152 | 152 |
| Native/tool components | 14 | 14 |
| Native-owned filesystem entries | 80 | 80 |
| Retained evidence paths | 2,053 | 2,053 |
| Unique retained evidence blobs | 1,405 | 1,405 |
| Copied package manifests | 12 | 12 |
| Raw review observations | 5,501 | 5,501 |
| Unowned ELF files | 0 | 0 |

Composer's count includes one metadata-only metapackage. Local path repositories
are assigned to their installed packages through image-internal symlinks. Native
ownership also follows merged `/usr` aliases. No missing native artifact pattern
remains in these observations.

The raw review observations include 4,706 unclassified ordinary files, 425
scripts/tools, 152 archives, 18 WebAssembly files, and 21 copied source manifests
or lockfiles. They also include 149 Debian copyright files without
machine-readable declarations, six absent common-license references, nine
installed-package notice gaps, PIE's archive notice gap, and 14 embedded-code
reviews. The supplemental notice resolutions below are recorded separately from
these raw observations.

## Recovered notices

Three supplemental notices are included in the source tree and image:

- [PIE 1.5.2](pie-1.5.2/LICENSE.md), with its pinned
  [upstream source record](pie-1.5.2/source.json).
- [cweagans/composer-patches](composer-notices/cweagans/composer-patches/LICENSE.md)
  and [hidehalo/nanoid-php](composer-notices/hidehalo/nanoid-php/LICENSE.md),
  retrieved at the installed source revisions recorded in the
  [Composer notice source record](composer-notices/source.json).

The collector retains these notices and source records. Its raw observations
still report their absence from the original PHAR or installed package directory;
the supplemental files provide that notice evidence for the reviewed versions.
They do not establish coverage for other versions or embedded dependencies.

## Remaining redistribution work

The remaining gate concerns these observed contents:

- **Package notices:** the installed revisions of OpenTelemetry `api`, `context`,
  `exporter-otlp`, `gen-otlp-protobuf`, `sdk`, and `sem-conv` contain no
  license/notice-named files in their complete upstream split-repository trees.
  Retain authoritative notices or establish the corresponding source and notices
  in the upstream monorepo. The local `tsduck/php` package also declares
  BSD-2-Clause without a corresponding retained top-level notice.
- **Debian evidence:** some retained copyright files have no machine-readable
  license declaration. Six references name absent common-license paths:
  `GFDL-3`, `GPL-2.0`, or `GPL-3.0`. Review the copyright text and resolve those
  references; do not infer a different license from a similar filename.
- **Embedded dependencies:** PHP, liburing, eight PECL extensions, Swoole, TSDuck,
  Composer, and PIE have retained source/tool evidence and bound output hashes.
  Their embedded dependency and distribution obligations remain unreviewed.
  A top-level notice alone does not settle those obligations.
- **Copied assets and sources:** review provenance for the actual copied
  WebAssembly files, archives, native/vendor sources, and source manifests.
  JavaScript manifests are source requirements, not proof of installed npm
  dependencies. This inventory does not select new DSP algorithm qualification.
- **Unclassified inherited files:** distinguish generated OS metadata and caches
  from distributed dependency material. Observations include dpkg state files,
  generated MIME data, Python bytecode, PHP/PEAR files, and Composer download
  archives. A raw gap count is not a count of missing licenses.

The machine-readable bundle names each path and component requiring review.
Its `not-qualified` outcome remains unchanged after a successful integrity check.

## Verification checkpoint

- In the runner image, 89 Python tests passed in 0.849 seconds; seven PHP-helper
  cases were skipped because that image has no PHP. All seven helper cases passed
  in the application CI image in 0.995 seconds: 96 distinct passing tests,
  including 27 inventory/helper regressions.
- The production build took 115.83 seconds and the cached worker build took
  3.19 seconds. Collection and verification took 52.23 and 52.31 seconds,
  respectively. Both used the recorded committed source and directory context.
- The existing Docker context check passed: 34 host artifacts excluded and 24
  source, asset and license markers retained. Accepted-image observations also
  confirm that `.agents/`, `docs/`, and the development key are absent.
- The existing production worker check passed in 36.90 seconds. It checked
  production configuration and dependencies, restricted coroutine file I/O,
  startup of both application roles, outbox and scheduler effects, and retirement
  of the exact deployment using disposable test infrastructure.
- PHP syntax, Python compilation, shell syntax, workflow YAML parsing and command
  ordering checks passed. Independent code review found no outstanding defects.
  `actionlint` was unavailable; no hosted workflow result is claimed.

The change adds packaging tools and evidence. Full backend, frontend, Registry
and DSP suites were not rerun; their behavior is outside this change.
