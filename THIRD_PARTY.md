# Third-party licensing

The root Apache-2.0 license applies to Baander-authored code. It does not replace
licenses or copyright notices belonging to bundled or installed dependencies.

- `packages/swoole-bundle` retains its MIT license and upstream copyright notices
  in `packages/swoole-bundle/LICENSE`.
- `packages/tsduck-php-ffi` retains its declared BSD-2-Clause license. TSDuck binaries
  and libraries have their own distribution notices.
- Composer, Yarn, native codec/DSP toolchains, and registry dependencies retain
  their upstream licenses. Preserve their license files and notices in distributed
  artifacts, including source/binary bundles and container images.

The [application inventory procedure](dev-docs/application-packaged-inventory.md)
collects installed Debian and Composer packages, native build evidence, copied
repository material, and file hashes from the actual `production` and `worker`
images. The [application review record](third_party/inventory/application-review.md)
records observed coverage and remaining evidence gaps. The Registry keeps its
separate [packaged inventory](relay/README.md#release-acceptance).

Inventory integrity and redistribution qualification are separate checks.
Retained notices and declared licenses are evidence for review; a successful
collector run does not resolve missing provenance or license obligations.
Release qualification must review the bundle for the exact distributed artifact
and resolve its remaining blockers.
