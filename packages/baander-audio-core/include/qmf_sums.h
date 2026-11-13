#ifndef BAANDER_AUDIO_CORE_QMF_SUMS_H
#define BAANDER_AUDIO_CORE_QMF_SUMS_H

#include <cstring>

/* The direct sums of the complex filterbanks (qmf.cpp, qmf_ld.cpp,
 * mps_ld.cpp).  Every output adds its terms in the order of its
 * definition, first term first.  The outer loop walks the terms and the
 * inner loop the outputs, each with its own running sum: the additions
 * into one output keep their order, and the inner loop, in which no
 * output depends on another, vectorises.
 *
 * A table such a loop reads is stored by term: the values of one term for
 * all outputs lie next to each other, so the inner loop reads them in
 * sequence.  The modulation tables of the filterbanks and the DCT-IV
 * tables of usac_fac.cpp follow this layout. */

/* u[n] = sum over j < 5 of x[n + terms j] window[n + terms j], n < terms:
 * the windowed history folded into one period of the modulation. */
static inline void aac_qmf_fold(float* u, const float* x, const float* window, int terms) {
  for (int n = 0; n < terms; n++) {
    u[n] = 0.0f;
  }
  for (int j = 0; j < 5; j++) {
    const float* xj = x + terms * j;
    const float* wj = window + terms * j;
    for (int n = 0; n < terms; n++) {
      u[n] += xj[n] * wj[n];
    }
  }
}

/* W[k] = sum over n < terms of u[n] (cos_t[n][k], sin_t[n][k]), k < bands
 * (at most 64): the analysis modulation.  The tables hold `bands` values
 * per term. */
static inline void aac_qmf_modulate(float (*W)[2], const float* u, const float* cos_t,
                                    const float* sin_t, int bands, int terms) {
  float re[64], im[64];
  for (int k = 0; k < bands; k++) {
    re[k] = 0.0f;
    im[k] = 0.0f;
  }
  for (int n = 0; n < terms; n++) {
    const float un = u[n];
    const float* c = cos_t + n * bands;
    const float* s = sin_t + n * bands;
    for (int k = 0; k < bands; k++) {
      re[k] += un * c[k];
      im[k] += un * s[k];
    }
  }
  for (int k = 0; k < bands; k++) {
    W[k][0] = re[k];
    W[k][1] = im[k];
  }
}

/* v[n] = sum over k < bands of X[k].re cos_t[k][n] - X[k].im sin_t[k][n],
 * n < outputs (at most 128): the synthesis modulation.  The tables hold
 * `outputs` values per band. */
static inline void aac_qmf_demodulate(float* v, const float (*X)[2], const float* cos_t,
                                      const float* sin_t, int bands, int outputs) {
  float acc[128];
  for (int n = 0; n < outputs; n++) {
    acc[n] = 0.0f;
  }
  for (int k = 0; k < bands; k++) {
    const float xr = X[k][0];
    const float xi = X[k][1];
    const float* c = cos_t + k * outputs;
    const float* s = sin_t + k * outputs;
    for (int n = 0; n < outputs; n++) {
      acc[n] += xr * c[n] - xi * s[n];
    }
  }
  memcpy(v, acc, (size_t)outputs * sizeof(float));
}

/* out[k] = sum over j < 5 of v[4 bands j + k] window[2 bands j + k] +
 * v[4 bands j + 3 bands + k] window[(2 j + 1) bands + k], k < bands (at
 * most 64): the ten windowed segments of the synthesis history. */
static inline void aac_qmf_overlap(float* out, const float* v, const float* window, int bands) {
  float acc[64];
  for (int k = 0; k < bands; k++) {
    acc[k] = 0.0f;
  }
  for (int j = 0; j < 5; j++) {
    const float* v0 = v + 4 * bands * j;
    const float* v1 = v0 + 3 * bands;
    const float* w0 = window + 2 * bands * j;
    const float* w1 = w0 + bands;
    for (int k = 0; k < bands; k++) {
      acc[k] += v0[k] * w0[k] + v1[k] * w1[k];
    }
  }
  memcpy(out, acc, (size_t)bands * sizeof(float));
}

#endif /* BAANDER_AUDIO_CORE_QMF_SUMS_H */
