# Concepts

> Shared domain vocabulary for this project — entities, named processes, and status concepts with project-specific meaning. Seeded with core domain vocabulary, then accretes as ce-compound and ce-compound-refresh process learnings; direct edits are fine. Glossary only, not a spec or catch-all.

## Identity

### Internal UUID

The persisted identity used inside the application for primary keys, foreign keys and domain events. Public transport contracts do not expose or require it to identify an entity.

### Public ID

An entity's independent, 21-character NanoID, represented by `PublicId` in code and usually `publicId` in resources. Public routes, relationships, cursors and realtime messages use this identity; existing snake-case contracts use `public_id`. A contextual name such as `videoId` still carries a public ID. Resolving it does not grant access to the entity.

### Browser device token

The client-generated UUID sent as `deviceId` during device registration and session claims, and in the `X-Baander-Device-Id` header. It identifies the browser installation within the account. The device resource's `publicId` addresses rename and forget operations and appears as a listening session's `activeDeviceId`.

### EQ audio-output token

The optional client value stored in an EQ profile's `deviceId`. It names an audio output according to the client and does not refer to the persisted device resource. The profile itself has a public ID.

### OAuth client identifier

The registered client's public ID, carried by OAuth's `client_id`, administration's `clientId` and the access-token JWT's `client_id` claim. It is separate from a token identifier or secret.

## Local development

### Persistent development instance

The configured local Baander instance at `baander.test` that automatically follows
master. Its libraries, accounts and settings survive ordinary updates. It runs
independently of source edits and branch switches in development worktrees.

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

The user's public ID encoded as bytes for WebAuthn registration, authentication and account signals. It identifies the account independently of the authenticator's credential ID. Passkeys enrolled with UUID handles are invalidated and require re-enrollment; authentication has no UUID-handle fallback.

### Refresh token chain

The sequence of refresh tokens one sign-in produces through rotation. Each rotation consumes the presented token and issues a replacement that links back to it; every token in the chain is bound to the same DPoP key. Revoking the chain ends that sign-in everywhere it was in use and closes the WebSocket connections it opened; it is what logout and reuse detection do.
*Avoid: token family (the vendor term).*

### Consumed refresh token

A refresh token that a rotation has already used. It stays stored, marked with the instant it was consumed, so a later presentation can be judged: a lost-response grace or a reuse.

### Lost-response grace

The server's answer to a client that lost the response of a rotation: a consumed refresh token presented again by the same DPoP key, within the grace window, while its replacement is still unused, gets a fresh pair and the unused replacement is revoked. Any other presentation of a consumed token is a reuse, which revokes the chain. The window is an operator setting measured on the database clock; zero turns the grace off.

### DPoP store outage

The state in which the Redis store behind the DPoP nonce and replay checks cannot answer. The checks stay fail-closed: no proof is accepted, but the server answers "come back later" (503 with a retry delay) rather than a nonce challenge or a denial, so clients keep their session and retry. The two revocation routes, logout and revoke, are the exception: they accept a proof whose replay check could not run once every other check passed, log one warning, and revoke the chain.

### Pending logout

A logout the browser has recorded but the server has not yet confirmed. The tab ends its session locally at once; the record keeps the DPoP key pair and the tokens of the ended session so the logout can be signed and sent again on the next start or when the browser is back online, until the server answers that the chain is revoked or gone.

### WebSocket handshake ticket

A single-use credential obtained through an authenticated, DPoP-protected API
request. It authorizes one upgrade at `/api/ws`, bound to the requesting user,
access token and browser Origin. It expires within thirty seconds, or when the
access token expires if that is sooner. Redemption consumes it atomically and
checks current token and user state. A ticket is a bearer capability during that
short window; it does not sign later WebSocket messages.

### Socket reconciliation

The periodic check inside the web server that closes every WebSocket connection whose token chain is dead: every access token of the chain is revoked or expired, or the chain is gone from the database. The chain is the unit, not the handshake token, because a rotation revokes the token a socket was opened with while the session goes on; rotation alone never closes a socket. The close on logout, revoke and reuse verdicts is the fast path; the reconciliation is the backstop that ends a socket within a minute whatever ended its chain, including a console process in another container or an expiry nobody refreshed.

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

The Redis token shared by every subject of one named limiter. Clearing the limiter
replaces this token, making previously read state unwritable without scanning
subject keys. Consumers retry against the new generation; other named limiters
retain their own state.

### Limiter revision tombstone

An empty subject state with an opaque revision that Redis retains for ten seconds
after an absent read or per-subject reset. Snapshots are writable for at most two
seconds, so a paused writer cannot mistake an expired state for the absence it
previously read.

## Provider requests

### Public destination policy

The destination check shared by metadata, IPRD catalog and push transports. It
permits approved HTTPS hostnames on port 443 only when every resolved address is
public, then pins an approved address while preserving TLS hostname verification.
It has no LAN exception. Webhooks and operator-configured lyrics retain separate
policies.

## Media availability

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

## Media execution

### Transcode execution generation

The identity of one admitted encoding attempt inside a job-specific output
directory. A seek or restarted attempt advances the generation so stale output
cannot be delivered as current output. Different jobs have independent generations.
This identity is distinct from a worker deployment's boot generation.

### Transcode control revision

The monotonically increasing order of committed desired-state changes for a video
job. The worker applies the latest revision, preserving paused state across a seek
and treating cancellation as terminal.

### Owned video job

A transcode job belonging to one playback user, video and quality tier. Another
user's job for the same source has independent controls, execution claims and
output. Historical jobs whose owner cannot be resolved retain no owner.

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

### Canonical audio track

The primary playback job's audio track shared by the video representations in a
signed rendition set. Other quality tiers do not become separate audio choices.
A source without audio has no canonical audio track.

### Source audio selection

The source audio chosen for movie encoding: the first track marked default in
discovery order, or the first audio track when none is marked. No audio means no
selection. Its nullable ordinal is a position among audio streams, separate from
the global FFprobe stream index. The same choice supplies mapping, filter
attributes and manifest language without reordering discovered tracks. It is
independent of the selected video's Source timestamp origin and does not add
interactive track controls.

### Selected video endpoint

The end of the selected non-attached movie video, expressed in Source position
coordinates. Qualified selected-stream timing excludes extension from audio,
subtitles, artwork and unselected video. The probed movie duration carries this
endpoint when it can be resolved; otherwise it retains a container-based estimate.
That estimate is not a verified selected video endpoint and does not establish
exact-end coverage.

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
fan out transactionally to sibling renditions of the same playback owner and video.

### Source subtitle track key

The letter `s` followed by a subtitle stream's absolute FFprobe index, such as
`s2`. It identifies that source track in extraction jobs, generation output and
signed subtitle URLs. Language, title and dispositions describe the track and do
not identify it; two tracks may share a language.

## Asynchronous execution

### Asynchronous workload catalog

The admitted message classes, transport selections, resource classes and routing
modes checked against compiled Messenger wiring. A default workload routes to its
transport without a stamp; an explicit-only workload can run inline and enters its
transport only through an explicit selection.

### Workload resource class

A catalog description of a workload as `io`, `cpu` or `coordination`. It describes
the work without reserving execution slots, CPU or memory.

### Consumer identity

The Redis consumer name generated from a deployment namespace digest, supervisor
boot ID, worker role and launch generation. Its registration is scoped to a Redis
stream and consumer group.

### Worker deployment pair

The fixed control and execution containers admitted together under one deployment
namespace and boot identity. Each has its own memory, CPU and PID ceilings and a
disjoint CPU set. Control owns the lease and coordination; execution observes the
lease and owns native processes. A private IPC volume connects the pair.

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

A comparison of one recorded synchronous action with the current registered pure
transition, calculated from detached captured data. It reports match, mismatch or
unavailable and leaves live application state and recorder history unchanged. Its
initial scope is the catalog selection store's `select` and `clear` actions.

## Audio codec

### HE-AAC profile

The object type an AAC stream or encoder session uses: AAC-LC codes the whole spectrum; HE-AAC v1 adds spectral band replication, HE-AAC v2 adds parametric stereo on top. The native binding and the Electron service name them `lc`, `he` and `hev2`. The ADTS header of an HE-AAC stream still names the AAC-LC profile and the core rate; a decoder recognises the profile from the data it carries.

### Spectral band replication

The HE-AAC tool that codes only the lower half of the spectrum with AAC-LC at half the sample rate and reconstructs the upper half from it, guided by per-frame envelope energies, noise floors and inverse filtering levels carried in a fill element after each channel element. A stream's output rate is twice its core rate once it carries SBR data, and a frame is 2048 samples.
*Avoid: SBR as a separate stream or codec.*

### Parametric stereo

The HE-AAC v2 tool that codes a stereo pair as one SBR channel plus per-band intensity differences and coherence; the decoder widens the mono core to two channels. A stream carrying it has one stream channel and two output channels.

### Codec delay

Samples at the input rate before the first input sample appears in the decoded output of a stream the encoder produced: 2048 for AAC-LC, 5635 for the HE-AAC profiles (1920 and 5379 with 960-sample frames). The encoder reports it and its flush drains it. The decoder's limiter adds its own look-ahead, which the decoder reports separately.

### AAC transport

The framing an AAC stream travels in: ADTS (self-synchronising headers per frame, implicit HE-AAC signalling), LOAS (self-synchronising AudioSyncStream carrying LATM), LATM (an AudioMuxElement with its StreamMuxConfig in band or out of band) or raw access units with an AudioSpecificConfig (the MP4 form). The decoder detects ADTS and LOAS from their sync words; the other forms are told to it.
*Avoid: container, which is the MP4 or Matroska file around the transport.*

### AudioSpecificConfig

The configuration record of ISO/IEC 14496-3 that names the object type, sample rate, channel layout and frame length of a stream and, with explicit signalling, its SBR and PS extension and output rate. Out-of-band in MP4 and LATM, in band in a StreamMuxConfig.

### Error concealment

What the decoder does with a frame it cannot parse, that fails its CRC or that never arrived: it continues the last good spectrum with a fade and then substitutes noise at its band energies, keeps the SBR envelope and PS parameters decaying, and crossfades into the next good frame. Concealed frames are counted; with concealment off the frame is an error.

### Channel mode

The 7.1 layout of an eight-channel stream, which the channel count alone does not fix: back pair behind the side pair (configuration 12, the default), front centre pair (configuration 7), top front pair (configuration 14) or the rear surround layout signalled by a PCE. The decoder reports the mode of the stream's layout; other channel counts have one layout each.

### Peak limiter

A look-ahead gain stage on the decoder's output that keeps every sample within full scale after a downmix or loud content, at the cost of its attack window as delay. Off by default.

### Low-delay profile

AAC-LD and AAC-ELD, the object types for conversation and live links: frames of 512 or 480 samples instead of 1024, a single long window without block switching, and for AAC-ELD a low-overlap filterbank whose window spans four frames with most of its weight at the front, so the codec delay drops to a few tens of milliseconds. The native binding and the Electron service name them `ld` and `eld`. ADTS cannot carry them; LOAS is their default transport.

### LD-SBR

Spectral band replication for AAC-ELD, run through a low-delay filterbank and a frame grid of the short frame; its header travels in the AudioSpecificConfig rather than in the stream. With dual rate the core runs at half the input rate as in HE-AAC; without it the core and the SBR share one rate.
*Avoid: HE-AAC ELD, which no standard names.*

### Error resilience tools

The optional tools of the error-resilient AAC syntax (object types 17, 23 and 39): virtual codebooks that bound the values a damaged section can take, reversible variable length coding of the scalefactors readable from both ends, and reordering of the spectral codewords so that a bit error damages few of them. The AudioSpecificConfig says which of the three a stream uses; the decoder reads them all, the encoder writes none.

### Afterburner

A second quantisation pass in the encoder that re-searches the scalefactors and codebooks of a frame against its bit budget, trading CPU for quality at low bitrates. Off by default and switchable at any time.

### Dynamic range control

MPEG-4 DRC: per-band gains and a program reference level the encoder writes into fill elements as metadata, which a decoder applies on request, scaled by the listener's boost and cut shares and optionally normalised to a target loudness. The DVB variant adds a heavy compression gain in the ancillary data. The encoder derives the gains from one of five named compression profiles; the decoder applies nothing unless asked.
*Avoid: MPEG-D DRC, the separate loudness and DRC standard of USAC streams.*

### USAC

Unified Speech and Audio Coding, ISO/IEC 23003-3, object type 42, as the xHE-AAC profile constrains it: the AAC object type whose frames carry no element ids (the configuration fixes the element list) and whose core channels switch per frame between a frequency-domain core with an arithmetic coder and a linear prediction core of ACELP and TCX frames, followed by enhanced SBR, MPS 212 and MPEG-D DRC. The decoder reads it from LOAS/LATM or raw access units; ADTS cannot carry it. The native binding and the Electron service name it `usac`; the codec decodes it and does not encode it.
*Avoid: xHE-AAC as a separate codec; it is the profile of USAC the decoder targets.*

### Enhanced SBR

Spectral band replication as USAC carries it: the header travels in the configuration, the core runs at 2:1, 8:3 (a 768-line core) or 4:1 of the output rate, the patch source bands are flattened before patching, and the standard adds a harmonic transposer, predictive vector coding of the envelopes and inter-TES. The decoder implements the three additions but no stream at hand uses them.
*Avoid: eSBR, which names the same tool.*

### MPS 212

The MPEG Surround one-to-two tool of a USAC channel pair: one core channel plus per-band level, correlation and phase parameters, optionally a residual signal, from which the decoder widens the pair in the QMF domain. The order in which it runs against the SBR is named by the stereoConfigIndex.
*Avoid: MPEG Surround alone, which also names the 5.1 trees; the 2-1-2 tool is one OTT box.*

### stereoConfigIndex

The configuration value of a USAC channel pair that orders its stereo tools: 0 no MPS 212; 1 SBR on the single core channel, then MPS 212 from the parameters alone; 2 the same order with a residual signal; 3 MPS 212 on the core band with the residual, then SBR of both output channels. Only 2 is unverified against a reference, for lack of a stream.

### MPEG-D DRC

The loudness and dynamic range standard of USAC streams (ISO/IEC 23003-4): loudness metadata and DRC gain sets in the configuration, gain sequences in extension elements, and a selection process that matches the listener's request (target loudness, a named effect with its fallback order, album mode, boost and cut) to the sets the stream carries. The decoder normalises to -24 LUFS by default and applies DRC only to prevent clipping unless an effect is requested; MPEG-4 DRC is refused on a USAC stream.
*Avoid: dynamic range control alone, which is the MPEG-4 tool.*

### Pre-roll frame

The audio pre-roll of a USAC stream access point: an extension element at the front of an independent frame (one whose `usacIndependencyFlag` is set, so that it decodes without history) that carries the stream configuration and the previous access units a decoder needs to warm its state. A cold decoder decodes them and drops their output; a warm one skips them. A seek or a configuration change lands on such a frame.
*Avoid: keyframe, which belongs to video.*

### MPEG Surround

ISO/IEC 23003-1 as AAC carries it: a 5.1 programme coded as a mono or stereo AAC-LC downmix plus a spatial payload of per-band level, correlation and prediction parameters, from which the decoder rebuilds the six channels in the QMF domain. The payload travels as `EXT_SAC_DATA` fill elements with an in-band header, or its configuration as an object type 30 layer of a two-layer LATM stream. The tree names the box layout: 5151 and 5152 build 5.1 from a mono downmix through five OTT boxes in two orders, 525 from a stereo downmix through a TTT box and three OTT boxes. The decoder decodes these three trees, reports the decoded surround as `hasSurround` and refuses the 7.1 trees, the HRTF and binaural modes and the 3D mode; the codec has no MPEG Surround encoder, and the test tool `mps-enc` writes the suites' streams.
*Avoid: MPS alone for the 5.1 trees; MPS 212 is the one-box tool of USAC and ELD v2.*

### OTT box

A one-to-two box of an MPEG Surround tree: from one input channel, a per-band level difference and correlation, and a decorrelated copy of the input (or, in the residual bands, a coded residual signal) it produces two output channels. An LFE box carries the level difference alone. The 2-1-2 tool is a tree of one OTT box.

### TTT box

The two-to-three box of the 525 tree, which rebuilds left, right and centre from the stereo downmix: in the prediction modes 0 and 1 from two prediction coefficients, a correlation and the residual where coded; in the energy modes 2 to 5 from two level differences, modes 2 and 3 with the centre's share subtracted from the other input before the split. A dual-mode box uses one mode below a band boundary and another above it.

### ELD v2

AAC-ELD with the 2-1-2 tool: an ELD core of 512 samples carrying a mono downmix, the low-delay form of the spatial configuration in the ELD-specific configuration and the spatial frame in the ELD extension payload, decoded to stereo through the low-delay filterbank pair and decorrelator with 256 samples of added delay. The decoder reads it; the test tool writes it on the codec's ELD encoder.
*Avoid: ELD with LD-SBR, which is a different extension of the same core.*

