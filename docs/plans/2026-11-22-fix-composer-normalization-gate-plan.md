---
title: Restore the Composer normalization gate
date: 2026-11-22
type: fix
status: completed
---

# Restore the Composer normalization gate

## Goal Capsule

Let `make ci` pass Composer normalization so it can start PHPStan, Deptrac, and
the backend suites. The check failed during
[Person profiles verification](../../ROADMAP.md#person-profiles-and-cross-media-credits).

## Product Contract

- R1. `composer validate --no-check-version` and `composer normalize --dry-run`
  pass in the application CI image.
- R2. Preserve the manifest values and the lockfile data. The lockfile content
  hash may change when the normalizer reorders dependency requirements.

## Planning Contract

The [Makefile](../../Makefile) runs the normalization dry run before static
analysis. On `8692af0bb`, Composer 2.11.1 and `ergebnis/composer-normalize`
2.56.0 returned exit code 1 in the CI image. The diff reorders root package
requirements and autoload maps. Generate the canonical order using those
versions. Keep the existing CI command as the regression check.

## Implementation Units

### U1. Normalize the root manifest

Run `composer normalize --no-update-lock` in the CI image with disposable
copies of the manifest and lockfile. This skips the normalizer's call to
`composer update --lock`. Compute the content hash with the image's Composer
and refresh that field if it changes. Apply the files after comparing their
parsed contents against the base.

### U2. Record the CI checkpoint

Run `make ci` in the application CI image. Record the gates reached and any
remaining failure in [ROADMAP.md](../../ROADMAP.md). Keep the earlier checkpoint
for Person profiles as historical evidence.

## Verification Contract

- AE1. The parsed manifest values match the base, and all lockfile data besides
  the content hash matches. `git diff --check` passes.
- AE2. The exact Composer validation and normalization commands in `make ci`
  pass in the CI image. The roadmap records the outcome of the later gates.
- AE3. Run the local mode of the [PHP dependency checker](../../docs-book/part-2-developer-guide/getting-started/php-dependencies.md#repeatable-checks)
  against the normalized checkout and report its result.
