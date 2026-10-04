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

This records repository licensing boundaries; it is not a completed inventory of
all transitive dependencies. Release qualification must generate and review that
inventory for the actual packaged artifacts.
