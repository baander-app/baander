#ifndef BAANDER_AAC_SPECTRAL_H
#define BAANDER_AAC_SPECTRAL_H
#include <cstdint>
#ifdef __cplusplus
extern "C" {
#endif
using AacTnsInfo = struct AacTnsInfo_ {
  int n_filt[8], coef_res[8], length[8][4], order[8][4], direction[8][4];
  float lpc[8][4][20];
};

void aac_tns_encode(AacTnsInfo* tns, float* spec, int nb, const int* sfb, int ri, int ws);
void aac_tns_decode(AacTnsInfo* tns, float* spec, int nb, const int* sfb, int ri, int ws);
void aac_pns_replace(float* spec, int sfb, const int* sfb_off, float energy, int fs);
void aac_pns_restore(float* spec, int sfb, const int* sfb_off, float energy, int fs);
void aac_ms_encode(float* mid, float* side, const float* L, const float* R, int n);
void aac_ms_decode(float* L, float* R, const float* mid, const float* side, int n);
void aac_intensity_decode(float* L, float* R, const float* spec, float scale, int start, int end);

#ifdef __cplusplus
}
#endif
#endif /* BAANDER_AAC_SPECTRAL_H */
