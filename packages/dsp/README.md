# DSP analysis qualification

Run `bash scripts/test-dsp-analysis.sh` from the repository with Emscripten
6.0.3 and Node.js on `PATH`. The gate builds `fft2048`, `spectral_features`, `dynamics_meter`, and
`loudness_r128` twice in temporary directories, runs analytical reference vectors against each
build, compares the binaries, and checks that the source-package, web-served,
and Electron public WASM artifacts match. It also checks that the web-served FFT
worklet and dynamics loader match their source-package copies. It leaves tracked files unchanged
and fails on a missing compiler, different compiler version, failed vector,
or stale artifact.

The gate also builds an unshipped small-capacity gating harness. Its tests check
exact threshold queries, tree balancing, and explicit exhaustion/reset behavior.
The production pool remains fixed at 262144 distinct energies; the harness
does not change the deployed ABI or memory limit.

After an intentional source change, regenerate the affected artifacts before
running the gate:

```sh
for module in fft2048 spectral_features dynamics_meter loudness_r128; do
    make -B -C "packages/dsp/$module"
    cp "packages/dsp/$module/$module.wasm" "public/dsp/$module.wasm"
    cp "packages/dsp/$module/$module.wasm" "ui/electron/public/dsp/$module.wasm"
done
cp packages/dsp/fft2048/wasm-spectrum.js public/audio-worklets/wasm-spectrum.js
cp packages/dsp/dynamics_meter/dynamics_meter.js public/dsp/dynamics_meter.js
cp packages/dsp/dynamics_meter/dynamics_meter.js ui/electron/public/dsp/dynamics_meter.js
bash scripts/test-dsp-analysis.sh
```

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
This gate does not establish resampling or
convolution quality, native AAC codec compliance, or audible playback quality.


The web loader shares compiled WASM modules, while each getter call creates an
independent instance and memory. Its tests use shipped binaries to check state
isolation, concurrent compilation, failed-load retries, and cache reset races.
Clearing the compilation cache never resets live measurements. The main-thread
processor owns only spectral analysis; loudness and dynamics run in its worklet.
The standalone JavaScript demo loaders are separate from this web loading path.


Playback without a captured audio source exposes neutral buffers and unavailable
measurements. It does not run a background analysis worker or fabricate channel
levels. Active analysis uses the spectrum worklet with the native analyser as
fallback; both feed the processor's owned spectral module.
