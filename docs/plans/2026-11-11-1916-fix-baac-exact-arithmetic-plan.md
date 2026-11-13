---
title: bAAC Exact Arithmetic - Plan
type: fix
date: 2026-11-11
topic: baac-exact-arithmetic
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# bAAC Exact Arithmetic - Plan

## Goal Capsule

**Objective:** A build of bAAC with GCC 13.3, 14.2, 15.2, 15.3, 16.2 or clang 23 on x86-64 with glibc encodes the same input to the same bytes and decodes the same stream to the same samples, and no encode or decode on the transcoder's path costs more CPU time than it does today.

**Means:** Release builds stop letting the compiler regroup floating-point arithmetic (KTD1), and the three places where that costs time get an order-preserving or explicitly ordered form (KTD2, KTD3, KTD4).

**Product authority:** The user's decision of 2026-11-11, recorded in Key Decisions. The lead commits; this branch (`fix-usac-encoder-vector`) stages and commits nothing.

**Document review:** `ce-doc-review` on 2026-11-11 with the coherence, feasibility, scope-guardian and adversarial lenses. It corrected the stage 1 stop condition and the stage of U6 and U8, and returned its other findings to the lead.

**Execution profile:** Two stages with a report and a stop after each. Stage 1 is U1 to U4 and U9, and keeps the encoder bytes of U1. Stage 2 is U5 and rewrites the committed streams once more. U6 to U8 produce facts and requirements for the lead and edit no file outside this plan; they are delivered with the stage 1 report, and the stage 2 report revises U6 and U8 for the rewritten streams and the AAC-LC encode figures.

**Authority and stop conditions:** Follow `AGENTS.md`, then the Product Contract, then the Planning Contract. Stop and report when any of these holds:

- A stage 1 change makes any compiler write a stream that differs from U1's committed streams.
- Decoder samples differ between two compilers on any committed stream.
- A workload that R5 covers (every measured decode and the HE-AAC v1, HE-AAC v2 and xHE-AAC encodes) is still slower than the old flags (Assumptions define "slower") after U2, U3 and the fallback of KTD2; report the figure with the stage 1 report.
- The stage 2 kernel cannot bring AAC-LC encode to the bar in R6 on all three GCC releases; report the best figure.
- A change would be needed in `.github/workflows/`, `docker/`, the documentation, `ROADMAP.md` or anything on the branch `baac-lossless`.
- The lead announces the quiet window for the lossless benchmark: no build and no measurement until it is over.

## Product Contract

### Summary

bAAC's Release build drops the compiler options that permit regrouped arithmetic, so its output is a function of the source and the C library. The tests gain a guard that names a changed input signal, the eleven committed encoder streams are rewritten by the CI-equivalent build, and three AAC-LC and HE-AAC streams join them. Three follow-on changes remove the speed cost: reordered filterbank loops, inline maximum and minimum, and an explicit kernel for the encoder's noise sum.

### Problem Frame

`test_usac_enc` fails in a Release build with GCC 16.2 and passes with GCC 13.3, the compiler the CI used until November 2026. Two causes were proven by swapping object files between the two builds:

- The test's own signal generator (`gen_he_signal` in `packages/baander-aac/tests/test_util.h`) is compiled with `-fassociative-math`. GCC 15 and 16 regroup its four-term sum after splitting the channel loop, so 19107 of 48128 right-channel samples differ by one unit in the last place. A GCC 16 library with the GCC 13 test object writes the committed stream; the reverse does not.
- The encoder itself differs between GCC releases once the input is identical. `usac_enc_sbr_cpe_64k.loas` comes out at 9580 bytes from GCC 16 and 9571 from GCC 13, and swapping `qmf.cpp.o` alone moves the result. The cause is the vectorised float reductions of the QMF bank.

Full suite on unmodified `c80c368cb`: GCC 13.3 46 of 46, GCC 16.2 45 of 46, GCC 15.3 44 of 46, clang 23 40 of 46. With clang every stored-vector suite fails.

The CI has since moved: its `ubuntu-latest` is now Ubuntu 26.04 with GCC 15.2. The "AAC native addon" workflow's run on master of 2026-11-11 (`c643dcb4f`) failed its Linux CTest step in `test_eld_sbr`, `test_allocation`, `test_usac_enc`, `test_usac_rap` and `test_drc_gen`; the last green run, of 2026-11-07, had Ubuntu 24.04 and GCC 13.3. An Ubuntu 26.04 container with GCC 15.2 fails `test_allocation` and `test_usac_enc` on `c80c368cb`; the other three failures did not reproduce in that container.

The transcoder reaches this code through the FFmpeg image, which compiles the library with Debian trixie's GCC 14.2, a compiler the suite never runs on. Its wrapper (`docker/ffmpeg/baac/libavcodec/libbaacenc.c`) encodes AAC-LC only and decodes every AAC source. The eleven committed encoder streams are xHE-AAC and AAC-ELD, for which `packages/baander-aac/src/encoder.cpp` switches the afterburner off, so no byte comparison runs the encoder the transcoder uses.

Removing the regrouping options makes four compilers agree, and measured alone it costs CPU time (median of paired runs, new against old, one pinned core):

| Workload | GCC 16.2 | GCC 13.3 | GCC 14.2 |
|---|---|---|---|
| encode AAC-LC 128k | 1.101 | 1.056 | 1.105 |
| encode HE-AAC v1 48k | 1.533 | 1.384 | 1.535 |
| encode HE-AAC v2 32k | 1.794 | 1.676 | 1.824 |
| encode xHE-AAC 64k | 1.286 | 1.235 | 1.293 |
| decode AAC-LC, HE-AAC v1, v2, xHE-AAC | 1.00 to 1.02 | 0.95 to 1.01 | 0.99 to 1.01 |
| decode AAC-LC with MPEG Surround 5.1 | 1.248 | 1.254 | 1.272 |

The four plain decodes are within measurement noise on every compiler; the other rows are decided. AAC-ELD, low-delay MPEG Surround and xHE-AAC speech (LPD) decode were not measured.

### Key Decisions

- **Exact arithmetic with all three speed remedies before this reaches master** (session-settled: user-directed — chosen over accepting about 5 % on AAC-LC encode until a kernel lands later, and over keeping the regrouping options with byte identity promised only for the CI compiler: nothing on the transcoder's path may get slower). Governs R1, R5, R6.
- **Two stages, each reported before the next starts.** Stage 1 is committed first because the lossless branch's recorded benchmark waits only for its flags. Governs R5, R6.

### Requirements

**Arithmetic and reproducibility**

- R1. Release builds of the library, the tools and the tests are compiled without `-fassociative-math`, `-freciprocal-math`, `-fno-signed-zeros` and `-ffinite-math-only`, and with contraction off.
- R2. A fresh encode reproduces every committed encoder stream byte for byte with GCC 13.3, 14.2, 15.2, 15.3, 16.2 and clang 23, and at the scalar, SSE2 and AVX2 dispatch levels.
- R3. The committed encoder streams are the ones the CI-equivalent build writes: GCC 15.2 on Ubuntu 26.04, Release. Stage 1's streams were first written by GCC 13.3 on Ubuntu 24.04, the CI's earlier image, and GCC 15.2 writes the same bytes.
- R4. The decoder writes bit-identical samples from every committed `.aac` and `.loas` stream with GCC 13.3, 14.2, 15.2, 15.3, 16.2 and clang 23.
- R11. The committed encoder streams include AAC-LC with the afterburner on, HE-AAC v1 and HE-AAC v2, each with the byte comparison the other streams have.
- R12. The `baac` encodes of the bench corpus as AAC-LC, HE-AAC v1, HE-AAC v2 and xHE-AAC are byte-identical across the compilers and dispatch levels of R2.

**Speed**

- R5. After stage 1, every measured decode and the HE-AAC v1, HE-AAC v2 and xHE-AAC encodes are no slower than the old flags with GCC 13.3, 14.2 and 16.2.
- R6. After stage 2, AAC-LC encode at 128 kbit/s is no slower than the old flags with GCC 13.3, 14.2 and 16.2.

**Tests**

- R7. A stored-vector suite reports a changed input signal by its checksum before it compares bytes.
- R8. `test_allocation` passes with GCC 15.2, 15.3 and clang 23, and its self-check still fails when the counting `operator new` is not in use.
- R9. Each SIMD noise-sum kernel returns the bits of its scalar twin for every length a band can have; a kernel that was neither compiled nor run on the host is named as such wherever its result is reported.

**Handover**

- R10. The lead receives the documentation facts, the CI requirement and the FFmpeg image statement from U6, U7 and U8; no documentation, workflow or Dockerfile is edited here.

### Scope Boundaries

- No edit to `packages/baander-aac/docs/`, `ROADMAP.md`, `.github/workflows/aac-native.yml` or `docker/ffmpeg/Dockerfile`.
- Nothing on the branch `baac-lossless` is read or changed.
- ARM, MSVC and C libraries other than glibc are not verified; U6 says so.
- The external decoder figures in the documentation are not re-measured: FDK and libxaac are not on the host.
- Considered and not built: a CMake option that brings the regrouping options back. No consumer asked for one, and a second arithmetic is what this plan removes.
- Considered and not built: a mechanism that makes `test_dsp` fail when a required dispatch level is missing on the runner. The lossless branch carries one and the lead merges it; U7 states the need.

### Open Questions

- Deferred to the lead before stage 2, not blocking stage 1: whether the NEON noise-sum kernel is written. It can be neither compiled nor run on the host, the only ARM build is the Node binding, which never had the regrouping options and so has no speed to recover, and the dispatch table leaves ARM on the scalar twin when no NEON entry exists. The request asks for the kernel, so U5 plans it.

## Planning Contract

### Key Technical Decisions

- KTD1. **Release options are `-O3 -fno-math-errno -fno-trapping-math`, with `-ffp-contract=off` for every configuration.** The two kept options change no result. Contraction is off tree-wide so that a target with fused multiply-add rounds like one without. The per-file `-ffp-contract=off` settings stay; they are redundant and harmless.
- KTD2. **The filterbank loops are interchanged over transposed tables.** Each output still adds its terms in source order, so the bytes of U1 do not change, and the compiler vectorises across outputs. A scratch prototype of this form on `qmf.cpp` gave identical streams and samples and these ratios against the old flags: HE-AAC v1 0.982, v2 0.946, xHE-AAC 0.998 and MPEG Surround decode 0.972 with GCC 16.2; 1.029, 1.021, 1.037 and 1.007 with GCC 14.2. Fallback when a compiler's own vectorisation misses R5: the interchanged inner loops call an elementwise dispatch kernel that multiplies and adds without fusing, which keeps the order and the bytes. The existing `vector_fmul_accumulate` is not that kernel: its AVX2 form fuses.
- KTD3. **`fmaxf` and `fminf` at the encoder's call sites become inline comparisons.** Without `-ffinite-math-only` the compiler calls the C library for them: 107 million instructions in a 20 s AAC-LC encode, where the old build had none. The comparison returns the same value for the finite operands these sites see.
- KTD4. **The noise sum is one kernel with a scalar twin, and both of its callers use it.** `band_noise_at` in `src/encoder.cpp` and `band_noise` in `src/afterburner.cpp` must return the same sum for the same band, so both go through the dispatch table. The lane count and the order in which lanes combine are defined once, by the scalar twin. Keeping source order is not an option: under KTD1 the compiler already vectorises the terms and adds them in order, and that form is the measured 5 to 10 % cost. Two scalar multi-accumulator rewrites were slower still (ratio 1.123 for one), which is why the kernel is explicit SIMD.
- KTD5. **The kernel lives in the existing kernel files.** `src/dsp.cpp`, `src/simd/sse2.cpp` and `src/simd/neon.cpp` are already in `binding.gyp`; `src/simd/avx2.cpp` is built by CMake only, and the binding dispatches to SSE2 at most. No source list changes.
- KTD6. **The committed streams are written twice, each time by the CI-equivalent build.** U1's and U9's streams hold through stage 1. U5 changes the rounding of the noise sum and rewrites them.
- KTD7. **`test_allocation`'s self-check calls the allocation function by name.** For a new-expression clang 23 removes a matched `new` and `delete`, and GCC 15 and 16 assume by default that a replaced `operator new` changes no global (`-fassume-sane-operators-new-delete`), so the comparison is decided without reading the counter. Neither licence covers a direct call of `::operator new`, which every compiler on the host counts, so the check stays a proof that the counting operators are linked in.

### Assumptions

- The C library's `sinf`, `cosf`, `expf` and `powf` return the same results on the CI runner and on the hosts that run the suite. glibc 2.39 and 2.44 agreed on every signal checksum.
- The host stays loaded during measurement. A workload is measured as at least eight old-then-new pairs. It is slower when at least seven of every eight pairs have a ratio above 1 and the median is above 1.01; otherwise it is no slower.

### Sources and Research

- Instruction counts on 20 s of the bench corpus, GCC 16.2, old flags against exact: `aac_qmf_analyze` 640M to 1408M, `aac_qmf_synthesize` 196M to 313M, `band_noise_at` 1041M to 1150M, `fmaxf` 0 to 107M. The measured decoders grow by at most 1.6 %.
- `packages/baander-aac/src/qmf_ld.cpp` (low-delay SBR, encoder and decoder) and `packages/baander-aac/src/mps_ld.cpp` (low-delay MPEG Surround) carry the same direct sums as `qmf.cpp`.
- Exact flags alone, full suite: GCC 13.3 46 of 46, GCC 16.2 46 of 46, sanitizer build 46 of 46, GCC 15.3 and clang 23 45 of 46 with only `test_allocation` failing.
- Decoder samples under the old flags differ between GCC 16.2 and GCC 13.3 on 28 of the 38 committed streams. Old against exact differs on all 38 by at most 5.9e-6 of full scale.
- `packages/baander-aac/binding.gyp` already compiles the Node binding with `-O3 -ffp-contract=off` and none of the regrouping options.
- Dispatch table and its test: `packages/baander-aac/include/aac_dsp.h`, `packages/baander-aac/tests/test_dsp.cpp`. The test skips a level the host lacks and compares the exact kernels with `bit_exact`.

## Implementation Units

### U1. Exact arithmetic flags, signal guard and committed streams

- **Goal:** The build computes in exact arithmetic and the stored-vector suites compare against streams written that way.
- **Requirements:** R1, R2, R3, R7.
- **Dependencies:** none.
- **Files:** `packages/baander-aac/CMakeLists.txt`, `packages/baander-aac/tools/fuzz/CMakeLists.txt`, `packages/baander-aac/tests/test_util.h`, `packages/baander-aac/tests/test_usac_enc.cpp`, `packages/baander-aac/tests/test_usac_enc_sbr.cpp`, `packages/baander-aac/tests/test_usac_rap.cpp`, `packages/baander-aac/tests/test_drc_gen.cpp`, `packages/baander-aac/tests/test_eld_sbr.cpp`, and under `packages/baander-aac/tests/data/` the seven `usac_enc_*.loas` and four `eld_*_ref.loas` streams.
- **Approach:** Apply KTD1. Move the sanitizer detection and the reference-build condition into `test_util.h`, add the signal checksum and its check, and give `test_eld_sbr` the `--write-streams` writer the other suites have. Remove the fuzz harnesses' `-fno-finite-math-only`, which the tree no longer needs. Write the eleven streams with the CI-equivalent build (KTD6).
- **Test scenarios:**
  - With the regrouping options put back on the command line, each stored-vector suite fails on the signal checksum and names both checksums.
  - A fresh encode in a Release build equals each committed stream on every compiler in R2.
  - A sanitizer build and a Debug build take the decode-comparison path and pass.
- **Verification:** The full suite passes on every compiler in R2 and in the sanitizer configuration, apart from `test_allocation` until U4.

### U2. Filterbank loops in output order over transposed tables

- **Goal:** The QMF banks cost what they did under the old flags without changing a result.
- **Requirements:** R2, R4, R5, R12.
- **Dependencies:** U1.
- **Files:** `packages/baander-aac/src/qmf.cpp`, `packages/baander-aac/src/qmf_ld.cpp`, `packages/baander-aac/src/mps_ld.cpp`, `packages/baander-aac/tests/test_qmf.cpp`.
- **Approach:** Apply KTD2 to every direct sum in the three files: the 64-, 24- and 16-band analysis matrices and the 32-band synthesis matrix of `qmf.cpp`, the analysis and synthesis matrices of the two low-delay files, and each file's analysis and synthesis window sums. Build each transposed table beside its original in the table initialisation.
- **Test scenarios:**
  - For each analysis size of `qmf.cpp` (16, 24, 32, 64) and its 32-band synthesis, the bank's output equals, bit for bit, a reference in `test_qmf` that adds the same terms in source order.
  - The streams of U1 are reproduced unchanged on every compiler in R2.
  - Decoder samples from the 38 committed streams equal those of U1's build.
  - `test_sbr_ld`, `test_eld_sbr`, `test_mps212` and `test_mps_stream` pass unchanged.
- **Verification:** Byte and sample identity against U1, then the paired speed table of the Verification Contract.

### U3. Inline maximum and minimum in the encoder

- **Goal:** The encoder makes no C library call for a maximum or minimum.
- **Requirements:** R2, R5, R6, R12.
- **Dependencies:** U1.
- **Files:** `packages/baander-aac/src/encoder.cpp`, `packages/baander-aac/src/psycho.cpp`, `packages/baander-aac/src/afterburner.cpp`.
- **Approach:** Apply KTD3 at every `fmaxf` and `fminf` call in the three files, with `std::max` and `std::min` or a comparison whose operand order returns what the call returned.
- **Test scenarios:**
  - The streams of U1 are reproduced unchanged on every compiler in R2.
  - The bench corpus encodes of R12 equal those of U1's build; the stored streams of U1 never run the afterburner, so this comparison is the one that covers its four call sites.
  - The encoder objects no longer import `fmaxf` or `fminf`.
- **Verification:** Byte identity against U1 and the AAC-LC encode row of the speed table.

### U4. Allocation self-check that the compiler cannot remove

- **Goal:** `test_allocation` passes on every compiler and still proves the counting operators are in use.
- **Requirements:** R8.
- **Dependencies:** none.
- **Files:** `packages/baander-aac/tests/test_allocation.cpp`.
- **Approach:** Apply KTD7 to the self-check at the end of `main`.
- **Test scenarios:**
  - The suite passes with GCC 13.3, 14.2, 15.2, 15.3, 16.2 and clang 23 in Release.
  - With the counting `operator new` definitions compiled out for one scratch run, the self-check fails.
- **Verification:** The full suite is 46 of 46 on every compiler in R2.

### U9. Committed AAC-LC and HE-AAC encoder streams

- **Goal:** The suite compares bytes on the encoder the transcoder uses and on the SBR and parametric-stereo encoders.
- **Requirements:** R2, R3, R11.
- **Dependencies:** U1, U2, U3.
- **Files:** `packages/baander-aac/tests/test_encoder_tuning.cpp`, and three new streams under `packages/baander-aac/tests/data/`: AAC-LC stereo at 128 kbit/s with the afterburner on, HE-AAC v1 stereo at 48 kbit/s and HE-AAC v2 at 32 kbit/s.
- **Approach:** Encode the signal of the existing stored-vector suites with `encode_stream` from `test_util.h`. Follow those suites: a `--write-streams` writer, the signal check of R7, the byte comparison in the reference build and the decode comparison elsewhere. Write the streams with the CI-equivalent build (KTD6).
- **Test scenarios:**
  - A fresh encode equals each of the three streams on every compiler in R2 and at the scalar, SSE2 and AVX2 levels.
  - Each stream decodes without error and within the suite's signal-to-noise bound of its source.
  - A sanitizer build takes the decode-comparison path and passes.
- **Verification:** The full suite passes on every compiler in R2 with the three streams in place.

### U5. Explicit noise-sum kernel

- **Goal:** AAC-LC encode costs no more than under the old flags.
- **Requirements:** R2, R3, R6, R9, R12.
- **Dependencies:** U1, U3, U9.
- **Files:** `packages/baander-aac/include/aac_dsp.h`, `packages/baander-aac/src/dsp.cpp`, `packages/baander-aac/src/simd/sse2.cpp`, `packages/baander-aac/src/simd/avx2.cpp`, `packages/baander-aac/src/simd/neon.cpp`, `packages/baander-aac/src/encoder.cpp`, `packages/baander-aac/src/afterburner.cpp`, `packages/baander-aac/tests/test_dsp.cpp`, and the fourteen streams of U1 and U9.
- **Approach:** Apply KTD4 and KTD5. Choose the lane count by measurement. Write the NEON kernel so that each line can be read against the scalar twin, subject to the open question. Rewrite the streams with the CI-equivalent build (KTD6) and update no checksum, since the signals do not change.
- **Execution note:** Write the scalar twin and its `test_dsp` comparison first; a SIMD kernel is done when it matches, not when the encoder runs.
- **Test scenarios:**
  - For every length from 1 to 128, which covers the widest scalefactor band of the tables, each SIMD kernel returns the scalar twin's bits.
  - A band whose quantised magnitude reaches the escape limit gives the same sum at every level.
  - `band_noise_at` and `band_noise` return the same value for the same band and scalefactor.
  - A fresh encode equals each rewritten stream on every compiler in R2 and at the scalar, SSE2 and AVX2 levels.
  - The bench corpus encodes of R12 are identical across the compilers and levels of R2.
- **Verification:** The full suite on every compiler in R2 and the sanitizer build, then AAC-LC encode measured against R6 on GCC 13.3, 14.2 and 16.2, and once more with the dispatch capped at SSE2.

### U6. Documentation facts for the lead

- **Goal:** The lead can write the documentation from a list of passages and facts.
- **Requirements:** R10.
- **Dependencies:** U1 to U4 and U9; revised after U5.
- **Files:** none; the list is part of the stage reports.
- **Approach:** Name each passage and the fact that replaces or annotates it:
  - `packages/baander-aac/docs/README.md`, the testing paragraph ending "a fresh encode reproduces every committed stream byte for byte": true across the compilers in R2 on x86-64 with glibc; ARM, MSVC and other C libraries unverified.
  - The same file's Building section: the Release options of KTD1 and why.
  - The same file's speed tables, `packages/baander-aac/docs/codec-comparison.md` (speed tables and "Release build, GCC 13.3") and `packages/baander-aac/docs/reference-comparison.md` (per-stream decoder figures on the committed streams, measured 2026-10-30): all were measured with the regrouping build and on the earlier streams.
- **Test expectation:** none; no file changes.

### U7. CI requirement for the lead

- **Goal:** The merged workflow checks byte identity on more than one compiler.
- **Requirements:** R10.
- **Dependencies:** U1, U9.
- **Files:** none.
- **Approach:** State the requirement for `.github/workflows/aac-native.yml`: the Linux job configures, builds and runs CTest a second time with a second compiler in its own build directory, and `test_dsp` must fail, not skip, when the runner lacks a level the job names. Name the second compiler from evidence: build the branch with each clang and GCC release that Ubuntu 26.04 (the runner's image) packages besides GCC 15.2, and report whether each writes the committed streams.
- **Test expectation:** none; no file changes.

### U8. FFmpeg image statement for the lead

- **Goal:** The lead knows what the flag change does to the transcoder's encoder.
- **Requirements:** R10.
- **Dependencies:** U1 and U9; revised after U5.
- **Files:** none.
- **Approach:** Confirm from `docker/ffmpeg/Dockerfile` that the image configures this package with `-DCMAKE_BUILD_TYPE=Release` and no flags of its own, then state the consequence: the image picks up KTD1 with no edit, and its AAC-LC encoder writes the bytes U9's stream pins, as R2 shows for GCC 14.2.
- **Test expectation:** none; no file changes.

## Verification Contract

| Check | How | Applies to |
|---|---|---|
| Full suite, Release | `cmake -S packages/baander-aac -B <dir> -G Ninja -DCMAKE_BUILD_TYPE=Release`, build, `ctest` | GCC 13.3 (Ubuntu 24.04 container), 14.2 (Debian trixie container), 15.2 (Ubuntu 26.04 container, the CI's image, with FFmpeg as in the CI), 15.3, 16.2, clang 23 |
| Full suite, sanitizers | the same with `-DBAAC_FUZZ=ON` | GCC 16.2 |
| Encoder byte identity, committed streams | every stored-vector suite run with `--write-streams`; outputs compared with `packages/baander-aac/tests/data/` | the five compilers, and `BAAC_AAC_CPU=scalar`, `sse2`, `avx2` on the host |
| Encoder byte identity, bench corpus | `baac enc` of the corpus as AAC-LC 128k, HE-AAC v1 48k, HE-AAC v2 32k and xHE-AAC 64k; outputs compared | the five compilers and the three dispatch levels; in stage 1 also against U1's build |
| Decoder sample identity | `baac dec` on the 38 committed `.aac` and `.loas` streams; outputs compared bit for bit | the five compilers |
| Node binding | `npx node-gyp rebuild`, then `npm test` in `packages/baander-aac`; compare `binding.gyp`'s flags with KTD1 | host |
| Speed | `baac` built from `c80c368cb` against the branch; old then new as a pair on the quietest core; the rule in Assumptions | GCC 13.3, 14.2, 16.2. Stage 1: the nine workloads of the Problem Frame table plus decodes of a committed AAC-ELD stream with low-delay SBR, `mps_212_eld.loas` and a committed xHE-AAC speech stream; the AAC-LC encode figure is reported for information, since R6 holds it to the bar only after stage 2. Stage 2: AAC-LC encode, also with `BAAC_AAC_CPU=sse2` |

## Definition of Done

- Stage 1: U1 to U4 and U9 are in the working tree, R1 to R5, R7, R8, R11 and R12 hold, and the report carries the suite counts, the identity results, the binding result, the speed table and the lists of U6 to U8.
- Stage 2: U5 is in the working tree, R2, R3, R6, R9 and R12 hold with the rewritten streams, the report names any kernel that was not compiled or not run, and it revises the lists of U6 and U8 for the rewritten streams and the AAC-LC encode figures.
- No scratch prototype, measurement script or build directory is left in the working tree.
- Nothing is staged or committed.
