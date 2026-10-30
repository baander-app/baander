#ifndef BAANDER_AAC_DRC_GEN_H
#define BAANDER_AAC_DRC_GEN_H

#include "aac.h"
#include "bitstream.h"
#include "unidrc.h"

#ifdef __cplusplus
extern "C" {
#endif

/* MPEG-D DRC gain generation for the xHE-AAC encoder (ISO/IEC 23003-4),
 * and the writers of the syntax it produces: uniDrcConfig(), the
 * uniDrcGain() payload of every frame and a loudnessInfoSet() with one
 * entry per DRC set.  The writers mirror the readers in unidrc_parse.cpp
 * and unidrc_gain.cpp field for field, for the subset of the syntax they
 * accept (version 0 and version 1 coefficients and instructions
 * without downmixes, ducking or the basic DRC; the regular, fading and
 * clipping gain coding profiles with linear interpolation).
 *
 * The generator computes two gain sequences over one channel group (all
 * channels linked) on the grid of the default deltaTmin:
 *
 * - the compressor: the K-weighted loudness of the input over a 400 ms
 *   window (BS.1770-4 clause 2, the momentary loudness of EBU Tech 3341),
 *   mapped through a static curve around the programme's integrated
 *   loudness (a dead band of a quarter of the target loudness range on
 *   either side, a ratio that brings the programme's loudness range to
 *   the target, boost of the quiet passages and cut of the loud ones,
 *   no boost of passages 30 to 40 LU below the anchor and quieter), then
 *   smoothed with a 50 ms attack and a 1.5 s release, its boost taken
 *   back where it would lift the true peak (4 times oversampled) above
 *   -1 dBTP;
 * - the limiter: the compressor's gain, lowered where needed so that the
 *   true peak stays at or below -1 dBTP after the programme is
 *   normalised to -16 LUFS.
 *
 * Both peak bounds lead in by at most 1 dB per grid step before a peak
 * and release over 50 ms after it.
 *
 * The decoder applies the gains a frame carries to the output of the
 * next frame (the default delay mode of the gain decoder), and that
 * output trails the input by the codec delay: the generator analyses the
 * input as it arrives and emits the nodes of output frame k + 1 with
 * input frame k, using what the delay leaves beyond one frame as its
 * lookahead.  Node gains are quantised to 0.125 dB (downwards where a
 * peak bound holds, so that it stays held); between the grid points the
 * nodes are thinned to those the decoder's linear interpolation needs
 * within 0.25 dB (never above a held bound), within the gain delta
 * range the coding allows. */

/* drcSetId of the two DRC sets the encoder writes, and their effects. */
#define AAC_DRC_GEN_SET_GENERAL 1 /* drcSetEffect general compression, the compressor */
#define AAC_DRC_GEN_SET_NIGHT 2   /* drcSetEffect night, the compressor and the limiter */
#define AAC_DRC_GEN_SETS 2
/* The peak ceiling the night set signals (limiterPeakTarget) and the
 * normalisation target its limiter protects. */
#define AAC_DRC_GEN_PEAK_TARGET_DB (-1.0f)
#define AAC_DRC_GEN_REFERENCE_LKFS (-16.0f)
/* Target loudness range in LU: the limits and the default. */
#define AAC_DRC_GEN_MIN_RANGE_LU 6
#define AAC_DRC_GEN_MAX_RANGE_LU 16
#define AAC_DRC_GEN_DEFAULT_RANGE_LU 10
/* Largest uniDrcGain() payload of one frame in bytes.  The generator
 * widens its thinning tolerance while a frame's payload exceeds 128
 * bytes; a frame that would still exceed this bound (which takes gains
 * that swing by the delta table's limit at nearly every grid point)
 * leaves the generator unwritable, and the encoder sends the gains of
 * silence for it. */
#define AAC_DRC_GEN_MAX_PAYLOAD_BYTES 192
/* The input's loudness range a one-pass generator assumes when sizing
 * its ratio. */
#define AAC_DRC_GEN_ASSUMED_RANGE_LU 16.0f

typedef struct AacDrcGen_ AacDrcGen;

using AacDrcGenParams = struct AacDrcGenParams_ {
  int sample_rate;  /* the input (and output) rate */
  int channels;     /* 1 to 6, canonical order */
  int frame_length; /* output samples per frame: the DRC frame */
  int delay;        /* input samples from an input sample to its decoded
                     * position; at least frame_length */
  int target_range_lu;
  /* The programme's figures when measured beforehand (two-pass): the
   * integrated loudness anchors the curve and the loudness range sizes
   * the ratio.  Without them the anchor is the integrated loudness of
   * the input so far and the range AAC_DRC_GEN_ASSUMED_RANGE_LU. */
  int has_anchor;
  float anchor_lkfs;
  int has_range;
  float range_lu;
  /* Meter each DRC set's output (the input times the decoded gains) for
   * aac_drc_gen_loudness. */
  int measure;
};

/* NULL for parameters outside the ranges above or out of memory. */
AacDrcGen* aac_drc_gen_create(const AacDrcGenParams* p);
void aac_drc_gen_destroy(AacDrcGen* g);

/* Feeds one frame (frame_length interleaved samples at full scale 1.0,
 * NULL for silence) and returns in `out` the nodes the frame carries:
 * the k-th call (from 0) those of output frame k + 1, sequence 0 the
 * compressor's, sequence 1 the limiter's, node times in output samples
 * from the frame start.  Returns AAC_OK. */
int aac_drc_gen_frame(AacDrcGen* g, const float* pcm, AacUniDrcGainFrame* out);

/* The loudness of a DRC set's output so far (drcSetId 1 or 2), with
 * `measure` set.  AAC_ERR_STATE before 400 ms or without the meters,
 * AAC_ERR_INVALID_ARG for another set. */
int aac_drc_gen_loudness(const AacDrcGen* g, int drc_set_id, AacLoudnessInfo* out);

/* The anchor (LKFS) and the ratio the static curve uses now; for tests
 * and diagnostics. */
void aac_drc_gen_curve(const AacDrcGen* g, float* anchor_lkfs, float* ratio);
/* The static curve: the gain in dB for a loudness `level` with the
 * anchor, the target range and the ratio. */
float aac_drc_gen_static_gain(float level, float anchor, int target_range_lu, float ratio);

/* The uniDrcConfig() the encoder writes for `channels` channels: one
 * channel layout, one coefficients block with two single-band gain sets
 * (regular profile, linear interpolation, the default deltaTmin and DRC
 * frame), and two DRC sets on the base layout: drcSetId 1 (general
 * compression) with gain set 0, drcSetId 2 (night) with gain set 1 and
 * a limiter peak target of -1 dBTP.  Both are in the version 1 syntax,
 * which uniDrcConfigExtension() carries: the FDK decoder applies no
 * version 0 set of this form. */
void aac_drc_gen_config(AacUniDrcConfig* c, int channels);

/* Writes uniDrcConfig() from the fields of `c`; returns the bits written,
 * AAC_ERR_INVALID_ARG for a configuration outside the writer's subset,
 * AAC_ERR_OVERFLOW. */
int aac_drc_gen_write_config(AacBitWriter* w, const AacUniDrcConfig* c);

/* Writes uniDrcGain() for the gain sets of `c` at `frame_length` output
 * samples and `sample_rate`: per gain sequence its nodes (times on the
 * deltaTmin grid, gains rounded to 0.125 dB), a single node at the frame
 * end in the compact coding mode, then uniDrcGainExtPresent 0.  Returns
 * the bits written, AAC_ERR_INVALID_ARG for nodes the syntax cannot
 * carry, AAC_ERR_OVERFLOW. */
int aac_drc_gen_write_gain(AacBitWriter* w, const AacUniDrcConfig* c, const AacUniDrcGainFrame* g,
                           int frame_length, int sample_rate);

/* Writes loudnessInfoSet() with one loudnessInfo() per entry (drcSetId
 * ids[i], downmixId 0, the figures of info[i] as aac_loudness_info_write
 * codes them), no album entries and no extension.  Returns the bits
 * written, AAC_ERR_INVALID_ARG or AAC_ERR_OVERFLOW. */
int aac_drc_gen_write_loudness(AacBitWriter* w, const AacLoudnessInfo* info, const int* ids, int n);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_DRC_GEN_H */
