# Native audio graph regression

Run from `ui/web`:

```sh
yarn exec playwright test -c tests/e2e/audio-graph/playwright.config.ts
```

The fixture bundles the actual `AudioProcessor`, substitutes its external WASM
loader and analysis worker, and creates its nodes in Chromium's native
`OfflineAudioContext`. A generated buffer enters the source gain and analyser
without depending on media decoding. Tests inspect the final 200 ms of a
one-second render so parameter smoothing settles naturally. They preserve all
processor gain automation, including normalization and rebuild fades.

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
