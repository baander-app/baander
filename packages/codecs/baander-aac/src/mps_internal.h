#ifndef BAANDER_AAC_MPS_INTERNAL_H
#define BAANDER_AAC_MPS_INTERNAL_H

#include <stdint.h>

#include "bitstream.h"
#include "mps.h"
#include "mps_signal.h"

/* The inside of the MPEG Surround decoder (ISO/IEC 23003-1 as AAC
 * carries it): the parameter coding shared with the 2-1-2 tool of USAC
 * (mps_params.cpp), the SpatialSpecificConfig reader (mps_config.cpp),
 * the SpatialFrame reader with its mapping to parameter sets
 * (mps_frame.cpp) and the state that the signal path builds on. */

#ifdef __cplusplus
extern "C" {
#endif

#define AAC_MPS_MAX_TTT_RANGES 2 /* the low and the high band range of a TTT box */
#define AAC_MPS_MAX_EXTENSIONS 8 /* SacExtensionConfig entries kept */

/* ── parameter coding (mps_params.cpp) ──────────────────────────── */

/* The parameter types of EcData(): the quantiser of each sets the level
 * count, the index offset and whether the fine quantiser codes its
 * lowest bit apart. */
enum {
  AAC_MPS_PARAM_CLD = 0,
  AAC_MPS_PARAM_ICC = 1,
  AAC_MPS_PARAM_IPD = 2,
  AAC_MPS_PARAM_CPC = 3
};

/* Variants of EcDataPair(): the AAC form may follow bsPcmCoding with
 * bsPilotCoding when more than four bands are coded; the low-delay form
 * pairs values along frequency only. */
#define AAC_MPS_EC_PILOT 1
#define AAC_MPS_EC_NO_TIME_PAIRS 2

/* Reads one codeword from a node table (an entry greater than zero is
 * the next node, one of zero or less a leaf holding -(symbol + 1));
 * returns the symbol, -1 for the escape leaf of a two-dimensional book,
 * or -2 when the walk leaves the table. */
int aac_mps_huff_read(AacBitReader* r, const int16_t (*nodes)[2], int count);

/* EcDataPair() of `pair ? 2 : 1` sets of `bands` values into out[set]
 * (and out[set + 1]); `history` holds the reference set's coded values
 * on the quantiser scale of `coarse`, `time_ref` says a time difference
 * to it is allowed, `flags` picks the variant.  Returns 0 or -1. */
int aac_mps_ec_pair_read(AacBitReader* r, int type, int (*out)[AAC_MPS_MAX_PARAM_BANDS], int set,
                         int bands, int pair, int coarse, int time_ref, const int* history,
                         int flags);

/* The parameter band groups of a stride (bsFreqResStride): map[0..groups]
 * are the group borders; returns the number of groups. */
int aac_mps_band_map(int bands, int stride, int* map);

/* bsFreqResStride to the stride in bands. */
extern const uint8_t aac_mps_stride[4];

/* The CPC codebooks (the CLD, ICC and IPD books live in mps212_tables.cpp). */
extern const int16_t aac_mps_huff_cpc_first[25][2];
extern const int16_t aac_mps_huff_cpc_1d[3][25][2];
extern const int16_t aac_mps_huff_cpc_2d_lav3[3][2][15][2];
extern const int16_t aac_mps_huff_cpc_2d_lav6[3][2][48][2];
extern const int16_t aac_mps_huff_cpc_2d_lav9[3][2][99][2];
extern const int16_t aac_mps_huff_cpc_2d_lav12[3][2][168][2];

/* ── the parsed parameters of one spatial frame ─────────────────── */

/* The parser fills the AacMpsFrameParams of mps_signal.h: the quantiser
 * indices on the fine scale per box, parameter set and bitstream
 * parameter band (bsQuantMode applied to the OTT CLDs of the 5151 and
 * 5152 trees, the ICC differences of the residual bands added, one ICC
 * copied to every box with bsOneIcc, coarse sets widened to the fine
 * scale), with set `num_sets - 1` always on the last slot of the frame
 * (a copy of the last coded set is appended when the coded sets end
 * earlier).  Bands beyond a box's coded count (bsOttBands of an LFE
 * box) carry the default values, the TTT arrays of the mode not in use
 * their defaults too.  The temporal shaping flags and envelopes are in
 * the canonical output order L, R, C, LFE, Ls, Rs (the bitstream orders
 * them L, R, C, Ls, Rs, without C for the subband domain processing of
 * the 525 tree). */

/* The coded history one parameter stream (a box's CLD, ICC, CPC...)
 * carries from frame to frame, and the coded sets of the current frame. */
using AacMpsEcStream = struct AacMpsEcStream_ {
  int mode[AAC_MPS_MAX_PARAM_SETS];         /* bsDataMode per parameter set */
  int coded_coarse[AAC_MPS_MAX_PARAM_SETS]; /* bsQuantCoarse per coded set */
  int stride_idx[AAC_MPS_MAX_PARAM_SETS];   /* bsFreqResStride per coded set */
  int cmp[AAC_MPS_MAX_PARAM_SETS][AAC_MPS_MAX_PARAM_BANDS]; /* coded values per coded set */
  int last[AAC_MPS_MAX_PARAM_BANDS];     /* the coded history on the coded quantiser scale */
  int coarse_prev;                       /* the quantiser of the last coded set */
  int idx_prev[AAC_MPS_MAX_PARAM_BANDS]; /* the last mapped indices */
  int set_coarse[AAC_MPS_MAX_PARAM_SETS]; /* the quantiser each set was coded on */
};

/* The parameter streams of a frame, as EcData() orders them. */
enum {
  AAC_MPS_STREAM_OTT_CLD = 0,                                  /* + box */
  AAC_MPS_STREAM_OTT_ICC = AAC_MPS_STREAM_OTT_CLD + AAC_MPS_MAX_OTT_BOXES,
  AAC_MPS_STREAM_TTT_CPC1 = AAC_MPS_STREAM_OTT_ICC + AAC_MPS_MAX_OTT_BOXES, /* + range */
  AAC_MPS_STREAM_TTT_CPC2 = AAC_MPS_STREAM_TTT_CPC1 + AAC_MPS_MAX_TTT_RANGES,
  AAC_MPS_STREAM_TTT_ICC = AAC_MPS_STREAM_TTT_CPC2 + AAC_MPS_MAX_TTT_RANGES,
  AAC_MPS_STREAM_TTT_CLD1 = AAC_MPS_STREAM_TTT_ICC + AAC_MPS_MAX_TTT_RANGES,
  AAC_MPS_STREAM_TTT_CLD2 = AAC_MPS_STREAM_TTT_CLD1 + AAC_MPS_MAX_TTT_RANGES,
  AAC_MPS_STREAM_ARBDMX = AAC_MPS_STREAM_TTT_CLD2 + AAC_MPS_MAX_TTT_RANGES, /* + channel */
  AAC_MPS_NUM_STREAMS = AAC_MPS_STREAM_ARBDMX + AAC_MPS_MAX_INPUT_CHANNELS
};

/* The frame fields the parse keeps between frames (SmgData continues the
 * previous set's smoothing with bsSmoothMode 1). */
using AacMpsFrameState = struct AacMpsFrameState_ {
  AacMpsEcStream stream[AAC_MPS_NUM_STREAMS];
  int prev_smooth_time;
  int prev_smoothing[AAC_MPS_MAX_PARAM_BANDS];
  int num_sets_prev;
};

/* ── the decoder state ──────────────────────────────────────────── */

struct AacMpsState_ {
  /* ── configuration ── */
  AacMpsConfig cfg;
  int core_frame_length, qmf_slots, sample_rate;
  int slots;     /* QMF slots per spatial frame */
  int num_bands; /* bitstream parameter bands */
  int ott_bands[AAC_MPS_MAX_OTT_BOXES]; /* coded bands per OTT box */
  int ott_cld_default[AAC_MPS_MAX_OTT_BOXES];
  /* the TTT box's band ranges [start, stop) and their modes; an empty
   * high range when bsTttDualMode is 0 */
  int ttt_start[AAC_MPS_MAX_TTT_RANGES], ttt_stop[AAC_MPS_MAX_TTT_RANGES];
  int ttt_mode[AAC_MPS_MAX_TTT_RANGES];
  int num_stp_channels, num_ges_channels; /* channels TempShapeData() flags */
  int num_ext;                            /* SacExtensionConfig entries */
  int ext_type[AAC_MPS_MAX_EXTENSIONS];
  int residual_frames; /* bsResidualFramesPerSpatialFrame + 1 */

  /* ── parameters (mps_frame.cpp) ── */
  AacMpsFrameParams params; /* the mapped parameters of the current frame */
  AacMpsFrameState frame;   /* the coded history */
  int frame_valid;          /* a frame has been read since the reset */
  int independency;         /* bsIndependencyFlag of the current frame */
  /* ResidualData(): where the residual_data() channel elements sat in
   * the frame the last aac_mps_read_frame parsed: `residual_present`
   * says the frame carried the extension, `residual_pos` is the bit
   * offset from the start of that frame's data of the first
   * individual_channel_stream() and `residual_end` the offset of the
   * extension's end.  The elements follow in box order (OTT boxes, then
   * the TTT box; only the boxes with residual_present in the config),
   * residual_frames per box, each an AAC-LC individual_channel_stream
   * (common_window 0) at the residual sampling frequency index of the
   * config, read through the residual reader when one is set. */
  int residual_present;
  int residual_pos;
  int residual_end;
  AacMpsResidualReader residual_reader; /* the caller's core decoder for residual_data() */
  void* residual_ctx;

  /* ── the signal path (mps_glue.cpp): created on the first
   * aac_mps_apply or aac_mps_set_residual, with the accumulation of core
   * frames into a spatial frame; `frame_pending` says a frame was read
   * since the last aac_mps_apply, which otherwise keeps the previous
   * parameters as for a lost frame. ── */
  struct AacMpsGlue_* glue;
  int frame_pending;
};

/* The glue's state (mps_glue.cpp): aac_mps_destroy frees it,
 * aac_mps_reset clears it. */
void aac_mps_glue_destroy(struct AacMpsGlue_* g);
void aac_mps_glue_reset(struct AacMpsGlue_* g);

/* The mapped parameters of the last frame read or concealed. */
const AacMpsFrameParams* aac_mps_frame_params(const AacMpsState* s);

/* ── configuration (mps_config.cpp) ─────────────────────────────── */

/* SpatialSpecificConfig() of the low-delay variant that the ELD
 * specific configuration carries (ldmps, ISO/IEC 23003-1 Annex on
 * AAC-ELD): bsFrameLength of five bits, no bsOneIcc, fixed gains,
 * bsMatrixMode or bs3DaudioMode for the 2-1-2 tree.  The reader must be
 * bounded to the extension's length, as aac_mps_config_read's.  Fills
 * `cfg` with low_delay set. */
int aac_mps_config_read_ld(AacBitReader* r, AacMpsConfig* cfg);

/* The parameter bands of a bsFreqRes value in the low-delay form (no
 * hybrid bands: 23, 15, 12, 9, 7, 5, 4). */
int aac_mps_param_bands_ld(int freq_res);

/* The box counts, channel counts and LFE boxes of a tree; returns 0 or
 * AAC_ERR_UNSUPPORTED for a tree outside this decoder. */
int aac_mps_tree_properties(int tree_config, int* num_ott, int* num_ttt, int* num_in, int* num_out,
                            int* ott_lfe);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_MPS_INTERNAL_H */
