---
title: Preserve SDR color when a movie uses BT.2020 primaries
date: 2026-10-24
status: reviewed
execution: code
---

# Preserve SDR color when a movie uses BT.2020 primaries

## Consumer and reproduced failure

The active movie worker probes source color metadata, selects a tone-map method
and builds the FFmpeg filter chain through SegmentEncoder. ColorSpace currently
classifies BT.2020 primaries alone as HDR. A source with BT.2020 primaries, BT.709
SDR transfer and BT.2020 nonconstant-luminance matrix therefore receives Hable
tone mapping. A native reproduction using the actual command builder encodes
neutral gray with luma 90 instead of the source/reference value 126. The failure
also reproduces in the CI image with FFmpeg 5.1.9 and PHP 8.5.2.

Color gamut and dynamic range are separate properties. The
[ITU operational guidance](https://www.itu.int/dms_pub/itu-r/opb/rep/R-REP-BT.2408-8-2024-PDF-E.pdf)
explicitly distinguishes SDR/2020 from HLG and PQ signals. Preserve the observed
source values and classify only the recognized HDR transfer characteristics;
primaries must not independently activate tone mapping.

This phase addresses that active input failure. ABR switching qualification,
Safari/native HLS, formal CMAF conformance, DASH subtitles and the unused native
reader experiments remain separate work. The user's original videos are read-only
and do not exercise this color combination; use a tiny deterministic generated
fixture for the regression.

## Color contract

Recognized PQ and HLG transfer names and their existing aliases remain HDR,
regardless of whether primaries are BT.2020, BT.709 or absent. BT.2020 with an SDR
transfer such as BT.709 or BT.2020-10/-12 must not receive HDR tone mapping.
Missing, empty or unknown transfer metadata must not imply HDR merely because
BT.2020 primaries are present. Preserve the existing probe fallback and
serialization behavior; do not add new normalization or inference rules.

Keep SDR on its existing processing path and preserve its source color metadata.
Do not add gamut conversion or retag wide-gamut SDR as BT.709. Existing software
PQ/HLG tone mapping continues to target BT.709. This verifies the selected
classification and software conversion path, not general HDR quality, mastering
luminance accuracy, Dolby Vision metadata or physical hardware acceleration.

The production change belongs in the shared ColorSpace predicate used by
VideoProbeResult, VideoProcessingRules and VideoFilterBuilder. Keep their
interfaces, codecs, segment timing, audio behavior and backend schemas unchanged.
No migration or local-data reset is needed.

## Ownership and workflow

Use the previously authorized equivalent planning, implementation and independent
review workflow because the compound-engineering skills remain unavailable.
An independent reviewer must approve this plan before implementation. One worker
owns the color predicate and focused domain/filter tests. A second owns the native
SegmentEncoder media regression. The lead owns integration, authoritative gates,
documentation and local commits. Workers do not stage or commit, and must preserve
one another's edits. Review simplification and the final diff before acceptance.
No merge or push is included.

## Verification

First add failing regressions against the unchanged predicate. Cover recognized
PQ/HLG names, SDR transfers with BT.2020 primaries, missing/unknown metadata,
probe reconstruction and serialized round trips. Exercise tone-map selection and
filter building through their existing consumers. A filter-string assertion alone
is not acceptance evidence.

The native regression generates a small lossless source with neutral gray/ramp and
chroma patches, probes the actual file, builds its filters and FFmpeg command,
reads the published presentation and independently decodes the produced fragment.
Compare interior Y/U/V samples with independently decoded source values in the
same pixel format and range. Use a small measured codec tolerance, with assertions
that reject the reproduced 126-to-90 luma shift. Preserve and assert BT.2020 SDR
primaries, transfer and matrix in produced output. Cover software H.264 and HEVC
and a nonzero source start without creating an unrelated format matrix.

Also encode small PQ and HLG fixtures through the actual software path. Require
HDR classification, selected tone mapping, successful fragment decode and BT.709
output metadata. Do not treat gray or chroma code-value checks as display-gamut
certification or a complete tone-mapping reference-vector suite. Retain source,
commands, native probes and measured samples for the acceptance checkpoint.

Run affected domain, filter, command-snapshot and native media tests in the CI
runtime, followed by full PHPStan and Deptrac. Preserve all existing tests in the
selected suites and record exact counts. The native proof covers probe-to-encoded
fragment behavior; frontend code, HTTP delivery and manifests are unchanged, so
rerun browser lifecycles only if implementation or evidence introduces a concern
there. Keep final coverage boundaries explicit rather than treating the previous
browser receipts as new acceptance for this change.

Update the testing guide and ROADMAP.md with the behavior, failed/passing native
measurements, suite counts and remaining qualifications. Apply prose-fix to changed
documentation, preserve historical entries and commit the verified phase locally.

Independent review approved this plan before implementation. Absent primaries
use the existing probe fallback, and native comparisons must use the same pixel
format and range with samples inside patch boundaries.
