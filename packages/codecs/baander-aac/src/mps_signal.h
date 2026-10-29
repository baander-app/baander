#ifndef BAANDER_AAC_MPS_SIGNAL_H
#define BAANDER_AAC_MPS_SIGNAL_H

#include <stdint.h>

#include "mps.h"
#include "qmf.h"

#ifdef __cplusplus
extern "C" {
#endif

/* The signal path of the MPEG Surround decoder (ISO/IEC 23003-1): from
 * the dequantised spatial parameters of one spatial frame and the input
 * channels in the QMF domain to the output channels of the 5151, 5152,
 * 525 and 212 trees.  The parser fills AacMpsFrameParams; the path runs
 * over an AacMpsSignalState.
 *
 * Signals of one spatial frame, per slot and hybrid band:
 *   x  the inputs of the pre-matrix: the input channels and, for the
 *      525 tree, the TTT residual;
 *   v  the pre-matrix outputs M1 x: the direct signals followed by the
 *      decorrelator inputs;
 *   d  the decorrelated signals;
 *   w  the direct signals, then per decorrelator the decorrelated
 *      signal or, in the residual bands of its box, the residual;
 *   y  the post-matrix outputs M2 w per output channel, in the
 *      canonical order L, R, C, LFE, Ls, Rs (L, R for 212). */

#define AAC_MPS_MAX_V 6                /* M1 rows: direct signals and decorrelator inputs */
#define AAC_MPS_MAX_X 3                /* M1 columns: input channels and the TTT residual */
#define AAC_MPS_MAX_DECORR 4           /* decorrelators of a tree */
#define AAC_MPS_MAX_W (AAC_MPS_MAX_V)  /* M2 columns */
#define AAC_MPS_MAX_BOXES (AAC_MPS_MAX_OTT_BOXES + AAC_MPS_MAX_TTT_BOXES)
#define AAC_MPS_MAX_SLOTS AAC_USAC_MAX_QMF_SLOTS
#define AAC_MPS_DECORR_MAX_ORDER 20
#define AAC_MPS_DECORR_MAX_DELAY 8
#define AAC_MPS_DECORR_SEEDS 10

/* The parameters of one spatial frame as quantiser indices on the fine
 * scale (a coarse value maps to the fine index as the parser's data
 * reader does), mapped to the parameter bands of bsFreqRes.
 *
 * Parameter sets: `num_sets` sets (1..8) at the slots `param_slot`
 * (ascending, each below the spatial frame length); when the last slot
 * is not the frame's last one the path appends the frame-end set itself.
 * `num_sets` 0 keeps the previous frame's matrices (a lost frame).
 *
 * Boxes are numbered as the bitstream orders them (OTT boxes, then the
 * TTT box): 5151: 0 front+centre/surround, 1 front/centre+LFE, 2 Ls/Rs,
 * 3 L/R, 4 C/LFE; 5152: 0 front+surround/centre+LFE, 1 left/right,
 * 2 C/LFE, 3 L/Ls, 4 R/Rs; 525: 0 C/LFE, 1 L/Ls, 2 R/Rs, TTT 0; 212: 0.
 * Index ranges: CLD -15..15, ICC 0..7, CPC -20..30 (0.1 per step), the
 * arbitrary downmix gain -15..15 on the CLD scale (dB).
 *
 * Output channels are indexed in the canonical order L, R, C, LFE, Ls,
 * Rs (L, R for 212) for the temporal shaping flags and envelopes; the
 * LFE entry is never read. */
using AacMpsFrameParams = struct AacMpsFrameParams_ {
  int num_sets;
  int param_slot[AAC_MPS_MAX_PARAM_SETS];
  int ott_cld[AAC_MPS_MAX_OTT_BOXES][AAC_MPS_MAX_PARAM_SETS][AAC_MPS_MAX_PARAM_BANDS];
  int ott_icc[AAC_MPS_MAX_OTT_BOXES][AAC_MPS_MAX_PARAM_SETS][AAC_MPS_MAX_PARAM_BANDS];
  int ttt_cpc1[AAC_MPS_MAX_TTT_BOXES][AAC_MPS_MAX_PARAM_SETS][AAC_MPS_MAX_PARAM_BANDS];
  int ttt_cpc2[AAC_MPS_MAX_TTT_BOXES][AAC_MPS_MAX_PARAM_SETS][AAC_MPS_MAX_PARAM_BANDS];
  int ttt_icc[AAC_MPS_MAX_TTT_BOXES][AAC_MPS_MAX_PARAM_SETS][AAC_MPS_MAX_PARAM_BANDS];
  int ttt_cld1[AAC_MPS_MAX_TTT_BOXES][AAC_MPS_MAX_PARAM_SETS][AAC_MPS_MAX_PARAM_BANDS];
  int ttt_cld2[AAC_MPS_MAX_TTT_BOXES][AAC_MPS_MAX_PARAM_SETS][AAC_MPS_MAX_PARAM_BANDS];
  int arbdmx_gain[AAC_MPS_MAX_INPUT_CHANNELS][AAC_MPS_MAX_PARAM_SETS][AAC_MPS_MAX_PARAM_BANDS];
  /* SmgData: the bands whose matrices are smoothed, per set, and the
   * smoothing time in slots (64, 128, 256 or 512; 0 smooths nothing) */
  int smoothing[AAC_MPS_MAX_PARAM_SETS][AAC_MPS_MAX_PARAM_BANDS];
  int smooth_time[AAC_MPS_MAX_PARAM_SETS];
  /* TempShapeData: bsTempShapeEnable, the per-channel flags of the
   * configured tool and, for GES, the dequantised envelope per slot */
  int temp_shape_enable;
  int stp_channel[AAC_MPS_MAX_OUTPUT_CHANNELS];
  int ges_channel[AAC_MPS_MAX_OUTPUT_CHANNELS];
  float env_shape[AAC_MPS_MAX_OUTPUT_CHANNELS][AAC_MPS_MAX_SLOTS];
};

/* ── tables (src/mps_tables.cpp) ────────────────────────────────── */

extern const float aac_mps_cld_db[31];          /* CLD index -15..15 in dB */
extern const float aac_mps_icc_value[8];        /* ICC index 0..7 */
extern const float aac_mps_cpc_value[51];       /* CPC index -20..30 */
extern const float aac_mps_surround_gain[5];    /* bsFixedGainSur */
extern const float aac_mps_lfe_gain[5];         /* bsFixedGainLfe */
extern const float aac_mps_dmx_gain[8];         /* bsFixedGainDmx */
extern const float aac_mps_env_shape_value[2][5]; /* bsEnvShapeData per bsEnvQuantMode */
/* Decorrelator (6.6.2): the QMF band above which the next reverb band
 * starts per bsDecorrConfig, the delay and the all-pass filter order per
 * reverb band, and the direct-form denominator per reverb band and
 * decorrelator index (the numerator is the reversed denominator). */
extern const uint8_t aac_mps_decorr_split[3][4];
extern const uint8_t aac_mps_decorr_delay[4];
extern const uint8_t aac_mps_decorr_order[4];
extern const float aac_mps_decorr_den[4][AAC_MPS_DECORR_SEEDS][AAC_MPS_DECORR_MAX_ORDER + 1];

/* ── tree definitions and mixing matrices (src/mps_tree.cpp) ─────── */

/* The signal layout of a tree. */
using AacMpsTreeLayout = struct AacMpsTreeLayout_ {
  int num_inputs;   /* input channels (M1 columns before the residual) */
  int num_x;        /* M1 columns */
  int num_direct;   /* direct signals: the first M1 rows / M2 columns */
  int num_decorr;   /* decorrelators: the remaining M1 rows / M2 columns */
  int num_outputs;  /* output channels */
  int ttt_residual_col;                  /* M1 column of the TTT residual, -1 without */
  int decorr_box[AAC_MPS_MAX_DECORR];    /* the OTT box whose residual replaces decorrelator j, -1 none */
  int ttt_decorr_band[AAC_MPS_MAX_PARAM_BANDS]; /* 525: the band takes the TTT decorrelator sum */
  int stp_group[AAC_MPS_MAX_OUTPUT_CHANNELS];   /* the input channel whose direct energy STP references, -1 unshaped */
  int ges_ref[AAC_MPS_MAX_OUTPUT_CHANNELS];     /* GES: 0/1 the input channel's envelope, 2 their mean, -1 unshaped */
};

/* The mixing matrices of one parameter band and set: M1 (complex, on x)
 * and M2 on w.  `m2_dry` applies to the direct signals (columns below
 * num_direct) and to the residual signals (the other columns);
 * `m2_wet` applies to the decorrelated signals (zero below
 * num_direct). Rows of M2 are output channels in the canonical order. */
using AacMpsBandMatrices = struct AacMpsBandMatrices_ {
  float m1[AAC_MPS_MAX_V][AAC_MPS_MAX_X][2];
  float m2_dry[AAC_MPS_MAX_OUTPUT_CHANNELS][AAC_MPS_MAX_W];
  float m2_wet[AAC_MPS_MAX_OUTPUT_CHANNELS][AAC_MPS_MAX_W];
};

/* Fills the layout of cfg's tree; returns AAC_OK or AAC_ERR_UNSUPPORTED. */
int aac_mps_tree_layout(const AacMpsConfig* cfg, AacMpsTreeLayout* t);

/* The one-to-two mixing matrix h[row][col] (left = h00 m + h01 d, right
 * = h10 m + h11 d) of a CLD and ICC index.  In a residual band the
 * decorrelator columns are zero (the residual enters with weights 1 and
 * -1) and the ICC is limited so that the downmix gain stays below 1.2. */
void aac_mps_ott_matrix(int cld_idx, int icc_idx, int residual_band, float h[2][2]);
/* The TTT matrix m[row][col] (rows l, r, c; columns the first and second
 * input and the residual) of bsTttMode `mode`: prediction (0, 1) from
 * the CPC indices and, for mode 0 outside the residual bands, the ICC
 * index; energy modes (2..5) from the CLD indices. */
void aac_mps_ttt_matrix(int mode, int cpc1_idx, int cpc2_idx, int icc_idx, int cld1_idx,
                        int cld2_idx, int residual_band, float m[3][3]);
/* The inverse of the matrix-compatible downmix (bsMatrixMode) as a
 * complex 2x2 h[row][col][re/im], from the CLD indices of the L/Ls and
 * R/Rs boxes and the TTT parameters: the CPC indices in a prediction
 * mode (`prediction` set), the CLD indices otherwise. */
void aac_mps_ttt_inverse(int cld_l_idx, int cld_r_idx, int prediction, int p1_idx, int p2_idx,
                         float h[2][2][2]);
/* The matrices of parameter set `set` and band `band`. */
void aac_mps_tree_matrices(const AacMpsConfig* cfg, const AacMpsTreeLayout* t,
                           const AacMpsFrameParams* p, int set, int band, AacMpsBandMatrices* m);

/* ── decorrelator (src/mps_dec.cpp) ─────────────────────────────── */

using AacMpsDecorr = struct AacMpsDecorr_ {
  int reverb_band[AAC_MPS_HYBRID_BANDS];
  const float* den[AAC_MPS_HYBRID_BANDS];
  float delay[AAC_MPS_HYBRID_BANDS][AAC_MPS_DECORR_MAX_DELAY][2];
  int delay_pos[AAC_MPS_HYBRID_BANDS];
  float state[AAC_MPS_HYBRID_BANDS][AAC_MPS_DECORR_MAX_ORDER + 1][2];
  float e_in[AAC_MPS_MAX_PARAM_BANDS], e_out[AAC_MPS_MAX_PARAM_BANDS];
  int ducking; /* the energy adjustment, on after init */
};

/* Decorrelator `index` (its filter set) under bsDecorrConfig. */
void aac_mps_decorr_init(AacMpsDecorr* d, int index, int decorr_config);
/* One slot of 71 hybrid bands: the pre-delay, the all-pass filter and
 * the energy adjustment per parameter band of the 28 band scale. */
void aac_mps_decorr_slot(AacMpsDecorr* d, const float (*in)[2], float (*out)[2]);

/* ── the signal path (src/mps_dec.cpp) ──────────────────────────── */

typedef struct AacMpsSignalState_ AacMpsSignalState;

/* `core_frame_length` is the core frame (1024, 960 or 512), `qmf_slots`
 * the QMF slots per core frame as the input arrives (16 for a 1024 frame
 * without SBR, 32 with 2:1 SBR); cfg->frame_length slots make a spatial
 * frame, a multiple of qmf_slots up to AAC_MPS_MAX_SLOTS.  Returns NULL
 * for a tree or configuration outside this path. */
AacMpsSignalState* aac_mps_signal_create(const AacMpsConfig* cfg, int core_frame_length,
                                         int qmf_slots);
void aac_mps_signal_destroy(AacMpsSignalState* s);
void aac_mps_signal_reset(AacMpsSignalState* s);
int aac_mps_signal_slots(const AacMpsSignalState* s);
const AacMpsTreeLayout* aac_mps_signal_layout(const AacMpsSignalState* s);

/* The residual of box `index` (box order as above) for the next spatial
 * frame: core time samples at full scale 1.0, cfg->frame_length slots
 * times the residual's QMF bands (64 when the core rate is the output
 * rate, 32 with 2:1 SBR) samples.  NULL clears it. */
void aac_mps_signal_set_residual(AacMpsSignalState* s, int index, const float* pcm, int length);
/* Delays the residuals by `slots` against the inputs (default 0). */
void aac_mps_signal_set_residual_delay(AacMpsSignalState* s, int slots);

/* One spatial frame: `in` holds cfg->num_input_channels QMF frames of
 * frame_length slots, `out` receives num_output_channels frames in the
 * canonical order.  The output lags the input by the hybrid delay of six
 * slots in every band.  `p` NULL keeps the previous parameters. */
void aac_mps_signal_apply(AacMpsSignalState* s, const AacMpsFrameParams* p,
                          const AacUsacQmfFrame* const* in, AacUsacQmfFrame* const* out);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_MPS_SIGNAL_H */
