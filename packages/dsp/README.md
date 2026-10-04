# DSP analysis qualification

Run `bash scripts/test-dsp-analysis.sh` from the repository with Emscripten
6.0.3 and Node.js on `PATH`. The gate builds `fft2048` and `spectral_features`
twice in temporary directories, runs analytical reference vectors against each
build, compares the binaries, and checks that the source-package, web-served,
and Electron public WASM artifacts match. It also checks that the web-served FFT
worklet matches the source-package worklet. It leaves tracked files unchanged
and fails on a missing compiler, different compiler version, failed vector,
or stale artifact.

After an intentional source change, regenerate the affected artifacts before
running the gate:

```sh
make -B -C packages/dsp/fft2048
cp packages/dsp/fft2048/fft2048.wasm public/dsp/fft2048.wasm
cp packages/dsp/fft2048/fft2048.wasm ui/electron/public/dsp/fft2048.wasm
make -B -C packages/dsp/spectral_features
cp packages/dsp/spectral_features/spectral_features.wasm public/dsp/spectral_features.wasm
cp packages/dsp/spectral_features/spectral_features.wasm ui/electron/public/dsp/spectral_features.wasm
cp packages/dsp/fft2048/wasm-spectrum.js public/audio-worklets/wasm-spectrum.js
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
buffering, and spectral features used by the web player. It does not
establish loudness compliance, dynamics metering accuracy, resampling or
convolution quality, native AAC codec compliance, or audible playback quality.
