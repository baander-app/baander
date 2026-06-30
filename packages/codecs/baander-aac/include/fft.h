#ifndef BAANDER_AAC_FFT_H
#define BAANDER_AAC_FFT_H

#include <cstddef>

#ifdef __cplusplus
extern "C" {
#endif

void aac_fft_forward_c(float* re, float* im, int n);
void aac_fft_inverse_c(float* re, float* im, int n);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_FFT_H */
