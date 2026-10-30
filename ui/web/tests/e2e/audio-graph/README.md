# Native audio graph regression

> **Audio warning:** The live playback tests can produce audible tones through
> speakers or headphones. Lower or mute system output before running them; tests
> may change player volume. Headless mode does not guarantee silent output.
> Offline rendering cases do not play sound.

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

Connection recovery uses a genuine native source-ownership conflict. It checks
partial capture, replacement of either media element, and reuse of previously
captured pairs. Processor tests also inject allocation and wiring failures to
verify that a working graph survives a failed replacement. Connection failures
are rejected rather than relabeled as passive playback: Web Audio capture cannot
be undone by disconnecting a node. Playback stops when no usable graph remains;
a later load or play event can retry. An element captured by another context
requires replacement or recovery by its owner.

Next/previous failure tests return HTTP 404 for the selected WAV and assert that
native `play()` rejection clears the playing state. Store tests resolve or reject
superseded play promises to check selection ownership and activity reporting,
including navigation away and back to the same track. Because these 404 responses
also fail transcoded requests, the player's transcoded retry fails as well.

The transcoding fallback test answers the original stream with bytes Chromium
cannot decode. It checks that the player requests `format=opus` and plays the
fixture WAV. The fixture ignores `format`, so the test covers how the request is
built, not the server's encoder.

Repeat-one coverage observes calls at the activity-service boundary across native
ended/replay cycles, checks that the source loads once, and checks that pause and
resume add no play event. Activity-service unit tests verify one API submission
per caller-owned playback attempt, including consecutive plays of the same song
and independent request failures. Delivery remains best effort; an uncertain
network failure is not automatically retried.

Stale-notification tests dispatch obsolete `play` and `ended` notifications after
pausing or selecting a new source. Chromium supplies the actual current media
state; handlers must ignore notifications that disagree with it. The existing
native-ended, gapless, crossfade, and repeat tests retain coverage for genuine
events. Unit fixtures explicitly model paused/ended state for genuine events and
leave stale notifications inconsistent to exercise the guards.

`media-interruption.spec.ts` checks how Chromium, Firefox and WebKit report a
stream that the server cuts midway. Each browser reaches `audio.baander.app`
through a loopback HTTP proxy that the test serves itself and never forwards.
A plain muted `<audio>` element plays `fixtures/tone-4s.mp3` joined three times,
an MP3 without a Xing header as a progressive encode writes it. The server stops
the body at 6.5 s and, once the element has played one second, resets the TCP
connection without a terminating chunk. It does this for a chunked response
without ranges and for a Content-Length response with ranges, and answers later
requests with a 502 (nginx in front) or a reset (no proxy). The test asserts an
error or a stall, and that any `ended` event comes after an error: Firefox reports
the error, plays what it buffered and then fires `ended`, and the player lets the
error win. A clean chunked end, which fires `ended` with no error in every
engine, is recorded for contrast.

The second test stops the server so connections are refused, reloads the same
URL, starts the server again and seeks to the saved position. It records whether
the position was seekable while paused or only during playback, for an original
file and for a progressive rendition that arrives complete after the restart.
Every outcome is printed and attached as JSON. The test does not run the player
hook or store.

`stream-recovery.spec.ts` runs the real player hook and store in Chromium through a
server outage. The fixture serves a 20-second WAV in a burst and then at 1.25 times
real time. While the test holds the fixture down, it cuts that stream without
finishing it, and answers `/live` and the stream with 502, as nginx does while the
web container restarts. The test checks that the player shows it is reconnecting,
keeps its clock at the saved position, resumes the same track at that position
once `/live` answers, and records one play. The app probes `/live` through its
shared API client; the fixture substitutes a `fetch` of the same endpoint with the
same rule, because the bundle leaves out the authenticated client.

The suite needs Playwright's Firefox and WebKit as well as Chromium. WebKit needs
the host libraries Playwright's Ubuntu images provide; on other hosts run the
spec in `mcr.microsoft.com/playwright` at the installed Playwright version, with
`--network none`. Playwright's Linux WebKit uses GStreamer, not Safari's media
stack.


## Spectrum calibration and Equalizer display

`native-spectrum-calibration.spec.ts` compares the native analyser with the
shipped FFT and worklet at 44.1 and 48 kHz. Offline signals cover amplitudes
1, 0.1, 0.01 and 0.001, low/middle/near-Nyquist frequencies, off-bin tones and
silence. It checks peak positions, dB agreement and worklet metadata. These
samples never reach audio hardware.

The separate `tests/e2e/styling/visualizer-calibration.spec.ts` uses the running
application to check all seven Equalizer modes in four moods, visible signal
marks, measured RMS meters, pause/resume, mode switching and resizing. From the
repository root, start the isolated preview and pass its URL explicitly:

```sh
make preview-up
E2E_BASE_URL="$(make preview-url)" yarn --cwd ui/web e2e:headless \
  tests/e2e/styling/visualizer-calibration.spec.ts
```

That UI fixture uses real login and a generated local stereo WAV, blocks service
workers for deterministic interception, and mutes browser output. Preference
writes are acknowledged locally so shared-account conflicts cannot interrupt
mode/theme checks; preference reads and authentication remain real. Keep system
output muted too. Its observed analysis is input before EQ and gain; these checks
do not qualify preference synchronization, production service-worker transport,
acoustic response or a post-EQ measurement.
