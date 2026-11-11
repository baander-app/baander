# Concepts

> Shared domain vocabulary for this project — entities, named processes, and status concepts with project-specific meaning. Seeded with core domain vocabulary, then accretes as ce-compound and ce-compound-refresh process learnings; direct edits are fine. Glossary only, not a spec or catch-all.

## Identity

### Internal UUID

The persisted identity used inside the application for primary keys, foreign keys and domain events. Public transport contracts do not expose or require it to identify an entity.

### Public ID

An entity's independent public identity, used by public routes, relationships, cursors and realtime messages instead of its internal UUID.

A contextual name for an entity still carries its public ID. Resolving it does not grant access to the entity.

### Browser device token

The client-generated identity of a browser installation within an account, used during device registration and session claims.

It is distinct from the device resource's public ID, which addresses device management and identifies a listening session's active device.

### EQ audio-output token

An optional client-defined identity for the audio output associated with an equalizer profile.

It does not refer to the persisted browser device resource. The profile itself has a public ID.

### OAuth client identifier

The registered OAuth client's public ID, used in authorization requests, administration and access-token claims.

It is separate from a token identifier or secret.

## Local development

### Persistent development instance

The configured local Baander instance that automatically follows the main development branch and retains its libraries, accounts and settings through ordinary updates.

It runs independently of source edits and branch switches in development worktrees.

### Worktree preview

A local Baander instance for one development worktree, with its own hostname and
application state. It supports feature verification without changing the persistent
development instance or another worktree's preview.

### Preview worker snapshot

The immutable worker build captured from a worktree when its preview is refreshed.
Live web edits can be newer than this build; preview status identifies the active
worker snapshot so integrated verification can account for that difference.

## Web server lifecycle

### Drain

The web server's single way of stopping: whatever asked it to stop, it stops taking new connections, lets the work it already has finish within a bounded time, tells clients to come back, and exits on its own.

A drain has a deadline, which an operator configures, and a shorter drain window inside it. During the window, client connections are still open: requests in progress and file downloads finish, real-time connections are closed with a code that tells clients to reconnect, the readiness probe reports not ready, and the liveness probe still answers but says the server is draining. Streams still running when the window closes are cut so that the client sees an error rather than a normal end, which lets a player resume instead of skipping to the next track. A drain runs once however many stop requests arrive. Anything that has not stopped by the deadline is killed, and the server then exits with a failure status.
*Avoid: graceful shutdown (for this process specifically), stop.*

### Worker reload

Replacing the web server's worker processes while the server itself keeps running and keeps its client connections. A reload never starts a drain, and a reload requested during a drain is ignored.

## Authentication

### Passkey user handle

The user's public ID encoded for passkey registration, authentication and account signals.

It identifies the account independently of the authenticator's credential ID.

### Refresh token chain

The sequence of refresh tokens one sign-in produces through rotation. Each rotation consumes the presented token and issues a replacement that links back to it; every token in the chain is bound to the same DPoP key. Revoking the chain ends that sign-in everywhere it was in use and closes the WebSocket connections it opened; it is what logout and reuse detection do.
*Avoid: token family (the vendor term).*

### Consumed refresh token

A refresh token that a rotation has already used. It stays stored, marked with the instant it was consumed, so a later presentation can be judged: a lost-response grace or a reuse.

### Lost-response grace

The bounded opportunity to recover a lost token-rotation response by presenting the consumed refresh token again with the same DPoP key while its replacement remains unused.

Recovery issues a fresh pair and revokes the unused replacement. Any other presentation of a consumed token is a reuse, which revokes the chain. The operator can disable the grace; its window is measured on the database clock.

### DPoP store outage

The state in which the shared store behind DPoP nonce and replay checks cannot answer.

The checks stay fail-closed: no proof is accepted, but the server reports temporary unavailability with a retry delay rather than a nonce challenge or a denial, so clients keep their session and retry. Logout and revocation are the exception: they accept a proof whose replay check could not run once every other check passed, log one warning, and revoke the chain.

### Pending logout

A logout the browser has recorded but the server has not yet confirmed. The tab ends its session locally at once; the record keeps the DPoP key pair and the tokens of the ended session so the logout can be signed and sent again on the next start or when the browser is back online, until the server answers that the chain is revoked or gone.

### WebSocket handshake ticket

A single-use credential obtained through an authenticated, DPoP-protected API request that authorizes one WebSocket upgrade bound to the requesting user, access token and browser Origin.

Its short lifetime cannot outlast the access token. Redemption consumes it atomically and checks current token and user state. A ticket is a bearer capability during that window; it does not sign later WebSocket messages.

### Socket reconciliation

The periodic check that closes WebSocket connections whose refresh token chain has no live access token or no longer exists.

The chain is the unit because rotation revokes the token a socket was opened with while the sign-in continues; rotation alone never closes a socket. Logout, revocation and reuse verdicts close sockets immediately; reconciliation provides a bounded backstop for changes made elsewhere and for expiry without refresh.

### Signed delivery URL

A video manifest or segment URL that carries an HMAC signature and an expiry instead of requiring a token. It is a bearer capability: whoever holds a valid one is served, with or without a session, until it expires or the signature fails; the signing endpoint issues one only to a signed-in member of the video's library. A token presented with it must still be valid, and the DPoP proof is not read on delivery routes.

Every child playlist, initialization file, media fragment and subtitle URL inherits the manifest's absolute expiry. Reading a playlist cannot extend it; an authenticated signing refresh can issue a new top-level URL. Expiry refuses new delivery requests after the deadline without revoking bytes already admitted or buffered by the player.

## Playlists

### Playlist collaborator

A user the playlist's owner has named on a playlist. While the playlist is collaborative, a collaborator may view it and add, remove and reorder its songs, adding only songs their own library membership lets them see; when the owner turns collaboration off, collaborators keep viewing and lose editing. The owner alone changes the playlist's metadata, deletes it and names collaborators; a collaborator may leave; an administrator who is not the owner may view it and nothing more.

### Playlist revision

An opaque marker of the complete playlist state returned with its displayed song order. A reorder must present that revision; if the playlist changed, the caller reloads it before choosing an order again. Hidden songs affect the revision without exposing their identifiers.

## Rate limiting

### Limiter generation

The shared identity of one named limiter's state, replaced when that limiter is cleared.

Replacement makes previously read state unwritable without scanning subjects. Consumers retry against the new generation; other named limiters retain their own state.

### Limiter revision tombstone

An empty subject state whose opaque revision outlives the snapshots that could otherwise mistake it for an earlier absence.

An absent read or per-subject reset retains this marker so a paused writer cannot restore stale state after expiry.

## Provider requests

### Public destination policy

The destination check shared by metadata, radio station catalog and push transports, admitting approved HTTPS hostnames on the standard secure port only when every resolved address is public.

It pins an approved address while preserving TLS hostname verification and has no LAN exception. Webhooks and operator-configured lyrics retain separate policies.

## Media availability

### Library

A watched directory whose discovered media populates the catalog and whose membership determines a user's access to its media.

### Accepted directory

A discovered directory whose file-index updates and ingestion intent committed
together. The scan summary counts these directories as queued. Acceptance does
not mean the asynchronous transport has received the message or catalog ingestion
has finished; the outbox relay owns that handoff and its bounded retries.

### Available source

A library file whose latest successful discovery recorded it as present. A completed
scan or queued import that finds the file missing marks its retained index record
unavailable. A later scan restores availability and queues the returning file even
when its content is unchanged. This observation does not diagnose a failed mount
or guarantee that the file is still reachable at playback time. A catalog track
without an index observation is unavailable until a scan confirms its source.

## Playback destinations

### Playback destination

The Bånder client or external receiver selected to play a listener's media.
An external receiver may use AirPlay, Chromecast or DLNA; it is not necessarily a registered Bånder device.

### Local casting companion

A Bånder component on the receiver's network that supplies discovery and control when the browser or remote server cannot provide them directly.
It connects the listener's home receivers to playback controlled through Bånder.

### Playback endpoint

A live player instance that can execute and report playback for a destination, such as one browser tab, a companion process or a Cast receiver application.
Its incarnation identifies that instance's current connection lifetime, separately from the saved browser device token or destination identity. When the same browser page registers again, as after a replaced socket, its next incarnation succeeds the earlier one: it takes the earlier one's place as runner or transfer participant and its unanswered commands, and the session revision does not change.

### Playback client-session identity

The browser tab's identity for video admission, retained across reloads and reconnects when browser execution locks are available.
It separates mutable playback jobs for clients using the same account and video. Without those locks, the identity is private to the current page lifetime.
Admission and signing carry this identity as `requestId`; individual playback commands use separate request IDs for replay detection.

### Uncertain transfer

A playback handoff whose target may have started but has not confirmed its state.
The source retains its media and cursor while paused until reconciliation establishes whether it can resume without overlapping the target.

### Disclosed command

A playback command that was included in a snapshot built for the endpoint incarnation it addresses. That snapshot is the only way a command leaves the server, and the command records the first time it happened.
A command that expires undisclosed cannot have had an effect. On a browser runner whose last report was stopped or paused it settles as never effective, and the session keeps its confirmed state. A disclosed command able to start playback that goes unanswered leaves the session unknown. No timer ends that state: the runner's answer, a local recovery, a close report from its page, a registration of its tab from another page or a playback override does.

### Playback page identity

A random value a browser document creates once, keeps in memory and sends as `pageId` with every registration of its playback endpoint. It is never stored, so a reload, a duplicated tab and a restored session each get a new one, while a document restored from the back/forward cache keeps its own. The server keeps it with the incarnation and never returns it.
With the tab's identity it tells a new page from a live one. A registration from another page of the same tab proves the earlier page gone, and the session is released from that page's incarnation in the registration's own transaction. One from the same page is that page's own reconnect, and its new incarnation succeeds the earlier one. A registration without a page identity, or against an earlier incarnation that has none, proves nothing.

### Close report

A playback endpoint's own statement, made with its capability, that this incarnation has stopped. The web client sends it at `pagehide`, from a request prepared ahead, and after a disconnect that silenced its players, before it registers again. The server retires the incarnation, records it stopped at the reported cursor and releases the session from it in the same request; a report for an incarnation the session no longer names changes only that incarnation.
A tab that is only hidden sends none. A crash, a killed browser or a dead network sends none either; the playback override is then the way back.

### Playback override

The listener's explicit decision, made in a registered browser tab, to end the hold of a browser destination that is no longer registered and has not confirmed that it is silent: the session's runner, or the target of an uncertain transfer. The web client offers it as "Play here anyway" in the playback destination menu.
The server retires that destination's incarnation without recording it as stopped, and the session continues paused at its cursor, without a runner unless a registered source of the failed transfer keeps it. No timer performs an override, and the server refuses it while the destination is still registered. A destination that is still playing when it is overridden keeps playing until a snapshot reaches it or its socket closes.

### Browser execution lock

An exclusive Web Lock held while one browser tab owns eligible local playback execution. Its name includes the server, account and nonsecret tab identity. A free lock proves the old holder released it; media fencing is a separate recovery condition.
A page claims a tab identity only while it holds that tab's lock, and gives the lock up only by ending or by entering the back/forward cache after fencing its players, so two live pages do not share a tab identity; without Web Locks, every page load uses a new one. The playback page identity tells the server whether a registration of the tab comes from the page it already knows or from a later one.

### Local recovery credential

A persisted, stop-only credential for one account, browser tab and endpoint incarnation. With authenticated owner authority, it permits retiring that exact local incarnation after the browser establishes its local stop condition. It grants no execution, command-reading or media access authority.

### Recovery checkpoint

The last recorded source-time cursor retained for interrupted playback, bound to its media identity, queue position and session revision. It preserves a useful resumption point without applying one item's position to another or claiming the exact position at a crash.

## Audio playback

### Loudness target

The loudness, measured in LUFS, that playback normalization aims to reach.

A listener chooses a preset or a custom target. The target controls playback gain within the gain limits; it does not change the source recording or select a transcoding standard.

## Media execution

### Transcode session

A user's playback lifecycle for a video transcode job, carrying its preparation, playback controls, position and completion state.

### Stale transcode session

A transcode session that no playback report and no stream signing has touched for
the longest lifetime of a signed stream URL, 24 hours, and whose presentation is not
finished. No client can still be playing it. Stale session expiry, part of job
cleanup, cancels the execution its worker still holds, ends the session and
releases its job, so the job becomes orphaned and cleanup removes it with its
output. A paused player renews its stream URL and is never stale.

### Transcode execution generation

The identity of one admitted media execution attempt inside a job-specific output
directory. A seek or restarted attempt advances the generation so stale output
cannot be delivered as current output. Different jobs have independent generations.
This identity is distinct from a worker deployment's boot generation.

### Transcode control revision

The monotonically increasing order of committed desired-state changes for a video
job. The worker applies the latest revision, preserving paused state across a seek
and treating cancellation as terminal.

A playback progress report that moves the desired position commits a new revision
without starting a new execution generation; a control that leaves the desired state
unchanged commits none. The worker's writes for the job are fenced on both the
generation and the revision, so a revision committed while a write is in flight
refuses that write. The worker then takes the latest revision and writes again. A
step with effects outside the job record, such as sealing a finished encode, records
that it ran so the retry does not repeat it.

### Owned video job

A transcode job belonging to one playback user, client session, video, selected presentation and quality tier.

Another client's job for the same source has independent controls, execution claims and output, including when both clients use the same account.

### Rendition set

The immutable set of owned video job public IDs and their individual execution
generations carried by a signed adaptive HLS or DASH manifest request. It fixes
which outputs the player may switch between; a changed set needs a new signature.

### Published presentation

The closed video and audio fragments recorded by one generation's atomic native
manifest, with their actual clocks, durations and codec declarations. Delivery
uses this index to advertise available media. Public fragment indexes are
zero-based ordinals within each generation and track; native file sequence numbers
remain separate.

### Copy presentation

A media presentation produced by repackaging selected source streams without audio or video re-encoding.
Its identity includes the selected streams and output container, and its timing comes from the copied media rather than an encoder's fixed keyframe schedule.
It uses the same execution ownership and publication guarantees as other presentations.

A copy is bound by disk rather than an encoder, so its producer reads the source in one initial burst and then at a bounded multiple of real time; the burst is counted from the seek boundary, and the lead throttle governs it from there.

### Lead throttle

The bound the session subscriber keeps on how far a producer's published media runs ahead of the playhead: the producer's process is stopped once its lead reaches the pause mark and continued once the lead has shrunk to the resume mark. It applies to every producer, encode or copy, and a seek restarts the producer rather than repositioning it.

### Join lead

The lead a growing sibling rendition's published media must hold over the playback cursor before a signing adds it to the rendition set: three segments, capped by the remaining duration. A sibling the player has attached stays until its lead shrinks to one segment and is then withdrawn once, so a rendition encoding near real time does not leave and rejoin as the cursor moves.
The session's primary rendition is offered as soon as it covers the cursor and alone decides readiness. The join lead is below the lead throttle's resume mark, so a throttled healthy encoder always qualifies; a sibling that encodes slower than playback does not join while the cursor advances.

### Sparse source

A video source whose frame rate is below one frame per second, so each frame is held on screen for seconds.
*Avoid:* held-frame source

Encoding converts it to one generated frame per second so that segments close on regular boundaries. A seek into it starts at the key frame before the target and keeps one frame interval of audio before the start, because a held frame can begin after the audio that belongs to it. Pacing such a source by its packets still releases each hold's generated frames at once; only pacing the generated frames spreads them out.

### Completed video presentation

A verified, sealed video presentation that can be delivered independently of its producer's later playback controls.
An active signed delivery grant retains its files and job record through the grant's expiry. Reading its playlists does not extend that deadline.

### Canonical audio track

The primary playback job's audio track shared by the video representations in a
signed rendition set. Other quality tiers do not become separate audio choices.
A source without audio has no canonical audio track.

### Source audio selection

The source audio chosen for movie encoding: the first track marked default in discovery order, or the first audio track when none is marked.

No audio means no selection. Its position among audio streams is separate from its position among all source streams. The same choice supplies encoding attributes and manifest language without reordering discovered tracks. It is independent of the selected video's Source timestamp origin and does not add interactive track controls.

### Selected video endpoint

The end of the selected non-attached movie video, expressed in Source position
coordinates. Qualified selected-stream timing excludes extension from audio,
subtitles, artwork and unselected video. The probed movie duration carries this
endpoint when it can be resolved; otherwise it retains a container-based estimate.
That estimate is not a verified selected video endpoint and does not establish
exact-end coverage.

Movie encoding pads audio with silence and cuts it at a resolved endpoint, so
both tracks finish together. With only the estimate, the encoder instead stops
the whole output when the video stream ends. That fallback does not guarantee
that audio reaches the exact video end, and it can publish the first segment
later.

### Source position

A playback position measured from the beginning of the source video. Subtracting
the execution generation's source start time gives its local media position. The
movie seek control spans the full source duration even while the published
presentation contains only part of the remaining media.

### Source timestamp origin

An explicit timestamp anchor established from the selected movie stream's first
verified presentation packet. Subtracting this anchor maps source timestamps to
Source position. It can differ from the container's reported start time and is
separate from a generation's requested source start position. An absent anchor
retains the existing input timestamp handling; it does not mean an inferred zero.

### Transcode control receipt

A durable record that one session has applied a request identity. It prevents the
same request from applying again after other controls have intervened. Controls
fan out transactionally to sibling renditions of the same playback owner, client session and video.

### Source subtitle track key

The stable identity of a source subtitle track derived from its position among all source streams, used across extraction, generation output and signed subtitle URLs.

Language, title and dispositions describe the track and do not identify it; two tracks may share a language.

## Asynchronous execution

### Asynchronous workload catalog

The inventory of admitted asynchronous workloads, their transports, resource classes and routing modes, checked against the application's configured message routing.

A default workload routes to its transport automatically; an explicit-only workload can run inline and enters its transport only through an explicit selection.

### Workload resource class

A workload classification that distinguishes input/output work, computation and coordination without reserving execution slots, CPU or memory.

### Consumer identity

The identity of one worker launch within a deployment and boot, scoped to its message stream and consumer group.

### Worker deployment pair

The fixed control and execution containers admitted together under one deployment
namespace and boot identity. Neither carries a CPU, memory or PID limit or a CPU
set; each starts at its own out-of-memory score in the kill order. Control owns
the lease and coordination; execution observes the lease and owns native
processes. A private IPC volume connects the pair.

Each unit publishes a heartbeat on that volume and admits work only while its
peer's heartbeat is current, ready and at the same lease epoch. A peer heartbeat
that is foreign, stale, draining or dated ahead of the reader's clock, or one
still missing or not ready after startup, is a peer failure: the unit drains, and its draining
heartbeat then drains the other unit.

### Small-host household profile

A startup profile for household playback and ordinary library maintenance with
fixed media slots. It permits original-file playback, compatible
completed artifacts and two shared audio/video copy jobs, but no server-side audio
or video re-encoding. Web and worker processes use the same profile; changing it
requires deployment replacement.

Per-service consumption has been measured on a large host; the smallest host
size, a 4 GB fit and Raspberry Pi support remain unqualified.

### Media job slot

One unit of concurrent media work a deployment admits: a video slot runs one
video conversion or stream copy, an audio slot one audio rendition or stream copy.
Original-file and completed-artifact playback use no slot. The startup profile
carries the slot counts: the full profile takes operator-set counts with a default
for each kind, and zero disables a kind; the household profile has two fixed
shared copy slots. Baander's native command, result and client limits are derived
from the slots, and the pooler's client limit bounds how many slots a profile
accepts. Slots are Baander's only bound on media work: containers carry no CPU or
memory limits, and operators lower the slots when their host cannot keep up.

### Configured hardware encoder

The accelerator and device a `full` deployment's startup profile names for video
encoding. Only the execution worker receives the device and its group. Media
control must pass a test encode on it at worker start or fails instead of encoding
in software, and unset video slots take the hardware default.

### Connection pooler

The transaction-mode PgBouncer between the application and PostgreSQL. Clients
hold a server connection only for the length of a transaction, so PostgreSQL's
backend count, and its memory, follow the pooler's server pools rather than the
number of web, worker and media clients. An application alias serves web, ordinary
workers, media jobs and migrations; a control alias with its own server pool
serves worker leases, media authority checks and the scheduler. Work that needs
session state connects to PostgreSQL directly.

### Kill order

The `oom_score_adj` ranking that decides which processes the kernel kills first
when host memory runs short, since no container has a memory limit. Web workers
rank highest, then media work (FFmpeg commands and PHP media children), then the
execution unit, Redis and the gateway; PostgreSQL, the pooler and the control
unit rank lowest. It needs a rootful Docker daemon, which can set the negative
scores.

### Ordinary admission target

The desired number of concurrent ordinary asynchronous receives and owned deliveries within a worker deployment pair's admitted resident-consumer maximum. Lowering the target leaves existing occupied permits held until their work completes.

### Occupied permit

A grant held by one ordinary consumer while it receives work and through any returned delivery's handler and transport completion. An uncertain receive or completion keeps the permit occupied until exact evidence resolves ownership; it does not authorize replacement work.

### Joint retirement barrier

The retained boot reservation that prevents replacement until both worker units
have confirmed containment or cancellation of an unclaimed creation intent.
Lease expiry and a direct-child exit do not satisfy this barrier.

### Native execution attempt

A bounded request owned by the execution daemon and identified by deployment,
boot, lease epoch, client generation and attempt identity. An uncertain reply does
not authorize another process or release its reserved slot.

## Backend verification

### Test lane

A disjoint set of selected test files that the backend coordinator runs in one
container with its own result artifacts. Ordinary tests share a ParaTest worker
pool; native tests run one fresh process per file without networking. Each
functional/integration lane owns a disposable Compose project with separate
PostgreSQL, Redis and application containers. Together, the lane manifests must
contain every discovered test file exactly once.

## Continuous integration

### Forge

A host for the repository that runs its CI: the self-hosted Forgejo instance or
GitHub. Both forges run the same workflow files, and each supplies its own runner,
image registry, cache store and coverage-baseline store.

### Capability gate

The build-time check that an FFmpeg binary lists every accelerator, encoder,
decoder, filter and demuxer the application can select, and that every shared
library it loads resolves. It runs where the binary is built and again where the
application image adopts it, so a component lost to a changed build fails the
image build instead of a transcode. It checks only the capabilities listed for
it, so new media features add their components to that list.

## Dependency maintenance

### Dependency policy

The repository inventory that assigns ownership to Composer projects, runtime
declarations, patches and forks. It records reviewed upstream references and
justified exceptions; manifests, locks and build recipes still own version pins.

### Dependency finding

A reported difference or inconsistency supported by the requested evidence, such
as an available stable upgrade or a changed patch hash. A recorded reason for
retaining a dependency does not suppress the finding.

### Evidence gap

A requested dependency check that could not establish its result, for example
because a source was unavailable or returned an unsupported format. A gap prevents
a clean result and preserves findings already collected from other sources.
Checks deliberately excluded by local mode are stated exclusions, not gaps.

## Live updates

### Principal session

The browser's ownership lifetime for one login and account. It owns query caches
and observers and remains unchanged when access tokens rotate. Logging out or
switching accounts ends that ownership.

### Authorization generation

The browser's current access boundary within a principal session. Advancing it
fences requests and callbacks from former access and clears protected data while
preserving the mounted player.

### Query family

One category in the finite shared live-update protocol used to group related
queries and guarded readers for invalidation and sampling. Feature-owned
descriptors map query keys to these categories.

### Dirty generation

A browser-local freshness marker for a query or guarded reader. A successful read
acknowledges the generation captured when that read started; a hint received
later requires another read, even if the first read succeeds.

### Scope revision

The live-update design's durable freshness marker for a query topic and audience.
It advances when committed source changes are projected, and several changes may
share one advance. It identifies a need to refetch current data, not an entity
version, a business-operation count, or a position in replayable event history.

## Store debugging

### Recorder instance

One successfully created store admitted by the enabled store debugger. Its
recorder-local ID distinguishes it from stores with the same display name and is
not reused after retirement or a history clear. Explicit retirement stops recording
that instance.

### History baseline

The immutable diagnostic captures immediately before the store debugger's retained
event window. Eviction folds discarded events into this baseline; clearing starts a
new epoch from current recorded captures. Reconstruction applies retained events to
the baseline through the selected event.

### Isolated store verification

A comparison of one recorded synchronous store action with its registered pure transition, calculated from detached captured data.

It reports whether the results match or the comparison is unavailable and leaves live application state and recorder history unchanged.

## Audio codec

### bAAC

The public name of Bånder's AAC encoder and decoder library.

### AAC implementation setting

The system setting that selects which codec implementation FFmpeg uses for AAC encoding and decoding across the server's transcodes: bAAC by default, Fraunhofer's fdk-aac, or FFmpeg's built-in codec.
*Avoid: kill switch, which names only the act of moving off bAAC.*

A change applies to transcodes started afterwards; running sessions and cached renditions keep the implementation that produced them. A rendition that failed while encoding or decoding AAC through one implementation is encoded again on its next request once another implementation is selected.

### HE-AAC profile

The AAC profile that determines whether AAC-LC codes the whole spectrum, HE-AAC v1 adds Spectral band replication or HE-AAC v2 adds Parametric stereo as well.

An HE-AAC transport header can describe the AAC-LC core while the additional profile information is carried in the coded data.

### Spectral band replication

The HE-AAC tool that codes the lower spectrum with AAC-LC and reconstructs the upper spectrum from it using transmitted envelope, noise and inverse-filtering information.
*Avoid: SBR as a separate stream or codec.*

The reconstructed output has a higher sample rate than the coded core.

### Parametric stereo

The HE-AAC v2 tool that codes a stereo pair as one SBR channel plus per-band intensity differences and coherence; the decoder widens the mono core to two channels. A stream carrying it has one stream channel and two output channels.

### Codec delay

The number of input-rate samples before the first input sample appears in the decoded output of an encoded stream.

The encoder reports its delay and drains it on flush. A decoder's Peak limiter adds its own look-ahead, reported separately.

### AAC transport

The framing an AAC stream travels in: ADTS (self-synchronising headers per frame, implicit HE-AAC signalling), LOAS (self-synchronising AudioSyncStream carrying LATM), LATM (an AudioMuxElement with its StreamMuxConfig in band or out of band) or raw access units with an AudioSpecificConfig (the MP4 form). The decoder detects ADTS and LOAS from their sync words; the other forms are told to it.
*Avoid: container, which is the MP4 or Matroska file around the transport.*

### AudioSpecificConfig

The configuration record of ISO/IEC 14496-3 that names the object type, sample rate, channel layout and frame length of a stream and, with explicit signalling, its SBR and PS extension and output rate. Out-of-band in MP4 and LATM, in band in a StreamMuxConfig.

### Error concealment

What the decoder does with a frame it cannot parse, that fails its CRC or that never arrived: it continues the last good spectrum with a fade and then substitutes noise at its band energies, keeps the SBR envelope and PS parameters decaying, and crossfades into the next good frame. Concealed frames are counted; with concealment off the frame is an error.

### Channel mode

The spatial layout of an audio stream where its channel count alone does not determine speaker placement.

The decoder reports the layout so consumers can distinguish alternative surround arrangements.

### Peak limiter

A look-ahead gain stage on the decoder's output that keeps samples within full scale after a downmix or loud content, adding its attack window to playback delay.

### Low-delay profile

An AAC profile for conversation and live links that reduces delay through shorter frames and a filterbank designed for low overlap.

AAC-LD and AAC-ELD differ in their filterbanks and extension tools. Their transport must support these profiles; ADTS cannot carry them.

### LD-SBR

Spectral band replication for AAC-ELD, run through a low-delay filterbank and a frame grid of the short frame; its header travels in the AudioSpecificConfig rather than in the stream. With dual rate the core runs at half the input rate as in HE-AAC; without it the core and the SBR share one rate.
*Avoid: HE-AAC ELD, which no standard names.*

### Error resilience tools

The AAC syntax tools that limit damage from bit errors by bounding decoded values, allowing scalefactors to be read from either end and separating spectral codewords.

The AudioSpecificConfig declares which tools a stream uses; support for decoding a tool does not imply that the encoder writes it.

### Afterburner

A second quantisation pass that re-searches a frame's scalefactors and codebooks against its bit budget, trading encoder CPU for quality.

It can be switched during encoding for profiles that support it.

### Dynamic range control

The MPEG-4 AAC tool that carries compression gains and a program reference level as metadata for optional decoder processing.
*Avoid: MPEG-D DRC, the separate loudness and DRC standard of USAC streams.*

The listener controls the applied boost, cut and optional normalization; the DVB variant also carries a heavy compression gain.

### USAC

Unified Speech and Audio Coding as constrained by the xHE-AAC profile, whose core channels can switch between frequency-domain and linear-prediction coding and whose extensions provide Enhanced SBR, MPS 212 and MPEG-D DRC.
*Avoid: xHE-AAC as a separate codec; it is the profile of USAC the codec targets.*

Its configuration fixes the element list rather than repeating element identities in each frame. It travels in LOAS, LATM or raw access units; ADTS cannot carry it. bAAC decodes both core forms and encodes the frequency-domain form through its xHE-AAC encoder.

### Enhanced SBR

Spectral band replication for USAC, with configuration-carried headers, additional core-to-output rate relationships and tools for harmonic transposition, envelope prediction and transient shaping.
*Avoid: eSBR, which names the same tool.*

### MPS 212

The MPEG Surround one-to-two tool of a USAC channel pair: one core channel plus per-band level, correlation and phase parameters, optionally a residual signal, from which the decoder widens the pair in the QMF domain. The order in which it runs against the SBR is named by the stereoConfigIndex.
*Avoid: MPEG Surround alone, which also names the 5.1 trees; the 2-1-2 tool is one OTT box.*

### stereoConfigIndex

The USAC channel-pair configuration that determines the order of MPS 212 and Spectral band replication and whether reconstruction includes a residual signal.

### MPEG-D DRC

The USAC loudness and dynamic range standard that describes loudness metadata, available gain sets and the process for selecting sets to match a listener's request.
*Avoid: dynamic range control alone, which is the MPEG-4 tool.*

Selection accounts for target loudness, requested effect and fallback order, album mode, boost and cut. Without a requested effect, the decoder applies DRC only to prevent clipping. MPEG-4 dynamic range control is a separate tool and is refused on a USAC stream.

### Pre-roll frame

The audio pre-roll carried at a USAC access point, containing the stream configuration and previous access units needed to warm decoder state.
*Avoid: keyframe, which belongs to video.*

A cold decoder decodes these units and drops their output; a warm one skips them. Seeking or changing configuration starts at such an access point.

### MPEG Surround

The AAC spatial coding tool that represents a multichannel programme as a mono or stereo downmix plus parameters from which a decoder rebuilds the surround channels.
*Avoid: MPS alone for surround trees; MPS 212 is the one-box tool of USAC and ELD v2.*

Its payload carries level, correlation and prediction information. A tree defines how OTT boxes and TTT boxes expand the downmix; its configuration can travel with the coded data or in the transport configuration.

### OTT box

A one-to-two box of an MPEG Surround tree: from one input channel, a per-band level difference and correlation, and a decorrelated copy of the input (or, in the residual bands, a coded residual signal) it produces two output channels. An LFE box carries the level difference alone. The 2-1-2 tool is a tree of one OTT box.

### TTT box

A two-to-three box in an MPEG Surround tree that rebuilds left, right and centre from a stereo downmix using prediction or energy parameters and any coded residual.

A dual-mode box uses different reconstruction modes below and above a band boundary.

### xHE-AAC encoder

The bAAC encoder for the xHE-AAC profile of USAC, using a frequency-domain core with arithmetic coding, noise filling and independent access points with audio pre-roll.
*Avoid: USAC encoder alone, which also names the linear prediction core the codec does not write.*

It produces stream or packet transports suitable for playback and muxing, with optional Enhanced SBR, MPS 212 and loudness metadata. ADTS cannot carry its output.

### Loudness info set

The USAC configuration metadata describing a programme's peaks, integrated and anchor loudness, loudness range and short-term maxima for playback normalization.
*Avoid: loudness metadata alone, which also names the MPEG-4 program reference level.*

The xHE-AAC encoder can write measurements collected before encoding. Measurements collected during a single pass can serve a later encode or container metadata; the active stream's configuration cannot change after encoding begins.

### Immediate playout frame

An independent USAC access point that carries audio pre-roll and, in stream transports, the configuration needed for a decoder to start and a muxer to cut a segment.
*Avoid: keyframe, which belongs to video; independent frame alone, which also names the frames before it.*

The encoder places these access points under interval and segment-size constraints or on request and reports their positions to the muxer. The frames repeated in the pre-roll are independent too.

### DRC gain set

An MPEG-D DRC gain sequence and its effect description, from which a decoder selects processing that matches the listener's request.
*Avoid: DRC profile, which names the MPEG-4 DRC metadata's compression curves.*

The xHE-AAC encoder can supply general compression and night-listening processing. Gains carried by one access unit apply to the following frame.

### VBR mode

An AAC encoder quality setting that determines a variable bitrate target scaled by channel count and bounds frame allocation through the bit reservoir.
*Avoid: quality alone, which names the underlying quality setting.*

A peak bitrate can lower the selected mode to one that fits the cap.

### Peak bitrate

The per-frame cap of the AAC encoder's rate loop, limiting each frame's allocation and borrowing from the bit reservoir whenever its side information fits.

The cap covers the rate loop's accounted bits rather than the complete transported access point: additional transport framing and the pre-roll of an Immediate playout frame can exceed it. It bounds frame bursts; the segment byte ceiling bounds a segment instead.

### Decoder flush and seek reset

The distinct decoder operations for draining delayed output at the end of a stream and discarding history before playback resumes at a random access point.
*Avoid: reset alone, which also clears the configuration and the counters.*

Flush emits the retained samples and then resets playback state. Seek reset drops buffered input and audio history while retaining configuration, allowing the next random access frame to decode as in a fresh decoder.

### Prebuilt binding

A distributed native bAAC addon built for a target platform and architecture that can be installed without compiling from source.
*Avoid: native module alone, which also names a build from source.*

Its stable runtime interface allows a compatible binary to serve both Node and Electron; a local source build remains a separate installation path.

### ELD v2

AAC-ELD with MPS 212, carrying a mono core and low-delay spatial parameters that reconstruct stereo through a low-delay filterbank and decorrelator.
*Avoid: ELD with LD-SBR, which is a different extension of the same core.*

## Typography

### Content language

The language catalog text renders in. It is the item's stored language when that value is a recognised language code, such as an album's release language (which its songs inherit) or a movie's original language; otherwise it is detected from the text's script.

It selects Han glyph forms and script spacing. It is separate from the interface locale, which governs interface text.

### Type role

One of the ten named text styles, from `text-hero` to `text-mono-sm`, that fixes size, weight, tracking and line-height for each platform. Every text element uses one role.
