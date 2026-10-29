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
 * smoothing data and the temporal shaping data; the decoder builds the
 * left and right QMF-domain channels from the downmix (and the residual
 * when the configuration codes one) with the hybrid filterbank, the
 * decorrelator, the per-slot interpolated mixing matrices and the
 * temporal shaping tools. */

typedef struct AacMps212State_ AacMps212State;

#define AAC_MPS212_MAX_PARAM_BANDS 28
#define AAC_MPS212_HYBRID_BANDS 71
#define AAC_MPS212_MAX_PARAM_SETS 9 /* eight coded sets and the frame-end set */

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
 * output lags the downmix by six slots in every band: the hybrid
 * filtering of the three lowest bands and the delay that keeps the
 * others in step with them.  The parameter grid of the frame sits on the
 * SBR output timing: on the input slots with stereoConfigIndex 1 and 2
 * (the SBR output), on the output slots with 3 (the input is the plain
 * analysis, six slots ahead of it). */
void aac_mps212_apply(AacMps212State* s, const AacUsacQmfFrame* dmx, const float* residual,
                      AacUsacQmfFrame* left, AacUsacQmfFrame* right);

/* Supplies the parameters of a lost frame (the previous ones held). */
void aac_mps212_conceal(AacMps212State* s);

/* Delays the residual by `slots` QMF slots against the downmix.  With
 * stereoConfigIndex 1 and 2 the default is the three envelope time slots
 * (times the QMF slots per time slot) by which the SBR module hands the
 * downmix later than the plain analysis of the core frame, and a
 * harmonic transposer adds its own delay; with 3 the downmix arrives at
 * the analysis timing and the default is zero. */
void aac_mps212_set_residual_delay(AacMps212State* s, int slots);

/* ── tables (src/mps212_tables.cpp) ─────────────────────────────── */

/* Parameter bands per bsFreqRes (0 is reserved). */
extern const uint8_t aac_mps212_freq_res_bands[8];
/* Hybrid band (71) to parameter band for a band count of the table. */
const uint8_t* aac_mps212_kernel(int num_bands);
/* The first hybrid band of each parameter band on the 28 band scale. */
extern const uint8_t aac_mps212_param_band_first_hybrid[29];
extern const float aac_mps212_cld_db[31];   /* CLD index -15..15 in dB */
extern const float aac_mps212_icc_value[8]; /* ICC index 0..7 */
extern const float aac_mps212_ipd_rad[16];  /* IPD index 0..15 */
extern const float aac_mps212_fixed_gain[8];
extern const uint8_t aac_mps212_stride[4];
extern const uint16_t aac_mps212_smoothing_time[4];
extern const float aac_mps212_hybrid_proto8[13];
extern const float aac_mps212_hybrid_proto2[13];
extern const uint8_t aac_mps212_decorr_delay[4];
extern const uint8_t aac_mps212_decorr_split[3][4];
extern const uint8_t aac_mps212_decorr_order[4];
extern const float aac_mps212_decorr_den[4][11];
extern const float aac_mps212_stp_bp[25];
extern const float aac_mps212_stp_gf[25];
extern const uint8_t aac_mps212_tsd_bits_32[16];
extern const uint8_t aac_mps212_tsd_bits_64[32];
extern const int32_t aac_mps212_opd_q28[16][8][31];

/* Huffman node tables: an entry greater than zero is the next node, an
 * entry of zero or less a leaf holding -(symbol + 1). */
extern const int16_t aac_mps212_huff_cld_first[30][2];
extern const int16_t aac_mps212_huff_icc_first[7][2];
extern const int16_t aac_mps212_huff_ipd_first[7][2];
extern const int16_t aac_mps212_huff_lav[3][2];
extern const int16_t aac_mps212_huff_env_shape[39][2];
extern const int16_t aac_mps212_huff_cld_1d[3][30][2];
extern const int16_t aac_mps212_huff_icc_1d[3][7][2];
extern const int16_t aac_mps212_huff_ipd_1d[3][7][2];
extern const int16_t aac_mps212_huff_cld_2d_lav3[3][2][15][2];
extern const int16_t aac_mps212_huff_cld_2d_lav5[3][2][35][2];
extern const int16_t aac_mps212_huff_cld_2d_lav7[3][2][63][2];
extern const int16_t aac_mps212_huff_cld_2d_lav9[3][2][99][2];
extern const int16_t aac_mps212_huff_icc_2d_lav1[3][2][3][2];
extern const int16_t aac_mps212_huff_icc_2d_lav3[3][2][15][2];
extern const int16_t aac_mps212_huff_icc_2d_lav5[3][2][35][2];
extern const int16_t aac_mps212_huff_icc_2d_lav7[3][2][63][2];
extern const int16_t aac_mps212_huff_ipd_2d_lav1[3][2][3][2];
extern const int16_t aac_mps212_huff_ipd_2d_lav3[3][2][15][2];
extern const int16_t aac_mps212_huff_ipd_2d_lav5[3][2][35][2];
extern const int16_t aac_mps212_huff_ipd_2d_lav7[3][2][63][2];

/* ── internal entry points, for the tests ───────────────────────── */

/* Reads one codeword from a node table; returns the symbol (-1 for the
 * escape leaf of a two-dimensional book) or -2 when the table is left. */
int aac_mps212_huff_read(AacBitReader* r, const int16_t (*nodes)[2], int count);

/* The parameter band groups of a stride: map[0..groups] are the group
 * borders; returns the number of groups. */
int aac_mps212_band_map(int bands, int stride, int* map);

/* The mixing matrix h[row][col][re/im] of one parameter band in the
 * parametric mode (no residual): left = h[0][0] m + h[0][1] w, right =
 * h[1][0] m + h[1][1] w, for the quantiser indices of CLD (-15..15) and
 * ICC (0..7). */
void aac_mps212_ott_matrix(int cld_idx, int icc_idx, float h[2][2][2]);

/* The prediction mode weights of one band (residual coding): left =
 * (w - re - j im) m + w res, right = (w + re + j im) m - w res, with
 * `beta` in place of w on the decorrelated signal where no residual is
 * coded. */
void aac_mps212_pred_weights(int cld_idx, int icc_idx, int ipd_idx, int has_ipd, float* w,
                             float* re, float* im, float* beta);

/* The parsed parameters of the last frame: quantiser indices on the fine
 * scale (type 0 CLD, 1 ICC, 2 IPD) after the mapping to parameter bands
 * and the interpolation of bsDataMode 2 sets. */
int aac_mps212_num_param_bands(const AacMps212State* s);
int aac_mps212_num_ipd_bands(const AacMps212State* s);
int aac_mps212_num_param_sets(const AacMps212State* s);
int aac_mps212_param_slot(const AacMps212State* s, int set);
int aac_mps212_param(const AacMps212State* s, int type, int set, int band);
int aac_mps212_data_mode(const AacMps212State* s, int type, int set);
int aac_mps212_phase_mode(const AacMps212State* s);
int aac_mps212_smooth_mode(const AacMps212State* s, int set);
int aac_mps212_smoothing(const AacMps212State* s, int set, int band);
int aac_mps212_temp_shape_enabled(const AacMps212State* s);
int aac_mps212_env_shape_channel(const AacMps212State* s, int ch);
float aac_mps212_env_shape(const AacMps212State* s, int ch, int slot);
int aac_mps212_tsd_transient(const AacMps212State* s, int slot);
int aac_mps212_tsd_phase(const AacMps212State* s, int slot);

/* The hybrid filterbank: `in` holds 13 QMF slots (oldest first) and the
 * analysis yields the 71 hybrid bands of the centre slot, the lowest ten
 * from the filters over QMF bands 0..2 and the upper 61 from the bands
 * themselves; the synthesis sums them back into 64 bands. */
void aac_mps212_hybrid_analysis(const float (*in)[64][2], float (*out)[2]);
void aac_mps212_hybrid_synthesis(const float (*in)[2], float (*out)[2]);

/* One slot of 71 hybrid bands through the state's decorrelator. */
void aac_mps212_decorrelate(AacMps212State* s, const float (*in)[2], float (*out)[2]);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_MPS212_H */
