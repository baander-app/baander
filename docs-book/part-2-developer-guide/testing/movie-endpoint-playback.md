# Movie endpoint playback qualification

This check exercises the selected-video endpoint through the production browser and worker image. The [full lifecycle drill](playback-lifecycle.md) remains separate.

## Selected video endpoint playback

Run the dedicated production-browser check against the production worker image
built from the current checkout:

```bash
BAANDER_TEST_IMAGE=baander-worker:test BAANDER_TEST_DOCKER_SOCKET_PATH=/var/run/docker.sock \
  bash scripts/test-movie-endpoint-browser.sh
```

The wrapper selects `BAANDER_TEST_MOVIE_ENDPOINT=1` in the disposable two-command
stack, with a 300-second outer bound. Its phases are authenticated,
movie-endpoint and endpoint-complete; the existing full lifecycle drill remains
separate. A real 42-second video with 48-second audio must advertise duration 42
through the signing response and actual movie slider. The observer reads those
values from the application rather than supplying fixture-known playback
metadata. It uses the real near-end/exact-end slider controls, natural completion
and source-zero replay with advancing decoded frames. The paired prior-resolver
run must retain its actual 48-second failure.

During the bounded natural-end tail, capability renewal and strict rendition-set
expansion may replace attachments within the same job, session and generation.
Each attachment must supply its genuine metadata/frame baseline before later
advancing frames and natural completion are compared. Source-clock continuity
and existing timing tolerances remain required; an attachment replacement alone
is not a playback defect. This is endpoint/completion/replay evidence, not the
full outage drill or uninterrupted playback of all 42 source seconds.

Keep signed delivery, real publication and verified worker/container cleanup
receipts. This check qualifies the calibrated metadata and source-clock paths;
missing/stale timing, arbitrary muxer tags, additional origins and untested
browser/format combinations remain outside exact-end acceptance. The separate
40 ms capture mismatch and bounded diagnostic follow-up remain unresolved.

