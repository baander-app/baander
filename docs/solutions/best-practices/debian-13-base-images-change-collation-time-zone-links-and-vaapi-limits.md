---
title: Moving the images to Debian 13 changes collation, legacy time zone names and the VAAPI test frame
date: "2026-11-04"
category: best-practices
module: Container images, PostgreSQL database image and hardware encoder qualification
problem_type: best_practice
component: infrastructure
severity: high
applies_when:
  - Moving a Debian-based image (PHP application, worker, database or Node tooling) to a new Debian release
  - Starting a new database image on a PostgreSQL data volume created under an older glibc
  - A test or setting names a time zone through a root-level IANA link such as Zulu, UCT or Universal
  - Changing the frame size of the hardware encoder test encode, or qualifying a new GPU
  - Deciding how to treat a Mesa or DRM warning inside a worker container
symptoms:
  - "PostgreSQL reports a collation version mismatch on a data volume created by the Debian 12 database image"
  - "SET timezone = 'zulu' fails with: invalid value for parameter \"TimeZone\""
  - "hevc_vaapi rejects the startup test encode on an RDNA4 GPU with \"constraints: width 384-8192\""
  - "amdgpu logs os_same_file_description couldn't determine if two DRM fds reference the same file description"
resolution_type: dependency_update
tags: [debian-13, trixie, glibc, collation, tzdata, vaapi, seccomp, base-image]
---

# Moving the images to Debian 13 changes collation, legacy time zone names and the VAAPI test frame

## Context

The application's Debian-based images moved from Debian 12 (bookworm) to Debian 13
(trixie): the PHP ZTS application base (`php:8.5.12-zts-trixie`, `Dockerfile:16`),
the Node tooling stage (`node:24.22.2-trixie-slim`, `Dockerfile:643`), the database
image (`postgres:18-trixie` with the PGDG PgBouncer build for Debian 13,
`docker/postgres/Dockerfile:3`, `:9` and `:14`) and the static FFmpeg builder
(`docker/ffmpeg/Dockerfile:2` and `:193`). The relay images under `relay/docker/`
were not part of the move and stay on a pinned `debian:bookworm-slim`. The reason was the GPU: the configurable media
capacity plan records that the RX 9070 XT's VAAPI fails to initialize under Debian
12's Mesa 22.3.6, and the move was chosen over a Mesa backport on Debian 12. On
Debian 13 `vainfo` initializes the card with Mesa 25.0.7 and libva 2.22.0 (commit
`6e8fb2392`).

The package renames were the expected part and fail loudly at build time: the
64-bit `time_t` `t64` suffixes, ICU 76, `libavif16`, `libzip5`, ImageMagick 7 and
`libgdk-pixbuf-2.0-dev`. The move also brought FFmpeg 7.1, whose encoder and probe
changes are covered in
[the FFmpeg major-upgrade learning](ffmpeg-major-upgrades-break-shortest-and-guessed-frame-rates.md).
Four further changes did not show at build time. They show only against existing
data, in one integration test, on the GPU, or as a log line, and each has a
non-obvious right answer.

The commits cited here are local to `feat/configurable-media-capacity` and
unmerged as of 2026-11-04; their SHAs may change when the branch merges.

## Guidance

### 1. A glibc upgrade changes the database's collation

Debian 13 moves glibc from 2.36 to 2.41. The database image's PostgreSQL takes
its default collation from the C library, so indexes on text columns built under
2.36 can disagree with the sort order 2.41 produces. PostgreSQL records the
collation version in each database when the database is created. When the
recorded version differs from the one the C library now reports, PostgreSQL
warns about a collation version mismatch. It warns and keeps serving queries; no
statement fails. An index whose order no longer matches the collation can return wrong
results for lookups and range scans, and a unique index can admit duplicates.

The remedy is to rebuild the indexes (`REINDEX DATABASE`) and only then record the
new version (`ALTER DATABASE … REFRESH COLLATION VERSION`), in every database
of the volume that warns. Recording the version without the rebuild silences the
warning and leaves the stale indexes in place. Run both statements over a direct
connection as the database owner, not through the
[connection pooler](../../../CONCEPTS.md#connection-pooler). Both statements act
on the database as a whole and need the owner's rights, and the pooler serves the
application's roles in transaction mode (`docker/postgres/pooler/pgbouncer.ini:24`).
A fresh volume is initialized under 2.41 and needs nothing. The operator procedure,
including the managed-preset variant, is in
[Moving to the Debian 13 images](../../../docs-book/part-1-operator-guide/upgrading.md#moving-to-the-debian-13-images).

The warning and the procedure come from commit `0bfede0d8` and this branch's
analysis of the glibc change. The session that made the change did not capture
the warning text from an actual upgrade run. The owner-rights reason above is PostgreSQL's general
requirement for these statements, not something observed through the pooler here.

### 2. Root-level time zone links moved to `tzdata-legacy`

Debian 13 moved the root-level backward-compatibility links (`Zulu`, `UCT`,
`Universal`) out of `tzdata` into the `tzdata-legacy` package. The database image
builds PostgreSQL against the system zoneinfo, according to commit `77bf8dffe`, so
on the new image `SET timezone = 'zulu'` fails with
`invalid value for parameter "TimeZone": "zulu"` (the integration test's recorded
failure). `Etc/Zulu` stays in the main package, according to the same commit.

`DatabaseTimeZoneGuardTest::testStartupProceedsAgainstUtcAndItsAliases` set
`zulu` to prove the guard accepts a UTC alias, and it failed on Debian 13. Commit
`77bf8dffe` switched it to `etc/zulu` (`tests/Integration/DatabaseTimeZoneGuardTest.php:71`).
The guard itself still accepts the legacy names:
`DatabaseTimeZoneGuard::UTC_NAMES` lists `uct`, `universal` and `zulu` next to
their `etc/` forms (`src/Shared/Infrastructure/Doctrine/DatabaseTimeZoneGuard.php:25`).
That is deliberate. The guard compares the name PostgreSQL reports, and a server
with `tzdata-legacy` installed can still report a root-level name. Keep the list.
Tests should set only names the image ships.

### 3. RDNA4's `hevc_vaapi` refuses frames narrower than 384

The hardware encoder qualification at worker start runs a short test encode on
the configured device. It used to encode a 256x256 frame. On the RX 9070 XT with
Mesa radeonsi, `hevc_vaapi` refuses widths below 384 (`constraints: width
384-8192`), so every HEVC qualification on RDNA4 failed. The failure would read
as a broken GPU or device mapping. Commit `62287f0ef` changed the test frame to
the smallest tier the ladder encodes, 360p
(`src/Transcode/Infrastructure/FFmpeg/HardwareCapabilitiesProber.php:223-226`,
`QualityTier::p360()` is 640x360 in
`src/Transcode/Domain/ValueObject/QualityTier.php:24-36`). A frame size the
ladder really produces is one the encoder must accept anyway. An arbitrary small
frame tests a size the application never encodes.

### 4. The `kcmp` warning under Docker's default seccomp profile is harmless

Inside the worker container, Mesa's amdgpu driver logs `amdgpu:
os_same_file_description couldn't determine if two DRM fds reference the same
file description` once per FFmpeg process. Docker's default seccomp profile
refuses the `kcmp` system call unless the container holds `CAP_SYS_PTRACE`, so
Mesa cannot tell whether two descriptors share one opened device and treats them
as distinct. That matters only when Mesa passes buffers between those descriptors
as kernel buffer handles. The operator guide states that FFmpeg's decode, scale and
encode path does not do this. That statement is inferred from an output comparison,
which commit `9765de531` records: 20-second `h264_vaapi` and `hevc_vaapi` encodes
on the RX 9070 XT produced bit-identical output (decoded MD5) with the default
profile and with `seccomp=unconfined`. The default profile stays, and no
capability is added; see
[Hardware Acceleration](../../../docs-book/part-1-operator-guide/transcoding.md#hardware-acceleration).

## Why This Matters

Each of these looks like something else. The collation change produces only a
warning while stale indexes keep answering queries, and the obvious "fix",
refreshing the version alone, hides the problem. The time zone failure looks like
a guard regression, and the tempting response is to drop the legacy names from
the guard, which would refuse servers that report them. The 384-pixel minimum
looks like a missing driver or a wrong device. The `kcmp` line invites widening the
container's seccomp profile or granting `CAP_SYS_PTRACE` to a container that
decodes library media with FFmpeg. Either loosens the container's isolation, and
the comparison showed no change in output to gain from it.

## When to Apply

- Before moving any image to a new Debian release, or to another distribution
  with a different glibc or tzdata layout.
- When a PostgreSQL volume created by an older image starts under a newer one.
- When a test, fixture or setting names a time zone; prefer `UTC` or an `Etc/` name.
- When qualifying a new GPU or encoder, or changing the test-encode frame.
- When a Mesa, DRM or seccomp warning appears in worker logs after an image change.

## Examples

The time zone test, before and after commit `77bf8dffe`:

```php
// Fails on Debian 13: the root-level Zulu link is in tzdata-legacy.
foreach (['UTC', 'Etc/UTC', 'zulu'] as $zone) {

// Passes: Etc/Zulu ships in the main tzdata package.
foreach (['UTC', 'Etc/UTC', 'etc/zulu'] as $zone) {
```

The test encode, before and after commit `62287f0ef`:

```php
// Before: hevc_vaapi on RDNA4 refuses widths below 384.
'nullsrc=s=256x256:d=0.1'

// After: the smallest tier the ladder encodes.
$tier = QualityTier::p360();
sprintf('nullsrc=s=%dx%d:d=0.1', $tier->width, $tier->height)
```

## Related

- [End movie audio at a stated video end and read the average frame rate across FFmpeg upgrades](ffmpeg-major-upgrades-break-shortest-and-guessed-frame-rates.md):
  the FFmpeg 5.1 to 7.1 changes from the same image move.
- [Moving to the Debian 13 images](../../../docs-book/part-1-operator-guide/upgrading.md#moving-to-the-debian-13-images):
  the operator procedure for existing data volumes.
- [Hardware Acceleration](../../../docs-book/part-1-operator-guide/transcoding.md#hardware-acceleration):
  hardware encoder configuration, the test encode and the seccomp finding.
- The configurable media capacity plan, retired and readable with
  `git show b9a67726f:docs/plans/2026-11-03-1905-feat-configurable-media-capacity-plan.md`: why the
  images moved (Mesa 22.3.6 cannot initialize RDNA4 VAAPI).
