# Video playback controls

This reference follows client controls through durable desired state, request receipts
and execution generations. It records the ordering and recovery rules needed when
changing the player or playback API. Operators should begin with
[Transcoding](../../part-1-operator-guide/media/transcoding.md) and
[video state](../../part-1-operator-guide/media/video-transcoding.md).

## Playback controls and generations

Pause, resume, seek, audio-profile changes, progress and cancellation update durable desired state synchronously. The transaction applies each change to sibling renditions of the same client session and video. Other clients' sessions remain independent, including clients signed in to the same account. Durable per-session/request receipts prevent a repeated request from applying again after an intervening control. Controls have ordered revisions; playback progress includes the currently attached generation and revision so an old player cannot overwrite a later seek. Cancellation is terminal.

Seeking while paused preserves the paused state.

Native seek events carry the generation captured when the media element was
attached. The server checks it under the existing playback locks and ignores an
old event without changing the generation, revision or desired position. Explicit
seek requests remain new user intent. Progress separately requires both the
current generation and revision; it cannot overwrite a later explicit seek.

### Seeks while a previous seek is pending

When another native seek arrives before the previous one finishes, the player keeps the latest queued target. Pause and resume commands retain their order. A following native seek normally uses the generation acknowledged by its predecessor. If a seek encounters a temporary network, timeout or server failure, the player may resend that exact request once with its original identity and generation.

A successful resend without an accepted generation requires a fresh authenticated read started after that response before the latest unsent target can use the canonical generation. A read after a timeout alone cannot establish that delivery has settled. A successful first response without an accepted generation still abandons the queued tail. Stale, terminal or unproven responses cannot continue the chain.

If both transport attempts fail, the player abandons the unsent tail and restores the current execution and cursor from fresh authenticated state, preserving pause intent. It sends no third copy. A new gesture immediately reserves its place in the control queue. Consecutive unsent seeks coalesce to the latest target, while pause and resume commands keep their order between seeks. Submission waits for a fresh authenticated generation and uses a new request identity.

This resend policy applies only to transient seek failures; pause, resume and progress retain their separate recovery rules.

Replacing the source, session or player component invalidates pending intent;
generation checks and the latest pause/resume intent still apply.

### Progress, pause and resume after temporary failures

If a progress report fails because of a temporary network, timeout or server error,
the player keeps the media attachment, local position and play or pause intent. The next eligible report sends the current position rather than
replaying the failed update.

Temporary network, timeout or server failures during pause or resume preserve the
media and its current local position. The player keeps the requested status until
a fresh authenticated read can reconcile it with the server's accepted status;
newer local pause or resume intent takes precedence. Reconciliation changes only
play or pause status, without replaying the command, rewinding to an older server
position or submitting another seek. Initial signing failures and nontransient
control failures retain their existing handling.

A seek or recovered execution uses a new generation. The worker retires superseded work before acknowledging its effects. Manifests, init segments, media segments and subtitles carry that generation; stale files cannot satisfy current delivery requests.

Signing returns the current job, generation, revision, readiness, position and desired status. Once ready, it also returns `startTime` and source `duration`. A generation may begin at a nonzero source position. The player adds `startTime` to the media element's local clock for progress and subtracts it when restoring a source position. After a generation change it obtains the current signed manifest and restores position and paused state.

### Readiness and the quality ladder

Readiness requires a closed, available fragment at the desired playback cursor,
for running and paused sessions alike. Resuming a still-encoding rendition does
not bypass this check. A pause event caused by a media error does not persist a
new user pause.

The session's lowest tier alone decides readiness. Each other tier belongs to the signed rendition set only while it can keep up with playback. A tier that is still encoding joins once its published media leads the playback cursor by the join lead of 18 seconds, which is three segments, or reaches the end of the source. A finished tier joins at once.

A tier the player has attached stays while it leads the cursor by more than one segment, and is withdrawn when that lead has shrunk to 6 seconds, before the player reaches the end of what the tier published.

The player loads a new manifest whenever the set changes, which interrupts playback for a second or two. The two leads are two segments apart because the lead of an encoder running at playback speed swings by one segment with every publication; a single threshold would make such a tier leave and rejoin with each segment. A tier whose encoder runs slower than playback does not join while the cursor advances, so the movie plays in the lower tiers.

When the higher tiers of a movie do not appear, check the worker's encode speed for that tier before the player.

### Expired, failed and cancelled sessions

A refresh that names only ended sessions receives `410 Gone` with the reason. Sessions end as `expired` when [job cleanup](../../part-1-operator-guide/commands/app-transcode-job-cleanup.md) expired them as stale or removed their jobs. The player then starts new sessions and continues at its position, paused if it was paused. It does so again whenever sessions it has held expire.

Only when new sessions are reported ended before they answered a refresh or accepted a seek does the player show an error, so sessions that are gone at once cannot make it start in a loop. Sessions end as `failed` when an encode failed and as `cancelled` when they were cancelled on purpose. The player shows the error for both and starts nothing, so one failing encode is not restarted by every open tab and a cancellation is not undone.

A start that finds no capacity still receives `503` with `Retry-After`.

## Cinema controls and presentation choices

`MoviePlayerOverlay` presents a themed cinema console over the persistent
`VideoPlaybackRuntime`. Opening settings or chapters does not create another media
element. The console remains visible while paused, focused, showing a panel or
reporting an error; uninterrupted playback hides it after inactivity. Shared
controls retain keyboard labels and focus behavior. Fullscreen includes the
application document so portalled menus remain visible.

The persistent video host fills the available cinema area. The video scales within
those bounds while preserving its aspect ratio, leaving unused space when the
picture and player have different shapes. Display fit is independent of encoded
resolution: selecting a lower tier changes the decoded pixels, while the player
continues to fit the picture without cropping. Resizing and fullscreen use the
same containment behavior.

Quality selection uses delivery negotiation for original-file, copy, encoded and
native HLS playback. Auto keeps ordinary delivery selection and limits its
encoding ladder to the configured video encoder slots. A manual choice
requests a supported resolution tier through `qualityTier`; the source dimensions,
quality policy and destination capabilities limit the choices. The
playback-information resource supplies these choices independently of the current
manifest. Unknown dimensions and sources below the smallest supported tier retain
Auto without offering an upscale.

A compatible original or copy can satisfy a manual tier at the source height.
Otherwise delivery reuses an exact completed presentation or admits the requested
encode tier alone. It does not substitute a smaller cached rendition. The player
shows the pending request separately from the confirmed selection and current
output; failed admission does not confirm the requested quality. Transport and
volume commands continue through the listening session, and presentation settings
require the matching local video owner.

The playback-information API supplies scoped audio tracks, subtitle descriptions,
source chapters and preview state. The live-query coordinator samples this
information every five seconds while the player is visible; its dedicated sampled
descriptor covers preview changes that do not update catalog rows. Preview
preparation is queued through the
admitted media consumer. `VideoTimelinePreview` publishes a contact sheet only
after successful generation and source-identity verification; the HTTP image route
checks library scope again. A missing or failed preview leaves the seek slider
usable. The cache lives beneath `CONVERT_STORAGE_PATH`, shared by HTTP and workers.
`VideoTimelinePreview` limits each sheet to 100 frames of 160×90 pixels, ten
columns and 4 MB; generation has a 120-second timeout. It retains at most 64
sheets and removes cached files older than one day. Preparation claims expire
after ten minutes, failed generation can retry after five minutes, and the queue
accepts at most 256 outstanding claims. Existing claims remain visible as pending
when that limit is reached.

### Audio tracks and timing

Audio choices use absolute source stream indexes. `DeliveryRequest` and signed
transcode admission carry the selected index and `audioOffsetMs`. A nonzero audio
offset selects encoded delivery. `PresentationSelection` and the encoding
fingerprint include that offset, preventing reuse of a differently corrected
presentation. Worker encoding attributes and manifest language come from the
selected source track.

Both timing controls accept integer milliseconds from −10000 to +10000. Earlier
and Later change the offset by 100 ms; Reset changes only that control to zero.
Positive values mean later audio or subtitles, and negative values mean earlier.
`SegmentEncoder` applies audio correction against the source clock, including
preceding audio needed after a seek, and retains endpoint padding and trimming.
The subtitle adapter applies each correction to saved original cue times and
reconciles cues that arrive later.

The runtime keeps the source cursor and play/pause intent while replacing an
audio or quality presentation. Its reported source cursor uses the retained
position while the replacement resets the media element's clock. Both choices share the same transition revision,
so an older completion cannot overwrite newer intent. The UI distinguishes pending
and accepted settings. Selectors show the pending choice, allowing a return to
the accepted Auto or default audio setting while a replacement is being prepared.
Audio-track and timing changes retain the pending companion value; repeated
100 ms timing gestures accumulate against the pending offset.

Changes serialize cancellation and admission. Before starting a replacement or
recovering the accepted presentation, the runtime must successfully cancel any
session whose identifier it already holds. Copy negotiation returns that
identifier before signing, so even a replacement that fails during signing can
be cancelled. This ordering allows a single producer slot to serve successive
choices.

If cancellation fails, the player retains the accepted settings, source cursor
and play/pause intent. Its existing one-second poll queues another cancellation
attempt for the same session, with a five-second timeout for each request.
Admission waits for cancellation to succeed; newer intent or loss of playback
ownership prevents the old recovery from continuing. If preparation fails after
cancellation succeeds, recovery restarts the accepted presentation. A destination
handoff waits for pending presentation changes to settle and fails if cancellation
remains unconfirmed, so a late cancellation cannot overtake the handoff.

Settings survive seeks and attachment renewal for the same movie and reset when
a different movie is selected. Remote destinations keep transport controls;
presentation settings explain that they must be changed on the playing destination.

Closing the owner while an admission response is lost has a separate cleanup
limit: the server may already have admitted a session whose identifier the player
never received. That session can remain until server expiry. Cancelling it safely
requires a server-scoped cancellation protocol; cancelling the former owner's
whole session group after ownership changes could also cancel its successor.
