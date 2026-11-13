# Bånder Audio Core

A self-contained AAC-LC and HE-AAC v1/v2 encoder and decoder in C++17
with a C API, a command-line tool and a Node-API binding for the Electron
app, over ADTS, LOAS/LATM and raw access units. Its
[lossless mode](#lossless-mode) is an encoder and a decoder for native
FLAC streams (RFC 9639) in the same library, tool and binding.

**Version:** 0.2.0
**License:** Apache-2.0 for Baander-authored code; see [Provenance](#provenance).
**Standard:** ISO/IEC 14496-3 (MPEG-4 Audio), AAC-LC, HE-AAC v1 and v2, 1024- and 960-sample frames; ADTS, LATM/LOAS (ISO/IEC 14496-3 1.7) and raw access units with an AudioSpecificConfig.

## What it does

### Decoder

Decodes every AAC-LC tool of ISO/IEC 14496-3 subpart 4: long and short
windows with window grouping, sine and KBD window shapes, section and
scalefactor coding with all eleven spectral Huffman codebooks and escape
sequences, pulse data, TNS, M/S stereo, intensity stereo and perceptual
noise substitution (including correlated noise in M/S bands). Every
raw data block element is handled: SCE, CPE and LFE channels, coupling
channel elements (dependent and independent, with their gain lists),
program config elements (with the height extension of ETSI TS 101 154)
and the channel configurations 1 to 7, 11, 12 and 14 of the standard; a
DSE is read for DVB ancillary data and otherwise skipped. A stream without a channel configuration or PCE
takes its layout from the elements it carries. The output is emitted in
the canonical order left, right, centre, LFE, back left, back right, then
the extra pair of a 7.1 layout (`aac_decoder_channel_mode` names it:
front centre, back behind the side pair, top front or the PCE rear
surround layout), or downmixed to stereo or mono with FFmpeg's default
coefficients. ADTS headers set the sample rate and channel configuration
of the stream, and a header may carry several raw data blocks; a
CRC-protected frame is verified over the header and the covered bits of
each element (ISO/IEC 13818-7 6.2) and a mismatch conceals the block.
Frames of 960 samples (120 per short window) decode when an
AudioSpecificConfig announces them or `aac_decoder_set_frame_length`
says so.

Transport: `aac_decoder_feed` and `aac_decoder_decode_next` take a byte
stream of any chunking. ADTS and LOAS are detected from their sync words
and resynchronised across damaged bytes (a frame is trusted once its
successor's header agrees with it in rate, layer, profile and layout);
LATM AudioMuxElements with the StreamMuxConfig in band (audioMuxVersion
0 and 1) or out of band, and raw access units, are taken one packet per
feed. An AudioSpecificConfig (`aac_decoder_set_config`) presets the rate,
layout, frame length and, with explicit backward compatible or
hierarchical signalling, the SBR output rate and the parametric stereo
pair before any data arrives. The one-shot `aac_decoder_decode` takes
one ADTS or LOAS frame, one LATM element or one access unit.

Error concealment is on by default: a block that fails to parse, fails
its CRC or never arrives is replaced. The elements decoded before the
failure keep their data; each missing channel continues its last good
spectrum with random signs and a fade of 3 dB per frame, then from the
fifth frame substitutes noise at the stored band energies down to
silence (`AAC_CONCEAL_NOISE` substitutes from the first frame); the
window sequence follows the last good frame so the overlap stays
consistent; SBR keeps the previous envelope and noise floor with the
envelope stepping down 3 dB per frame, and parametric stereo keeps its
parameters; the first good frame afterwards is crossfaded in over the
frame. Delta-coded SBR and PS data after a loss is clamped rather than
refused until a frequency-coded frame re-anchors it. Concealed frames are
counted (`aac_decoder_concealed_frames`, `aac_decoder_last_frame_concealed`);
with `AAC_CONCEAL_OFF` the frame returns `AAC_ERR_DECODE`.

The error resilient object types (ER AAC-LC, AAC-LD and AAC-ELD) may
switch on the three error resilience tools in their AudioSpecificConfig,
and the decoder reads all of them: virtual codebook 11
(`aacSectionDataResilienceFlag`: five-bit section codebooks, one band
per escape section, and the virtual codebooks 16 to 31 whose magnitude
limits expose a damaged escape sequence), reversible variable length
coded scalefactors (`aacScalefactorDataResilienceFlag`: when the forward
decoding fails its length and reverse global gain checks, the data is
decoded backward from its end as well, the consistent prefix and suffix
are kept and the bands between them interpolated; the frame then counts
as concealed) and Huffman codeword reordering
(`aacSpectralDataResilienceFlag`: the presorted codewords are read back
from their segments, priority codewords first, the rest in alternating
directions with segment hopping). A value above a virtual codebook's
limit or an inconsistent reordering conceals the frame, or returns
`AAC_ERR_DECODE` with concealment off. The tools are decoded only; the
encoder's low-delay streams do not use them. They were written without
the standard's text at hand: the virtual codebook limits and the ER
section syntax are verified end to end (FFmpeg decodes our hand-built ER
AAC-LC frames to 135 dB), but the RVLC escape codebook is a stand-in
prefix code rather than the standard's table, and the RVLC field order
and the reordering rules (presort priority, segment layout, direction
alternation and hopping) follow our reading of the standard, so streams
from other encoders that use these two tools are not known to decode
until the tables and rules are checked against ISO/IEC 14496-3.

A look-ahead peak limiter (`aac_decoder_set_limiter`, off by default)
keeps the output within full scale after a downmix: the gain ramps down
over the attack window so that it has reached the needed value when the
peak leaves the delay line, recovers at a constant rate in decibels over
the release time, and passes samples that never needed a reduction
unchanged; its delay is the attack window, reported by
`aac_decoder_delay` and drained at the end of the stream by
`aac_decoder_flush`.

MPEG-4 dynamic range control (ISO/IEC 14496-3 4.5.2.7) is parsed from
every fill element whose extension payload carries `dynamic_range_info`
(the PCE tag, excluded channels, up to sixteen bands with their tops and
interpolation scheme, the program reference level and the band gains)
and attached to the channel element the fill element follows, or to the
program when it comes first; the DVB ancillary data of a data stream
element (ETSI TS 101 154) supplies the heavy compression gain. When
`aac_decoder_set_drc` enables it, the band gains (scaled by the boost
and cut percentages, a quarter dB per step) multiply the spectral lines
of each channel before the inverse transform, where the window overlap
blends one frame's gain into the next; on an SBR stream they multiply
the 64 QMF bands after the high band is generated, interpolated across
the frame's slots from the previous frame's gains, so that the
replicated band is scaled as well. A target reference level adds the
fixed gain that brings the stream's program reference level to it,
heavy compression replaces the band gains by the ancillary data's gain,
and excluded channels are left alone. `aac_decoder_has_drc` and
`aac_decoder_program_ref_level` report the last frame.

Against FFmpeg's decoder the output agrees to floating-point precision
(135 dB difference SNR) on FFmpeg-encoded streams across all sample rates
from 8 to 96 kHz, every layout from mono to 7.1 (including PCE-defined
ones such as 6.1), the stereo and mono downmixes, 32 to 320 kbit/s and
the `fast` coder; see [reference-comparison.md](reference-comparison.md).

An HE-AAC stream decodes with its spectral band replication (SBR, ISO/IEC
14496-3 4.6.18): the SBR extension of each SCE or CPE is parsed from its
fill element, the 32-band QMF analysis of the core, the LPC-based high
frequency generator with inverse filtering, the envelope and noise-floor
adjustment with gain limiting, sinusoid insertion and smoothing, and the
64-band synthesis follow the standard, with every frame class and the
header-reset path. Once a frame carries SBR data the stream is decoded at
twice the core rate until a reset: `aac_decoder_frame_size` becomes 2048,
`aac_decoder_output_sample_rate` twice `aac_decoder_sample_rate`, and
elements without SBR data (an LFE, say) pass through the same filterbanks
so that all channels keep the same delay. `aac_decoder_get_sbr_ps` reports
SBR and parametric stereo data in the last frame.

An HE-AAC v2 stream (a mono element whose SBR extension carries
parametric stereo, ISO/IEC 14496-3 8.6.4) decodes to a stereo pair: the
PS data (IID, ICC and, when present, IPD and OPD parameters in 10, 20 or
34 bands) is parsed from the SBR extension, and the 64-band QMF signal
goes through the hybrid filterbank, the decorrelator with its
fractional-delay all-pass links and transient attenuation, and the
interpolated mixing matrices before both channels are synthesized. Once
the element has carried PS data `aac_decoder_channels` reports 2 for a
stream request of 0 until a reset, while `aac_decoder_stream_channels`
stays 1. Against FFmpeg's decoder the SBR and PS output agrees to
floating point precision (100 to 134 dB difference SNR on HE-AAC v1,
125 to 128 dB on HE-AAC v2) on FDK-encoded streams at 44.1 and 48 kHz,
mono, stereo and 5.1, constant and variable bitrate. The 34-band and
IPD/OPD paths follow the standard but are not covered by the test
streams, which FDK encodes with 20 bands and without phase parameters.

The low-delay object types decode too. AAC-LD (object type 23) frames
carry 512 or 480 samples in one long window with the LD band tables,
TNS limits and the sine or low-overlap window of ISO/IEC 14496-3 4.6.17;
AAC-ELD (object type 39) uses the low-overlap filterbank of 4.6.20, whose
synthesis window spans four frames, and the ELD channel stream syntax
without window fields, pulse data or gain control. Both use the
error-resilient framing: the element sequence is fixed by the channel
configuration, with no element ids and no end marker, so these streams
need an AudioSpecificConfig (LOAS, LATM or raw access units; ADTS cannot
carry them). `aac_decoder_object_type` names the type in use.
`aac_decoder_set_eld_downscale` plays an ELD stream at one half or one
quarter of its rate with a filterbank of a half or a quarter of the
length, for devices that cannot run the full rate. An ELD stream whose
configuration carries LD-SBR has one low-delay SBR state per channel
element: the `sbr_data` of each element follows the channel elements
(behind a 10-bit CRC when the config asks for one), and the output
stage runs the low-delay filterbank, so with dual rate the frame doubles
and the output rate is twice the core rate, as with HE-AAC. Against
FFmpeg's decoder our LD and ELD streams agree to floating point
precision (135 to 137 dB difference SNR); FFmpeg has no ELD-with-SBR
decoder and our LD-SBR filterbank windows and frame grid are our own
design where the standard's tables were not available, so LD-SBR
streams are verified by roundtrip only and their interoperability with
other decoders is unverified.

A USAC stream (Unified Speech and Audio Coding, ISO/IEC 23003-3, object
type 42, as the xHE-AAC profile constrains it) decodes from LOAS/LATM or
from raw access units with an AudioSpecificConfig (the MP4 form; ADTS
cannot carry it). The `UsacConfig` in the configuration fixes the element
list, so a frame carries no element ids: the walker runs the configured
SCE, CPE, LFE and extension elements in order, reads the audio pre-roll
extension at a stream access point (its embedded configuration
reconfigures the decoder; a cold decoder decodes the pre-roll frames to
warm its state and drops their output, a warm one skips them), honours
`usacIndependencyFlag`, and skips the extension elements it does not
know by their escaped length.
Each core channel is decoded by the frequency-domain core (the USAC
window sequences and shapes, the scale factors, the context based
arithmetic coder for the spectrum with its reset at an independent frame,
noise filling, TNS, and in a channel pair M/S per band and complex
stereo prediction with the MDST estimate from the current and previous
frame) or by the linear prediction core (ACELP and TCX subframes, the
LPC filter sets from the LSF first-stage and algebraic vector quantisers,
forward aliasing cancellation at every core and mode boundary, the bass
post filter), switching per frame with the transitions the standard
describes; the 768-line core of the 8:3 ratio uses its own band tables.
Time warping is read and refused, since xHE-AAC excludes it. Enhanced
SBR follows the core at the 2:1, 8:3 and 4:1 ratios with the SBR header
in the configuration and the pre-flattening of the patch source bands;
the harmonic transposer, predictive vector coding of the envelopes and
inter-TES are implemented per the standard but unverified, because the
FDK encoder never emits them and no stream at hand uses them. MPEG
Surround 212 widens the one core channel of a CPE to two from its CLD,
ICC and IPD parameters through the hybrid filterbank, the decorrelator,
the temporal shaping tools and, when the stream codes it, the residual
signal. MPEG-D DRC and loudness (ISO/IEC 23003-4) are parsed from the
configuration extensions and the `uniDrc` extension elements; the
selection process with the effect fallbacks, album mode and peak
protection picks the gain sets, and the gains are interpolated and
applied in the time domain. As in the gain decoder's default delay mode,
the gains of an access unit shape the output of the next one; after a
reset the first frame (on random access, the pre-roll's) applies 0 dB,
and the next ramps from there to the first gains received. The gain
curve agrees with libxaac's within 0.02 dB rms and with the FDK
decoder's within 0.08 dB rms (0.02 dB without SBR), which is how far
the two references differ from each other. `aac_decoder_set_loudness` (on by default,
normalising to -24 LUFS), `aac_decoder_set_drc_effect` and the
`aac_decoder_loudness` readback are the C API of that path; the binding
options are listed under the Node-API binding. Concealment covers a lost
USAC frame (the FD spectrum fades, the LPD excitation repeats, and the
arithmetic coder resets at the next independent frame); the limiter, the
layouts and the output delay report are shared with the other object
types, and `aac_decoder_set_drc` (MPEG-4 DRC) reports unsupported on a
USAC stream. `bac` and the binding decode USAC through the streaming
API.

The three orders of the stereo tools in a CPE, named by
`stereoConfigIndex`: with 1 the SBR of the single core channel runs first
and MPS 212 widens the full-band QMF signal from the parameters alone;
with 2 the same order carries a residual signal that MPS 212 adds in its
low bands; with 3 MPS 212 runs on the core band with the residual before
any SBR, and the two-channel SBR data then generates the high band of
each output channel from its own low band. For 1 and 2 the SBR hands the
lowest QMF bands to MPS 212 six slots ahead of the rest, as the reference
decoder regroups them; for 3 the parameter grid follows the input timing
with no such prefix.

Against the FDK decoder (loudness normalisation off, 48 kHz output, delay
0 in every case) the decode of the FDK xHE-AAC encoder's streams in
`tests/data` reaches 81 to 84 dB difference SNR on the FD core without
SBR, 80 dB with 2:1 SBR and MPS 212 with residual coding
(stereoConfigIndex 3), 80 dB on a mono 2:1 SBR stream (50 dB above
7 kHz, the floor of the 16-bit reference), 61 dB on speech with the LPD
core and 4:1 SBR and 52 dB with 2:1 SBR, and 24 to 26 dB on music with
the LPD core at 8:3 and 4:1 with MPS 212 (stereoConfigIndex 1): there
libxaac itself reaches 20 to 27 dB against FDK, the two references
disagreeing by 38 to 50 dB in the SBR bands, so the limit is the LPD
core on music, not the stereo tools. Parsing is bit-exact with libxaac on
every stream (core, SBR and MPS 212), and the MPEG-D DRC normalisation
gain matches the FDK decoder on every stream. stereoConfigIndex 2 is
verified by construction only, since no stream at hand uses it. FFmpeg's
USAC decoder (master at the time of writing) decodes the 2:1 and 8:3
streams wrongly and served as no oracle for them; see
[reference-comparison.md](reference-comparison.md).

An MPEG Surround stream (ISO/IEC 23003-1) carries a 5.1 programme as a
mono or stereo downmix coded by AAC-LC plus a spatial payload, and the
decoder rebuilds the six channels from it. The payload travels as
`EXT_SAC_DATA` extension payloads in the fill elements of a block
(gathered across them, the in-band `bsSacHeader` ahead of the spatial
frame), or its configuration comes from a two-layer LATM stream whose
second layer signals object type 30 with the `SpatialSpecificConfig`;
both land in the same place. Three trees are decoded: 5151 and 5152
build 5.1 from a mono downmix through five one-to-two (OTT) boxes in
two orders, and 525 from a stereo downmix through a two-to-three (TTT)
box that rebuilds left, right and centre, followed by three OTT boxes.
The baseline tools are implemented: the OTT and TTT boxes (TTT
prediction modes 0 and 1, energy modes 4 and 5 and the
centre-subtraction modes 2 and 3), the decorrelators of the baseline
profile with their ducking, residual coding (the residual channel
elements decoded by the core and mixed in their bands in place of the
decorrelated signal), smoothing, the temporal shaping tools (subband
temporal processing and guided envelope shaping), arbitrary downmix
gains, the fixed gains and the matrix mode. The core is aligned by the
560 samples the reference decoder applies its parameters late, analysed
into the QMF domain (or taken as the SBR's QMF output when the core has
one), run through the spatial decoder and synthesised; the output is six
channels in the canonical order, or the existing stereo downmix when two
channels are requested, while `aac_decoder_stream_channels` keeps
reporting the core's channels. `aac_decoder_has_surround` (`hasSurround`
in the binding) says that the last frame carried a surround payload that
was decoded, and `aac_decoder_delay` includes the surround decoder's
delay: 1521 samples for AAC-LC plus surround (560 of input alignment,
384 of hybrid delay and 577 of QMF synthesis), 960 behind an SBR core
(nine QMF slots of alignment and six of hybrid delay, the SBR
filterbanks being part of the encoder's priming), plus 64 per QMF slot
a spatial frame gathers beyond the core frame. AAC-ELD v2, an ELD core
of 512 samples with the low-delay 2-1-2 payload in the ELD extension
(its configuration in the ELD-specific configuration, the frame as the
extension payload of type 9), decodes to stereo through the low-delay
filterbank pair, the low-delay decorrelator and the 2-1-2 mixing, with
256 samples of added delay. The normative tables (the CPC codebooks, the
quantisers, the decorrelator configurations and the filter prototypes)
are ISO/IEC 23003-1 data transcribed from libxaac (Apache-2.0) with
attribution, as the USAC tables are.

No free encoder writes MPEG Surround 5.1, so the test tool `mps-enc`
(`tools/mps-enc.cpp`, built next to `bac`) writes the committed streams
from 5.1 or stereo WAV with the codec's own AAC-LC or ELD encoder, and
libxaac is the reference decoder: its parameter mapping equals ours bit
for bit on every frame, and its decodes of the committed streams are the
oracle. The FDK decoder binary pads the AAC-LC core to six channels
without running its surround decoder, so it is no oracle for 5.1, though
it does decode ELD v2. Against libxaac (delay 0, the first second, per
channel) the 5151 tree reaches 62 to 72 dB, the 5152 tree 64 to 72 dB,
the 525 tree 63 to 73 dB in TTT prediction mode 1 and 47 to 66 dB in
energy mode 5 on a broadband programme; ELD v2 reaches 77 dB against
libxaac and 77 dB against the FDK decoder. Limits: TTT prediction mode 0
and the centre-subtraction modes 2 and 3 are verified by construction
only, since libxaac's own output breaks there; energy modes whose two
level differences exceed about 48 dB cannot be matched against libxaac's
fixed-point weights; the object type 30 form is checked against the
in-band form only (40 dB or better); SBR input (HE-AAC plus surround,
the SBR's QMF output handed to the spatial decoder) is wired but
exercised by no stream; LD-SBR with the spatial payload, temporal
shaping in the low-delay path and ELD frame lengths other than 512 are
not covered; the 7.1 trees (7271, 7272, 7571 and 7572), the HRTF and
binaural output modes and the 3D audio mode are refused. Figures per
stream and channel are in [reference-comparison.md](reference-comparison.md).

### Encoder

The encoder follows the structure of the Fraunhofer FDK encoder and
3GPP TS 26.403:

- block switching with one block of lookahead and FDK's window grouping
  per attack position; KBD windows for long frames, sine for short;
- a masking model with Bark-scale spreading, a PCM-resolution floor and
  pre-echo control;
- a bitrate-dependent lowpass (FDK's bandwidth table, full band from
  128 kbit/s per channel) and a per-band minimum SNR derived from the bits
  available per Bark, adapted for quiet bands and local peaks and valleys;
- TNS with FDK's analysis: lag-windowed, energy-normalized
  autocorrelation over quarters of the range, an upper and a lower filter
  on long windows (merged when they agree), one filter per short window
  with 3-bit coefficients, and filters shared between the channels of a
  pair when they nearly coincide;
- perceptual noise substitution at 28 to 48 kbit/s per channel in the
  constant-rate modes (FDK's AAC-LC tuning): bands above 4.1 kHz whose
  quarters carry similar power and whose lines are unpredictable are sent
  as a noise energy instead of coefficients;
- intensity stereo when the bitrate is below five times the lowpass
  frequency: regions of at least six bands where the channels correlate
  above 0.95 and are panned away from the middle carry the left channel
  and a level difference;
- per-band M/S stereo chosen by perceptual entropy;
- one to eight channels as the standard layouts (mono, stereo, 3.0, 4.0,
  5.0, 5.1, 6.1, 7.1): each syntactic element (SCE, CPE, LFE) decides its
  own windows, TNS, stereo tools and noise substitution, the LFE element
  codes the lowest twelve lines in long windows only, and one rate loop
  spends the frame's bits across all elements; the 6.1 layout and the 7.1
  layouts other than configuration 7, which the ADTS header cannot name,
  carry a program config element in every frame (`aac_encoder_set_channel_mode`
  picks the 7.1 layout: back pair behind the side pair by default, front
  centre pair, top front pair, or the rear surround layout with the
  height extension);
- 1024-sample frames, or 960 (`aac_encoder_set_frame_length`) with the
  standard's 960-sample band tables, a 1920-point transform and SBR on
  15 envelope slots;
- ADTS with an optional CRC, LOAS, LATM with the StreamMuxConfig in band
  or out of band, or raw access units (`aac_encoder_set_transport`),
  with the AudioSpecificConfig from `aac_encoder_config` in implicit,
  explicit backward compatible or hierarchical SBR/PS signalling
  (`aac_encoder_set_signaling`; ADTS always signals implicitly); the
  MPEG-2 ADTS variant (`aac_encoder_set_mpeg2`) sets the header's id and
  disables noise substitution, which MPEG-2 AAC lacks;
- thresholds raised towards the bit budget in the fourth-root domain so
  that quiet bands become holes before loud bands lose precision, with
  hole-avoidance flags from spread energy and top-down band release when
  the minimum-SNR floors still overshoot;
- a bit reservoir driven by perceptual entropy with FDK's save/spend
  curves (CBR and ABR), constrained and true VBR by quality;
- scalefactor search against the resulting noise targets, scalefactor
  smoothing where it saves bits, and codebook sectioning by dynamic
  programming over all codebooks.

HE-AAC v1 and v2 are encoded with a spectral band replication encoder
(ISO/IEC 14496-3 4.6.18, structured after 3GPP TS 26.404) and a
parametric stereo encoder (8.6.4). With `AAC_AOT_SBR` or `AAC_AOT_PS`
the input runs at twice the core rate and a frame is 2048 samples: each
SCE or CPE of the layout gets an SBR encoder that analyses its channels
with the 64-band QMF bank, hands the core coder the lower half of the
spectrum resampled to half rate, and describes the upper half per frame
as an envelope grid (fixed or transient-aligned borders with the
relative-border parity the syntax demands), quantized envelope energies
on the high or low frequency table, noise floors from the tonality of
the original against the patched bands, inverse filtering levels, and
delta coding in time or frequency, whichever is cheaper. The SBR
configuration (start and stop frequencies, noise bands, noise floor
ceiling, frequency scale) comes from FDK's tuning table for the core
rate, channel count and bitrate; the core lowpass of an SBR element is
its crossover, so that the bands the generator copies upwards carry
signal. The extension travels in a fill element after its channel
element and its bits are part of the frame's budget; the ADTS header
keeps the AAC-LC profile and the core rate, so decoders detect SBR from
the data (implicit signalling), as they do for FDK's streams. With
`AAC_AOT_PS` a stereo input is coded as one SCE: both channels are
analysed, their inter-channel intensity difference and coherence are
estimated per hybrid band and envelope, quantized on the coarse grids
and coded as frequency or time deltas, and an energy-preserving downmix
is what the core and SBR encoders code. Phase parameters are not sent.
Against the FDK encoder at the same settings, the decoded output lines
up within one QMF slot in time and within about half a decibel in the
band levels; an LFE in an HE-AAC layout goes through the same QMF
resampling with no SBR data.

With `AAC_AOT_ER_LD` or `AAC_AOT_ER_ELD` the encoder codes 512 (480
through `aac_encoder_set_frame_length`) samples per frame in one long
window with the LD or ELD filterbank, writes the error-resilient framing
and defaults to LOAS. Its delay is one frame plus the filterbank's
overlap: 1024 (960) samples for AAC-LD, 768 (720) for AAC-ELD.
`aac_encoder_set_ld_sbr` adds LD-SBR to an ELD encoder: with dual rate
the core runs at half the input rate and a frame is 1024 (960) input
samples; the SBR headers travel in the AudioSpecificConfig and the
payloads after the channel elements. The DRC metadata path is not
available for the low-delay types, whose frames carry no fill elements.

`aac_encoder_set_afterburner` switches a second quantisation pass after
the rate loop: for every band it measures the real quantisation noise
against the band's allowance and moves scalefactors one step at a time
where the noise falls most per bit, paid from the frame's slack in the
constant-rate modes or from bands with margin in the quality modes,
re-sectioning the codebooks and counting the bits exactly after every
move within the scalefactor limits. It is on by default for AAC-LC and
HE-AAC, where it adds 0.02 to 0.07 PEAQ points for about 70 % more
encoding time, and off for AAC-LD and AAC-ELD.

Three tuning controls apply to every profile and leave the stream
unchanged byte for byte while they are unset.
`aac_encoder_set_peak_bitrate` caps every frame at its share of a peak
bitrate: the rate loop's per-frame limit becomes the smaller of the AAC
limit and that share (rounded down to whole bytes), and the bit
reservoir shrinks so that it never lends a frame more than the cap
allows. In CBR and ABR the cap may not lie below the bitrate.
`aac_encoder_set_bandwidth` replaces the tuning table's lowpass of the
core; an SBR core is always coded up to its crossover, so the override
leaves HE-AAC streams and xHE-AAC streams running SBR as they are. The
decoded output's -50 dB bandwidth lands within one scalefactor band
above the asked value, because a band that holds the cutoff is coded
whole. `aac_encoder_set_vbr_mode` takes the five VBR modes the FDK
encoder names, as stereo targets of 32, 72, 112, 148 and 228 kbit/s
(scaled per channel): the target becomes the nominal bitrate, whose
rate loop and reservoir bound every frame as in ABR (so a hard passage
cannot overshoot it), each frame's thresholds come from the mode's
quality (no surplus is spent on easy frames), and every frame carries
the VBR marker. A peak bitrate below
the mode's target lowers the mode to the highest one that fits. The
qualities were chosen on a 48 kHz stereo music excerpt; the reference
comparison has the figures per mode against the FDK encoder.

### Loudness

The loudness meter (`include/loudness.h`, `src/loudness.cpp`) measures a
programme in one pass per ITU-R BS.1770-4: the K-weighting as two
biquads whose coefficients are derived for the sample rate from the
analogue prototypes behind the standard's 48 kHz table (and match it
there; the DRC gain generator uses the same filter), the mean square
over 400 ms blocks with 75 % overlap, the absolute gate at -70 LKFS and
the relative gate 10 LU below the mean of the blocks above it. It takes
1 to 6 channels in the canonical order L, R, C, LFE, Ls, Rs (the LFE
does not count, the surrounds count at +1.5 dB). Alongside the
integrated loudness it reports the loudness range of EBU Tech 3342 (3 s
windows every 100 ms, the absolute gate at -70 LUFS, the relative gate
20 LU below the mean, the spread between the 10th and the 95th
percentile), the maximum momentary and short-term loudness of EBU Tech
3341 (ungated), the sample peak and the true peak of BS.1770-4 Annex 2
(4 times oversampled below 96 kHz, twice from 96 kHz, through a 25-tap
Kaiser-windowed sinc per phase). A result needs 400 ms of input; the
range and the short-term maximum need 3 s, and `has_range` and
`has_short_term` say when they exist. No level is reported below
`AAC_LOUDNESS_SILENCE` (-150 dB), which digital silence reads. On 48 kHz
stereo the meter runs at about 300 times real time.

`aac_loudness_info_write` writes the loudnessInfoSet() of ISO/IEC
23003-4 that a USAC configuration carries in the
ID_CONFIG_EXT_LOUDNESS_INFO extension: one loudnessInfo() for the
programme as coded (drcSetId 0, downmixId 0) with the sample peak, the
true peak, the program loudness, the anchor loudness when the caller
supplies one, the loudness range and the momentary and short-term
maxima, all marked BS.1770-4 and "accurate"; `aac_loudness_info_bits`
counts the bits, and the configuration extension pads them to bytes.
`aac_loudness_info_set_write` writes the same entries for several DRC
sets, one per drcSetId, which the encoder's DRC gain sets use.
The decoder's `aac_unidrc_parse_loudness` reads the set back. Checked
against FFmpeg's `ebur128` filter on the EBU test signals that can be
synthesised (sine levels in mono, stereo and 5.1, the three gating
cases, the four loudness range pairs, bursts, the true-peak cases) and
on the FDK decoder's output of the committed USAC streams: the
integrated loudness agrees within 0.05 LU, the range to the figure, the
true peak within 0.04 dB; at a quarter-rate sine FFmpeg's resampler
overshoots the true peak by 0.6 dB where this meter reads 0.1 dB under
it, and on material with a single 3 s window FFmpeg reports a range of
20 LU where this meter reports 0. Written into the configuration of
two FDK streams (every StreamMuxConfig and the UsacConfig copy inside
each audio pre-roll, which the decoders compare), the set leaves the
FDK decoder's and libxaac's output unchanged, FFmpeg master reads the
program loudness from it and normalises by it with `-target_level`
(from the MP4 form; its LATM decoder does not take USAC), and this
decoder normalises by it. The suite `test_loudness` holds the
synthetic cases against the standards' figures, the derived K-weighting
against the 48 kHz table, the feed chunking, the writer's bit layout
against hand-computed fields and the FDK encoder's own set, the entries
per DRC set, and the round trip through the decoder's parser.

The encoder carries the figures in two forms. `aac_encoder_set_loudness_info`
takes an `AacLoudnessInfo` before the first frame of an xHE-AAC stream and
writes the loudnessInfoSet() into the UsacConfig as the
ID_CONFIG_EXT_LOUDNESS_INFO extension, so that every StreamMuxConfig, the
AudioSpecificConfig of `aac_encoder_config` and the copy inside each audio
pre-roll declare it; NULL removes the set, and a second call replaces it.
That is the two-pass form: the whole programme through the meter first,
then the encode (`bac enc --loudness` does both). The one-pass form,
`aac_encoder_set_loudness_measurement`, makes the encoder feed every frame
it codes to a meter of its own, which `aac_encoder_loudness` reads at any
time after 400 ms of input; the meter starts over with each stream after
a flush and keeps the last stream's figures until then. A stream's
configuration cannot change once it has begun, so the one-pass figures
are for an MP4 header or for a second encode, not for the stream they
were measured from. The meter (`aac_loudness_create`, `_feed`, `_result`)
and `AacLoudnessInfo` are public API in `aac.h`; `loudness.h` keeps the
writer. The meter changes no output byte, and the set changes the
configuration alone: the raw access units of a stream without pre-roll
are byte-identical with and without it. On a stream carrying the set,
FFmpeg master (from the MP4 form, `-target_level`) and our decoder apply
the same normalisation gain, and the FDK decoder and libxaac decode it
unchanged. The suite `test_encoder_loudness` covers both forms.

With `AAC_AOT_USAC` the same analysis, psychoacoustics, TNS, M/S decision
and rate loop feed an xHE-AAC (ISO/IEC 23003-3) writer instead of the
AAC-LC one: 1024-line frames of the frequency-domain core, mono as a
single channel element and stereo as a channel pair with a common
window and per-band M/S, every band's scale factor coded (the USAC
rule, with the first as the global gain), the spectrum through the
context-based arithmetic coder, and noise filling of the lines
quantised to zero (on by default; the noise level follows the level of
those lines, the noise offset centres the empty bands on their
neighbours). Every frame is independent once a second (the arithmetic
context reset, a decoder can start there) and carries the audio
pre-roll extension with the previous access unit, so a decoder joining
the stream at that frame hears it whole; the UsacConfig in the pre-roll
is the stream's, extensions included. A fill element closes the frame
when fewer than five bits follow the last arithmetic payload, which
keeps the decoders whose range decoder reads past the frame's end in
step. The output is LOAS/LATM by default, raw access units with the
USAC AudioSpecificConfig for MP4, never ADTS. A LOAS frame or LATM
element with the configuration in band carries the StreamMuxConfig at
the first frame and at every immediate playout frame (at every
independent frame that opens a segment when the pre-roll is off),
where a decoder can start and a muxer cuts, and sets useSameStreamMux
elsewhere, as the FDK encoder does; a decoder that joins between two
of them skips the frames up to the next one. (AAC-LC and HE-AAC frames
are each a starting point and carry it in every frame.) The afterburner
and the AAC DRC metadata are refused, as the USAC frame has no place for them;
`aac_encoder_set_usac` sets noise filling, the pre-roll, the independent
frame schedule and the segment size ceiling (below), a stream
identifier, whether enhanced SBR and MPS 212 are on, off or chosen
by bitrate (MPS 212 needs SBR, so asking for it with SBR off is
refused), and the MPEG-D DRC gain sets (below). Fill the structure
with `aac_usac_encoder_options_init` first and set only the fields that
change, so the call stays correct when the structure gains a field;
`aac_encoder_set_loudness_info` adds the loudness info set (see
Loudness). The AAC-LC output is unchanged byte for byte.

For streaming, the immediate playout frames are where a muxer cuts
segments (an HLS or DASH segment, an MP4 fragment) and where a player
joins. The schedule sets the distance from one to the next in frames
(`independent_interval_frames`), milliseconds (`_ms`) or output samples
(`_samples`); the first one set wins in the order samples,
milliseconds, frames, a value in samples or milliseconds is rounded up
to whole frames of the output rate (2048 samples while SBR runs), and
none set keeps one second. `independent_max_distance_frames` bounds the
distance whatever the interval says. The frame before each immediate
playout frame (two before it with SBR) is independent too, since the
pre-roll repeats those frames and a decoder starts from the first of
them. `aac_encoder_request_independent_frame` makes the next frame
independent at any point of the stream, with the immediate playout
frame following it by the pre-roll's frame count (the same frame when
the pre-roll is off) unless one is due sooner, and restarts the
interval from there. `segment_max_bytes` caps the bytes of the access
units from one immediate playout frame to the next, the pre-roll
included: the rate loop caps each frame at the segment's remaining
budget spread over its remaining frames, never below what the frame's
side information needs, in every rate control mode. The quality falls
while the ceiling binds (about 3 dB of SNR on dense transients at
128 kbit/s with the segments cut by a third), and a ceiling below what
the side information and the pre-roll need cannot be honoured.
`aac_encoder_last_frame_info` reports the last produced frame for the
muxer: its index, whether it is independent, the bytes of its pre-roll
(a frame with one opens a segment), its bytes as produced (the access
unit of the raw transport, the transport frame of LOAS and LATM; a
LOAS flush reports its last frame), and the position of its first
decoded sample on the input's timeline (the frame index times the frame
size less the encoder delay). The default schedule writes the same
bytes as before. A stream cut at any immediate playout frame decodes
in the FDK decoder, libxaac and our decoder with its first frame as
close to the source as the continuous decode's, at the reported
position. FFmpeg master decodes the cut from the MP4 form, but it does
not run the pre-roll access units on a cold start, so its first frame
after the cut is wrong (about 0 dB against its continuous decode) and
the second is right; the FDK encoder's streams cut the same way behave
alike in it.

Below 64 kbit/s per channel the xHE-AAC encoder runs enhanced SBR at the
2:1 ratio (`coreSbrFrameLengthIndex` 3): the core codes 1024 lines at
half the output rate and each frame carries 2048 output samples, the
delay is HE-AAC's 5635 samples, and the HE-AAC encoder's analysis, grid
and envelope estimation feed the USAC form of the data (`UsacSbrData`,
with `SbrConfig` carrying the default header and neither the harmonic
transposer, inter-TES nor PVC). An independent frame sends `SbrInfo`,
takes the default header and codes its first envelope and noise floor
along frequency; the other frames send neither header nor info. The
crossover and bandwidth come from the HE-AAC tuning table for the core
rate and bitrate. With `mps212` on, a stereo pair adds MPEG Surround
2-1-2 (`stereoConfigIndex` 1): the pair is analysed in the SBR encoder's
QMF domain, the level difference and coherence of twenty parameter bands
are estimated per frame (the three lowest QMF bands through a windowed
transform across the frame, since the decoder's hybrid filter bank would
understate the separation of sounds near a band border), and the
energy-preserving downmix, scaled per band group so that it carries the
energy of both channels and divided by the fixed downmix gain of 4.5 dB,
is what the core and SBR code. `Mps212Data` carries one parameter set per
frame with the decoder's Huffman codes, without residual, phase coding
or temporal shaping. An SBR stream pre-rolls two frames, the plain core
one, and the independent frame interval of one second counts the longer
frames. The SBR rule is overridden with `sbr` in `AacUsacEncoderOptions`;
a 2-1-2 pair always has SBR, and an input rate whose half is not an AAC
rate keeps the plain core. 2-1-2 is never chosen by bitrate: FFmpeg's
USAC decoder (master at the time of writing) reads a second `core_mode`
bit for the absent second core channel of a `stereoConfigIndex` 1 pair
and has no 2-1-2 upmix, so it rejects every such stream, ours and the
FDK encoder's alike, while the FDK decoder and libxaac decode them. A
caller who knows the players sets `mps212` to 1 for a stereo pair at
24 or 32 kbit/s, where it buys 1.5 to 2 kHz of bandwidth over the SBR
pair.

With `drc` on (off by default), the encoder writes MPEG-D DRC gain sets
(ISO/IEC 23003-4) for a quieter listening mode. The UsacConfig gains an
`ID_EXT_ELE_UNI_DRC` element after the pre-roll element, whose
configuration is a `uniDrcConfig()` with one channel layout, one
coefficients block of two single-band gain sets (the regular gain
coding profile, linear interpolation, the default `deltaTmin` and DRC
frame) and two DRC sets in the version 1 syntax of the configuration
extension, which the FDK decoder applies and the version 0 form does
not: drcSetId 1 with the effect "general compression" and drcSetId 2
with "night". Every access unit carries that frame's `uniDrcGain()`
(a few bytes), the pre-roll's configuration included. The gains come
from the input: the K-weighted loudness over 400 ms, mapped through a
static curve around the programme's integrated loudness (a dead band of
half the target loudness range, then a ratio that brings the
programme's range to the target, `drc_target_range_lu`, 6 to 16 LU,
default 10; up to 12 dB of boost and 24 dB of cut, no boost for
passages 30 to 40 LU below the anchor and quieter), smoothed with a
50 ms attack and a 1.5 s release. The general set is that compressor,
its boost bounded so that the true peak stays at or below -1 dBTP; the
night set adds a limiter that holds the true peak (4 times oversampled)
at -1 dBTP after the programme is normalised to -16 LUFS, which its
`limiterPeakTarget` declares. The decoders, this one included, apply
the gains of a frame to the next frame's output, so the generator uses
what the codec delay leaves beyond one frame as its lookahead. With
`aac_encoder_set_loudness_info` the programme's figures anchor the curve
and size the ratio (two-pass); without them the anchor is the
integrated loudness of the input so far and the range is assumed to be
16 LU (one-pass). The loudness info set then carries an entry per DRC
set besides the programme's (an empty set without any figures, which
libxaac needs before it applies a set): estimated from the programme's
figures (its integrated loudness and bounds on the peaks), or measured
on the set's output, which the encoder meters with loudness
measurement on (`aac_encoder_drc_loudness`) and takes with
`aac_encoder_set_drc_loudness_info`; `bac --drc --loudness` runs that
three-pass flow. On a music test signal with a 16 LU loudness range,
the target of 10 LU brings the decoded range to 10.2 LU one-pass and
10.7 LU two-pass, and FDK, libxaac and our decoder apply the gains (see
`docs/reference-comparison.md`). The gains cost about 0.7 kbit/s; the
configuration grows by 34 bytes (and the loudness entries by up to
33), which a LOAS stream pays at each immediate playout frame, where
its StreamMuxConfig travels.

## Lossless mode

The lossless mode codes PCM as native FLAC (RFC 9639). It is Bånder Audio Core's own
encoder and decoder, written from the RFC, and shares nothing with the
AAC code but the build, the CPU detection and the error codes. Its API
is `include/flac.h`; every name begins with `bac_flac_`. PCM crosses
that API as interleaved signed 32-bit integers at the stream's bit depth
(a 16-bit sample is a value from -32768 to 32767), never as float, so
that every sample comes back bit for bit. The library does not reorder
channels: the order is the one RFC 9639 section 9.1.3 assigns to each
channel count, which `flac.h` lists.

### Decoder

The decoder reads a native FLAC stream from a byte FIFO in any chunking.
A stream is the `fLaC` marker with its metadata blocks and then frames,
or frames alone: a stream joined in the middle, or the samples of a
container that keeps STREAMINFO out of band, which
`bac_flac_decoder_set_streaminfo` supplies. It decodes fixed and
variable block sizes, every subframe type (constant, verbatim, fixed
and linear prediction), every stereo assignment, 1 to 8 channels and 4
to 32 bits per sample, inside and outside the streamable subset.

Every frame is checked. The decoder verifies the CRC-8 of each frame
header and the CRC-16 of each frame. A damaged frame is reported once
as `AAC_ERR_DECODE` and never concealed; the decoder then finds the next
valid frame by itself, and that frame's sample position says how many
samples were lost. `bac_flac_decoder_lost_frames` counts the damaged
stretches. A stream whose sample rate, channel count or bit depth
changes between frames ends with `AAC_ERR_UNSUPPORTED` at the first
frame that differs. MD5 verification of the decoded samples against
STREAMINFO is a setting (`bac_flac_decoder_set_md5_check`, off by
default in the library, on in the command-line tool), and
`bac_flac_decoder_finish` returns its verdict: match, mismatch, or
unknown when the stream states no MD5 or was not decoded from its first
frame to its last.

Two things that are not FLAC but are found around FLAC streams in files
are passed over. An ID3v2 tag in front of the marker is skipped by the
length it states. An ID3v1 tag behind the last frame, 128 bytes that
begin with `TAG`, ends the input and is no error. Neither tag is read,
and `bac_flac_decoder_id3` says what was passed over: the bytes of the
tags in front, and whether a tag behind the last frame was dropped. Any
other bytes where a frame was expected are damage.

Behind damage the decoder searches for the next frame: a sync code with
a header whose CRC-8 holds is read to its end, without restoring
samples, and is a frame when its CRC-16 holds. A stream can be built of
such headers that are no frames, each claiming the largest frame there
is, so the search keeps an account. What it has read for rejected
candidates may exceed the size of one frame only by four bytes for each
byte of input it has passed; while the account is overdrawn, sync codes
are passed unread. The work of a call is therefore bounded by the input
it holds, and a crafted stream costs time in proportion to its length.
The price is that a frame which follows such a stretch closely can be
passed over. A header that leaves the bit depth to a STREAMINFO the
decoder was not given is treated the same way: the bytes behind it are
read at every depth, and it counts as a frame, which the caller is told
is unsupported, only when one of them ends in a CRC-16 that holds.

For a seek the decoder hands out the stream's seek table
(`bac_flac_decoder_seek_points`, with `bac_flac_decoder_first_frame_offset`
as the origin of its byte offsets) and the caller moves in its own
input: after `bac_flac_decoder_seek_reset` the bytes fed next may begin
anywhere, and decoding resumes at the first valid frame found, whose
position `bac_flac_decoder_last_frame_info` reports.

Memory is bounded by the stream's own limits and not by what a header
claims. Metadata the decoder does not use is skipped as it arrives, a
frame that claims more samples than STREAMINFO permits is refused before
anything is sized for it, and the FIFO holds 64 KiB or one frame of the
largest size the decoder accepts, whichever is more. Once the first
frames are decoded the decoder allocates nothing
(`tests/test_flac_decoder.cpp` counts).

### Encoder

The encoder writes native FLAC in the streamable subset with one fixed
block size per stream. It takes 1 to 8 channels, bit depths of 8, 12,
16, 20, 24 and 32, and every sample rate a frame header can express.
Anything else is refused; nothing is ever coded with loss, and a sample
outside the bit depth fails the call that carries it.

A level from 0 to 8 sets how much the encoder searches. Each level stays
within the ceilings of the reference encoder's preset of the same
number, so a stream of level 5 asks no more of a decoder than `flac -5`
does. Level 5 is the default.

| Level | Block | Prediction | Stereo | Finest partition order | Analysis windows |
|---:|---:|---|---|---:|---:|
| 0 | 1152 | fixed | independent channels | 3 | - |
| 1 | 1152 | fixed | the two assignments an estimate prefers | 3 | - |
| 2 | 1152 | fixed | all four assignments | 3 | - |
| 3 | 4096 | linear, order 6 or below | independent channels | 4 | 1 |
| 4 | 4096 | linear, order 8 or below | the two assignments an estimate prefers | 4 | 1 |
| 5 | 4096 | linear, order 8 or below | all four assignments | 5 | 1 |
| 6 | 4096 | linear, order 8 or below | all four assignments | 6 | 3 |
| 7 | 4096 | linear, order 12 or below | all four assignments | 6 | 3 |
| 8 | 4096 | linear, order 12 or below | all four assignments | 6 | 9, of which the best 2 are coded |

For each channel the encoder codes the fixed predictor whose residual
is smallest and, from level 3, the linear predictors its analysis
proposes, and keeps the smallest subframe by exact size. The size of a
residual is exact as well: for every Rice parameter that can be the best
one of some partition the bits are counted, not estimated, over every
partition order the level allows. A stereo pair is stored as left and
right, left and side, side and right, or mid and side, whichever is
smallest among the assignments the level codes. Linear predictors stay
at order 12 or below at every sample rate, 5-bit Rice parameters are
used above 16 bits only, and at 16 bits and below every prediction fits
a 32-bit sum.

A stream is written in this order: `bac_flac_encoder_write_header`,
`bac_flac_encoder_encode` any number of times,
`bac_flac_encoder_finish`. A caller whose output can seek then writes
the bytes of `bac_flac_encoder_final_header` over the first header,
which puts the sample total, the MD5 of the input, the frame size range
and the seek points into the stream. Without that step the stream is
complete and decodable, with a total and an MD5 of zero. The options
(`BacFlacEncoderOptions`) reserve a seek table with a point for every
ten seconds when the caller states the total up front, add a PADDING
block, and carry the tags and pictures. The encoder sizes its buffers
when it is created and allocates nothing while a stream runs
(`tests/test_allocation.cpp` counts).

The encoder's output does not depend on the processor. The lossless
mode has a kernel table of its own (`src/flac_dsp.cpp`, with SSE2, AVX2
and NEON kernels in `src/simd/flac_*.cpp`), chosen at run time and
capped by `BAC_AAC_CPU` like the AAC table. Its integer kernels are
exact, and its two floating-point kernels accumulate in one stated order
and are compiled in exact arithmetic, without contraction and without
fused multiply-add. A stream therefore comes out the same byte for byte
at every level, which `tests/test_flac_dsp.cpp` checks kernel by kernel
and on whole streams.

The output does not depend on the C library either. The analysis needs
a cosine for its windows and a base 2 logarithm for its size estimates,
and a library may return either neighbour of the true value: libraries
differ in that last bit, and so does one library on processors with and
without FMA. The analysis therefore computes both itself (`flac_cos` and
`flac_log2` in `src/flac_lpc.cpp`). `tests/test_flac_format.cpp` holds
the two functions to their error bounds and pins their results and the
windows made with them, and `tests/test_flac_encoder.cpp` pins fifteen
whole streams by digest.

Two limits of the kernel set are worth knowing:

- The AVX2 level writes Rice codes eight at a time and reads them with a
  single-instruction zero count. Both kernels are built by GCC and Clang
  only and are installed when the processor reports BMI2 (and LZCNT for
  the reader). A build with MSVC, which is how the Windows prebuild is
  made, uses the loops every level shares; its output is the same and
  its encoding is slower.
- The NEON level has kernels for the window, the autocorrelation, the
  32-bit residual and the Rice fold and sums. They are compiled for
  arm64 only
  and are not exercised on the x86 host the codec was developed on; the
  arm64 runner of `.github/workflows/audio-core.yml` runs them against
  the scalar kernels and fails when they did not run.

### Tags and pictures

The encoder writes a VORBIS_COMMENT block and PICTURE blocks from its
options, in the order given, and checks them before a byte of the stream
is written. A tag is `NAME=value`: the name is printable ASCII without
`=`, the value any UTF-8, and names may repeat. A picture carries a type
from 0 to 20 (RFC 9639 Table 13; 3 is the front cover), a media type, a
description, its dimensions and the file's bytes, which the library
stores as given without looking into them. A stream holds at most one
picture each of the types 1 and 2, and a picture with its text has to
fit one metadata block: 16777183 bytes less the media type and the
description.

The decoder skips tags and pictures unread by default, however large.
With `bac_flac_decoder_set_metadata` it keeps the stream's tags and
pictures up to a byte limit the caller sets (32 MiB when the caller
names none) and hands them out through `bac_flac_decoder_comments` and
`bac_flac_decoder_pictures`. A block that would pass the limit is
skipped whole and counted by `bac_flac_decoder_skipped_metadata`; the
audio decodes all the same. Nothing is sized from a length or a count
inside a block: a block whose inner lengths do not fit it is dropped and
reported as one `AAC_ERR_DECODE`. SEEKTABLE and PADDING are read or
skipped as the format says; APPLICATION and CUESHEET blocks are skipped.

### API

All functions are declared in `include/flac.h` with C linkage and return
the `AacError` codes of `aac.h`.

```c
/* decoder */
BacFlacDecoder* bac_flac_decoder_create(void);
int bac_flac_decoder_set_streaminfo(BacFlacDecoder* dec, const uint8_t* streaminfo, int size);
int bac_flac_decoder_set_metadata(BacFlacDecoder* dec, int collect, size_t max_bytes);
int bac_flac_decoder_set_md5_check(BacFlacDecoder* dec, int enabled);
int bac_flac_decoder_feed(BacFlacDecoder* dec, const uint8_t* data, int size);
int bac_flac_decoder_decode_next(BacFlacDecoder* dec, int32_t* pcm, int pcm_size);
int bac_flac_decoder_end_input(BacFlacDecoder* dec);
int bac_flac_decoder_finish(BacFlacDecoder* dec, BacFlacMd5Verdict* verdict);
int bac_flac_decoder_reset(BacFlacDecoder* dec);
int bac_flac_decoder_seek_reset(BacFlacDecoder* dec);
int bac_flac_decoder_stream_info(BacFlacDecoder* dec, BacFlacStreamInfo* info);
int bac_flac_decoder_last_frame_info(BacFlacDecoder* dec, BacFlacFrameInfo* info);
int bac_flac_decoder_lost_frames(BacFlacDecoder* dec);
int bac_flac_decoder_pending_bytes(BacFlacDecoder* dec);
int bac_flac_decoder_seek_points(BacFlacDecoder* dec, const BacFlacSeekPoint** points);
int64_t bac_flac_decoder_first_frame_offset(BacFlacDecoder* dec);
int bac_flac_decoder_comments(BacFlacDecoder* dec, const BacFlacComment** comments,
                               BacFlacComment* vendor);
int bac_flac_decoder_pictures(BacFlacDecoder* dec, const BacFlacPicture** pictures);
int bac_flac_decoder_skipped_metadata(BacFlacDecoder* dec);
int bac_flac_decoder_id3(BacFlacDecoder* dec, uint64_t* id3v2_bytes, int* id3v1_trailer);
void bac_flac_decoder_destroy(BacFlacDecoder* dec);

/* encoder */
BacFlacEncoder* bac_flac_encoder_create(int sample_rate, int channels, int bits_per_sample,
                                          int level, int* error);
void bac_flac_encoder_options_init(BacFlacEncoderOptions* options);
int bac_flac_encoder_set_options(BacFlacEncoder* enc, const BacFlacEncoderOptions* options);
int bac_flac_encoder_block_size(BacFlacEncoder* enc);
int bac_flac_encoder_header_bytes(BacFlacEncoder* enc);
int bac_flac_encoder_write_header(BacFlacEncoder* enc, uint8_t* out, int out_size);
int bac_flac_encoder_max_output_bytes(BacFlacEncoder* enc, int n_samples);
int bac_flac_encoder_encode(BacFlacEncoder* enc, const int32_t* pcm, int n_samples,
                             uint8_t* out, int out_size);
int bac_flac_encoder_finish(BacFlacEncoder* enc, uint8_t* out, int out_size);
int bac_flac_encoder_final_header(BacFlacEncoder* enc, uint8_t* out, int out_size);
int bac_flac_encoder_last_frame_info(BacFlacEncoder* enc, BacFlacFrameInfo* info);
int bac_flac_encoder_stream_info(BacFlacEncoder* enc, BacFlacStreamInfo* info);
void bac_flac_encoder_destroy(BacFlacEncoder* enc);
```

`bac_flac_decoder_feed` returns the bytes it took, fewer than offered
when the FIFO is full, which means a frame is waiting for
`bac_flac_decoder_decode_next`. That call returns the block size of the
frame it decoded, 0 when no complete frame is pending, and
`AAC_ERR_OVERFLOW` when the caller's buffer is too small, in which case
the frame stays pending. `bac_flac_decoder_end_input` tells the decoder
that no more bytes follow, so that a frame the input ends in is damage
and not a wait for more. On the encoder side
`bac_flac_encoder_max_output_bytes` is the most a call with that many
samples can write, and a call with a smaller buffer is refused without
consuming input. One encoder or decoder runs on the calling thread;
handles are independent of each other. The header states the terms of
every function in full.

### Lossless commands

```sh
bac flac enc [--level 0..8] [--raw RATE CHANNELS BITS] [--tag NAME=VALUE]... [--picture FILE]... <in.wav | -> <out.flac | ->
bac flac dec [--no-md5] [--raw] <in.flac | -> <out.wav | ->
bac flac test [--no-md5] <in.flac | ->
bac flac info [--export-picture FILE [--picture-number N]] <in.flac | ->
```

`enc` reads an integer WAV file (PCM, or the extensible format with its
valid-bits field) or, with `--raw`, headerless PCM, and writes a native
FLAC stream. `dec` does the reverse, and `test` decodes without writing.
A dash is standard input or standard output. Raw PCM is interleaved
little-endian signed integers, each sample in its bit depth rounded up
to whole bytes.

Input and output are streamed: the tool holds a few blocks, however long
the file. When the output can seek, `enc` reserves a seek table and,
after the last frame, rewrites the header with the sample total, the MD5
of the input and the seek points. Into a pipe the stream is complete
without them. `--tag` adds a tag and may be repeated, also with one
name. `--picture` adds a JPEG or PNG file as a front cover, with the
media type and the dimensions read from the file's own header. `info`
prints what a stream's header says, its tags and its pictures without
decoding the audio, and `--export-picture` writes one picture's bytes to
a file. ID3 tags around a stream are passed over and reported: `test`
names them with its result, `dec` on standard error, and `info` shows
the one in front and, for a file, the one at its end.

`dec` and `test` verify every frame checksum, the sample total the
header states and, unless `--no-md5` is given, the MD5 of the decoded
samples. Every command exits non-zero on any error or mismatch, a read
that fails included; `enc` then leaves no output file (a pipe or a
device it was told to write into is not removed), and `dec` leaves what
could be decoded.

`bac bench` adds lossless rows to its table: the synthetic signal at 16
bits through levels 0, 5 and 8.

The codec's computed AAC tables are built when the first AAC encoder or
decoder is created, not when the program starts, so the `flac` commands
do not pay for them. The tool links the C++ runtime dynamically, as the
package's other builds do, and loading that runtime is most of what one
start of `bac` costs.

### Lossless classes

The binding exports `FlacEncoder` and `FlacDecoder` beside the AAC
classes. Samples are `Int32Array`, interleaved, at the stream's bit
depth.

```js
import { FlacDecoder, FlacEncoder } from '@baander/audio-core';

const encoder = new FlacEncoder({ sampleRate: 44100, channels: 2, bitsPerSample: 16, level: 5 });
const stream = Buffer.concat([encoder.encode(pcm), encoder.finish()]);

const decoder = new FlacDecoder({ md5: true });
use(decoder.decode(stream)); // Int32Array
while (decoder.pending) use(decoder.decode());
use(decoder.finish()); // decoder.md5 is 'match' afterwards
```

The results of `encode()` and `finish()` joined are a complete stream;
the first begins with the header, `headerBytes` long, and
`finalHeader()` returns the header to write over it once the stream is
finished. The encoder options are `sampleRate`, `channels`,
`bitsPerSample`, `level`, `totalSamples`, `seekTable`, `padding`,
`tags`, `vendor` and `pictures`; the encoder reports `lastFrame`,
`streamInfo` and `blockSize`.

What a decoder call returns is bounded, whatever the stream says: a FLAC
frame of 50 bytes can stand for two megabytes of samples. One call
returns at most `maxOutput` samples (1048576 by default) and holds the
rest back; while `pending` is true the caller collects with `decode()`
without an argument before it gives more bytes. The decoder options add
`md5`, `streamInfo` (STREAMINFO supplied out of band) and `metadata`
(`true`, or `{ maxBytes }`, to keep tags and pictures). The decoder
reports `streamInfo`, `lastFrame`, `errors`, `md5`, `seekPoints`,
`firstFrameOffset`, `tags`, `vendor`, `pictures`, `skippedMetadata`,
`id3v2Bytes` and `id3v1`, and has `reset()` and `seekReset()`. `node/index.d.ts` states every
field.

### Tests

Eight CTest suites belong to the lossless mode, and `test_allocation`
holds its encoder and decoder to the rule of the AAC ones. The decoder,
conformance, encoder and interop suites run again with the kernel table
capped at the scalar kernels and, on x86, at the SSE2 ones.

- `test_flac_format`: MD5 against the test suite of RFC 1321, both frame
  checksums against values worked out from their polynomials, the coded
  numbers, STREAMINFO and frame headers against the example streams of
  RFC 9639 Appendix D, and the bit reader and writer.
- `test_flac_decoder`: streams written by hand, so that every subframe
  type, residual form, stereo assignment, bit depth and block size is
  decoded from a known description to known samples; damaged, truncated
  and unusual streams; feeding in any chunking; resynchronisation;
  seeking; the bounds the decoder keeps on its memory; and the work of
  the frame search on streams built to make it expensive, counted in
  bytes read and not in time.
- `test_flac_conformance`: the decoder against streams it did not write.
  The committed fixtures (`tests/data/flac`) are short synthetic signals
  encoded by `flac` 1.4.3 and by FFmpeg, each with the MD5 of its source.
  The FLAC decoder testbench (ietf-wg-cellar/flac-test-files, about
  300 MB, fetched by `tests/flac-testbench.sh` and never committed) adds
  real recordings and the format's corner cases.
- `test_flac_encoder`: every stream has to come back bit for bit from
  the library's own decoder, with the sample total and the MD5 its
  header states, across bit depths, channel counts, sample rates and
  levels, and has to stay within the ceilings of its level. Fifteen
  streams, of five inputs made with integers alone at levels 0, 5 and
  8, are pinned by their SHA-256 digests: every compiler, C library and
  processor the suite runs on has to write the same bytes. The binding's
  smoke test pins all fifteen again, which covers the Windows build.
- `test_flac_interop`: the encoder against decoders it shares no line
  with. `flac -t` tests each stream, `flac -d` and FFmpeg decode it, and
  the samples of both are compared with the input.
- `test_flac_dsp`: every kernel of every level the machine can run
  against the scalar kernel, bit for bit, and whole streams, which have
  to come out the same whatever level the process is capped at.
  `BAC_REQUIRE_KERNELS` names levels that have to run.
- `test_flac_meta`: tags and pictures through the C API, blocks whose
  inner lengths lie, the caller's limit, ID3 tags around a stream, and
  the same data through `metaflac`, `flac` and FFmpeg.
- `test_flac_cli`: the `bac flac` commands as the built tool.
- `test_allocation`: neither the lossless encoder nor the decoder
  allocates once a stream runs.

The reference encoder and FFmpeg are oracles: the suites run their
binaries and compare output. `tests/flac-reference.sh` builds `flac` at
the commit of its 1.4.3 tag and prints the binary's path for
`BAC_FLAC_REFERENCE`; `BAC_FLAC_TESTBENCH` names the testbench
directory. A suite whose oracle is missing skips that comparison with a
message and passes. With `BAC_REQUIRE_ORACLES` set, which CI does, it
fails instead. `fuzz_flac_decoder` and `fuzz_flac_encoder` are described
under [Fuzzing and benchmarks](#fuzzing-and-benchmarks), where the
measurements against `flac`, FFmpeg and ALAC are linked as well.

### Limits

- Native FLAC only. Ogg FLAC is not read or written.
- ID3 tags are no part of FLAC, and the decoder treats the two that are
  found around FLAC files differently from the reference decoder in one
  case. Both decoders accept an ID3v2 tag in front of the stream. An
  ID3v1 tag behind the last frame makes `flac` report lost sync; Bånder Audio Core
  decodes and verifies every sample before it, counts no error, and
  reports the tag (`bac_flac_decoder_id3`, `id3v1` in the binding, a
  line from `bac flac test`). The tags themselves are not read: their
  fields are not available through this library.
- The encoder writes one fixed block size per stream and the streamable
  subset. It does not write variable block sizes, bit depths other than
  8, 12, 16, 20, 24 and 32, or CUESHEET and APPLICATION blocks.
- The encoder codes on the calling thread. It has no multithreaded mode.
- The decoder does not seek by itself: it gives the seek points and
  resumes wherever the caller continues.
- A stream whose properties change between frames is refused from the
  frame that differs.
- The library stores pictures as given. Only the command-line tool reads
  a JPEG or PNG header, to fill in the media type and the dimensions.

## API

All functions are declared in `include/aac.h` with C linkage.

### Encoder

```c
AacEncoderHandle aac_encoder_create(int sample_rate, int channels, int bitrate,
                                    AacObjectType aot, AacRateControl rc_mode);
int aac_encoder_encode(AacEncoderHandle ctx, const float* pcm, int n_samples,
                       uint8_t* out, int out_size);
int aac_encoder_flush(AacEncoderHandle ctx, uint8_t* out, int out_size);
int aac_encoder_set_quality(AacEncoderHandle ctx, int quality);
int aac_encoder_frame_size(AacEncoderHandle ctx);
int aac_encoder_delay(AacEncoderHandle ctx);
int aac_encoder_max_frame_bytes(AacEncoderHandle ctx);
void aac_encoder_destroy(AacEncoderHandle ctx);
int aac_encoder_set_metadata(AacEncoderHandle ctx, AacDrcProfile profile, int prog_ref_level);
/* stream format, before the first frame or after a flush */
int aac_encoder_set_transport(AacEncoderHandle ctx, AacTransport transport);
int aac_encoder_set_signaling(AacEncoderHandle ctx, AacSignaling signaling);
int aac_encoder_set_frame_length(AacEncoderHandle ctx, int frame_length);
int aac_encoder_set_crc(AacEncoderHandle ctx, int enabled);
int aac_encoder_set_mpeg2(AacEncoderHandle ctx, int enabled);
int aac_encoder_set_channel_mode(AacEncoderHandle ctx, AacChannelMode mode);
/* xHE-AAC: noise filling, pre-roll, independent frame schedule, segment size ceiling,
 * stream id, SBR, MPS 212, DRC gain sets; init fills the defaults */
void aac_usac_encoder_options_init(AacUsacEncoderOptions* options);
int aac_encoder_set_usac(AacEncoderHandle ctx, const AacUsacEncoderOptions* options);
/* xHE-AAC random access: an independent frame on demand, and the last frame for a muxer */
int aac_encoder_request_independent_frame(AacEncoderHandle ctx);
int aac_encoder_last_frame_info(AacEncoderHandle ctx, AacEncoderFrameInfo* info);
/* xHE-AAC loudness metadata: the set of a measured programme (two-pass),
 * or a meter inside the encoder (one-pass) */
int aac_encoder_set_loudness_info(AacEncoderHandle ctx, const AacLoudnessInfo* info);
int aac_encoder_set_loudness_measurement(AacEncoderHandle ctx, int enabled);
int aac_encoder_loudness(AacEncoderHandle ctx, AacLoudnessInfo* out);
/* the loudness meter (ITU-R BS.1770-4, EBU Tech 3341/3342), 1 to 6 channels */
AacLoudnessMeter* aac_loudness_create(int sample_rate, int channels);
void aac_loudness_feed(AacLoudnessMeter* m, const float* interleaved, int frames);
int aac_loudness_result(const AacLoudnessMeter* m, AacLoudnessInfo* out);
void aac_loudness_reset(AacLoudnessMeter* m);
void aac_loudness_destroy(AacLoudnessMeter* m);
int aac_encoder_config(AacEncoderHandle ctx, uint8_t* out, int out_size);
int aac_encoder_set_ld_sbr(AacEncoderHandle ctx, int enabled, int dual_rate);
int aac_encoder_set_afterburner(AacEncoderHandle ctx, int enabled);
int aac_encoder_set_metadata(AacEncoderHandle ctx, AacDrcProfile profile, int prog_ref_level);
/* tuning: a per-frame cap, the core's lowpass, the FDK-named VBR modes */
int aac_encoder_set_peak_bitrate(AacEncoderHandle ctx, int bps);
int aac_encoder_set_bandwidth(AacEncoderHandle ctx, int hz);
int aac_encoder_bandwidth(AacEncoderHandle ctx);
int aac_encoder_set_vbr_mode(AacEncoderHandle ctx, int mode);
```

- `sample_rate` is one of the twelve AAC rates (8000 to 96000 Hz);
  `channels` is 1 to 8, interleaved in the canonical order (L, R, C, LFE,
  Ls, Rs, then Lc, Rc for 7.1 and Cs as the seventh channel of 6.1);
  `aot` is `AAC_AOT_LC`, `AAC_AOT_SBR` (HE-AAC v1), `AAC_AOT_PS`
  (HE-AAC v2), `AAC_AOT_ER_LD` (AAC-LD) or `AAC_AOT_ER_ELD` (AAC-ELD; the
  low-delay types take 22.05 to 48 kHz, the rates with LD band tables,
  and channel configurations 1 to 7). With SBR the sample rate is twice a core rate the tuning
  table covers (32000 to 96000 Hz; 22050 and 44100 included); the tuning
  rows span 8 to 160 kbit/s for a mono element and 16 to 256 kbit/s for a
  stereo one, and a bitrate outside them takes the nearest row. HE-AAC v2
  takes a stereo input only.
- `aac_encoder_encode` takes exactly `frame_size()` (1024 or 960, twice
  that with SBR) interleaved float samples per channel at full scale 1.0
  and writes one frame of the transport: an ADTS frame, a LOAS frame, a
  LATM AudioMuxElement or a raw access unit. A NaN sample is coded as
  silence, and a sample beyond 65536 in magnitude, an infinity included,
  as 65536 of its sign. The output buffer must hold
  `aac_encoder_max_frame_bytes` (768 bytes per channel plus the header,
  plus 272 bytes per SBR element, plus the LATM overhead); a smaller one
  is refused with `AAC_ERR_OVERFLOW` before any input is consumed.
- The first `aac_encoder_delay` output samples of the decoded stream are
  silence: 2048 for AAC-LC (one block of lookahead for block switching
  plus the transform overlap; 1920 with 960-sample frames), 5635 at the
  input rate for HE-AAC (the core delay at half rate plus the QMF chains
  of encoder and decoder; 5379 with 960-sample frames). xHE-AAC has
  AAC-LC's delay and frame size with the plain core and HE-AAC's while
  it runs SBR (by default stereo below 128 kbit/s and mono below
  64 kbit/s; `aac_encoder_frame_size` and `aac_encoder_delay` say which).
- `aac_encoder_flush` drains the delay: with ADTS or LOAS it writes the
  remaining frames (two, or three with SBR) back to back and returns
  their total length, then 0 until the next `aac_encoder_encode`; with a
  packet transport it writes one unit per call until it returns 0. A
  flushed encoder starts over, so the next input opens a stream identical
  to a fresh encoder's, and the stream format may be changed again.
- `aac_encoder_config` writes the AudioSpecificConfig of the stream (for
  an MP4 `esds` box or an out-of-band StreamMuxConfig); a 6.1 or
  PCE-signalled 7.1 layout is written as channel configuration 0 with the
  PCE inside, 960-sample frames set the frame length flag.
- `quality` is 1..100 for `AAC_RC_CVBR` and `AAC_RC_TVBR`; 70 codes at the
  masking threshold, 100 nine dB below it. In the VBR modes the quality
  also selects the lowpass (13 kHz at 1 to 19.3 kHz at 100), and a quality
  set after creation re-derives every setting that depends on it.
- `aac_encoder_set_peak_bitrate` (bits per second, 0 for none) and
  `aac_encoder_set_vbr_mode` (1 to 5, 0 restores the bitrate and rate
  control given at creation) are taken before the first frame only
  (`AAC_ERR_STATE` after it); a peak below the bitrate in CBR or ABR, a
  negative peak and a mode outside 0 to 5 are refused with
  `AAC_ERR_INVALID_ARG`. The cap counts a frame as the rate control does,
  the raw data block plus an ADTS header's bits: ADTS frames and raw
  access units stay within it, LOAS and LATM add their header bytes,
  and the pre-roll an xHE-AAC immediate playout frame carries stays
  outside it. A frame whose side information alone exceeds the cap
  cannot honour it. A quality set after a VBR mode overrides the mode's.
- `aac_encoder_set_bandwidth` takes 1000 Hz or more (0 returns to the
  tuning table) at any time and clamps to half the core rate;
  `aac_encoder_bandwidth` reads the lowpass in force.
- `aac_encoder_set_loudness_info` takes the figures of a whole programme
  (`AacLoudnessInfo`: the integrated loudness and the sample peak, the
  true peak, range, anchor loudness and the momentary and short-term
  maxima each with its `has_*` flag) before the first frame of an
  xHE-AAC stream (`AAC_ERR_STATE` after it, `AAC_ERR_UNSUPPORTED` for
  another object type); NULL removes the set.
  `aac_encoder_set_loudness_measurement` runs a meter on the input the
  encoder codes (before the first frame; up to six channels, else
  `AAC_ERR_UNSUPPORTED`), and `aac_encoder_loudness` reads it
  (`AAC_ERR_STATE` under 400 ms of input or without a meter). The meter
  itself takes interleaved input at full scale 1.0 in the canonical
  channel order and any chunking.

### Decoder

```c
AacDecoderHandle aac_decoder_create(int sample_rate, int channels);
int aac_decoder_decode(AacDecoderHandle ctx, const uint8_t* data, int size,
                       float* pcm, int pcm_size);
int aac_decoder_reset(AacDecoderHandle ctx);
int aac_decoder_clear_history(AacDecoderHandle ctx);
int aac_decoder_flush(AacDecoderHandle ctx, float* out, int out_size);
int aac_decoder_frame_size(AacDecoderHandle ctx);
int aac_decoder_sample_rate(AacDecoderHandle ctx);
int aac_decoder_output_sample_rate(AacDecoderHandle ctx);
int aac_decoder_channels(AacDecoderHandle ctx);
int aac_decoder_stream_channels(AacDecoderHandle ctx);
int aac_decoder_get_sbr_ps(AacDecoderHandle ctx, int* has_sbr, int* has_ps);
int aac_decoder_has_surround(AacDecoderHandle ctx);
void aac_decoder_destroy(AacDecoderHandle ctx);
/* stream settings */
int aac_decoder_set_transport(AacDecoderHandle ctx, AacTransport transport);
int aac_decoder_set_config(AacDecoderHandle ctx, const uint8_t* asc, int size);
int aac_decoder_set_frame_length(AacDecoderHandle ctx, int frame_length);
int aac_decoder_set_concealment(AacDecoderHandle ctx, AacConcealment mode);
int aac_decoder_last_frame_concealed(AacDecoderHandle ctx);
int aac_decoder_concealed_frames(AacDecoderHandle ctx);
int aac_decoder_set_limiter(AacDecoderHandle ctx, int enabled, int attack_ms, int release_ms);
int aac_decoder_delay(AacDecoderHandle ctx);
int aac_decoder_set_drc(AacDecoderHandle ctx, int enabled, int boost, int cut,
                        int target_ref_level, int heavy);
int aac_decoder_program_ref_level(AacDecoderHandle ctx);
int aac_decoder_has_drc(AacDecoderHandle ctx);
int aac_decoder_channel_mode(AacDecoderHandle ctx);
/* USAC loudness and MPEG-D DRC */
int aac_decoder_set_loudness(AacDecoderHandle ctx, int enabled, int target_lufs_x100);
int aac_decoder_set_drc_effect(AacDecoderHandle ctx, AacDrcEffect effect, int album_mode,
                               int boost, int cut);
int aac_decoder_loudness(AacDecoderHandle ctx, int* program_loudness_x100,
                         int* norm_gain_db_x100);
/* streaming input */
int aac_decoder_feed(AacDecoderHandle ctx, const uint8_t* data, int size);
int aac_decoder_decode_next(AacDecoderHandle ctx, float* pcm, int pcm_size);
int aac_decoder_pending_bytes(AacDecoderHandle ctx);
```

- `aac_decoder_decode` takes one ADTS frame (every raw data block in it)
  or one raw data block at the creation sample rate, and returns the
  samples per channel written to `pcm`, interleaved with
  `aac_decoder_channels` channels, or a negative `AacError`. `pcm_size`
  must hold the whole output; `AAC_DECODER_MAX_OUTPUT` samples per channel
  always suffice.
- `channels` is the output layout: 0 emits the stream's own channels in
  canonical order, 1 and 2 downmix larger layouts (centre and surrounds
  at -3 dB, LFE dropped; a stereo stream becomes 0.707 (L + R) in mono, a
  mono stream goes to both stereo channels at -3 dB, as FFmpeg mixes
  them), 3 to 8 copy the stream's channels and zero the rest.
  `aac_decoder_stream_channels` reports what the last frame carried.
- `aac_decoder_has_surround` reports that the last frame carried MPEG
  Surround data that was decoded: the output is then the surround
  channels (5.1 in the canonical order from the 5151, 5152 and 525
  trees, or a stereo pair from the 2-1-2 tree of ELD v2) built from the
  mono or stereo core, `aac_decoder_stream_channels` keeps reporting the
  core's channels, and `aac_decoder_delay` includes the surround
  decoder's delay while it runs.
- The ADTS header overrides the creation sample rate and channel
  configuration; `aac_decoder_sample_rate` reports the stream's rate. A
  change of rate or layout mid-stream resets the decoder state.
  `aac_decoder_reset` starts over for a new stream: it clears the
  history, the counters and the input FIFO and re-applies the
  configuration set through the API.
- `aac_decoder_clear_history` prepares a seek. It drops the input FIFO
  and what the decoder remembers of the audio before (the filterbank
  overlap, the SBR, parametric stereo, MPEG Surround and 2-1-2 states,
  the DRC gains, the limiter and the concealment history) and keeps the
  configuration, the transport state (the StreamMuxConfig of a LOAS
  stream whose frames refer to an earlier one), the surround
  configuration of an in-band header and the counters. A random access
  frame fed next decodes as it would in a fresh decoder, bit for bit;
  for xHE-AAC that is an independent frame, whose pre-roll then plays
  as at the start of the stream. The high band of HE-AAC returns with
  the next SBR header, as it does for a fresh decoder. AAC-ELD with
  LD-SBR has no in-band SBR header: its envelopes are coded against the
  previous frame's, so the first frames after a seek, as from a fresh
  decoder, conceal until a frame codes them afresh.
- `aac_decoder_flush` ends the stream. It drains the samples the
  decoder still holds, `aac_decoder_delay` of them per channel, by
  running frames of silence through the filterbanks, the SBR, surround
  and limiter stages; returns the samples per channel written (0 when
  it holds none); and then resets the decoder as `aac_decoder_reset`
  does. A buffer smaller than `aac_decoder_delay` times
  `aac_decoder_channels` gets `AAC_ERR_OVERFLOW` and the decoder is left
  as it was. The encoder's own flush and priming cover the filterbank
  tails, so a decode of a whole stream plus this flush holds the whole
  input once the priming and the decoder delay are trimmed.
- `aac_decoder_delay` is the delay the decoder adds beyond the
  encoder's priming (`aac_encoder_delay`, which already counts the core,
  SBR and low-delay filterbanks of a standard decoder): the limiter's
  look-ahead (its attack window, 0 when it is off) plus the surround
  decoder's delay while it runs. A player trims the priming plus this
  delay from the start of the decode and appends the flush. Per
  profile, without the limiter: AAC-LC, HE-AAC, HE-AAC v2, AAC-LD,
  AAC-ELD, ELD with LD-SBR and xHE-AAC (with and without SBR or 2-1-2)
  add 0; AAC-LC plus MPEG Surround adds 1521, ELD v2 256 and HE-AAC plus
  MPEG Surround 960 (by construction: no committed stream carries that
  combination), each plus 64 per gathered QMF slot. The suite
  `test_playback` checks every profile's figure against the alignment
  of its decode to the sample.
- Rules on what applies where. MPEG-D DRC applies to USAC only: a
  `uniDrc` extension payload in an AAC fill element is skipped. The
  MPEG-4 DRC of `aac_decoder_set_drc` applies to AAC only: it is refused
  with `AAC_ERR_UNSUPPORTED` once a USAC configuration is in force and
  ignored by a USAC stream configured after it was switched on. An
  explicit SBR configuration whose output rate is not twice the core
  rate is refused.
- Field tolerance. A program config element is accepted with its
  channel elements in any order and wherever it stands in the raw data
  block. A PCE whose layout differs from the one in force under ADTS
  installs the new layout; the block it came in is refused (concealed
  in the new layout when concealment is on), and the frames that follow
  decode in it, while the ADTS header's channel configuration no
  longer overrides it. SBR data appearing in an implicitly signalled
  stream switches to the doubled rate, and SBR data vanishing from one
  switches back to the core rate, each a configuration change. An SBR
  header whose frequency tables cannot be built is a damaged header:
  the SBR payload is discarded with it, the core decodes, and the high
  band is concealed on the header in force.
- `aac_decoder_feed` appends bytes to the input FIFO (64 KiB plus one
  frame) and returns how many it took; a packet transport takes a packet
  whole or not at all. `aac_decoder_decode_next` returns the samples per
  channel of the next complete frame, 0 when none is pending, or a
  negative error (an unsupported stream, or a damaged one with
  concealment off). With `AAC_TRANSPORT_DETECT` the first bytes choose
  ADTS or LOAS, else raw access units once a config was set.
- `aac_decoder_set_limiter` takes the attack (look-ahead) and release
  times in milliseconds; the look-ahead must fit 8192 frames at the
  highest output rate, 96 kHz doubled by SBR (about 42 ms).
- `aac_decoder_object_type` reports the object type of the stream;
  `aac_decoder_set_eld_downscale` (1, 2 or 4, before the first frame)
  plays an ELD stream at a fraction of its rate and is refused for a
  stream with LD-SBR. `aac_decoder_set_frame_length` takes 1024 or 960
  only: the low-delay frame lengths are signalled by the configuration.
- `aac_decoder_set_drc` (off by default) takes the boost and cut
  scaling in percent, the target reference level in quarter dB below
  full scale (-1 for no loudness normalisation) and whether DVB heavy
  compression is preferred; it may be changed at any time.
- `aac_decoder_set_loudness` (on by default) brings a USAC stream with
  loudness metadata to the target in LUFS times 100 (-2400 by default,
  -3100 to -1000) and leaves a stream without metadata alone;
  `aac_decoder_set_drc_effect` asks for an MPEG-D DRC effect
  (`AAC_DRC_EFFECT_OFF` for none at all, `AAC_DRC_EFFECT_NONE`, the
  default, for clipping prevention only, then night, noisy, limited,
  low level, dialog and general with the standard's fallback order),
  album mode and the boost and cut scaling in percent;
  `aac_decoder_loudness` reads back the declared program loudness
  (`INT32_MIN` when the stream declares none) and the applied
  normalisation gain, both times 100. All three may be changed at any
  time and take effect from the next frame.
- `aac_encoder_set_metadata` selects a compression profile (film
  standard, film light, music standard, music light or speech) and the
  program reference level in quarter dB below full scale (124 is
  -31 dBFS) before the first frame. Every channel element is then
  followed by a fill element with `dynamic_range_info`: the reference
  level and one band whose gain comes from the profile's curve at the
  frame's RMS level relative to the reference (the curves are
  piecewise-linear tables in `src/drc.cpp`; the level estimate releases
  by 2 dB per frame). With `AAC_DRC_NONE` the output is unchanged.

## Building

### Native

```sh
cd packages/baander-audio-core
mkdir -p cmake-build && cd cmake-build
cmake .. -DCMAKE_BUILD_TYPE=Release
cmake --build .
ctest
```

`build/` belongs to the Node-API binding (node-gyp); CMake builds live in
`cmake-build/`. The build produces `libbac.a`, the `bac` tool and
the tests. Options: `BAC_AAC_BUILD_ENCODER`, `BAC_AAC_BUILD_DECODER`,
`BAC_AAC_BUILD_TESTS`, `BAC_AAC_BUILD_TOOLS`, `BAC_AAC_SHARED`.

`cmake --install .` installs the library, the public headers under
`include/bac/` and a pkg-config file, `bac.pc`, whose `Libs.private`
names the C++ runtime, so a C program linking the static library resolves
it. FFmpeg's `--enable-libbaac` finds the codec through that file; the
project's FFmpeg image builds it this way
([`docker/ffmpeg/baac/README.md`](../../../docker/ffmpeg/baac/README.md)).

SIMD: the vector operations, the radix-2 FFT stages, the MDCT twiddles
and the AAC-LC encoder's quantisation noise sum dispatch at runtime
through one function table (`include/aac_dsp.h`) to
SSE2, AVX2 (x86) or NEON (ARM); only `src/simd/avx2.cpp` is compiled with
AVX2 flags, so the library runs on CPUs without it. The transform kernels
compute the same products and sums as the scalar ones and the noise sum
adds its terms in one order on every level, all without fused
multiply-adds, so an encoder produces the same stream on every level;
`BAC_AAC_CPU=scalar|sse2|avx2|neon` in the environment caps the level
for comparisons, and `tests/test_dsp.cpp` checks every kernel of every
level the machine can run against the scalar one. The decoder's QMF
banks run their modulation through the FFT; the encoder's keep the direct
sums. Neither the encoder nor the decoder allocates once it is running
(`tests/test_allocation.cpp` counts).

Floating point: a GCC or Clang build computes in exact arithmetic, every
sum, product and quotient rounded in the order the source writes it, so
that its streams do not depend on the compiler. For those compilers
(AppleClang included) CMake compiles Release with
`-O3 -fno-math-errno -fno-trapping-math` and every configuration with
`-ffp-contract=off`. The fast-math rewrites stay off because each
compiler applies them its own way: under `-fassociative-math` each
regroups and vectorises a sum differently, and a sum rounded
differently can turn a rate or masking decision; `-freciprocal-math`,
`-fno-signed-zeros` and `-ffinite-math-only` do the same to quotients,
zeros and comparisons;
and under the unsafe-math group x86 compilers take 1/sqrt(x) from the
RSQRTSS estimate, which differs between processor vendors. Contraction
is off so that a target with fused multiply-add rounds like one
without; `-fno-math-errno` and `-fno-trapping-math` change no result. On
32-bit x86 CMake adds `-mfpmath=sse` next to `-msse2`, since GCC
otherwise evaluates float arithmetic on the x87 unit at extended
precision (`FLT_EVAL_METHOD` 2, and 0 with the option). The Node
binding sets its own flags in `binding.gyp`: `-O3 -ffp-contract=off`,
`-msse2` on x86 and `-mfpmath=sse` on ia32; it never took the fast-math
options. Other toolchains get none of these options, MSVC included
(CMake adds none for it, and the binding builds with `/O2`), and the
byte-for-byte reproducibility stated under [Testing](#testing) does not
cover them.

### Command-line tool

```sh
bac enc <in.f32le> <out.aac> <rate> <channels> [bitrate] [cbr|abr|cvbr|tvbr] [quality] [lc|he|hev2|xhe] [options]
bac dec <in.aac> <out.f32le> [rate] [channels] [--no-loudness]
bac loudness <in.wav> | <in.f32le> <rate> <channels>
bac bench [--seconds N] [--dump DIR] [--only NAME] [--streams DIR]
bac flac enc | dec | test | info ...
```

The `flac` commands are the lossless mode; their arguments are under
[Lossless commands](#lossless-commands).

For every profile `--peak-bitrate N`, `--bandwidth HZ` and
`--vbr-mode 1..5` set the tuning controls (with a VBR mode the bitrate
and mode arguments are replaced by the mode's, and a quality argument
overrides the mode's quality). `--afterburner` and `--no-afterburner`
switch the second quantisation pass (not for `xhe`). `bac dec --no-loudness` turns off
the decoder's loudness normalisation of USAC streams.

With the `xhe` profile, `--loudness` measures the whole input first and
writes the loudness info set into the stream (the figures go to stderr),
`--sbr on|off|auto` and `--mps on|off|auto` choose enhanced SBR and
MPS 212 (`auto`, the default, by bitrate), and `--no-noise-filling`,
`--preroll off` and `--stream-id N` set the other USAC options,
`--rap-ms N`, `--rap-samples N` and `--rap-max-frames N` the independent
frame schedule, `--segment-max-bytes N` the segment size ceiling and
`--drc` with `--drc-range LU` the MPEG-D DRC gain sets (with
`--loudness`, a further pass measures each set's output for its entry
in the loudness info set); the options are refused with another
profile. `loudness` prints the meter's
figures (integrated loudness, loudness range, true peak, sample peak,
momentary and short-term maxima) of a WAV file (integer PCM of 8 to 32
bits or 32-bit float, up to six channels in the canonical order) or of
raw float input with its rate and channel count.

`bench` encodes and decodes a synthetic 30 s stereo signal at 48 kHz
through AAC-LC (1024 and 960 frames, and with the afterburner), HE-AAC
v1 and v2, AAC-LD, AAC-ELD and xHE-AAC (the plain core, with SBR, and
with SBR and MPS 2-1-2) and prints the times and realtime factors;
`--dump` writes each stream and its decode, for a byte-for-byte comparison
between builds, and `--streams DIR` also decodes every `.aac` and `.loas`
stream in a directory (`tests/data` for the committed streams) and prints
the realtime factor of each stream and of each profile. Figures are in
[Fuzzing and benchmarks](#fuzzing-and-benchmarks).

Raw PCM is interleaved 32-bit float. With ffmpeg:

```sh
ffmpeg -i song.flac -f f32le -ac 2 -ar 44100 - | bac enc - song.aac 44100 2 128000
```

### Node-API binding (`@baander/audio-core`)

The package root is this directory (`package.json`, `binding.gyp`); the
entry is `node/index.js`, ESM only, for Node 26 and Electron. `npm install`
or `yarn install` builds the binding with node-gyp from the same sources
(SSE2 on x86, NEON on arm64). The Electron app depends on it as
`file:../../packages/baander-audio-core` and rebuilds it for the Electron
ABI with `yarn electron-rebuild`; electron-builder ships the entry, the
types and the compiled binding only.

```js
import { AacDecoder, AacEncoder } from '@baander/audio-core';

const encoder = new AacEncoder({ sampleRate: 44100, channels: 2, bitrate: 128000, mode: 'cbr' });
const stream = Buffer.concat([encoder.encode(pcm), encoder.flush()]);

const decoder = new AacDecoder({ sampleRate: 44100, channels: 2 });
const samples = decoder.decode(stream); // Float32Array, interleaved
```

`FlacEncoder` and `FlacDecoder`, the classes of the lossless mode, are
described under [Lossless classes](#lossless-classes). Both AAC classes
accept input in arbitrary chunk sizes and keep partial frames
between calls. The decoder options add `transport` (`detect`, `adts`,
`loas`, `latm`, `latm-raw`, `raw`), `config` (AudioSpecificConfig bytes),
`frameLength`, `concealment` (`off`, `noise`, `interpolate`) and
`limiter` (`true` or `{ attackMs, releaseMs }`), and the decoder reports
`concealedFrames`, `channelMode`, `delay` and `hasSurround` (the last
frame carried MPEG Surround data that was decoded, so the stream plays as
5.1, or as stereo for ELD v2). `decoder.flush()` returns the samples the
decoder still holds at the end of the stream (`delay` per channel, an
empty `Float32Array` when it holds none) and starts over;
`decoder.clearHistory()` prepares a seek as `aac_decoder_clear_history`
does; the encoder options add
`transport`, `signaling`, `frameLength`, `crc`, `mpeg2`, `channelMode`
(`7.1`, `7.1-front`, `7.1-top`, `7.1-rear`) and the tuning controls
`peakBitrate`, `bandwidth` and `vbrMode`, and the encoder exposes
`config` and the `unitSizes` of its last result, which a packetiser needs
with the LATM and raw transports. For USAC streams (`objectType`
`usac`) the decoder options add `loudness` (`false`, or
`{ enabled, targetLufs }` with the target in LUFS, -31 to -10; on by
default at -24), `drcEffect` (`off`, `none`, `night`, `noisy`, `limited`,
`lowlevel`, `dialog`, `general`), `albumMode`, `drcBoost` and `drcCut`
(percent), and `decoder.loudness()` reads back the declared program
loudness in LUFS (`null` when the stream declares none) and the
normalisation gain in dB after each decode call. The encoder's
`profile: 'xhe'` writes xHE-AAC (LOAS by default, `raw` with `config` for
MP4) and takes `usac: { noiseFilling, preroll, independentIntervalFrames,
independentIntervalMs, independentIntervalSamples,
independentMaxDistanceFrames, segmentMaxBytes, streamId, sbr, mps212,
drc, drcTargetRangeLu }` (the tools `'on'`, `'off'` or `'auto'`, `drc` a
boolean); `encoder.requestIndependentFrame()`
asks for an independent frame and `encoder.lastFrame` reports the last
frame produced (`{ frameIndex, independent, prerollBytes, bytes,
samplePosition }`, `null` before the first),
`loudnessInfo` (the figures the loudness info set carries;
`integratedLkfs` and `samplePeakDbfs` at least) and `measureLoudness:
true`, after which `encoder.loudness` holds the figures of the input
encoded so far (`null` under 400 ms); the exported
`measureLoudness(pcm, sampleRate, channels)` meters a whole signal for
the two-pass flow and returns the object `loudnessInfo` accepts. `npm
test` runs the binding's smoke test. In the Electron app the
main-process `AacService` wraps the binding behind the
`window.electron.aac` bridge (one-shot `decode`, `encode` and
`measureLoudness`, and chunked decoder/encoder sessions with
`flushDecoder` and `clearDecoderHistory` for a decoder session and
`encoderLoudness` for an encoder session opened with
`measureLoudness`); every
decode result carries the `loudness` readback.

### Building for Electron and prebuilds

The binding is a Node-API module: its ABI is the Node-API version it
declares (`NAPI_VERSION=8` in `binding.gyp`), not the Node or Electron
release it was compiled against, so one binary per platform and
architecture serves Node 26 and Electron 45 alike. `node/index.js`
resolves it in this order and lists every path it tried when none loads:

1. the file named in `BAANDER_AUDIO_CORE_BINDING`, when that is set (a
   build elsewhere);
2. `build/Release/baander_audio_core.node`, then `build/Debug/`, where node-gyp
   and `@electron/rebuild` put a local build, so a build a developer
   just made wins over a binary fetched earlier;
3. `prebuilds/<process.platform>-<process.arch>/baander_audio_core.node`, the
   prebuilt release asset (`linux-x64`, `win32-x64`, `darwin-x64`,
   `darwin-arm64`).

The package's `install` script (`node/scripts/fetch-prebuilds.mjs
--install`) runs on every `npm install` and `yarn install`, including the
copy the Electron app makes of this `file:` dependency. It asks the GitHub
API of the repository in `package.json` for the release
`audio-core-v<version>` of the package's own version, downloads
`baander_audio_core-<platform>-<arch>.node` into `prebuilds/`, checks it against
the release's `SHA256SUMS` and loads it once in a child process, which
must report the same version. When any of that fails (offline, a proxy,
no release yet) it builds from source with `node-gyp rebuild` instead, so
a machine with a toolchain never depends on the network and a machine
without one needs only the release. The script never fails the install:
when neither route works it prints what to run later
(`npm rebuild @baander/audio-core` or `yarn electron-rebuild`) and the
loader reports the missing binding at first use. An existing prebuild is kept; `npm run prebuild:download
-- --force` replaces it, and `BAANDER_AUDIO_CORE_BUILD_FROM_SOURCE=1` (or
npm's `--build-from-source`) skips the download. `GITHUB_TOKEN` is
optional for a public repository; `BAANDER_AUDIO_CORE_PREBUILD_REPO`,
`BAANDER_AUDIO_CORE_PREBUILD_TAG` and `BAANDER_AUDIO_CORE_PREBUILD_API` pin the
repository, the release and the API host. Naming the script `install`
(not `postinstall`) matters: npm and Yarn run an implicit `node-gyp
rebuild` for a package with a `binding.gyp` only when it has no `install`
script. Note that Yarn's lockfile hashes the packed contents of a `file:`
dependency, `prebuilds/` included, so a prebuild downloaded into this
source checkout changes `ui/electron/yarn.lock` on the next install;
let the install script fetch it into `node_modules` instead.

To produce a prebuild locally, build for the Electron the desktop app
locks (`ui/electron/yarn.lock`) and copy the result into place; a plain
`npx node-gyp rebuild` against the Node headers gives a binary that
loads in Electron as well:

```sh
npx node-gyp rebuild --runtime=electron --target=45.0.0 --dist-url=https://electronjs.org/headers
mkdir -p prebuilds/$(node -p 'process.platform + "-" + process.arch')
cp build/Release/baander_audio_core.node prebuilds/$(node -p 'process.platform + "-" + process.arch')/
```

`.github/workflows/audio-core.yml` does the same on Linux x64, Windows
x64, macOS x64 and macOS arm64 for every push to `master` and pull
request that touches the package, the desktop app's Electron version or
the workflow: it builds against the headers of the locked Electron, runs
the smoke test under Node and under Electron (`ELECTRON_RUN_AS_NODE`,
no display needed) with the binary in `prebuilds/`, runs the CTest
suites on Linux with FFmpeg, the reference `flac` and the FLAC decoder
testbench required, runs `test_dsp` and the lossless suites on the two
macOS runners against the kernels of their processors, and keeps each
binary as a workflow artifact. Pushing a tag `audio-core-v<version>` (the version in
`package.json`; the workflow refuses a mismatch) attaches the four
binaries and `SHA256SUMS` to the GitHub release of that tag, creating
the release when needed; nothing is committed. The desktop app's
`electron-builder.yml` bundles `prebuilds/<platform>-${arch}/*.node`
per OS block next to the `build/Release` fallback and unpacks both from
the asar archive, which `process.dlopen` cannot read.

## Testing

`ctest` runs forty-nine suites at the highest DSP dispatch level the
machine has, and eight of them again with the dispatch table capped
(`BAC_AAC_CPU`) at the scalar and the SSE2 kernels on x86, and at the
scalar kernels elsewhere: `test_mdct`, `test_qmf` and `test_roundtrip`
check their accuracy bounds at each level, `test_encoder_tuning`
compares the committed AAC-LC, HE-AAC and HE-AAC v2 streams byte for
byte at each, and four suites of the lossless mode run at each level as
well. The suites of the lossless mode are described under
[Lossless mode](#tests); the others cover table consistency
(prefix-free, complete Huffman codebooks; scalefactor bands for 1024 and 960 frames;
Princen-Bradley windows), MDCT against the direct definition and TDAC,
the QMF banks (every analysis and
synthesis pairing reconstructs its input at the accuracy of the
prototype, and the full-rate and half-rate analyses agree on the low
band), bit reader/writer, ADTS and Huffman coding, decoder compliance on
hand-built and damaged streams and on the committed HE-AAC streams
(`tests/data`, FDK-encoded from `gen_he_signal`: the stream parameters,
the low band against the source through the filterbanks, the high band's
energy, the header reset between two streams, the return to the core
rate after a reset), channel layouts, encode/decode roundtrips at every
sample rate, layout and rate-control mode and of HE-AAC v1 (mono, stereo,
5.0) and v2 (the stream decodes with SBR and PS at the input rate and
channel count, the delay is as declared, the low band survives the core
coder, the band between 9 and 12 kHz comes back within 3 dB of the
source and the bitrate is held), rate control, the coding tools
(noise substitution keeps the level of the substituted region, intensity
stereo keeps the balance, TNS covers both window types), and
interoperability with FFmpeg, the transport layer (CRC known answers
and coverage, ADTS resync across inserted headers, truncation and
garbage, byte-wise feeding reproducing the one-shot decode, ASC
roundtrips over every signalling mode, layout and frame length,
StreamMuxConfig and LATM element roundtrips, LOAS/LATM/raw roundtrips
of LC and HE-AAC streams with the StreamMuxConfig in every frame), concealment (transparency on undamaged
streams, damaged and lost frames at 1 and 10 percent on LC and HE-AAC
streams with no error, exact counts, bounded discontinuities and the
fade), 960-sample frames (transforms, API, roundtrips at every rate,
HE-AAC delay), the limiter, and dynamic range control (the parser and
writer on hand-built infos with every optional part, the gain formulas,
the band gain and its boost and cut scaling applied to encoder-written
metadata within 0.1 dB, reference-level normalisation, excluded
channels and two bands from a hand-built fill element, heavy
compression from a hand-built data stream element, an HE-AAC stream,
and FFmpeg decoding our metadata stream without complaint and, since
its decoder parses but does not apply the gains, matching our decode
with DRC off at 135 dB), the afterburner (every coded band's stored
values are the plain quantisation at its scalefactor, so the noise the
rate loop and the afterburner measure is that of the coded values; byte
identity with it off, determinism, the segmental SNR gain per bitrate,
the budget limits of every rate-control mode, decoding by our decoder
and FFmpeg), the
LD-SBR module (filterbank reconstruction and delay, grid and bitstream
roundtrips, a coding roundtrip with an ideal core at 512 and 480 in
dual and single rate), the low-delay types (window and filterbank
reconstruction, LD and ELD roundtrips at 48 and 32 kHz over LOAS and
raw access units, downscaled playout against a decimated reference,
concealment, configuration roundtrips, and FFmpeg decoding our LD and
ELD streams within float rounding) and ELD with LD-SBR (roundtrips in
dual and single rate at 512 and 480, delays, concealment of a
truncated unit and of a CRC-caught payload, the configuration with its
headers, and byte identity of plain ELD streams against committed
references), playback control (`test_playback`: every profile's decode
lines up with the source at the encoder's priming plus
`aac_decoder_delay`, to the sample; the flush drains the limiter's
look-ahead and the surround decoder's delay exactly as the decode of
appended silent frames would, and refuses a short buffer without
losing the tail; a seek through `aac_decoder_clear_history` from inside
a frame matches a fresh decoder bit for bit on every profile, on LOAS
frames that refer to the first frame's StreamMuxConfig and on the
surround streams; the DRC and SBR-rate rules; PCEs in unusual element
orders and a PCE changing the layout under ADTS; SBR appearing in and
vanishing from an implicitly signalled stream; and an SBR header that
cannot be applied, after which the core band stays within 0.5 dB and
the concealed high band within 6 dB), the DSP kernels (every SIMD kernel against its scalar
version), allocation (zero heap use in the steady state of every
profile), and the USAC decoder in six suites: the configuration
(`test_usac_config`: the UsacConfig of every committed stream, the LATM
access units, the pre-roll payload and its reconfiguration, independent
frames and the layouts), the frequency-domain core (`test_usac_fd`: the
arithmetic coder tables and roundtrip, the window shapes, the MDST
estimate and complex prediction, the 768-line frame, and the two FD
streams against the FDK decode), the linear prediction core
(`test_usac_lpd`: the vector quantiser, the ACELP pulses and pitch, the
aliasing cancellation, and the six LPD streams against their stored core
output at 80 dB or better), the enhanced SBR (`test_usac_sbr`: the
frequency tables of the three ratios, the grid and syntax, the
constructed harmonic transposer, PVC and inter-TES data, the low bands
of the 8:3 and 4:1 speech decodes against FDK, and the mono 2:1 stream
through the public API against the FDK decode), MPS 212 (`test_mps212`:
the codebooks and tables, the mixing matrices, the hybrid filterbank
and decorrelator, the regrouped low band, and the four stereo streams
against the FDK decodes) and MPEG-D DRC (`test_unidrc`: the parsers with
a mutation fuzz, the gain decoder, the selection process, and the
loudness and normalisation gain of two committed streams), and the
xHE-AAC encoder in four (`test_usac_enc`: the arithmetic coder against
the decoder's with escapes, short windows and mixed lengths, the
UsacConfig writer against the reader, encode and decode round trips
of the plain core through the public API against AAC-LC at the same
bitrate, noise filling, the raw access unit bitrates at 48 to
128 kbit/s, the API's refusals, and the two committed streams
`usac_enc_sce_64k.loas` and `usac_enc_cpe_96k.loas`;
`test_usac_enc_sbr`: the SBR and 2-1-2 configurations the bitrate rule
and the options produce, read back and rewritten, the pre-roll frame
count of an SBR stream, round trips at 16 to 64 kbit/s with SNR and
replicated-band bounds, the 2-1-2 on a panned pair and on unrelated
channels, frequent independent frames, digital silence, the raw access
unit bitrates at 16 to 96 kbit/s, and the committed streams
`usac_enc_sbr_sce_32k.loas`, `usac_enc_sbr_cpe_64k.loas` and
`usac_enc_mps_cpe_32k.loas`; `test_usac_rap`: the independent frame
schedule in frames, milliseconds and samples with and without SBR, its
precedence, the distance bound and on-demand requests (in the flush
too), the frame reports against the access units, the segment size
ceiling on a transient-rich signal in the constant-rate and true VBR
modes with and without the pre-roll, cuts at every immediate playout
frame of the raw and LOAS forms decoded from cold and aligned at the
reported position, the StreamMuxConfig of LOAS and LATM streams at the
first frame and the immediate playout frames only (on-demand ones, the
pre-roll off and a second stream after a flush included) with a cut
between two of them decoding as the cut at the next, and the committed stream
`usac_enc_rap_cpe_64k.loas` with on-demand independent frames under a
ceiling; `test_drc_gen`: the `uniDrcConfig()`, `uniDrcGain()` and
loudness info set writers against the parsers, the gain generator on a
signal of loud, medium and quiet passages (the direction and bounds of
the gains, attack and release, the peak bounds, the loudness range of
each set's output, one- and two-pass), streams with the gain sets on
and off through our decoder's DRC requests, and the committed stream
`usac_enc_drc_cpe_64k.loas`) and its loudness metadata in another
(`test_encoder_loudness`: the two-pass set in the configuration as the
writer's bytes and read back by the decoder as the declared loudness
with its normalisation gain, the set's removal and replacement and its
place next to the stream id, the refusals by object type and state, the
one-pass meter against the standalone one and EBU Tech 3341's first
case across a flush, and byte identity of the AAC-LC, HE-AAC and USAC
output with the meter on and of the raw access units with the set), and
the tuning controls and encoder correctness checks in
`test_encoder_tuning` (no frame above the peak bitrate's share on clicks
and noise in every profile and rate control mode, the decoded -50 dB
bandwidth against the override and the SBR core left alone, the VBR
modes' bitrate order and ceilings, a peak lowering the mode in either
order, a cleared mode reproducing the plain stream, the element order
SCE FIL CPE FIL CPE FIL LFE END of 5.1 HE-AAC with each SBR payload in
the fill element after its own element, FFmpeg decoding that stream
channel for channel, HE-AAC v2 draining a short last block and a
one-sample input, the coding of NaN, infinite and huge input samples
as their replacements (byte identity with the replaced signal in AAC-LC
CBR and TVBR, HE-AAC v1 and v2, xHE-AAC and AAC-ELD, and finite
decodes), and the committed streams `lc_enc_cpe_128k.aac`,
`he_enc_cpe_48k.aac` and `hev2_enc_cpe_32k.aac`).

Six suites keep committed encoder streams under `tests/data`:
`test_eld_sbr`, the four xHE-AAC encoder suites and
`test_encoder_tuning`. A fresh encode reproduces each stream byte for
byte with GCC 13.3, 14.2, 14.3, 15.2, 15.3 and 16.x and clang 18, 21
and 23 on x86-64 with glibc 2.39 to 2.44; ARM, MSVC and other C
libraries are unverified. Every GCC or Clang build compares its fresh
encodes with the committed streams byte for byte, Debug and the
sanitizer build included; a build with another compiler compares the
decodes instead. `--write-streams tests/data` on each suite regenerates
its own streams, and the committed ones come from a Release build with
the compiler of the `linux-x64` job in
`.github/workflows/audio-core.yml` (`ubuntu-latest`, today Ubuntu 26.04
with GCC 15.2). The other USAC
data under `tests/data` is the FDK xHE-AAC encoder's output
(`usac_*.loas`, 48 access units each, mono and stereo at 12 to
96 kbit/s), the FDK decoder's 16-bit decodes with loudness
normalisation off (`*_fdk.wav`) and the stored LPD core output
(`usac_lpd_*_core.s16`); libxaac is the bit-level oracle these suites
were pinned against (frame boundaries, parameters and the MPS 212 upmix
on the reference downmix), FDK the behavioural one through its binaries,
and FFmpeg plays no part in the USAC suites. MPEG Surround has three
suites: the syntax (`test_mps`: configuration and frame round trips
through the test tool's writer, which the suite compiles in, the
refusals, the extension configurations, the low-delay form with its
frames and filterbank, the quantisation modes, concealment and reset,
the residual positions, temporal shaping and the committed streams), the
signal path (`test_mps_signal`: the tables, the OTT and TTT matrices
against hand-computed values and libxaac's tables, energy preservation
through every tree, the decorrelators, the residual path and synthetic
round trips through the 525 tree with and without residual and the 5151
tree) and the streams (`test_mps_stream`: the four quiet committed
streams against libxaac's decodes per channel at 40 dB or better, the
stereo request against the mixed 5.1 decode, the object type 30 form
against the in-band form, the louder streams decoding to six channels,
and ELD v2 against libxaac). The MPEG Surround data under `tests/data`
is the test tool's output (`mps_*.loas`, 96 access units of 48 kHz 5.1:
the 5151, 5152 and 525 trees with the in-band header and 525 with the
object type 30 configuration, each loud and at -12 dB (`_q`, since the
loud ones saturated the references), 525 in the TTT energy
mode at -12 dB, and the ELD v2 stream `mps_212_eld.loas`) and libxaac's
16-bit decodes of the first second of the quiet streams and the ELD v2
stream (`mps_*_xaac.wav`). No free encoder writes MPEG Surround 5.1, so
`mps-enc` (`tools/mps-enc.cpp`, the CMake target next to `bac`; test
tooling, not a product feature) wrote them: it takes 48 kHz 5.1 or
stereo WAV, measures the OTT and TTT parameters of the chosen tree
(CLD from band energies, ICC from the normalised cross-correlation, CPC
as the least squares prediction of the centre), codes the downmix with
the codec's AAC-LC or ELD encoder and writes the spatial payload in the
in-band or object type 30 form, with options for Huffman coding and
paired sets, the number of parameter sets, the TTT modes and the dual
mode, the fixed gains, the quantisation mode, smoothing, arbitrary
downmix gains and the header and independency cadence; `--verify`
parses every frame back and compares the indices, `--dump` prints the
parameters as the decoder maps them. The interop test skips when
`ffmpeg` is
not on PATH; otherwise it requires our decoder to match FFmpeg's decode
of FFmpeg-encoded streams (ADTS and LATM), and FFmpeg to decode our
streams (ADTS, LOAS with implicit, backward compatible and hierarchical
signalling, 960-sample LC frames over LOAS, every 7.1 mode, MPEG-2 ADTS)
exactly as our decoder does, both at better than 90 dB difference SNR,
and the committed HE-AAC streams to decode within 70 dB of FFmpeg; the
streams of ours it checks use TNS, noise substitution and intensity
stereo, and its HE-AAC v1 and v2 streams of ours decode identically in
both decoders. FFmpeg 6.1 rejects SBR with 960-sample frames, so HE-AAC
at 960 is covered by roundtrips only.

Noise substitution never signals correlated noise (an M/S flag on a noise
band): FFmpeg's decoder draws independent noise regardless of the flag,
and its random sequence would then run ahead of a conforming decoder's.

## Fuzzing and benchmarks

[codec-comparison.md](codec-comparison.md) measures the codec against
FFmpeg's encoder, FDK AAC, libxaac and Apple's AAC (perceptual quality,
speed, memory, decoder agreement) and lists where it falls short;
[bench/](../bench/README.md) reproduces it.
[apple-aac-probe.md](apple-aac-probe.md) records what Apple's encoder
does as its output streams show it, which of those behaviours improved
ours, and the remaining gaps; `tools/aac-probe.cpp` is the probe.

Seven fuzz harnesses under `tools/fuzz/` drive the decoder, the
AudioSpecificConfig and StreamMuxConfig parsers, the encoder, MPEG-D
DRC, MPEG Surround and the FLAC decoder and encoder of the lossless mode
with mutated input, and
[tools/fuzz/README.md](../tools/fuzz/README.md) documents their inputs:

- `fuzz_decoder` covers ADTS, LOAS, LATM and raw access units with a
  config, every object type, concealment on and off by an option byte,
  and the output, DRC and loudness settings.
- `fuzz_asc` covers the config parsers, the writer's round trip, and
  applying a config to a decoder.
- `fuzz_encoder` takes the profile, rate, channels, bitrate, modes,
  transport and USAC options from the first bytes and the rest as PCM;
  our decoder has to decode the result without an error.
- `fuzz_unidrc` covers the `uniDrcConfig()`, `loudnessInfoSet()` and
  `uniDrcGain()` parsers and the module's output.
- `fuzz_mps` runs the surround streams through the public decoder.
- `fuzz_flac_decoder` feeds the FLAC decoder through its FIFO in pieces
  of every size: the marker and the metadata blocks, tags and pictures
  kept under each limit or skipped, a STREAMINFO given out of band, ID3
  tags around the stream, a seek reset and a second pass. A mutated
  frame almost never keeps its checksums, so the harness also runs with
  them ignored, through an internal switch, to reach the code behind
  them.
- `fuzz_flac_encoder` takes the level, channel count, bit depth, sample
  rate, seek table, padding, tags and pictures from the first bytes and
  the rest as PCM; our decoder has to return the samples bit for bit,
  the tags and pictures as given, and the input's total and MD5.

`fuzz_seeds` derives about 800 seeds from every stream under
`tests/data` (whole, chunked, truncated, as access units with their
config, the config alone, and the DRC payloads) and from hand-built
inputs at the bounds a decoder has to enforce. The bounds covered are
the Huffman escape length, the HCR lengths, the LATM `ascLen`, the PCE
element counts, rates above 96 kHz, the ELD and surround extension
lengths, and configs larger than any the codec writes. The reproducers
of past findings live under `tests/data/fuzz/<harness>/` and join the
corpus, so every run replays them. The first ten-minute runs found an
uninitialised envelope grid in concealed USAC SBR frames, an encoder
stack overflow at eight channels, a frame above
`aac_encoder_max_frame_bytes` when a coarse quality spent a full
reservoir, an MPS 2-1-2 phase table read past its end, an unbounded
USAC sampling rate, left shifts of negative values, an ASC writer
accepting the scalable object types; each is fixed and its input kept.
Once the harnesses' finite-output check survived the Release flags, it
found two ways a damaged USAC frame turned the output into NaN or
infinity: subband samples near 1e10 whose products overflowed a float
in the SBR linear prediction, now summed in double, and speech
synthesis filters that rounding to float made unstable for corrupt,
tightly packed LPC sets, now pulled back inside the unit circle.

The CMake option `BAC_FUZZ` builds the tree with ASan and UBSan,
UBSan including its check for a float converted to an integer out of
range (`float-cast-overflow`), which `-fsanitize=undefined` leaves out.
Under libFuzzer when the compiler links `-fsanitize=fuzzer` (clang with
its compiler-rt runtimes), else under the mutation driver
`fuzz_driver.cpp`, which replays the seeds, mutates them for a time or
run budget, and writes a faulting input to an artifact directory as a
reproducer. Without the option the harnesses build plain, and
`test_fuzz_smoke` generates the corpus and runs each harness for a fixed
number of mutations from a fixed seed (about 20 seconds each, side by
side), so the same inputs run on every machine.

`bac bench --streams tests/data` gives the realtime factors (audio
seconds per wall-clock second, one thread, Release build). The figures
below come from a shared 2.1 GHz Xeon virtual machine, where repeated
runs vary by about a quarter, so read them as orders of magnitude. They
were measured with the earlier Release build, which still used the
fast-math rewrites ([Native](#native) lists today's options): the
synthetic signal's figures on 2026-10-31, under `-fassociative-math`,
`-freciprocal-math`, `-fno-signed-zeros` and `-ffinite-math-only`, and
those of the committed streams, as they stood on 2026-10-30, under
`-ffast-math`.

The synthetic 30 s stereo signal at 48 kHz:

| Configuration | kbit/s | Encode | Decode |
| --- | ---: | ---: | ---: |
| LC 128k | 127.9 | 44x | 700x |
| HE-AAC v1 64k | 64.0 | 53x | 290x |
| HE-AAC v2 32k | 31.9 | 84x | 290x |
| LC 960 128k | 127.9 | 36x | 250x |
| LD 512 64k | 66.9 | 60x | 570x |
| ELD 512 64k | 67.6 | 56x | 550x |
| LC 48k no afterburner | 47.9 | 77x | 700x |
| xHE-AAC 96k | 97.3 | 22x | 280x |
| xHE-AAC SBR 48k | 51.8 | 34x | 240x |
| xHE-AAC SBR + MPS 2-1-2 32k | 34.3 | 42x | 180x |

The committed streams, decoded (the USAC streams are the FDK encoder's
and ours; the decoder's API does not tell a USAC 2-1-2 pair from a plain
pair, so those count as stereo):

| Profile | Streams | Decode |
| --- | ---: | ---: |
| LC + MPEG Surround 5.1 | 9 | 72x |
| ELD + MPS 2-1-2 | 1 | 130x |
| ELD mono / stereo | 2 / 2 | 740x / 400x |
| HE-AAC v1 mono / stereo | 1 / 2 | 690x / 350x |
| HE-AAC v2 stereo | 1 | 450x |
| xHE-AAC mono / stereo | 3 / 3 | 530x / 300x |
| xHE-AAC SBR mono / stereo | 6 / 6 | 420x / 200x |

The xHE-AAC SBR rows include the six LPD streams
(`usac_lpd_speech_12000.loas`, `usac_speech_48000_16000.loas`,
`usac_lpd_speech_24000.loas` and `usac_speech_48000_24000.loas` mono,
`usac_music_48000_24000.loas` and `usac_music_48000_16000.loas`
stereo). Their figures also predate the reordered LPC synthesis filter
(`lpd_syn_filter` in `src/usac_lpc.cpp`), which changes their decoded
samples by at most 3.2e-6 of full scale.

On the synthetic signal, encoding runs at 22 to 84 times realtime and
decoding at 180 to 700 times. AAC-LC and HE-AAC encode with the
afterburner on, their default. MPEG Surround 5.1 is the slowest decode: it synthesises six
channels through the hybrid QMF bank.

## Project structure

```
include/      public aac.h, flac.h (the lossless mode) and internal headers
src/          tables, FFT/MDCT, QMF, bitstream, ASC, LATM, transport layer, decoder, concealment, error resilience tools (er_vcb11, er_rvlc, er_hcr), SBR and PS decoders, low-delay filterbanks, LD-SBR, limiter, dynamic range control, USAC decoder (usac_config, usac_frame, usac_arith, usac_fd, usac_stereo, usac_lpd with usac_lpc, usac_acelp, usac_tcx and usac_fac), USAC encoder (usac_enc, usac_enc_config, usac_arith_enc, the USAC form of the SBR encoder in sbr_enc_usac, the 2-1-2 encoder in mps212_enc and the MPEG-D DRC gain generator in drc_gen), enhanced SBR (sbr_esbr), MPS 212 (mps212), MPEG Surround (mps_config, mps_frame, mps_params, mps_tables, mps_tree, mps_dec, mps_glue, and the low-delay form of ELD v2 in mps_ld with mps_ld_tables), MPEG-D DRC (unidrc_parse, unidrc_gain, unidrc), psychoacoustics, encoder, afterburner, coding tools, SBR and PS encoders, the lossless mode (flac_api, flac_format, flac_dec, flac_enc, flac_lpc, flac_meta, and the kernel table flac_dsp), SIMD (simd/, with the lossless kernels in flac_sse2, flac_avx2 and flac_neon)
tools/        bac command-line tool (with the benchmark and, in bac_flac with its PCM and picture files, the lossless commands), mps-enc test stream writer for MPEG Surround, fuzz/ harnesses with their seed generator and mutation driver
tests/        CTest suites and the committed HE-AAC, ELD, USAC and MPEG Surround reference streams and decodes (tests/data), the FLAC fixtures (tests/data/flac), and the scripts that build the reference flac and fetch the FLAC decoder testbench
bench/        the codec comparison harness (corpus, reference tools, quality, speed, compatibility, the lossless stage with its gates) and its results
node/         Node-API binding (addon.cc and flac_addon.cc, ESM entry, types, smoke test, the prebuild fetch script)
prebuilds/    prebuilt bindings per platform-arch, fetched from a release (not committed)
docs/         this file, the reference comparison and the codec comparison
```

## Provenance

The standard tables (spectral and scalefactor Huffman codebooks,
scalefactor band offsets) are ISO/IEC 14496-3 data, transcribed from
FFmpeg's `libavcodec/aactab.c` (LGPL 2.1+) and verified against it
entry by entry. The encoder's allocation strategy, bandwidth table, block
switching, TNS analysis, noise substitution tuning, intensity and M/S
decisions follow the published design of the Fraunhofer FDK AAC encoder
(AOSP `external/aac`, the `android17-release` state as mirrored in
`mstorsjo/fdk-aac`) and 3GPP TS 26.403 as references; no FDK code was
copied. The SBR decoder is written from ISO/IEC 14496-3 4.6.18 with the
structure of FFmpeg's `libavcodec/aacsbr_template.c` as the reference
for the processing order and buffer layout; its Huffman trees, the
noise table and the QMF prototype are ISO/IEC 14496-3 data (Tables
4.A.87 and 4.A.90 and the SBR codebooks), and the filterbank
conventions were pinned against FFmpeg's and FAAD2's banks. The PS
decoder is written from ISO/IEC 14496-3 8.6.4 with the structure of
FFmpeg's `libavcodec/aacps.c` as the reference for the buffer layout and
the hybrid filter alignment; its Huffman codebooks, band mappings,
quantization tables and filter prototypes are ISO/IEC 14496-3 data
(Tables 8.20 to 8.49). The SBR encoder follows 3GPP TS 26.404 and the
published design of FDK's `libSBRenc` (its tuning table, transient
detector, noise floor estimation and envelope coding decisions) with no
code copied; the PS encoder is written from ISO/IEC 14496-3 8.6.4 and
3GPP TS 26.405. The HE-AAC test streams were encoded with the Fraunhofer
FDK encoder. The transport layer, AudioSpecificConfig and LATM/LOAS code
is written from ISO/IEC 14496-3 1.6 and 1.7 and ISO/IEC 13818-7 (the
ADTS CRC coverage), the concealment from the decoder's own state with
the standard's recommendations, the 960-sample tables are ISO/IEC
14496-3 data, the limiter follows the description of the MPEG-D DRC
peak limiter, and the 7.1 channel modes and PCE height extension follow
ISO/IEC 14496-3 Table 1.19 and ETSI TS 101 154; FDK was consulted for
its feature list and parameter ranges only.

The USAC (xHE-AAC) decoder is written from ISO/IEC 23003-3 with the
AOSP FDK decoder as the behavioural oracle through its binaries only (no
FDK source was read) and the Ittiam libxaac decoder (AOSP
`external/libxaac`, Apache-2.0) as the bit-level oracle; no code of
either was copied. The normative trained tables that cannot be written
from the standard's text alone (the arithmetic coder's context models and
cumulative frequency tables, the MDST filter coefficients of complex
stereo prediction, the LSF, AVQ, ACELP, TCX and FAC tables of the LPD
core, the MPS 212 codebooks, quantisers, mixing kernels, prototypes and
decorrelator filters, and the enhanced SBR tables as they are sourced)
are ISO/IEC 23003-3 and 23003-1 data transcribed from libxaac's ROM files under its
Apache-2.0 licence and verified against it entry by entry.

The MPEG Surround decoder is written from ISO/IEC 23003-1 with libxaac
as the bit-level oracle (its parameter mapping compared frame by frame
and its decodes committed as references) and the FDK decoder binary as
a behavioural oracle for ELD v2 only; no code of either was copied. The
CPC codebooks and the quantisers of the parameters, the decorrelator
configurations and all-pass coefficients, the fixed gains, the envelope
quantiser of the guided envelope shaping and the low-delay filterbank
and decorrelator tables are ISO/IEC 23003-1 data transcribed from
libxaac's ROM files under its Apache-2.0 licence, with the attribution
header the USAC tables carry, and checked against it. The MPEG Surround
test streams were written by the package's own test tool.

The lossless mode is written from RFC 9639 (the FLAC format, with the
worked examples of its Appendix D as the first test vectors) and RFC
1321 (MD5). The reference implementation, libFLAC with its `flac` and
`metaflac` tools at release 1.4.3, and FFmpeg's FLAC encoder and decoder
are oracles through their binaries only: the suites and the benchmark
run them and compare output, and no source of either was read, copied
or adapted. The encoder's levels keep within the block size, the
predictor order and the partition order of the reference encoder's
preset of the same number, so that a level asks no more of a decoder
than that preset does. The committed FLAC fixtures were encoded by `flac`
1.4.3 and by FFmpeg from the suite's own synthetic signals. The FLAC
decoder testbench (ietf-wg-cellar/flac-test-files, CC0 1.0) is fetched
at a pinned commit and checked against a committed SHA-256 manifest; it
is not part of the repository. The lossless mode is Apache-2.0 like the
rest of the Baander-authored code.
