#ifndef BAANDER_AAC_MDCT_H
#define BAANDER_AAC_MDCT_H

#include <cstdint>

#include "aac_tables.h"
#include "fft.h"

#ifdef __cplusplus
extern "C" {
#endif

using AacWindowSequence = enum AacWindowSequence_ {
  AAC_WIN_ONLY_LONG = 0,
  AAC_WIN_LONG_START = 1,
  AAC_WIN_EIGHT_SHORT = 2,
  AAC_WIN_LONG_STOP = 3,
};

using AacWindowShape = enum AacWindowShape_ {
  AAC_WIN_SINE = 0,
  AAC_WIN_KBD = 1,
};

/* MDCT of window length n (2048 or 256; 1920 or 240 for 960-sample
 * frames), producing n/2 coefficients, with
 * the scaling of ISO/IEC 14496-3 4.6.11.3.1 / 4.6.11.3.2:
 *   forward  X[k] = 2 * sum_{j<n} z[j] cos(2 pi / n (j + n0)(k + 1/2))
 *   inverse  x[j] = 2/n * sum_{k<n/2} X[k] cos(2 pi / n (j + n0)(k + 1/2))
 * with n0 = n/4 + 1/2.  Windowed inverse transforms overlap-added with the
 * Princen-Bradley windows reconstruct the input exactly.  Both directions
 * fold to a DCT-IV of n/2 points, computed with an n/4-point complex FFT. */
using AacMdct = struct AacMdct_ {
  int n;
  AacFft fft;
  float pre_re[AAC_FFT_MAX], pre_im[AAC_FFT_MAX];
  float post_re[AAC_FFT_MAX], post_im[AAC_FFT_MAX];
};

void aac_mdct_init(AacMdct* m, int n);
/* in: n windowed time samples; out: n/2 coefficients. */
void aac_mdct_forward(const AacMdct* m, const float* in, float* out);
/* in: n/2 coefficients; out: n time samples, not windowed. */
void aac_imdct(const AacMdct* m, const float* in, float* out);

#ifdef __cplusplus
}
#endif

#endif /* BAANDER_AAC_MDCT_H */
