# Codec comparison harness

Reproduces the measurements in
[docs/codec-comparison.md](../docs/codec-comparison.md): baander-aac
against FFmpeg's native `aac` encoder, Fraunhofer FDK AAC, Ittiam's
libxaac, Apple's Core Audio AAC (through qaac under Wine) and, for the
lossless tier, ALAC and FLAC.

## Steps

```sh
# our codec
cmake -S .. -B ../cmake-build -G Ninja -DCMAKE_BUILD_TYPE=Release
ninja -C ../cmake-build baac

# reference codecs and meters into work/ext, then their paths
./setup-refs.sh
. work/ext/env.sh
export VISQOL=/path/to/visqol  # optional, see below

./bench.py list        # the configurations and which tools they lack
./bench.py quality     # encode, decode, align, PEAQ (and ViSQOL)
./bench.py speed       # single-threaded encode and decode speed, memory
./bench.py compat      # cross-decoder agreement
./bench.py lossless    # ALAC and FLAC
./bench.py report      # results/*.csv, results/*.json, results/tables.md
./bandwidth.py         # each configuration's audio bandwidth (work/bandwidth.json)
```

`corpus.sh` fetches the ten TCDAudio14 music excerpts (48 kHz, 16-bit
stereo, 7 to 16 s each) from the ViSQOL repository at a pinned commit
and checks their SHA-256 sums; `bench.py` fetches them on first use.
The excerpts are not committed. Each stage caches its results under
`work/`; delete a configuration's directory under `work/quality/` to
measure it again. `--only REGEX` limits a stage to matching configuration
ids, `--jobs N` sets the parallel encodes of the quality stage (timing
there is under load; use the speed stage for speed).

## Method

- Every encoder reads the same 16-bit samples (a canonical WAV, or raw
  float for `baac`) and writes ADTS for AAC-LC and HE-AAC. For xHE-AAC
  ours and FDK's write LOAS and libxaac a raw elementary stream.
- Quality decodes use one decoder per family so that only the encoder
  differs: FFmpeg for AAC-LC and HE-AAC, libxaac for xHE-AAC (loudness
  normalisation and limiter off).
- Each decode is aligned to the source by cross-correlation and its
  level matched below 3 kHz (where every codec codes the waveform), then
  written as 16-bit WAV. The level correction is recorded (`gain_db`).
- FDK's xHE-AAC encoder runs in its two-pass loudness mode; its
  one-pass mode levels the programme inside the encoder. The FDK
  decoder is built with loudness normalisation and limiter off, and our
  decoder runs with `--no-loudness`.
- PEAQ: GstPEAQ, basic and advanced versions. ViSQOL v3: audio mode,
  default libsvm model, 48 kHz.
- Bitrates are file bitrates including the ADTS or LOAS framing.

## ViSQOL

ViSQOL builds with Bazel 5.3.2; see its README. Where `releases.bazel.build`
is unreachable, take Bazel from its GitHub release assets. Set `VISQOL`
to the binary (and `VISQOL_MODEL` to a model file to override the
default).
