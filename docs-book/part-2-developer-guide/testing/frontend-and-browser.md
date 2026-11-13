# Frontend, Browser, and Audio Testing

Unit, browser, audio-graph, authentication-transport, typography, store-debugger, Electron, and native Bånder Audio Core codec verification across the client workspaces.

## Frontend Testing

The web frontend uses Vitest:

```bash
cd ui/web
yarn test              # Run tests
yarn test:watch        # Watch mode
yarn test:coverage     # With coverage
```

For application browser tests, start this worktree's isolated preview and supply
its URL explicitly:

```bash
make preview-up
E2E_BASE_URL="$(make preview-url)" yarn --cwd ui/web e2e:headless
```

The runner does not provision an app or fall back to `baander.test`. Preview source
media is read-only and writable state is isolated; workers use the snapshot from
`make preview-up`. The local `.test` preview names are the explicit exception to
the `baander.app` fixture rule for this application browser workflow. Scripted
browser-auth and DSP suites retain their own disposable servers.
See [Frontend Development](../development/frontend-development.md) for more details.

For focused styling checks against that preview, run:

```bash
E2E_BASE_URL="$(make preview-url)" yarn --cwd ui/web e2e:headless \
  tests/e2e/styling tests/e2e/settings/preferences.spec.ts tests/e2e/layout/layout.spec.ts
```

The styling suite signs in through the real login flow. Deterministic catalog GET
fixtures and a local silent WAV cover empty and populated views and an active
player without library scans or authentication-store injection. Its DOM appearance
matrix checks all 32 mood/accent combinations; separate Settings tests exercise
saved preferences through the application. Playback and mocked command-palette cases
block service workers for deterministic fixture interception; login remains real.
Screenshots, geometry, keyboard focus,
reduced-motion and computed-contrast assertions cover selected routes, controls,
overlays and viewport sizes, not every application state. See the
[browser-test guide](../../../ui/web/tests/e2e/README.md) for scope and audio precautions.

The recessed music footer has a focused rendering check:

```bash
E2E_BASE_URL="$(make preview-url)" yarn --cwd ui/web e2e:headless \
  tests/e2e/styling/footer-material.spec.ts
```

It checks every mood/accent combination against pixels from the painted surface,
including the gradient, rather than treating the background as a flat color.
Samples come from the empty right padding at the labels' and controls' vertical
positions; artwork, glyph edges and shadow borders are excluded. This sampling
assumes the centerpiece's background varies vertically. A future horizontal or
localized reflection would require different sampling. The shared flat-background
contrast helper does not certify gradients.

The same spec captures desktop and narrow layouts with seek focus visible.
Geometry checks cover the compact footer height, vertical alignment and contained
controls. A desktop pixel check includes the lower lip when comparing the
centerpiece's top and bottom gaps.
Review those captures at normal size to judge the subtle recess and lower edge.
Browser pixels establish rendered contrast, not the near-black behavior of a
physical OLED panel; record hardware observations separately when available.

The Electron app has its own Node test suite, run with Node 26:

```bash
cd ui/electron
yarn test              # node --import tsx --experimental-test-module-mocks --test tests/*.test.ts
```

It covers the preload bridges, IPC validation and renderer trust, the
discovery and deep-link services, DSP asset regeneration and the native AAC
codec service (decoding, encoding in every profile including xHE-AAC with
its loudness metadata, and the loudness meter). The codec tests use the
compiled `@baander/audio-core` binding from `node_modules` or from a build in
the codec directory, and skip the binding-dependent cases when neither
exists. Without `tsx` the service test also runs under Node's own type
stripping (`node --experimental-strip-types --experimental-test-module-mocks
--test tests/aac-service.test.ts`); the IPC test does not, since its sources
import modules without extensions.

The codec itself has CTest suites and a binding smoke test:

```bash
cd packages/baander-audio-core
mkdir -p cmake-build && cd cmake-build && cmake .. -DCMAKE_BUILD_TYPE=Release && make && ctest
cd .. && npm install && npm test
```

The CTest suites check the standard tables, the MDCT, bitstream coding,
decoder behaviour on hand-built and damaged streams, encode/decode roundtrips
at every sample rate and rate-control mode, rate control, the coding tools
(noise substitution, intensity stereo, TNS) in the encoder's streams, and
interoperability with FFmpeg's encoder and decoder when `ffmpeg` is on
`PATH`; the interop suite skips otherwise.


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
against reference signals. They replace external WASM analysis modules;
they do not certify production codecs or audible clicks during live graph
changes. A separate loader check serves the production TypeScript loader and
shipped WASM to Chromium, checking instance memory and analysis-state isolation.
Stereo metering cases additionally run the production metering worklet and WASM
against native mono, right-only, asymmetric, and opposite-phase signals, and
compare the independent-channel analyser fallback. The same native cases verify
paired phase samples and correlation; worklet tests also cover quadrature,
silence, ring ordering, and programme reset. Native media tests use locally generated WAV files and the
real playback hook, store, service, and processor to exercise repeated preloaded
handoffs, crossfade overlap, promoted-element controls, and interrupted fades.
One resume regression delays a context-resume rejection across track reselection
and verifies that the newer native playback continues advancing. Hook unit tests
also cover deferred success, native play rejection, and same-track replay.
The active-media error regression reloads an already playing element to a local
404 response, checks the real Chromium `MediaError`, and verifies stop and
reselection recovery.

Two specs cover a stream that the server cuts midway, as it does when the web
server [drains](../../part-1-operator-guide/maintenance/upgrading.md#stopping-and-restarting-the-web-server).
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
authenticated streaming. Rebuild timing and worklet graph attachment
also have unit regressions in `audio-processor-rebuild.test.ts`. Deferred module
and WASM loading, connection replacement, and React StrictMode cleanup have
processor, service, and playback-hook regressions. Native browser lifecycle tests
use small fixture worklets to verify readiness and teardown; they do not replace
tests of the production WASM algorithms. The browser suite runs as a blocking
step in the frontend workflow.

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
modes across four moods using a local stereo WAV with browser output muted and
service workers blocked for fixture interception. Preference writes receive local
acknowledgments; authentication and preference reads remain real. Keep system
output muted too. The display measures input before EQ and gain; it does not
establish preference synchronization, acoustic, post-EQ or production
service-worker qualification. See the
[audio-graph guide](../../../ui/web/tests/e2e/audio-graph/README.md#spectrum-calibration-and-equalizer-display)
and [FFT contract](../../../packages/dsp/fft2048/README.md#display-spectrum) for the
numeric scale and the two test boundaries.

## Browser authentication transport

`yarn --cwd ui/web test:browser-auth` runs `ui/web/tests/e2e/browser-auth/`
against a scripted server on the loopback; it runs as a blocking step in the
frontend workflow. The Chromium project runs every scenario except the ones
tagged `@webkit`, with the named hosts `web.baander.app`, `api.baander.app` and
`foreign.baander.app` resolved to the loopback through a browser flag. The WebKit
project runs only the tagged scenarios, which check what WebKit's storage gives
back; WebKit has no such flag, so that project uses `localhost` and `127.0.0.1`
as two secure origins and skips the foreign host. These loopback names are the
one accepted exception to the `baander.app` rule in `AGENTS.md`. On a host
without a WebKit build, run the suite in the Playwright image with the
repository mounted and `node node_modules/@playwright/test/cli.js test -c
tests/e2e/browser-auth/playwright.config.ts` from `ui/web`.

The scripted server's refresh state machine (`refresh-state.ts`) answers refresh
tokens the way `/api/auth/refresh` does on PostgreSQL and is pinned to
`tests/Integration/RefreshTokenGraceTest.php`; it also answers logout, and an
outage mode makes refresh and logout answer `503` with `Retry-After` until the
test ends it. The suite checks that the service worker signs native image and
media requests with the page's DPoP credentials and sends none to a foreign
origin, that an expired token refreshes once and retries, and that a refresh
failure is bounded. Its modes cover a nonce challenge answered with one
re-signed proof, a `503` with `Retry-After` that keeps the session and retries
after the delay, a lost refresh response re-presented inside the grace window,
two tabs sharing one real credential store so the second tab adopts the pair the
first rotated instead of presenting a consumed token, and a service worker
stopped through CDP that pulls its credentials back from the page before signing
the next request.

The pending-logout scenarios run on the real auth store. A logout while offline
ends the session at once and is sent exactly once when the browser comes back
online; a logout during a server outage is kept and delivered on the next
trigger once the outage ends; a reopened page whose entry's access token expired
refreshes first and logs out with the new token; an entry whose chain was
revoked elsewhere is dropped after the logout `401` and the refresh refusal; two
pages of one origin send one logout for one entry; and the stored key of a
pending logout reads back after a reload and still signs a proof. Mock proofs
qualify the browser transport; they do not validate the server's DPoP
cryptography.

## Web typography

Run `yarn --cwd ui/web test:typography` for the typography harness at
`https://typography.baander.app:5188`, served with a temporary certificate and
mapped to loopback in Chromium, Firefox and WebKit. It renders the design-language
test strings in every type role, real song and column-browser rows, badges and
labels. It checks that Thai, Burmese, Hangul and CJK text has no letter-spacing,
that script line-heights and the Burmese size apply, that glyph ink stays inside
fixed rows, that the element's language picks the Han glyph family, and that every
character renders from a bundled Geist or Noto face. A Latin-only page checks that
no Thai, Burmese, Korean or CJK font file is requested. The frontend workflow runs
this gate. WebKit needs its Playwright system libraries; on hosts without them, run
the harness in the `mcr.microsoft.com/playwright` image. Port 5188 must be free.

`ui/web/tests/typography/census.test.ts` runs with the unit suite. It fails on
literal font sizes, light weights, literal tracking, families other than the two
stacks, and the 11px dense size outside the allowlisted admin tables, badges and
chips.

## Web store isolation and debugging

Run `yarn --cwd ui/web test:store-debug` for the disposable Chromium workflow at
`https://debug.baander.app:5187`, mapped to loopback with a temporary certificate.
It checks production selection/view-mode stores, render isolation, no-op updates,
and enabling, inspecting, exporting, and disabling the store debugger. The frontend
workflow runs this gate. Local prerequisites are Chromium and OpenSSL, as for the
audio graph tests; port 5187 must be free.

In a development build, enable the debug panel from Diagnostics, open Store Timeline
or Store Inspector, and select **Enable store tracing and reload**. Recording is
opt-in through `baander-store-debug` in local storage and is disabled in production
builds. Disabling also reloads, removing tracing wrappers instead of keeping a
conditional branch on every action. Enabling recording incurs snapshot/stack costs.

The timeline exports schema-version-2 JSON with ordered events, recorder instance
IDs, current captures and a history baseline. Each admitted store records a
registration capture after successful creation; construction-time intermediate
states are outside the recorded history. Duplicate display names have distinct instance IDs. Explicit
`unregisterStore(instanceId)` retirement releases observation and removes that
instance from later historical points and the current Inspector. Retirement does
not happen automatically when a component unmounts.

Select a retained event to inspect **Historical store captures** for every recorded
instance present at that point. Reconstruction folds the full retained history
through the selected event, even when the timeline filter hides intervening events. It uses
the baseline and recorded captures, without reading newer live state, changing
stores or running effects. Update before/after captures still belong to the selected
event. Clearing or evicting that event removes selection. Collections over 50
instances use a keyboard-accessible virtualized scroll region; rows keep their
expanded or collapsed state while scrolling.

Evicted events advance the baseline to the start of the retained window. Clearing
the timeline starts a new baseline epoch from current recorded captures; instance
and event IDs are not reused. At most 100 active instances are admitted. Omitted
registrations remain uninstrumented and increase the visible cumulative omission
count, which persists across clearing and retirement. A nonzero count marks
reconstruction as partial even when each displayed capture is complete. Retiring
an admitted instance frees a slot for a later registration.

Synchronous nested calls retain parent links. Async completions link to their
originating action; updates after an `await` retain their call stacks without
inferred parentage. Redacted, truncated and native values make captures incomplete.
Even complete captures are sanitized diagnostic values, not executable application
state. Reconstruction does not restore a running application or replay network or
media effects. Schema v1 has no compatibility conversion, and trace import is not
supported.

The timeline can verify one retained `catalog.selection` `select` or `clear`
action, including a successful no-op. It calculates the expected result with the
current pure transition shared by the live store, using detached captured data
and a trusted descriptor held by the recorder. Verification requires complete
starting-state, argument, update and terminal captures, matching transition
identity/version, and an unambiguous successful synchronous invocation. Nested,
foreign or unattributed updates and broken invocation boundaries make verification
unavailable. Results are match, mismatch or unavailable with a reason; a no-op
remains an explicit recorded action even when it emits no update.

Verification leaves live stores and recorder history unchanged and runs no live
effects. It supports neither async replay nor trace import. Schema-version-2
records without the optional invocation provenance remain inspectable but are
unavailable for verification. See the
[transition verifier](../../../ui/web/src/shared/stores/verify-transition.ts) for the
admission checks.

Unit tests verify creator/action identity when tracing is disabled, no-op subscriber
and persistence behavior, secret redaction, immutable snapshots, and event ordering.
They also cover instance registration and retirement, nested subscriber mutations,
baseline eviction and clear, admission omissions, and reconstruction without effects.
Player/session tests additionally cover clock isolation and sync under continuous
playback. Performance assertions use counts rather than timing-sensitive thresholds.

