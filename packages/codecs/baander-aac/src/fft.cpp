#include "fft.h"

#include <cmath>

void aac_fft_init(AacFft* f, int n) {
  f->n = n;
  int bits = 0;
  while ((1 << bits) < n) {
    bits++;
  }
  for (int i = 0; i < n; i++) {
    int r = 0;
    for (int b = 0; b < bits; b++) {
      r |= ((i >> b) & 1) << (bits - 1 - b);
    }
    f->bitrev[i] = (short)r;
  }
  for (int k = 0; k < n / 2; k++) {
    double a = -2.0 * M_PI * k / n;
    f->tw_re[k] = (float)cos(a);
    f->tw_im[k] = (float)sin(a);
  }
}

void aac_fft_forward(const AacFft* f, float* re, float* im) {
  const int n = f->n;
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
    const int stride = n / (2 * half);
    for (int start = 0; start < n; start += 2 * half) {
      for (int j = 0; j < half; j++) {
        const float wr = f->tw_re[j * stride];
        const float wi = f->tw_im[j * stride];
        const int a = start + j;
        const int b = a + half;
        const float tr = re[b] * wr - im[b] * wi;
        const float ti = re[b] * wi + im[b] * wr;
        re[b] = re[a] - tr;
        im[b] = im[a] - ti;
        re[a] += tr;
        im[a] += ti;
      }
    }
  }
}
