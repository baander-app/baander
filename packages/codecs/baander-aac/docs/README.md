# baander-aac

A self-contained AAC encoder/decoder library written in C++17/C11. Supports AAC-LC, HE-AAC v1 (SBR), and HE-AAC v2 (SBR + Parametric Stereo) with runtime SIMD dispatch for x86, ARM, and WebAssembly.

**Version:** 0.1.0
**License:** Proprietary
**Standards:** ISO 14496-3 (MPEG-4 Audio)

---

## Table of Contents

- [Features](#features)
- [Architecture](#architecture)
- [Building](#building)
  - [Native (Linux / macOS / Windows)](#native-linux--macos--windows)
  - [WASM (Web)](#wasm-web)
  - [Cross-compilation (iOS / Android)](#cross-compilation-ios--android)
  - [Build Options](#build-options)
- [Native API Reference](#native-api-reference)
  - [Encoder](#encoder)
  - [Decoder](#decoder)
  - [CPU Feature Detection](#cpu-feature-detection)
  - [DSP Dispatch](#dsp-dispatch)
- [WASM API Reference](#wasm-api-reference)
- [Usage Examples](#usage-examples)
  - [Encoding (Native)](#encoding-native)
  - [Decoding (Native)](#decoding-native)
  - [Decoding (WASM/Browser)](#decoding-wasmbrowser)
- [Audio Object Types](#audio-object-types)
- [Rate Control Modes](#rate-control-modes)
- [SIMD Backends](#simd-backends)
- [Testing](#testing)
- [Project Structure](#project-structure)
- [Integration with Baander](#integration-with-baander)

---

## Features

- **Three AAC profiles:** AAC-LC (Low Complexity), HE-AAC v1 (Spectral Band Replication), HE-AAC v2 (SBR + Parametric Stereo)
- **Full encoder/decoder pipeline** — independent encoder and decoder builds via CMake options
- **Runtime SIMD dispatch** — SSE2, AVX2+FMA3+BMI2 (x86), NEON (ARM), SIMD128 (WASM)
- **WASM decoder** — decoder-only WebAssembly build for browser-based playback
- **Zero external dependencies** — no libfdk, no FFmpeg linkage; all tables and DSP are self-contained
- **Pure C API** — `extern "C"` linkage with opaque handles, callable from any language
- **ISO 14496-3 compliant** — Huffman codebooks, scalefactor bands, ADTS framing, TNS, PNS, M/S stereo

---

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                      Public C API (aac.h)                    │
│              Encoder Handle / Decoder Handle                 │
├─────────────────────────────────────────────────────────────┤
│  api.cpp ── glue layer bridging C API → internal C++ types  │
├──────────┬──────────────────┬───────────────────────────────┤
│ Encoder  │     Decoder      │          Shared               │
├──────────┼──────────────────┼───────────────────────────────┤
│ encoder  │ decoder          │ fft      │ bitstream          │
│ psycho   │ sbr (QMF)        │ mdct     │ tables             │
│ sbr_enc  │ sbr_dec          │ spectral │ aac_cpu            │
│          │ ps (ParamStereo) │          │                    │
├──────────┴──────────────────┴──────────┴────────────────────┤
│                    AacDSP (function pointers)                │
│         Runtime dispatch via aac_dsp_init() + CPU flags     │
├──────────┬──────────┬──────────┬────────────────────────────┤
│  SSE2    │  AVX2    │  NEON    │  WASM SIMD128             │
│ (4-wide) │ (8-wide) │ (4-wide) │  (4-wide, decoder-only)   │
└──────────┴──────────┴──────────┴────────────────────────────┘
```

The codec follows FFmpeg's `AVFloatDSPContext` pattern for SIMD dispatch:

1. `aac_dsp_init()` fills the `AacDSP` struct with scalar C fallbacks
2. Platform-specific init functions (`aac_dsp_init_sse2`, `_avx2`, `_neon`, `_wasm`) override function pointers for detected CPU features
3. Hot-path code calls through function pointers — zero branching at call sites

---

## Building

### Native (Linux / macOS / Windows)

```bash
cd packages/codecs/baander-aac
mkdir -p build && cd build
cmake .. -DCMAKE_BUILD_TYPE=Release
cmake --build .
```

This produces `libbaander-aac.a` (static) on all platforms. Add `-DBAAC_AAC_SHARED=ON` for a shared library.

### WASM (Web)

The WASM build is managed by a Makefile wrapper at `packages/dsp/baander-aac/`:

```bash
cd packages/dsp/baander-aac
make
```

This produces `baander-aac.wasm` and `baander-aac.js` (loader). Requires Emscripten (`emcc` on `$PATH`).

The WASM build includes only the **decoder** — the encoder is disabled via `-DBAAC_AAC_BUILD_ENCODER=OFF`.

### Cross-compilation (iOS / Android)

Pass the appropriate CMake toolchain file:

```bash
# iOS
cmake .. -DCMAKE_SYSTEM_NAME=iOS -DCMAKE_OSX_ARCHITECTURES=arm64

# Android (with NDK toolchain)
cmake .. -DCMAKE_SYSTEM_NAME=Android \
  -DCMAKE_ANDROID_NDK=/path/to/ndk \
  -DANDROID_ABI=arm64-v8a
```

### Build Options

| Option | Default | Description |
|--------|---------|-------------|
| `BAAC_AAC_BUILD_ENCODER` | `ON` | Build the encoder. Forced `OFF` for WASM. |
| `BAAC_AAC_BUILD_DECODER` | `ON` | Build the decoder. |
| `BAAC_AAC_BUILD_TESTS` | `ON` | Build the test suite. Forced `OFF` for WASM. |
| `BAAC_AAC_SHARED` | `OFF` | Build a shared library instead of static. |

Release builds add `-O3 -ffast-math -fno-math-errno`. SIMD source files always compile with the required ISA flags (e.g. `avx2.cpp` gets `-mavx2 -mfma -mbmi2`) regardless of build type.

---

## Native API Reference

All types and functions are declared in `include/aac.h` with `extern "C"` linkage.

### Encoder

```c
// Create an encoder context.
// sample_rate:  Hz (e.g. 44100, 48000)
// channels:     1 (mono) or 2 (stereo)
// bitrate:      target bitrate in bps (used for ABR/CBR; ignored for TVBR)
// aot:          Audio Object Type (AAC_AOT_LC, AAC_AOT_SBR, AAC_AOT_PS)
// rc_mode:      Rate control mode (AAC_RC_TVBR, AAC_RC_CVBR, AAC_RC_ABR, AAC_RC_CBR)
// Returns:      opaque handle, or NULL on error.
AacEncoderHandle aac_encoder_create(int sample_rate, int channels, int bitrate,
                                    AacObjectType aot, AacRateControl rc_mode);

// Encode one frame of PCM float samples.
// pcm:       interleaved float PCM, must contain exactly frame_size() * channels samples
// n_samples: number of samples per channel (must equal frame_size())
// out:       output buffer for encoded bitstream
// out_size:  size of output buffer in bytes
// Returns:   number of bytes written to out, or negative AacError.
int aac_encoder_encode(AacEncoderHandle ctx, const float* pcm, int n_samples,
                       uint8_t* out, int out_size);

// Set quality level for TVBR/CVBR modes.
// quality: 1–10 (higher = better quality / more bits)
int aac_encoder_set_quality(AacEncoderHandle ctx, int quality);

// Returns the number of samples per channel per frame (1024 for AAC-LC).
int aac_encoder_frame_size(AacEncoderHandle ctx);

// Returns the algorithmic delay in samples (due to MDCT overlap).
int aac_encoder_delay(AacEncoderHandle ctx);

// Flush any buffered samples. Call at end of stream.
// Returns: bytes written, or negative AacError.
int aac_encoder_flush(AacEncoderHandle ctx, uint8_t* out, int out_size);

// Release encoder resources.
void aac_encoder_destroy(AacEncoderHandle ctx);
```

### Decoder

```c
// Create a decoder context.
// sample_rate: expected sample rate in Hz
// channels:    expected channel count (1 or 2)
// Returns:     opaque handle, or NULL on error.
AacDecoderHandle aac_decoder_create(int sample_rate, int channels);

// Decode one AAC frame (raw or ADTS-wrapped) into PCM float.
// data:     pointer to encoded AAC data
// size:     size of encoded data in bytes
// pcm:      output buffer for interleaved float PCM
// pcm_size: size of PCM buffer in floats
// Returns:  number of decoded samples per channel, or negative AacError.
int aac_decoder_decode(AacDecoderHandle ctx, const uint8_t* data, int size,
                       float* pcm, int pcm_size);

// Returns the frame size in samples per channel.
int aac_decoder_frame_size(AacDecoderHandle ctx);

// Returns the sample rate (may differ from creation param if SBR upsampling).
int aac_decoder_sample_rate(AacDecoderHandle ctx);

// Returns the channel count (may differ if PS expands mono to stereo).
int aac_decoder_channels(AacDecoderHandle ctx);

// Query whether SBR and/or Parametric Stereo are active.
// has_sbr:  pointer to receive SBR flag (1 = active)
// has_ps:   pointer to receive PS flag (1 = active)
// Returns:  AAC_OK or negative error code.
int aac_decoder_get_sbr_ps(AacDecoderHandle ctx, int* has_sbr, int* has_ps);

// Release decoder resources.
void aac_decoder_destroy(AacDecoderHandle ctx);
```

### CPU Feature Detection

Declared in `include/aac_cpu.h`.

```c
// Returns CPU feature flags (lazily initialized, thread-safe).
int aac_get_cpu_flags(void);

// Human-readable flag string for debug logging.
const char* aac_cpu_flags_string(int flags);

// Override flags for testing SIMD paths on non-matching hardware.
void aac_set_cpu_flags_override(int flags);
```

**Flag constants:**

| Flag | Value | Platform |
|------|-------|----------|
| `AAC_CPU_FLAG_SSE2` | `1 << 0` | x86 |
| `AAC_CPU_FLAG_AVX` | `1 << 1` | x86 |
| `AAC_CPU_FLAG_AVX2` | `1 << 2` | x86 |
| `AAC_CPU_FLAG_FMA3` | `1 << 3` | x86 |
| `AAC_CPU_FLAG_BMI2` | `1 << 4` | x86 |
| `AAC_CPU_FLAG_NEON` | `1 << 5` | ARM |
| `AAC_CPU_FLAG_WASM_SIMD128` | `1 << 6` | WASM |
| `AAC_CPU_FLAG_SSE41` | `1 << 7` | x86 |

### DSP Dispatch

Declared in `include/aac_dsp.h`. The `AacDSP` struct holds function pointers for all hot-path operations:

- **FFT:** `fft_forward`, `fft_inverse`
- **MDCT:** `mdct_forward`, `imdct_half`
- **Vector ops:** `vector_fmul`, `vector_fmul_scalar`, `vector_fmul_add`, `vector_fmul_window`, `vector_fmul_reverse`, `vector_fmul_accumulate`
- **Huffman:** `huffman_decode`
- **SBR QMF:** `sbr_qmf_analysis`, `sbr_qmf_synthesis`
- **Psychoacoustic:** `psycho_spreading`

Initialization:

```c
AacDSP dsp;
aac_dsp_init(&dsp);  // fills scalar defaults, then overrides per CPU flags
```

---

## WASM API Reference

The browser-side API is at `packages/dsp/baander-aac/`. TypeScript declarations in `baander-aac.d.ts`.

```typescript
import { loadAacDecoder, BaanderAacAPI } from './baander-aac.js';

const api: BaanderAacAPI = await loadAacDecoder('/path/to/baander-aac.wasm');
```

### Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `decoderCreate` | `(sample_rate: number, channels: number) => AacDecoderHandle` | Create decoder context |
| `decoderDestroy` | `(ctx: AacDecoderHandle) => void` | Release decoder |
| `decoderDecode` | `(ctx, dataPtr, dataSize, pcmPtr, pcmSize) => number` | Decode one frame. Returns samples per channel, or negative error. |
| `decoderFrameSize` | `(ctx) => number` | Frame size in samples |
| `decoderSampleRate` | `(ctx) => number` | Configured sample rate |
| `decoderChannels` | `(ctx) => number` | Configured channel count |
| `decoderGetSbrPs` | `(ctx, hasSbrPtr, hasPsPtr) => number` | Query SBR/PS status |
| `memory` | `WebAssembly.Memory` | WASM linear memory for creating typed views |

**Note:** `dataPtr` and `pcmPtr` are byte offsets into WASM linear memory. Use `api.memory.buffer` to create `Uint8Array` and `Float32Array` views.

The loader handles both underscored (`_aac_decoder_create`) and plain (`aac_decoder_create`) export names, and falls back to a WASI shim if direct instantiation fails.

---

## Usage Examples

### Encoding (Native)

```c
#include "aac.h"

int main() {
    // Create mono AAC-LC encoder at 128 kbps
    AacEncoderHandle enc = aac_encoder_create(44100, 1, 128000, AAC_AOT_LC, AAC_RC_CBR);
    if (!enc) return 1;

    float pcm[1024];  // exactly frame_size * channels samples
    uint8_t output[8192];

    // ... fill pcm with 1024 float samples ...

    int bytes = aac_encoder_encode(enc, pcm, 1024, output, sizeof(output));
    if (bytes > 0) {
        // output[0..bytes-1] contains the encoded AAC frame
    }

    // End of stream — flush remaining samples
    int flush_bytes = aac_encoder_flush(enc, output, sizeof(output));

    aac_encoder_destroy(enc);
    return 0;
}
```

### Decoding (Native)

```c
#include "aac.h"

int main() {
    AacDecoderHandle dec = aac_decoder_create(44100, 2);
    if (!dec) return 1;

    uint8_t* aac_data = /* ... read from file/network ... */;
    int aac_size = /* ... size of aac_data ... */;

    float pcm[4096];
    int n_samples = aac_decoder_decode(dec, aac_data, aac_size, pcm, 4096);

    if (n_samples > 0) {
        // pcm[0 .. n_samples*channels-1] contains interleaved float PCM
    }

    // Check if SBR/PS was activated
    int has_sbr, has_ps;
    aac_decoder_get_sbr_ps(dec, &has_sbr, &has_ps);

    aac_decoder_destroy(dec);
    return 0;
}
```

### Decoding (WASM/Browser)

```typescript
import { loadAacDecoder } from './baander-aac.js';

async function decodeFrame(aacChunk: Uint8Array): Promise<Float32Array> {
    const api = await loadAacDecoder();

    // Create decoder
    const handle = api.decoderCreate(44100, 2);

    // Allocate WASM memory for input
    const dataPtr = 0; // or use a memory allocator
    const dataView = new Uint8Array(api.memory.buffer, dataPtr, aacChunk.length);
    dataView.set(aacChunk);

    // Allocate WASM memory for output (1024 samples * 2 channels)
    const pcmPtr = dataPtr + aacChunk.length + 16; // leave some padding
    const pcmSize = 2048;

    // Decode
    const n = api.decoderDecode(handle, dataPtr, aacChunk.length, pcmPtr, pcmSize);

    // Read output
    const pcm = new Float32Array(api.memory.buffer, pcmPtr, n * 2);
    const result = new Float32Array(pcm); // copy out of WASM memory

    api.decoderDestroy(handle);
    return result;
}
```

---

## Audio Object Types

| Constant | Value | Profile | Description |
|----------|-------|---------|-------------|
| `AAC_AOT_LC` | 2 | AAC-LC | Low Complexity — the baseline profile. 1024-sample MDCT, no SBR. |
| `AAC_AOT_SBR` | 5 | HE-AAC v1 | Spectral Band Replication — encodes lower frequencies, reconstructs highs at decode time. ~50% bitrate savings vs LC at similar quality. |
| `AAC_AOT_PS` | 29 | HE-AAC v2 | Parametric Stereo — mono core + SBR + spatial parameters. ~30% savings over HE-AAC v1 for stereo content. |

HE-AAC profiles use a 2:1 SBR ratio. The encoder runs a 64-band QMF analysis filterbank; the decoder reconstructs high frequencies via envelope/noise-floor patching and QMF synthesis.

---

## Rate Control Modes

| Constant | Value | Description |
|----------|-------|-------------|
| `AAC_RC_TVBR` | 0 | True VBR — quality-based. Use `aac_encoder_set_quality()` to set target (1–10). Bitrate varies freely. |
| `AAC_RC_CVBR` | 1 | Constrained VBR — quality-based with bitrate ceiling. |
| `AAC_RC_ABR` | 2 | Average Bitrate — targets the specified bitrate over time. |
| `AAC_RC_CBR` | 3 | Constant Bitrate — strict bitrate targeting via lambda adjustment and bit reservoir. |

---

## SIMD Backends

| Backend | File | Width | Requirements | Ops Covered |
|---------|------|-------|--------------|-------------|
| **SSE2** | `src/simd/sse2.cpp` | 4-wide (128-bit XMM) | x86-64 (universal) | FFT, MDCT, vector ops, Huffman, SBR QMF, psycho spreading |
| **AVX2** | `src/simd/avx2.cpp` | 8-wide (256-bit YMM) | AVX2 + FMA3 + BMI2 | All SSE2 ops with FMA fused multiply-add for MDCT |
| **NEON** | `src/simd/neon.cpp` | 4-wide (128-bit Q) | AArch64 / ARMv7 | Same coverage as SSE2 |
| **WASM** | `src/simd/wasm.cpp` | 4-wide (v128) | WASM SIMD128 | Decoder-only: FFT, IMDCT, vector ops, Huffman, SBR QMF |

Runtime detection is thread-safe and lazy (via `std::atomic`). On first call to `aac_get_cpu_flags()`:

- **x86:** CPUID leaf checks for SSE2, SSE4.1, AVX, FMA3, AVX2, BMI2 (with OSXSAVE verification)
- **ARM:** `getauxval(AT_HWCAP)` on Linux, `sysctl` on macOS
- **WASM:** compile-time `#if defined(__wasm_simd128__)`

Override with `aac_set_cpu_flags_override()` to test specific SIMD paths.

---

## Testing

Four test executables are built when `BAAC_AAC_BUILD_TESTS=ON`:

| Test | File | What it validates |
|------|------|-------------------|
| `test_mdct` | `tests/test_mdct.cpp` | FFT forward+inverse roundtrip, MDCT forward+IMDCT roundtrip at multiple sizes (64–1024). Tests scalar and SIMD paths. |
| `test_bitstream` | `tests/test_bitstream.cpp` | Bitstream read/write roundtrip, signed values, ADTS header parse+write, Huffman encode+decode. |
| `test_roundtrip` | `tests/test_roundtrip.cpp` | AAC-LC mono/stereo and HE-AAC encode→decode roundtrip. Verifies non-silent output and no error codes. |
| `test_quality` | `tests/test_quality.cpp` | SNR measurement between original and reconstructed PCM after encode/decode. Target: >20 dB. Uses 3-frame pipeline for MDCT delay alignment. |

```bash
cd build
cmake --build . --target test_mdct test_bitstream test_roundtrip test_quality
ctest --output-on-failure
```

---

## Project Structure

```
packages/codecs/baander-aac/
├── CMakeLists.txt              # Build configuration
├── .clang-tidy                 # Static analysis config (DSP-tuned suppressions)
├── include/                    # Public headers
│   ├── aac.h                   # Primary C API (encoder/decoder)
│   ├── aac_cpu.h               # CPU feature detection
│   ├── aac_dsp.h               # DSP function-pointer dispatch struct
│   ├── aac_tables.h            # Static AAC tables (ISO 14496-3)
│   ├── bitstream.h             # Bitstream reader/writer + ADTS header
│   ├── decoder.h               # Internal decoder types
│   ├── encoder.h               # Internal encoder state
│   ├── fft.h                   # Scalar FFT declarations
│   ├── mdct.h                  # MDCT/IMDCT context and operations
│   ├── ps.h                    # Parametric Stereo (HE-AAC v2)
│   ├── psycho.h                # Psychoacoustic model
│   ├── sbr.h                   # Spectral Band Replication
│   └── spectral.h              # TNS, PNS, M/S, intensity stereo
├── src/                        # Implementation
│   ├── api.cpp                 # C API glue layer
│   ├── encoder.cpp             # Encoder internals
│   ├── decoder.cpp             # Decoder internals
│   ├── fft.cpp                 # Scalar FFT
│   ├── mdct.cpp                # MDCT/IMDCT
│   ├── bitstream.cpp           # Bitstream read/write + ADTS
│   ├── spectral.cpp            # TNS, PNS, M/S processing
│   ├── psycho.cpp              # Psychoacoustic analysis
│   ├── sbr.cpp                 # SBR QMF analysis/synthesis
│   ├── sbr_enc.cpp             # SBR encoder
│   ├── sbr_dec.cpp             # SBR decoder
│   ├── ps.cpp                  # Parametric Stereo
│   ├── aac_cpu.cpp             # CPU feature detection
│   ├── tables.cpp              # Static tables + Huffman VLC data
│   ├── huff_tables_6_11.inc    # Huffman codebook tables (included by tables.cpp)
│   └── simd/
│       ├── sse2.cpp            # x86 SSE2 (4-wide)
│       ├── avx2.cpp            # x86 AVX2+FMA3 (8-wide)
│       ├── neon.cpp            # ARM NEON (4-wide)
│       └── wasm.cpp            # WASM SIMD128 (4-wide, decoder-only)
└── tests/
    ├── CMakeLists.txt
    ├── test_mdct.cpp
    ├── test_bitstream.cpp
    ├── test_roundtrip.cpp
    └── test_quality.cpp

packages/dsp/baander-aac/        # WASM packaging
├── Makefile                     # Emscripten build wrapper
├── baander-aac.js               # WASM loader (ES module)
├── baander-aac.d.ts             # TypeScript declarations
└── build-wasm/                  # (generated) WASM build output
```

---

## Integration with Baander

### Server-side transcoding

The PHP backend uses **FFmpeg** with the AAC codec for audio transcoding. The baander-aac native library is not called directly from PHP — it serves as a purpose-built alternative for environments where FFmpeg is unavailable or too heavy (WASM browser decoding, embedded players).

The transcode pipeline maps AAC variants to RFC 6381 codec strings for HLS/DASH manifests:

| Variant | RFC 6381 String | Profile |
|---------|-----------------|---------|
| `aac`, `aac-lc` | `mp4a.40.2` | AAC-LC |
| `he-aac` | `mp4a.40.5` | HE-AAC v1 |
| `he-aacv2` | `mp4a.40.29` | HE-AAC v2 |

### Browser-side decoding

The WASM decoder is loaded by the web player (`packages/dsp/baander-aac/baander-aac.js`) for client-side AAC decoding in environments where the browser's native `<audio>` element or MSE pipeline cannot handle the incoming format directly.

### Supported file formats

The library and scanner recognize `.aac` (raw ADTS) and `.m4a` (MP4 container) as AAC audio files. MIME type: `audio/aac`.
