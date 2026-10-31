#include "fft.h"

#include <cmath>

#include "aac_dsp.h"

void aac_fft_init(AacFft* f, int n) {
  f->n = n;
  int p = 1;
  while (n % (2 * p) == 0) {
    p *= 2;
  }
  f->p = p;
  f->m = n / p;
  int bits = 0;
  while ((1 << bits) < p) {
    bits++;
  }
  for (int i = 0; i < p; i++) {
    int r = 0;
    for (int b = 0; b < bits; b++) {
      r |= ((i >> b) & 1) << (bits - 1 - b);
    }
    f->bitrev[i] = (short)r;
  }
  for (int k = 0; k < n; k++) {
    double a = -2.0 * M_PI * k / n;
    f->tw_re[k] = (float)cos(a);
    f->tw_im[k] = (float)sin(a);
  }
  /* The stage tables copy the n-point twiddles, so a stage reads the same
   * values whichever table it takes them from. */
  for (int half = 1; half < p; half <<= 1) {
    const int stride = f->m * (p / (2 * half));
    for (int j = 0; j < half; j++) {
      f->stage_re[half - 1 + j] = f->tw_re[j * stride];
      f->stage_im[half - 1 + j] = f->tw_im[j * stride];
    }
  }
}

namespace {

/* Radix-2 transform of the p points at re[0..p) and im[0..p). */
void fft_pow2(const AacFft* f, const AacDSP* dsp, float* re, float* im) {
  const int n = f->p;
  for (int i = 0; i < n; i++) {
    int j = f->bitrev[i];
    if (i < j) {
      float t = re[i];
      re[i] = re[j];
      re[j] = t;
      t = im[i];
      im[i] = im[j];
      im[j] = t;
    }
  }
  for (int half = 1; half < n; half <<= 1) {
    dsp->fft_radix2_stage(re, im, n, half, f->stage_re + half - 1, f->stage_im + half - 1);
  }
}

}  // namespace

void aac_fft_forward(const AacFft* f, float* re, float* im) {
  const int m = f->m;
  const int p = f->p;
  const AacDSP* dsp = aac_dsp_global();
  if (m == 1) {
    fft_pow2(f, dsp, re, im);
    return;
  }
  /* Cooley-Tukey with n = m p: for each residue j2 modulo p an m-point
   * DFT over x[p j1 + j2], twiddled by exp(-2 pi i j2 k1 / n), then for
   * each k1 a p-point FFT over j2 giving X[k1 + m k2]. */
  float yr[AAC_FFT_MAX], yi[AAC_FFT_MAX]; /* [j2 * m + k1] */
  for (int j2 = 0; j2 < p; j2++) {
    for (int k1 = 0; k1 < m; k1++) {
      float sr = 0.0f;
      float si = 0.0f;
      /* The twiddle index is computed here rather than read from a table:
       * the sum must stay in this order for the encoder's output to be
       * independent of the build, and the modulo keeps the compiler from
       * splitting it into partial sums. */
      for (int j1 = 0; j1 < m; j1++) {
        const int t = (j1 * k1 % m) * p; /* exp(-2 pi i j1 k1 / m) */
        const float xr = re[p * j1 + j2];
        const float xi = im[p * j1 + j2];
        sr += xr * f->tw_re[t] - xi * f->tw_im[t];
        si += xr * f->tw_im[t] + xi * f->tw_re[t];
      }
      const int t = j2 * k1;
      yr[j2 * m + k1] = sr * f->tw_re[t] - si * f->tw_im[t];
      yi[j2 * m + k1] = sr * f->tw_im[t] + si * f->tw_re[t];
    }
  }
  for (int k1 = 0; k1 < m; k1++) {
    float zr[AAC_FFT_MAX], zi[AAC_FFT_MAX];
    for (int j2 = 0; j2 < p; j2++) {
      zr[j2] = yr[j2 * m + k1];
      zi[j2] = yi[j2 * m + k1];
    }
    fft_pow2(f, dsp, zr, zi);
    for (int k2 = 0; k2 < p; k2++) {
      re[k1 + m * k2] = zr[k2];
      im[k1 + m * k2] = zi[k2];
    }
  }
}
