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

True-peak reconstruction uses the four-phase, 12-tap-per-phase FIR from
BS.1770-5 Annex 2. The player uses 4× mode; 2× evaluates alternate phases
with lower resolution, and 1× measures sample peaks. Fixed channel histories
persist across calls and clear on reset. Processing performs no allocation.

The peak getter reports the maximum for the latest call, including raw samples.
Collect the maximum across calls for a programme measurement. The filter delay
is 5.875 input frames; feed 11 zero frames at the end of a finite stream and
include their peaks. Do not pad individual blocks. A removed channel's pending
filter tail is included while its history advances with silence.

Actual-WASM tests cover EBU Tech 3341 cases 15–19 at 44.1 and 48 kHz,
independent intersample signals, chunk boundaries, channel history, and reset.
This is not an EBU Mode compliance claim: cases 20–23 and complete programme
qualification remain outstanding. In particular, 4× at 44.1 kHz is below the
192 kHz reconstruction rate described by Annex 2.

Momentary and short-term windows are 400 ms and 3 seconds. During startup,
their averages use the samples received so far. Silence is represented by a
finite floor. Call `reset_loudness` to begin a new measurement; worklet-level
programme reset still needs integration before programme measurements can be
considered qualified.
