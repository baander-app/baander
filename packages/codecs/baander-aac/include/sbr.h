#ifndef BAANDER_AAC_SBR_H
#define BAANDER_AAC_SBR_H
#include <cstdint>

#include "aac_dsp.h"
#include "aac_tables.h" /* AAC_SBR_QMF_BANDS defined here */
#ifdef __cplusplus
extern "C" {
#endif
/* AAC_SBR_QMF_BANDS inherited from aac_tables.h — do not re-define */

using AacSbrQmf = struct AacSbrQmf_ {
  float analysis_buf[64][32];
  float synthesis_buf[64][32];
  float overlap[64][32];
};

using AacSbrEnvelope = struct AacSbrEnvelope_ {
  int freq_bands_lo, freq_bands_hi, time_slots;
  float envelope[8][64];
  float noise_floor[8][64];
};

void aac_sbr_qmf_analysis(AacSbrQmf* qmf, const float* time_in, float qmf_out[64][32],
                          const struct AacDSP_* dsp);
void aac_sbr_qmf_synthesis(AacSbrQmf* qmf, const float qmf_in[64][32], float* time_out,
                           const struct AacDSP_* dsp);
void aac_sbr_encode(AacSbrEnvelope* env, AacSbrQmf* qmf, const float* core_low, int core_bands,
                    int sbr_bands);
void aac_sbr_decode(AacSbrEnvelope* env, AacSbrQmf* qmf, const float* core_low, int core_bands,
                    int sbr_bands, float* output);

#ifdef __cplusplus
}
#endif
#endif /* BAANDER_AAC_SBR_H */
