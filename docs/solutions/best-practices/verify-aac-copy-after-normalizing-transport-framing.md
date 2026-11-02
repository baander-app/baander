---
title: Verify AAC stream copy after normalizing transport framing
date: "2026-11-02"
category: best-practices
module: Transcode media verification
problem_type: best_practice
component: testing_framework
severity: medium
applies_when:
  - Verifying that AAC repackaging preserves encoded audio without re-encoding
  - Investigating different packet hashes after copying ADTS AAC into MP4
tags: [aac, adts, mp4, ffmpeg, stream-copy, verification]
---

# Verify AAC stream copy after normalizing transport framing

## Context

An isolated copy experiment produced 816 packets in both an ADTS AAC source and
its MP4 output, but their packet hashes differed. Comparing those hashes directly
would have rejected a valid copy operation. Copying the MP4 audio back to ADTS
restored every packet hash and produced a byte-identical source file.

This was a media-format experiment for household playback work. It did not
qualify Bånder's copy execution, HTTP delivery, playback timing or memory use.

## Guidance

Compare encoded audio in the same transport representation. Keep the selected
stream, codec configuration, packet count and packet order explicit. For a
controlled ADTS fixture, copy the MP4 result back to ADTS and compare that result
with the original. A different raw hash across the container change is not, by
itself, evidence of re-encoding or lost audio.

If a fixture cannot reproduce identical transport headers, compare the AAC
access-unit payloads after parsing the framing, together with the codec
configuration. Do not weaken the comparison to packet count alone.

Retain the command arguments and immutable image identity with the result.
Generate encoded fixtures before any measured no-re-encoding workload. Application
integration tests must separately establish ownership, publication, timing and
playability; a successful packaging experiment does not establish those contracts.

## Why This Matters

FFmpeg's `aac_adtstoasc` bitstream filter removes ADTS headers and creates an
AudioSpecificConfig. FFmpeg automatically inserts it for MOV/MP4 outputs. That
changes the packet representation without decoding the audio. See the
[official bitstream-filter documentation](https://ffmpeg.org/ffmpeg-bitstream-filters.html#aac_005fadtstoasc).

The experiment also reported `format.duration` as 17.170614 seconds for the ADTS
source and 17.408000 seconds for MP4. The byte-identical round trip shows why that
duration difference alone cannot establish a change to the encoded audio.
Accurate playback duration needs its own timing check.

## When to Apply

Use this method when qualifying AAC container-copy output or diagnosing a failed
media-integrity assertion. It does not replace checks for a selected alternate
stream, changed codec configuration or missing packets.

## Examples

The verified fixture was a 440 Hz sine wave sampled at 48 kHz, generated with a
17.37-second limit. The probe selected `baander-ci:php-refresh`; its execution-time
image digest was not retained. At documentation time, that tag resolved to
`sha256:b8a4f6b13ac2ed5934605a3574e4d6d56213d5dab3153c4c208a91c47491ce7e`.
The following commands reproduce the operation in a disposable directory with
FFmpeg; the first command deliberately encodes the test input.

```bash
ffmpeg -hide_banner -loglevel error \
  -f lavfi -i sine=frequency=440:sample_rate=48000 \
  -t 17.37 -c:a aac source.aac
ffmpeg -hide_banner -loglevel error -i source.aac \
  -map 0:0 -c:a copy -vn -sn -dn -movflags +faststart -f mp4 copy.m4a
ffmpeg -hide_banner -loglevel error -i copy.m4a \
  -map 0:0 -c:a copy -f adts roundtrip.aac
cmp source.aac roundtrip.aac
```

For this fixture, `cmp` succeeds. Both ADTS files have SHA-256
`448264a087810c4572bf415aa1089409e1ed2d22a82867f3112d498f169d81cc`.
All three files contain 816 packets; the source and round-trip packet hashes
match in order. These are recorded fixture results, not universal counts or a
requirement that arbitrary ADTS files reproduce identical headers.

## Related

- [AAC transport vocabulary](../../../CONCEPTS.md#aac-transport)
- [Backend and media verification](../../../docs-book/part-2-developer-guide/testing.md)
