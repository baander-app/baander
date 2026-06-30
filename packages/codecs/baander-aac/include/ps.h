#ifndef BAANDER_AAC_PS_H
#define BAANDER_AAC_PS_H
#include <cstdint>
#ifdef __cplusplus
extern "C" {
#endif
using AacPsParams = struct AacPsParams_ {
  int enable_iid, enable_icc, enable_ext;
  float iid[20], icc[20];
  int num_iid_bands, num_icc_bands;
};

void aac_ps_encode(AacPsParams* ps, const float qmf_L[64][32], const float qmf_R[64][32], int nb);
void aac_ps_decode(AacPsParams* ps, const float qmf_mono[64][32], float qmf_L[64][32],
                   float qmf_R[64][32], int nb);

#ifdef __cplusplus
}
#endif
#endif /* BAANDER_AAC_PS_H */
