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
else. The same call fetches the stage's recordings: the FLAC decoder
testbench (`../tests/flac-testbench.sh`), seven files of which are part
of the corpus, and the held-out recordings (`./fetch-heldout.sh`). The
quality, speed and compat stages also need scipy and soundfile.

`report` replaces a results file and its section of `results/tables.md`
only when the stage has a cache under `work/`; the others stay as they
are, so one stage can be measured again without the tools of the rest.
`./bench.py selftest` checks the harness's own logic (the gate
statistic, the check mode, the report) on made-up figures. The check
mode holds a complete result against the list of gates a complete run
has to judge, so a run that lost a gate does not pass for lack of it.

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
- **Corpus additions.** Seven recordings of the FLAC decoder testbench
  are measured beside the ten items: five 96 kHz 24-bit files (testbench
  files 28 to 32), a 5.1 file (41) and a 16-bit stereo file (01). Each
  set has a size gate on its total at level 5, an encode gate and two
  decode gates, and the sizes are recorded file by file at levels 5 and
  8. The enlarged corpus, the ten items and these seven, has totals and
  size gates of its own at levels 5 and 8. The totals of the ten items
  stay as they were and are labelled as the original corpus.
- **Held-out recordings.** Three recordings that are never used to tune
  the encoder, at level 5, with the same four gates for each: Variatio 4
  of the Open Goldberg Variations (96 kHz, 24-bit stereo, 69 s), twenty
  seconds of the 5.1 master of the film Sintel (48 kHz, 16-bit, from
  5:00 to 5:20) and the audio of the film's trailer (48 kHz, 16-bit
  stereo, 52 s). [Held-out recordings](#held-out-recordings) states
  where they come from, how the stage keeps them unseen and what
  happened to the set before them.
- **What the figures describe.** One build on one machine: `baac` as
  CMake builds it in Release, on the processor the run records. The
  judged speed figure leaves the start of a process out, and the
  per-item figures include it; one start of `baac` costs more than one
  of `flac`, because the tool loads the C++ runtime dynamically. On x86
  the AVX2 kernels run, among them a Rice writer that only GCC and Clang
  build, so an MSVC build encodes more slowly than a run here shows. The
  NEON kernels do not run on an x86 host, and no ARM speed is recorded.
- **Host.** A run records the processor, the compiler, the versions of
  the references and the load average at its start and end. A run that
  was limited (`--only`, `--gates-only`, `--reps`) or made while the
  load was above `--quiet-load` (2.0) is recorded as such and is not a
  gate result: `check` exits non-zero for it.

## Held-out recordings

The held-out recordings exist to answer one question: whether the
encoder is as good on material it was not tuned on as on the corpus. The
answer holds only if nothing was decided by looking at them, so the
harness makes the first look the recorded one.

`fetch-heldout.sh` downloads three files into `work/heldout` and checks
them against `heldout.sha256`; `setup-refs.sh` calls it. The script
downloads and checksums and does nothing else.

| File | Source | Licence |
|---|---|---|
| `open-goldberg-variatio-4.flac` | Kimiko Ishizaka, J.S. Bach: "Open" Goldberg Variations, BWV 988, Variatio 4; Internet Archive item `OpenGoldbergVariations` | Creative Commons Zero 1.0 |
| `sintel-master-51.flac` | The 5.1 master of the open movie Sintel, (c) Blender Foundation, durian.blender.org; `media.xiph.org/sintel` | Creative Commons Attribution 3.0 |
| `sintel_trailer-audio.flac` | The trailer audio of the same film; `media.xiph.org/sintel` | Creative Commons Attribution 3.0 |

Only the lossless stage reads the files, and only in a run that can
count: a complete run with `--check` that starts on a quiet host. A
limited run, a run without `--check` and a run that starts under load
leave them alone and say so. Before the stage decodes the first file it
books the run in `work/heldout/runs.json`, with the date and the SHA-256
of the `baac` binary it measures, and the result records whether the run
was the first. A later run of the same binary is a repeat of the same
measurement and counts. A later run of another binary does not: `check`
reports its held-out gates and exits non-zero, because the encoder may
have changed in answer to the first look. Deleting the ledger does not
make the recordings unseen; a set that has been looked at is replaced,
not reused. The span of the film master was fixed before the file was
decoded.

A held-out gate that fails in the recorded run is reported as failed.
The encoder is not tuned in answer to it.

### The first held-out set

The seven testbench recordings that are now corpus additions were the
first held-out set. They were run once before the encoder was final, in
a limited run on a host under load, and two encode gates failed there:
the 96 kHz 24-bit set at a ratio of 1.089 and the 16-bit stereo file at
1.031. Four speed changes to the encoder followed (a Rice writer in the
kernel table, a wider residual kernel for AVX2, a sample scan and input
copy the compiler can vectorise, and a CRC-16 that takes sixteen bytes
at a step). They were chosen and measured on the ten items and on the
ten items scaled to 24 bits, and they left every encoded byte as it was.
The failed gates had nonetheless prompted the work, so the set could no
longer serve as held-out. It was folded into the corpus, where tuning on
it is allowed, and the three recordings above replaced it.

## ViSQOL

ViSQOL builds with Bazel 5.3.2; see its README. Where `releases.bazel.build`
is unreachable, take Bazel from its GitHub release assets. Set `VISQOL`
to the binary (and `VISQOL_MODEL` to a model file to override the
default).
