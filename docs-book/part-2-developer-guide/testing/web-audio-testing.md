# Web Audio Testing

## Web audio graph

Run `yarn --cwd ui/web test:audio-graph` after installing Chromium, Firefox and
WebKit with `yarn --cwd ui/web exec playwright install chromium firefox webkit`.
WebKit needs the host libraries that Playwright's Ubuntu images provide; on other
hosts, run the suite in the `mcr.microsoft.com/playwright` image of the installed
Playwright version, with `--network none`. The runner also requires
OpenSSL for its temporary HTTPS certificate. It serves a disposable fixture at
`audio.baander.app`, explicitly mapped to loopback; it does not contact the public
application.

These browser tests render the real `AudioProcessor` nodes with
`OfflineAudioContext` and compare stereo, processing order, and normalization
against reference signals. They replace the external WASM analysis modules;
they do not certify production codecs or audible clicks during live graph
changes. A separate loader check serves the production TypeScript loader and
shipped WASM to Chromium, checking instance memory and analysis-state isolation.

Stereo metering cases run the production metering worklet and WASM against native
mono, right-only, asymmetric, and opposite-phase signals, and compare the
independent-channel analyser fallback. The same native cases verify paired phase
samples and correlation; worklet tests also cover quadrature, silence, ring
ordering, and programme reset.

Native media tests use locally generated WAV files and the real playback hook,
store, service, and processor to exercise repeated preloaded handoffs, crossfade
overlap, promoted-element controls, and interrupted fades. One resume regression
delays a context-resume rejection across track reselection and verifies that the
newer native playback keeps advancing. Hook unit tests also cover deferred success,
native play rejection, and same-track replay. The active-media error regression
reloads an already playing element to a local 404 response, checks the real
Chromium `MediaError`, and verifies stop and reselection recovery.

Two specs cover a stream that the server cuts midway, as it does when the web
server [drains](../../part-1-operator-guide/maintenance/web-server-restarts.md#stopping-and-restarting-the-web-server).
`media-interruption.spec.ts` plays a plain `<audio>` element in Chromium, Firefox
and WebKit through a loopback proxy that resets the connection mid-body, for a
chunked response and for a Content-Length response. It asserts that each engine
reports an error or a stall, and that any `ended` event comes after an error:
Firefox reports the error, plays what it buffered and then fires `ended`. A clean
chunked end, which fires `ended` in every engine, is recorded for contrast. A
second test records how each engine reloads the same URL after an outage and
seeks to a saved position. `stream-recovery.spec.ts` runs the real player hook
and store in Chromium through an outage in which `/live` and the stream answer
`502`. It checks that the player shows it is reconnecting, holds its clock at the
saved position, resumes the same track there once `/live` answers, and records
one play. Playwright's Linux WebKit decodes media with GStreamer, not Safari's
media stack, so Safari's resume is a [manual check](runtime-drills.md#safari-resume-after-a-server-restart-manual).
These verify element ownership and timing, not sample-accurate gaplessness or
authenticated streaming.

Rebuild timing and worklet graph attachment also have unit regressions in
`audio-processor-rebuild.test.ts`. Deferred module and WASM loading, connection
replacement, and React StrictMode cleanup have processor, service, and playback-hook
regressions. Native browser lifecycle tests use small fixture worklets to verify
readiness and teardown; they do not replace tests of the production WASM
algorithms. The browser suite runs as a blocking step in the frontend workflow.

Run `bash scripts/test-dsp-analysis.sh` with Emscripten 6.0.3 to test the actual
WASM analysis modules and production worklet code against reference signals.
The gate rebuilds twice and checks reproducibility and shipped artifact parity.
See [DSP qualification](../../../packages/dsp/README.md) for covered contracts and
the remaining loudness and codec qualification limits.

The native audio graph suite also runs normalization against the shipped loudness
WASM without mounting Equalizer. It checks output attenuation, independent volume
and mute, chain rebuilds, disable, and programme reset. Unit coverage checks
settings restoration, native-readiness rejection, expiry, and passive playback
volume ownership.

## Spectrum and Equalizer calibration

The native `native-spectrum-calibration.spec.ts` under the audio-graph suite
compares the analyser with the shipped FFT/worklet at 44.1 and 48 kHz using
known offline tones and silence. The authenticated UI check is separate:

```bash
E2E_BASE_URL="$(make preview-url)" yarn --cwd ui/web e2e:headless \
  tests/e2e/styling/visualizer-calibration.spec.ts
```

Start or refresh the isolated preview first. The UI fixture renders all seven
modes across four moods from a local stereo WAV, with browser output muted and
service workers blocked so the fixture can intercept requests. Preference writes
receive local acknowledgments; authentication and preference reads remain real.
Keep system output muted too. The display measures input before EQ and gain; it does not
establish preference synchronization, acoustic, post-EQ or production
service-worker qualification. See the
[audio-graph guide](../../../ui/web/tests/e2e/audio-graph/README.md#spectrum-calibration-and-equalizer-display)
and [FFT contract](../../../packages/dsp/fft2048/README.md#display-spectrum) for the
numeric scale and the two test boundaries.
