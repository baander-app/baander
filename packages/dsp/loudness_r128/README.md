# Loudness analysis

The mono/stereo weighting path uses the two filters specified in
[ITU-R BS.1770-5, Annex 1](https://www.itu.int/rec/R-REC-BS.1770-5-202311-I/en).
At 48 kHz it uses the published coefficients; other sample rates transform
that reference response. Stereo energy is the sum of the filtered channel
energies. Mono contributes one channel. Channels beyond the first two are
not supported by this implementation.

Actual-WASM tests check frequency response, mono/stereo energy, and the
stereo calibration signals in
[EBU Tech 3341](https://tech.ebu.ch/docs/tech/tech3341.pdf).
The streaming suite separately checks block-size independence, silence,
reset, and bounded history. Run `bash scripts/test-dsp-analysis.sh` from
the repository root with Emscripten 6.0.3.

Integrated loudness uses complete 400 ms blocks with 75% overlap. Both gates
operate on energy: first the −70 LUFS absolute gate, then a threshold 10 LU
below the absolute-gated mean. Earlier accepted blocks remain part of the
programme until reset, including across long silent passages.

Loudness range follows the gated short-term distribution described in
[EBU Tech 3342](https://tech.ebu.ch/docs/tech/tech3342.pdf). Complete three-second
windows are sampled at least ten times per second. The inclusive gates are
−70 LUFS and 20 LU below the absolute-gated energy mean. The result is the
difference between nearest-rank 95th and 10th percentile levels. Before a usable
observation it returns zero. For file measurements, callers must feed at least
1.5 seconds of trailing silence before reading the final range.

Integrated loudness and LRA each have an independent preallocated pool of
262144 distinct accepted block energies. Identical energies share a count.
At ten observations per second, each pool lasts approximately 7.3 hours in the
worst case. On exhaustion, the affected `lufsI()` or `lra()` getter returns
`NaN` until reset; callers must check `Number.isFinite` before displaying or
serializing it. Other meters continue. No accepted programme history is
silently discarded to recover space. Both pools fit the fixed 32 MiB WASM
memory at the verified 44.1, 48, 96, and 192 kHz sample rates.

This is not an EBU Mode compliance claim. Remaining defects include:

- The peak estimator uses linear interpolation and cannot measure intersample
  overshoot. Its oversampling argument does not make it a compliant true-peak
  meter.

Momentary and short-term windows are 400 ms and 3 seconds. During startup,
their averages use the samples received so far. Silence is represented by a
finite floor. Call `reset_loudness` to begin a new measurement; worklet-level
programme reset still needs integration before programme measurements can be
considered qualified.
