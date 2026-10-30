# Browser tests

> **Audio warning:** Playback tests can produce audible tones, music or other media
> through speakers or headphones. Lower or mute system output before starting.
> Tests may change player volume, so muting only the player is not a reliable way
> to silence a run. Headless mode does not guarantee silent output.

This applies to the general end-to-end suite (`yarn e2e` and `yarn e2e:headless`),
`yarn test:audio-graph`, the two-command browser checkpoint, and manual playback
checks against a running instance. These Yarn commands run from `ui/web`.

Warn anyone nearby before an audible check. When checking decoding, timing or
controls without listening, mute system output. Keep the application's volume and
mute controls available to the tests. Record output muting in manual verification
evidence; muted output does not establish audible quality.

The [audio graph guide](audio-graph/README.md) describes its native playback and
offline-rendering cases. Offline rendering and mocked player unit tests do not
play sound.

## Application styling checks

Start this worktree's preview, then pass its URL explicitly:

```bash
make preview-up
E2E_BASE_URL="$(make preview-url)" yarn --cwd ui/web e2e:headless \
  tests/e2e/styling tests/e2e/settings/preferences.spec.ts tests/e2e/layout/layout.spec.ts
```

Refresh the preview with `make preview-up` after application changes. The runner
requires `E2E_BASE_URL`; it does not start the app or select a shared instance.

The styling specs use real login. Catalog GET fixtures supply deterministic empty
and populated views, and active-player checks serve a local silent WAV without
scanning libraries. They do not inject authentication-store state. The appearance
matrix changes root DOM attributes to check all 32 mood/accent renderings;
Settings tests separately exercise preference persistence through the application.
Only the mocked command-palette search group disables service workers so WebKit
can intercept its catalog fixtures. Login still uses real HTTP authentication;
playback cases retain the stream service worker.

Coverage includes login controls, settings and form overlays, populated catalog
and player views, administration forms, and selected shell routes. Screenshots
support visual review; geometry, focus, reduced-motion and computed-contrast
assertions check the selected states and viewport sizes. These checks do not
establish visual or accessibility coverage of every application state.
