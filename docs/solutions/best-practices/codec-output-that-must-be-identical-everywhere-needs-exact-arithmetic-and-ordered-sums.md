---
title: Codec output that must be identical everywhere needs exact arithmetic and ordered sums
date: 2026-11-12
category: best-practices
module: bAAC codec build flags and floating-point code (packages/baander-aac)
problem_type: test_failure
component: infrastructure
related_components: [testing_framework, development_workflow]
symptoms:
  - "test_usac_enc failed in a Release build on GCC 16.2 while CI's GCC 13.3 passed 46 of 46; GCC 15 failed two suites and clang five"
  - "The AAC native addon workflow failed on master once GitHub's ubuntu-latest moved to Ubuntu 26.04 with GCC 15.2 (test_eld_sbr: 8359 bytes against the reference's 8358)"
  - "The sanitizer build passed while Release failed, and whole streams kept their size: only a few frames differed (frame 34: 230 against 229 bytes)"
root_cause: config_error
resolution_type: code_fix
severity: high
tags: [baac, aac, flac, fast-math, floating-point, reproducibility, compiler, libm, glibc, vectorisation, reference-streams, sanitizer]
---

# Codec output that must be identical everywhere needs exact arithmetic and ordered sums

## Problem

The bAAC package compares freshly encoded streams with committed reference streams byte
for byte, and its README promised that every build reproduces them. The promise held
only for the compiler that wrote the references. Its Release build put
`-fassociative-math`, `-freciprocal-math`, `-fno-signed-zeros` and
`-ffinite-math-only` on every file, tests included, so each compiler version regrouped
and vectorised floating-point sums its own way. The FFmpeg image builds the package with
a different compiler from CI's, so the transcoder's bytes depended on which machine built
it.

Fixed on `master` in `c501eebf1` (`bd175794f`, `4039dff18`, `768802054`, `5210f3e7c`).
The FLAC encoder's part (its own cosine and base-2 logarithm, and strict math for
`flac_enc.cpp`) is on the branch `baac-lossless` (`c65fcea36`, `5e3f4b9c2`) and not
merged yet.

## Symptoms

- Byte comparisons fail on one compiler and pass on another, by a byte or two in a few
  frames of an otherwise identical stream.
- A sanitizer or Debug build passes where Release fails. That points at
  optimisation-dependent floating point, not at a logic error.
- CI stays green until its runner image changes compiler, then fails with no change in
  the repository.

## What Didn't Work

- **Guessing flags.** With `-fno-associative-math` alone, GCC 13, 15 and 16 agreed with
  each other, but on a different signal from the one the references were written from
  (up to 1.8e-4 away). No flag kept the existing references valid.
- **Writing the references with the build under test.** CI wrote the references with
  GCC 13 and checked them with GCC 13, so the comparison could never see a compiler
  dependence.
- **Reading the vectoriser's report to find the speed loss.** Under exact flags GCC
  vectorised 790 loops instead of 808, which understated the cost: the hot reductions
  were still reported as vectorised but had to add in source order, so they ran at the
  latency of one addition per term.
- **Simple multi-accumulator rewrites.** Two scalar four-accumulator versions of the
  AAC-LC noise sum were slower than the old build (one timed 1.123), and a
  register-blocked QMF form was worse on GCC 14.

## Solution

**Locate the cause by swapping object files, not by changing flags.** A GCC 16 library
linked with a GCC 13 test object wrote the stored stream; a GCC 13 library with a GCC 16
test object did not. Capturing the PCM the test hands the encoder (`-Wl,--wrap` on the
encode call) showed 19,107 of 48,128 right-channel samples differing by one unit in the
last place between GCC 13 and 16. Bisecting the library one translation unit at a time
left only `src/qmf.cpp`. Two independent causes: the tests' own signal generator (GCC 15
and 16 split its per-channel loop at `-O3` and regrouped a four-term sum) and the QMF
bank's float reductions.

**Exact arithmetic, everywhere it reaches.** Release keeps `-O3 -fno-math-errno
-fno-trapping-math`, adds `-ffp-contract=off` in every configuration, and drops the four
regrouping options. 32-bit x86 builds with GCC add `-mfpmath=sse`, without which
`FLT_EVAL_METHOD` is 2 and scalar code runs on the x87 stack. The suites that compare
committed streams check a checksum of their generated signal first, so a different
signal fails with "the generated signal has checksum …, the stream was encoded from …"
instead of looking like a codec change.

**Measure what exactness costs, then remove the cost without regrouping.** Exact flags
alone cost HE-AAC v1 encode 38 to 53 %, HE-AAC v2 68 to 82 %, xHE-AAC 24 to 29 %, the
MPEG Surround decode 25 % and AAC-LC encode 5 to 10 %. Callgrind instruction counts
located it (`aac_qmf_analyze` went from 640M to 1,408M instructions); for the xHE-AAC
speech decode the instruction count was flat and the loss was latency, which an
`llvm-mca` cycle estimate of the inner loops showed. Each remedy keeps every bit the
same on every compiler:

- **Interchange loops over transposed tables** where many outputs each sum many terms
  (the QMF banks, `lpd_dct4`): the outer loop walks the terms, the inner loop walks the
  outputs, each output still adds its terms in definition order, and the compiler
  vectorises across outputs.
- **Inline comparisons instead of `fmaxf` and `fminf`**, which were libm calls under
  exact flags.
- **Explicit fixed-order partial sums** for the latency-bound chains that remain (the LPD
  synthesis filter, SBR tonality, the TNS autocorrelation), written out so every compiler
  follows the same order.
- **A DSP kernel with a fixed combination tree** for the AAC-LC quantisation noise
  (`quant_noise` in `include/aac_dsp.h`): eight accumulators, line i into accumulator
  i mod 8, combined as `((a0+a4)+(a2+a6))+((a1+a5)+(a3+a7))`, with scalar, SSE2, AVX2
  and NEON versions that produce the same bits.

**Close the other sources of machine dependence.** glibc's FMA and non-FMA variants of
`cos` and `log2` return different bits for some arguments (6 of 69,536 window values and
186 of 4,000,000 logarithms differed), so the FLAC analysis computes its windows and size
estimates with its own functions. Sorts whose order decides output break ties by channel,
then band, because libstdc++, libc++ and the MSVC library order equal keys differently
(a 5.1 input with duplicated channel pairs had 55,532 ties). Non-finite or huge input is
coded from a cleaned copy: a NaN reached an `(int)` conversion in `bit_demand`, which is
undefined behaviour.

**Verified, 2026-11-11:** 48 of 48 on GCC 14.2 (the FFmpeg image's compiler), 15.2 (CI),
16.2, clang 23, the sanitizer build and a Debug build, and 46 of 46 earlier on GCC 13.3,
14.3, 15.3 and clang 18 and 21. Every build writes the fourteen committed streams at the
default, scalar, SSE2 and AVX2 levels, and 41 committed streams decode to identical
samples across builds. With glibc's FMA and AVX variants masked
(`GLIBC_TUNABLES=glibc.cpu.hwcaps=-FMA,-AVX2,-AVX`, confirmed active with
`ld.so --list-diagnostics`) and the SSE2 level, every committed stream is unchanged.
Against the old fast-math build, timed as alternating old/new pairs on the quietest core,
no workload is slower on GCC 14.2 or 16.2: encodes 0.90 to 0.98, decodes 0.70 to 1.01,
the xHE-AAC speech decode 0.99 (1.06 and 1.08 before the ordered synthesis filter).

## Why This Works

A floating-point sum's result depends on its grouping. The regrouping options license
each compiler, and each optimisation level, to choose a grouping, so the bytes become a
property of the build. Exact arithmetic fixes the grouping to the one the source writes.
The speed lost to that is the loss of independent work: a sum in source order is a chain
of dependent additions. Interchanging loops and writing several accumulators with a
fixed combination recover the independent work while keeping one grouping that every
compiler must follow.

## Prevention

- Do not put regrouping fast-math options on code whose output is compared byte for byte
  or must be reproducible across machines. `-ffp-contract=off` belongs with them:
  contraction into fused multiply-add is a grouping choice too.
- Check references with more than one compiler. CI that writes and checks references with
  the same compiler cannot see this class of failure.
- Diagnose a compiler-dependent difference by swapping object files and bisecting
  translation units, and by capturing intermediate data at a function boundary.
- Measure the cost of exact arithmetic with instruction counts and a latency estimate,
  not the vectoriser's report, and restore speed with loop interchange and explicit
  orders. Never by reintroducing reassociation.
- A sanitizer build needs `float-cast-overflow` added explicitly: `-fsanitize=undefined`
  does not include it, so a NaN reaching an integer conversion passes unnoticed.
- libm results can depend on processor features. Test once with glibc tunables masking
  FMA and AVX2, and compute values that decide output with the codec's own functions
  where the C library's differ.
- Break ties in any sort whose order reaches the output.
- Clang rewrites `powf(2.0f, y)` into `exp2f(y)` at `-O1` and above while GCC calls
  `powf`. They agree for the exponents the MPEG Surround decoder uses; check any new use.

## Related

- `docs/solutions/best-practices/static-ffmpeg-build-silently-drops-components-the-application-uses.md`:
  another way the FFmpeg image's build differs from what was tested.
- Open, recorded in `ROADMAP.md`: NEON code has never executed and no CI runner compares
  it with scalar; MSVC and toolchains other than GCC and Clang get none of the options.
