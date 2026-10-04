# DSP analysis qualification

Run `bash scripts/test-dsp-analysis.sh` from the repository with Emscripten
6.0.3 and Node.js on `PATH`. The gate builds `fft2048`, `spectral_features`, `dynamics_meter`, and
`loudness_r128` twice in temporary directories, runs analytical reference vectors against each
build, compares the binaries, and checks that the source-package, web-served,
and Electron public WASM artifacts match. It also checks that the web-served FFT
worklet and dynamics loader match their source-package copies. It leaves tracked files unchanged
and fails on a missing compiler, different compiler version, failed vector,
or stale artifact.

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
at 44.1 and 48 kHz. Integrated gating, loudness range, and true-peak
reconstruction still require correction and independent vectors; see the
[loudness module](loudness_r128/README.md) for the remaining limitations.
This gate does not establish resampling or
convolution quality, native AAC codec compliance, or audible playback quality.
