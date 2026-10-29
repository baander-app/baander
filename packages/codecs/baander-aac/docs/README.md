# baander-aac

A self-contained AAC-LC and HE-AAC v1/v2 encoder and decoder in C++17
with a C API, a command-line tool and a Node-API binding for the Electron
app, over ADTS, LOAS/LATM and raw access units.

**Version:** 0.1.0
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
`aac_decoder_delay`.

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
applied in the time domain. `aac_decoder_set_loudness` (on by default,
normalising to -24 LUFS), `aac_decoder_set_drc_effect` and the
`aac_decoder_loudness` readback are the C API of that path; the binding
options are listed under the Node-API binding. Concealment covers a lost
USAC frame (the FD spectrum fades, the LPD excitation repeats, and the
arithmetic coder resets at the next independent frame); the limiter, the
layouts and the output delay report are shared with the other object
types, and `aac_decoder_set_drc` (MPEG-4 DRC) reports unsupported on a
USAC stream. `baac` and the binding decode USAC through the streaming
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
core and 4:1 SBR and 52 dB with 2:1 SBR, and 18 to 26 dB on music with
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

`aac_encoder_set_afterburner` enables a second quantisation pass after
the rate loop: for every band it measures the real quantisation noise
against the band's allowance and moves scalefactors one step at a time
where the noise falls most per bit, paid from the frame's slack in the
constant-rate modes or from bands with margin in the quality modes,
re-sectioning the codebooks and counting the bits exactly after every
move within the scalefactor limits. It raises the segmental SNR by about
0.6 to 1.0 dB between 32 and 96 kbit/s for roughly twice the encoding
time; with it off the output is unchanged byte for byte.

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
int aac_encoder_config(AacEncoderHandle ctx, uint8_t* out, int out_size);
int aac_encoder_set_ld_sbr(AacEncoderHandle ctx, int enabled, int dual_rate);
int aac_encoder_set_afterburner(AacEncoderHandle ctx, int enabled);
int aac_encoder_set_metadata(AacEncoderHandle ctx, AacDrcProfile profile, int prog_ref_level);
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
  LATM AudioMuxElement or a raw access unit. The output buffer must hold
  `aac_encoder_max_frame_bytes` (768 bytes per channel plus the header,
  plus 272 bytes per SBR element, plus the LATM overhead); a smaller one
  is refused with `AAC_ERR_OVERFLOW` before any input is consumed.
- The first `aac_encoder_delay` output samples of the decoded stream are
  silence: 2048 for AAC-LC (one block of lookahead for block switching
  plus the transform overlap; 1920 with 960-sample frames), 5635 at the
  input rate for HE-AAC (the core delay at half rate plus the QMF chains
  of encoder and decoder; 5379 with 960-sample frames).
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

### Decoder

```c
AacDecoderHandle aac_decoder_create(int sample_rate, int channels);
int aac_decoder_decode(AacDecoderHandle ctx, const uint8_t* data, int size,
                       float* pcm, int pcm_size);
int aac_decoder_reset(AacDecoderHandle ctx);
int aac_decoder_frame_size(AacDecoderHandle ctx);
int aac_decoder_sample_rate(AacDecoderHandle ctx);
int aac_decoder_output_sample_rate(AacDecoderHandle ctx);
int aac_decoder_channels(AacDecoderHandle ctx);
int aac_decoder_stream_channels(AacDecoderHandle ctx);
int aac_decoder_get_sbr_ps(AacDecoderHandle ctx, int* has_sbr, int* has_ps);
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
- The ADTS header overrides the creation sample rate and channel
  configuration; `aac_decoder_sample_rate` reports the stream's rate. A
  change of rate or layout mid-stream resets the decoder state.
  `aac_decoder_reset` clears the filterbank history, the concealment
  state and the input FIFO for a seek and re-applies the configuration.
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
cd packages/codecs/baander-aac
mkdir -p cmake-build && cd cmake-build
cmake .. -DCMAKE_BUILD_TYPE=Release
cmake --build .
ctest
```

`build/` belongs to the Node-API binding (node-gyp); CMake builds live in
`cmake-build/`. The build produces `libbaander-aac.a`, the `baac` tool and
the tests. Options: `BAAC_AAC_BUILD_ENCODER`, `BAAC_AAC_BUILD_DECODER`,
`BAAC_AAC_BUILD_TESTS`, `BAAC_AAC_BUILD_TOOLS`, `BAAC_AAC_SHARED`.

SIMD: the vector operations, the radix-2 FFT stages and the MDCT twiddles
dispatch at runtime through one function table (`include/aac_dsp.h`) to
SSE2, AVX2 (x86) or NEON (ARM); only `src/simd/avx2.cpp` is compiled with
AVX2 flags, so the library runs on CPUs without it. The transform kernels
compute the same products and sums as the scalar ones, without fused
multiply-adds, so an encoder produces the same stream on every level;
`BAAC_AAC_CPU=scalar|sse2|avx2|neon` in the environment caps the level
for comparisons, and `tests/test_dsp.cpp` checks every kernel of every
level the machine can run against the scalar one. The decoder's QMF
banks run their modulation through the FFT; the encoder's keep the direct
sums. Neither the encoder nor the decoder allocates once it is running
(`tests/test_allocation.cpp` counts).

### Command-line tool

```sh
baac enc <in.f32le> <out.aac> <rate> <channels> [bitrate] [cbr|abr|cvbr|tvbr] [quality]
baac dec <in.aac> <out.f32le> [rate] [channels]
baac bench [--seconds N] [--dump DIR] [--only NAME]
```

`bench` encodes and decodes a synthetic 60 s stereo signal at 48 kHz
through AAC-LC (1024 and 960 frames, and with the afterburner), HE-AAC
v1 and v2, AAC-LD and AAC-ELD and prints the times and realtime factors;
`--dump` writes each stream and its decode, for a byte-for-byte comparison
between builds.

Raw PCM is interleaved 32-bit float. With ffmpeg:

```sh
ffmpeg -i song.flac -f f32le -ac 2 -ar 44100 - | baac enc - song.aac 44100 2 128000
```

### Node-API binding (`@baander/aac-native`)

The package root is this directory (`package.json`, `binding.gyp`); the
entry is `node/index.js`, ESM only, for Node 26 and Electron. `npm install`
or `yarn install` builds the binding with node-gyp from the same sources
(SSE2 on x86, NEON on arm64). The Electron app depends on it as
`file:../../packages/codecs/baander-aac` and rebuilds it for the Electron
ABI with `yarn electron-rebuild`; electron-builder ships the entry, the
types and the compiled binding only.

```js
import { AacDecoder, AacEncoder } from '@baander/aac-native';

const encoder = new AacEncoder({ sampleRate: 44100, channels: 2, bitrate: 128000, mode: 'cbr' });
const stream = Buffer.concat([encoder.encode(pcm), encoder.flush()]);

const decoder = new AacDecoder({ sampleRate: 44100, channels: 2 });
const samples = decoder.decode(stream); // Float32Array, interleaved
```

Both classes accept input in arbitrary chunk sizes and keep partial frames
between calls. The decoder options add `transport` (`detect`, `adts`,
`loas`, `latm`, `latm-raw`, `raw`), `config` (AudioSpecificConfig bytes),
`frameLength`, `concealment` (`off`, `noise`, `interpolate`) and
`limiter` (`true` or `{ attackMs, releaseMs }`), and the decoder reports
`concealedFrames`, `channelMode` and `delay`; the encoder options add
`transport`, `signaling`, `frameLength`, `crc`, `mpeg2` and `channelMode`
(`7.1`, `7.1-front`, `7.1-top`, `7.1-rear`), and the encoder exposes
`config` and the `unitSizes` of its last result, which a packetiser needs
with the LATM and raw transports. For USAC streams (`objectType`
`usac`) the decoder options add `loudness` (`false`, or
`{ enabled, targetLufs }` with the target in LUFS, -31 to -10; on by
default at -24), `drcEffect` (`off`, `none`, `night`, `noisy`, `limited`,
`lowlevel`, `dialog`, `general`), `albumMode`, `drcBoost` and `drcCut`
(percent), and `decoder.loudness()` reads back the declared program
loudness in LUFS (`null` when the stream declares none) and the
normalisation gain in dB after each decode call. `npm test` runs the
binding's smoke test. In the Electron app the main-process `AacService`
wraps the binding behind the `window.electron.aac` bridge (one-shot
`decode`/`encode` and chunked decoder/encoder sessions); every decode
result carries the `loudness` readback.

## Testing

`ctest` runs thirty-four suites, and the transform, filterbank and
roundtrip suites again at every DSP dispatch level this machine can run:
table consistency (prefix-free, complete
Huffman codebooks; scalefactor bands for 1024 and 960 frames;
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
of LC and HE-AAC streams), concealment (transparency on undamaged
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
with DRC off at 135 dB), the afterburner (byte identity with it off,
determinism, the segmental SNR gain per bitrate, the budget limits of
every rate-control mode, decoding by our decoder and FFmpeg), the
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
references), the DSP kernels (every SIMD kernel against its scalar
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
loudness and normalisation gain of two committed streams). The USAC
data under `tests/data` is the FDK xHE-AAC encoder's output
(`usac_*.loas`, 48 access units each, mono and stereo at 12 to
96 kbit/s), the FDK decoder's 16-bit decodes with loudness
normalisation off (`*_fdk.wav`) and the stored LPD core output
(`usac_lpd_*_core.s16`); libxaac is the bit-level oracle these suites
were pinned against (frame boundaries, parameters and the MPS 212 upmix
on the reference downmix), FDK the behavioural one through its binaries,
and FFmpeg plays no part in the USAC suites. The interop test skips when
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

## Project structure

```
include/      public aac.h and internal headers
src/          tables, FFT/MDCT, QMF, bitstream, ASC, LATM, transport layer, decoder, concealment, error resilience tools (er_vcb11, er_rvlc, er_hcr), SBR and PS decoders, low-delay filterbanks, LD-SBR, limiter, dynamic range control, USAC decoder (usac_config, usac_frame, usac_arith, usac_fd, usac_stereo, usac_lpd with usac_lpc, usac_acelp, usac_tcx and usac_fac), enhanced SBR (sbr_esbr), MPS 212 (mps212), MPEG-D DRC (unidrc_parse, unidrc_gain, unidrc), psychoacoustics, encoder, afterburner, coding tools, SBR and PS encoders, SIMD
tools/        baac command-line tool
tests/        CTest suites and the committed HE-AAC, ELD and USAC reference streams and decodes (tests/data)
node/         Node-API binding (addon.cc, ESM entry, types, smoke test)
docs/         this file and the reference comparison
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
