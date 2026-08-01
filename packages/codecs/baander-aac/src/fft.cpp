#include "fft.h"

#include <cmath>
#include <cstring>

static void bit_reverse(float* re, float* im, int n) {
  int j = 0;
  for (int i = 0; i < n - 1; i++) {
    if (i < j) {
      float tr = re[i];
      re[i] = re[j];
      re[j] = tr;
      float ti = im[i];
      im[i] = im[j];
      im[j] = ti;
    }
    int k = n >> 1;
    while (k <= j) {
      j -= k;
      k >>= 1;
    }
    j += k;
  }
}

void aac_fft_forward_c(float* re, float* im, int n) {
  bit_reverse(re, im, n);
  for (int s = 1; s < n; s <<= 1) {
    int m = s << 1;
    float wn_re = cosf((float)M_PI / (float)s);
    float wn_im = -sinf((float)M_PI / (float)s);
    for (int k = 0; k < n; k += m) {
      float w_re = 1.0f, w_im = 0.0f;
      for (int j = 0; j < s; j++) {
        float t_re = w_re * re[k + j + s] - w_im * im[k + j + s];
        float t_im = w_re * im[k + j + s] + w_im * re[k + j + s];
        re[k + j + s] = re[k + j] - t_re;
        im[k + j + s] = im[k + j] - t_im;
        re[k + j] += t_re;
        im[k + j] += t_im;
        float nw_re = w_re * wn_re - w_im * wn_im;
        float nw_im = w_re * wn_im + w_im * wn_re;
        w_re = nw_re;
        w_im = nw_im;
      }
    }
  }
}

void aac_fft_inverse_c(float* re, float* im, int n) {
  for (int i = 0; i < n; i++) { im[i] = -im[i];
}
  aac_fft_forward_c(re, im, n);
  float inv = 1.0f / (float)n;
  for (int i = 0; i < n; i++) {
    re[i] *= inv;
    im[i] = -im[i] * inv;
  }
}
