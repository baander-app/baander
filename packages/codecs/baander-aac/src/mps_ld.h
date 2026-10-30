#ifndef BAANDER_AAC_MPS_LD_H
#define BAANDER_AAC_MPS_LD_H

#include <stdint.h>

#include "mps.h"
#include "mps_signal.h"

#ifdef __cplusplus
extern "C" {
#endif

/* The low-delay mode of MPEG Surround (ISO/IEC 23003-1 as AAC-ELD v2
 * carries it): the 2-1-2 tree on the core's 64-band low-delay
 * filterbank, without the hybrid filterbank, with the shorter
 * decorrelator of the low-delay mode and the parameter bands mapped
 * over the QMF bands alone (23 at most).
 *
 * The filterbank keeps the structure of the SBR QMF banks (a 640-tap
 * window folded into 128 values and a cosine modulation) with the
 * low-delay prototype for both the analysis and the synthesis:
 *   W(k) = sum_n u(n) exp(i pi / 128 (k + 1/2)(2 n + 1)),
 *   v(n) = 1/64 Re{sum_k X(k) exp(i pi / 128 (k + 1/2)(2 n - 127))}.
 * The pair reconstructs its input after AAC_MPS_LD_DELAY samples.
 * Signals are in the full scale of 32768 of the QMF frames. */

#define AAC_MPS_LD_BANDS 64
#define AAC_MPS_LD_WINDOW 640
#define AAC_MPS_LD_DELAY 256
#define AAC_MPS_LD_MAX_PARAM_BANDS 23
#define AAC_MPS_LD_DECORR_MAX_ORDER 15
#define AAC_MPS_LD_DECORR_MAX_DELAY 8

/* ── tables (src/mps_ld_tables.cpp) ─────────────────────────────── */

extern const float aac_mps_ld_window[AAC_MPS_LD_WINDOW];
/* QMF band to parameter band for a band count of the low-delay form
 * (23, 15, 12, 9, 7, 5 or 4), or NULL. */
const uint8_t* aac_mps_ld_kernel(int num_bands);
extern const uint8_t aac_mps_ld_decorr_split[3][4];
extern const uint8_t aac_mps_ld_decorr_delay[4];
extern const uint8_t aac_mps_ld_decorr_order[4];
extern const float aac_mps_ld_decorr_den[4][AAC_MPS_LD_DECORR_MAX_ORDER + 1];

/* ── the filterbank ─────────────────────────────────────────────── */

using AacMpsLdAnalysis = struct AacMpsLdAnalysis_ {
  float x[AAC_MPS_LD_WINDOW]; /* input history, newest sample first */
};

using AacMpsLdSynthesis = struct AacMpsLdSynthesis_ {
  float v[2 * 18 * AAC_MPS_LD_BANDS]; /* the newest ten blocks of v, the newest at `off` */
  int off;
};

void aac_mps_ld_tables_init(void);
void aac_mps_ld_analysis_init(AacMpsLdAnalysis* a);
/* One slot: consumes 64 input samples and writes W[k] = {re, im}. */
void aac_mps_ld_analyze(AacMpsLdAnalysis* a, const float* in, float (*W)[2]);
void aac_mps_ld_synthesis_init(AacMpsLdSynthesis* s);
/* One slot: takes X[k] for k < 64 and writes 64 output samples. */
void aac_mps_ld_synthesize(AacMpsLdSynthesis* s, const float (*X)[2], float* out);

/* ── the signal path ────────────────────────────────────────────── */

typedef struct AacMpsLdState_ AacMpsLdState;

/* The 2-1-2 path of a low-delay configuration: cfg->frame_length QMF
 * slots make a spatial frame.  Returns NULL for a configuration outside
 * this path (another tree, the AAC form, a frequency resolution without
 * a band map). */
AacMpsLdState* aac_mps_ld_create(const AacMpsConfig* cfg);
void aac_mps_ld_destroy(AacMpsLdState* s);
void aac_mps_ld_reset(AacMpsLdState* s);
int aac_mps_ld_slots(const AacMpsLdState* s);

/* One spatial frame: `in` holds the downmix's QMF frame of frame_length
 * slots, `out` receives the left and right QMF frames.  The output
 * keeps the input's timing: the parameters apply to the slots they are
 * coded for.  `p` NULL keeps the previous parameters.  The temporal
 * shaping tools are read but not applied in this mode. */
void aac_mps_ld_apply(AacMpsLdState* s, const AacMpsFrameParams* p, const AacUsacQmfFrame* in,
                      AacUsacQmfFrame* const* out);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_MPS_LD_H */
