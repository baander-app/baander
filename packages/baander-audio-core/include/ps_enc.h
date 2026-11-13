#ifndef BAANDER_AAC_PS_ENC_H
#define BAANDER_AAC_PS_ENC_H

#include <cstdint>

#include "bitstream.h"
#include "ps.h"

#ifdef __cplusplus
extern "C" {
#endif

/* Parametric stereo encoder (ISO/IEC 14496-3 8.6.4) for the stereo pair
 * of an HE-AAC v2 element: estimates the inter-channel intensity
 * difference and coherence of 20 parameter bands per envelope from the
 * hybrid-filtered QMF signals, quantizes them to the coarse grids, codes
 * them with the delta-frequency or delta-time codebooks, and downmixes
 * the pair to the one channel the core and SBR encoders code.  Phase
 * parameters are not sent. */

#define AAC_PS_ENC_MAX_ENV 4
#define AAC_PS_ENC_BANDS 20

using AacPsEncoder = struct AacPsEncoder_ {
  int num_env; /* envelopes per frame: 1, 2 or 4 */
  float in_buf[2][5][44][2]; /* hybrid analysis history of both channels */
  float hyb[2][AAC_PS_MAX_SUBBANDS][AAC_PS_SLOTS][2];
  float dmx_lr[64], dmx_m[64]; /* smoothed energies behind the downmix gain */
  int iid_idx[AAC_PS_ENC_MAX_ENV][AAC_PS_ENC_BANDS];
  int icc_idx[AAC_PS_ENC_MAX_ENV][AAC_PS_ENC_BANDS];
  int prev_iid[AAC_PS_ENC_BANDS];
  int prev_icc[AAC_PS_ENC_BANDS];
  int have_prev;
  int iid_dt[AAC_PS_ENC_MAX_ENV]; /* envelope coded as time deltas */
  int icc_dt[AAC_PS_ENC_MAX_ENV];
  int iid_delta[AAC_PS_ENC_MAX_ENV][AAC_PS_ENC_BANDS]; /* the coded deltas */
  int icc_delta[AAC_PS_ENC_MAX_ENV][AAC_PS_ENC_BANDS];
  int bits; /* of the coded ps_data() of this frame */
  int header; /* this frame carries the PS header */
};

/* Prepares the encoder for an element bitrate (bits per second). */
void aac_ps_enc_init(AacPsEncoder* ps, int bitrate);
void aac_ps_enc_reset(AacPsEncoder* ps);

/* Downmixes one QMF slot of the pair to M with a smoothed per-band gain
 * that keeps the pair's energy. */
void aac_ps_enc_downmix(AacPsEncoder* ps, const float L[64][2], const float R[64][2], float M[64][2]);

/* Estimates and codes the parameters of one frame from the `slots` + 6
 * slots ([re/im][slot][band]) of both channels that the decoder's grid
 * covers (32 or 30 slots per frame plus the hybrid filters' lookahead),
 * with `header` set when the PS header is to be sent. */
void aac_ps_enc_analyze(AacPsEncoder* ps, const float L[2][38][64], const float R[2][38][64],
                        int header, int slots);

/* Writes ps_data() of the frame. */
void aac_ps_enc_write(const AacPsEncoder* ps, AacBitWriter* w);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_PS_ENC_H */
