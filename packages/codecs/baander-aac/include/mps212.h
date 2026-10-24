#ifndef BAANDER_AAC_MPS212_H
#define BAANDER_AAC_MPS212_H

#include "usac.h"

#ifdef __cplusplus
extern "C" {
#endif

/* MPEG Surround 212 (ISO/IEC 23003-3 7.11 with the one-to-two box of
 * ISO/IEC 23003-1): the stereo tool of a USAC CPE with stereoConfigIndex
 * greater than 0.  Mps212Data() carries the CLD, ICC and (with phase
 * coding) IPD parameters per parameter band and parameter set, the
 * temporal shaping data and the smoothing data; the decoder builds the
 * left and right QMF-domain channels from the downmix (and the residual
 * when the configuration codes one) with the decorrelator, the
 * parameter interpolation and the temporal shaping tools. */

typedef struct AacMps212State_ AacMps212State;

#define AAC_MPS212_MAX_PARAM_BANDS 28
#define AAC_MPS212_HYBRID_BANDS 71
#define AAC_MPS212_MAX_PARAM_SETS 8

/* `frame_length` is the core frame length, `output_frame_length` the
 * number of time samples per frame after SBR (the QMF frame has
 * output_frame_length / 64 slots), `sample_rate` the output rate. */
AacMps212State* aac_mps212_create(const AacUsacMps212Config* cfg, int stereo_config_index,
                                  int frame_length, int output_frame_length, int sample_rate);
void aac_mps212_destroy(AacMps212State* s);
void aac_mps212_reset(AacMps212State* s);

/* Parses Mps212Data() of the frame.  `indep` is usacIndependencyFlag (no
 * reference to the previous frame's parameters then).  Returns AAC_OK or
 * AAC_ERR_DECODE; a damaged frame keeps the previous parameters. */
int aac_mps212_read(AacMps212State* s, AacBitReader* r, int indep);

/* Builds the two output channels from the downmix.  `residual` is the
 * residual channel of the CPE as core time samples (frame_length, full
 * scale 1.0) when the configuration codes one, NULL otherwise; the module
 * runs it through its own QMF analysis.  `left` may alias `dmx`.  The
 * output is delayed by the six slots of the hybrid filterbank. */
void aac_mps212_apply(AacMps212State* s, const AacUsacQmfFrame* dmx, const float* residual,
                      AacUsacQmfFrame* left, AacUsacQmfFrame* right);

/* Supplies the parameters of a lost frame (the previous ones held). */
void aac_mps212_conceal(AacMps212State* s);

/* Delays the residual by `slots` QMF slots against the downmix, for a
 * frame walker whose SBR path hands the downmix later than the plain
 * analysis of the core frame would. */
void aac_mps212_set_residual_delay(AacMps212State* s, int slots);

/* ── tables and internal entry points, for the tests ───────────── */

/* Parameter bands per bsFreqRes (0 is reserved). */
extern const uint8_t aac_mps212_freq_res_bands[8];
/* Hybrid band (71) to parameter band for a band count of the table. */
const uint8_t* aac_mps212_kernel(int num_bands);
extern const float aac_mps212_cld_db[31];   /* CLD index -15..15 in dB */
extern const float aac_mps212_icc_value[8]; /* ICC index 0..7 */
extern const float aac_mps212_fixed_gain_db[8];

/* Codebooks of the Huffman-coded parameters.  The two-dimensional books
 * come in four LAV classes per type (CLD 3, 5, 7, 9; ICC and IPD 1, 3, 5,
 * 7); a symbol of a one-dimensional book is the value minus the book's
 * offset, a symbol of a two-dimensional book is (x + lav) * (2 lav + 1) +
 * (y + lav). */
enum {
  AAC_MPS212_CB_CLD_FIRST = 0,
  AAC_MPS212_CB_ICC_FIRST,
  AAC_MPS212_CB_IPD_FIRST,
  AAC_MPS212_CB_CLD_1D,
  AAC_MPS212_CB_ICC_1D,
  AAC_MPS212_CB_IPD_1D,
  AAC_MPS212_CB_CLD_2D, /* four classes */
  AAC_MPS212_CB_ICC_2D = AAC_MPS212_CB_CLD_2D + 4,
  AAC_MPS212_CB_IPD_2D = AAC_MPS212_CB_CLD_2D + 8,
  AAC_MPS212_CB_LAV = AAC_MPS212_CB_CLD_2D + 12,
  AAC_MPS212_CB_ENV,
  AAC_MPS212_CB_COUNT
};
/* The codeword of a symbol; returns 0, or -1 for an invalid symbol. */
int aac_mps212_huff_code(const AacMps212State* s, int codebook, int symbol, uint32_t* code,
                         int* len);
int aac_mps212_codebook_info(const AacMps212State* s, int codebook, int* nsym, int* offset,
                             int* lav);

/* The mixing matrix h[row][col][re/im] of one parameter band: left =
 * h[0][0] m + h[0][1] w, right = h[1][0] m + h[1][1] w, for a CLD in dB,
 * an ICC value and an IPD in radians. */
void aac_mps212_ott_matrix(float cld_db, float icc, float ipd, int has_ipd, float h[2][2][2]);

/* The parsed parameters of the last frame: quantiser indices on the fine
 * scale (type 0 CLD, 1 ICC, 2 IPD). */
int aac_mps212_num_param_bands(const AacMps212State* s);
int aac_mps212_num_param_sets(const AacMps212State* s);
int aac_mps212_param_slot(const AacMps212State* s, int set);
int aac_mps212_param(const AacMps212State* s, int type, int set, int band);
int aac_mps212_data_mode(const AacMps212State* s, int type, int set);
int aac_mps212_phase_mode(const AacMps212State* s);
int aac_mps212_smooth_mode(const AacMps212State* s, int set);
int aac_mps212_temp_shape_enabled(const AacMps212State* s);
int aac_mps212_env_shape_channel(const AacMps212State* s, int ch);
int aac_mps212_env_shape(const AacMps212State* s, int ch, int slot);
int aac_mps212_tsd_transient(const AacMps212State* s, int slot);
int aac_mps212_tsd_phase(const AacMps212State* s, int slot);

/* The hybrid filterbank: `in` holds 13 QMF slots (oldest first) and the
 * analysis yields the 71 hybrid bands of the centre slot; the synthesis
 * sums them back into 64 bands. */
void aac_mps212_hybrid_analysis(const float (*in)[64][2], float (*out)[2]);
void aac_mps212_hybrid_synthesis(const float (*in)[2], float (*out)[2]);

/* One slot of 71 hybrid bands through the state's decorrelator. */
void aac_mps212_decorrelate(AacMps212State* s, const float (*in)[2], float (*out)[2]);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_MPS212_H */
