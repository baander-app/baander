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
 * runs it through its own QMF analysis.  `left` may alias `dmx`. */
void aac_mps212_apply(AacMps212State* s, const AacUsacQmfFrame* dmx, const float* residual,
                      AacUsacQmfFrame* left, AacUsacQmfFrame* right);

/* Supplies the parameters of a lost frame (the previous ones held). */
void aac_mps212_conceal(AacMps212State* s);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_MPS212_H */
