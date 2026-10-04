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

This is not an EBU Mode compliance claim. Remaining defects include:

- Integrated loudness averages logarithmic levels, includes incomplete startup
  blocks, and discards history after approximately five minutes.
- Loudness range uses momentary history instead of gated short-term history.
- The peak estimator uses linear interpolation and cannot measure intersample
  overshoot. Its oversampling argument does not make it a compliant true-peak
  meter.

Momentary and short-term windows are 400 ms and 3 seconds. During startup,
their averages use the samples received so far. Silence is represented by a
finite floor. Call `reset_loudness` to begin a new measurement; worklet-level
programme reset still needs integration before programme measurements can be
considered qualified.
