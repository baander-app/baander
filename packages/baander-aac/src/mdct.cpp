#include "mdct.h"

#include <cmath>

#include "aac_dsp.h"

void aac_mdct_init(AacMdct* m, int n) {
  m->n = n;
  const int half = n / 2;
  const int quarter = n / 4;
  aac_fft_init(&m->fft, quarter);
  for (int j = 0; j < quarter; j++) {
    double a = -M_PI * (j + 0.25) / half;
    m->pre_re[j] = (float)cos(a);
    m->pre_im[j] = (float)sin(a);
    double b = -M_PI * j / half;
    m->post_re[j] = (float)cos(b);
    m->post_im[j] = (float)sin(b);
  }
}

/* DCT-IV of n/2 points, C[k] = sum_j u[j] cos(pi / (n/2) (j + 1/2)(k + 1/2)),
 * via an n/4-point complex FFT.  in and out may not alias. */
static void dct4(const AacMdct* m, const float* in, float* out) {
  const int half = m->n / 2;
  const AacDSP* dsp = aac_dsp_global();
  float re[AAC_FFT_MAX], im[AAC_FFT_MAX];
  dsp->mdct_pre_twiddle(re, im, in, m->pre_re, m->pre_im, half);
  aac_fft_forward(&m->fft, re, im);
  dsp->mdct_post_twiddle(out, re, im, m->post_re, m->post_im, half);
}

void aac_mdct_forward(const AacMdct* m, const float* in, float* out) {
  const int n = m->n;
  const int half = n / 2;
  const int quarter = n / 4;
  const int three = 3 * quarter;
  float u[2 * AAC_FFT_MAX];
  for (int i = 0; i < quarter; i++) {
    u[i] = -in[three - 1 - i] - in[three + i];
  }
  for (int i = quarter; i < half; i++) {
    u[i] = in[i - quarter] - in[three - 1 - i];
  }
  dct4(m, u, out);
  for (int k = 0; k < half; k++) {
    out[k] *= 2.0f;
  }
}

void aac_imdct(const AacMdct* m, const float* in, float* out) {
  const int n = m->n;
  const int half = n / 2;
  const int quarter = n / 4;
  const int three = 3 * quarter;
  float c[2 * AAC_FFT_MAX];
  dct4(m, in, c);
  const float scale = 2.0f / (float)n;
  for (int i = 0; i < quarter; i++) {
    const float v = -c[i] * scale;
    out[three - 1 - i] = v;
    out[three + i] = v;
  }
  for (int i = quarter; i < half; i++) {
    const float v = c[i] * scale;
    out[i - quarter] = v;
    out[three - 1 - i] = -v;
  }
}
