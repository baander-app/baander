#ifndef BAANDER_AAC_SBR_H
#define BAANDER_AAC_SBR_H

#include <cstdint>

#include "aac_tables.h"
#include "bitstream.h"
#include "ps.h"
#include "qmf.h"

#ifdef __cplusplus
extern "C" {
#endif

/* Spectral band replication decoder (ISO/IEC 14496-3 4.6.18) for one SCE
 * or CPE: parses the sbr_extension_data of the element's FIL element,
 * and turns the element's 1024 core samples per channel into 2048 samples
 * at twice the core rate.  The design follows the standard and the
 * structure of FFmpeg's decoder: 32-band QMF analysis of the core, the
 * LPC-based high-frequency generator with inverse filtering per patch,
 * envelope and noise-floor adjustment with gain limiting and sinusoid
 * insertion, and 64-band synthesis.  An element whose stream carries no
 * SBR data is upsampled through the same filterbanks so that every
 * channel of a frame keeps the same delay.  A single channel element
 * that carries parametric stereo data can be asked for a stereo pair:
 * the PS decoder then builds the right channel from the left one. */

#define AAC_SBR_SLOTS 16          /* envelope time slots per frame */
#define AAC_SBR_QMF_SLOTS 32      /* QMF slots per frame */
#define AAC_SBR_HF_HISTORY 8      /* QMF slots of the previous frame kept for the generator */
#define AAC_SBR_ADJ_OFFSET 2      /* slots of lead-in in the adjuster's buffers */
#define AAC_SBR_MAX_BANDS 48      /* high-resolution envelope bands */
#define AAC_SBR_MAX_ENV 8
#define AAC_SBR_MAX_NOISE_BANDS 5
#define AAC_SBR_MAX_PATCHES 6
#define AAC_SBR_MAX_LIMITER 30

/* The frequency band tables of one SBR configuration (4.6.18.3), built
 * from the header fields and the SBR sample rate. */
using AacSbrFreq = struct AacSbrFreq_ {
  int sample_rate; /* the SBR rate, twice the core rate */
  int start_freq, stop_freq, xover_band, freq_scale, alter_scale, noise_bands;
  int limiter_bands;
  int k[3];  /* k0, k1, k2 */
  int kx, m; /* first SBR band and the number of bands */
  int n_master;
  uint16_t f_master[49];
  int n[2]; /* bands at low and high resolution */
  uint16_t f_tablelow[25];
  uint16_t f_tablehigh[49];
  int n_q;
  uint16_t f_tablenoise[AAC_SBR_MAX_NOISE_BANDS + 1];
  int n_lim;
  uint16_t f_tablelim[AAC_SBR_MAX_LIMITER];
  int num_patches;
  int patch_num_subbands[AAC_SBR_MAX_PATCHES];
  int patch_start_subband[AAC_SBR_MAX_PATCHES];
};

/* Builds every table from the header fields; returns 0, AAC_ERR_DECODE for
 * a combination the standard rules out or AAC_ERR_UNSUPPORTED for a rate
 * without offset table. */
int aac_sbr_freq_tables(AacSbrFreq* f);

/* Rebuilds the limiter table alone after limiter_bands changed. */
void aac_sbr_freq_limiter(AacSbrFreq* f);

using AacSbrChannel = struct AacSbrChannel_ {
  /* bitstream data of the frame */
  int frame_class;
  int add_harmonic_flag;
  int num_env;
  int freq_res[AAC_SBR_MAX_ENV + 1];
  int num_noise;
  int df_env[AAC_SBR_MAX_ENV + 1];
  int df_noise[2];
  int invf_mode[2][AAC_SBR_MAX_NOISE_BANDS]; /* [0] this frame, [1] previous */
  int add_harmonic[AAC_SBR_MAX_BANDS];
  int amp_res;
  /* state */
  AacQmfAnalysis analysis;
  AacQmfSynthesis synthesis;
  int e_a[2]; /* envelope of the transient: [0] previous frame, [1] this one, or -1 */
  float bw_array[AAC_SBR_MAX_NOISE_BANDS];
  float W[2][AAC_SBR_QMF_SLOTS][32][2]; /* analysis output of this and the previous frame */
  int Ypos;
  float Y[2][38][64][2]; /* adjusted high band of this and the previous frame */
  float g_temp[42][AAC_SBR_MAX_BANDS];
  float q_temp[42][AAC_SBR_MAX_BANDS];
  uint8_t s_indexmapped[AAC_SBR_MAX_ENV + 1][AAC_SBR_MAX_BANDS];
  int env_facs_q[AAC_SBR_MAX_ENV + 1][AAC_SBR_MAX_BANDS];
  float env_facs[AAC_SBR_MAX_ENV + 1][AAC_SBR_MAX_BANDS];
  int noise_facs_q[3][AAC_SBR_MAX_NOISE_BANDS];
  float noise_facs[3][AAC_SBR_MAX_NOISE_BANDS];
  int t_env[AAC_SBR_MAX_ENV + 1];
  int t_env_num_env_old;
  int t_q[3];
  unsigned f_indexnoise;
  unsigned f_indexsine;
};

using AacSbrState = struct AacSbrState_ {
  int sample_rate; /* the SBR rate, twice the core rate */
  int start;       /* a header has been seen: the high band is generated */
  int ready_for_dequant;
  int elem_type;
  int reset;
  /* header: spectrum parameters, whose change resets the tables */
  int start_freq, stop_freq, xover_band, freq_scale, alter_scale, noise_bands;
  int amp_res_header;
  int limiter_bands, limiter_gains, interpol_freq, smoothing_mode;
  int coupling;
  int k[3];  /* k0, k1, k2 */
  int kx[2]; /* [0] previous frame, [1] this frame */
  int m[2];
  int kx_and_m_pushed;
  int n_master;
  AacSbrChannel data[2];
  int n[2]; /* bands at low and high resolution */
  int n_q;
  int n_lim;
  uint16_t f_master[49];
  uint16_t f_tablelow[25];
  uint16_t f_tablehigh[49];
  uint16_t f_tablenoise[AAC_SBR_MAX_NOISE_BANDS + 1];
  uint16_t f_tablelim[AAC_SBR_MAX_LIMITER];
  int num_patches;
  int patch_num_subbands[AAC_SBR_MAX_PATCHES];
  int patch_start_subband[AAC_SBR_MAX_PATCHES];
  float X_low[32][40][2];
  float X_high[64][40][2];
  float X[2][2][38][64]; /* [channel][re/im][slot][band] */
  float alpha0[64][2];
  float alpha1[64][2];
  float e_origmapped[AAC_SBR_MAX_ENV][AAC_SBR_MAX_BANDS];
  float q_mapped[AAC_SBR_MAX_ENV][AAC_SBR_MAX_BANDS];
  uint8_t s_mapped[AAC_SBR_MAX_ENV][AAC_SBR_MAX_BANDS];
  float e_curr[AAC_SBR_MAX_ENV][AAC_SBR_MAX_BANDS];
  float q_m[AAC_SBR_MAX_ENV][AAC_SBR_MAX_BANDS];
  float s_m[AAC_SBR_MAX_ENV][AAC_SBR_MAX_BANDS];
  float gain[AAC_SBR_MAX_ENV][AAC_SBR_MAX_BANDS];
  int ps_present; /* the frame carried parametric stereo data */
  AacPsState ps;  /* parametric stereo of a single channel element */
};

/* Prepares the state for a core sample rate; the SBR rate is twice it. */
void aac_sbr_init(AacSbrState* s, int core_sample_rate);

/* Places the element in pure upsampling mode until the next header. */
void aac_sbr_turnoff(AacSbrState* s);

/* Parses sbr_extension_data() (Table 4.55) of `count` payload bytes after
 * the extension_type field, with `crc` set for extension type 14.  The
 * reader is left just past the payload. */
int aac_sbr_decode_extension(AacSbrState* s, AacBitReader* r, int crc, int count, int elem_type);

/* Upsamples and, when SBR data is active, reconstructs the high band of
 * the element's channels in place: each buffer holds 1024 core samples at
 * full scale 1.0 on entry and 2048 output samples on return.  For a CPE
 * `right` is the second channel; for an SCE a non-NULL `right` requests
 * parametric stereo output, which is a copy of the left channel while no
 * PS data is active. */
void aac_sbr_apply(AacSbrState* s, int elem_type, float* left, float* right);

/* Tables (sbr_tables.cpp). */
extern const int8_t aac_sbr_huff_env_level_15_t[120][2];
extern const int8_t aac_sbr_huff_env_level_15_f[120][2];
extern const int8_t aac_sbr_huff_env_balance_15_t[48][2];
extern const int8_t aac_sbr_huff_env_balance_15_f[48][2];
extern const int8_t aac_sbr_huff_env_level_30_t[62][2];
extern const int8_t aac_sbr_huff_env_level_30_f[62][2];
extern const int8_t aac_sbr_huff_env_balance_30_t[24][2];
extern const int8_t aac_sbr_huff_env_balance_30_f[24][2];
extern const int8_t aac_sbr_huff_noise_level_30_t[62][2];
extern const int8_t aac_sbr_huff_noise_balance_30_t[24][2];
extern const float aac_sbr_noise_table[512][2];

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_SBR_H */
