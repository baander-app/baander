# Third-party licensing

The root Apache-2.0 license applies to Baander-authored code, with the
package-specific exceptions below. It does not replace licenses or copyright
notices belonging to bundled or installed dependencies.

- `packages/swoole-bundle` retains its MIT license and upstream copyright notices
  in `packages/swoole-bundle/LICENSE`.
- `packages/tsduck-php-ffi` retains its declared BSD-2-Clause license and Martin
  Christiansen’s copyright attribution in its [LICENSE](packages/tsduck-php-ffi/LICENSE).
  TSDuck binaries and libraries have their own distribution notices.
- `packages/codecs/baander-aac` is Apache-2.0. Its ISO/IEC 14496-3 tables
  (Huffman codebooks and scalefactor bands) were transcribed from FFmpeg's
  `libavcodec/aactab.c` (LGPL 2.1 or later) and verified against it; the
  encoder's allocation design follows the Fraunhofer FDK AAC encoder and
  3GPP TS 26.403 as published references without copying their code. The
  Node-API binding depends on `node-addon-api` (MIT) and `node-gyp` (MIT).
- Composer, Yarn, native codec/DSP toolchains, and registry dependencies retain
  their upstream licenses. Preserve their license files and notices in distributed
  artifacts, including source/binary bundles and container images.
- The web movie player bundles HLS.js 1.7.3. Its upstream attribution is retained
  in [hls.js-LICENSE.txt](public/licenses/hls.js-LICENSE.txt), alongside the complete
  [Apache-2.0 license](public/licenses/Apache-2.0.txt), in the public assets.

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
