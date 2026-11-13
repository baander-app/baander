# Fuzz harnesses

Seven harnesses drive the codec with mutated input under the address and
undefined-behaviour sanitizers. Each defines `LLVMFuzzerTestOneInput`, so
the same source runs under libFuzzer or under the mutation driver of
`fuzz_driver.cpp`.

| Harness | What it drives |
| --- | --- |
| `fuzz_decoder` | The public decoder over ADTS, LOAS, LATM and raw access units with an AudioSpecificConfig, through the FIFO or the one-shot call: every object type the decoder has, concealment off, noise or interpolation, downmixes, the limiter, MPEG-4 and MPEG-D DRC, loudness normalisation, 960-sample frames, ELD downscaling and resets |
| `fuzz_asc` | The AudioSpecificConfig parser over bytes and over a signalled bit length (`ascLen`), the StreamMuxConfig reader and writer, the writer's round trip, and `aac_decoder_set_config` with one access unit |
| `fuzz_encoder` | The encoder with options from the first 13 bytes (profile, rate, channels, bitrate, rate control, quality, frame length, transport, signalling, 7.1 mode, LD-SBR, afterburner, CRC, MPEG-2, USAC options, DRC metadata, the ELD extension payload) and the rest as 16-bit PCM; encode, flush, then our decoder on the result, which has to decode it without an error and with finite output |
| `fuzz_unidrc` | The `uniDrcConfig()`, `loudnessInfoSet()` and `uniDrcGain()` parsers, and the whole MPEG-D DRC module with the player's request; the gains and the output stay finite |
| `fuzz_mps` | The MPEG Surround streams (5151, 5152 and 525 trees, the in-band and object type 30 forms, ELD v2) through the public decoder, one LOAS frame at a time or through the FIFO |
| `fuzz_flac_decoder` | The FLAC decoder of the lossless mode through its FIFO in pieces of every size: the marker and the metadata blocks, tags and pictures kept under each limit or skipped, a STREAMINFO given out of band, ID3 tags around the stream, frames with their checksums verified or ignored, a seek reset, a reset and a second pass, with and without the end of the input declared |
| `fuzz_flac_encoder` | The FLAC encoder with the level, channel count, bit depth, sample rate, seek table, padding, tags and pictures from the first bytes and the rest as PCM; encode, finish, the final header or none, then our decoder on the result, which has to return the samples bit for bit, the tags and pictures as given, and the input's total and MD5 |

Each harness source documents its input layout: the option bytes come
first, then the payload. A harness aborts when a property fails: a
return outside the documented codes, output that is not finite, more
samples than `AAC_DECODER_MAX_OUTPUT`, or an encoder stream that our
decoder refuses. The FLAC harnesses add their own: a frame larger than
the format allows or than the buffer it was decoded into, more tags and
pictures kept than the caller's limit, a decoder that neither takes
input nor produces output, and decoded samples, tags or pictures that
differ from what the encoder was given.

A mutated FLAC frame almost never keeps its CRC-8 and CRC-16, so a
decoder that verifies them would reject every mutation before the code
behind the checksums ran. `fuzz_flac_decoder` therefore switches the
verification off for most inputs through the decoder's internal
interface (`flac_decoder_ignore_checksums`), as RFC 9639 section 11
advises for fuzz testing; one bit of its options switches it back on,
so that the path a caller uses is covered as well. The public API has
no such switch.

## Building

```sh
cmake -S . -B cmake-build-fuzz -DCMAKE_BUILD_TYPE=Release -DBAAC_FUZZ=ON
cmake --build cmake-build-fuzz -j8
```

`BAAC_FUZZ` instruments the whole tree (library, tests and harnesses)
with `-fsanitize=address,undefined`. When the compiler can link
`-fsanitize=fuzzer` (clang with its compiler-rt runtimes), the harnesses
run under libFuzzer; otherwise they link the mutation driver. A compiler
that cannot link the sanitizers is refused at configure time. Without
`BAAC_FUZZ` the harnesses are still built, without sanitizers, so that
the smoke test runs in every build.

## Seeds

```sh
cmake-build-fuzz/tools/fuzz/fuzz_seeds tests/data corpus
```

writes `corpus/decoder`, `corpus/asc`, `corpus/encoder`, `corpus/unidrc`,
`corpus/mps`, `corpus/flac_decoder` and `corpus/flac_encoder`, about 800
files. The seeds are derived, not committed. They come from three
sources:

- Every committed stream under `tests/data`: its first frames whole,
  chunked and truncated, the first frame alone, its access units with
  the configuration, its configuration in each parser mode, and for the
  USAC streams the DRC configuration with the gain payload of each frame.
- Encoder inputs for every profile and transport, including rates above
  96 kHz and below 8 kHz, zero channels and an invalid rate control mode.
- Hand-built inputs at the bounds a decoder has to enforce:
  - Huffman escape prefixes of 9, 13 and 24 ones.
  - ER AAC-LC with the reordered spectral data's lengths at their
    maxima (HCR).
  - LATM `ascLen` larger than, smaller than and equal to zero against
    the config.
  - A program config element with every element count at its maximum.
  - Sampling frequencies of 192 kHz, 96001 Hz and 2^24 - 1 in the
    AAC-LC, HE-AAC, USAC and ELD configs.
  - ELD extensions and a surround extension with escaped lengths beyond
    the config.
  - USAC extension configs and configuration extensions of escaped
    lengths.
  - A config with a 255-byte PCE comment, larger than any the codec
    writes.
  - ADTS frames with several raw data blocks and CRCs.
  - uniDrcConfig counts beyond the decoder's limits.
  - FLAC frames of the largest block size (65535 samples of eight
    channels at 32 bits), the highest partition order (15), a predictor
    of order 32 with the widest coefficients, and a 33-bit side channel
    at both ends of its range in each stereo form.
  - FLAC metadata: a block longer than the input, tag blocks with a
    vendor length, a field count and a field length past the block, and
    picture blocks with a media type, description and data length past
    the block.
  - ID3v2 tags in front of a FLAC stream, one with a length past the
    input, and an ID3v1 tag behind the last frame.

The FLAC seeds also include every committed stream under
`tests/data/flac` (whole, cut short, in small pieces, and as frames with
their STREAMINFO out of band), streams the library's encoder writes with
tags and a picture, and encoder inputs for every level and bit depth
with tags and pictures the format takes and each kind it refuses.

## Running

With the mutation driver:

```sh
fuzz_decoder --seconds 600 --seed 1 --artifacts findings corpus/decoder
fuzz_decoder findings/crash-fuzz_decoder-1234.bin      # replays one input
```

The driver replays every seed, then mutates them until the time or run
budget is spent. The mutations are bit flips, byte writes, arithmetic,
interesting integers, insertions, deletions, duplications, truncation,
and splices between two seeds. An input that faults, trips a sanitizer,
fails a property or runs over `--timeout` seconds is written to the
artifact directory. A run is deterministic for a seed and a corpus.

The driver makes every sanitizer report fatal and aborting
(`__asan_default_options`, `__ubsan_default_options`), so an
undefined-behaviour report saves its input like a fault does.
`ASAN_OPTIONS` and `UBSAN_OPTIONS` override this; a UBSan suppressions
file (`UBSAN_OPTIONS=suppressions=FILE` with lines such as
`shift-base:mps_frame.cpp`) lets a long run continue past a known report.

With libFuzzer, the usual options apply:
`fuzz_decoder -max_total_time=600 -artifact_prefix=findings/ corpus/decoder`.

`ctest` runs `test_fuzz_smoke`. It generates the corpus into a temporary
directory and runs the seven harnesses side by side for 20 seconds each;
`BAAC_FUZZ_SMOKE_SECONDS` changes the budget. On a failure it keeps the
directory with the logs and the reproducer.
