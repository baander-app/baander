# DSP qualification

Run `bash scripts/test-dsp-analysis.sh` from the repository with Emscripten
6.0.3 and Node.js on `PATH`. The gate builds `fft2048`, `spectral_features`,
`dynamics_meter`, `loudness_r128`, and `partitioned_convolver` twice in temporary
directories, runs analytical reference vectors against each build, compares the
binaries, and checks that the source-package, web-served, and Electron public WASM
artifacts match. It also checks that the web-served FFT worklet and dynamics loader
match their source-package copies. It leaves tracked files unchanged and fails on
a missing compiler, different compiler version, failed vector, or stale artifact.

The gate also builds an unshipped small-capacity gating harness. Its tests check
exact threshold queries, tree balancing, and explicit exhaustion/reset behavior.
The production pool remains fixed at 262144 distinct energies; the harness
does not change the deployed ABI or memory limit.

After an intentional source change, regenerate the five qualified DSP modules
from the repository root, then run the gate:

```sh
yarn --cwd ui/electron build:wasm
bash scripts/test-dsp-analysis.sh
```

Regeneration requires Emscripten 6.0.3. Set `EMCC` to select a compiler outside
`PATH`; the regeneration command and qualification gate both respect it. The
command rejects a missing or different compiler before changing tracked artifacts.
It forces fresh builds of `fft2048`, `spectral_features`, `dynamics_meter`,
`loudness_r128`, and `partitioned_convolver` into temporary output paths. Once all
builds succeed and their outputs are readable, it publishes identical WASM bytes
to each source package, `public/dsp/`, and `ui/electron/public/dsp/`. It also synchronizes the dynamics
loader into both public directories and the FFT worklet into
`public/audio-worklets/`. Build, publication, and copied-byte verification errors
fail the command. Rerun regeneration to repair an interrupted publication.

Ordinary Electron development and packaging consume checked-in assets without a
compiler. They require all six modules in `ui/electron/src/shared/wasm-modules.json`:
`fft2048`, `spectral_features`, `dynamics_meter`, `loudness_r128`,
`partitioned_convolver`, and `resampler_hq`. Each module requires its JavaScript
loader except standalone `fft2048`. Missing required files fail with a diagnostic
that names the file and directs developers to restoration or explicit regeneration.
The regeneration command covers the five qualified DSP modules. Restore missing
resampler artifacts and JavaScript loaders other than the synchronized dynamics
loader from the repository. Packaging fails if a required DSP copy fails or its
destination bytes differ from the source.

The reference suite can also inspect existing web-served artifacts directly:

```sh
node --test packages/dsp/tests/*.test.mjs
```

Forgejo runs the same gate with Emscripten 6.0.3. Its installer is pinned to
the official SDK's 6.0.3 tag commit,
`db04e88298d9916fc51fcd3743045ca3eb695127`.

This qualification covers the FFT magnitude and waveform output, worklet
buffering, spectral features, and the dynamics meter’s rolling RMS and
sample-peak decay contract. Meter worklet tests cover continuous delivery,
silence, channel handling, sample rates, and safe WASM buffer ownership.
Loudness streaming tests exercise state continuity and window decay; they do
not establish R128 compliance. Independent vectors cover mono/stereo K-weighting
at 44.1 and 48 kHz. Integrated gating tests cover energy thresholds, complete
blocks, retained programme history, and explicit capacity exhaustion.
Loudness range tests cover gated three-second windows, programme history,
nearest-rank percentiles, and independent capacity exhaustion. True-peak tests
cover EBU cases 15–19, intersample overshoot, and streaming filter history.
Player programme resets cover source loads, handoffs, and repeats, with
queued-report isolation. Crossfades measure the output mix; see the
[loudness module](loudness_r128/README.md) for the remaining limitations.
Convolver vectors compare the compiled module with independent causal convolution
using an absolute sample tolerance of `2e-6`. They cover pending tails across
internal blocks and whole-stream, single-frame, and uneven calls, including
zero-input tail draining, one-tap gain, asymmetric stereo, and a silent channel.
The module retains direct time-domain convolution; its real-time allocation and
performance behavior is unqualified. No active player wiring was found, so these
vectors establish module streaming correctness without claiming an audible player
change. This gate does not establish resampling quality, native AAC codec
compliance, audible playback quality, or overall native-path release certification.


The web loader shares compiled WASM modules, while each getter call creates an
independent instance and memory. Its tests use shipped binaries to check state
isolation, concurrent compilation, failed-load retries, and cache reset races.
Clearing the compilation cache never resets live measurements. The main-thread
processor owns only spectral analysis; loudness and dynamics run in its worklet.
The standalone JavaScript demo loaders are separate from this web loading path.


Playback without a captured audio source exposes neutral buffers and unavailable
measurements. It does not run a background analysis worker or fabricate channel
levels. Active analysis uses the spectrum worklet with the native analyser as
fallback; both expose the [display spectrum contract](fft2048/README.md#display-spectrum)
and feed the processor's owned spectral module through decoded linear floats.
The Equalizer uses a common logarithmic axis and peak-band aggregation, renders
the selected mode at full visibility, and clears live readings while paused.
Channel meters show RMS dBFS on a −60 to 0 scale, with zero signal shown as −∞.
The shared contract includes the actual sample rate, FFT size and −100 to 0 dB
bounds. The separate linear FFT utility API is unchanged.


The player exposes the latest complete worklet meter report for channel levels,
stereo RMS, and momentary LUFS. Reports expire after 250 ms of audio time and
clear at programme and playback lifecycle boundaries. Native LUFS is not averaged
with analyser estimates. If reports are unavailable, separate channel analysers
measure all 2048 float samples per channel; fallback loudness remains an
unweighted energy estimate, not EBU R128. The analysis result exposes
`loudnessAvailable`; the Equalizer shows unavailable when no fresh native loudness
report exists, rather than labeling that estimate LUFS. Both taps measure the
source mix before EQ and output gain. Ready worklet spectrum buffers remain authoritative during
UI reads. Native browser tests cover mono upmix, right-only, asymmetric and
opposite-phase stereo with the actual metering worklet and shipped WASM.


Phase visualization uses the latest 64 paired L/R samples captured together in
the metering worklet. Its correlation is the normalized cross-product over the
publication interval, without mean subtraction; it is unavailable when either
channel has zero energy. The SVG plots sum against difference (a goniometer),
not a phase angle derived from peak frequency. Paused, expired, reset, or
unavailable worklet data removes the trace. Separate analyser snapshots do not
claim paired phase data. Processing uses fixed sample buffers.

Playback normalization follows fresh native loudness reports independently of the
Equalizer screen. It measures the input mix before EQ, master gain, and listening
volume, and applies a separate smoothed correction limited to −20 through +6.02 dB.
It is continuous momentary adjustment, not a track-level ReplayGain scan or an
output limiter. Native readiness, non-silent input, and loudness above −60 LUFS
are required; fallback estimates do not drive correction. Disable, programme
reset, pause, expired reports, and teardown return correction to unity. Listening
volume and mute remain independent downstream controls. The displayed correction
is the commanded dB adjustment; the audio gain approaches it with smoothing.
