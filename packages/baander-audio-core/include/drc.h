#ifndef BAANDER_AAC_DRC_H
#define BAANDER_AAC_DRC_H

#include <cstdint>

#include "aac.h"
#include "bitstream.h"
#include "channel_map.h"

#ifdef __cplusplus
extern "C" {
#endif

/* MPEG-4 dynamic range control (ISO/IEC 14496-3 4.5.2.7): the
 * dynamic_range_info() carried in a fill element's extension payload of
 * type EXT_DYNAMIC_RANGE, its application to the decoded signal, the DVB
 * ancillary data of a data stream element with the heavy compression
 * gain (ETSI TS 101 154, MPEG-4 audio ancillary data), and the encoder
 * side that derives the gain of a frame from a compression profile. */

#define AAC_EXT_DYNAMIC_RANGE 11
/* drc_band_incr is four bits, so a frame carries at most 16 bands. */
#define AAC_DRC_MAX_BANDS 16
/* The exclusion mask grows by seven channels per additional byte; the
 * first 32 flags are kept, which covers every layout the decoder has. */
#define AAC_DRC_MAX_EXCLUDED 32
/* Sync byte that opens the DVB ancillary data in a data stream element. */
#define AAC_DVB_ANCILLARY_SYNC 0xBC

/* One dynamic_range_info().  Gains are signalled in steps of a quarter
 * dB: dyn_rng_ctl is the magnitude, dyn_rng_sgn 0 a boost and 1 a cut.
 * A band covers the spectral lines up to (band_top + 1) * 4 - 1; without
 * drc_bands_present one band spans the whole spectrum.  prog_ref_level
 * is the program loudness in quarter dB below full scale, or -1 when
 * the frame carries none. */
using AacDrcInfo = struct AacDrcInfo_ {
  int present;
  int pce_tag_present;
  int pce_instance_tag;
  int excluded_present;
  uint32_t exclude_mask; /* bit i: channel i of the raw data block is left alone */
  int num_excluded;      /* flags signalled (7 per byte of excluded_channels) */
  int bands_present;
  int num_bands;
  int interpolation_scheme; /* 0: linear over the frame in the QMF domain */
  int band_top[AAC_DRC_MAX_BANDS];
  int prog_ref_level;
  int sign[AAC_DRC_MAX_BANDS];
  int ctl[AAC_DRC_MAX_BANDS];
};

/* Parses dynamic_range_info() after the extension_type field; the four
 * bits of the extension type count as the first half of the first byte.
 * Returns the bytes the syntax occupies (the n of the standard) or
 * AAC_ERR_DECODE. */
int aac_drc_parse(AacDrcInfo* d, AacBitReader* r);

/* Writes the extension_type and dynamic_range_info() of `d`; returns the
 * bits written. */
int aac_drc_write(const AacDrcInfo* d, AacBitWriter* w);
/* The bytes aac_drc_write writes, which is what the fill element's count
 * has to carry. */
int aac_drc_bytes(const AacDrcInfo* d);

/* The DVB ancillary data of a data stream element (ETSI TS 101 154):
 * the fields the decoder keeps.  compression_value is the heavy
 * compression gain word in the AC-3 compr format (a signed four-bit
 * exponent and a four-bit mantissa). */
using AacDvbAncillary = struct AacDvbAncillary_ {
  int present;
  int mpeg_audio_type;
  int dolby_surround_mode;
  int drc_presentation_mode;
  int compression_on;
  int compression_value;
};

/* Parses the payload bytes of a data stream element as DVB ancillary
 * data; returns 1 when they are (the sync byte matched and the fields
 * fit), 0 otherwise. */
int aac_dvb_ancillary_parse(AacDvbAncillary* a, const uint8_t* data, int size);
/* Writes the DVB ancillary data with the compression fields of `a` into
 * `out` (at most 8 bytes); returns the bytes written. */
int aac_dvb_ancillary_write(const AacDvbAncillary* a, uint8_t* out, int out_size);

/* ── gains ────────────────────────────────────────────────────────── */

/* Linear gain of one band: 2 raised to ±ctl × factor / 24, where factor
 * is cut / 100 for a cut (sign 1) and boost / 100 for a boost (sign 0),
 * so that the full scale of a quarter dB per step is applied at 100. */
float aac_drc_band_gain(int sign, int ctl, int boost, int cut);
/* Linear gain that brings a program at prog_ref_level to target_ref_level
 * (both in quarter dB below full scale): 1 when either is negative. */
float aac_drc_reference_gain(int prog_ref_level, int target_ref_level);
/* Linear gain of a DVB compression_value word. */
float aac_dvb_compression_gain(int compression_value);

/* ── decoder ──────────────────────────────────────────────────────── */

using AacDrcSettings = struct AacDrcSettings_ {
  int enabled;
  int boost, cut; /* percent */
  int target_ref_level; /* quarter dB below full scale, or -1 */
  int heavy;
};

/* Per-element application state for the QMF domain: the gains of the
 * previous frame per QMF band, between which the current frame's gains
 * are interpolated across its slots. */
using AacDrcQmf = struct AacDrcQmf_ {
  int active;         /* gains were set for this frame */
  int interpolate;    /* linear interpolation from prev to gain across the slots */
  float prev[2][64];  /* [channel][band] previous frame's gains */
  float gain[2][64];  /* this frame's gains */
};

using AacDrcDecoder = struct AacDrcDecoder_ {
  AacDrcSettings settings;
  AacDrcInfo info[AAC_MAX_ELEMENTS]; /* attached to the channel element it follows */
  AacDrcInfo global;                 /* carried before any channel element */
  AacDvbAncillary ancillary;
  int has_drc;        /* the last frame carried DRC data */
  int prog_ref_level; /* of the last frame, or -1 */
};

void aac_drc_decoder_init(AacDrcDecoder* d);
/* Clears the per-frame data before a raw data block is parsed. */
void aac_drc_frame_begin(AacDrcDecoder* d);
/* Takes the DRC info of a fill element's EXT_DYNAMIC_RANGE payload of
 * `count` bytes (extension_type already peeked, not consumed) for the
 * layout element `elem`, or the program when `elem` is negative.  The
 * reader is left past the payload.  Returns 0 or AAC_ERR_DECODE. */
int aac_drc_decode_fill(AacDrcDecoder* d, AacBitReader* r, int elem, int count);
/* Parses a data_stream_element() (ISO/IEC 14496-3 Table 4.10) after its
 * id, keeping DVB ancillary data when it carries any. */
int aac_drc_decode_dse(AacDrcDecoder* d, AacBitReader* r);
/* Closes the frame: records has_drc and prog_ref_level. */
void aac_drc_frame_end(AacDrcDecoder* d);

/* The gains of this frame for channel `channel` (its index in the raw
 * data block) of layout element `elem`, per spectral line of the core,
 * including the reference-level and heavy compression gains; returns 0
 * when every gain is one (the output is left untouched). */
int aac_drc_line_gains(const AacDrcDecoder* d, int elem, int channel, float* gain,
                       int frame_length);
/* Applies the frame's gains to a channel's spectrum before the inverse
 * transform (grouped short windows included, with the stride of 128
 * lines per window). */
void aac_drc_apply_spectrum(const AacDrcDecoder* d, int elem, int channel, float* coef,
                            int num_windows, int frame_length);
/* Sets the QMF-domain gains of an element's channels for this frame
 * (SBR streams, where the whole band is scaled after the high band is
 * generated): per QMF band of the 64-band synthesis. */
void aac_drc_set_qmf_gains(const AacDrcDecoder* d, int elem, int first_channel, int nch,
                           AacDrcQmf* q, int frame_length);
void aac_drc_qmf_init(AacDrcQmf* q);
/* Keeps the previous frame's gains for this frame (a concealed element,
 * whose DRC data was lost with it), so they are applied without a ramp. */
void aac_drc_qmf_hold(AacDrcQmf* q);
/* Scales the QMF matrix X[re/im][slot][band] of one channel by this
 * frame's gains, interpolated from the previous frame's over `slots`. */
void aac_drc_apply_qmf(AacDrcQmf* q, int channel, float (*X)[38][64], int slots);

/* ── encoder ──────────────────────────────────────────────────────── */

using AacDrcEncoder = struct AacDrcEncoder_ {
  AacDrcProfile profile;
  int prog_ref_level;
  /* Running loudness per element in dB below full scale, with a release
   * that lets the level fall slowly so the gain does not pump. */
  float level_db[AAC_MAX_ELEMENTS];
  int sign[AAC_MAX_ELEMENTS];
  int ctl[AAC_MAX_ELEMENTS];
};

void aac_drc_encoder_init(AacDrcEncoder* e);
/* Clears the loudness history, keeping the profile. */
void aac_drc_encoder_reset(AacDrcEncoder* e);
/* Measures the loudness of element `elem` over `n` samples of its
 * channels `a` and `b` (NULL for a single channel; full scale `scale`)
 * and derives this frame's gain from the profile's curve. */
void aac_drc_encoder_analyse(AacDrcEncoder* e, int elem, const float* a, const float* b, int n,
                             float scale);
/* Bits the fill element of one element takes, 0 with AAC_DRC_NONE. */
int aac_drc_encoder_bits(const AacDrcEncoder* e);
/* Writes the fill element with dynamic_range_info() of element `elem`. */
void aac_drc_encoder_write(const AacDrcEncoder* e, int elem, AacBitWriter* w);
/* The gain in dB the profile's curve assigns to a level `rel_db` dB above
 * the program reference level. */
float aac_drc_profile_gain_db(AacDrcProfile profile, float rel_db);

#ifdef __cplusplus
}
#endif
#endif /* BAANDER_AAC_DRC_H */
