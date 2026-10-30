# FFT analysis

`fft2048` is a standalone WASM module with a 2048-sample transform and 1024
magnitude bins. Its utility and display entrypoints have different contracts.

## Display spectrum

`process_display_spectrum` uses a periodic Blackman window and 1/N FFT scaling
to match Web Audio's analyser with smoothing disabled. Magnitudes are encoded
as bytes over −100 to 0 dB: decode a byte as `-100 + byte * 100 / 255`.
Zero includes silence and values below the floor. A full-scale, bin-centred sine
peaks near −13.56 dB; these are windowed spectrum magnitudes, not RMS dBFS.

The `wasm-spectrum` worklet publishes all 1024 bins with `sampleRate`, `fftSize`,
`minDecibels` and `maxDecibels`. Bin frequency is `bin * sampleRate / fftSize`;
`timeDomainData` contains 2048 waveform samples encoded over 0–255. The worklet
observes the player's input before EQ and gain. Its mono downmix can cancel
opposite-phase stereo; independent stereo meters and paired phase data retain
that distinction.

The web display groups peak levels into logarithmic bands from 20 Hz to the
lower of 20 kHz and Nyquist. It excludes DC and out-of-range bins. Spectral-feature
consumers decode dB bytes to linear floats before calling the float-input API;
encoded dB bytes are not linear magnitudes.

## Utility spectrum

`process_spectrum` retains its linear-magnitude byte output, 2/N scaling and
selectable unwindowed or Hann-windowed transform. The streaming utility API and
its independent DFT reference tests retain that contract. It is not the web
display's numeric format.

## Build and demo

From this directory, `make build` builds the module and `make serve-demo` serves
`demo.html` on local port 8000. The demo plays an embedded tone or microphone
input and draws a reduced spectrum and waveform. It can produce audible output.
The worklet throttles posts and sends compact buffers to limit message and
allocation costs.

For shipped artifacts, follow the [DSP qualification workflow](../README.md).
It rebuilds with Emscripten 6.0.3, compares two builds, tests the actual WASM and
checks the web/Electron copies and worklet. A local demo is not calibration or
playback qualification.
