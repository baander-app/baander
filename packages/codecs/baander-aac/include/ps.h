#ifndef BAANDER_AAC_PS_H
#define BAANDER_AAC_PS_H
#include <cstdint>
#ifdef __cplusplus
extern "C" {
#endif
#define AAC_PS_MAX_BANDS 20
#define AAC_PS_DECORR_SIZE 16

using AacPsParams = struct AacPsParams_ {
  int enable_iid, enable_icc, enable_ext;
  float iid[AAC_PS_MAX_BANDS], icc[AAC_PS_MAX_BANDS];
  int num_iid_bands, num_icc_bands;
  /* Decorrelator delay-line state (zero-initialised by caller). */
  float decorr_state[AAC_PS_MAX_BANDS][AAC_PS_DECORR_SIZE];
  int decorr_pos[AAC_PS_MAX_BANDS];
  int decorr_initialized;
};

void aac_ps_encode(AacPsParams* ps, const float qmf_L[64][32], const float qmf_R[64][32], int nb);
void aac_ps_decode(AacPsParams* ps, const float qmf_mono[64][32], float qmf_L[64][32],
                   float qmf_R[64][32], int nb);

#ifdef __cplusplus
}
#endif
#endif /* BAANDER_AAC_PS_H */
