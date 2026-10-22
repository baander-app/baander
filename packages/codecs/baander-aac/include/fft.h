#ifndef BAANDER_AAC_FFT_H
#define BAANDER_AAC_FFT_H

#include <cstddef>

#ifdef __cplusplus
extern "C" {
#endif

/* Largest complex FFT the codec needs: the 2048-sample MDCT runs a
 * 512-point FFT. */
#define AAC_FFT_MAX 512

/* Radix-2 complex FFT of a fixed power-of-two size with precomputed
 * twiddles and bit-reversal permutation. */
using AacFft = struct AacFft_ {
  int n;
  float tw_re[AAC_FFT_MAX / 2];
  float tw_im[AAC_FFT_MAX / 2];
  short bitrev[AAC_FFT_MAX];
};

/* n must be a power of two in [2, AAC_FFT_MAX]. */
void aac_fft_init(AacFft* f, int n);

/* In-place forward transform: X[k] = sum x[j] exp(-2 pi i j k / n),
 * unnormalized. */
void aac_fft_forward(const AacFft* f, float* re, float* im);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_FFT_H */
