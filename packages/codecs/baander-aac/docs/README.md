# baander-aac

A self-contained AAC-LC encoder and decoder in C++17 with a C API, a
command-line tool and a Node-API binding for the Electron app.

**Version:** 0.1.0
**License:** Apache-2.0 for Baander-authored code; see [Provenance](#provenance).
**Standard:** ISO/IEC 14496-3 (MPEG-4 Audio), AAC-LC, 1024-sample frames, ADTS framing.

## What it does

### Decoder

Decodes every AAC-LC tool of ISO/IEC 14496-3 subpart 4: long and short
windows with window grouping, sine and KBD window shapes, section and
scalefactor coding with all eleven spectral Huffman codebooks and escape
sequences, pulse data, TNS, M/S stereo, intensity stereo and perceptual
noise substitution (including correlated noise in M/S bands). SCE and CPE
elements are decoded; DSE, PCE and FIL elements are skipped. ADTS headers
set the sample rate and channel count of the stream; CRC-protected frames
are accepted (the CRC is not verified).

Against FFmpeg's decoder the output agrees to floating-point precision
(135 dB difference SNR) on FFmpeg-encoded streams across all sample rates
from 8 to 96 kHz, mono and stereo, 32 to 320 kbit/s and the `fast`
coder; see [reference-comparison.md](reference-comparison.md).

An HE-AAC stream (SBR, with or without PS) decodes as its AAC-LC core at the
core sample rate; `aac_decoder_get_sbr_ps` reports that SBR data was
present. SBR and PS are not decoded.

### Encoder

The encoder follows the structure of the Fraunhofer FDK encoder and
3GPP TS 26.403:

- block switching with one block of lookahead and FDK's window grouping
  per attack position; KBD windows for long frames, sine for short;
- a masking model with Bark-scale spreading, a PCM-resolution floor and
  pre-echo control;
- a bitrate-dependent lowpass (FDK's bandwidth table, full band from
  128 kbit/s per channel) and a per-band minimum SNR derived from the bits
  available per Bark, adapted for quiet bands and local peaks and valleys;
- TNS with FDK's analysis: lag-windowed, energy-normalized
  autocorrelation over quarters of the range, an upper and a lower filter
  on long windows (merged when they agree), one filter per short window
  with 3-bit coefficients, and filters shared between the channels of a
  pair when they nearly coincide;
- perceptual noise substitution at 28 to 48 kbit/s per channel in the
  constant-rate modes (FDK's AAC-LC tuning): bands above 4.1 kHz whose
  quarters carry similar power and whose lines are unpredictable are sent
  as a noise energy instead of coefficients;
- intensity stereo when the bitrate is below five times the lowpass
  frequency: regions of at least six bands where the channels correlate
  above 0.95 and are panned away from the middle carry the left channel
  and a level difference;
- per-band M/S stereo chosen by perceptual entropy;
- thresholds raised towards the bit budget in the fourth-root domain so
  that quiet bands become holes before loud bands lose precision, with
  hole-avoidance flags from spread energy and top-down band release when
  the minimum-SNR floors still overshoot;
- a bit reservoir driven by perceptual entropy with FDK's save/spend
  curves (CBR and ABR), constrained and true VBR by quality;
- scalefactor search against the resulting noise targets, scalefactor
  smoothing where it saves bits, and codebook sectioning by dynamic
  programming over all codebooks.

Only AAC-LC is encoded; `aac_encoder_create` returns NULL for the HE-AAC
object types.

## API

All functions are declared in `include/aac.h` with C linkage.

### Encoder

```c
AacEncoderHandle aac_encoder_create(int sample_rate, int channels, int bitrate,
                                    AacObjectType aot, AacRateControl rc_mode);
int aac_encoder_encode(AacEncoderHandle ctx, const float* pcm, int n_samples,
                       uint8_t* out, int out_size);
int aac_encoder_flush(AacEncoderHandle ctx, uint8_t* out, int out_size);
int aac_encoder_set_quality(AacEncoderHandle ctx, int quality);
int aac_encoder_frame_size(AacEncoderHandle ctx);
int aac_encoder_delay(AacEncoderHandle ctx);
void aac_encoder_destroy(AacEncoderHandle ctx);
```

- `sample_rate` is one of the twelve AAC rates (8000 to 96000 Hz);
  `channels` is 1 or 2; `aot` must be `AAC_AOT_LC`.
- `aac_encoder_encode` takes exactly `frame_size()` (1024) interleaved
  float samples per channel at full scale 1.0 and writes one ADTS frame.
- The first 2048 output samples of the decoded stream are silence
  (`aac_encoder_delay`): one block of lookahead for block switching plus
  the transform overlap.
- `aac_encoder_flush` drains the delay: it writes the two remaining ADTS
  frames back to back and returns their total length, then 0 until the
  next `aac_encoder_encode`.
- `quality` is 1..100 for `AAC_RC_CVBR` and `AAC_RC_TVBR`; 70 codes at the
  masking threshold, 100 nine dB below it. The bitrate still selects the
  lowpass in the VBR modes.

### Decoder

```c
AacDecoderHandle aac_decoder_create(int sample_rate, int channels);
int aac_decoder_decode(AacDecoderHandle ctx, const uint8_t* data, int size,
                       float* pcm, int pcm_size);
int aac_decoder_frame_size(AacDecoderHandle ctx);
int aac_decoder_sample_rate(AacDecoderHandle ctx);
int aac_decoder_channels(AacDecoderHandle ctx);
int aac_decoder_get_sbr_ps(AacDecoderHandle ctx, int* has_sbr, int* has_ps);
void aac_decoder_destroy(AacDecoderHandle ctx);
```

- `aac_decoder_decode` takes one ADTS frame (or one raw data block at the
  creation sample rate) and returns the samples per channel written to
  `pcm` (1024), or a negative `AacError`. `pcm_size` must hold a whole
  frame.
- The ADTS header overrides the creation sample rate;
  `aac_decoder_sample_rate` reports the stream's rate. `channels` is the
  output layout: a stereo stream through a mono decoder is downmixed, a
  mono stream through a stereo decoder is duplicated.

## Building

### Native

```sh
cd packages/codecs/baander-aac
mkdir -p cmake-build && cd cmake-build
cmake .. -DCMAKE_BUILD_TYPE=Release
cmake --build .
ctest
```

`build/` belongs to the Node-API binding (node-gyp); CMake builds live in
`cmake-build/`. The build produces `libbaander-aac.a`, the `baac` tool and
the tests. Options: `BAAC_AAC_BUILD_ENCODER`, `BAAC_AAC_BUILD_DECODER`,
`BAAC_AAC_BUILD_TESTS`, `BAAC_AAC_BUILD_TOOLS`, `BAAC_AAC_SHARED`.

SIMD: the vector operations dispatch at runtime to SSE2, AVX2 (x86) or
NEON (ARM); only `src/simd/avx2.cpp` is compiled with AVX2 flags, so the
library runs on CPUs without it.

### Command-line tool

```sh
baac enc <in.f32le> <out.aac> <rate> <channels> [bitrate] [cbr|abr|cvbr|tvbr] [quality]
baac dec <in.aac> <out.f32le> [rate] [channels]
```

Raw PCM is interleaved 32-bit float. With ffmpeg:

```sh
ffmpeg -i song.flac -f f32le -ac 2 -ar 44100 - | baac enc - song.aac 44100 2 128000
```

### Node-API binding (`@baander/aac-native`)

The package root is this directory (`package.json`, `binding.gyp`); the
entry is `node/index.js`, ESM only, for Node 26 and Electron. `npm install`
or `yarn install` builds the binding with node-gyp from the same sources
(SSE2 on x86, NEON on arm64). The Electron app depends on it as
`file:../../packages/codecs/baander-aac` and rebuilds it for the Electron
ABI with `yarn electron-rebuild`; electron-builder ships the entry, the
types and the compiled binding only.

```js
import { AacDecoder, AacEncoder } from '@baander/aac-native';

const encoder = new AacEncoder({ sampleRate: 44100, channels: 2, bitrate: 128000, mode: 'cbr' });
const stream = Buffer.concat([encoder.encode(pcm), encoder.flush()]);

const decoder = new AacDecoder({ sampleRate: 44100, channels: 2 });
const samples = decoder.decode(stream); // Float32Array, interleaved
```

Both classes accept input in arbitrary chunk sizes and keep partial frames
between calls. `npm test` runs the binding's smoke test. In the Electron
app the main-process `AacService` wraps the binding behind the
`window.electron.aac` bridge (one-shot `decode`/`encode` and chunked
decoder/encoder sessions).

## Testing

`ctest` runs eight suites: table consistency (prefix-free, complete
Huffman codebooks; scalefactor bands; Princen-Bradley windows), MDCT
against the direct definition and TDAC, bit reader/writer, ADTS and
Huffman coding, decoder compliance on hand-built and damaged streams,
encode/decode roundtrips at every sample rate and rate-control mode, rate
control, the coding tools (noise substitution keeps the level of the
substituted region, intensity stereo keeps the balance, TNS covers both
window types), and interoperability with FFmpeg. The interop test skips
when `ffmpeg` is not on PATH; otherwise it requires our decoder to match
FFmpeg's decode of FFmpeg-encoded streams, and FFmpeg to decode our
streams exactly as our decoder does, both at better than 90 dB difference
SNR; the streams of ours it checks use TNS, noise substitution and
intensity stereo.

Noise substitution never signals correlated noise (an M/S flag on a noise
band): FFmpeg's decoder draws independent noise regardless of the flag,
and its random sequence would then run ahead of a conforming decoder's.

## Project structure

```
include/      public aac.h and internal headers
src/          tables, FFT/MDCT, bitstream, decoder, psychoacoustics, encoder, coding tools, SIMD
tools/        baac command-line tool
tests/        CTest suites
node/         Node-API binding (addon.cc, ESM entry, types, smoke test)
docs/         this file and the reference comparison
```

## Provenance

The standard tables (spectral and scalefactor Huffman codebooks,
scalefactor band offsets) are ISO/IEC 14496-3 data, transcribed from
FFmpeg's `libavcodec/aactab.c` (LGPL 2.1+) and verified against it
entry by entry. The encoder's allocation strategy, bandwidth table, block
switching, TNS analysis, noise substitution tuning, intensity and M/S
decisions follow the published design of the Fraunhofer FDK AAC encoder
(AOSP `external/aac`, the `android17-release` state as mirrored in
`mstorsjo/fdk-aac`) and 3GPP TS 26.403 as references; no FDK code was
copied.
