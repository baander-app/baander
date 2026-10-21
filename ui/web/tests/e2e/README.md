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
