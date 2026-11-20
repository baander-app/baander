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
