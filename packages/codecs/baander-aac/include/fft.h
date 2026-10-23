#ifndef BAANDER_AAC_FFT_H
#define BAANDER_AAC_FFT_H

#include <cstddef>

#ifdef __cplusplus
extern "C" {
#endif

/* Largest complex FFT the codec needs: the 2048-sample MDCT runs a
 * 512-point FFT (the 1920-sample MDCT of a 960 frame a 480-point one). */
#define AAC_FFT_MAX 512

/* Complex FFT of a fixed size n = m * 2^k with m odd and small (1 for the
 * 1024 frame's transforms, 15 for the 960 frame's): the 2^k-point parts
 * run radix-2 over precomputed twiddles and a bit-reversal permutation,
 * the m-point parts are direct sums. */
using AacFft = struct AacFft_ {
  int n;
  int m; /* odd factor */
  int p; /* power-of-two factor, n = m * p */
  float tw_re[AAC_FFT_MAX]; /* exp(-2 pi i k / n) for k < n */
  float tw_im[AAC_FFT_MAX];
  short bitrev[AAC_FFT_MAX];
};

/* n must be in [2, AAC_FFT_MAX] with an odd factor of at most 15. */
void aac_fft_init(AacFft* f, int n);

/* In-place forward transform: X[k] = sum x[j] exp(-2 pi i j k / n),
 * unnormalized. */
void aac_fft_forward(const AacFft* f, float* re, float* im);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_FFT_H */
