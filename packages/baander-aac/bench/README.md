# Codec comparison harness

Reproduces the measurements in
[docs/codec-comparison.md](../docs/codec-comparison.md): baander-aac
against FFmpeg's native `aac` encoder, Fraunhofer FDK AAC, Ittiam's
libxaac and Apple's Core Audio AAC (through qaac under Wine); and
bAAC's lossless mode against FLAC 1.4.3, FFmpeg's FLAC and ALAC encoders
and Apple's `refalac`.

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
./bench.py lossless    # the lossless mode: sizes, speeds, gates
./bench.py check       # the gates of that run; exits non-zero unless all passed
./bench.py report      # results/*.csv, results/*.json, results/tables.md
./bandwidth.py         # each configuration's audio bandwidth (work/bandwidth.json)
```

The lossless stage needs only the pinned `flac`, FFmpeg and Python with
numpy: `./setup-refs.sh --lossless-only` builds `flac` 1.4.3 and writes
its exports, and the full script writes them before it fetches anything
else. `../tests/flac-testbench.sh` fetches the recordings of the held-out
sets. The quality, speed and compat stages also need scipy and
soundfile.

`report` replaces a results file and its section of `results/tables.md`
only when the stage has a cache under `work/`; the others stay as they
are, so one stage can be measured again without the tools of the rest.
`./bench.py selftest` checks the harness's own logic (the gate
statistic, the check mode, the report) on made-up figures.

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

## Lossless stage

`./bench.py lossless` measures the `baac flac` commands against the
references and judges the gates of the lossless mode; the comment above
`stage_lossless` in `bench.py` is the full statement of the method.

- **Rows.** bAAC levels 0 to 8, `flac -0` to `-8`, FFmpeg's FLAC encoder
  at levels 5 and 8 (for reference, no gate), FFmpeg ALAC, and `refalac`
  when it runs on the machine. Where it does not, its row is carried
  from the run of 2026-10-30, marked, and compared on size only.
- **Size.** One pass over the 130 s corpus. FLAC rows record the file
  and its frame bytes (the file without its metadata blocks); size
  gates between FLAC encoders compare frame bytes and take no
  tolerance.
- **Speed.** Every timed process is pinned to one core with `taskset`
  (`--core N`; the idlest by default) and measured in CPU time. A gate
  takes five repeats or more (`--repeat`); in each, the reference and
  bAAC run back to back in alternating order and give one paired ratio.
  The threshold is 1 plus the reference's own spread over the repeats.
  All ratios at or below it: passed. All above: failed. Otherwise:
  undecided, which is not a pass.
- **What is judged.** One process over the corpus repeated until the
  fastest command takes 2 CPU seconds, encoders reading raw PCM. `flac`
  reads raw PCM about 3 % faster than WAV and bAAC reads both alike, so
  raw input is the case that favours bAAC least. The same measure from
  WAV, and with one process per corpus item (which adds each tool's
  start), is recorded beside the judged figure.
- **Decode.** `flac`'s own `-5` and `-8` streams, decoded by bAAC,
  `flac -d` (MD5 verified by both) and FFmpeg (frame CRCs checked by
  both, no MD5).
- **Exactness.** Every stream is decoded by its own decoder and by the
  others, and each decode compared with the source bytes.
- **Held-out recordings.** Five 96 kHz 24-bit files, a 5.1 file and a
  16-bit stereo file from the FLAC decoder testbench, at level 5. They
  are measured for the record and never used to tune the encoder.
- **Host.** A run records the processor, the compiler, the versions of
  the references and the load average at its start and end. A run that
  was limited (`--only`, `--gates-only`, `--reps`) or made while the
  load was above `--quiet-load` (2.0) is recorded as such and is not a
  gate result: `check` exits non-zero for it.

## ViSQOL

ViSQOL builds with Bazel 5.3.2; see its README. Where `releases.bazel.build`
is unreachable, take Bazel from its GitHub release assets. Set `VISQOL`
to the binary (and `VISQOL_MODEL` to a model file to override the
default).
