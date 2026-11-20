# Native media qualification

These suites exercise native encoding, source selection and decoded output. Browser playback has separate [qualification checks](media-and-playback.md).

## Native Segment Encoder Suites

### Colour conversion

`SegmentEncoderMediaTest` generates deterministic colour patches, probes them,
encodes through the actual movie command builder and independently decodes the
published fragment. H.264 and HEVC cases compare interior Y/U/V samples at the
same pixel format and range, covering fresh output and a nonzero source start.
BT.2020 SDR must retain its primaries, transfer, matrix and range without tone
mapping; PQ and HLG cases require decoded software tone mapping to BT.709.

This covers transfer-based HDR classification and the software encode path, not
display-gamut certification, a complete HDR reference-vector suite, Dolby Vision
or hardware acceleration. Native colour checks do not establish new HTTP or
browser playback acceptance.

### Movie stream selection

`SegmentEncoderSourceSelectionMediaTest` generates a moving movie with distinct
attached artwork and checks both cover-first and cover-last stream ordering with
FFprobe. It runs the production probe, filters and command builder, reads the
published presentation, then independently decodes each initialization/media pair.
Fresh 14-second output and the eight-second tail after source second six must
retain the movie dimensions, frame rate, duration and changing frames. A cover-only
source must fail the required video mapping and publish no movie fragments.
These cases qualify native source selection through publication; they do not add
browser playback acceptance or establish CMAF conformance.

### Audio profiles

`SegmentEncoderAudioProfileMediaTest` covers Opus and AAC on fresh 14-second
output and the eight-second tail from source second six. Each case requires
closed MP4 video/audio tracks with continuous native timelines, checks the actual
MPD and initialized codec, and independently decodes every published fragment.
Audio must retain stereo at 48 kHz. Decoded duration allows 50 ms per fragment
and 100 ms overall for priming and padding. Video colour markers and audio
frequency changes distinguish the requested source interval from an incorrect
restart; positive audio energy and changing video frames are required.

### Sparse video

`SegmentEncoderSparseMediaTest` covers 14 sparse movie cases. Software H.264
and HEVC retain the covering red, green or blue source frame on fresh output
and restarts at source seconds six and 24. Other cases cover fractional cadence,
a nonintegral final hold, video-only output including a silent six-second restart,
and audio delayed by eight seconds in MOV. Independent fragment and HLS decoding
checks continuous timelines, zero-based one-second video timestamps, the complete
held-frame sequence and positive audio signal. Every growing playlist prefix and
completed playlist must retain the seven-second target. Two paced H.264/HEVC
cases require independently decodable video and audio, followed by a larger video
publication edge while the encoder remains running and the presentation remains
incomplete. The later edge must arrive before second 29 of a thirty-second paced
input, leaving a one-second margin before EOF to exclude final-flush output.
Each producer has a 45-second process bound.

The sparse path applies only to finite positive probed rates below one fps and
normalizes video to one fps. Transitions round upward so future content is not
shown early; terminal extension must be nonnegative and less than one second,
with a small numerical tolerance. Delayed-audio tests require approximately two
seconds of remaining delay after a six-second restart. Their indexed MOV source
establishes video origin zero. This does not establish a general repair for
Matroska sources whose reported start time disagrees with their video packets,
or arbitrary variable-rate gaps.

### Timestamp origins

`SegmentEncoderTimestampOriginMediaTest` covers 19 sparse Matroska source-origin
cases through software H.264/HEVC output. Verified origins zero and four, delayed
and matched audio, fresh output and restarts at source seconds six and 24 must
retain the full colour holds, duration, audio delay and subtitle source interval.
Three audio-first cases require the first movie packet after packet sixteen and
within the bounded 128-packet prefix. Independent fragment/HLS decoding and
production subtitle extraction check media clocks and embedded cues. Two Opus
cases supplement the AAC controls. Complete HLS audio decoding must retain the
full presentation duration, near-silent initial samples and audible tone onset;
a correct MPD delay or MP4 edit list alone cannot satisfy this check.

The shared video probe reads at most 128 packets across all streams. Origin
evidence comes only from the first observed packet of the selected non-attached
movie stream. The qualified source is Matroska H.264 with explicitly zero B-frames
and finite positive cadence below one fps. Its first packet must be a key packet
without a discard flag and have finite nonnegative PTS; reported format start
must be at or after that PTS and format duration must be greater. A mismatched
reported start or positive verified origin selects an explicit common timestamp
anchor. Matched zero origins keep the existing path.

For this Matroska path, duration is normalized once by subtracting the verified
origin. Media encoding seeks in absolute timestamps and shifts video and audio
by the same origin-plus-source-position offset. Subtitle extraction subtracts
only the origin; existing timeline rebasing then applies the requested seek.
With a verified anchor, a positive sparse restart before one nominal frame
interval reads from the file beginning instead of input seeking, preserving
leading physical audio while retaining the requested offset and trimming. Later
restarts keep fast input seeking. The nominal interval is not a proof of general
variable-rate behavior.

Source-origin qualification remains unavailable for missing movie-packet
evidence, malformed metadata, negative origins, B-frame input, other containers
and general variable-rate gaps.

### Audio normalization and trailing silence

Every movie with audio applies `aresample=first_pts=0` after any negative-preroll
trimming and profile filters, whether or not the probe has a verified timestamp
anchor. Positive leading delay becomes actual encoded silence; negative codec
priming is trimmed without asynchronous compensation. Ordinary sources retain
their existing source-origin and seek commands, and video-only output gains no
audio options. HLS therefore receives actual silent samples before the intended
tone instead of relying on an MP4 edit list. The audio-only transcoder is outside
this movie-path normalization.

In the qualified cases where selected audio ends at or before video,
normalization is followed by `apad=packet_size=1024`. If the selected video stream
states its own end through MOV or MP4 stream timing or the Matroska track duration
tag, `whole_dur` pads audio to that end and `atrim` cuts it there. Both tracks then
finish together. If only a container estimate is available, output keeps
`-shortest` so audio still stops at the video end. On FFmpeg 7, `-shortest` buffers
up to ten seconds of frames before publishing the first segment and drops the
final AAC frame that crosses the video end.

Commands with audio also set `-max_interleave_delta 0`. Padded audio runs ahead of
the movie; without strict interleaving, the DASH muxer can flush it before the
first video packet and cut empty leading audio segments. Real silence must cover
the remaining video when selected source audio ends early, including sought
output with no source audio packets left. The producer must terminate without
losing the video tail.

Video-only commands omit padding, trimming, strict interleaving and `-shortest`.
Separate subtitle helpers do not set duration. Longer-soundtrack endpoint
resolution uses qualified selected-video metadata; missing or invalid evidence
retains the container estimate.

When audio exists, one FFmpeg process reads the source through two inputs.
Video input zero retains hardware options, fixture pacing and source selection.
Unpaced audio input one uses two decoder threads and disables video, subtitle
and data decoding. The selected audio ordinal maps from input one. Both readers
share the logical source position and verified-origin translation.

Sparse audio requests a position one nominal source-frame interval before the
generation start, clamped to zero. At a zero-relative position it omits the input seek;
otherwise it retains preroll. Unanchored audio receives the negative difference
between the generation start and its earlier input seek to preserve the
generation clock. Verified-origin audio keeps the existing common offset;
existing trimming removes negative local samples. Video seeking and ordinary
accurate audio seeking are unchanged. Cue alignment can decode farther back than
the requested nominal interval, so that interval is not a universal I/O bound.

Independent audio EOF allows padding to publish playable fragments while video
remains active. Video-only commands retain one input. Cancellation stops the
one process and both source reads.

### Ordinary audio delay

`SegmentEncoderAudioDelayMediaTest` covers sixteen ordinary 25-fps MOV cases
with video beginning at zero and no verified source origin. H.264/HEVC output
and AAC/Opus profiles cover fresh output and six-/24-second restarts of a
thirty-second source with audio delayed eight seconds. Independent fragment and
HLS decoding must retain the full video grid, held colour intervals, continuous
tracks, complete PCM including silence, measured tone onset and the audible tail.
Controls cover 44.1 kHz AAC with DRC, matched PCM audio and encoded AAC with
negative initial priming timestamps. Matched-source waveform correlation and
bounded lag check content alignment without requiring byte-identical PCM.

Ordinary B-frame timestamps are checked from bounded native MP4 decode times,
sample durations and signed composition offsets, with the actual timescale and
unshifted initialization edit. FFprobe timestamps remain diagnostic evidence;
subtracting its first reported PTS must not conceal a displaced presentation.

### Default audio selection

`SegmentEncoderDefaultAudioMediaTest` drives the production probe, stream
manager, encoder and HLS renderer with distinguishable source audio. Commentary
is 220 Hz, mono/44.1 kHz/English; later default dialogue is 880 Hz,
surround/48 kHz/Danish. Selection uses the first marked default in discovery
order, falling back to the first audio track when none is marked. Multiple
defaults choose the first marked track; no audio has no selected ordinal.
The ordinal counts audio streams and does not use the global FFprobe index.
Discovered order stays intact, while raw/serialized probe reconstruction and
direct construction derive the same selected codec, channels, sample rate and
layout for filters and mapping. Native MPD and production HLS/DASH manifest
checks establish selected language; independent HLS probes check actual codec,
sample rate and channels alongside decoded tone identity.

Independent fragment and full HLS decoding require the selected tone and complete
fresh/seek-six tails for AAC and Opus. No-default and multiple-default controls,
eight-second delayed dialogue and delayed unselected commentary check selection
and timing together. Fixture preflight verifies actual defaults and layouts:
MOV PCM supplies the marked-default cases, and Matroska FLAC preserves an
explicitly absent default. The chosen audio keeps its own timestamps and existing
leading-silence normalization; video-origin qualification remains independent.

### Short audio tails

`SegmentEncoderShortAudioMediaTest` drives the real probe, stream manager,
encoder and presentation reader with fourteen-second video and short source
audio. Independent video-fragment and full HLS PCM decoding require complete
decoded video, including frame count, colour sequence and track duration, plus
tone before the audio boundary and measured silence through the tail.
AAC/Opus fresh output and exhausted-audio seeks, leading delay plus trailing
silence, a selected second track ending before an unselected track and matched
audio controls check selection, coverage and bounded termination.
An encoded AAC source control verifies a restart after its final source packet.
Two paced AAC/Opus cases require increasing, independently decodable joint
video/audio prefixes while the presentation remains dynamic and the producer
runs, followed by complete final tails. A cancellation case stops the same
producer after playable prefixes appear. Receipts retain publication timing,
closed audio/video lead and process memory; these fixture measurements do not
establish general resource guarantees. A separate longer-soundtrack audit shows
the final producer reaches the actual video endpoint. Current probe duration
uses qualified selected-video timing when available; the earlier container-based
failure is retained as historical evidence, and the estimate fallback remains
unqualified for exact-end behavior.

### Selected video endpoint

`SegmentEncoderVideoEndpointMediaTest` covers fourteen selected-video endpoint
cases through the real probe, stream manager, encoder and presentation reader.
MOV/Matroska sources with longer audio, B-frames, delayed video, positive starts
and sparse held frames must retain complete decoded video, bounded audio and
exact-end coverage using the DTO duration. Fresh and near-end AAC/Opus cases
check source identity, content and finite process completion. Distinct audio
markers at source seconds 20 and 26 verify the clock through sparse seeks,
including the retained Matroska cue-layout regression.

The [Selected video endpoint](../../../CONCEPTS.md#selected-video-endpoint) uses
the same first non-attached movie stream as encoding. For MOV/MP4, its start at
or after the normalization origin plus its positive duration span gives the
endpoint. Valid rational time-base values take precedence over textual seconds,
allowing only FFprobe's decimal rounding differences and rejecting
contradictory metadata. Matroska uses the selected track's strictly parsed
`DURATION` tag as an absolute endpoint; inferred stream duration is not
independent evidence. Normalize once by the verified source origin, or by an
ordinary finite nonnegative format start without inventing an origin. A
qualified result must be finite and positive.

Missing, malformed or unqualified timing retains the prior container-duration
estimate, including its existing verified-origin adjustment. That fallback does
not establish an exact video end. The bounded 128-packet prefix cannot determine
an unknown endpoint; no second probe, full-file scan, frame-rate inference or
endpoint cache is added. Serialized duration preserves the resolved value without
another origin subtraction. Fresh worker playback metadata changes; existing
catalog records are not backfilled or reset, and native manifest clocks stay based
on actual media.

## AAC implementation in the suites

The CI image runs the project's FFmpeg build, which carries Bånder Audio Core,
fdk-aac and FFmpeg's built-in AAC codec. The functional audio stream tests run the
real rendition encoder with the `transcode.aac_implementation` setting at its
default, so their AAC renditions encode with Bånder Audio Core and AAC sources
decode with it. Unit tests stub the setting and the codec listing to cover each
position and a binary that lacks one.

To run the media suites under another position, set it in the test database before
the run, or change the default in `TranscodeSettingDefinitions` locally; no
environment variable selects it for the backend suites. The adaptive browser check
takes `BAANDER_TEST_AAC_IMPLEMENTATION` (`bac` by default, `fdk_aac` or `ffmpeg`)
and encodes its DASH audio with that implementation. The image build itself checks
every implementation: its smoke test encodes and decodes with Bånder Audio Core,
and the capability gate requires each implementation as encoder and as decoder.

