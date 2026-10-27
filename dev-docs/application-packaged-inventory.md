# Application packaged inventory

Before reviewing an application image for redistribution, collect the files and
licensing evidence from the image you intend to distribute. The collector inspects
an exported, stopped container; it does not start the application or mount the
checkout over its files. Run it separately for `production` and `worker` so their
component coverage can be compared from observations.

## Build and collect locally

Use a clean checkout of the committed source, Docker with Buildx, Bash, PHP 8.5 or later
with Swoole, Composer, and enough temporary disk space for an exported image and its
extracted filesystem. Install the standalone tooling dependencies with
`composer --working-dir=scripts install --no-dev` before collecting an inventory.
Run these commands from the repository root:

```sh
test -z "$(git status --porcelain)"
BAANDER_INVENTORY_REVISION=$(git rev-parse HEAD)
BAANDER_INVENTORY_OUTPUT="var/test-runs/application-inventory-$BAANDER_INVENTORY_REVISION"

docker buildx build --target production --load \
  --build-arg BAANDER_SOURCE_REVISION="$BAANDER_INVENTORY_REVISION" \
  --tag "baander-inventory-production:$BAANDER_INVENTORY_REVISION" .

bash scripts/collect-packaged-inventory.sh \
  --image "baander-inventory-production:$BAANDER_INVENTORY_REVISION" \
  --target production --revision "$BAANDER_INVENTORY_REVISION" \
  --output "$BAANDER_INVENTORY_OUTPUT/production"

docker buildx build --target worker --load \
  --build-arg BAANDER_SOURCE_REVISION="$BAANDER_INVENTORY_REVISION" \
  --tag "baander-inventory-worker:$BAANDER_INVENTORY_REVISION" .

bash scripts/collect-packaged-inventory.sh \
  --image "baander-inventory-worker:$BAANDER_INVENTORY_REVISION" \
  --target worker --revision "$BAANDER_INVENTORY_REVISION" \
  --output "$BAANDER_INVENTORY_OUTPUT/worker"
```

The output lives under ignored `var/test-runs/`. Choose a fresh output directory
for each collection so files from an earlier run cannot be mistaken for current
evidence. The revision argument must match the image's observed
`org.opencontainers.image.revision` label. The worker inherits this label from
production. A clean checkout makes that label useful, but the label alone does not
prove that a build used clean source.

Each collector invocation resolves the supplied image to its immutable local image
ID before exporting it. It generates the bundle and verifies it against the same
export and inspected image identity. To repeat collection and verification, run
the same command against that retained image ID with a fresh output directory.
A rebuild may resolve different upstream artifacts; it is a new observation.

## Read the bundle

| Path | Purpose |
|---|---|
| `image-inspect.json` | Observed image ID, platform, revision label, and available repository digests. |
| `inventory.json` | Installed components, file ownership and hashes, retained evidence references, and review gaps. |
| `evidence/sha256/` | Retained notice and build-evidence bytes, deduplicated by content hash. |
| `checksums.json` | Hashes of the inventory, readable summary, and referenced retained evidence. |
| `SUMMARY.txt` | Component coverage and concrete review gaps for the observed image. |
| `collection.log` | Collector and verifier diagnostics, including failures. |

Start with `SUMMARY.txt`, then use `review_gaps` in `inventory.json` to locate the
component or file requiring review. Evidence entries connect the original image
path to its retained path and hash. Missing notices, unknown ownership, and gaps
in embedded-dependency provenance require investigation before redistribution.
Retain the bundle with the image identity when recording that review in
[`THIRD_PARTY.md`](../THIRD_PARTY.md).

Installed Composer metadata and Debian package state describe installed packages.
Copied repository sources, local packages, JavaScript manifests and lockfiles,
and public assets are recorded separately. A copied JavaScript lockfile does not
establish that its dependency set is installed. A filesystem census also cannot
establish the licensing of every dependency embedded in a native binary or
WebAssembly asset.

A successful command establishes integrity of the observed inventory and retained
evidence. It does not clear the image for redistribution. Review gaps remain
visible even when integrity verification passes. Collection or verification
failures return a nonzero status; keep the partial output and log to diagnose them.

## CI retention and publication

The quality gate collects the worker inventory immediately after its existing
worker build. Its bundle is retained under
`var/test-runs/application-inventory-worker/` in the `backend-verification` artifact.

The production job loads its built image locally and collects
`var/test-runs/application-inventory-production/` before publishing. Pull requests
use this same collection path. On master, integrity verification must pass before
the job pushes the built image's revision and `latest` tags. It does not rebuild
between collection and publication. The production bundle is retained in the
`application-packaged-inventory-production` artifact.

Both uploads run with `always()` and use the existing 14-day artifact retention,
so available diagnostics and partial bundles survive collection or verification
failures. After a successful revision-tag push, `published-image-inspect.json`
records the local image's repository digests; `push-revision.log` and
`push-latest.log` retain the registry publication receipts. These publication files
are supplemental receipts, outside the inventory checksum manifest. Compare their
image identity with `image-inspect.json` when following a bundle to the published
image. An upload with no output files does not establish a successful collection.
