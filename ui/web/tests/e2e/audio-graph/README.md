# Native audio graph regression

Run from `ui/web`:

```sh
yarn exec playwright test -c tests/e2e/audio-graph/playwright.config.ts
```

The fixture bundles the actual `AudioProcessor`, substitutes its external WASM
loader, and creates its nodes in Chromium's native
`OfflineAudioContext`. A generated buffer enters the source gain and analyser
without depending on media decoding. Tests inspect the final 200 ms of a
one-second render so parameter smoothing settles naturally. They preserve all
processor gain automation and rebuild fades. Normalization is exercised separately
in a live AudioContext against the shipped loudness WASM, without Equalizer
mounted. It checks independent listening volume/mute, rebuilding, disabling, and
programme reset against measured output.

The fixture uses disposable HTTPS certificates and a loopback server at
`audio.baander.app`. Chromium maps that hostname to `127.0.0.1`; these tests never
contact the application. The runner needs OpenSSL and Playwright's Chromium.

For an additional agent-browser check against the same browser and renderer:

```sh
AUDIO_GRAPH_CDP_PORT=9237 AGENT_BROWSER_BINARY=/path/to/agent-browser \
  yarn exec playwright test -c tests/e2e/audio-graph/playwright.config.ts
```

This optional check uses an already installed CLI and asserts both rendered
channel gains. Choose an unused local CDP port.

`playback.spec.ts` also mounts the actual React `useAudioPlayback` hook with the
real player store, AudioService, and AudioProcessor. Three generated WAV tracks
are served by the disposable HTTPS server and decoded by native media elements.
It checks promotion through A → B → A, preload reuse via native `loadstart`
events, overlap before `ended`, active media controls, and manual interruption.
These checks establish element reuse and overlap; they do not measure a
sample-accurate gap between decoded tracks. External activity recording, EQ
state reapplication, and WASM analysis modules are fixture substitutes.

Additional native offline renders start a crossfade at context time two seconds
and inspect both channels before, during, and after the ramp, including
cancellation midway through the fade.

To prove the media regressions against an unchanged source revision, bundle an
isolated archive of its production sources while retaining the current tests:

```sh
AUDIO_GRAPH_SOURCE_REF=HEAD yarn exec playwright test \
  -c tests/e2e/audio-graph/playwright.config.ts playback.spec.ts
```

The archived files never replace or modify the working checkout.


Passive-mode coverage checks that unavailable audio capture produces neutral
buffers and no polling, rather than simulated measurements. This exercises the
real processor in Chromium; analysis modules remain fixture substitutes except
in the separate production WASM loader test.
