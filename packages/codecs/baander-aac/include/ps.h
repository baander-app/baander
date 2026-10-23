#ifndef BAANDER_AAC_PS_H
#define BAANDER_AAC_PS_H

#include <cstdint>

#include "bitstream.h"

#ifdef __cplusplus
extern "C" {
#endif

/* Parametric stereo decoder (ISO/IEC 14496-3 8.6.4) for the SBR output of
 * a single channel element: parses ps_data() from the SBR extension and
 * turns the 64-band QMF signal of the mono channel into a stereo pair.
 * The processing follows the standard in the structure of FFmpeg's
 * decoder: a hybrid filterbank splits the lowest QMF bands into 71 or 91
 * sub-subbands, a decorrelator of fractional-delay all-pass links builds
 * the side signal, and the IID, ICC, IPD and OPD parameters of each
 * envelope drive a mixing matrix that is interpolated over time. */

#define AAC_PS_MAX_ENV 5
#define AAC_PS_MAX_PAR 34      /* IID / ICC parameter bands */
#define AAC_PS_MAX_IPDOPD 17   /* IPD / OPD parameter bands */
#define AAC_PS_MAX_SUBBANDS 91 /* hybrid sub-subbands */
#define AAC_PS_MAX_ALLPASS 50  /* sub-subbands with all-pass decorrelation */
#define AAC_PS_SLOTS 32
#define AAC_PS_MAX_DELAY 14
#define AAC_PS_LINKS 3
#define AAC_PS_MAX_LINK_DELAY 5

using AacPsState = struct AacPsState_ {
  /* bitstream data */
  int start; /* a header has been seen: parameters are applied */
  int enable_iid, iid_quant, nr_iid_par, nr_ipdopd_par;
  int enable_icc, icc_mode, nr_icc_par;
  int enable_ext;
  int frame_class;
  int num_env_old, num_env;
  int enable_ipdopd;
  int border_position[AAC_PS_MAX_ENV + 1];
  int8_t iid_par[AAC_PS_MAX_ENV][AAC_PS_MAX_PAR];
  int8_t icc_par[AAC_PS_MAX_ENV][AAC_PS_MAX_PAR];
  int8_t ipd_par[AAC_PS_MAX_ENV][AAC_PS_MAX_PAR];
  int8_t opd_par[AAC_PS_MAX_ENV][AAC_PS_MAX_PAR];
  int is34bands, is34bands_old;
  /* processing state */
  float in_buf[5][44][2]; /* hybrid analysis input with six slots of history */
  float delay[AAC_PS_MAX_SUBBANDS][AAC_PS_SLOTS + AAC_PS_MAX_DELAY][2];
  float ap_delay[AAC_PS_MAX_ALLPASS][AAC_PS_LINKS][AAC_PS_SLOTS + AAC_PS_MAX_LINK_DELAY][2];
  float peak_decay_nrg[AAC_PS_MAX_PAR];
  float power_smooth[AAC_PS_MAX_PAR];
  float peak_decay_diff_smooth[AAC_PS_MAX_PAR];
  float H11[2][AAC_PS_MAX_ENV + 1][AAC_PS_MAX_PAR];
  float H12[2][AAC_PS_MAX_ENV + 1][AAC_PS_MAX_PAR];
  float H21[2][AAC_PS_MAX_ENV + 1][AAC_PS_MAX_PAR];
  float H22[2][AAC_PS_MAX_ENV + 1][AAC_PS_MAX_PAR];
  float Lbuf[AAC_PS_MAX_SUBBANDS][AAC_PS_SLOTS][2];
  float Rbuf[AAC_PS_MAX_SUBBANDS][AAC_PS_SLOTS][2];
  int8_t opd_hist[AAC_PS_MAX_PAR];
  int8_t ipd_hist[AAC_PS_MAX_PAR];
};

/* Clears the state; the derived tables are built on the first call. */
void aac_ps_init(AacPsState* ps);

/* Parses ps_data() (Table 8.11) from an SBR extension payload of
 * `bits_left` bits and returns the bits consumed.  On an error the
 * parameters are cleared, PS is switched off until the next header and
 * the whole payload is skipped. */
int aac_ps_read_data(AacPsState* ps, AacBitReader* r, int bits_left);

/* Turns the QMF signal L ([re/im][slot][band]) of a mono channel into the
 * stereo pair L and R in place; `top` is the highest QMF band carrying
 * signal (kx + M of the SBR decoder). */
void aac_ps_apply(AacPsState* ps, float L[2][38][64], float R[2][38][64], int top);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_PS_H */
