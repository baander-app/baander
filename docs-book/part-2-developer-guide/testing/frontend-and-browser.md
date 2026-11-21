# Frontend, Browser, and Audio Testing

Use this page to run web unit tests and application browser checks against an
isolated preview, and to test the Electron app and native Bånder Audio Core
codec. Use the focused guides for other browser checks:

- [Web audio testing](web-audio-testing.md): audio graph, spectrum and Equalizer calibration.
- [Authentication and typography harnesses](browser-harnesses.md): isolated transport and font checks.
- [Web store debugging](web-store-debugging.md): store isolation, tracing and historical captures.

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

Checks that use the shared seeded administrator assign a fresh browser device
token to each context and remove only that run's device registrations after the
browsers close. The fixture verifies that `E2E_BASE_URL` matches this worktree's
managed preview. Cleanup also runs after test failure or logout; a cleanup failure
fails the run and retains its ownership manifest. Existing saved devices and
historical rows remain untouched. The lifecycle is described in the
[browser-test guide](../../../ui/web/tests/e2e/README.md#saved-browser-devices).

For focused styling checks against that preview, run:

```bash
E2E_BASE_URL="$(make preview-url)" yarn --cwd ui/web e2e:headless \
  tests/e2e/styling tests/e2e/settings/preferences.spec.ts tests/e2e/layout/layout.spec.ts
```

The styling suite uses the real login flow with a fresh server fixture user. The
`emptyCatalog` and `populatedCatalog` fixtures in `tests/e2e/styling/fixtures.ts`
create a fixture run and sign its admin in. They set `superAdmin` so administration
specs can reach views only a super admin sees.

The fresh account has no listening session or saved preferences. The preview's
own accounts therefore cannot change what a test sees. Deterministic catalog GET
fixtures cover empty and populated views without library scans or
authentication-store injection. The listening session accepts only songs the server
knows and answers 422 to any other, so the populated catalog puts its long and
non-Latin titles on three 30-second songs the fixture's `catalog` operation creates;
album and playlist IDs stay invented. Because every test starts and removes a fixture
run through the preview's fixture executor, the suite runs one worker at a time. Its DOM appearance
matrix checks all 32 mood/accent combinations; separate Settings tests exercise
saved preferences through the application. Playback and mocked command-palette cases
block service workers so the fixtures can intercept requests deterministically;
login remains real. Screenshots, geometry, keyboard focus, reduced-motion and
computed-contrast assertions cover selected routes, controls, overlays and viewport
sizes, not every application state. See the
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
centerpiece's top and bottom gaps. Review those captures at normal size to judge
the subtle recess and lower edge.
Browser pixels establish rendered contrast, not the near-black behavior of a
physical OLED panel; record hardware observations separately when available.

The footer specs sign in as a server fixture user and play that fixture's own song,
through the `pausedFooter` fixture in `tests/e2e/styling/footer-playback.ts`.

The well handoff between the footer and the sidebar notification pane has its own
spec:

```bash
E2E_BASE_URL="$(make preview-url)" yarn --cwd ui/web e2e:headless \
  tests/e2e/styling/well-handoff.spec.ts
```

It reads painted pixels at 390px and 1280px in the four moods. In the open pane it
samples the inset upper edge and the lower lip. In the footer centerpiece it samples
the empty right padding, which is shaded with a lip at rest and spans at most one
level while the pane is open; a flat face can span one level because the browser
dithers the gradient it is painted with. The spec also checks four things: label
contrast over the pane's shading and on the flat centerpiece; that the pane's fade
and the centerpiece's shading report the same duration and easing; that both are
immediate when reduced motion is requested; and that Escape returns focus to the
bell. A last case checks that the admin bell's outage entry, which holds no well, keeps
the muted text colour; it lists the entry at once with `listOutageEntry` from
`tests/e2e/live-updates/recovery-fixtures.ts`, without waiting the two counted minutes
the application waits.

`tests/e2e/settings/sign-in-conflict.spec.ts` signs one fixture user in on a second,
fresh browser while the first holds a listening session, and holds the second
browser's first read of the layout or player preferences for three seconds, so the
session's track or volume always arrives first. Preference writes stay real in both
browsers: a write refused for its stale version is one way a "Settings conflict"
dialog appears, and a stubbed write would hide it.

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
