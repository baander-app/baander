#ifndef BAANDER_AAC_H
#define BAANDER_AAC_H

#ifdef __cplusplus
#include <cstddef>
#include <cstdint>
#else
#include <stddef.h>
#include <stdint.h>
#endif

#ifdef __cplusplus
extern "C" {
#endif

/* Audio Object Types (ISO 14496-3) */
typedef enum AacObjectType_ {
  AAC_AOT_LC = 2,      /* AAC-LC */
  AAC_AOT_SBR = 5,     /* HE-AAC v1 (SBR) */
  AAC_AOT_ER_LC = 17,  /* error resilient AAC-LC (decoder only) */
  AAC_AOT_ER_LD = 23,  /* AAC-LD: 512 or 480 samples per frame, low-overlap window */
  AAC_AOT_PS = 29,     /* HE-AAC v2 (SBR + Parametric Stereo) */
  AAC_AOT_MPS = 30,    /* MPEG Surround over a core type (configuration only) */
  AAC_AOT_ER_ELD = 39, /* AAC-ELD: the low-delay filterbank, with optional LD-SBR */
  AAC_AOT_USAC = 42,   /* USAC / xHE-AAC: the frequency-domain core, mono and stereo */
} AacObjectType;

/* Rate Control Modes */
typedef enum AacRateControl_ {
  AAC_RC_TVBR = 0, /* True VBR — quality-based */
  AAC_RC_CVBR = 1, /* Constrained VBR */
  AAC_RC_ABR = 2,  /* Average Bitrate */
  AAC_RC_CBR = 3,  /* Constant Bitrate */
} AacRateControl;

/* Error Codes */
typedef enum AacError_ {
  AAC_OK = 0,
  AAC_ERR_INVALID_ARG = -1,
  AAC_ERR_MEMORY = -2,
  AAC_ERR_INIT = -3,
  AAC_ERR_ENCODE = -4,
  AAC_ERR_DECODE = -5,
  AAC_ERR_UNSUPPORTED = -6,
  AAC_ERR_OVERFLOW = -7,
  AAC_ERR_STATE = -8,
} AacError;

/* Transport formats.  The decoder detects ADTS and LOAS from their sync
 * words; raw access units need an AudioSpecificConfig
 * (aac_decoder_set_config) or the creation parameters. */
typedef enum AacTransport_ {
  AAC_TRANSPORT_DETECT = 0,    /* decoder only: ADTS or LOAS by sync word, else raw */
  AAC_TRANSPORT_ADTS = 1,
  AAC_TRANSPORT_LOAS = 2,      /* AudioSyncStream carrying AudioMuxElement(1) */
  AAC_TRANSPORT_LATM_MCP1 = 3, /* AudioMuxElement with in-band StreamMuxConfig */
  AAC_TRANSPORT_LATM_MCP0 = 4, /* AudioMuxElement, StreamMuxConfig out of band */
  AAC_TRANSPORT_RAW = 5,       /* raw_data_block access units (MP4 samples) */
} AacTransport;

/* How SBR and PS are signalled in an AudioSpecificConfig (ISO/IEC 14496-3
 * 1.6.5).  ADTS can only signal implicitly. */
typedef enum AacSignaling_ {
  AAC_SIGNAL_IMPLICIT = 0,      /* core AOT only; decoders detect SBR from the data */
  AAC_SIGNAL_EXPLICIT_BC = 1,   /* backward compatible: core AOT plus a sync extension */
  AAC_SIGNAL_EXPLICIT_HIER = 2, /* hierarchical: AOT 5 or 29 in front of the core */
} AacSignaling;

/* Decoder error concealment (ISO/IEC 14496-3 4.5.2.x leaves the method to
 * the decoder). */
typedef enum AacConcealment_ {
  AAC_CONCEAL_OFF = 0,         /* a damaged frame returns AAC_ERR_DECODE */
  AAC_CONCEAL_NOISE = 1,       /* the last good band energies are refilled with noise */
  AAC_CONCEAL_INTERPOLATE = 2, /* the last good spectrum fades out over a few frames, then noise */
} AacConcealment;

/* Channel modes beyond the plain channel counts: the 7.1 layouts of
 * ISO/IEC 14496-3 Table 1.19 (channel configurations 7, 12 and 14) and the
 * two PCE-signalled 7.1 layouts of ETSI TS 101 154.  The canonical
 * interleaved order is L, R, C, LFE, Ls, Rs, then the layout's extra pair:
 * Lc, Rc (front centre), Lrs, Rrs (back of the side pair), or Ltf, Rtf (top
 * front). */
typedef enum AacChannelMode_ {
  AAC_MODE_DEFAULT = 0,           /* by channel count: 1..6 as their configuration, 7 as 6.1, 8 as 7.1 back (12) */
  AAC_MODE_7_1_FRONT_CENTER = 7,  /* configuration 7: C, Lc+Rc, L+R, Ls+Rs, LFE */
  AAC_MODE_7_1_BACK = 12,         /* configuration 12: C, L+R, Ls+Rs, Lrs+Rrs, LFE */
  AAC_MODE_7_1_TOP_FRONT = 14,    /* configuration 14: C, L+R, Ls+Rs, LFE, Ltf+Rtf */
  AAC_MODE_7_1_REAR_SURROUND = 33 /* PCE: C, L+R, Ls+Rs, Lrs+Rrs, LFE with the side pair as side */
} AacChannelMode;

/* MPEG-4 dynamic range control profiles the encoder can write into its
 * fill elements (ISO/IEC 14496-3 4.5.2.7, with the DVB loudness
 * conventions of ETSI TS 101 154): a program reference level and a
 * compression curve of the named character.  The decoder applies what a
 * stream carries when aac_decoder_set_drc enables it. */
typedef enum AacDrcProfile_ {
  AAC_DRC_NONE = 0,           /* no dynamic_range_info written */
  AAC_DRC_FILM_STANDARD = 1,
  AAC_DRC_FILM_LIGHT = 2,
  AAC_DRC_MUSIC_STANDARD = 3,
  AAC_DRC_MUSIC_LIGHT = 4,
  AAC_DRC_SPEECH = 5,
} AacDrcProfile;

/* Opaque Handles */
typedef void* AacEncoderHandle;
typedef void* AacDecoderHandle;

/* ── Encoder API ─────────────────────────────────────────────────── */

AacEncoderHandle aac_encoder_create(int sample_rate, int channels, int bitrate, AacObjectType aot,
                                    AacRateControl rc_mode);

void aac_encoder_destroy(AacEncoderHandle ctx);

int aac_encoder_encode(AacEncoderHandle ctx, const float* pcm, int n_samples, uint8_t* out,
                       int out_size);

int aac_encoder_set_quality(AacEncoderHandle ctx, int quality);
int aac_encoder_frame_size(AacEncoderHandle ctx);
int aac_encoder_delay(AacEncoderHandle ctx);
/* Largest frame aac_encoder_encode can produce; a call with a smaller
 * output buffer is refused with AAC_ERR_OVERFLOW before it consumes any
 * input. */
int aac_encoder_max_frame_bytes(AacEncoderHandle ctx);
/* Drains the encoder delay: with a stream transport (ADTS, LOAS) the
 * remaining frames are written back to back and their total length is
 * returned; with a packet transport (LATM, raw access units) one unit per
 * call, so callers loop until 0.  The encoder then starts a new stream. */
int aac_encoder_flush(AacEncoderHandle ctx, uint8_t* out, int out_size);

/* Stream format settings.  Each must be called before the first
 * aac_encoder_encode (or after a flush); otherwise AAC_ERR_STATE. */
/* Default AAC_TRANSPORT_ADTS; AAC_TRANSPORT_DETECT is refused. */
int aac_encoder_set_transport(AacEncoderHandle ctx, AacTransport transport);
/* Default implicit.  ADTS always signals implicitly; ASC and LATM carry the
 * chosen mode. */
int aac_encoder_set_signaling(AacEncoderHandle ctx, AacSignaling signaling);
/* 1024 (default) or 960 core samples per frame; aac_encoder_frame_size
 * follows (960, or 1920 with SBR).  For the low-delay object types 512
 * (default) or 480. */
int aac_encoder_set_frame_length(AacEncoderHandle ctx, int frame_length);
/* Writes the ADTS CRC (protection_absent = 0); the other transports carry
 * no checksum. */
int aac_encoder_set_crc(AacEncoderHandle ctx, int enabled);
/* MPEG-2 AAC-LC/HE (ADTS id 1): no perceptual noise substitution. */
int aac_encoder_set_mpeg2(AacEncoderHandle ctx, int enabled);
/* Chooses the 7.1 layout of an 8-channel input (AAC_MODE_7_1_BACK by
 * default); AAC_ERR_INVALID_ARG when the mode needs another channel count. */
int aac_encoder_set_channel_mode(AacEncoderHandle ctx, AacChannelMode mode);
/* Writes the AudioSpecificConfig (for an MP4 esds box or out-of-band
 * StreamMuxConfig) and returns its length in bytes, or AAC_ERR_OVERFLOW. */
int aac_encoder_config(AacEncoderHandle ctx, uint8_t* out, int out_size);

/* ── Encoder: low delay, afterburner, dynamic range ──────────────── */

/* aac_encoder_create with AAC_AOT_ER_LD or AAC_AOT_ER_ELD makes a
 * low-delay encoder: 512 core samples per frame by default, 480 through
 * aac_encoder_set_frame_length; ADTS cannot carry them, so the transport
 * defaults to LOAS and aac_encoder_set_transport refuses ADTS.  The
 * delay is one frame plus the filterbank's overlap (aac_encoder_delay). */
/* AAC-ELD only: SBR with the low-delay filterbank.  With `dual_rate` the
 * core runs at half the input rate and the frame is 1024 (960) input
 * samples; without it the core and the SBR share one rate.  Default off.
 * AAC_ERR_INVALID_ARG for any other object type. */
int aac_encoder_set_ld_sbr(AacEncoderHandle ctx, int enabled, int dual_rate);
/* A second quantisation pass that re-searches the scalefactors and the
 * codebooks of every frame against its bit budget (better quality for
 * about 85 % more encoder CPU).  Default on for AAC-LC and HE-AAC, off for
 * AAC-LD and AAC-ELD; USAC refuses it.  May be switched at any time. */
int aac_encoder_set_afterburner(AacEncoderHandle ctx, int enabled);
/* Writes dynamic_range_info with the profile's compression curve and
 * `prog_ref_level` (0..127, the program's loudness in quarter dB below
 * full scale, so 124 is -31 dBFS) into every frame; AAC_DRC_NONE writes
 * nothing (default).  Before the first frame only.  AAC_ERR_UNSUPPORTED
 * for a profile on the low-delay object types (AAC_AOT_ER_LD,
 * AAC_AOT_ER_ELD): their frames carry no fill elements. */
int aac_encoder_set_metadata(AacEncoderHandle ctx, AacDrcProfile profile, int prog_ref_level);
/* AAC-ELD only: an extension_payload() (its extension_type nibble
 * first, `nbits` bits of `data` from the most significant bit) written
 * after the channel elements and the LD-SBR data of the next frame
 * only, as ELD v2 carries its MPEG Surround payload (EXT_LDSAC_DATA).
 * `nbits` 0 clears it.  AAC_ERR_UNSUPPORTED for another object type,
 * AAC_ERR_INVALID_ARG when the payload does not fit a frame: more than
 * 1024 bytes, or more than the frame's bit budget (6144 bits per
 * channel per 1024 samples, scaled to the frame length) less 512 bits
 * per channel that the core keeps.  An accepted payload is budgeted by
 * the rate control, so the frame that carries it is encoded within the
 * same bounds as any other. */
int aac_encoder_set_extension_payload(AacEncoderHandle ctx, const uint8_t* data, int nbits);

/* ── Encoder: tuning ─────────────────────────────────────────────── */

/* A hard cap on the bitrate of any single frame, in bits per second:
 * no frame takes more than its share of `bps` (the frame's bits as the
 * rate control counts them, the raw data block with an ADTS header's
 * bits, rounded down to whole bytes; LOAS and LATM add their few header
 * bytes on top, and the pre-roll of an xHE-AAC immediate playout frame
 * stays outside it), and the bit reservoir never lends a frame more
 * than the cap allows.  Holds for every profile and rate control mode
 * whenever the frame's side information fits the cap.  0 removes the
 * cap (default).  Before the first frame only; AAC_ERR_INVALID_ARG for
 * a negative value, in CBR and ABR outside a VBR mode for a cap below
 * the bitrate, and in a VBR mode for a cap below the first mode's
 * target. */
int aac_encoder_set_peak_bitrate(AacEncoderHandle ctx, int bps);
/* The lowpass of the core coder in Hz instead of the bitrate's tuning
 * table entry (0, the default): the bands above it are not coded.  An
 * override also turns off the sparse, coarsely coded bands that AAC-LC
 * at 48 to 95 kbit/s per channel otherwise adds up to 20 kHz.  The
 * value is clamped to half the core rate (half the input rate with
 * SBR).  The channels of an SBR element are coded up to the element's
 * crossover whatever the override says, since the bands below the
 * crossover are what the decoder copies upwards; an LFE keeps its
 * fixed range.  May be set at any time; AAC_ERR_INVALID_ARG below
 * 1000 Hz. */
int aac_encoder_set_bandwidth(AacEncoderHandle ctx, int hz);
/* The lowpass in force, in Hz: the override, the tuning table's entry,
 * or the SBR crossover.  The bands up to it are coded at full
 * precision; sparse bands coded above it are not counted. */
int aac_encoder_bandwidth(AacEncoderHandle ctx);
/* A VBR mode as the FDK encoder names them, 1 (lowest) to 5 (highest):
 * the mode's bitrate target for a stereo stream is 32, 72, 112, 148 or
 * 228 kbit/s, scaled per channel for other layouts, and it becomes the
 * nominal bitrate.  Each frame is coded at the noise level of a quality
 * chosen to bring music close to the target, spends no surplus on easy
 * frames, and is bounded by the bitrate's rate loop and reservoir as in
 * ABR; every frame carries the VBR marker.  A later
 * aac_encoder_set_quality overrides the mode's quality, also when a peak
 * bitrate set afterwards lowers the mode.  A peak bitrate below the
 * target lowers the mode to the highest one that fits; a peak below the
 * first mode's target is refused.  0 clears the mode and restores the
 * bitrate and rate control given at creation, and is refused while a
 * peak below that bitrate is set under CBR or ABR.  Before the first
 * frame only; AAC_ERR_INVALID_ARG for another mode or a peak that does
 * not suit the result. */
int aac_encoder_set_vbr_mode(AacEncoderHandle ctx, int mode);

/* ── Encoder: USAC (xHE-AAC) ─────────────────────────────────────── */

/* aac_encoder_create with AAC_AOT_USAC makes an xHE-AAC encoder
 * (ISO/IEC 23003-3, the frequency-domain core with 1024-line frames,
 * mono as a single channel element and stereo as a channel pair): it
 * writes LOAS/LATM by default, raw access units with the USAC
 * AudioSpecificConfig (aac_encoder_config) for MP4, and refuses ADTS,
 * which cannot carry the object type, with AAC_ERR_UNSUPPORTED.  The
 * delay (aac_encoder_delay) and frame size are AAC-LC's, or HE-AAC's
 * when the stream runs SBR (see the sbr option below). */
typedef struct AacUsacEncoderOptions_ {
  /* Noise filling of the lines quantised to zero (default on). */
  int noise_filling;
  /* The audio pre-roll extension element at every independent frame,
   * which lets a decoder start at that frame (default on). */
  int preroll;
  /* The independent frame schedule: the distance from one immediate
   * playout frame (usacIndependencyFlag set, the arithmetic coder reset,
   * the audio pre-roll carried) to the next, as a count of frames, of
   * milliseconds or of output samples.  The first one set wins in the
   * order samples, milliseconds, frames; a value in samples or
   * milliseconds is rounded up to whole frames of the output rate (2048
   * samples per frame while SBR runs).  All 0 keeps the default of one
   * second.  The frame before each immediate playout frame (two before
   * it with SBR) is independent as well: it opens the pre-roll. */
  int independent_interval_frames;
  int independent_interval_ms;
  int independent_interval_samples;
  /* An upper bound in frames on the distance between independent
   * frames, whatever the interval and the on-demand requests say; 0 for
   * none (default). */
  int independent_max_distance_frames;
  /* A ceiling in bytes on each segment of access units from one
   * immediate playout frame to the next (the pre-roll included), for a
   * muxer that cuts the stream there; 0 for none (default).  The rate
   * loop caps every frame at the segment's remaining budget spread over
   * its remaining frames, so the quality falls while the ceiling binds;
   * a ceiling below what the frames' side information and the pre-roll
   * need cannot be honoured. */
  int segment_max_bytes;
  /* The stream identifier of the configuration (0..65535), or -1 for
   * none (default). */
  int stream_id;
  /* Enhanced SBR at the 2:1 ratio (the core at half the output rate):
   * 1 on, 0 off, -1 by bitrate (default: on below 64 kbit/s per
   * channel). */
  int sbr;
  /* MPEG Surround 2-1-2 for stereo input (one core channel plus the
   * spatial parameters, stereoConfigIndex 1): 1 on, 0 or -1 off (the
   * default: the stock FFmpeg decoder cannot decode such a pair, so the
   * tool is only ever opted into for players known to handle it).  The
   * tool runs in the SBR encoder's filterbank, so with it on `sbr` must
   * be 1 or -1 (then SBR is on whatever the bitrate). */
  int mps212;
  /* MPEG-D DRC gain sets (ISO/IEC 23003-4) for a quieter listening mode:
   * 1 on, 0 or any other value off (default).  The stream then carries a
   * uniDrcConfig() with two DRC sets on its own layout, drcSetId 1
   * "general compression" (a compressor that boosts the quiet passages
   * and cuts the loud ones around the programme's integrated loudness,
   * the boost bounded at -1 dBTP) and drcSetId 2 "night" (the same
   * compressor with a limiter that
   * holds the true peak at -1 dBTP after normalisation to -16 LUFS),
   * and every frame the gains in the UNI_DRC extension element.  A
   * player applying MPEG-D DRC selects a set by the effect it asks for;
   * every other decoder ignores the element.  The programme's figures of
   * aac_encoder_set_loudness_info anchor the compressor (two-pass);
   * without them it follows the integrated loudness of the input so far
   * (one-pass). */
  int drc;
  /* The loudness range in LU the DRC sets bring the programme towards,
   * 6 to 16 (default 10; any other value takes the default).  The ratio
   * follows from the programme's loudness range when its figures carry
   * one, from an assumed 16 LU otherwise. */
  int drc_target_range_lu;
} AacUsacEncoderOptions;
/* Fills every field with its default, so that a caller sets only what it
 * changes and stays correct when the structure grows. */
void aac_usac_encoder_options_init(AacUsacEncoderOptions* options);
/* Applies the options before the first frame (AAC_ERR_STATE after it);
 * AAC_ERR_UNSUPPORTED for another object type, AAC_ERR_INVALID_ARG for
 * `mps212` on with `sbr` off (the options then stay as they were).  Any
 * other field outside its range takes the default; the schedule and
 * segment fields take 0 for none. */
int aac_encoder_set_usac(AacEncoderHandle ctx, const AacUsacEncoderOptions* options);

/* Asks for an independent frame on demand, at any point of the stream:
 * the next encoded frame is independent and opens the pre-roll of the
 * immediate playout frame that follows it (one frame later for the
 * plain core, two with SBR; the same frame when the pre-roll is off),
 * unless one is already due sooner.  The interval count restarts from
 * that immediate playout frame.  AAC_ERR_UNSUPPORTED for another object
 * type. */
int aac_encoder_request_independent_frame(AacEncoderHandle ctx);

/* What a muxer needs to know about the last frame aac_encoder_encode or
 * aac_encoder_flush produced. */
typedef struct AacEncoderFrameInfo_ {
  int frame_index;     /* frames since the stream's start, from 0 */
  int independent;     /* usacIndependencyFlag: a decoder may start here */
  int preroll_bytes;   /* bytes of the audio pre-roll extension, 0 without one */
  int bytes;           /* bytes of the frame as produced: the access unit or the transport frame */
  /* The position of the frame's first decoded sample on the input's
   * timeline, in output samples: the frame index times the frame size
   * less the encoder delay, so the first frames are negative (they
   * decode to the delay's priming). */
  int64_t sample_position;
} AacEncoderFrameInfo;
/* AAC_ERR_STATE before the first frame; the last frame of a flushed
 * stream stays readable until the next stream's first frame.  A frame
 * with the pre-roll is where a segment starts (every independent frame
 * when the pre-roll is off).  AAC_ERR_UNSUPPORTED for an object type
 * other than AAC_AOT_USAC. */
int aac_encoder_last_frame_info(AacEncoderHandle ctx, AacEncoderFrameInfo* info);

/* ── Loudness measurement ────────────────────────────────────────── */

/* A loudness meter per ITU-R BS.1770-4 and EBU Tech 3341/3342 over
 * interleaved float input at full scale 1.0 in the canonical channel
 * order L, R, C, LFE, Ls, Rs (a channel count of 1 to 6 takes that
 * order's prefix): the K-weighted mean square over 400 ms blocks every
 * 100 ms, gated absolutely at -70 LKFS and relatively 10 LU below the
 * mean of the blocks above it; the loudness range of EBU Tech 3342, the
 * ungated maximum momentary and short-term loudness of EBU Tech 3341,
 * the sample peak and the true peak of BS.1770-4 Annex 2. */
typedef struct AacLoudnessMeter_ AacLoudnessMeter;

/* sample_rate 8000 to 384000, channels 1 to 6; NULL otherwise or out of
 * memory. */
AacLoudnessMeter* aac_loudness_create(int sample_rate, int channels);
void aac_loudness_destroy(AacLoudnessMeter* m);
/* Forgets every block, for a new programme. */
void aac_loudness_reset(AacLoudnessMeter* m);
/* Interleaved frames at full scale 1.0, in any chunking. */
void aac_loudness_feed(AacLoudnessMeter* m, const float* interleaved, int frames);

/* No level or peak is reported below this: digital silence, and a
 * loudness with no block above the absolute gate, read exactly it. */
#define AAC_LOUDNESS_SILENCE (-150.0f)

/* A programme's loudness figures: what the meter measures, and what
 * aac_encoder_set_loudness_info writes into an xHE-AAC stream.  The
 * has_* flags say which optional figures are present. */
typedef struct AacLoudnessInfo_ {
  float sample_peak_dbfs;
  float true_peak_dbtp;  /* never below the sample peak */
  float integrated_lkfs; /* AAC_LOUDNESS_SILENCE when no block passes the absolute gate */
  float range_lu;        /* 0 when fewer than two windows pass the gates */
  float anchor_lkfs;     /* caller-supplied (dialogue level); the meter leaves it 0 with has_anchor 0 */
  int has_anchor, has_range, has_true_peak;
  float max_momentary_lkfs, max_short_term_lkfs; /* EBU Tech 3341, ungated */
  int has_momentary, has_short_term;             /* a window of 400 ms / 3 s exists */
} AacLoudnessInfo;

/* AAC_OK, or AAC_ERR_STATE when fewer than 400 ms were fed (the result
 * needs one block).  The range and the short-term maximum need 3 s;
 * before that has_range and has_short_term are 0. */
int aac_loudness_result(const AacLoudnessMeter* m, AacLoudnessInfo* out);

/* ── Encoder: xHE-AAC loudness metadata ─────────────────────────── */

/* Two-pass form: writes the loudnessInfoSet() of ISO/IEC 23003-4 (one
 * loudnessInfo() for the programme as coded: the sample peak, the true
 * peak with has_true_peak, the program loudness, the anchor loudness
 * with has_anchor, the loudness range with has_range, the momentary and
 * short-term maxima with their flags; every level at 0.25 dB
 * resolution) into the UsacConfig of the stream as the
 * ID_CONFIG_EXT_LOUDNESS_INFO configuration extension, which every
 * StreamMuxConfig, the AudioSpecificConfig of aac_encoder_config and
 * the configuration inside each audio pre-roll then carry, so that a
 * player normalises the programme by it.  Measure the whole input with
 * the meter first, then set the result before the first frame
 * (AAC_ERR_STATE after it, until a flush); NULL removes the set.
 * AAC_ERR_UNSUPPORTED for an object type other than AAC_AOT_USAC. */
int aac_encoder_set_loudness_info(AacEncoderHandle ctx, const AacLoudnessInfo* info);
/* One-pass form: with `enabled` the encoder feeds every input frame it
 * codes to a meter of its own, whose figures aac_encoder_loudness reads
 * at any time (AAC_ERR_STATE before 400 ms of input, and while no meter
 * runs).  The meter starts over with the stream at the first frame
 * after a flush, and the figures of a finished stream stay readable
 * until then.  A stream's configuration cannot change once it has
 * begun, so the one-pass result cannot land in the stream it was
 * measured from: it is for the caller to write into an MP4 header, or
 * to encode the programme again with aac_encoder_set_loudness_info.
 * Enabling is for before the first frame (AAC_ERR_STATE after it);
 * disabling frees the meter at any time.  AAC_ERR_UNSUPPORTED for more
 * than six channels, the layouts the meter has no weights for. */
int aac_encoder_set_loudness_measurement(AacEncoderHandle ctx, int enabled);
int aac_encoder_loudness(AacEncoderHandle ctx, AacLoudnessInfo* out);

/* The DRC sets of the drc option of aac_encoder_set_usac, by drcSetId. */
#define AAC_USAC_DRC_SET_GENERAL 1
#define AAC_USAC_DRC_SET_NIGHT 2
/* With DRC sets the loudness info set carries an entry for each set
 * besides the programme's, so that a player's loudness normalisation
 * matches the set it selects.  By default the entry is estimated from
 * the programme's figures (the integrated loudness, which the
 * compressor roughly keeps, and bounds on the peaks: the general set's
 * the programme's peak or -1 dBTP, the night set's its ceiling); this
 * call gives the figures measured on the set's output
 * instead (NULL returns to the estimate).  Before the first frame
 * (AAC_ERR_STATE after it); AAC_ERR_INVALID_ARG for another drcSetId,
 * AAC_ERR_UNSUPPORTED for an object type other than AAC_AOT_USAC. */
int aac_encoder_set_drc_loudness_info(AacEncoderHandle ctx, int drc_set_id,
                                      const AacLoudnessInfo* info);
/* With the drc option and loudness measurement on, the encoder also
 * meters each DRC set's output (the input times the gains as a decoder
 * applies them); this reads a set's figures, for the second pass of a
 * measured encode.  AAC_ERR_STATE without the meters or before 400 ms
 * of output. */
int aac_encoder_drc_loudness(AacEncoderHandle ctx, int drc_set_id, AacLoudnessInfo* out);

/* ── Decoder API ─────────────────────────────────────────────────── */

/* Samples per channel one call to aac_decoder_decode can produce: up to
 * four raw data blocks of 2048 samples (SBR) in one ADTS frame. */
#define AAC_DECODER_MAX_OUTPUT 8192

/* `channels` is the output layout: 0 emits the stream's own channels in
 * canonical order (L, R, C, LFE, Ls, Rs, Lc, Rc), 1 and 2 downmix larger
 * layouts (a mono stream goes to both stereo channels at -3 dB, a stereo
 * stream becomes 0.707 (L + R)); 3..8 copy the stream's channels and zero
 * the rest. */
AacDecoderHandle aac_decoder_create(int sample_rate, int channels);
void aac_decoder_destroy(AacDecoderHandle ctx);

/* Decodes one ADTS frame (every raw data block in it) or one raw data
 * block; returns the samples per channel written to pcm, interleaved with
 * aac_decoder_channels() channels, or a negative AacError.  pcm_size must
 * hold the whole output: AAC_DECODER_MAX_OUTPUT * channels floats always
 * suffice. */
int aac_decoder_decode(AacDecoderHandle ctx, const uint8_t* data, int size, float* pcm,
                       int pcm_size);

/* Starts over for a new stream: clears the signal history, the
 * concealment counters and the input FIFO, drops the transport's
 * synchronisation and re-applies the configuration. */
int aac_decoder_reset(AacDecoderHandle ctx);

/* For a seek: drops the signal history (the filterbank overlap, the SBR,
 * parametric stereo and surround states, the dynamic range gains in
 * force, the concealment fades) and the bytes waiting in the input FIFO,
 * which belong to the old position, and keeps the rest: the
 * configuration (an AudioSpecificConfig, a LOAS stream's StreamMuxConfig,
 * a surround configuration from an in-band header), the transport in
 * use, the settings and the counters.  The next frame decodes as the
 * first frame of a stream does: it starts from silence through the
 * window's rising half, with nothing of the old position's overlap, and
 * a frame at a random access point (any frame of AAC-LC, HE-AAC and the
 * low-delay types, an independent frame with its pre-roll of xHE-AAC,
 * whose pre-roll is then decoded as at a stream start) gives the output
 * a fresh decoder gives it.  The high band of HE-AAC returns with the
 * next SBR header, as for a fresh decoder. */
int aac_decoder_clear_history(AacDecoderHandle ctx);

/* Drains the tail the decoder holds back at the end of a stream: runs
 * the decoder on silent frames until aac_decoder_delay samples per
 * channel have come out (the limiter's look-ahead, the surround
 * decoder's delay), writes them to `out` interleaved with
 * aac_decoder_channels() channels and returns their count: 0 when
 * nothing is held back (no delay, or no frame decoded),
 * AAC_ERR_OVERFLOW when `out_size` floats do not hold them (nothing is
 * written then, and the decoder keeps its state).  Afterwards the
 * decoder is reset for a new stream, as by aac_decoder_reset.  The
 * filterbank tails of the last frame are no part of this: an encoder's
 * flush writes the frames that carry them (aac_encoder_flush), and the
 * stream's priming covers their delay. */
int aac_decoder_flush(AacDecoderHandle ctx, float* out, int out_size);

/* Samples per channel of one raw data block in the output: 1024, or 2048
 * while SBR is decoded. */
int aac_decoder_frame_size(AacDecoderHandle ctx);
/* Sample rate of the core stream. */
int aac_decoder_sample_rate(AacDecoderHandle ctx);
/* Sample rate of the output: twice the core rate while SBR is decoded. */
int aac_decoder_output_sample_rate(AacDecoderHandle ctx);
/* Channels of the output: the requested count, or with 0 the stream's,
 * which is 2 for a mono stream once it has carried parametric stereo. */
int aac_decoder_channels(AacDecoderHandle ctx);
/* Channels the last decoded frame carried. */
int aac_decoder_stream_channels(AacDecoderHandle ctx);
/* Whether the last decoded frame carried SBR and parametric stereo data. */
int aac_decoder_get_sbr_ps(AacDecoderHandle ctx, int* has_sbr, int* has_ps);
/* Whether the MPEG Surround decoder built the last decoded frame: the
 * stream is configured for surround (by its AudioSpecificConfig or an
 * in-band header) on a core it can take, and the output is the surround
 * channels (5.1 in the order L, R, C, LFE, Ls, Rs, or a stereo pair for
 * the 2-1-2 tree) built from the mono or stereo core, while
 * aac_decoder_stream_channels keeps reporting the core's channels.  It
 * stays set over a frame without a surround payload (one of a spatial
 * frame spanning several core frames, or a lost one), which the surround
 * decoder bridges with the parameters it has. */
int aac_decoder_has_surround(AacDecoderHandle ctx);

/* ── Decoder stream settings ─────────────────────────────────────── */

/* Default AAC_TRANSPORT_DETECT.  LATM without in-band configuration needs
 * aac_decoder_set_config first. */
int aac_decoder_set_transport(AacDecoderHandle ctx, AacTransport transport);
/* Applies an AudioSpecificConfig: sample rate, layout, frame length and
 * explicit SBR/PS signalling.  Sets the transport to raw access units when
 * it is still DETECT.  Returns AAC_ERR_UNSUPPORTED for an object type
 * the decoder lacks. */
int aac_decoder_set_config(AacDecoderHandle ctx, const uint8_t* asc, int size);
/* 1024 (default) or 960 samples per raw data block, for ADTS streams
 * that cannot say; an AudioSpecificConfig overrides it (and names 512 or
 * 480 for the low-delay object types, which only it can signal). */
int aac_decoder_set_frame_length(AacDecoderHandle ctx, int frame_length);
/* Default AAC_CONCEAL_INTERPOLATE.  With concealment on, a damaged frame
 * decodes to concealed audio and counts in aac_decoder_concealed_frames. */
int aac_decoder_set_concealment(AacDecoderHandle ctx, AacConcealment mode);
/* Whether the last frame returned was concealed, and how many were since
 * creation or reset. */
int aac_decoder_last_frame_concealed(AacDecoderHandle ctx);
int aac_decoder_concealed_frames(AacDecoderHandle ctx);
/* Peak limiter on the output (off by default): attack_ms of look-ahead
 * delay, release_ms of gain recovery. */
int aac_decoder_set_limiter(AacDecoderHandle ctx, int enabled, int attack_ms, int release_ms);
/* The delay this decoder adds beyond the stream's own: the samples per
 * channel, at the output rate, by which its output lags the output of
 * the standard's decoder (FFmpeg's, which the core, SBR, parametric
 * stereo and USAC paths match sample for sample).  The limiter's
 * look-ahead and the surround decoder's delay while it runs; 0
 * otherwise.  The delay of the filterbanks themselves (the transform
 * overlap, the SBR QMF chain) is part of the stream's priming
 * (aac_encoder_delay, an MP4 edit list) and not counted here, so a
 * player trims the priming plus this delay at the start of a stream and
 * drains this delay with aac_decoder_flush at its end.  It can change
 * with the stream (the output rate, the surround decoder starting). */
int aac_decoder_delay(AacDecoderHandle ctx);
/* The channel mode of the stream's layout (AAC_MODE_DEFAULT for the plain
 * configurations 1..6 and 6.1). */
int aac_decoder_channel_mode(AacDecoderHandle ctx);

/* ── Decoder: low delay and dynamic range ────────────────────────── */

/* The object type of the stream being decoded (AAC_AOT_LC for AAC-LC and
 * the HE-AAC profiles, AAC_AOT_ER_LC, AAC_AOT_ER_LD, AAC_AOT_ER_ELD or
 * AAC_AOT_USAC),
 * from the AudioSpecificConfig or the first frame; 0 before any. */
int aac_decoder_object_type(AacDecoderHandle ctx);
/* AAC-ELD downscaled playout: decodes a 512 (480) frame stream to
 * 512 / factor samples per frame at 1 / factor of the rate with the
 * correspondingly shorter filterbank; factor 1 (default), 2 or 4.  To be
 * set before the first frame after creation or aac_decoder_reset: once a
 * frame was decoded the call returns AAC_ERR_STATE until a reset, whatever
 * reconfiguration the stream went through.  AAC_ERR_UNSUPPORTED for a
 * stream with LD-SBR.  Only ELD streams are affected. */
int aac_decoder_set_eld_downscale(AacDecoderHandle ctx, int factor);
/* MPEG-4 dynamic range control (ISO/IEC 14496-3 4.5.2.7).  When enabled
 * (default off) the dynamic_range_info of the stream's fill elements is
 * applied to the output: `boost` and `cut` scale the signalled gains in
 * percent (0..100; 100 applies them fully), `target_ref_level` is the
 * wanted program loudness in quarter dB below full scale (0..127; a
 * stream's prog_ref_level is matched to it by a fixed gain) or -1 for no
 * loudness normalisation, and `heavy` applies the DVB heavy compression
 * gains carried in the ancillary data instead of the DRC bands when the
 * stream has them.  May be changed at any time. */
int aac_decoder_set_drc(AacDecoderHandle ctx, int enabled, int boost, int cut, int target_ref_level,
                        int heavy);
/* The program reference level the last frame carried (0..127 in quarter
 * dB below full scale), or -1 when it carried none. */
int aac_decoder_program_ref_level(AacDecoderHandle ctx);
/* Whether the last decoded frame carried dynamic range control data. */
int aac_decoder_has_drc(AacDecoderHandle ctx);

/* ── Decoder: USAC loudness and MPEG-D DRC ───────────────────────── */

/* The DRC effect a player asks for (ISO/IEC 23003-4 selection process);
 * the stream's DRC sets are matched to it, with the standard's fallback
 * order when the exact effect is absent. */
typedef enum AacDrcEffect_ {
  AAC_DRC_EFFECT_OFF = -1,     /* no MPEG-D DRC at all */
  AAC_DRC_EFFECT_NONE = 0,     /* none requested; DRC only to prevent clipping (default) */
  AAC_DRC_EFFECT_NIGHT = 1,
  AAC_DRC_EFFECT_NOISY = 2,
  AAC_DRC_EFFECT_LIMITED = 3,
  AAC_DRC_EFFECT_LOWLEVEL = 4,
  AAC_DRC_EFFECT_DIALOG = 5,
  AAC_DRC_EFFECT_GENERAL = 6,
} AacDrcEffect;

/* Loudness normalisation of USAC streams from their MPEG-D loudness
 * metadata: when enabled (the default) the program is brought to
 * `target_lufs_x100` (LUFS times 100; default -2400, range -3100 to
 * -1000).  Streams without loudness metadata are left as they are.  May
 * be changed at any time; takes effect from the next frame. */
int aac_decoder_set_loudness(AacDecoderHandle ctx, int enabled, int target_lufs_x100);
/* The MPEG-D DRC request: the effect, album mode (use the album loudness
 * and gains when the stream has them) and the scaling of the boosting
 * and attenuating gains in percent (0..100). */
int aac_decoder_set_drc_effect(AacDecoderHandle ctx, AacDrcEffect effect, int album_mode,
                               int boost, int cut);
/* Readback after a frame: the program loudness the stream declares (LUFS
 * times 100) or INT32_MIN when it declares none, and the normalisation
 * gain applied (dB times 100).  Either pointer may be NULL. */
int aac_decoder_loudness(AacDecoderHandle ctx, int* program_loudness_x100,
                         int* norm_gain_db_x100);

/* ── Streaming decode ────────────────────────────────────────────── */

/* Appends bytes to the decoder's input FIFO; returns the number taken
 * (fewer than size when the FIFO is full).  aac_decoder_decode_next then
 * decodes the next complete frame of the transport (ADTS, LOAS, LATM or
 * raw access units with an AudioSpecificConfig), resynchronising across
 * damaged bytes, and returns the samples per channel written, 0 when no
 * complete frame is pending, or a negative AacError (only with
 * concealment off, or for an unsupported stream). */
int aac_decoder_feed(AacDecoderHandle ctx, const uint8_t* data, int size);
int aac_decoder_decode_next(AacDecoderHandle ctx, float* pcm, int pcm_size);
/* Bytes waiting in the FIFO. */
int aac_decoder_pending_bytes(AacDecoderHandle ctx);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_H */
