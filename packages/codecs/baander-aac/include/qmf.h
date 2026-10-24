#ifndef BAANDER_AAC_QMF_H
#define BAANDER_AAC_QMF_H

#include "aac_tables.h"

#ifdef __cplusplus
extern "C" {
#endif

/* The complex QMF banks of ISO/IEC 14496-3 4.6.18 on the 640-tap prototype
 * of Table 4.A.87.  Signals are in the full scale of 32768 the SBR
 * decoder's gain formulas expect.
 *
 * The 32-band analysis splits the core decoder's output into 32 complex
 * subbands per slot of 32 samples; the 64-band synthesis turns 64 subbands
 * into 64 samples per slot (and pairs of slots into 128 samples at twice
 * the core rate).  The 64-band analysis and the 32-band synthesis serve
 * the encoder: the former splits the full-rate input, the latter builds the
 * half-rate core signal from its lower 32 subbands. */

using AacQmfAnalysis = struct AacQmfAnalysis_ {
  int bands;      /* 32 or 64 */
  float x[640];   /* input history, newest sample first */
};

using AacQmfSynthesis = struct AacQmfSynthesis_ {
  int bands;      /* 64 or 32 */
  float v[2 * 1152]; /* the newest ten blocks of v, the newest at `off` */
  int off;
};

void aac_qmf_tables_init(void);

/* `bands` is 16, 24, 32 or 64. */
void aac_qmf_analysis_init(AacQmfAnalysis* a, int bands);
/* One slot: consumes `bands` input samples and writes W[k] = {re, im} for
 * k < bands. */
void aac_qmf_analyze(AacQmfAnalysis* a, const float* in, float (*W)[2]);

void aac_qmf_synthesis_init(AacQmfSynthesis* s, int bands);
/* One slot: takes X[k] for k < 64 (the upper half ignored for 32 bands)
 * and writes `bands` output samples. */
void aac_qmf_synthesize(AacQmfSynthesis* s, const float (*X)[2], float* out);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_QMF_H */
