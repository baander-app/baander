# Application image inventory review

The initial inventory covers the root Dockerfile's `production` and `worker` targets on
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

Three supplemental notices were included in the measured source tree and images:

- [PIE 1.5.2](pie-1.5.2/LICENSE.md), with its pinned
  [upstream source record](pie-1.5.2/source.json).
- [cweagans/composer-patches](composer-notices/cweagans/composer-patches/LICENSE.md)
  and [hidehalo/nanoid-php](composer-notices/hidehalo/nanoid-php/LICENSE.md),
  retrieved at the installed source revisions recorded in the
  [Composer notice source record](composer-notices/source.json).

The collector retained these notices and source records. Its raw observations
still report their absence from the original PHAR or installed package directory;
the supplemental files provide that notice evidence for the reviewed versions.
They do not establish coverage for other versions or embedded dependencies.

### OpenTelemetry supplemental evidence

The source tree now also retains licenses for the six OpenTelemetry packages
listed in the [Composer notice source record](composer-notices/source.json).
For each installed split-repository revision, its root Git tree exactly matches a
subtree in a pinned `open-telemetry/opentelemetry-php` monorepo revision. The record
names both tree identities, the monorepo revision and subtree path, and the upstream
license URL, Git blob and SHA-256. This proves content correspondence without
claiming a unique original split commit in the monorepo.

All six corresponding package manifests declare Apache-2.0. Each complete
monorepo tree contains the same root `LICENSE` and no separately named `NOTICE`.
The six retained license files preserve those upstream bytes. This resolves
their supplemental top-level license evidence; embedded dependency obligations
remain outside this check.

On 2026-10-20, direct checks verified the six subtree mappings and all eight
recovered Composer packages' notice hashes and installed source references.
A disposable Docker context export using the repository's `.dockerignore`
preserved all nine notice/provenance paths. The existing collector then generated
and verified a fixture bundle retaining every path byte-for-byte, with its
`not-qualified` outcome unchanged. These checks took 0.70 seconds; logs and upstream
responses are under `var/test-runs/application-package-notices-20261020/`.
No product code or tests changed, and no product suites were rerun.

This follow-up did not rebuild or collect the application images. The image IDs,
counts and raw observations above still describe the earlier snapshot; the six
new licenses are supplemental source evidence, not measured contents of those
images.

### Debian common-license references

The [Debian reference record](debian-notices/source.json) resolves six missing
paths using the explicit version text in the retained copyright files. Both
measured image bundles already contain the corresponding complete license bodies
under these names:

| Referring packages | Missing name | Retained name |
|---|---|---|
| gzip | `GFDL-3` | `GFDL-1.3` |
| libpango-1.0-0, libpangocairo-1.0-0, libpangoft2-1.0-0 | `GPL-2.0` | `GPL-2` |
| libdecor-0-0, libdecor-0-plugin-1-cairo | `GPL-3.0` | `GPL-3` |

All paths are under `/usr/share/common-licenses/`. The record preserves each
package version, copyright excerpt and hash, license path and hash, and both
image identities. Three unchanged license bodies are retained alongside it.
The correspondence follows the grants, including their “or later” terms, rather
than filename similarity. The gzip GFDL grant covers `doc/* NEWS README` with no
invariant sections or cover texts. The libdecor GPL grant covers
`debian/tests/run-with-display`. Pango's reference occurs in a license-definition
stanza; it does not classify the Pango binaries as GPL.

On 2026-10-20, checks matched all six scoped references and three complete bodies
against both image bundles. A disposable Docker context export preserved all four
new files, and fixture collection and verification retained their exact bytes.
These checks took 1.02 seconds; logs are under
`var/test-runs/debian-license-references-20261020/`. No application code or tests
changed, and no product suites or application image builds ran.
The six raw missing-reference observations remain historical evidence; these
supplemental source records do not change image measurements or qualify a release.

## Runtime images without Composer install caches

On 2026-10-20, both targets were rebuilt from committed source
`8130c214fe0ba715d7bcfb0b2295c1e84f84e94d`, using an extracted directory context
with `.dockerignore` applied. The production dependency step now deletes
`/home/www-data/.cache/composer` after autoloader generation in the same layer.
The worker inherits that result. These are the new image identities:

```text
production sha256:f9c7b1819f597224670076ed07f2417b0fc6f286cf5799db6988f73162c4116f
worker     sha256:dc2e24871e4084c6e134c30d906d8dc9d13f533744757e640a1c0e4118010398
```

Both inventory generation and verification passed. Their observed contents are
identical, including 636 Debian packages, 152 Composer entries, 14 native/tool
components, 80 native-owned entries and 12 copied manifests. Installed package
records and native artifact hashes also match the initial inventory exactly.

| Observation, per image | Initial inventory | New inventory |
|---|---:|---:|
| Files and symlinks | 52,278 | 52,117 |
| Raw review observations | 5,501 | 5,328 |
| Retained evidence paths | 2,053 | 2,063 |
| Unique retained evidence blobs | 1,405 | 1,406 |

The removed cache contained 173 files totalling 15,057,283 bytes, including 148
downloaded archives. No cache path or associated review observation remains.
The comparison also accounts for 12 added notice/review files and five changed
repository files, all matching the committed source bytes. Five additional file
differences are confined to `vendor/upscale/swoole-reflection/.git`: index
checkout timestamps and device/inode fields, plus timestamps in four reflogs.
All 13 tracked index entries and their object IDs match; these metadata changes
do not indicate dependency source drift.

The existing worker image runner rejected the retained old image with the new
cache-absence assertion. The rebuilt worker passed that assertion, platform and
restricted file-I/O checks, and the isolated startup, outbox, scheduler and
deployment-retirement smoke in 37.54 seconds. Production and worker builds took
21.30 and 1.88 seconds; inventory collection and verification took 52.81 and
53.20 seconds. Shell syntax and `git diff --check` passed. No new test suite or
broad application suite ran.

Full bundles, build and verification logs, `comparison.json`, and the five-file
Git metadata proof are under `var/test-runs/production-composer-cache-20261020/`.
The inventory SHA-256 values are:

```text
production d03269b4d74d2af64c5ebc165f2ebe57a8471cea7e1a71c6ecbaf9326463ba2a
worker     6885cab08815ebde4fa622de16ab8fea548f54a5fcbd9e46ca8041cc5d95a20a
```

The earlier machine-readable review record remains historical evidence. This
documentation follows the new measured source snapshot; both new inventories
still report `not-qualified`.

## Remaining redistribution work

The remaining gate concerns these observed contents:

- **Package notices:** the local `tsduck/php` package declares BSD-2-Clause without
  a retained top-level notice, copyright attribution or established upstream
  source. Its package tree is unchanged from the initial repository commit.
  The Composer notice record pins that tree and manifest; repository ownership
  and the native TSDuck license do not establish the PHP bindings' notice.
- **Debian evidence:** 149 retained copyright files have no machine-readable
  license declaration. Their text still needs review; resolving the six
  common-license references above does not settle those declarations or
  package-wide redistribution obligations.
- **Embedded dependencies:** PHP, liburing, eight PECL extensions, Swoole, TSDuck,
  Composer, and PIE have retained source/tool evidence and bound output hashes.
  Their embedded dependency and distribution obligations remain unreviewed.
  A top-level notice alone does not settle those obligations.
- **Copied assets and sources:** review provenance for the actual copied
  WebAssembly files, archives, native/vendor sources, and source manifests.
  JavaScript manifests are source requirements, not proof of installed npm
  dependencies. This inventory does not select new DSP algorithm qualification.
- **Unclassified inherited files:** distinguish generated OS metadata and caches
  from distributed dependency material. Remaining observations include dpkg state
  files, generated MIME data, Python bytecode and PHP/PEAR files. The new images
  exclude Composer's install cache. A raw gap count is not a count of missing
  licenses.

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
