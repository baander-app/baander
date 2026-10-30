#ifndef BAANDER_AAC_QMF_LD_H
#define BAANDER_AAC_QMF_LD_H

#ifdef __cplusplus
extern "C" {
#endif

/* The low-delay complex filterbank (CLDFB) of AAC-ELD's low-delay SBR
 * (ISO/IEC 14496-3 4.6.20).  The banks keep the structure, gains and
 * modulation of the SBR QMF banks in qmf.h (a 10 B tap window folded into
 * 2 B values and modulated with exp(i pi / (2 B) (k + 1/2)(2 n - d))), so
 * that their subband signals feed the SBR tools unchanged, and replace the
 * symmetric prototype by four asymmetric windows (analysis and synthesis,
 * 64 and 32 bands) whose pairings reconstruct the input with a third of
 * the symmetric bank's delay.  Signals are in the full scale of 32768 of
 * the SBR gain formulas.
 *
 * The 32-band analysis splits the core signal into 32 complex subbands
 * per slot of 32 samples; the 64-band synthesis turns 64 subbands into 64
 * samples per slot (the dual-rate output) and the 32-band synthesis takes
 * the lower 32 subbands to 32 samples (the single-rate output).  The
 * 64-band analysis and the 32-band synthesis serve the encoder: the
 * former splits the full-rate input, the latter builds the half-rate
 * core signal from its lower 32 subbands.
 *
 * Delays of the pairings, in output samples: 64-band analysis to 64-band
 * synthesis AAC_CLDFB_DELAY; 32-band analysis to 64-band synthesis
 * AAC_CLDFB_DELAY + 1 (the output's even samples carry the input); 32 to
 * 32 and 64 to 32 (AAC_CLDFB_DELAY + 1) / 2, the half-rate output of the
 * latter sitting on the odd input samples. */

#define AAC_CLDFB_DELAY 193

using AacCldfbAnalysis = struct AacCldfbAnalysis_ {
  int bands;    /* 32 or 64 */
  float x[640]; /* input history, newest sample first */
};

using AacCldfbSynthesis = struct AacCldfbSynthesis_ {
  int bands;         /* 64 or 32 */
  float v[2 * 1152]; /* the newest ten blocks of v, the newest at `off` */
  int off;
};

void aac_cldfb_tables_init(void);

void aac_cldfb_analysis_init(AacCldfbAnalysis* a, int bands);
/* One slot: consumes `bands` input samples and writes W[k] = {re, im} for
 * k < bands. */
void aac_cldfb_analyze(AacCldfbAnalysis* a, const float* in, float (*W)[2]);

void aac_cldfb_synthesis_init(AacCldfbSynthesis* s, int bands);
/* One slot: takes X[k] for k < 64 (the upper half ignored for 32 bands)
 * and writes `bands` output samples. */
void aac_cldfb_synthesize(AacCldfbSynthesis* s, const float (*X)[2], float* out);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_QMF_LD_H */
